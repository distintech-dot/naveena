<?php
/**
 * HAPUS SEMUA DATA per menu — khusus Super Admin.
 *
 * Pengaman berlapis (karena tindakan ini tidak dapat dibatalkan):
 *   1. Hanya Super Admin, dan hanya data pada cakupan cabang yang dipilih.
 *   2. Pratinjau jumlah baris yang akan terhapus (dihitung server, belum dihapus).
 *   3. Verifikasi kode sekali-pakai:
 *        - Bila layanan email aktif  -> kode 6 digit dikirim ke email Super Admin.
 *        - Bila email belum aktif    -> cara alternatif: masukkan ULANG password
 *          Super Admin DAN ketik jumlah baris yang tampil di pratinjau.
 *   4. Konfirmasi dua tahap di sisi antarmuka (modal + dialog sistem).
 *   5. Snapshot database otomatis dibuat SEBELUM penghapusan (dapat dipulihkan
 *      dari menu Backup Database), lalu seluruh proses dicatat di Audit Log.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/layout.php';
$user = require_login();
if (!is_super()) deny('Hanya Super Admin yang boleh menghapus seluruh data.');

/** Menu yang mendukung "hapus semua data" beserta definisi tabelnya. */
function purge_specs(): array
{
    return [
        'pasien' => [
            'label' => 'Data Pasien', 'back' => 'pasien.php',
            'scope_col' => 'branch_id', 'count_sql' => 'SELECT COUNT(*) FROM patients',
            'warn' => 'Menghapus SELURUH data pasien beserta seluruh riwayat yang terhubung: transaksi & pembayaran, '
                . 'rekam medis (termasuk foto klinis), dan reservasi. Tindakan ini mengosongkan dasar data klinik — '
                . 'biasanya hanya untuk memulai sistem dari nol.',
            'steps' => [
                /* Daftar treatment reservasi dihapus LEBIH DULU: PRAGMA foreign_keys=ON
                   sehingga baris anak tidak boleh ditinggal saat induknya dihapus. */
                ['label' => 'Treatment reservasi', 'sql' => 'DELETE FROM appointment_treatments WHERE appointment_id IN (SELECT id FROM appointments WHERE patient_id IN (SELECT id FROM patients{BR}))'],
                ['label' => 'Reservasi', 'sql' => 'DELETE FROM appointments WHERE patient_id IN (SELECT id FROM patients{BR})'],
                ['label' => 'Item transaksi', 'sql' => 'DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE patient_id IN (SELECT id FROM patients{BR}))'],
                ['label' => 'Pembayaran', 'sql' => 'DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE patient_id IN (SELECT id FROM patients{BR}))'],
                ['label' => 'Transaksi', 'sql' => 'DELETE FROM orders WHERE patient_id IN (SELECT id FROM patients{BR})'],
                ['label' => 'Foto rekam medis', 'sql' => 'DELETE FROM medical_record_photos WHERE medical_record_id IN (SELECT id FROM medical_records WHERE patient_id IN (SELECT id FROM patients{BR}))'],
                ['label' => 'Rekam medis', 'sql' => 'DELETE FROM medical_records WHERE patient_id IN (SELECT id FROM patients{BR})'],
                ['label' => 'Pasien', 'sql' => 'DELETE FROM patients{BR}'],
            ],
        ],
        'rekam_medis' => [
            'label' => 'Rekam Medis', 'back' => 'rekam_medis.php',
            'scope_col' => 'branch_id', 'count_sql' => 'SELECT COUNT(*) FROM medical_records',
            'warn' => 'Menghapus SELURUH rekam medis (catatan SOAP, assessment/diagnosa, kode ICD) beserta foto klinisnya. '
                . 'Data pasien, transaksi, dan stok tidak berubah.',
            'steps' => [
                ['label' => 'Foto rekam medis', 'sql' => 'DELETE FROM medical_record_photos WHERE medical_record_id IN (SELECT id FROM medical_records{BR})'],
                ['label' => 'Rekam medis', 'sql' => 'DELETE FROM medical_records{BR}'],
            ],
        ],
        'order' => [
            'label' => 'Riwayat Order', 'back' => 'order.php',
            'scope_col' => 'branch_id', 'count_sql' => 'SELECT COUNT(*) FROM orders',
            'warn' => 'Menghapus SELURUH transaksi beserta item dan pembayarannya. Stok produk skincare akan dikembalikan '
                . '(ditambahkan kembali) dan dicatat sebagai pergerakan stok Koreksi, begitu juga bahan treatment yang '
                . 'dipakai pada transaksi-transaksi itu. Laporan keuangan serta Top 5 menjadi kosong.',
            'steps' => [
                ['label' => 'Pembayaran', 'sql' => 'DELETE FROM payments WHERE order_id IN (SELECT id FROM orders{BR})'],
                ['label' => 'Item transaksi', 'sql' => 'DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders{BR})'],
                ['label' => 'Transaksi', 'sql' => 'DELETE FROM orders{BR}'],
            ],
            'stock_restore' => true,
        ],
        'treatment' => [
            'label' => 'Master Treatment', 'back' => 'treatment.php',
            'scope_col' => 'branch_id', 'count_sql' => 'SELECT COUNT(*) FROM treatments',
            'warn' => 'Menghapus SELURUH master treatment (nama, harga, durasi). Item treatment pada transaksi lama tetap '
                . 'menyimpan nama & harganya sehingga histori tidak rusak, tetapi tidak lagi terhubung ke master.',
            'steps' => [
                /* Paket bertipe treatment ikut terhapus (isinya menunjuk master
                   treatment, jadi anaknya dihapus lebih dulu). */
                ['label' => 'Isi paket treatment', 'sql' => 'DELETE FROM package_items WHERE package_id IN (SELECT id FROM packages WHERE kind=\'treatment\'{AND})'],
                ['label' => 'Paket treatment', 'sql' => 'DELETE FROM packages WHERE kind=\'treatment\'{AND}'],
                ['label' => 'Treatment', 'sql' => 'DELETE FROM treatments{BR}'],
            ],
        ],
        'skincare' => [
            'label' => 'Master Skincare', 'back' => 'skincare.php',
            'scope_col' => 'branch_id', 'count_sql' => 'SELECT COUNT(*) FROM skincare_products',
            'warn' => 'Menghapus SELURUH produk skincare beserta baris stoknya. Riwayat pergerakan stok tetap disimpan '
                . 'sebagai catatan dan tidak dihapus.',
            'steps' => [
                /* Paket produk ikut terhapus (isinya menunjuk master produk). */
                ['label' => 'Isi paket produk', 'sql' => 'DELETE FROM package_items WHERE package_id IN (SELECT id FROM packages WHERE kind=\'product\'{AND})'],
                ['label' => 'Paket produk', 'sql' => 'DELETE FROM packages WHERE kind=\'product\'{AND}'],
                ['label' => 'Baris stok', 'sql' => 'DELETE FROM inventory WHERE item_type = "skincare" AND item_id IN (SELECT id FROM skincare_products{BR})'],
                ['label' => 'Produk', 'sql' => 'DELETE FROM skincare_products{BR}'],
            ],
        ],
        'bahan' => [
            'label' => 'Bahan Treatment', 'back' => 'bahan.php',
            'scope_col' => 'branch_id', 'count_sql' => 'SELECT COUNT(*) FROM treatment_materials',
            'warn' => 'Menghapus SELURUH bahan treatment beserta baris stoknya. Riwayat pergerakan stok tetap disimpan.',
            'steps' => [
                ['label' => 'Baris stok', 'sql' => 'DELETE FROM inventory WHERE item_type = "material" AND item_id IN (SELECT id FROM treatment_materials{BR})'],
                ['label' => 'Bahan', 'sql' => 'DELETE FROM treatment_materials{BR}'],
            ],
        ],
        'suppliers' => [
            'label' => 'Supplier', 'back' => 'suppliers.php',
            'scope_col' => null, 'count_sql' => 'SELECT COUNT(*) FROM suppliers',
            'warn' => 'Menghapus SELURUH data supplier. Nama supplier pada produk/bahan tetap tersimpan sebagai teks '
                . 'sehingga histori tidak rusak.',
            'steps' => [['label' => 'Supplier', 'sql' => 'DELETE FROM suppliers']],
        ],
        'inventory_movement' => [
            'label' => 'Stok & Movement', 'back' => 'inventory_movement.php',
            'scope_col' => 'branch_id', 'count_sql' => 'SELECT COUNT(*) FROM inventory_movements',
            'warn' => 'Menghapus SELURUH riwayat pergerakan stok (jejak audit stok). Jumlah stok produk saat ini TIDAK berubah — '
                . 'hanya riwayatnya yang hilang, sehingga penelusuran asal-usul stok setelah ini tidak dapat dilakukan.',
            'steps' => [['label' => 'Pergerakan stok', 'sql' => 'DELETE FROM inventory_movements{BR}']],
        ],
        /* ---- SATU TOMBOL: kosongkan SELURUH data operasional ----
           Dipakai saat aplikasi akan diduplikasi untuk klinik lain: semua data
           klinik dihapus, tetapi MANAJEMEN USER, hak akses, Pengaturan Sistem,
           kamus ICD, dan maksimal 2 cabang (contoh/demo) tetap tersimpan supaya
           login & fungsinya tetap berjalan. */
        'semua' => [
            'label' => 'SEMUA Data Operasional', 'back' => 'developer.php#hapusdata',
            'scope_col' => null, 'count_sql' => '', 'locked_scope' => 'all',
            'trim_branches' => 2,
            /* Nominal biaya operasional pada menu Keuangan ikut dikosongkan. */
            'reset_finance' => true,
            'warn' => 'Menghapus SELURUH data operasional klinik: pasien, rekam medis (beserta foto), reservasi, '
                . 'transaksi & pembayaran, master treatment/skincare/bahan, supplier, dokter & terapis, '
                . 'seluruh riwayat stok & movement, audit log, dan riwayat import. '
                . 'Data yang TIDAK dihapus: manajemen user beserta hak akses, Pengaturan Sistem, kamus ICD, '
                . 'serta maksimal 2 cabang pertama (sebagai contoh). Gunakan tombol "Isi Data Demo" setelah ini '
                . 'bila ingin langsung melihat aplikasi berisi data contoh.',
            'keep_rows' => [
                'Manajemen User & hak akses (Super Admin, Direktur, Admin/Dokter, Kasir)',
                'Pengaturan Sistem (identitas klinik, tema, email, WhatsApp, pembayaran, backup, pemeliharaan)',
                'Kamus ICD-10 & ICD-9-CM (data rujukan, bukan data klinik)',
                'Maksimal 2 cabang pertama — cabang selebihnya dihapus',
                'Struktur biaya operasional pada menu Keuangan (nama & periodenya tetap; nominalnya dikosongkan)',
                'Berkas backup yang sudah tersimpan (termasuk snapshot pengaman tindakan ini)',
            ],
            'steps' => [
                ['label' => 'Treatment reservasi', 'sql' => 'DELETE FROM appointment_treatments'],
                ['label' => 'Foto rekam medis', 'sql' => 'DELETE FROM medical_record_photos'],
                ['label' => 'Reservasi', 'sql' => 'DELETE FROM appointments'],
                ['label' => 'Rekam medis', 'sql' => 'DELETE FROM medical_records'],
                ['label' => 'Item transaksi', 'sql' => 'DELETE FROM order_items'],
                ['label' => 'Isi paket', 'sql' => 'DELETE FROM package_items'],
                ['label' => 'Paket treatment & produk', 'sql' => 'DELETE FROM packages'],
                ['label' => 'Pembayaran', 'sql' => 'DELETE FROM payments'],
                ['label' => 'Transaksi', 'sql' => 'DELETE FROM orders'],
                ['label' => 'Pasien', 'sql' => 'DELETE FROM patients'],
                ['label' => 'Pergerakan stok', 'sql' => 'DELETE FROM inventory_movements'],
                ['label' => 'Baris stok', 'sql' => 'DELETE FROM inventory'],
                ['label' => 'Master treatment', 'sql' => 'DELETE FROM treatments'],
                ['label' => 'Master skincare', 'sql' => 'DELETE FROM skincare_products'],
                ['label' => 'Bahan treatment', 'sql' => 'DELETE FROM treatment_materials'],
                ['label' => 'Supplier', 'sql' => 'DELETE FROM suppliers'],
                ['label' => 'Dokter', 'sql' => 'DELETE FROM doctors'],
                ['label' => 'Terapis', 'sql' => 'DELETE FROM therapists'],
                ['label' => 'Antrean pembayaran gateway', 'sql' => 'DELETE FROM pay_pending'],
                ['label' => 'Rincian galat import', 'sql' => 'DELETE FROM import_errors'],
                ['label' => 'Riwayat import', 'sql' => 'DELETE FROM import_batches'],
                ['label' => 'Riwayat email laporan', 'sql' => 'DELETE FROM email_report_logs'],
                ['label' => 'Audit log', 'sql' => 'DELETE FROM audit_logs'],
            ],
        ],
    ];
}

