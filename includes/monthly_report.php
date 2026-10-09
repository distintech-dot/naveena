<?php
/**
 * Laporan bulanan via email: isi email, lampiran PDF, pengiriman, dan
 * pengiriman otomatis tanpa cron.
 *
 * Dipisahkan dari settings.php agar bisa dipakai modul lain (mis. cron, CLI)
 * dan diuji langsung tanpa merender halaman HTML.
 */
declare(strict_types=1);

require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/receipt.php';   // money(), tglIndo(), logo_local_path()

/**
 * Data laporan bulanan (bulan tertentu): angka, perbandingan cabang, dan
 * rincian treatment/skincare. Sumber angkanya sama dengan menu Laporan.
 */
function monthly_report_data(string $month = ''): array
{
    if ($month === '' || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m', strtotime('first day of last month'));
    }
    $first = $month . '-01';
    $last  = date('Y-m-t', strtotime($first));

    $f = [
        'scope' => null, 'ps' => $first, 'pe' => $last, 'status' => 'paid',
        'sql' => 'o.status = ? AND date(o.created_at) BETWEEN ? AND ?',
        'params' => ['paid', $first, $last],
    ];
    $tot = report_totals($f);
    $branches = report_per_branch($f);
    $methods = report_payment_methods($f);
    $treatments = report_top_items('treatment', $f, 10);
    $skincares = report_top_items('skincare', $f, 10);
    $patients = report_top_patients($f, 10);

    return [
        'month' => $month, 'first' => $first, 'last' => $last,
        'totals' => $tot, 'branches' => $branches, 'methods' => $methods,
        'treatments' => $treatments, 'skincares' => $skincares, 'patients' => $patients,
    ];
}

