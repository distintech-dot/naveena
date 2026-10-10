<?php
/**
 * Struk / invoice sebagai PDF asli + pengiriman ke WhatsApp.
 *
 * Catatan jujur soal WhatsApp: tautan wa.me HANYA bisa mengisi teks pesan —
 * WhatsApp tidak mengizinkan lampiran dokumen lewat tautan. Jadi:
 *   - Bila WhatsApp API klinik sudah dikonfigurasi → pesan (berisi tautan PDF)
 *     dikirim otomatis oleh server.
 *   - Bila belum → sistem menyiapkan pesan + mengunduh PDF, lalu WhatsApp dibuka
 *     dari nomor yang login di perangkat itu (nomor Naveena) supaya petugas
 *     tinggal mengirim (dan melampirkan PDF bila diperlukan).
 * Tidak ada klaim "PDF otomatis terlampir" bila API belum ada.
 */
declare(strict_types=1);

require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/mailer.php';   // wa_api_configured(), wa_api_send()

/** Ambil data lengkap satu transaksi untuk struk. */
function receipt_data(int $orderId): ?array
{
    $o = one('SELECT o.*, p.name AS patient_name, p.patient_number, p.member_number, p.phone AS patient_phone,
                     b.name AS branch_name, b.address AS branch_address, b.phone AS branch_phone, b.email AS branch_email,
                     u.name AS cashier_user
              FROM orders o
              JOIN patients p ON p.id = o.patient_id
              JOIN branches b ON b.id = o.branch_id
              LEFT JOIN users u ON u.id = o.user_id
              WHERE o.id = ?', [$orderId]);
    if (!$o) return null;
    $o['items'] = all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
    $o['payments'] = all('SELECT * FROM payments WHERE order_id = ? ORDER BY id', [$orderId]);
    $o['paid_total'] = array_sum(array_map(fn($p) => $p['status'] === 'valid' ? (float)$p['amount'] : 0, $o['payments']));
    return $o;
}

/** Bangun PDF struk (80mm) dari data transaksi. Mengembalikan ['bytes','filename']. */
function receipt_pdf(array $o, bool $isCopy = false): array
{
    $W = 226.77;   // 80 mm
    $M = 13;

    // ---- ukur dulu supaya tinggi halaman pas dengan isinya ----
    $measure = new MiniPdf($W, 2000, $M);
    $measure->dry = true;
    render_receipt($measure, $o, $isCopy);
    $used = 2000 - $measure->y;
    $height = max(220, min(1800, $used + $M + 6));

    $pdf = new MiniPdf($W, $height, $M);
    render_receipt($pdf, $o, $isCopy);
    $bytes = $pdf->output();
    $safe = preg_replace('/[^A-Za-z0-9\-]/', '-', (string)$o['invoice_number']);
    return ['bytes' => $bytes, 'filename' => 'struk-' . ($safe ?: $o['id']) . '.pdf'];
}

function render_receipt(MiniPdf $p, array $o, bool $isCopy = false): void
{
    $company = clinic_name();
    // ---- kepala: pakai logo klinik bila tersedia, jika tidak pakai teks ----
    $logo = logo_pdf_path();   // PDF hanya mendukung PNG (logo JPEG dikonversi otomatis)
    $drawn = ['w' => 0.0, 'h' => 0.0];
    if ($logo !== '') $drawn = $p->imagePngCentered($logo, $p->W - $p->margin * 4, 42);
    if ($drawn['w'] <= 0) {
        /* PDF memakai font base-14 terbatas, jadi nama klinik ditulis apa adanya
           (huruf di luar WinAnsi otomatis disederhanakan oleh MiniPdf). Dicetak
           dengan paragraph() supaya nama panjang MEMBUNGKUS, bukan meluber
           keluar lebar kertas struk 80 mm. */
        $p->paragraph($company, clinic_pdf_name_size($company, 15.0, 10.5), true, 0, true);
        if (clinic_tagline() !== '') $p->line(clinic_tagline(), 8.5, false, 0, true);
    }
    $p->gap(2);
    /* Nama cabang = nama klinik + kota, jadi bisa panjang; dibungkus & ukurannya
       menyesuaikan supaya tidak meluber keluar lebar kertas struk. */
    $p->paragraph((string)$o['branch_name'], doc_line_fit_size((string)$o['branch_name'], 9.0, 6.8), true, 0, true);
    if ($o['branch_address']) $p->paragraph((string)$o['branch_address'], 7.2, false, 0, true);
    if ($o['branch_phone'])   $p->line('Telp/WA: ' . (string)$o['branch_phone'], 7.2, false, 0, true);
    if ($o['branch_email'])   $p->line((string)$o['branch_email'], 7.2, false, 0, true);
    $p->hr();

    $p->line('STRUK PEMBAYARAN' . ($isCopy ? ' (SALINAN)' : ''), 8.5, true, 0, true);
    $p->gap(1);

    // ---- identitas transaksi ----
    $p->kv('No. Invoice', (string)$o['invoice_number']);
    $p->kv('Tanggal', tgl((string)$o['created_at'], true));
    $p->kv('Pasien', (string)$o['patient_name']);
    $p->kv('No. Pasien', (string)$o['patient_number']);
    if ($o['member_number']) $p->kv('No. Member', (string)$o['member_number']);
    $p->kv('Kasir', (string)($o['cashier_user'] ?: $o['cashier_name'] ?: '-'));
    $p->hr();

    // ---- rincian item ----
    /* Bahan treatment (item_type='material') dan ISI PAKET (item_type='package_item')
       SENGAJA dilewati: keduanya bukan barang yang ditagihkan (nilainya 0) —
       rinciannya tetap tersimpan pada transaksi dan halaman detail. */
    $p->line('RINCIAN', 8, true);
    $p->gap(1);
    $trQty = 0; $skQty = 0; $pkQty = 0; $trSum = 0; $skSum = 0; $pkSum = 0;
    foreach ($o['items'] as $it) {
        $t = (string)($it['item_type'] ?? '');
        if ($t === 'material' || $t === 'package_item') continue;
        $isTr = $t === 'treatment';
        $isPk = $t === 'package';
        if ($isTr) { $trQty += (float)$it['quantity']; $trSum += (float)$it['subtotal']; }
        elseif ($isPk) { $pkQty += (float)$it['quantity']; $pkSum += (float)$it['subtotal']; }
        else       { $skQty += (float)$it['quantity']; $skSum += (float)$it['subtotal']; }
        $p->kvWrap((string)$it['item_name'], money($it['subtotal']), 8.2);
        /* HARGA NORMAL DICORET bila sedang promo — di PDF coretan tidak mungkin,
           jadi harga normal ditulis lebih dulu dengan keterangan "(normal)" lalu
           harga promo + tanda *. */
        $hn = (float)($it['price_normal'] ?? 0);
        $promo = $hn > 0 && abs($hn - (float)$it['price']) > 0.5;
        if ($promo) {
            $p->line(($isTr ? 'Treatment' : ($isPk ? 'Paket' : 'Skincare')) . ' - ' . qty_text($it['quantity'])
                . ' x ' . money($hn) . ' (harga normal) -> PROMO ' . money($it['price']), 6.4, false, 0);
        } else {
            $p->line(($isTr ? 'Treatment' : ($isPk ? 'Paket' : 'Skincare')) . ' - ' . qty_text($it['quantity'])
                . ' x ' . money($it['price']), 6.8, false, 0);
        }
    }
    $p->hr();

    // ---- ringkasan ----
    $p->kv('Subtotal', money($o['subtotal']));
    if ((float)$o['discount'] > 0) $p->kv('Diskon', '- ' . money($o['discount']));
    /* Kode unik transfer/QRIS: ditampilkan supaya pasien tahu nominal pastinya
       (total sudah termasuk kode unik ini). */
    if ((int)($o['unique_code'] ?? 0) > 0) {
        $pm = '';
        foreach ((array)($o['payments'] ?? []) as $pay) {
            if (!empty($pay['method'])) { $pm = (string)$pay['method']; break; }
        }
        $p->kv('Kode Unik' . ($pm !== '' ? ' (' . $pm . ')' : ''), (string)(int)$o['unique_code'], 7);
    }
    /* Diskon member ditampilkan terpisah supaya jelas asalnya. */
    if ((float)($o['member_discount'] ?? 0) > 0) {
        $mpct = (float)($o['member_pct'] ?? 0);
        $mlab = trim((string)($o['member_tier'] ?? ''));
        $p->kv('Diskon Member' . ($mlab !== '' ? ' (' . $mlab . ')' : ($mpct > 0 ? ' (' . num($mpct, $mpct == (int)$mpct ? 0 : 1) . '%)' : '')),
            '- ' . money($o['member_discount']));
        /* Cakupan yang dipilih kasir pada transaksi ini (treatment/skincare/keduanya). */
        if (!empty($o['member_scope']) && $o['member_scope'] !== 'both') {
            $p->kv('  Cakupan', member_scope_text((string)$o['member_scope']), 7);
        }
    }
    $p->kv('TOTAL', money($o['total']), 10, true);
    foreach ($o['payments'] as $pay) {
        $label = 'Bayar (' . $pay['method'] . ')' . ($pay['status'] !== 'valid' ? ' [' . strtoupper($pay['status']) . ']' : '');
        $p->kv($label, money($pay['amount']));
    }
    $p->kv('Kembali', money(max(0, $o['paid_total'] - (float)$o['total'])));
    if ((int)($o['member_card'] ?? 0) === 1 && (float)($o['member_discount'] ?? 0) > 0) {
        $p->gap(1);
        $p->paragraph('Terima kasih, potongan harga member ' . $company . ' sudah diterapkan pada transaksi ini.', 7, true, 0, true);
    }
    if ($trQty > 0) $p->kv('Total Treatment', num($trQty) . ' tindakan');
    if ($skQty > 0) $p->kv('Total Skincare', num($skQty) . ' produk');
    if ($o['void_reason']) {
        $p->hr();
        $p->paragraph('CATATAN: transaksi berstatus ' . strtoupper((string)$o['status']) . ' - ' . $o['void_reason'], 7.5, true, 0, true);
    }
    $p->hr();

    // ---- kaki ----
    $footer = setting('receipt_footer');
    if ($footer !== '') $p->paragraph($footer, 7.2, false, 0, true);
    $p->gap(2);
    $p->paragraph('Dokumen ini dibuat otomatis oleh ' . $company . ' Management System.', 6.5, false, 0, true);
}

/* ------------------------------------------------------------------ *
 * Pesan WhatsApp
 * ------------------------------------------------------------------ */

/**
 * Rincian item transaksi sebagai teks (untuk variabel {rincian} pada WhatsApp/email).
 *
 * Harga normal ditandai "(harga normal …)" bila item sedang PROMO — permintaan
 * pemilik: pasien tahu harga aslinya sebelum potongan promo. Bahan treatment &
 * isi paket (berharga 0) dilewati karena bukan barang yang ditagihkan.
 */
function receipt_items_text(array $o, string $linePrefix = '- '): string
{
    $out = [];
    foreach (($o['items'] ?? []) as $it) {
        $t = (string)($it['item_type'] ?? '');
        if ($t === 'material' || $t === 'package_item') continue;
        $hn = (float)($it['price_normal'] ?? 0);
        $promo = $hn > 0 && abs($hn - (float)$it['price']) > 0.5;
        $out[] = $linePrefix . $it['item_name'] . ' ' . qty_text($it['quantity']) . ' x '
            . ($promo ? money($hn) . ' (harga normal) -> PROMO ' . money($it['price']) : money($it['price']))
            . ' = ' . money($it['subtotal']);
    }
    return implode("\n", $out);
}

/** Baris keterangan PROMO saja (kosong bila tidak ada item promo). */
function receipt_promo_text(array $o, string $linePrefix = ''): string
{
    $out = [];
    foreach (($o['items'] ?? []) as $it) {
        $hn = (float)($it['price_normal'] ?? 0);
        if ($hn > 0 && abs($hn - (float)$it['price']) > 0.5) {
            $out[] = $linePrefix . $it['item_name'] . ': ' . money($hn) . ' -> PROMO ' . money($it['price']);
        }
    }
    return implode("\n", $out);
}

function wa_receipt_template(array $o): string
{
    $tpl = setting('wa_receipt_template');
    if (trim($tpl) === '') {
        /* Template bawaan memakai NAMA KLINIK AKTIF (bukan nama lama di kode)
           supaya penggantian nama klinik ikut terpakai pada pesan WhatsApp. */
        $cn = clinic_name();
        $tpl = "Halo Kak {nama} 🙏\n\nTerima kasih telah melakukan perawatan di {$cn} {cabang}.\n\n"
             . "Rincian transaksi Kakak:\nNo. Invoice: {invoice}\nTanggal: {tanggal}\nTotal: {total}\nMetode: {metode}\n\n"
             . "Struk digital: {link}\n\nSalam sehat,\n{$cn} {cabang}";
    }
    $methods = implode(', ', array_map(fn($p) => (string)$p['method'], $o['payments']));
    $promoTxt = receipt_promo_text($o);
    return str_replace(
        ['{nama}', '{pasien}', '{klinik}', '{cabang}', '{invoice}', '{tanggal}', '{total}', '{metode}', '{alamat}', '{link}',
         '{rincian}', '{promo}'],
        [
            (string)$o['patient_name'],
            (string)$o['patient_name'],
            clinic_name(),
            (string)$o['branch_name'],
            (string)$o['invoice_number'],
            tgl((string)$o['created_at'], true),
            money($o['total']),
            $methods !== '' ? $methods : '-',
            (string)($o['branch_address'] ?: ''),
            '{LINK}',   // placeholder, diisi setelah PDF tersedia
            receipt_items_text($o),
            $promoTxt !== '' ? $promoTxt : '-',
        ],
        $tpl
    );
}
/**
 * Susun pesan final: ganti placeholder tautan dengan tautan sebenarnya, atau
 * buang barisnya bila tautan tidak disertakan. Menangani pesan yang ditulis
 * ulang sendiri oleh petugas (tanpa placeholder) dengan menambahkan tautan di akhir.
 */
function wa_receipt_finalize(string $message, string $link): string
{
    $msg = str_replace([chr(0xE2) . chr(0x80) . chr(0x8B)], '', $message);   // buang ZWSP
    $marker = '(tautan struk PDF)';
    $hasPlaceholder = (strpos($msg, '{LINK}') !== false) || (strpos($msg, $marker) !== false);

    if ($link !== '') {
        $msg = str_replace(['{LINK}', $marker], $link, $msg);
        if (!$hasPlaceholder && strpos($msg, $link) === false) {
            $msg = rtrim($msg) . "\n\nStruk digital: " . $link;
        }
    } else {
        $msg = str_replace(['{LINK}', $marker], '', $msg);
        // hapus baris label yang kini kosong
        $msg = preg_replace('/^[ \t]*(Struk digital|Link struk)\s*:?[ \t]*$/mi', '', (string)$msg);
        $msg = preg_replace('/[ \t]+\n/', "\n", (string)$msg);
    }
    $msg = preg_replace("/\n{3,}/", "\n\n", (string)$msg);
    return trim((string)$msg);
}

/** Nomor WhatsApp Naveena yang dipakai sebagai pengirim (fallback wa.me). */
function wa_sender_number(): string
{
    $n = trim(setting('wa_sender_number'));
    if ($n === '') $n = trim(setting('wa_api_sender'));
    if ($n === '') $n = trim(setting('company_phone'));
    return $n;
}
function wa_receipt_status_text(): string
{
    if (wa_api_configured()) {
        return 'WhatsApp API aktif — pesan dikirim otomatis dari server (berisi rincian + tautan struk PDF).';
    }
    return 'WhatsApp API belum dikonfigurasi — WhatsApp akan dibuka dari nomor yang login di perangkat ini. '
         . 'Pesan sudah terisi otomatis dan struk PDF diunduh agar dapat dilampirkan.';
}