/** Kode verifikasi sekali-pakai (dikirim via email bila layanan email aktif). */
function purge_code_request(string $menu, string $scopeKey): array
{
    $code = (string)random_int(100000, 999999);
    set_setting('purge_code_hash', password_hash($code, PASSWORD_DEFAULT));
    set_setting('purge_code_menu', $menu);
    set_setting('purge_code_scope', $scopeKey);
    set_setting('purge_code_expires', (string)(time() + 600));   // 10 menit

    $to = trim((string)setting('email_recipient')) ?: 'distintech@gmail.com';
    $html = email_wrap_html('Kode Verifikasi Hapus Data', '<p>Permintaan <strong>hapus semua data</strong> dibuat untuk menu '
        . '<strong>' . e(purge_specs()[$menu]['label'] ?? $menu) . '</strong> dengan cakupan <strong>' . e($scopeKey) . '</strong>.</p>'
        . '<p style="font-size:26px;letter-spacing:6px;font-weight:700;color:#B3261E">' . e($code) . '</p>'
        . '<p>Kode berlaku 10 menit dan hanya dapat dipakai sekali. Bila Anda tidak meminta ini, abaikan email ini '
        . 'dan periksa siapa yang memiliki akses Super Admin.</p>');
    $err = '';
    $sent = mail_configured() ? send_email($to, 'Kode Verifikasi Hapus Data — ' . clinic_name(), $html, $err) : false;
    audit('Permintaan Kode Hapus Semua Data', 'Pengaturan', null, null,
        ['menu' => $menu, 'cakupan' => $scopeKey, 'email_terkirim' => $sent], $sent ? 'Kode dikirim ke ' . $to : (string)$err);
    return ['sent' => $sent, 'email' => $to, 'error' => $err];
}
function purge_code_verify(string $code, string $menu, string $scopeKey): bool
{
    if ((int)setting('purge_code_expires', '0') < time()) return false;
    if (setting('purge_code_menu') !== $menu) return false;
    if (setting('purge_code_scope') !== $scopeKey) return false;
    $hash = (string)setting('purge_code_hash');
    if ($hash === '' || !password_verify($code, $hash)) return false;
    set_setting('purge_code_hash', '');       // sekali pakai
    set_setting('purge_code_expires', '0');
    return true;
}