/** Isi email laporan bulanan (HTML). */
function monthly_report_email(string $month = ''): array
{
    $d = monthly_report_data($month);
    $t = $d['totals'];
    $total = $t['total'] ?: 1;

    $rows = [
        ['head' => true, 'cells' => ['Keterangan', 'Nilai']],
    ];
    $rows[] = ['cells' => ['Total Pendapatan', '<strong>' . money($t['total']) . '</strong>']];
    $rows[] = ['cells' => ['Jumlah Transaksi', num($t['trx'])]];
    $rows[] = ['cells' => ['Rata-rata per Transaksi', money($t['avg'])]];
    $rows[] = ['cells' => ['Pendapatan Treatment', money($t['tr']) . ' (' . num($t['tr_q']) . ' treatment)']];
    $rows[] = ['cells' => ['Penjualan Skincare', money($t['sk']) . ' (' . num($t['sk_q']) . ' produk)']];
    $rows[] = ['cells' => ['Total Diskon', money($t['disc'])]];

    $rows[] = ['head' => true, 'cells' => ['Cabang', 'Transaksi', 'Pendapatan', 'Kontribusi']];
    foreach ($d['branches'] as $b) {
        $rows[] = ['cells' => [
            e($b['name']), num($b['trx']),
            '<strong>' . money($b['total']) . '</strong>',
            num($b['total'] / $total * 100, 1) . '%',
        ]];
    }
    $rows[] = ['head' => true, 'cells' => ['TOTAL SEMUA CABANG', num(array_sum(array_column($d['branches'], 'trx'))),
        '<strong>' . money(array_sum(array_column($d['branches'], 'total'))) . '</strong>', '100%']];

    if ($d['methods']) {
        $rows[] = ['head' => true, 'cells' => ['Metode Pembayaran', 'Jumlah', 'Total', '']];
        foreach ($d['methods'] as $m) {
            $rows[] = ['cells' => [e($m['method']), num($m['n']), money($m['total']), '']];
        }
    }

    $body = '<p style="font-size:13px">Berikut laporan operasional <strong>' . e(clinic_name())
        . '</strong> untuk periode <strong>' . e(tglIndo($d['first'])) . ' — ' . e(tglIndo($d['last'])) . '</strong>.</p>';
    $html = email_wrap_html('Laporan Bulanan ' . tglIndo($d['first']), $body, $rows);

    /* Rincian yang dilampirkan di isi email: **TOP 10 PASIEN** (permintaan pemilik —
       bagian "Top 5 Treatment" & "Top 5 Skincare" di bagian bawah DIGANTI, karena
       rincian treatment/skincare sudah tersedia lebih lengkap di lampiran PDF/Excel
       dan yang lebih berguna dilihat cepat dari email adalah pasien teratas). */
    $extra = '';
    $topPx = array_slice(array_values($d['patients'] ?? []), 0, 10);
    if ($topPx) {
        $extra .= '<h3 style="font-size:14px;color:' . theme_current()['brand'] . ';margin-top:22px">Top 10 Pasien</h3>'
            . '<p style="font-size:12px;color:#6B5A65;margin:0 0 6px">Diurutkan dari total transaksi (Rp) terbesar.</p>'
            . '<table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;font-size:13px">'
            /* Urutan kolom MENGIKUTI tabel di aplikasi: Total Transaksi (penentu
               peringkat) lebih dulu, lalu Jumlah Transaksi. */
            . '<tr style="background:#F7F7F9"><th align="left">Pasien</th><th align="right">Kunjungan</th>'
            . '<th align="right">Total Transaksi</th><th align="right">Jumlah Transaksi</th></tr>';
        foreach ($topPx as $r) {
            $extra .= '<tr>'
                . '<td style="border-bottom:1px solid #EDEDED">' . e((string)$r['name'])
                . '<span style="color:#6B5A65"> · ' . e((string)$r['patient_number']) . '</span></td>'
                . '<td align="right" style="border-bottom:1px solid #EDEDED">' . num((int)$r['visits']) . '</td>'
                . '<td align="right" style="border-bottom:1px solid #EDEDED"><strong>' . money((float)$r['total']) . '</strong></td>'
                . '<td align="right" style="border-bottom:1px solid #EDEDED">' . num((int)$r['trx']) . '</td>'
                . '</tr>';
        }
        $extra .= '</table>';
    }
    if ($extra !== '') {
        /* PENTING: blok tambahan disisipkan pada kemunculan TERAKHIR `</div></div>`
           saja. Sebelumnya memakai `str_replace()` yang mengganti SEMUA kemunculan,
           sehingga daftar pasien tampil BERKALI-KALI di dalam satu email (pemilik
           melihat "isi masih ada yang sama" di pratinjau). */
        /* Sisipkan SEBELUM catatan kaki ("Dikirim otomatis oleh …") supaya bacanya
           runtut: ringkasan → per cabang → metode bayar → Top 10 Pasien → catatan kaki.
           Bila penandanya tidak ditemukan, jatuh ke sebelum penutup terakhir. */
        $kaki = '<p style="font-size:12px;color:#6B5A65';
        $pos = strrpos($html, $kaki);
        if ($pos === false) $pos = strrpos($html, '</div></div>');
        if ($pos !== false) {
            $html = substr($html, 0, $pos) . $extra . substr($html, $pos);
        } else {
            $html .= $extra;
        }
    }

    return [
        'subject' => 'Laporan Bulanan ' . clinic_name() . ' — ' . tglIndo($d['first']),
        'html' => $html, 'month' => $d['month'], 'data' => $d,
    ];
}

/**
 * Data laporan bulanan dalam struktur lengkap (dipakai grafik & ekspor).
 * Sumber angkanya sama dengan menu Laporan sehingga konsisten.
 */
function monthly_report_bundle(string $month = ''): array
{
    $d = monthly_report_data($month);
    $f = report_filters_manual($d['first'], $d['last'], null, 'paid');
    return report_bundle_for($f, true);
}

/**
 * Laporan bulanan sebagai PDF asli (BERISI GRAFIK) — lampiran email.
 *
 * @param bool $withFinance paksa sertakan blok KEUANGAN (HPP, laba bersih).
 *        Dipakai HANYA untuk email yang dikirim ke Direktur/Owner.
 */
function monthly_report_pdf(string $month = '', bool $withFinance = false): array
{
    require_once __DIR__ . '/pdf.php';
    require_once __DIR__ . '/report_pdf.php';
    require_once __DIR__ . '/chartimg.php';
    $prevMode = finance_mode();
    $prevForce = finance_report_include();
    if ($withFinance) {
        /* Laporan keuangan selalu memakai mode LENGKAP untuk pemilik. */
        set_setting('finance_mode', 'lengkap');
        finance_report_include(true);
    }
    try {
        $B = monthly_report_bundle($month);
        $pdf = report_pdf_build($B, current_user() ?: ['name' => 'Sistem', 'role_name' => 'Otomatis'],
            ($withFinance ? 'laporan-bulanan-keuangan-' : 'laporan-bulanan-') . $B['filters']['ps'] . '.pdf');
    } finally {
        if ($withFinance) {
            set_setting('finance_mode', $prevMode);
            finance_report_include($prevForce);
        }
    }
    return ['bytes' => $pdf['bytes'], 'filename' => $pdf['filename'], 'charts' => $pdf['charts'] ?? 0];
}

