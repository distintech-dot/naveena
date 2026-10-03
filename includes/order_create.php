<?php
/**
 * PEMBUATAN TRANSAKSI (satu tempat untuk semua jalur).
 *
 * Dipakai oleh:
 *   1. order_baru.php — kasir menyimpan transaksi setelah pembayaran diterima, dan
 *   2. bayar.php / pay_webhook.php — menyelesaikan transaksi SETELAH pembayaran
 *      transfer/QRIS dinyatakan lunas (dikonfirmasi kasir atau oleh gateway).
 *
 * Aturan penting (permintaan pemilik klinik: "wajib bayar dulu"): transaksi
 * TIDAK dibuat bila pembayaran belum dikonfirmasi. Pemanggil wajib mengisi
 * $in['paid_confirm'] = '1' (dikonfirmasi kasir) atau $in['pay_verified'] = '1'
 * (dinyatakan lunas oleh payment gateway).
 *
 * @param array $in       data transaksi (pasien, item, metode, diskon, dll)
 * @param array $user     pengguna penyimpan (audit & nama kasir)
 * @param int   $branchId cabang transaksi
 * @return array{order_id:int,invoice:string,card_msg:string,email_info:string,member_warn:string,unique_code:int}
 */
declare(strict_types=1);

function order_create(array $in, array $user, int $branchId): array
{
    $paidConfirm = ($in['paid_confirm'] ?? '') === '1';
    $payVerified = ($in['pay_verified'] ?? '') === '1';
    if (!$paidConfirm && !$payVerified) {
        throw new RuntimeException('Transaksi belum dapat disimpan: pembayaran belum dikonfirmasi. '
            . 'Selesaikan pembayaran lebih dulu (tunai/transfer/QRIS) pada langkah Pembayaran.');
    }
    $patientId = (int)($in['patient_id'] ?? 0);
    $items = ($in['items'] ?? []);
    $method = in_array((string)($in['method'] ?? 'Cash'), PAY_METHODS, true) ? (string)($in['method'] ?? 'Cash') : 'Cash';
    $refNo = trim((string)($in['ref_no'] ?? ''));
    $discount = qty_parse($in['discount'] ?? 0);
    $useMemberCard = ($in['member_card'] ?? '0') === '1';   // pilihan petugas di form
    /* Konfirmasi aktivasi kartu untuk pasien yang BELUM terdaftar sebagai
       pemegang kartu. Tanpa konfirmasi ini, pilihan "Ada kartu member"
       TIDAK memberi diskon dan kartu tidak dibuat — ini mencegah pasien baru
       langsung mendapat potongan tanpa sengaja (permintaan pemilik klinik). */
    $activateConfirm = ($in['member_activate'] ?? '') === '1';
    $memberWarn = '';
    $notes = trim((string)($in['notes'] ?? ''));
    $apptId = (int)($in['appointment_id'] ?? 0);

    if (!$patientId) throw new RuntimeException('Transaksi gagal disimpan. Pasien belum dipilih.');
    $pat = one('SELECT * FROM patients WHERE id = ?', [$patientId]);
    if (!$pat) throw new RuntimeException('Transaksi gagal disimpan. Pasien tidak ditemukan.');
    assert_branch((int)$pat['branch_id']);
    /* CABANG TRANSAKSI MENGIKUTI DATA PASIEN (permintaan pemilik).
       Pemakainya yang dapat mengakses SEMUA cabang (Super Admin & Direktur) bisa
       saja memilih cabang Kaliwungu di pemilih cabang sementara pasiennya terdaftar
       di Cepiring — dulu transaksinya ikut tersimpan di Kaliwungu sehingga data
       tidak sesuai cabangnya. Sekarang cabang transaksi DIPASTIKAN sama dengan
       cabang pasien, di jalur mana pun (kasir, bayar.php, maupun webhook gateway),
       karena pasien & stok/treatment memang milik cabang itu. */
    if (user_branch() === null && (int)$pat['branch_id'] !== $branchId) {
        $branchId = (int)$pat['branch_id'];
    }
    if (!$items || !is_array($items)) throw new RuntimeException('Transaksi gagal disimpan. Belum ada item treatment/skincare.');
    if ($discount < 0) throw new RuntimeException('Diskon tidak boleh negatif.');

    // Validate items up-front
    $clean = [];        // item yang dijual (treatment/skincare/paket) — masuk perhitungan harga
    $materials = [];    // bahan treatment terpakai — harga 0, hanya mengurangi stok
    /* Paket yang dipilih pada transaksi ini (komponen & stoknya diproses di bawah). */
    $packages = [];
    $subtotal = 0.0;
    foreach ($items as $it) {
        $rawType = (string)($it['type'] ?? '');
        /* PENTING: jenis item harus dikenali apa adanya. Sebelumnya nilai tak
           dikenal DIPAKSA menjadi 'treatment' — paket akan salah dianggap
           treatment. Sekarang hanya jenis yang dikenal yang dipakai. */
        $type = in_array($rawType, ['treatment', 'skincare', 'material', 'package'], true) ? $rawType : 'treatment';
        $iid  = (int)($it['id'] ?? 0);
        /* Jumlah boleh pecahan dan boleh ditulis dengan koma Indonesia
           ("0,5" liter) — karena itu dibaca lewat qty_parse(), bukan (float). */
        $qty  = qty_parse($it['qty'] ?? 0);
        $price = qty_parse($it['price'] ?? 0);
        if ($iid <= 0 || $qty <= 0) continue;
        if ($type === 'material') {
            /* Bahan treatment: TIDAK dijual ke pasien — harga selalu 0 dan
               tidak ikut subtotal. Yang penting hanya ketersediaan stok. */
            $row = one('SELECT * FROM treatment_materials WHERE id = ?', [$iid]);
            if (!$row) throw new RuntimeException('Bahan treatment tidak ditemukan.');
            if ((int)$row['branch_id'] !== $branchId) throw new RuntimeException('Bahan ' . $row['name'] . ' bukan milik cabang ini.');
            if ($row['status'] !== 'active') throw new RuntimeException('Bahan ' . $row['name'] . ' sudah nonaktif.');
            if ((float)$row['stock'] + 0.00001 < $qty) {
                throw new RuntimeException('Stok bahan ' . $row['name'] . ' tidak cukup untuk pemakaian '
                    . qty_unit($qty, $row['unit']) . '. Tersedia ' . qty_unit($row['stock'], $row['unit']) . '.');
            }
            $materials[] = ['type' => 'material', 'id' => $iid, 'name' => $row['name'], 'code' => $row['code'],
                'qty' => $qty, 'price' => 0.0, 'line' => 0.0, 'unit' => $row['unit']];
            continue;
        }
        if ($price < 0) throw new RuntimeException('Harga tidak boleh negatif.');
        if ($type === 'package') {
            /* PAKET: satu baris harga (harga paket) + komponennya dicatat &
               stoknya dikurangi di bawah. Komponen diperiksa ketersediaannya
               LEBIH DULU supaya tidak ada transaksi setengah jalan. */
            $pkg = one('SELECT * FROM packages WHERE id = ?', [$iid]);
            if (!$pkg) throw new RuntimeException('Paket tidak ditemukan.');
            if ((int)$pkg['branch_id'] !== $branchId) throw new RuntimeException('Paket ' . $pkg['name'] . ' bukan milik cabang ini.');
            if ($pkg['status'] !== 'active') throw new RuntimeException('Paket ' . $pkg['name'] . ' sudah nonaktif.');
            $comps = package_items((int)$pkg['id']);
            if (!$comps) throw new RuntimeException('Paket ' . $pkg['name'] . ' belum memiliki isi paket.');
            foreach ($comps as $ci) {
                if (!$ci['active']) throw new RuntimeException('Isi paket ' . $ci['name'] . ' sudah nonaktif — perbarui paket ' . $pkg['name'] . '.');
            }
            foreach (package_stock_needs((int)$pkg['id'], $qty) as $need) {
                if ($need['stock'] + 0.00001 < $need['need']) {
                    throw new RuntimeException('Stok ' . $need['name'] . ' tidak cukup untuk paket ' . $pkg['name']
                        . ' (butuh ' . qty_unit($need['need'], $need['unit']) . ', tersedia ' . qty_unit($need['stock'], $need['unit']) . ').');
                }
            }
            $line = round($qty * $price, 2);
            $subtotal += $line;
            /* HPP paket: pakai nilai paket (snapshot). Bila paket belum diisi HPP,
               hitung dari komponen saat ini sebagai cadangan. */
            $pkgHpp = (float)$pkg['hpp'] > 0 ? (float)$pkg['hpp'] : package_hpp_calc((int)$pkg['id']);
            $packages[] = ['id' => (int)$pkg['id'], 'name' => $pkg['name'], 'code' => $pkg['code'],
                'qty' => $qty, 'comps' => $comps];
            /* `pkg_kind` ('treatment' | 'product') dipakai perhitungan diskon member:
               cakupan "treatment saja" juga mencakup PAKET TREATMENT, dan
               "skincare saja" juga mencakup PAKET PRODUK (permintaan pemilik). */
            $clean[] = ['type' => 'package', 'id' => (int)$pkg['id'], 'name' => (string)$pkg['name'],
                'code' => (string)$pkg['code'], 'qty' => $qty, 'price' => $price, 'line' => $line,
                'hpp' => $pkgHpp, 'pkg_kind' => (string)($pkg['kind'] ?? 'treatment')];
            continue;
        }
        if ($type === 'skincare') {
            $row = one('SELECT * FROM skincare_products WHERE id = ?', [$iid]);
            $name = $row['name'] ?? ''; $code = $row['code'] ?? '';
            if (!$row) throw new RuntimeException('Produk skincare tidak ditemukan.');
            if ((int)$row['branch_id'] !== $branchId) throw new RuntimeException('Produk ' . $name . ' bukan milik cabang ini.');
            if ($row['status'] !== 'active') throw new RuntimeException('Produk ' . $name . ' sudah nonaktif dan tidak dapat dijual.');
            if ((float)$row['stock'] + 0.00001 < $qty) throw new RuntimeException('Stok ' . $name . ' tidak cukup untuk '
                . qty_unit($qty, $row['unit']) . '. Tersedia ' . qty_unit($row['stock'], $row['unit']) . '.');
        } else {
            $row = one('SELECT * FROM treatments WHERE id = ?', [$iid]);
            $name = $row['name'] ?? ''; $code = $row['code'] ?? '';
            if (!$row) throw new RuntimeException('Treatment tidak ditemukan.');
            if ((int)$row['branch_id'] !== $branchId) throw new RuntimeException('Treatment ' . $name . ' bukan milik cabang ini.');
            if ($row['status'] !== 'active') throw new RuntimeException('Treatment ' . $name . ' sudah nonaktif.');
        }
        $line = round($qty * $price, 2);
        $subtotal += $line;
        /* HPP per unit untuk perhitungan laba (menu Keuangan). Disimpan sebagai
           SNAPSHOT pada baris transaksi supaya mengubah HPP master di kemudian
           hari TIDAK mengubah laba transaksi yang sudah terjadi.
           - treatment : kolom `hpp` pada master treatment
           - skincare  : kolom `purchase_price` (harga beli = HPP produk) */
        $hppUnit = $type === 'skincare'
            ? (float)($row['purchase_price'] ?? 0)
            : (float)($row['hpp'] ?? 0);
        $clean[] = ['type' => $type, 'id' => $iid, 'name' => $name, 'code' => $code, 'qty' => $qty,
            'price' => $price, 'line' => $line, 'hpp' => $hppUnit];
    }
    if (!$clean) throw new RuntimeException('Transaksi gagal disimpan. Belum ada item treatment/skincare — bahan treatment saja tidak cukup untuk membuat transaksi (bahan tidak dijual ke pasien).');
    if ($discount > $subtotal) throw new RuntimeException('Diskon tidak boleh melebihi subtotal.');

    /* ---- Diskon member: dihitung SERVER dari data transaksi (level + cakupan
       barang). Angka yang dikirim halaman tidak pernah dipercaya. ---- */
    $yearTotalBefore = member_year_total($patientId);
    $isMemberNow = (int)($pat['member_card'] ?? 0) === 1;
    /* Kartu dapat terbuka dari DUA jalur:
       (1) satu transaksi ≥ ambang aktivasi ("beli member"), atau
       (2) akumulasi PERIODE berjalan (termasuk transaksi ini) ≥ ambang level terendah.
       Pada kedua jalur, transaksi yang membukanya juga mendapat potongan. */
    $baseMin = member_base_level()['min_year'];
    $hitBill = member_activate_amount() > 0 && $subtotal + 0.001 >= member_activate_amount();
    $hitYear = $baseMin > 0 && ($yearTotalBefore + $subtotal) + 0.001 >= $baseMin;

    /* Pasien BUKAN pemegang kartu: hanya boleh memakai kartu + mendapat diskon
       bila kasir sudah mengonfirmasi "Ya, aktifkan kartu". */
    $needActivation = $useMemberCard && !$isMemberNow;
    if ($needActivation && !$activateConfirm) {
        $useMemberCard = false;
        $memberWarn = 'Pasien ' . $pat['name'] . ' belum terdaftar sebagai pemegang Kartu Member, '
            . 'jadi diskon member TIDAK diterapkan. Bila pasien memang ingin dibuatkan kartu sekarang, '
            . 'pilih tombol konfirmasi "Ya, aktifkan kartu" pada bagian Kartu Member lalu simpan lagi.';
    }
    /* Kartu dibuat bila: (a) kasir mengonfirmasi aktivasi, atau (b) aturan
       Pengaturan terpenuhi (transaksi ≥ ambang / akumulasi periode). */
    $willActivate = ($needActivation && $activateConfirm) || $hitBill || $hitYear;
    $activateSource = ($needActivation && $activateConfirm && !$hitBill)
        ? 'manual' : ($hitBill ? 'beli' : 'transaksi');
    /* Cakupan diskon DIPILIH KASIR per transaksi (treatment / skincare /
       keduanya); setelan Pengaturan hanya menjadi nilai bawaannya. Nilai
       yang dikirim halaman selalu divalidasi di sini. */
    $memberScope = member_scope_norm((string)($in['member_scope'] ?? ''), member_scope());
    $member = member_discount_calc($clean, $subtotal, $useMemberCard, $yearTotalBefore, $willActivate, $isMemberNow, $memberScope);
    if ($member['amount'] > $subtotal - $discount) {
        $member['amount'] = round(max(0, $subtotal - $discount), 2);
    }
    /* Kode unik transfer/QRIS (3 digit, dipilih server): ditambahkan ke total
       supaya nominal transfer mudah dicocokkan dengan mutasi bank/QRIS.
       Pemanggil mengirim nilainya; nilai 0 berarti tanpa kode unik. */
    /* Kode unik: dipakai yang dikirim halaman (hanya bila bentuknya sah 101–999)
       supaya nominal yang ditampilkan ke pasien SAMA dengan yang tersimpan.
       Kalau tidak ada/tidak sah, dibuat di sini. */
    $uniqueCode = (int)($in['unique_code'] ?? 0);
    if (($uniqueCode < 101 || $uniqueCode > 999) && pay_unique_code_enabled()) {
        $uniqueCode = 0;   // pemanggil tidak menyertakan kode → ditangani di bawah
    }
    $total = round($subtotal - $discount - $member['amount'], 2);
    if ($uniqueCode > 0) $total = round($total + $uniqueCode, 2);
    $paid = (float)($in['paid'] ?? $total);
    if ($paid <= 0) $paid = $total;
    /* Transfer & QRIS: pelanggan mengirim nominal TEPAT seperti yang ditampilkan
       (sudah termasuk kode unik), jadi jumlah bayar disamakan dengan total.
       Uang tunai tetap memakai jumlah yang benar-benar diterima kasir. */
    if ($method !== 'Cash' && $paid + 0.01 < $total) $paid = $total;
    if ($paid < 0) throw new RuntimeException('Jumlah pembayaran tidak valid.');
    if ($paid + 0.01 < $total && $method === 'Cash') throw new RuntimeException('Jumlah pembayaran kurang dari total transaksi.');

    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $invoice = next_invoice($branchId);
        q('INSERT INTO orders (invoice_number, patient_id, user_id, cashier_name, branch_id, appointment_id,
                subtotal, discount, member_card, member_pct, member_discount, member_tier, member_scope,
                unique_code, total, status, payment_status, notes, created_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"paid","paid",?,datetime("now","localtime"))',
            [$invoice, $patientId, $user['id'], $user['name'], $branchId, $apptId ?: null, $subtotal, $discount,
             $useMemberCard ? 1 : 0, $member['pct'], $member['amount'], $member['label'] ?: null,
             $useMemberCard ? $memberScope : null, $uniqueCode, $total, $notes]);
        $orderId = (int)$pdo->lastInsertId();

        foreach ($clean as $c) {
            q('INSERT INTO order_items (order_id, item_type, treatment_id, skincare_id, package_id, item_code, item_name,
                    quantity, price, subtotal, hpp)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$orderId, $c['type'],
                 $c['type'] === 'treatment' ? $c['id'] : null,
                 $c['type'] === 'skincare' ? $c['id'] : null,
                 $c['type'] === 'package' ? $c['id'] : null,
                 $c['code'], $c['name'], $c['qty'], $c['price'], $c['line'], (float)($c['hpp'] ?? 0)]);
            if ($c['type'] === 'skincare') {
                inv_apply('skincare', $c['id'], -1 * $c['qty'], 'Penjualan', 'Penjualan invoice ' . $invoice,
                    ['ref_type' => 'order', 'ref_id' => $orderId]);
            }
        }
        /* PAKET: satu baris bertipe `package` (harga & HPP paket), lalu setiap
           komponen dicatat sebagai baris `package_item` berharga 0 + stok
           produk/bahan dikurangi lewat inv_apply(). */
        foreach ($packages as $pk) {
            foreach ($pk['comps'] as $ci) {
                q('INSERT INTO order_items (order_id, item_type, package_id, treatment_id, skincare_id, material_id,
                        item_code, item_name, quantity, price, subtotal, hpp)
                   VALUES (?,?,?,?,?,?,?,?,?,0,0,0)',
                    [$orderId, 'package_item', $pk['id'],
                     $ci['item_type'] === 'treatment' ? $ci['item_id'] : null,
                     $ci['item_type'] === 'skincare' ? $ci['item_id'] : null,
                     $ci['item_type'] === 'material' ? $ci['item_id'] : null,
                     $ci['code'], $pk['name'] . ' — ' . $ci['name'], $ci['quantity'] * $pk['qty']]);
            }
            package_apply_stock($pk['id'], $pk['qty'], -1,
                'Paket ' . $pk['name'] . ' pada invoice ' . $invoice,
                ['ref_type' => 'order', 'ref_id' => $orderId]);
        }
        /* Bahan treatment yang dipakai untuk proses treatment: dicatat pada
           transaksi (jejak pemakaian) + mengurangi stok inventory, tetapi
           TIDAK menambah harga transaksi dan tidak muncul di struk. */
        foreach ($materials as $m) {
            q('INSERT INTO order_items (order_id, item_type, material_id, item_code, item_name, quantity, price, subtotal)
               VALUES (?,?,?,?,?,?,0,0)',
                [$orderId, 'material', $m['id'], $m['code'], $m['name'], $m['qty']]);
            inv_apply('material', $m['id'], -1 * $m['qty'], 'Pemakaian Internal',
                'Pemakaian bahan treatment pada invoice ' . $invoice,
                ['ref_type' => 'order', 'ref_id' => $orderId]);
        }
        q('INSERT INTO payments (order_id, method, amount, status, ref_no, notes, paid_at, created_by)
           VALUES (?,?,?,"valid",?,?,datetime("now","localtime"),?)', [$orderId, $method, $paid, $refNo ?: null, 'Pembayaran ' . $invoice, $user['id']]);
        if ($apptId) {
            q('UPDATE appointments SET status = "Selesai", converted_order_id = ?, updated_at = datetime("now","localtime") WHERE id = ? AND branch_id = ?', [$orderId, $apptId, $branchId]);
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        $pdo->exec('ROLLBACK');
        throw new RuntimeException('Transaksi gagal disimpan. ' . $ex->getMessage());
    }
    audit('Tambah Transaksi', 'Kasir', $orderId, null, [
        'invoice' => $invoice, 'patient' => $pat['name'], 'subtotal' => $subtotal, 'discount' => $discount,
        'total' => $total, 'method' => $method, 'items' => count($clean),
        'bahan' => array_map(fn($m) => $m['name'] . ' × ' . qty_unit($m['qty'], $m['unit']), $materials),
        'kartu_member' => $useMemberCard ? 1 : 0, 'diskon_member' => $member['amount'], 'tier' => $member['label'],
        'cakupan_diskon' => $useMemberCard ? member_scope_text($memberScope) : '',
        'aktivasi_dikonfirmasi' => $activateConfirm ? 1 : 0,
    ], 'Transaksi baru' . ($materials ? ' (memakai ' . count($materials) . ' bahan treatment, tidak ditagihkan)' : '')
        . ($member['amount'] > 0 ? ' — diskon member ' . money($member['amount']) . ' (' . $member['label'] . ')' : ''));

    /* ---- Kartu member & level: disinkronkan dari aturan di Pengaturan ----
       Kartu aktif bila transaksi ini mencapai ambang aktivasi ATAU akumulasi
       periode berjalan sudah mencapai level terendah. Level naik otomatis mengikuti
       akumulasi periode berjalan; bila periode akumulasi berakhir (1/3/5 tahun),
       akumulasi mulai dari nol dan level dapat turun sesuai setelan Pengaturan. */
    $cardMsg = '';
    $sync = member_sync_level($patientId, $willActivate, null, $willActivate ? $activateSource : '');
    if ($sync['activated']) {
        $cardMsg = ' Pasien ini kini memiliki KARTU MEMBER baru (level '
            . $sync['status']['level']['label'] . ') dan kartunya dapat diunduh di halaman pasien.';
        audit('Kartu Member Diberikan', 'Pasien', $patientId, ['member' => 0],
            ['member' => 1, 'level' => $sync['to'], 'sumber' => $activateSource],
            'Kartu member otomatis dari transaksi ' . $invoice);
    } elseif ($sync['changed']) {
        $cardMsg = ' Level member pasien naik menjadi ' . $sync['status']['level']['label']
            . ' (akumulasi ' . member_period_label() . ' ' . money($sync['status']['year_total']) . ').';
        audit('Level Member Naik', 'Pasien', $patientId, ['level' => $sync['from']],
            ['level' => $sync['to'], 'akumulasi' => $sync['status']['year_total']],
            'Kenaikan level otomatis dari transaksi ' . $invoice);
    } elseif ($useMemberCard && !$member['eligible'] && $member['reason'] !== '') {
        $cardMsg = ' Catatan kartu member: ' . $member['reason'];
    }
    /* ---- Status pasien otomatis: "Baru" → "Lama" ----
       Setelah transaksi ini, pasien yang sudah mencapai minimal 3 HARI kunjungan
       berbeda otomatis berstatus "Lama" (lihat includes/patient.php). */
    try { patient_sync_type($patientId, (int)$user['id']); } catch (Throwable $e) { /* jangan gagalkan transaksi */ }

    /* ---- Kirim struk ke EMAIL pasien (opsional) ----
       Hanya berjalan bila: setelan "kirim otomatis" aktif, layanan email
       sudah dikonfigurasi, dan pasien punya alamat email. Hasilnya
       dilaporkan APA ADANYA — tidak pernah diklaim terkirim bila provider
       menolak. Bila gagal, kasir tetap bisa mengirim manual dari halaman
       detail transaksi (tombol Kirim Email). */
    $emailInfo = '';
    $patientEmail = trim((string)($pat['email'] ?? ''));
    if (setting('email_receipt_auto') === '1' && $patientEmail !== '') {
        if (!function_exists('send_receipt_email')) require_once __DIR__ . '/includes/mailer.php';
        if (!mail_configured()) {
            $emailInfo = ' Struk TIDAK dikirim ke email ' . $patientEmail
                . ' — layanan email belum dikonfigurasi di Pengaturan Sistem.';
        } else {
            $ro = receipt_data($orderId);
            if ($ro) {
                $res = send_receipt_email($ro, $patientEmail);
                if ($res['ok']) {
                    q('UPDATE orders SET receipt_status = ?, receipt_sent_at = datetime("now","localtime"),
                           receipt_sent_to = ?, receipt_sent_via = ? WHERE id = ?',
                       ['sent', $patientEmail, 'Email', $orderId]);
                    $emailInfo = ' Struk dikirim ke email ' . $patientEmail . '.';
                } else {
                    $emailInfo = ' Struk GAGAL dikirim ke email ' . $patientEmail . ' — ' . $res['error'];
                }
                q('INSERT INTO email_report_logs (recipient, period, status, message, created_at)
                   VALUES (?,?,?,?,datetime("now","localtime"))',
                  [$patientEmail, date('Y-m'), $res['ok'] ? 'sent' : 'failed',
                   ($res['ok'] ? 'Struk ' : 'Gagal kirim struk ') . $invoice . ($res['ok'] ? '' : ': ' . $res['error'])]);
            }
        }
    } elseif (setting('email_receipt_auto') === '1' && $patientEmail === '') {
        $emailInfo = ' Pasien belum punya email — tambahkan email di data pasien bila ingin mengirim struk.';
    }

return [
        'order_id' => $orderId,
        'invoice' => $invoice,
        'card_msg' => $cardMsg,
        'email_info' => $emailInfo,
        'member_warn' => $memberWarn,
        'unique_code' => (int)($in['unique_code'] ?? 0),
    ];
}