/** Snapshot database sebelum penghapusan (terkompres, sama seperti backup biasa). */
function purge_snapshot(string $menu): string
{
    require_once __DIR__ . '/includes/backup_lib.php';
    /* enforce=false: snapshot pengaman WAJIB dibuat walau penyimpanan backup
       penuh — justru saat itulah salinan pengaman paling diperlukan. */
    $res = backup_create('Snapshot otomatis sebelum HAPUS SEMUA (' . $menu . ')',
        current_user()['id'] ?? null, ['prefix' => 'sebelum-hapus-', 'enforce' => false]);
    if (!$res['ok']) {
        throw new RuntimeException('Gagal membuat snapshot pengaman sebelum menghapus (' . $res['error'] . '). Penghapusan dibatalkan.');
    }
    return $res['file'];
}

$specs = purge_specs();
$menu = (string)gp('menu', '');
if (!isset($specs[$menu])) {
    flash('Menu hapus-data tidak dikenal.', 'error');
    header('Location: dashboard.php');
    exit;
}
$spec = $specs[$menu];

/* Cakupan cabang: semua cabang atau satu cabang terpilih. Spesifikasi gabungan
   (mis. "semua data") selalu berlaku untuk SELURUH cabang — pemilih cabang
   disembunyikan dan nilai dari URL/POST diabaikan. */