/** Laporan bulanan sebagai Excel (.xlsx) BERISI GRAFIK — lampiran email ke-2. */
function monthly_report_xlsx(string $month = '', bool $withFinance = false): array
{
    require_once __DIR__ . '/xlsx.php';
    require_once __DIR__ . '/chartimg.php';
    $prevMode = finance_mode();
    $prevForce = finance_report_include();
    if ($withFinance) {
        set_setting('finance_mode', 'lengkap');
        finance_report_include(true);
    }
    try {
        $B = monthly_report_bundle($month);
    } finally {
        if ($withFinance) {
            set_setting('finance_mode', $prevMode);
            finance_report_include($prevForce);
        }
    }
    $f = $B['filters'];
    $t = $B['totals'];

    $sheets = [];
    $sheets[] = ['name' => 'Ringkasan', 'widths' => [38, 20], 'rows' => [
        ['Keterangan', 'Nilai'],
        ['Periode', $f['ps'] . ' s.d. ' . $f['pe']],
        ['Total Pendapatan', (float)$t['total']],
        ['Jumlah Transaksi', (int)$t['trx']],
        ['Rata-rata per Transaksi', (float)round($t['avg'])],
        ['Subtotal (sebelum diskon)', (float)$t['subtotal']],
        ['Total Diskon', (float)$t['disc']],
        ['Pendapatan Treatment', (float)$t['tr']],
        ['Jumlah Treatment Terjual', (float)$t['tr_q']],
        ['Penjualan Skincare', (float)$t['sk']],
        ['Jumlah Skincare Terjual', (float)$t['sk_q']],
        ['Total Pembayaran Valid', (float)$t['pay_total']],
        ['Jumlah Pembayaran', (int)$t['pay_n']],
    ]];

    $rows = [['Cabang', 'Transaksi', 'Treatment', 'Skincare', 'Diskon', 'Pendapatan']];
    $sum = array_sum(array_column($B['branches'], 'total')) ?: 1;
    foreach ($B['branches'] as $b) {
        $rows[] = [$b['name'], (int)$b['trx'], (float)$b['tr'], (float)$b['sk'], (float)$b['disc'], (float)$b['total']];
    }
    $rows[] = ['TOTAL', (int)array_sum(array_column($B['branches'], 'trx')),
               (float)array_sum(array_column($B['branches'], 'tr')), (float)array_sum(array_column($B['branches'], 'sk')),
               (float)array_sum(array_column($B['branches'], 'disc')), (float)array_sum(array_column($B['branches'], 'total'))];
    $sheets[] = ['name' => 'Per Cabang', 'widths' => [30, 12, 16, 16, 14, 16], 'rows' => $rows];

    $rows = [['Metode', 'Jumlah', 'Total']];
    foreach ($B['methods'] as $m) $rows[] = [$m['method'], (int)$m['n'], (float)$m['total']];
    $sheets[] = ['name' => 'Metode Bayar', 'widths' => [22, 12, 16], 'rows' => $rows ?: [['-', 0, 0]]];

    $rows = [['#', 'Treatment', 'Kategori', 'Terjual', 'Pendapatan']];
    foreach ($B['treatments'] as $i => $r) $rows[] = [$i + 1, $r['nama'], $r['kategori'], (float)$r['q'], (float)$r['s']];
    $sheets[] = ['name' => 'Treatment', 'widths' => [6, 38, 16, 12, 16], 'rows' => $rows];

    $rows = [['#', 'Produk Skincare', 'Kategori', 'Terjual', 'Penjualan']];
    foreach ($B['skincares'] as $i => $r) $rows[] = [$i + 1, $r['nama'], $r['kategori'], (float)$r['q'], (float)$r['s']];
    $sheets[] = ['name' => 'Skincare', 'widths' => [6, 38, 16, 12, 16], 'rows' => $rows];

    /* Lembar KEUANGAN (hanya untuk lampiran yang dikirim ke Direktur/Owner). */
    if ($withFinance) {
        $fs = finance_summary($f, finance_mode() === 'lengkap', $f['scope']);
        $rw = [['Komponen', 'Nilai']];
        $rw[] = ['Mode laporan', finance_mode_label($fs['mode'])];
        $rw[] = ['Pendapatan Treatment', (float)$fs['pendapatan_treatment']];
        $rw[] = ['Pendapatan Skincare', (float)$fs['pendapatan_skincare']];
        $rw[] = ['Pendapatan Paket', (float)$fs['pendapatan_paket']];
        $rw[] = ['Pengurangan Diskon Transaksi', -(float)$fs['diskon']];
        $rw[] = ['Pengurangan Diskon Member', -(float)$fs['diskon_member']];
        $rw[] = ['TOTAL PENDAPATAN (OMZET)', (float)$fs['omzet']];
        $rw[] = ['HPP Treatment', -(float)$fs['hpp_treatment']];
        $rw[] = ['HPP Produk', -(float)$fs['hpp_produk']];
        $rw[] = ['HPP Paket', -(float)$fs['hpp_paket']];
        if ($fs['mode'] === 'lengkap') {
            $rw[] = ['LABA KOTOR', (float)$fs['laba_kotor']];
            foreach ($fs['biaya_rows'] as $c) {
                $rw[] = [$c['name'] . ' (' . $c['period_label'] . ' · ' . ($c['scope_label'] ?? 'Semua cabang') . ')',
                    -(float)$c['share']];
                if (!empty($c['breakdown']) && count($c['parts'] ?? []) > 1) $rw[] = ['   = ' . $c['breakdown'], ''];
            }
            $rw[] = ['TOTAL BIAYA OPERASIONAL', -(float)$fs['biaya_total']];
        }
        $rw[] = ['LABA BERSIH', (float)$fs['laba_bersih']];
        if ($fs['margin'] !== null) $rw[] = ['Margin Laba Bersih (%)', (float)$fs['margin']];
        $sheets[] = ['name' => 'Keuangan', 'widths' => [48, 20], 'rows' => $rw];

        $rw = [['Cabang', 'Omzet', 'HPP Treatment', 'HPP Produk', 'HPP Paket', 'Biaya Operasional', 'Laba Bersih']];
        foreach (finance_per_branch($f) as $b) {
            $rw[] = [$b['branch'], (float)$b['omzet'], (float)$b['hpp_treatment'], (float)$b['hpp_produk'],
                (float)$b['hpp_paket'], (float)$b['biaya_total'], (float)$b['laba_bersih']];
        }
        $sheets[] = ['name' => 'Laba per Cabang', 'widths' => [26, 16, 16, 16, 16, 18, 16], 'rows' => $rw];
    }
    $items = [];
    foreach (report_charts($B) as $c) $items[] = ['title' => $c['title'], 'png' => $c['png']];
    if ($withFinance) {
        $fsr = finance_series($f);
        $png = chart_bar(['labels' => $fsr['labels'],
            'series' => [['label' => 'Laba Bersih', 'data' => $fsr['laba'], 'color' => chart_hex2rgb(chart_series_color('positif'))]]],
            'Laba Bersih per ' . ($fsr['granularity'] === 'harian' ? 'Hari' : 'Bulan'));
        if ($png) $items[] = ['title' => 'Laba Bersih per ' . ($fsr['granularity'] === 'harian' ? 'Hari' : 'Bulan'), 'png' => $png];
    }
    $bytes = xlsx_build($sheets, ['name' => 'Grafik', 'items' => $items]);
    return ['bytes' => $bytes,
            'filename' => ($withFinance ? 'laporan-bulanan-keuangan-' : 'laporan-bulanan-') . $f['ps'] . '.xlsx',
            'charts' => count($items)];
}