$scopeVal = gp('scope', 'all');
$scopeId = null;
if (!empty($spec['locked_scope'])) {
    $scopeVal = 'all';
} elseif ($scopeVal !== 'all') {
    $scopeId = (int)$scopeVal;
    if (!one('SELECT id FROM branches WHERE id = ?', [$scopeId])) $scopeId = null;
    $scopeVal = $scopeId === null ? 'all' : (string)$scopeId;
}
$scopeKey = $scopeId === null ? 'Semua Cabang' : (string)scalar('SELECT name FROM branches WHERE id = ?', [$scopeId], '-');

/**
 * Bersihkan baris data operasional yang tertinggal di basis data CENTRAL untuk
 * cabang yang TIDAK dipertahankan.
 *
 * Ditemukan dari kegagalan nyata: `DELETE FROM branches` selalu ditolak
 * ("FOREIGN KEY constraint failed") karena central masih memuat data contoh
 * dokter/terapis (tabel operasional) yang ber-FK ke `branches`. Fungsi ini
 * menyisir sendiri tabel mana pun di central yang punya kolom `branch_id`
 * ber-FK ke `branches`, jadi tidak bergantung daftar nama tabel.
 *
 * PENTING: memakai KONEKSI YANG SAMA (`main` = central). Membuka koneksi KEDUA
 * ke central saat transaksi tulis sedang berjalan membuat pernyataan kedua
 * terblokir kunci tulis dan menunggu `busy_timeout` (5 detik) SETIAP pernyataan —
 * pernah membuat "Hapus Semua Data" memakan 55 detik.
 */
function purge_central_leftovers(array $keepIds): int
{
    $dibuang = 0;
    try {
        $c = db();      // main = central.sqlite (routed) — jangan buka koneksi kedua
        $tabel = $c->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(PDO::FETCH_ASSOC);
        $keep = implode(',', array_map('intval', $keepIds ?: [0]));
        foreach ($tabel as $t) {
            $sql = (string)$t['sql'];
            $nama = (string)$t['name'];
            if (!preg_match('/REFERENCES\s+[`"\[]?branches/i', $sql)) continue;
            /* `users` DIKECUALIKAN: akun pengguna bukan data operasional dan sudah
               dipindahkan ke cabang yang dipertahankan oleh pemanggil. Tanpa
               pengecualian ini, akun staf yang terpaut cabang terakhir ikut terhapus
               (terjadi: 8 akun menjadi 6). */
            if ($nama === 'users') continue;
            /* Kolomnya harus ada; kalau tidak ada, tabel itu tidak menyimpan cabang. */
            $kol = $c->query('PRAGMA table_info(' . $c->quote($nama) . ')')->fetchAll(PDO::FETCH_ASSOC);
            $punya = false;
            foreach ($kol as $k) if ((string)$k['name'] === 'branch_id') { $punya = true; break; }
            if (!$punya) continue;
            try {
                $dibuang += (int)$c->exec('DELETE FROM main."' . $nama
                    . '" WHERE branch_id IS NOT NULL AND branch_id NOT IN (' . $keep . ')');
            } catch (Throwable $e) { /* dilewati — dilaporkan lewat jumlah */ }
        }
    } catch (Throwable $e) { /* central tidak tersedia */ }
    return $dibuang;
}

/** Sisipkan pembatas cabang ke SQL spesifikasi. */
function purge_sql(string $sql, ?int $scopeId, ?string $scopeCol): string
{
    /* {BR}  → ' WHERE kolom = N'  (untuk pernyataan DELETE utama)
       {AND} → ' AND kolom = N'   (untuk SYARAT TAMBAHAN, mis. di dalam subquery
                                   seperti "(SELECT id FROM packages WHERE kind='product'{AND})".
                                   Tanpa penanda ini, pemakaian {BR} di dalam subquery
                                   menghasilkan "WHERE ... WHERE ..." yang tidak sah.) */
    $hasil = $sql;
    if (strpos($hasil, '{AND}') !== false) {
        $hasil = str_replace('{AND}', ($scopeId === null || $scopeCol === null)
            ? '' : ' AND ' . $scopeCol . ' = ' . (int)$scopeId, $hasil);
    }
    if (strpos($hasil, '{BR}') !== false) {
        $hasil = str_replace('{BR}', ($scopeId === null || $scopeCol === null)
            ? '' : ' WHERE ' . $scopeCol . ' = ' . (int)$scopeId, $hasil);
    }
    return $hasil;
}

/** Hitung & rinci apa yang akan terhapus (tanpa mengubah apa pun). */
function purge_preview(array $spec, ?int $scopeId): array
{
    $col = $spec['scope_col'];
    $detail = [];
    foreach ($spec['steps'] as $st) {
        $sql = purge_sql($st['sql'], $scopeId, $col);
        // ubah DELETE menjadi COUNT untuk pratinjau
        $cnt = preg_replace('/^DELETE FROM\s+(\w+)/i', 'SELECT COUNT(*) FROM $1', $sql, 1);
        $detail[$st['label']] = (int)scalar($cnt);
    }
    /* Cabang berlebih yang akan dihapus (hanya untuk spesifikasi yang memangkas
       cabang, mis. "semua data" — sisakan 2 cabang sebagai contoh). */
    $trim = (int)($spec['trim_branches'] ?? 0);
    if ($trim > 0) {
        $detail['Cabang (sisakan ' . $trim . ' sebagai contoh)'] = max(0, (int)scalar('SELECT COUNT(*) FROM branches') - $trim);
    }
    /* "SEMUA data": nominal biaya operasional pada menu Keuangan ikut dikosongkan
       (barisnya tetap ada sebagai kerangka) supaya klinik baru tidak mewarisi
       angka biaya milik klinik sebelumnya. */
    if (!empty($spec['reset_finance'])) {
        $detail['Nominal biaya operasional dikosongkan (Keuangan)'] =
            (int)scalar('SELECT COUNT(*) FROM finance_cost_amounts');
    }
    $countSql = (string)($spec['count_sql'] ?? '');
    if ($countSql === '') {
        /* Spesifikasi gabungan (tanpa satu tabel utama): total = jumlah rincian. */
        $total = array_sum($detail);
    } else {
        if ($scopeId !== null && $col !== null) {
            if (preg_match('/FROM\s+(\w+)/i', $countSql, $m)) {
                $countSql .= ' WHERE ' . $col . ' = ' . (int)$scopeId;
            }
        }
        $total = (int)scalar($countSql);
    }
    return ['total' => $total, 'detail' => $detail];
}

$stage = (string)gp('stage', 'preview');
$preview = purge_preview($spec, $scopeId);