/**
 * Kirim laporan bulanan (HTML + lampiran PDF & Excel) dan catat hasilnya.
 *
 * @param bool $withFinance sertakan blok KEUANGAN (HPP, laba bersih) pada
 *        lampiran. Dipakai untuk email yang ditujukan ke Direktur/Owner —
 *        lihat `send_monthly_reports()` yang mengirim DUA email.
 */
function send_monthly_report(?string $month = null, ?string $toOverride = null, bool $withFinance = false): array
{
    /* $month null/kosong = bulan lalu (perilaku bawaan laporan bulanan). */
    $rep = monthly_report_email((string)($month ?? ''));
    $to = $toOverride ?: trim((string)setting('email_recipient'));
    $att = [];
    $pdfErr = '';
    /* Dua lampiran sesuai permintaan: PDF (+grafik) dan Excel (+grafik). */
    try {
        $mpdf = monthly_report_pdf($rep['month'], $withFinance);
        $att[] = ['name' => $mpdf['filename'], 'mime' => 'application/pdf', 'data' => $mpdf['bytes']];
    } catch (Throwable $ex) {
        $pdfErr = 'PDF: ' . $ex->getMessage();
    }
    $xlsErr = '';
    try {
        $mxls = monthly_report_xlsx($rep['month'], $withFinance);
        $att[] = ['name' => $mxls['filename'],
                  'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                  'data' => $mxls['bytes']];
    } catch (Throwable $ex) {
        $xlsErr = 'Excel: ' . $ex->getMessage();
    }
    $err = '';
    $ok = send_email($to, $rep['subject'], $rep['html'], $err, $att);

    /* Kunci riwayat memakai awalan "Laporan " supaya SAMA dengan yang diperiksa
       pengaman kirim-ulang di Pengaturan (email_resend_notice('Laporan <bulan>')). */
    q('INSERT INTO email_report_logs (recipient, period, status, message) VALUES (?,?,?,?)',
      [$to, 'Laporan ' . $rep['month'], $ok ? 'sent' : 'failed',
       $ok ? 'Laporan bulanan ' . ($withFinance ? 'LENGKAP + KEUANGAN ' : '') . 'terkirim ('
           . count($att) . ' lampiran: ' . implode(' + ', array_map(fn($a) => $a['name'], $att)) . ')'
           : (string)$err . ($pdfErr ? ' | ' . $pdfErr : '') . ($xlsErr ? ' | ' . $xlsErr : '')]);
    audit($ok ? 'Kirim Laporan Email' : 'Gagal Kirim Laporan Email', 'Laporan', null, null,
        ['month' => $rep['month'], 'to' => $to, 'berhasil' => $ok, 'lampiran' => count($att),
         'jenis' => $withFinance ? 'lengkap+keuangan' : 'lengkap (tanpa keuangan)',
         'nama_lampiran' => array_map(fn($a) => $a['name'], $att),
         'pdf_error' => $pdfErr, 'xlsx_error' => $xlsErr], $ok ? 'Laporan bulanan dikirim' : (string)$err);
    if ($ok) {
        set_setting('email_last_sent_period', $rep['month']);
        set_setting('email_last_success_at', date('Y-m-d H:i:s'));
    }
    email_record_result($ok, $ok ? 'Laporan ' . $rep['month'] . ' terkirim' : (string)$err, $ok);
    return ['ok' => $ok, 'error' => $err, 'to' => $to, 'month' => $rep['month'],
            'attachments' => array_map(fn($a) => $a['name'], $att),
            'with_finance' => $withFinance,
            'pdf_attached' => count($att) > 0, 'pdf_error' => $pdfErr, 'xlsx_error' => $xlsErr];
}

/**
 * Pengiriman OTOMATIS tanpa cron: dipanggil saat petugas membuka aplikasi.
 * Mengirim laporan bulan sebelumnya satu kali setelah tanggal & jam yang diatur.
 * Dilindungi pengaturan email_last_sent_period agar tidak mengirim berulang.
 */
function email_auto_run(): ?array
{
    if (setting('email_service_active') !== '1') return null;
    if (!mail_configured()) return null;
    $mode = (string)setting('email_schedule_mode', 'auto');
    if ($mode === 'manual') return null;

    $day = max(1, min(28, (int)setting('email_schedule_day', '1')));
    $time = (string)setting('email_schedule_time', '08:00');
    if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) $time = '08:00';

    $now = time();
    $target = strtotime(date('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT) . ' ' . $time);
    if ($now < $target) return null;                       // belum waktunya bulan ini

    $period = date('Y-m', strtotime('first day of last month'));
    if (setting('email_last_sent_period') === $period) return null;   // sudah terkirim

    /* Bila percobaan gagal, jangan ulangi setiap kali login (proses membuat PDF +
       Excel + grafik memerlukan beberapa detik). Diberi jeda 6 jam. */
    $lastTry = (int)setting('email_last_attempt_at', '0');
    if ($lastTry > 0 && (time() - $lastTry) < 6 * 3600) return null;
    set_setting('email_last_attempt_at', (string)time());

    $all = send_monthly_reports($period);
    $res = $all[0];
    /* Ringkas hasil SEMUA penerima supaya dashboard jujur melaporkan apa yang terjadi. */
    $res['semua_penerima'] = array_map(fn($r) => ['to' => $r['to'], 'ok' => $r['ok'],
        'keuangan' => !empty($r['with_finance']), 'error' => $r['error']], $all);
    $res['auto'] = true;
    return $res;
}

/** Diagnosa jalur pengiriman email (ditampilkan di Pengaturan). */
function email_diagnostics(): array
{
    $out = [];
    $mode = email_mode();
    $out['mode'] = $mode === 'api' ? 'HTTP API (HTTPS)' : 'SMTP langsung';
    $out['configured'] = mail_configured();
    $out['sender'] = (string)setting('email_sender');
    $out['recipient'] = (string)setting('email_recipient');
    $out['recipient_finance'] = email_finance_recipient();
    $out['finance_enabled'] = email_finance_enabled();

    // uji koneksi TCP ke host SMTP / API
    if ($mode === 'smtp') {
        $host = trim((string)setting('smtp_host'));
        $port = (int)setting('smtp_port', '587');
        if ($host === '') {
            $out['connect'] = 'Host SMTP belum diisi.';
            $out['connect_ok'] = false;
        } else {
            $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port, $e, $s, 8);
            if ($fp) {
                $out['connect_ok'] = true;
                $out['connect'] = 'Berhasil terhubung ke ' . $host . ':' . $port;
                fclose($fp);
            } else {
                $out['connect_ok'] = false;
                $out['connect'] = 'Tidak dapat terhubung ke ' . $host . ':' . $port . ' (' . $s . '). '
                    . 'Kemungkinan port SMTP diblokir jaringan server — gunakan jalur HTTPS API.';
            }
        }
    } else {
        $url = trim((string)setting('email_api_url'));
        if ($url === '') {
            $out['connect'] = 'URL endpoint API belum diisi.';
            $out['connect_ok'] = false;
        } else {
            $host = parse_url($url, PHP_URL_HOST) ?: '';
            $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
            $port = (int)(parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
            $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $e, $s, 10);
            if ($fp) {
                $out['connect_ok'] = true;
                $out['connect'] = 'Berhasil terhubung ke ' . $host . ':' . $port;
                fclose($fp);
            } else {
                $out['connect_ok'] = false;
                $out['connect'] = 'Tidak dapat terhubung ke ' . $host . ':' . $port . ' (' . $s . ')';
            }
        }
        $out['api_key_set'] = trim((string)setting('email_api_key')) !== '';
    }
    $out['last'] = email_last_result();
    return $out;
}

/* ================================================================== *
 * DUA PENERIMA LAPORAN BULANAN (permintaan pemilik klinik)
 *   1. Laporan lengkap TANPA keuangan → staf/dokter/bebas (email_recipient)
 *   2. Laporan lengkap + KEUANGAN (HPP & laba bersih) → Direktur/Owner
 * Keduanya dapat diatur sendiri di Pengaturan Sistem → Email.
 * ================================================================== */

/** Penerima laporan "lengkap + keuangan" (Direktur/Owner). */
function email_finance_recipient(): string
{
    return trim((string)setting('email_finance_recipient'));
}

/** Apakah email keuangan ke Direktur/Owner diaktifkan? */
function email_finance_enabled(): bool
{
    return setting('email_finance_enabled', '0') === '1' && email_finance_recipient() !== '';
}

/**
 * Kirim laporan bulanan ke SEMUA penerima yang diatur:
 *  - laporan lengkap (tanpa keuangan) ke `email_recipient`,
 *  - laporan lengkap + keuangan ke `email_finance_recipient` (bila diaktifkan).
 * Bila keduanya menunjuk alamat yang SAMA, hanya satu email dikirim (yang
 * berisi keuangan) supaya tidak ada kiriman ganda.
 *
 * @return array<int,array> hasil tiap pengiriman (format send_monthly_report()).
 */
function send_monthly_reports(?string $month = null, ?string $toOverride = null): array
{
    $out = [];
    $staff = $toOverride !== null ? trim($toOverride) : trim((string)setting('email_recipient'));
    $fin = email_finance_recipient();
    $finOn = email_finance_enabled();
    if ($finOn && $fin !== '' && strcasecmp($fin, $staff) === 0) {
        /* Satu alamat saja: kirim versi berkeuangan (paling lengkap). */
        return [send_monthly_report($month, $staff, true)];
    }
    if ($staff !== '') $out[] = send_monthly_report($month, $staff, false);
    if ($finOn) $out[] = send_monthly_report($month, $fin, true);
    if (!$out) {
        $out[] = ['ok' => false, 'error' => 'Alamat email laporan belum diisi.',
                  'to' => '', 'month' => $month, 'attachments' => [], 'with_finance' => false];
    }
    return $out;
}