/* ---------------- Aksi ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        $scopeId = ($_POST['scope'] ?? 'all') === 'all' ? null : (int)$_POST['scope'];
        if (!empty($spec['locked_scope'])) $scopeId = null;
        $scopeVal = $scopeId === null ? 'all' : (string)$scopeId;
        $scopeKey = $scopeId === null ? 'Semua Cabang' : (string)scalar('SELECT name FROM branches WHERE id = ?', [$scopeId], '-');
        $preview = purge_preview($spec, $scopeId);

        if ($act === 'send_code') {
            $res = purge_code_request($menu, $scopeKey);
            flash($res['sent']
                ? 'Kode verifikasi dikirim ke ' . $res['email'] . '. Berlaku 10 menit.'
                : 'Email belum dapat dipakai untuk mengirim kode (' . ($res['error'] ?: 'belum dikonfigurasi')
                  . '). Gunakan cara alternatif: masukkan ulang password Super Admin dan jumlah data.',
                $res['sent'] ? 'success' : 'warning');
            header('Location: purge.php?menu=' . urlencode($menu) . '&scope=' . urlencode($scopeVal) . '&stage=verify');
            exit;
        }

        if ($act === 'verify_password') {
            // Cara alternatif saat email belum aktif
            $pass = (string)($_POST['password'] ?? '');
            $typed = trim((string)($_POST['typed_count'] ?? ''));
            if (!password_verify($pass, (string)$user['password_hash'])) {
                throw new RuntimeException('Password Super Admin tidak sesuai.');
            }
            if ((int)$typed !== (int)$preview['total']) {
                throw new RuntimeException('Jumlah data yang diketik (' . $typed . ') tidak sama dengan hasil pratinjau ('
                    . num($preview['total']) . '). Ketik ulang dengan teliti.');
            }
            if ($preview['total'] <= 0) throw new RuntimeException('Tidak ada data untuk dihapus pada cakupan ini.');
            $_SESSION['purge_ok'] = ['menu' => $menu, 'scope' => $scopeKey, 'expires' => time() + 600, 'method' => 'password'];
            flash('Verifikasi berhasil (password + jumlah data). Lanjutkan dengan konfirmasi terakhir di bawah.', 'success');
            header('Location: purge.php?menu=' . urlencode($menu) . '&scope=' . urlencode($scopeVal) . '&stage=confirm');
            exit;
        }

        if ($act === 'verify_code') {
            $code = trim((string)($_POST['code'] ?? ''));
            if (!purge_code_verify($code, $menu, $scopeKey)) {
                throw new RuntimeException('Kode verifikasi salah atau sudah kedaluwarsa. Minta kode baru bila perlu.');
            }
            $_SESSION['purge_ok'] = ['menu' => $menu, 'scope' => $scopeKey, 'expires' => time() + 600, 'method' => 'code'];
            flash('Kode verifikasi benar. Lanjutkan dengan konfirmasi terakhir di bawah.', 'success');
            header('Location: purge.php?menu=' . urlencode($menu) . '&scope=' . urlencode($scopeVal) . '&stage=confirm');
            exit;
        }

        if ($act === 'execute') {
            $ok = $_SESSION['purge_ok'] ?? null;
            if (!$ok || $ok['menu'] !== $menu || $ok['scope'] !== $scopeKey || (int)$ok['expires'] < time()) {
                throw new RuntimeException('Verifikasi belum dilakukan atau sudah kedaluwarsa. Ulangi dari awal.');
            }
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') throw new RuntimeException('Alasan penghapusan wajib diisi (tercatat di audit log).');
            if ($preview['total'] <= 0) throw new RuntimeException('Tidak ada data untuk dihapus.');

            $snapshot = purge_snapshot($menu);
            $deleted = [];
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                // Kembalikan stok bila menghapus transaksi
                if (!empty($spec['stock_restore'])) {
                    $bs = $scopeId === null ? '' : ' AND o.branch_id = ?';
                    $bp = $scopeId === null ? [] : [$scopeId];
                    $items = all("SELECT oi.skincare_id, SUM(oi.quantity) q
                                  FROM order_items oi JOIN orders o ON o.id = oi.order_id
                                  WHERE oi.item_type='skincare' AND oi.skincare_id IS NOT NULL {$bs}
                                  GROUP BY oi.skincare_id", $bp);
                    foreach ($items as $it) {
                        $prod = one('SELECT * FROM skincare_products WHERE id = ?', [(int)$it['skincare_id']]);
                        if (!$prod) continue;   // produk sudah tidak ada -> lewati
                        inv_apply('skincare', (int)$it['skincare_id'], (float)$it['q'], 'Koreksi',
                            'Pengembalian stok karena hapus semua riwayat order', ['ref_type' => 'purge']);
                    }
                    if ($items) $deleted['Stok dikembalikan (produk)'] = count($items);
                    /* Bahan treatment yang dipakai pada transaksi yang dihapus juga
                       dikembalikan, supaya stok bahan tidak berkurang tanpa jejak. */
                    $mats = all("SELECT oi.material_id, SUM(oi.quantity) q
                                 FROM order_items oi JOIN orders o ON o.id = oi.order_id
                                 WHERE oi.item_type='material' AND oi.material_id IS NOT NULL {$bs}
                                 GROUP BY oi.material_id", $bp);
                    foreach ($mats as $it) {
                        $mat = one('SELECT * FROM treatment_materials WHERE id = ?', [(int)$it['material_id']]);
                        if (!$mat) continue;    // bahan sudah tidak ada -> lewati
                        inv_apply('material', (int)$it['material_id'], (float)$it['q'], 'Koreksi',
                            'Pengembalian pemakaian bahan karena hapus semua riwayat order', ['ref_type' => 'purge']);
                    }
                    if ($mats) $deleted['Stok dikembalikan (bahan treatment)'] = count($mats);
                    /* Isi PAKET (produk & bahan) pada transaksi yang dihapus juga
                       dikembalikan stoknya. */
                    $pki = all("SELECT oi.skincare_id, oi.material_id, oi.item_type, SUM(oi.quantity) q
                                FROM order_items oi JOIN orders o ON o.id = oi.order_id
                                WHERE oi.item_type='package_item' AND (oi.skincare_id IS NOT NULL OR oi.material_id IS NOT NULL) {$bs}
                                GROUP BY oi.skincare_id, oi.material_id", $bp);
                    $pkn = 0;
                    foreach ($pki as $it) {
                        if (!empty($it['skincare_id'])) {
                            $prod = one('SELECT id FROM skincare_products WHERE id = ?', [(int)$it['skincare_id']]);
                            if (!$prod) continue;
                            inv_apply('skincare', (int)$it['skincare_id'], (float)$it['q'], 'Koreksi',
                                'Pengembalian stok isi paket karena hapus semua riwayat order', ['ref_type' => 'purge']);
                            $pkn++;
                        } elseif (!empty($it['material_id'])) {
                            $mat = one('SELECT id FROM treatment_materials WHERE id = ?', [(int)$it['material_id']]);
                            if (!$mat) continue;
                            inv_apply('material', (int)$it['material_id'], (float)$it['q'], 'Koreksi',
                                'Pengembalian stok isi paket karena hapus semua riwayat order', ['ref_type' => 'purge']);
                            $pkn++;
                        }
                    }
                    if ($pkn) $deleted['Stok dikembalikan (isi paket)'] = $pkn;
                }
                foreach ($spec['steps'] as $st) {
                    $sql = purge_sql($st['sql'], $scopeId, $spec['scope_col']);
                    $cnt = preg_replace('/^DELETE FROM\s+(\w+)/i', 'SELECT COUNT(*) FROM $1', $sql, 1);
                    $n = (int)scalar($cnt);
                    if ($n > 0) {
                        /* WAJIB lewat q(): pernyataan harus dikualifikasi ke berkas
                           cabang (temp view hanya bisa dibaca, tidak bisa diubah), dan
                           operasi tanpa pembatas cabang dipecah per berkas cabang. */
                        q($sql);
                        $deleted[$st['label']] = $n;
                    }
                }
                /* Kosongkan nominal biaya operasional (menu Keuangan) — lihat
                   catatan pada pratinjau di atas. */
                if (!empty($spec['reset_finance'])) {
                    $nf = (int)scalar('SELECT COUNT(*) FROM finance_cost_amounts');
                    if ($nf > 0) {
                        /* Nominal tiap cakupan dikosongkan dan tidak ada yang
                           berlaku lagi; daftar pos biaya (nama & periode) tetap
                           tersimpan sebagai kerangka. */
                        $pdo->exec('UPDATE finance_cost_amounts SET amount = 0, applicable = 0,
                                    updated_at = datetime("now","localtime")');
                        $deleted['Nominal biaya operasional dikosongkan (Keuangan)'] = $nf;
                    }
                }
                /* Spesifikasi gabungan: sisakan sejumlah cabang sebagai contoh.
                   Akun user yang terpaut cabang terhapus dipindahkan ke cabang
                   pertama yang dipertahankan supaya FK & login tetap sehat. */
                $trim = (int)($spec['trim_branches'] ?? 0);
                if ($trim > 0) {
                    $all = array_column(all('SELECT id FROM branches ORDER BY id'), 'id');
                    $keep = array_slice($all, 0, $trim);
                    if ($keep) {
                        $keepList = implode(',', array_map('intval', $keep));
                        /* Sisa baris data operasional di CENTRAL (mis. data contoh
                           dokter/terapis pada basis data lama) dibersihkan lebih dulu:
                           baris seperti itu masih ber-FK ke `branches` sehingga
                           penghapusan cabang selalu gagal. */
                        purge_central_leftovers($keep);
                        $pdo->exec('UPDATE users SET branch_id = ' . (int)$keep[0]
                            . ' WHERE branch_id IS NOT NULL AND branch_id NOT IN (' . $keepList . ')');
                        $nBranch = (int)scalar('SELECT COUNT(*) FROM branches WHERE id NOT IN (' . $keepList . ')');
                        if ($nBranch > 0) {
                            $pdo->exec('DELETE FROM branches WHERE id NOT IN (' . $keepList . ')');
                            $deleted['Cabang (sisakan ' . count($keep) . ' sebagai contoh)'] = $nBranch;
                        }
                    }
                }
                $pdo->exec('COMMIT');
            } catch (Throwable $ex) {
                $pdo->exec('ROLLBACK');
                throw new RuntimeException('Penghapusan gagal dan dibatalkan seluruhnya (tidak ada data yang berubah): ' . $ex->getMessage());
            }
            unset($_SESSION['purge_ok']);
            audit('HAPUS SEMUA DATA', $spec['label'], null,
                ['menu' => $menu, 'cakupan' => $scopeKey, 'pratinjau' => $preview['total']],
                ['terhapus' => $deleted, 'snapshot' => $snapshot, 'verifikasi' => $ok['method']], $reason);
            flash('Selesai. ' . num(array_sum(array_intersect_key($deleted, $preview['detail'])))
                . ' baris data ' . strip_tags($spec['label']) . ' dihapus (cakupan: ' . $scopeKey . '). '
                . 'Snapshot pengaman: ' . $snapshot . ' — dapat dipulihkan dari menu Backup Database.',
                'warning');
            header('Location: ' . $spec['back']);
            exit;
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
        header('Location: purge.php?menu=' . urlencode($menu) . '&scope=' . urlencode($scopeVal) . '&stage=' . urlencode($stage));
        exit;
    }
}

$verified = isset($_SESSION['purge_ok']) && $_SESSION['purge_ok']['menu'] === $menu
    && $_SESSION['purge_ok']['scope'] === $scopeKey && (int)$_SESSION['purge_ok']['expires'] >= time();
$emailReady = mail_configured();

page_head('Hapus Semua Data — ' . strip_tags($spec['label']), '');
?>
<div class="page-head">
  <div>
    <h2 style="color:#B3261E">⚠ Hapus Semua Data — <?= $spec['label'] ?></h2>
    <p class="muted">Khusus Super Admin · tindakan permanen, tidak dapat dibatalkan.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e($spec['back']) ?>">Kembali ke <?= strip_tags($spec['label']) ?></a>
    <a class="btn" href="backup.php"><?= icon('database') ?> Backup Database</a>
  </div>
</div>

<div class="alert alert-error"><?= $spec['warn'] ?></div>

<?php if (!empty($spec['keep_rows'])): ?>
<div class="card">
  <div class="card-head"><h3>Data yang TIDAK Dihapus</h3>
    <span><?= badge('Tetap utuh', 'green') ?></span></div>
  <div class="card-body">
    <ul style="margin:0;padding-left:20px">
      <?php foreach ($spec['keep_rows'] as $k): ?><li><?= e($k) ?></li><?php endforeach; ?>
    </ul>
    <div class="notice mt-2">
      Setelah pengosongan, aplikasi tetap berjalan normal (login, master data, dan seluruh menu aktif).
      Untuk mengisi kembali dengan data contoh, gunakan <strong>Isi Data Demo</strong> di
      <a href="developer.php#datademo">Pengaturan Sistem</a>.
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3>1. Pilih Cakupan &amp; Lihat Pratinjau</h3></div>
  <?php if (empty($spec['locked_scope'])): ?>
  <form method="get" class="filter-bar" style="background:#FFF6F5">
    <input type="hidden" name="menu" value="<?= e($menu) ?>">
    <div class="field"><label>Cakupan Cabang</label>
      <select class="input input-sm" name="scope">
        <option value="all"<?= $scopeId === null ? ' selected' : '' ?>>Semua Cabang (total)</option>
        <?php foreach (branches() as $b): ?>
          <option value="<?= (int)$b['id'] ?>"<?= $scopeId === (int)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <button class="btn btn-sm btn-primary" type="submit">Terapkan Cakupan</button>
    <span class="muted">Cakupan terpilih: <strong><?= e($scopeKey) ?></strong> — belum ada data yang dihapus.</span>
  </form>
  <?php else: ?>
  <div class="filter-bar" style="background:#FFF6F5">
    <span class="muted">Cakupan: <strong>Seluruh data (semua cabang)</strong> — belum ada data yang dihapus.
      Data pelanggan/pengguna, Pengaturan Sistem, dan kamus ICD tetap tersimpan.</span>
  </div>
  <?php endif; ?>
  <div class="card-body">
    <div class="grid g3">
      <div class="stat" style="border-color:#F5C9C6">
        <span class="lbl">Data <?= strip_tags($spec['label']) ?> Akan Dihapus</span>
        <span class="val" style="color:#B3261E"><?= num($preview['total']) ?></span>
        <span class="sub">cakupan <?= e($scopeKey) ?></span>
      </div>
      <div class="stat"><span class="lbl">Total Baris Terdampak</span>
        <span class="val"><?= num(array_sum($preview['detail'])) ?></span>
        <span class="sub">termasuk data turunan (tabel terkait)</span></div>
      <div class="stat"><span class="lbl">Snapshot Pengaman</span>
        <span class="val" style="font-size:1rem">Otomatis dibuat</span>
        <span class="sub">sesaat sebelum penghapusan, dapat dipulihkan</span></div>
    </div>
    <?php if ($preview['detail']): ?>
      <div class="table-wrap mt-2">
        <table class="tbl">
          <thead><tr><th>Tabel / Bagian</th><th class="num">Baris</th></tr></thead>
          <tbody>
          <?php foreach ($preview['detail'] as $lbl => $n): ?>
            <tr><td><?= e($lbl) ?></td><td class="num"><?= num($n) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th>Total</th><th class="num"><?= num(array_sum($preview['detail'])) ?></th></tr></tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($preview['total'] <= 0): ?>
  <div class="alert alert-info">Tidak ada data <?= strip_tags($spec['label']) ?> pada cakupan <strong><?= e($scopeKey) ?></strong>.
    Tidak ada yang perlu dihapus.</div>
<?php else: ?>
<div class="card">
  <div class="card-head"><h3>2. Verifikasi Identitas</h3>
    <span><?= $emailReady ? badge('Kode dikirim ke email', 'green') : badge('Cara alternatif (email belum aktif)', 'yellow') ?></span></div>
  <div class="card-body">
    <?php if ($emailReady): ?>
      <p class="muted">Kode verifikasi 6 digit akan dikirim ke <strong><?= e(setting('email_recipient')) ?></strong>.
        Kode berlaku 10 menit dan hanya dapat dipakai sekali.</p>
      <form method="post" class="flex gap-sm flex-wrap" style="align-items:flex-end">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_code">
        <input type="hidden" name="scope" value="<?= e($scopeVal) ?>">
        <div class="field"><label>Langkah A</label>
          <button class="btn btn-primary" type="submit">Kirim Kode Verifikasi ke Email</button></div>
      </form>
      <form method="post" class="flex gap-sm flex-wrap mt-2" style="align-items:flex-end">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify_code">
        <input type="hidden" name="scope" value="<?= e($scopeVal) ?>">
        <div class="field"><label>Langkah B — Kode dari Email</label>
          <input class="input" name="code" inputmode="numeric" maxlength="6" placeholder="6 digit" required></div>
        <button class="btn" type="submit">Verifikasi Kode</button>
      </form>
    <?php else: ?>
      <div class="alert alert-warning">
        Layanan email belum dikonfigurasi, jadi kode verifikasi tidak dapat dikirim. Sistem memakai
        <strong>cara alternatif yang sama kuatnya</strong>: masukkan ulang <strong>password Super Admin</strong> Anda
        dan ketik <strong>jumlah baris hasil pratinjau</strong> (<strong><?= num($preview['total']) ?></strong>).
        Ini mencegah penghapusan karena salah klik.
      </div>
      <form method="post" class="flex gap-sm flex-wrap" style="align-items:flex-end">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify_password">
        <input type="hidden" name="scope" value="<?= e($scopeVal) ?>">
        <div class="field"><label>Email Super Admin</label><input class="input" value="<?= e($user['email']) ?>" disabled></div>
        <div class="field"><label>Password Super Admin <span class="req">*</span></label>
          <input class="input" type="password" name="password" required></div>
        <div class="field"><label>Ketik jumlah data: <strong><?= num($preview['total']) ?></strong> <span class="req">*</span></label>
          <input class="input" name="typed_count" inputmode="numeric" required placeholder="<?= num($preview['total']) ?>"></div>
        <button class="btn btn-primary" type="submit">Verifikasi</button>
      </form>
      <p class="small muted mt-2">Ingin memakai kode email? Aktifkan layanan email di
        <a href="settings.php#email">Pengaturan Sistem</a>, lalu muat ulang halaman ini.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($verified && $preview['total'] > 0): ?>
<div class="card" style="border-color:#F5C9C6">
  <div class="card-head"><h3>3. Konfirmasi Terakhir &amp; Jalankan</h3>
    <span><?= badge('Identitas terverifikasi', 'green') ?></span></div>
  <form method="post"
        data-heavy-confirm="HAPUS SEMUA"
        data-heavy-warning="Menghapus <strong><?= num($preview['total']) ?> data <?= strip_tags($spec['label']) ?></strong>
          (cakupan <strong><?= e($scopeKey) ?></strong>) beserta <strong><?= num(array_sum($preview['detail'])) ?></strong> baris data turunan.<br><br>
          Ini akan mengosongkan data secara <strong>permanen</strong> dan memengaruhi laporan/dashboard.
          Snapshot pengaman akan dibuat otomatis sebelum penghapusan (dapat dipulihkan dari Backup Database)."
        data-heavy-confirm2="PERINGATAN KEDUA (terakhir): <?= num($preview['total']) ?> data <?= strip_tags($spec['label']) ?> pada cakupan <?= e($scopeKey) ?> akan dihapus PERMANEN. Lanjutkan?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="execute">
    <input type="hidden" name="scope" value="<?= e($scopeVal) ?>">
    <div class="card-body">
      <div class="field"><label>Alasan penghapusan <span class="req">*</span></label>
        <input class="input" name="reason" required placeholder="mis. memulai sistem dari nol untuk operasional baru">
        <span class="hint">Alasan ini tersimpan di Audit Log bersama jumlah data yang terhapus.</span></div>
      <div class="notice mt-2">Setelah selesai, Anda dapat memulihkan data kapan saja dari
        <a href="backup.php">Backup Database</a> (snapshot otomatis dibuat lebih dulu).</div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <a class="btn" href="<?= e($spec['back']) ?>">Batalkan</a>
      <button class="btn btn-danger" type="submit">Hapus Semua Data Sekarang (2x konfirmasi)</button>
    </div>
  </form>
</div>
<?php elseif ($preview['total'] > 0): ?>
<div class="notice">Langkah 3 (konfirmasi terakhir) akan muncul setelah verifikasi identitas berhasil.</div>
<?php endif; ?>
<?php page_foot(); ?>
