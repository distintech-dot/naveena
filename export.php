<?php
/**
 * Export centre — CSV / Excel / PDF (print-optimised) for every report.
 * All rows come from the database with the same filters used on screen.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$type   = (string)gp('type', 'transaksi');
$format = (string)gp('format', 'csv');
$user   = require_login();
$scope  = scope_branch();
$period = gp('period', 'month');
[$ps, $pe] = resolve_period($period, gp('start'), gp('end'));

if ($type === 'invoice') {
    require_perm('order.view');
} else {
    require_perm('export.data');
}

$bs = $scope === null ? ['', []] : [' AND x.branch_id = ?', [$scope]];
function sc(string $col, ?int $scope): array { return $scope === null ? ['', []] : [" AND {$col} = ?", [$scope]]; }

/* ------------------------------------------------------------------ *
 * Laporan LENGKAP dalam bentuk Excel — semua bagian laporan dalam satu berkas
 * (ringkasan, progres bulanan, per cabang, metode bayar, kasir, top 5,
 * top 10 pasien, rincian item). Angka diambil dari sumber yang sama dengan
 * halaman Laporan (includes/reports.php) supaya tidak pernah berbeda.
 * ------------------------------------------------------------------ */
/* ------------------------------------------------------------------ *
 * Laporan lengkap dalam bentuk Excel (.xlsx) BERISI GRAFIK.
 * ------------------------------------------------------------------ */
/* ---- EKSPOR KEUANGAN (menu Keuangan — khusus owner) ---- */
if ($type === 'keuangan') {
    require_perm('finance.view');
    if (!is_owner_level()) deny('Ekspor laporan keuangan hanya untuk Direktur/Owner dan Super Admin.');
    require_perm('export.data');
    require_once __DIR__ . '/includes/reports.php';
    require_once __DIR__ . '/includes/chartimg.php';
    require_once __DIR__ . '/includes/xlsx.php';
    $fk = report_filters();
    $sk = finance_summary($fk, finance_mode() === 'lengkap', $fk['scope']);
    $scopeName = $fk['scope'] === null ? 'Semua Cabang'
        : (string)scalar('SELECT name FROM branches WHERE id=?', [$fk['scope']], '-');
    audit('Export ' . strtoupper($format === 'xlsx' ? 'Excel' : $format), 'Keuangan', null, null,
        ['periode' => $fk['ps'] . ' s.d. ' . $fk['pe'], 'mode' => $sk['mode']],
        'Laporan keuangan diekspor (' . $format . ')');

    if ($format === 'xlsx') {
        $sheets = [];
        $rows = [['Komponen', 'Nilai']];
        $rows[] = ['Periode', $fk['ps'] . ' s.d. ' . $fk['pe']];
        $rows[] = ['Cakupan', $scopeName];
        $rows[] = ['Mode laporan', finance_mode_label($sk['mode'])];
        $rows[] = ['', ''];
        $rows[] = ['Pendapatan Treatment', (float)$sk['pendapatan_treatment']];
        $rows[] = ['Pendapatan Skincare', (float)$sk['pendapatan_skincare']];
        $rows[] = ['Pendapatan Paket', (float)$sk['pendapatan_paket']];
        $rows[] = ['Pengurangan Diskon Transaksi', -(float)$sk['diskon']];
        $rows[] = ['Pengurangan Diskon Member', -(float)$sk['diskon_member']];
        $rows[] = ['TOTAL PENDAPATAN (OMZET)', (float)$sk['omzet']];
        $rows[] = ['HPP Treatment', -(float)$sk['hpp_treatment']];
        $rows[] = ['HPP Produk', -(float)$sk['hpp_produk']];
        $rows[] = ['HPP Paket', -(float)$sk['hpp_paket']];
        if ($sk['mode'] === 'lengkap') {
            $rows[] = ['LABA KOTOR', (float)$sk['laba_kotor']];
            foreach ($sk['biaya_rows'] as $c) {
                $rows[] = [$c['name'] . ' (' . $c['period_label'] . ' · ' . ($c['scope_label'] ?? 'Semua cabang') . ')',
                    -(float)$c['share']];
                if (!empty($c['breakdown']) && count($c['parts'] ?? []) > 1) $rows[] = ['   = ' . $c['breakdown'], ''];
            }
            $rows[] = ['TOTAL BIAYA OPERASIONAL', -(float)$sk['biaya_total']];
        }
        $rows[] = ['LABA BERSIH', (float)$sk['laba_bersih']];
        if ($sk['margin'] !== null) $rows[] = ['Margin Laba Bersih (%)', (float)$sk['margin']];
        $sheets[] = ['name' => 'Keuangan', 'widths' => [48, 20], 'rows' => $rows];

        $rows = [['Cabang', 'Transaksi', 'Omzet', 'HPP Treatment', 'HPP Produk', 'Biaya Operasional', 'Laba Bersih', 'Margin %']];
        foreach (finance_per_branch($fk) as $b) {
            $rows[] = [$b['branch'], (int)$b['trx'], (float)$b['omzet'], (float)$b['hpp_treatment'],
                (float)$b['hpp_produk'], (float)$b['biaya_total'], (float)$b['laba_bersih'],
                $b['margin'] !== null ? (float)$b['margin'] : ''];
        }
        $sheets[] = ['name' => 'Per Cabang', 'widths' => [26, 12, 16, 16, 16, 18, 16, 12], 'rows' => $rows];

        $items = [];
        $fs = finance_series($fk);
        $png = chart_bar(['labels' => $fs['labels'],
            'series' => [['label' => 'Laba Bersih', 'data' => $fs['laba'], 'color' => chart_hex2rgb(chart_series_color('positif'))]]],
            'Laba Bersih per ' . ($fs['granularity'] === 'harian' ? 'Hari' : 'Bulan'));
        if ($png) $items[] = ['title' => 'Laba Bersih per ' . ($fs['granularity'] === 'harian' ? 'Hari' : 'Bulan'), 'png' => $png];
        if ($fs['branches'] && count($fs['branches']) > 1) {
            $bSer = []; $pal2 = array_map('chart_hex2rgb', chart_colors(count($fs['branches']))); $bi = 0;
            foreach ($fs['branches'] as $nm => $vals) {
                $bSer[] = ['label' => branch_short_label((string)$nm), 'data' => $vals, 'color' => $pal2[$bi % count($pal2)]];
                $bi++;
            }
            $png2 = chart_bar(['labels' => $fs['labels'], 'series' => $bSer], 'Laba Bersih per Cabang');
            if ($png2) $items[] = ['title' => 'Laba Bersih per Cabang', 'png' => $png2];
        }
        $bytes = xlsx_build($sheets, ['name' => 'Grafik', 'items' => $items]);
        $fname = 'keuangan-' . $fk['ps'] . '-sd-' . $fk['pe'] . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
    /* CSV laporan keuangan */
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="keuangan-' . $fk['ps'] . '-sd-' . $fk['pe'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [clinic_name() . ' — Laporan Keuangan (' . finance_mode_label($sk['mode']) . ')']);
    fputcsv($out, ['Periode', $fk['ps'] . ' s.d. ' . $fk['pe'], 'Cakupan', $scopeName]);
    fputcsv($out, []);
    fputcsv($out, ['Komponen', 'Nilai']);
    fputcsv($out, ['Pendapatan Treatment', round($sk['pendapatan_treatment'], 2)]);
    fputcsv($out, ['Pendapatan Skincare', round($sk['pendapatan_skincare'], 2)]);
    fputcsv($out, ['Pendapatan Paket', round($sk['pendapatan_paket'], 2)]);
    fputcsv($out, ['Pengurangan Diskon Transaksi', -round($sk['diskon'], 2)]);
    fputcsv($out, ['Pengurangan Diskon Member', -round($sk['diskon_member'], 2)]);
    fputcsv($out, ['TOTAL PENDAPATAN (OMZET)', round($sk['omzet'], 2)]);
    fputcsv($out, ['HPP Treatment', -round($sk['hpp_treatment'], 2)]);
    fputcsv($out, ['HPP Produk', -round($sk['hpp_produk'], 2)]);
    fputcsv($out, ['HPP Paket', -round($sk['hpp_paket'], 2)]);
    fputcsv($out, ['HPP Paket', -round($sk['hpp_paket'], 2)]);
    if ($sk['mode'] === 'lengkap') {
        fputcsv($out, ['LABA KOTOR', round($sk['laba_kotor'], 2)]);
        foreach ($sk['biaya_rows'] as $c) {
            fputcsv($out, [$c['name'] . ' (' . $c['period_label'] . ' · ' . ($c['scope_label'] ?? 'Semua cabang') . ')',
                -round($c['share'], 2)]);
            if (!empty($c['breakdown']) && count($c['parts'] ?? []) > 1) fputcsv($out, ['   = ' . $c['breakdown'], '']);
        }
        fputcsv($out, ['TOTAL BIAYA OPERASIONAL', -round($sk['biaya_total'], 2)]);
    }
    fputcsv($out, ['LABA BERSIH', round($sk['laba_bersih'], 2)]);
    if ($sk['margin'] !== null) fputcsv($out, ['Margin Laba Bersih (%)', $sk['margin']]);
    fputcsv($out, []);
    fputcsv($out, ['Cabang', 'Omzet', 'HPP Treatment', 'HPP Produk', 'Biaya Operasional', 'Laba Bersih']);
    foreach (finance_per_branch($fk) as $b) {
        fputcsv($out, [$b['branch'], round($b['omzet'], 2), round($b['hpp_treatment'], 2),
            round($b['hpp_produk'], 2), round($b['biaya_total'], 2), round($b['laba_bersih'], 2)]);
    }
    fclose($out);
    exit;
}

/* `&keuangan=1` = ikutkan blok keuangan (HPP & laba bersih). HANYA boleh dipakai
   level owner — permintaan dari level lain diabaikan (tanpa blok keuangan). */
if (gp('keuangan') === '1' && has_perm('finance.view') && is_owner_level()) finance_report_include(true);

if ($type === 'laporan' && $format === 'xlsx') {
    require_once __DIR__ . '/includes/reports.php';
    require_once __DIR__ . '/includes/chartimg.php';
    require_once __DIR__ . '/includes/xlsx.php';
    $Bx = report_bundle(true);
    $fx = $Bx['filters'];
    $tx = $Bx['totals'];

    audit('Export Excel + Grafik', 'Laporan', null, null, [
        'periode' => $fx['ps'] . ' s.d. ' . $fx['pe'],
        'cakupan' => $fx['scope'] === null ? 'Semua Cabang' : $fx['scope'],
    ], 'Laporan lengkap + grafik diekspor ke Excel');

    $scopeName = $fx['scope'] === null ? 'Semua Cabang' : (string)scalar('SELECT name FROM branches WHERE id=?', [$fx['scope']], '-');
    $sheets = [];

    // 1. Ringkasan
    $sheets[] = ['name' => 'Ringkasan', 'widths' => [38, 20], 'rows' => [
        ['Keterangan', 'Nilai'],
        ['Periode', $fx['ps'] . ' s.d. ' . $fx['pe']],
        ['Cakupan', $scopeName],
        ['Status transaksi', $fx['status']],
        ['Dicetak', date('Y-m-d H:i') . ' WIB oleh ' . $user['name']],
        ['', ''],
        ['Total Pendapatan', (float)$tx['total']],
        ['Jumlah Transaksi', (int)$tx['trx']],
        ['Rata-rata per Transaksi', (float)round($tx['avg'])],
        ['Subtotal (sebelum diskon)', (float)$tx['subtotal']],
        ['Total Diskon', (float)$tx['disc']],
        ['Diskon Member (otomatis)', (float)($tx['member_disc'] ?? 0)],
        ['Transaksi Memakai Kartu Member', (int)($tx['member_trx'] ?? 0)],
        ['Pendapatan Treatment', (float)$tx['tr']],
        ['Jumlah Treatment Terjual', (float)$tx['tr_q']],
        ['Penjualan Skincare', (float)$tx['sk']],
        ['Jumlah Skincare Terjual', (float)$tx['sk_q']],
        ['Pendapatan Paket', (float)($tx['pkg'] ?? 0)],
        ['Jumlah Paket Terjual', (float)($tx['pkg_q'] ?? 0)],
        ['Total Pembayaran Valid', (float)$tx['pay_total']],
        ['Jumlah Pembayaran', (int)$tx['pay_n']],
    ]];

    // 2. Progres berkala
    $mx = $Bx['monthly'];
    $rows = [['Periode', 'Transaksi', 'Treatment', 'Skincare', 'Total', 'Perubahan %']];
    foreach ($mx['labels_full'] as $i => $lbl) {
        $prev = $i > 0 ? $mx['total'][$i - 1] : 0;
        $rows[] = [$lbl, (int)$mx['trx'][$i], (float)$mx['tr'][$i], (float)$mx['sk'][$i], (float)$mx['total'][$i],
                   $prev > 0 ? round(($mx['total'][$i] - $prev) / $prev * 100, 1) : ''];
    }
    $rows[] = ['TOTAL', (int)array_sum($mx['trx']), (float)array_sum($mx['tr']), (float)array_sum($mx['sk']), (float)$mx['sum'], ''];
    /* Grafik NATIVE EXCEL dari sel lembar ini: bila angkanya diubah, grafik ikut
       berubah. Dua grafik dipisah — (1) Treatment vs Skincare berjajaran,
       (2) Total Pendapatan — supaya nilai masing-masing terbaca dan Total tidak
       menempel di puncak batang seperti grafik bertumpuk. */
    $lastDataRow = count($rows);                    // baris pertama = judul kolom
    $progresCharts = [];
    if ($lastDataRow > 2) {
        $progresCharts[] = [
            'title' => 'Progres Pendapatan — Treatment vs Skincare',
            'cat' => '$A$2:$A$' . $lastDataRow,
            'type' => 'col',
            'series' => [
                ['name' => 'Treatment', 'name_ref' => '$C$1', 'range' => '$C$2:$C$' . $lastDataRow,
                 'color' => ltrim(chart_series_color('treatment'), '#')],
                ['name' => 'Skincare', 'name_ref' => '$D$1', 'range' => '$D$2:$D$' . $lastDataRow,
                 'color' => ltrim(chart_series_color('skincare'), '#')],
            ],
        ];
        $progresCharts[] = [
            'title' => 'Total Pendapatan',
            'cat' => '$A$2:$A$' . $lastDataRow,
            'type' => 'line',
            'series' => [
                ['name' => 'Total', 'name_ref' => '$E$1', 'range' => '$E$2:$E$' . $lastDataRow, 'color' => '6D4C41'],
            ],
        ];
    }
    $sheets[] = [
        'name' => 'Progres ' . ucfirst($mx['granularity']),
        'widths' => [22, 12, 16, 16, 16, 14],
        'rows' => $rows,
        'charts' => $progresCharts,
    ];

    // 3. Per cabang
    $rows = [['Cabang', 'Transaksi', 'Treatment', 'Skincare', 'Diskon', 'Pendapatan', 'Kontribusi %']];
    $sumBx = array_sum(array_column($Bx['branches'], 'total')) ?: 1;
    foreach ($Bx['branches'] as $b) {
        $rows[] = [$b['name'], (int)$b['trx'], (float)$b['tr'], (float)$b['sk'], (float)$b['disc'],
                   (float)$b['total'], round($b['total'] / $sumBx * 100, 1)];
    }
    if ($Bx['branches']) {
        $rows[] = ['TOTAL', (int)array_sum(array_column($Bx['branches'], 'trx')),
                   (float)array_sum(array_column($Bx['branches'], 'tr')), (float)array_sum(array_column($Bx['branches'], 'sk')),
                   (float)array_sum(array_column($Bx['branches'], 'disc')), (float)array_sum(array_column($Bx['branches'], 'total')), 100];
    }
    $brLast = count($rows);
    $brCharts = [];
    if (count($Bx['branches']) >= 2) {
        $brCharts[] = [
            'title' => 'Pendapatan per Cabang',
            'cat' => '$A$2:$A$' . $brLast,
            'type' => 'col',
            'series' => [['name' => 'Pendapatan', 'name_ref' => '$F$1', 'range' => '$F$2:$F$' . $brLast, 'color' => ltrim(chart_series_color('treatment'), '#')]],
        ];
        $brCharts[] = [
            'title' => 'Kontribusi Pendapatan per Cabang',
            'cat' => '$A$2:$A$' . $brLast,
            'type' => 'pie',
            'series' => [['name' => 'Pendapatan', 'range' => '$F$2:$F$' . $brLast]],
        ];
    }
    $sheets[] = ['name' => 'Per Cabang', 'widths' => [30, 12, 16, 16, 14, 16, 14], 'rows' => $rows,
        'charts' => $brCharts];

    // 4. Metode pembayaran
    $rows = [['Metode', 'Jumlah', 'Total', 'Porsi %']];
    foreach ($Bx['methods'] as $m) {
        $rows[] = [$m['method'], (int)$m['n'], (float)$m['total'], round($tx['pay_total'] > 0 ? $m['total'] / $tx['pay_total'] * 100 : 0, 1)];
    }
    if (count($rows) < 2) $rows[] = ['-', 0, 0, 0];
    $sheets[] = ['name' => 'Metode Bayar', 'widths' => [22, 12, 16, 12], 'rows' => $rows,
        'charts' => count($rows) > 2 ? [[
            'title' => 'Komposisi Metode Pembayaran',
            'cat' => '$A$2:$A$' . count($rows),
            'type' => 'pie',
            'series' => [['name' => 'Total', 'range' => '$C$2:$C$' . count($rows)]],
        ]] : []];

    // 5. Kinerja kasir
    $rows = [['Kasir', 'Transaksi', 'Treatment', 'Skincare', 'Pendapatan', 'Rata-rata/Transaksi']];
    foreach ($Bx['cashiers'] as $c) {
        $rows[] = [$c['nama'], (int)$c['trx'], (float)$c['tr'], (float)$c['sk'], (float)$c['total'],
                   $c['trx'] > 0 ? (float)round($c['total'] / $c['trx']) : 0];
    }
    if (count($rows) < 2) $rows[] = ['-', 0, 0, 0, 0, 0];
    $sheets[] = ['name' => 'Kinerja Kasir', 'widths' => [28, 12, 16, 16, 16, 18], 'rows' => $rows,
        'charts' => count($rows) > 2 ? [[
            'title' => 'Pendapatan per Kasir',
            'cat' => '$A$2:$A$' . count($rows),
            'type' => 'col',
            'series' => [['name' => 'Pendapatan', 'name_ref' => '$E$1', 'range' => '$E$2:$E$' . count($rows), 'color' => ltrim(chart_series_color('treatment'), '#')]],
        ]] : []];

    // 6 & 7. Top 5
    $rows = [['#', 'Treatment', 'Kategori', 'Terjual', 'Pendapatan']];
    foreach (array_slice($Bx['treatments'] ?? [], 0, 5) as $i => $r) {
        $rows[] = [$i + 1, $r['nama'], $r['kategori'], (float)$r['q'], (float)$r['s']];
    }
    $sheets[] = ['name' => 'Top 5 Treatment', 'widths' => [6, 38, 16, 12, 16], 'rows' => $rows,
        'charts' => count($rows) > 2 ? [[
            'title' => 'Top 5 Treatment (Pendapatan)',
            'cat' => '$B$2:$B$' . count($rows),
            'type' => 'col',
            'series' => [['name' => 'Pendapatan', 'name_ref' => '$E$1', 'range' => '$E$2:$E$' . count($rows), 'color' => ltrim(chart_series_color('treatment'), '#')]],
        ]] : []];
    $rows = [['#', 'Produk Skincare', 'Kategori', 'Terjual', 'Penjualan']];
    foreach (array_slice($Bx['skincares'] ?? [], 0, 5) as $i => $r) {
        $rows[] = [$i + 1, $r['nama'], $r['kategori'], (float)$r['q'], (float)$r['s']];
    }
    $sheets[] = ['name' => 'Top 5 Skincare', 'widths' => [6, 38, 16, 12, 16], 'rows' => $rows,
        'charts' => count($rows) > 2 ? [[
            'title' => 'Top 5 Skincare (Penjualan)',
            'cat' => '$B$2:$B$' . count($rows),
            'type' => 'col',
            'series' => [['name' => 'Penjualan', 'name_ref' => '$E$1', 'range' => '$E$2:$E$' . count($rows), 'color' => ltrim(chart_series_color('skincare'), '#')]],
        ]] : []];

    // 8. Top 10 pasien
    $bsx = $fx['scope'] === null ? '' : ' AND o.branch_id = ?';
    $bpx = $fx['scope'] === null ? [] : [$fx['scope']];
    $tp = all("SELECT p.name, p.member_number, p.patient_number, b.name branch_name,
                      COUNT(DISTINCT o.id) trx, COALESCE(SUM(o.total),0) total
               FROM orders o JOIN patients p ON p.id=o.patient_id JOIN branches b ON b.id=o.branch_id
               WHERE o.status='paid' AND date(o.created_at) BETWEEN ? AND ? {$bsx}
               GROUP BY p.id ORDER BY trx DESC, total DESC LIMIT 10", array_merge([$fx['ps'], $fx['pe']], $bpx));
    $rows = [['#', 'Nama Pasien', 'No. Member', 'No. Pasien', 'Kunjungan', 'Total Transaksi', 'Cabang']];
    foreach ($tp as $i => $r) {
        $rows[] = [$i + 1, $r['name'], $r['member_number'] ?: '-', $r['patient_number'], (int)$r['trx'], (float)$r['total'], $r['branch_name']];
    }
    $sheets[] = ['name' => 'Top 10 Pasien', 'widths' => [6, 30, 18, 18, 12, 18, 28], 'rows' => $rows];

    // 9 & 10. Rincian item
    $rows = [['Treatment', 'Kode', 'Kategori', 'Terjual', 'Pendapatan']];
    foreach ($Bx['treatments'] ?? [] as $r) $rows[] = [$r['nama'], $r['kode'], $r['kategori'], (float)$r['q'], (float)$r['s']];
    $sheets[] = ['name' => 'Rincian Treatment', 'widths' => [38, 14, 16, 12, 16], 'rows' => $rows];
    $rows = [['Produk', 'Kode', 'Kategori', 'Terjual', 'Penjualan']];
    foreach ($Bx['skincares'] ?? [] as $r) $rows[] = [$r['nama'], $r['kode'], $r['kategori'], (float)$r['q'], (float)$r['s']];
    $sheets[] = ['name' => 'Rincian Skincare', 'widths' => [38, 14, 16, 12, 16], 'rows' => $rows];

    // 11. Pemakaian bahan treatment (tidak dijual ke pasien)
    $rows = [['Bahan', 'Kode', 'Jumlah Terpakai', 'Jumlah Transaksi', 'Harga Satuan', 'Nilai Bahan', 'Catatan']];
    $nq = 0; $nv = 0;
    foreach ($Bx['materials'] ?? [] as $r) {
        $hrg = $r['material_id'] ? (float)scalar('SELECT price FROM treatment_materials WHERE id=?', [(int)$r['material_id']], 0) : 0;
        $nq += (float)$r['q']; $nv += $hrg * (float)$r['q'];
        $rows[] = [$r['nama'], $r['kode'], qty_text($r['q']), (int)$r['trx'], $hrg, $hrg * (float)$r['q'], 'Tidak ditagihkan ke pasien'];
    }
    if ($nq > 0) $rows[] = ['TOTAL', '', qty_text($nq), '', '', $nv, 'Tidak masuk pendapatan'];
    $sheets[] = ['name' => 'Pemakaian Bahan', 'widths' => [34, 14, 16, 16, 14, 16, 30], 'rows' => $rows];

    // 12. Pemakaian kartu member (diskon otomatis)
    $rows = [['Level Member', 'Cakupan Diskon', 'Jumlah Transaksi', 'Nilai Transaksi', 'Total Diskon Member', 'Rata-rata Diskon']];
    $sumT = 0; $sumS = 0; $sumD = 0;
    foreach ($Bx['member_usage'] ?? [] as $r) {
        $sumT += (int)$r['trx']; $sumS += (float)$r['subtotal']; $sumD += (float)$r['disc'];
        $rows[] = [$r['tier'], member_scope_text((string)($r['scope'] ?? 'both')), (int)$r['trx'],
            (float)$r['subtotal'], (float)$r['disc'],
            (float)round((int)$r['trx'] > 0 ? (float)$r['disc'] / (int)$r['trx'] : 0)];
    }
    if ($sumT > 0) $rows[] = ['TOTAL', '', $sumT, $sumS, $sumD, ''];
    $sheets[] = ['name' => 'Kartu Member', 'widths' => [28, 22, 16, 18, 20, 16], 'rows' => $rows,
        'charts' => count($rows) > 2 ? [[
            'title' => 'Diskon Member per Level',
            'cat' => '$A$2:$A$' . count($rows),
            'type' => 'col',
            'series' => [['name' => 'Diskon Member', 'name_ref' => '$E$1', 'range' => '$E$2:$E$' . count($rows), 'color' => ltrim(chart_series_color('treatment'), '#')]],
        ]] : []];

    /* 13. KEUANGAN (khusus owner): HPP, laba kotor, biaya operasional, laba
       bersih, dan laba bersih per cabang + grafik Excel dari sel. */
    $extraCharts = [];
    if (finance_report_include()) {
        $fxs = finance_summary($fx, finance_mode() === 'lengkap', $fx['scope']);
        $rows = [['Komponen', 'Nilai']];
        $rows[] = ['Mode laporan', finance_mode_label($fxs['mode'])];
        $rows[] = ['Pendapatan Treatment', (float)$fxs['pendapatan_treatment']];
        $rows[] = ['Pendapatan Skincare', (float)$fxs['pendapatan_skincare']];
        $rows[] = ['Pendapatan Paket', (float)$fxs['pendapatan_paket']];
        $rows[] = ['Pengurangan Diskon Transaksi', -(float)$fxs['diskon']];
        $rows[] = ['Pengurangan Diskon Member', -(float)$fxs['diskon_member']];
        $rows[] = ['TOTAL PENDAPATAN (OMZET)', (float)$fxs['omzet']];
        $rows[] = ['HPP Treatment', -(float)$fxs['hpp_treatment']];
        $rows[] = ['HPP Produk', -(float)$fxs['hpp_produk']];
        $rows[] = ['HPP Paket', -(float)$fxs['hpp_paket']];
        $omzetRow = 7;   // baris "TOTAL PENDAPATAN (OMZET)" pada lembar ini
        if ($fxs['mode'] === 'lengkap') {
            $rows[] = ['LABA KOTOR', (float)$fxs['laba_kotor']];
            foreach ($fxs['biaya_rows'] as $c) {
                $rows[] = [$c['name'] . ' (' . $c['period_label'] . ' ' . money($c['amount']) . ')', -(float)$c['share']];
            }
            $rows[] = ['TOTAL BIAYA OPERASIONAL', -(float)$fxs['biaya_total']];
        }
        $rows[] = ['LABA BERSIH', (float)$fxs['laba_bersih']];
        if ($fxs['margin'] !== null) $rows[] = ['Margin Laba Bersih (%)', (float)$fxs['margin']];
        $sheets[] = ['name' => 'Keuangan', 'widths' => [46, 20], 'rows' => $rows];

        $rows = [['Cabang', 'Transaksi', 'Omzet', 'HPP Treatment', 'HPP Produk', 'Biaya Operasional', 'Laba Bersih', 'Margin %']];
        foreach (finance_per_branch($fx) as $b) {
            $rows[] = [$b['branch'], (int)$b['trx'], (float)$b['omzet'], (float)$b['hpp_treatment'],
                (float)$b['hpp_produk'], (float)$b['biaya_total'], (float)$b['laba_bersih'],
                $b['margin'] !== null ? (float)$b['margin'] : ''];
        }
        $sheets[] = ['name' => 'Laba per Cabang', 'widths' => [26, 12, 16, 16, 16, 18, 16, 12], 'rows' => $rows,
            'charts' => count($rows) > 2 ? [[
                'title' => 'Laba Bersih per Cabang',
                'cat' => '$A$2:$A$' . count($rows),
                'type' => 'col',
                'series' => [['name' => 'Laba Bersih', 'name_ref' => '$G$1', 'range' => '$G$2:$G$' . count($rows),
                    'color' => ltrim(chart_series_color('positif'), '#')]],
            ]] : []];

        /* Grafik laba bersih per periode (lembar gambar "Grafik"). */
        $fseries = finance_series($fx);
        $png = chart_bar([
            'labels' => $fseries['labels'],
            'series' => [['label' => 'Laba Bersih', 'data' => $fseries['laba'],
                          'color' => chart_hex2rgb(chart_series_color('positif'))]],
        ], 'Laba Bersih per ' . ($fseries['granularity'] === 'harian' ? 'Hari' : 'Bulan'));
        if ($png) $extraCharts[] = ['title' => 'Laba Bersih per ' . ($fseries['granularity'] === 'harian' ? 'Hari' : 'Bulan'), 'png' => $png];
        if ($fseries['branches'] && count($fseries['branches']) > 1) {
            $bSer = []; $pal2 = array_map('chart_hex2rgb', chart_colors(count($fseries['branches']))); $bi = 0;
            foreach ($fseries['branches'] as $nm => $vals) {
                $bSer[] = ['label' => branch_short_label((string)$nm), 'data' => $vals, 'color' => $pal2[$bi % count($pal2)]];
                $bi++;
            }
            $png2 = chart_bar(['labels' => $fseries['labels'], 'series' => $bSer], 'Laba Bersih per Cabang');
            if ($png2) $extraCharts[] = ['title' => 'Laba Bersih per Cabang', 'png' => $png2];
        }
    }

    // + sheet grafik
    $items = [];
    foreach (report_charts($Bx) as $c) $items[] = ['title' => $c['title'], 'png' => $c['png']];
    foreach ($extraCharts as $c) $items[] = ['title' => $c['title'], 'png' => $c['png']];

    $bytes = xlsx_build($sheets, ['name' => 'Grafik', 'items' => $items]);
    $fname = 'laporan-lengkap-' . $fx['ps'] . '-sd-' . $fx['pe'] . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

/* ------------------------------------------------------------------ *
 * Laporan lengkap sebagai PDF (dibuat server, BERISI GRAFIK).
 * Sama dengan PDF yang dilampirkan pada email laporan otomatis.
 * ------------------------------------------------------------------ */
if ($type === 'laporan' && $format === 'pdfserver') {
    require_once __DIR__ . '/includes/report_pdf.php';
    $Bs = report_bundle(true);
    audit('Unduh Laporan PDF + Grafik', 'Laporan', null, null, [
        'periode' => $Bs['filters']['ps'] . ' s.d. ' . $Bs['filters']['pe'],
        'cakupan' => $Bs['filters']['scope'] === null ? 'Semua Cabang' : $Bs['filters']['scope'],
    ], 'Laporan PDF (server) + grafik dibuat');
    $pdf = report_pdf_build($Bs, $user);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdf['filename'] . '"');
    header('Content-Length: ' . strlen($pdf['bytes']));
    echo $pdf['bytes'];
    exit;
}

if ($type === 'laporan' && $format === 'excel') {
    require_once __DIR__ . '/includes/reports.php';
    $B2 = report_bundle(true);
    audit('Export Excel', 'Laporan', null, null, [
        'periode' => $B2['filters']['ps'] . ' s.d. ' . $B2['filters']['pe'],
        'cakupan' => $B2['filters']['scope'] === null ? 'Semua Cabang' : $B2['filters']['scope'],
    ], 'Laporan lengkap diekspor ke Excel');

    $f2 = $B2['filters'];
    $t2 = $B2['totals'];
    $fname = 'laporan-lengkap-' . $f2['ps'] . '-sd-' . $f2['pe'] . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    echo "\xEF\xBB\xBF";
    $num = function ($v) { return is_float($v) || is_numeric($v) ? (float)$v : $v; };
    $section = function (string $title) { echo '<tr><td colspan="8" style="background:#FDF2F7;font-weight:bold">' . e($title) . '</td></tr>'; };
    $tbl = function (array $head, array $body, string $title) {
        echo '<table border="1" cellspacing="0" cellpadding="4" style="margin-bottom:14px">';
        echo '<tr><th colspan="' . max(1, count($head)) . '" style="background:#C2185B;color:#fff;text-align:left">' . e($title) . '</th></tr>';
        echo '<tr>'; foreach ($head as $h) echo '<th style="background:#FDF2F7">' . e($h) . '</th>'; echo '</tr>';
        foreach ($body as $r) {
            echo '<tr>';
            foreach ($r as $c) echo '<td>' . e(is_float($c) ? number_format($c, 0, ',', '.') : (string)$c) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    };
    echo '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>';
    echo '<h2 style="color:#8E0E42">' . e(clinic_name()) . ' — Laporan Lengkap</h2>';
    echo '<p>Periode: <b>' . e(tgl($f2['ps'])) . ' — ' . e(tgl($f2['pe'])) . '</b> · Cakupan: <b>'
        . e($f2['scope'] === null ? 'Semua Cabang' : (string)scalar('SELECT name FROM branches WHERE id=?', [$f2['scope']], '-'))
        . '</b> · Status transaksi: <b>' . e($f2['status']) . '</b><br>Dibuat: ' . e(tglIndo(date('Y-m-d'))) . ' '
        . date('H:i') . ' oleh ' . e($user['name']) . ' (' . e($user['role_name']) . ')</p>';

    $tbl(['Keterangan', 'Nilai'], [
        ['Total Pendapatan', $t2['total']],
        ['Jumlah Transaksi', $t2['trx']],
        ['Rata-rata per Transaksi', $t2['avg']],
        ['Subtotal (sebelum diskon)', $t2['subtotal']],
        ['Total Diskon', $t2['disc']],
        ['Pendapatan Treatment', $t2['tr']],
        ['Jumlah Treatment Terjual', $t2['tr_q']],
        ['Penjualan Skincare', $t2['sk']],
        ['Jumlah Skincare Terjual', $t2['sk_q']],
        ['Total Pembayaran Valid', $t2['pay_total']],
        ['Jumlah Pembayaran', $t2['pay_n']],
    ], '1. Ringkasan Periode');

    $monthly = $B2['monthly'];
    $mRows = [];
    foreach ($monthly['labels_full'] as $i => $lbl) {
        $prev = $i > 0 ? $monthly['total'][$i - 1] : 0;
        $chg = $prev > 0 ? round((($monthly['total'][$i] - $prev) / $prev) * 100, 1) : '';
        $mRows[] = [$lbl, $monthly['trx'][$i], $monthly['tr'][$i], $monthly['sk'][$i], $monthly['total'][$i],
                    $chg === '' ? '-' : (($chg >= 0 ? '+' : '') . $chg . '%')];
    }
    $mRows[] = ['TOTAL', array_sum($monthly['trx']), array_sum($monthly['tr']), array_sum($monthly['sk']), $monthly['sum'], ''];
    $tbl(['Bulan', 'Transaksi', 'Treatment', 'Skincare', 'Total', 'Perubahan'], $mRows,
        '2. Progres Bulanan (' . count($monthly['labels']) . ' bulan terakhir)');

    $brRows = [];
    $brSum = array_sum(array_column($B2['branches'], 'total')) ?: 1;
    foreach ($B2['branches'] as $b) {
        $brRows[] = [$b['name'], $b['trx'], $b['tr'], $b['sk'], $b['disc'], $b['total'], round($b['total'] / $brSum * 100, 1) . '%'];
    }
    $brRows[] = ['TOTAL', array_sum(array_column($B2['branches'], 'trx')), array_sum(array_column($B2['branches'], 'tr')),
                 array_sum(array_column($B2['branches'], 'sk')), array_sum(array_column($B2['branches'], 'disc')),
                 array_sum(array_column($B2['branches'], 'total')), '100%'];
    $tbl(['Cabang', 'Transaksi', 'Treatment', 'Skincare', 'Diskon', 'Pendapatan', 'Kontribusi'], $brRows, '3. Perbandingan Cabang');

    $pm = [];
    foreach ($B2['methods'] as $m) $pm[] = [$m['method'], $m['n'], $m['total'], round($t2['pay_total'] > 0 ? $m['total'] / $t2['pay_total'] * 100 : 0, 1) . '%'];
    $tbl(['Metode Pembayaran', 'Jumlah', 'Total', 'Porsi'], $pm ?: [['-', 0, 0, '-']], '4. Metode Pembayaran');

    $ck = [];
    foreach ($B2['cashiers'] as $c) $ck[] = [$c['nama'], $c['trx'], $c['tr'], $c['sk'], $c['total'], $c['trx'] > 0 ? round($c['total'] / $c['trx']) : 0];
    $tbl(['Kasir', 'Transaksi', 'Treatment', 'Skincare', 'Pendapatan', 'Rata-rata/Transaksi'], $ck ?: [['-', 0, 0, 0, 0, 0]], '5. Kinerja Kasir');

    $tt = [];
    foreach (array_slice($B2['treatments'], 0, 5) as $i => $r) $tt[] = [$i + 1, $r['nama'], $r['kategori'], $r['q'], $r['s']];
    $tbl(['#', 'Treatment', 'Kategori', 'Terjual', 'Pendapatan'], $tt ?: [['-', '-', '-', 0, 0]], '6. Top 5 Treatment');
    $ss = [];
    foreach (array_slice($B2['skincares'], 0, 5) as $i => $r) $ss[] = [$i + 1, $r['nama'], $r['kategori'], $r['q'], $r['s']];
    $tbl(['#', 'Produk Skincare', 'Kategori', 'Terjual', 'Penjualan'], $ss ?: [['-', '-', '-', 0, 0]], '7. Top 5 Skincare');

    $bs2 = $f2['scope'] === null ? '' : ' AND o.branch_id = ?';
    $bp2 = $f2['scope'] === null ? [] : [$f2['scope']];
    $tp = all("SELECT p.name, p.patient_number, p.member_number, b.name branch_name,
                      COUNT(DISTINCT o.id) trx, COALESCE(SUM(o.total),0) total
               FROM orders o JOIN patients p ON p.id=o.patient_id JOIN branches b ON b.id=o.branch_id
               WHERE o.status='paid' AND date(o.created_at) BETWEEN ? AND ? {$bs2}
               GROUP BY p.id ORDER BY trx DESC, total DESC LIMIT 10", array_merge([$f2['ps'], $f2['pe']], $bp2));
    $tpRows = [];
    foreach ($tp as $i => $r) $tpRows[] = [$i + 1, $r['name'], $r['member_number'] ?: '-', $r['trx'], $r['total'], $r['branch_name']];
    $tbl(['#', 'Nama Pasien', 'No. Member', 'Kunjungan', 'Total Transaksi', 'Cabang'], $tpRows ?: [['-', '-', '-', 0, 0, '-']], '8. Top 10 Pasien');

    $rt = [];
    foreach ($B2['treatments'] as $r) $rt[] = [$r['nama'], $r['kode'], $r['kategori'], $r['q'], $r['s']];
    $tbl(['Treatment', 'Kode', 'Kategori', 'Terjual', 'Pendapatan'], $rt ?: [['-', '-', '-', 0, 0]], '9. Rincian Treatment');
    $rs = [];
    foreach ($B2['skincares'] as $r) $rs[] = [$r['nama'], $r['kode'], $r['kategori'], $r['q'], $r['s']];
    $tbl(['Produk', 'Kode', 'Kategori', 'Terjual', 'Penjualan'], $rs ?: [['-', '-', '-', 0, 0]], '10. Rincian Skincare');

    echo '<p style="font-size:11px;color:#635F82">Semua angka dihitung dari database pada saat berkas dibuat.</p>';
    echo '</body></html>';
    exit;
}

/* ------------------------------------------------------------------ *
 * Laporan lengkap (PDF siap cetak/simpan) — grafik + tabel dalam satu dokumen.
 * ------------------------------------------------------------------ */
if ($type === 'laporan' && $format === 'pdf') {
    require_once __DIR__ . '/includes/reports.php';
    require_once __DIR__ . '/includes/report_document.php';
    $bundle = report_bundle(true);
    audit('Unduh Laporan Lengkap', 'Laporan', null, null, [
        'periode' => $bundle['filters']['ps'] . ' s.d. ' . $bundle['filters']['pe'],
        'cabang' => $bundle['filters']['scope'] === null ? 'Semua Cabang' : $bundle['filters']['scope'],
        'transaksi' => $bundle['totals']['trx'],
    ], 'Dokumen laporan lengkap (grafik + tabel) dibuat');
    render_report_document($bundle, $user);
    exit;
}

$title = 'Laporan';
$headers = [];
$rows = [];
$totals = null;
$meta = 'Periode: ' . tgl($ps) . ' — ' . tgl($pe) . ' · Cabang: ' . ($scope === null ? 'Semua Cabang' : (string)scalar('SELECT name FROM branches WHERE id=?', [$scope], '-'));

switch ($type) {
    case 'transaksi':
        $title = 'Laporan Transaksi';
        $w = ['o.status IS NOT NULL'];
        $p = [];
        [$b1, $b2] = sc('o.branch_id', $scope);
        $w[] = '1=1' . $b1;
        $p = array_merge($p, $b2);
        if (gp('from') !== '') { $w[] = 'date(o.created_at) >= ?'; $p[] = gp('from'); }
        if (gp('to') !== '') { $w[] = 'date(o.created_at) <= ?'; $p[] = gp('to'); }
        if (gp('status') !== '') { $w[] = 'o.status = ?'; $p[] = gp('status'); }
        if (gp('q') !== '') { $w[] = '(o.invoice_number LIKE ? OR pat.name LIKE ?)'; $p[] = '%' . gp('q') . '%'; $p[] = '%' . gp('q') . '%'; }
        $sql = 'SELECT o.invoice_number, o.created_at, pat.name patient, b.name branch, o.cashier_name, o.subtotal, o.discount, o.total, o.status,
                       o.member_card, o.member_discount, o.member_tier, o.member_scope,
                       (SELECT GROUP_CONCAT(DISTINCT pm.method) FROM payments pm WHERE pm.order_id=o.id) methods
                FROM orders o JOIN patients pat ON pat.id=o.patient_id JOIN branches b ON b.id=o.branch_id
                WHERE ' . implode(' AND ', $w) . ' ORDER BY o.id DESC';
        $headers = ['Invoice', 'Tanggal', 'Pasien', 'Cabang', 'Kasir', 'Subtotal', 'Diskon', 'Diskon Member',
                    'Kartu Member', 'Cakupan Diskon', 'Total', 'Metode Bayar', 'Status'];
        $sum = 0;
        foreach (all($sql, $p) as $r) {
            $rows[] = [$r['invoice_number'], $r['created_at'], $r['patient'], $r['branch'], $r['cashier_name'] ?: '-',
                       $r['subtotal'], $r['discount'], (float)($r['member_discount'] ?? 0),
                       (int)($r['member_card'] ?? 0) === 1 ? ('Ya' . (!empty($r['member_tier']) ? ' — ' . $r['member_tier'] : '')) : 'Tidak',
                       (int)($r['member_card'] ?? 0) === 1 ? member_scope_text((string)($r['member_scope'] ?? '')) : '-',
                       $r['total'], $r['methods'] ?: '-', $r['status']];
            if ($r['status'] === 'paid') $sum += (float)$r['total'];
        }
        $totals = ['', '', '', '', 'TOTAL (transaksi valid)', '', '', '', '', '', $sum, '', ''];
        $meta = 'Transaksi' . ($ps ? ' · ' . tgl($ps) : '') . ' — ' . tgl($pe) . ' · Cabang: ' . ($scope === null ? 'Semua Cabang' : (string)scalar('SELECT name FROM branches WHERE id=?', [$scope], '-'));
        break;

    case 'pasien':
        require_perm('patient.view');
        $title = 'Data Pasien';
        [$b1, $b2] = sc('p.branch_id', $scope);
        $p = $b2;
        $w = 'p.status = "active" AND 1=1' . $b1;
        if (gp('q') !== '') { $w .= ' AND (p.name LIKE ? OR p.nik LIKE ? OR p.phone LIKE ? OR p.email LIKE ? OR p.member_number LIKE ?)'; $q = '%' . gp('q') . '%'; array_push($p, $q, $q, $q, $q, $q); }
        $headers = ['No. Pasien', 'Nama', 'JK', 'NIK', 'Telepon', 'Email', 'Member', 'Status', 'Cabang', 'Kunjungan', 'Total Belanja'];
        foreach (all("SELECT p.*, b.name branch, (SELECT COUNT(*) FROM orders o WHERE o.patient_id=p.id AND o.status='paid') visits,
                             (SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.patient_id=p.id AND o.status='paid') spent
                      FROM patients p JOIN branches b ON b.id=p.branch_id WHERE {$w} ORDER BY p.name", $p) as $r) {
            $rows[] = [$r['patient_number'], $r['name'], $r['gender'] ?: '-', $r['nik'] ?: '-', $r['phone'] ?: '-', ($r['email'] ?? '') ?: '-', $r['member_number'] ?: '-', $r['patient_type'], $r['branch'], $r['visits'], $r['spent']];
        }
        $meta = 'Seluruh data pasien aktif · Cabang: ' . ($scope === null ? 'Semua Cabang' : (string)scalar('SELECT name FROM branches WHERE id=?', [$scope], '-'));
        break;

    case 'inventory':
        require_perm('inventory.view');
        $title = 'Laporan Inventory';
        $headers = ['Jenis', 'Kode', 'Nama', 'Kategori', 'Stok', 'Minimum', 'Satuan', 'Harga', 'Nilai Stok', 'Cabang', 'Status'];
        [$b1, $b2] = sc('s.branch_id', $scope);
        foreach (all("SELECT s.*, b.name branch, 'Skincare' jenis FROM skincare_products s JOIN branches b ON b.id=s.branch_id WHERE 1=1 {$b1} ORDER BY s.name", $b2) as $r) {
            $rows[] = [$r['jenis'], $r['code'], $r['name'], $r['category'], qty_text($r['stock']), qty_text($r['minimum_stock']), $r['unit'], $r['purchase_price'], (float)$r['stock'] * (float)$r['purchase_price'], $r['branch'], $r['status']];
        }
        [$m1, $m2] = sc('m.branch_id', $scope);
        foreach (all("SELECT m.*, b.name branch, 'Bahan Treatment' jenis FROM treatment_materials m JOIN branches b ON b.id=m.branch_id WHERE 1=1 {$m1} ORDER BY m.name", $m2) as $r) {
            $rows[] = [$r['jenis'], $r['code'], $r['name'], $r['category'], qty_text($r['stock']), qty_text($r['minimum_stock']), $r['unit'], $r['price'], (float)$r['stock'] * (float)$r['price'], $r['branch'], $r['status']];
        }
        $meta = 'Seluruh stok · Cabang: ' . ($scope === null ? 'Semua Cabang' : (string)scalar('SELECT name FROM branches WHERE id=?', [$scope], '-'));
        break;

    case 'treatment':
        $title = 'Master Treatment';
        [$b1, $b2] = sc('t.branch_id', $scope);
        /* Kolom HPP hanya untuk level owner (data internal perusahaan). */
        $withHpp = finance_report_include();
        $headers = ['Kode', 'Nama Treatment', 'Kategori', 'Harga Normal', 'Harga Promo'];
        if ($withHpp) $headers[] = 'HPP';
        $headers = array_merge($headers, ['Durasi (menit)', 'Cabang', 'Status']);
        foreach (all("SELECT t.*, b.name branch FROM treatments t JOIN branches b ON b.id=t.branch_id WHERE 1=1 {$b1} ORDER BY t.name", $b2) as $r) {
            $row = [$r['code'], $r['name'], $r['category'], $r['normal_price'], $r['promo_price'] ?: '-'];
            if ($withHpp) $row[] = (float)($r['hpp'] ?? 0);
            $rows[] = array_merge($row, [$r['duration'], $r['branch'], $r['status']]);
        }
        break;

    case 'skincare':
        $title = 'Master Skincare';
        [$b1, $b2] = sc('s.branch_id', $scope);
        $headers = ['Kode', 'Nama Produk', 'Kategori', 'HPP (Harga Beli)', 'Harga Jual', 'Stok', 'Min. Stok', 'Satuan', 'Supplier', 'Cabang', 'Status'];
        foreach (all("SELECT s.*, b.name branch FROM skincare_products s JOIN branches b ON b.id=s.branch_id WHERE 1=1 {$b1} ORDER BY s.name", $b2) as $r) {
            $rows[] = [$r['code'], $r['name'], $r['category'], $r['purchase_price'], $r['selling_price'], qty_text($r['stock']), qty_text($r['minimum_stock']), $r['unit'], $r['supplier_name'] ?: '-', $r['branch'], $r['status']];
        }
        break;

    case 'penjualan':
        $title = 'Rincian Penjualan Item';
        [$b1, $b2] = sc('o.branch_id', $scope);
        $p = array_merge([$ps, $pe], $b2);
        $headers = ['Tanggal', 'Invoice', 'Jenis', 'Kode', 'Item', 'Qty', 'Harga', 'Subtotal', 'Cabang'];
        /* Bahan treatment (item_type='material') TIDAK termasuk penjualan —
           pemakaiannya bisa dilihat pada laporan "Pemakaian Bahan Treatment". */
        foreach (all("SELECT date(o.created_at) tgl, o.invoice_number, oi.item_type, oi.item_code, oi.item_name, oi.quantity, oi.price, oi.subtotal, b.name branch
                      FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN branches b ON b.id=o.branch_id
                      WHERE o.status='paid' AND oi.item_type <> 'material' AND date(o.created_at) BETWEEN ? AND ? {$b1} ORDER BY o.id DESC", $p) as $r) {
            $rows[] = [$r['tgl'], $r['invoice_number'], $r['item_type'] === 'treatment' ? 'Treatment' : 'Skincare', $r['item_code'], $r['item_name'], qty_text($r['quantity']), $r['price'], $r['subtotal'], $r['branch']];
        }
        break;

    case 'keuangan':
        $title = 'Laporan Keuangan';
        [$b1, $b2] = sc('o.branch_id', $scope);
        $p = array_merge([$ps, $pe], $b2);
        $headers = ['Tanggal', 'Jumlah Transaksi', 'Pendapatan Treatment', 'Penjualan Skincare', 'Diskon', 'Diskon Member', 'Total Pendapatan'];
        $rows = all("SELECT date(o.created_at) tgl, COUNT(DISTINCT o.id) trx, COALESCE(SUM(o.total),0) total, COALESCE(SUM(o.discount),0) disc,
                            COALESCE(SUM(o.member_discount),0) mdisc,
                            COALESCE(SUM((SELECT COALESCE(SUM(oi.subtotal),0) FROM order_items oi WHERE oi.order_id=o.id AND oi.item_type='treatment')),0) tr,
                            COALESCE(SUM((SELECT COALESCE(SUM(oi.subtotal),0) FROM order_items oi WHERE oi.order_id=o.id AND oi.item_type='skincare')),0) sk
                     FROM orders o WHERE o.status='paid' AND date(o.created_at) BETWEEN ? AND ? {$b1}
                     GROUP BY tgl ORDER BY tgl", $p);
        $g = ['trx' => 0, 'tr' => 0, 'sk' => 0, 'disc' => 0, 'mdisc' => 0, 'total' => 0];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [$r['tgl'], $r['trx'], $r['tr'], $r['sk'], $r['disc'], $r['mdisc'], $r['total']];
            foreach ($g as $k => $v) $g[$k] += (float)$r[$k];
        }
        $rows = $out;
        $totals = ['TOTAL', $g['trx'], $g['tr'], $g['sk'], $g['disc'], $g['mdisc'], $g['total']];
        break;

    case 'movement':
        require_perm('inventory.view');
        $title = 'Inventory Movement';
        [$b1, $b2] = sc('m.branch_id', $scope);
        $p = $b2;
        $w = '1=1' . $b1;
        if (gp('from') !== '') { $w .= ' AND date(m.created_at) >= ?'; $p[] = gp('from'); }
        if (gp('to') !== '') { $w .= ' AND date(m.created_at) <= ?'; $p[] = gp('to'); }
        if (gp('type') !== '') { $w .= ' AND m.type = ?'; $p[] = gp('type'); }
        $headers = ['Tanggal', 'Item', 'Kode', 'Jenis', 'Stok Sebelum', 'Perubahan', 'Stok Sesudah', 'User', 'Kode Cabang', 'Keterangan'];
        foreach (all("SELECT m.*, b.name branch FROM inventory_movements m JOIN branches b ON b.id=m.branch_id WHERE {$w} ORDER BY m.id DESC", $p) as $r) {
            $rows[] = [$r['created_at'], $r['item_name'], $r['item_code'], $r['type'], qty_text($r['stock_before']), ((float)$r['quantity'] > 0 ? '+' : '') . qty_text($r['quantity']), qty_text($r['stock_after']), $r['user_name'], $r['branch'], $r['reason'] ?: '-'];
        }
        break;

    case 'laporan_treatment':
        $title = 'Laporan Penjualan Treatment';
        [$b1, $b2] = sc('o.branch_id', $scope);
        $headers = ['Treatment', 'Kode', 'Kategori', 'Jumlah Terjual', 'Total Pendapatan', 'Persentase'];
        $d = all("SELECT oi.item_name, oi.item_code, COALESCE(t.category,'-') kategori, COALESCE(SUM(oi.quantity),0) q, COALESCE(SUM(oi.subtotal),0) s
                  FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN treatments t ON t.id=oi.treatment_id
                  WHERE o.status='paid' AND oi.item_type='treatment' AND date(o.created_at) BETWEEN ? AND ? {$b1}
                  GROUP BY oi.item_name ORDER BY s DESC", array_merge([$ps, $pe], $b2));
        $tot = array_sum(array_column($d, 's')) ?: 1;
        $sumQ = 0; $sumS = 0;
        foreach ($d as $r) { $rows[] = [$r['item_name'], $r['item_code'], $r['kategori'], $r['q'], $r['s'], round($r['s'] / $tot * 100, 2) . '%']; $sumQ += $r['q']; $sumS += $r['s']; }
        $totals = ['TOTAL', '', '', $sumQ, $sumS, '100%'];
        break;

    case 'laporan_skincare':
        $title = 'Laporan Penjualan Skincare';
        [$b1, $b2] = sc('o.branch_id', $scope);
        $headers = ['Produk', 'Kode', 'Kategori', 'Jumlah Terjual', 'Total Penjualan', 'Persentase'];
        $d = all("SELECT oi.item_name, oi.item_code, COALESCE(s.category,'-') kategori, COALESCE(SUM(oi.quantity),0) q, COALESCE(SUM(oi.subtotal),0) s
                  FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN skincare_products s ON s.id=oi.skincare_id
                  WHERE o.status='paid' AND oi.item_type='skincare' AND date(o.created_at) BETWEEN ? AND ? {$b1}
                  GROUP BY oi.item_name ORDER BY s DESC", array_merge([$ps, $pe], $b2));
        $tot = array_sum(array_column($d, 's')) ?: 1;
        $sumQ = 0; $sumS = 0;
        foreach ($d as $r) { $rows[] = [$r['item_name'], $r['item_code'], $r['kategori'], $r['q'], $r['s'], round($r['s'] / $tot * 100, 2) . '%']; $sumQ += $r['q']; $sumS += $r['s']; }
        $totals = ['TOTAL', '', '', $sumQ, $sumS, '100%'];
        break;

    case 'pemakaian_bahan':
        /* Pemakaian bahan treatment pada proses treatment. Bahan tidak dijual ke
           pasien sehingga tidak pernah masuk pendapatan — laporan ini hanya
           mencatat pemakaian + nilai biaya bahannya. */
        require_once __DIR__ . '/includes/reports.php';
        $title = 'Pemakaian Bahan Treatment (tidak ditagihkan)';
        [$b1, $b2] = sc('o.branch_id', $scope);
        $ex = all("SELECT date(o.created_at) tgl, o.invoice_number, p.name pasien, b.name cabang,
                          oi.item_code, oi.item_name, oi.quantity, COALESCE(m.unit,'-') unit, m.price harga
                   FROM order_items oi JOIN orders o ON o.id = oi.order_id
                   JOIN patients p ON p.id = o.patient_id JOIN branches b ON b.id = o.branch_id
                   LEFT JOIN treatment_materials m ON m.id = oi.material_id
                   WHERE o.status='paid' AND oi.item_type='material' AND date(o.created_at) BETWEEN ? AND ? {$b1}
                   ORDER BY o.id DESC", array_merge([$ps, $pe], $b2));
        $headers = ['Tanggal', 'Invoice', 'Pasien', 'Cabang', 'Kode', 'Bahan Treatment', 'Jumlah', 'Satuan', 'Harga Satuan', 'Nilai Bahan', 'Ditagihkan?'];
        $nQ = 0; $nV = 0;
        foreach ($ex as $r) {
            $v = (float)$r['harga'] * (float)$r['quantity'];
            $nQ += (float)$r['quantity']; $nV += $v;
            $rows[] = [$r['tgl'], $r['invoice_number'], $r['pasien'], $r['cabang'], $r['item_code'], $r['item_name'],
                qty_text($r['quantity']), $r['unit'], (float)$r['harga'], $v, 'Tidak (bahan treatment)'];
        }
        $totals = ['TOTAL', '', '', '', '', '', qty_text($nQ), '', '', $nV, 'Rp 0 ditagihkan'];
        $footnote = 'Bahan treatment dipakai sebagai pelengkap proses treatment dan tidak dijual ke pasien, '
            . 'sehingga tidak pernah menambah total transaksi atau muncul di struk. Kolom "Nilai Bahan" hanya '
            . 'informasi biaya (harga master bahan × jumlah pakai).';
        break;

    case 'laporan_member':
        /* Pemakaian kartu member & diskon otomatisnya. */
        require_once __DIR__ . '/includes/reports.php';
        $title = 'Laporan Pemakaian Kartu Member';
        [$b1, $b2] = sc('o.branch_id', $scope);
        $rows = [];
        $headers = ['Level Member', 'Cakupan Diskon', 'Jumlah Transaksi', 'Nilai Transaksi', 'Total Diskon Member', 'Rata-rata Diskon'];
        $d = all("SELECT COALESCE(NULLIF(o.member_tier,''),'Tanpa tier') tier,
                         COALESCE(NULLIF(o.member_scope,''),'both') scope, COUNT(*) trx,
                         COALESCE(SUM(o.subtotal),0) subtotal, COALESCE(SUM(o.member_discount),0) disc
                  FROM orders o
                  WHERE o.status='paid' AND o.member_card = 1 AND date(o.created_at) BETWEEN ? AND ? {$b1}
                  GROUP BY tier, scope ORDER BY disc DESC", array_merge([$ps, $pe], $b2));
        $sumT = 0; $sumS = 0; $sumD = 0;
        foreach ($d as $r) {
            $sumT += (int)$r['trx']; $sumS += (float)$r['subtotal']; $sumD += (float)$r['disc'];
            $rows[] = [$r['tier'], member_scope_text((string)$r['scope']), (int)$r['trx'], (float)$r['subtotal'],
                (float)$r['disc'], (float)round((int)$r['trx'] > 0 ? (float)$r['disc'] / (int)$r['trx'] : 0)];
        }
        $totals = ['TOTAL', '', $sumT, $sumS, $sumD, ''];
        $footnote = 'Diskon member dihitung otomatis dari nilai transaksi sesuai level member. Aturan berlaku: ' . member_rules_text() . '. Cakupan: ' . member_scope_text() . ', minimal transaksi ' . money(member_min_transaction()) . '.';
        break;

    case 'icd':
        if (!has_perm('medical.view') && !has_perm('medical.manage')) {
            deny('Anda tidak memiliki akses ke kamus ICD.');
        }
        $ikind = gp('kind') === 'icd9cm' ? 'icd9cm' : 'icd10';
        $title = 'Kamus ' . ($ikind === 'icd10' ? 'ICD-10 (Diagnosis)' : 'ICD-9-CM (Tindakan)');
        $w = ['kind = ?'];
        $p = [$ikind];
        if (gp('q') !== '') {
            $w[] = '(code LIKE ? OR code_norm LIKE ? OR name_id LIKE ? OR name_en LIKE ?)';
            $norm = icd_norm(gp('q'));
            array_push($p, '%' . gp('q') . '%', '%' . $norm . '%', '%' . gp('q') . '%', '%' . gp('q') . '%');
        }
        $headers = $ikind === 'icd10' ? ['Kode', 'Nama Diagnosis (Indonesia)', 'Nama Diagnosis (Inggris)'] : ['Kode', 'Nama Tindakan'];
        foreach (all('SELECT * FROM icd_codes WHERE ' . implode(' AND ', $w) . ' ORDER BY LENGTH(code), code', $p) as $r) {
            $rows[] = $ikind === 'icd10'
                ? [$r['code'], $r['name_id'], $r['name_en']]
                : [$r['code'], $r['name_en']];
        }
        $meta = 'Sumber: ' . e(setting('icd_source_note')) . ' · Total ' . num(count($rows)) . ' kode';
        break;

    case 'rekam_medis':
        if (!has_perm('medical.view')) deny('Anda tidak memiliki akses ke rekam medis.');
        /* Judul ini dipakai SERAGAM pada ekspor CSV, Excel (.xlsx), dan PDF
           (dokumen cetak), termasuk nama lembar Excel (permintaan pemilik). */
        $title = 'Data Rekam Medis Elektronik';
        [$b1, $b2] = sc('m.branch_id', $scope);
        $p = $b2;
        $wc = '1=1' . $b1;
        if (gp('from') !== '') { $wc .= ' AND m.date >= ?'; $p[] = gp('from'); }
        if (gp('to') !== '')   { $wc .= ' AND m.date <= ?'; $p[] = gp('to'); }
        if (gp('q') !== '') {
            $wc .= ' AND (pat.name LIKE ? OR m.record_number LIKE ? OR m.diagnosis LIKE ?)';
            $t = '%' . gp('q') . '%'; array_push($p, $t, $t, $t);
        }
        $headers = ['No. Rekam Medis', 'Tanggal', 'Pasien', 'No. Pasien', 'Dokter/Terapis', 'Cabang',
                    'Subjektif (S)', 'Objektif (O)', 'Assessment / Diagnosa (A)', 'ICD-10', 'Tindakan', 'ICD-9-CM', 'Planning (P)', 'Status'];
        foreach (all("SELECT m.*, pat.name patient, pat.patient_number, b.name branch
                      FROM medical_records m JOIN patients pat ON pat.id=m.patient_id JOIN branches b ON b.id=m.branch_id
                      WHERE {$wc} ORDER BY m.date DESC, m.id DESC", $p) as $r) {
            $rows[] = [$r['record_number'], $r['date'], $r['patient'], $r['patient_number'], $r['staff_name'] ?: '-',
                       $r['branch'], $r['subjective'] ?: '-', $r['objective'] ?: '-', $r['diagnosis'] ?: '-',
                       $r['icd10'] ?: '-', $r['action'] ?: '-', $r['icd9'] ?: '-', $r['solution'] ?: '-', $r['status']];
        }
        $meta = 'Seluruh rekam medis pada rentang/filter yang dipilih · Cabang: '
            . ($scope === null ? 'Semua Cabang' : (string)scalar('SELECT name FROM branches WHERE id=?', [$scope], '-'));
        break;

    case 'suppliers':
        if (!has_perm('supplier.manage') && !has_perm('inventory.view')) deny('Anda tidak memiliki akses ke data supplier.');
        $title = 'Data Supplier';
        [$b1, $b2] = sc('s.branch_id', $scope);
        $headers = ['Nama Supplier', 'Telepon', 'Email', 'Alamat', 'Cabang', 'Jumlah Produk', 'Jumlah Bahan', 'Status'];
        $sqlSup = "SELECT s.*, b.name branch,
                          (SELECT COUNT(*) FROM skincare_products p WHERE p.supplier_name = s.name) produk,
                          (SELECT COUNT(*) FROM treatment_materials m WHERE m.supplier_name = s.name) bahan
                   FROM suppliers s LEFT JOIN branches b ON b.id = s.branch_id
                   WHERE 1=1" . ($scope !== null ? ' AND (s.branch_id = ? OR s.branch_id IS NULL)' : '') . "
                   ORDER BY s.name";
        foreach (all($sqlSup, $scope !== null ? [$scope] : []) as $r) {
            $rows[] = [$r['name'], $r['phone'] ?: '-', $r['email'] ?: '-', $r['address'] ?: '-',
                       $r['branch'] ?: 'Semua Cabang', $r['produk'], $r['bahan'],
                       $r['status'] === 'active' ? 'Aktif' : 'Nonaktif'];
        }
        $meta = 'Seluruh supplier terdaftar';
        break;

    case 'invoice':
        $o = one('SELECT o.*, p.name patient, p.patient_number, b.name branch, b.address, b.phone, b.email FROM orders o
                  JOIN patients p ON p.id=o.patient_id JOIN branches b ON b.id=o.branch_id WHERE o.id = ?', [(int)gp('id')]);
        if (!$o) { http_response_code(404); exit('Transaksi tidak ditemukan.'); }
        assert_branch((int)$o['branch_id']);
        $title = 'Invoice ' . $o['invoice_number'];
        $items = all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$o['id']]);
        $pays = all('SELECT * FROM payments WHERE order_id = ?', [$o['id']]);
        $headers = ['Jenis', 'Kode', 'Item', 'Qty', 'Harga', 'Subtotal'];
        foreach ($items as $it) $rows[] = [$it['item_type'] === 'treatment' ? 'Treatment' : 'Skincare', $it['item_code'], $it['item_name'], qty_text($it['quantity']), $it['price'], $it['subtotal']];
        $meta = 'Pasien: ' . $o['patient'] . ' (' . $o['patient_number'] . ')<br>Cabang: ' . $o['branch'] . '<br>' . $o['address'] . '<br>' . $o['phone'] . '<br>Tanggal: ' . tgl($o['created_at'], true);
        $footnote = 'Subtotal ' . money($o['subtotal']) . ' · Diskon ' . money($o['discount']) . ' · <strong>Total ' . money($o['total']) . '</strong>'
            . ' · Pembayaran: ' . implode(', ', array_map(fn($p) => $p['method'] . ' ' . $p['status'], $pays))
            . '<br>' . setting('receipt_footer');
        break;

    default:
        http_response_code(400);
        exit('Jenis export tidak dikenal.');
}

$footnote = $footnote ?? '';

$filename = $type . '-' . date('Ymd-His');

/* ------------------------------------------------------------------ *
 * EXCEL (.xlsx) ASLI — termasuk LEMBAR FOTO
 *
 * Dipakai tombol "Excel + Foto" pada menu Riwayat Order (foto/lampiran klinis
 * pasien) dan Data Pasien (foto pasien). Foto disimpan di luar folder publik,
 * jadi hanya bisa masuk ke berkas ini lewat jalur yang sudah diperiksa izin &
 * cabangnya di sini — tidak pernah membocorkan foto cabang lain.
 * ------------------------------------------------------------------ */

/* Ukuran FOTO di lembar Excel (dibuat kecil supaya puluhan foto tetap muat
   dalam satu lembar). Grafik laporan TIDAK memakai nilai ini. */
const EXCEL_PHOTO_PX = 200;    // sisi terpanjang gambar yang disimpan (px)
const EXCEL_PHOTO_W  = 170;    // lebar tampil di Excel (px)
const EXCEL_PHOTO_H  = 130;    // tinggi tampil maksimum di Excel (px)

/** Kumpulkan foto pasien (foto profil) untuk ditempel ke Excel. */
function export_patient_photo_items(?int $scope, string $q, int $max = 40): array
{
    $w = ['p.status = "active"'];
    $params = [];
    if ($scope !== null) { $w[] = 'p.branch_id = ?'; $params[] = $scope; }
    if ($q !== '') {
        $w[] = '(p.name LIKE ? OR p.nik LIKE ? OR p.phone LIKE ? OR p.email LIKE ? OR p.member_number LIKE ?)';
        $t = '%' . $q . '%';
        array_push($params, $t, $t, $t, $t, $t);
    }
    $rows = all('SELECT p.id, p.name, p.patient_number, p.member_number, p.photo_file, p.photo_updated_at, b.name branch_name
                 FROM patients p JOIN branches b ON b.id = p.branch_id
                 WHERE ' . implode(' AND ', $w) . ' AND COALESCE(p.photo_file, "") <> "" ORDER BY p.name LIMIT ' . (int)$max, $params);
    $items = [];
    foreach ($rows as $r) {
        $path = photo_dir('patient') . '/' . basename((string)$r['photo_file']);
        $png = photo_png_bytes($path, EXCEL_PHOTO_PX, EXCEL_PHOTO_PX);
        if ($png === null) continue;
        $items[] = [
            'title' => $r['name'] . ' · ' . $r['patient_number']
                . ($r['member_number'] ? ' · ' . $r['member_number'] : '') . ' · ' . $r['branch_name'],
            'png' => $png['png'],
            'width' => EXCEL_PHOTO_W, 'height' => EXCEL_PHOTO_H,
        ];
    }
    return $items;
}

/** Kumpulkan foto/lampiran klinis (rekam medis) untuk ditempel ke Excel. */
function export_clinical_photo_items(?int $scope, string $from, string $to, int $max = 40): array
{
    $w = ['o.status = "paid"'];
    $params = [];
    if ($from !== '') { $w[] = 'date(o.created_at) >= ?'; $params[] = $from; }
    if ($to !== '')   { $w[] = 'date(o.created_at) <= ?'; $params[] = $to; }
    if ($scope !== null) { $w[] = 'm.branch_id = ?'; $params[] = $scope; }
    /* Diambil dari transaksi pada periode/filter yang sama, jadi isi lampiran
       mengikuti apa yang sedang dilihat kasir di Riwayat Order. */
    $rows = all('SELECT ph.id, ph.medical_record_id, ph.local_path, ph.file_url, ph.storage, ph.caption,
                        m.record_number, m.date rm_date, p.name patient_name, p.patient_number,
                        o.invoice_number, b.name branch_name
                 FROM medical_record_photos ph
                 JOIN medical_records m ON m.id = ph.medical_record_id
                 JOIN patients p ON p.id = m.patient_id
                 JOIN branches b ON b.id = m.branch_id
                 JOIN orders o ON o.patient_id = p.id
                 WHERE ' . implode(' AND ', $w) . '
                 GROUP BY ph.id ORDER BY m.date DESC, ph.id DESC LIMIT ' . (int)$max, $params);
    $items = [];
    foreach ($rows as $r) {
        if ((string)$r['storage'] !== 'local' || (string)$r['local_path'] === '') continue;  // foto di CDN tidak bisa ditempel
        $cand = [
            photo_dir('medical') . '/' . basename((string)$r['local_path']),
            local_upload_dir() . '/' . basename((string)$r['local_path']),
        ];
        $path = '';
        foreach ($cand as $c) { if (is_readable($c)) { $path = $c; break; } }
        if ($path === '') continue;
        $png = photo_png_bytes($path, EXCEL_PHOTO_PX, EXCEL_PHOTO_PX);
        if ($png === null) continue;
        $t = 'Invoice ' . $r['invoice_number'] . ' · RM ' . $r['record_number'] . ' (' . tgl($r['rm_date']) . ') · '
            . $r['patient_name'];
        if (trim((string)$r['caption']) !== '') $t .= ' — ' . $r['caption'];
        $items[] = ['title' => $t, 'png' => $png['png'], 'width' => EXCEL_PHOTO_W, 'height' => EXCEL_PHOTO_H];
    }
    return $items;
}

/** Kumpulkan foto/lampiran klinis untuk SATU rekam medis (ekspor data RM). */
function export_rm_photo_items(?int $scope, string $from, string $to, int $max = 40): array
{
    $w = ['1=1'];
    $params = [];
    if ($scope !== null) { $w[] = 'm.branch_id = ?'; $params[] = $scope; }
    if ($from !== '') { $w[] = 'm.date >= ?'; $params[] = $from; }
    if ($to !== '')   { $w[] = 'm.date <= ?'; $params[] = $to; }
    $rows = all('SELECT ph.local_path, ph.caption, m.record_number, m.date rm_date, p.name patient_name
                 FROM medical_record_photos ph
                 JOIN medical_records m ON m.id = ph.medical_record_id
                 JOIN patients p ON p.id = m.patient_id
                 WHERE ' . implode(' AND ', $w) . '
                 ORDER BY m.date DESC, ph.id DESC LIMIT ' . (int)$max, $params);
    $items = [];
    foreach ($rows as $r) {
        if ((string)$r['local_path'] === '') continue;
        $cand = [
            photo_dir('medical') . '/' . basename((string)$r['local_path']),
            local_upload_dir() . '/' . basename((string)$r['local_path']),
        ];
        $path = '';
        foreach ($cand as $c) { if (is_readable($c)) { $path = $c; break; } }
        if ($path === '') continue;
        $png = photo_png_bytes($path, EXCEL_PHOTO_PX, EXCEL_PHOTO_PX);
        if ($png === null) continue;
        $t = 'RM ' . $r['record_number'] . ' (' . tgl($r['rm_date']) . ') · ' . $r['patient_name'];
        if (trim((string)$r['caption']) !== '') $t .= ' — ' . $r['caption'];
        $items[] = ['title' => $t, 'png' => $png['png'], 'width' => EXCEL_PHOTO_W, 'height' => EXCEL_PHOTO_H];
    }
    return $items;
}

if ($format === 'xlsx') {
    require_once __DIR__ . '/includes/xlsx.php';

    /* Lembar data: memakai persis baris yang sama dengan tampilan di layar. */
    $rowsX = [$headers];
    foreach ($rows as $r) {
        $rowsX[] = array_map(fn($c) => is_float($c) ? round($c, 2) : (string)$c, $r);
    }
    if ($totals) $rowsX[] = array_map(fn($c) => is_float($c) ? round($c, 2) : (string)$c, $totals);

    $sheets = [[
        'name' => substr($title, 0, 31),
        'widths' => array_map(fn($h) => max(12, min(34, strlen((string)$h) + 4)), $headers),
        'rows' => $rowsX,
    ]];

    /* Lembar FOTO (bila jenis ekspor ini punya foto). */
    $photoItems = [];
    $photoSheetName = '';
    if ($type === 'pasien') {
        $photoItems = export_patient_photo_items($scope, gp('q'), 40);
        $photoSheetName = 'Foto Pasien';
    } elseif ($type === 'transaksi') {
        $photoItems = export_clinical_photo_items($scope, gp('from'), gp('to'), 40);
        $photoSheetName = 'Foto Lampiran Klinis';
    } elseif ($type === 'rekam_medis') {
        $photoItems = export_rm_photo_items($scope, gp('from'), gp('to'), 40);
        $photoSheetName = 'Foto Lampiran Klinis';
    }

    $images = $photoItems ? ['name' => $photoSheetName, 'items' => $photoItems] : [];
    $bytes = xlsx_build($sheets, $images);

    audit('Export Excel', 'Laporan', null, null,
        ['type' => $type, 'rows' => count($rows), 'foto' => count($photoItems)],
        'Export Excel' . ($photoItems ? ' + ' . count($photoItems) . ' foto' : ''));

    $fname = $filename . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

/* ---------------- CSV ---------------- */
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [clinic_name() . ' — ' . $title]);
    fputcsv($out, [strip_tags(str_replace('<br>', ' | ', $meta))]);
    fputcsv($out, []);
    fputcsv($out, $headers);
    foreach ($rows as $r) fputcsv($out, array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $r));
    if ($totals) fputcsv($out, $totals);
    fclose($out);
    audit('Export CSV', 'Laporan', null, null, ['type' => $type, 'rows' => count($rows)], 'Export data');
    exit;
}

/* ---------------- Excel (HTML table, opens natively in Excel) ---------------- */
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="utf-8"></head><body>';
    echo '<h3>' . e(clinic_name()) . ' — ' . e($title) . '</h3><p>' . $meta . '</p>';
    echo '<table border="1" cellspacing="0" cellpadding="4"><thead><tr>';
    foreach ($headers as $h) echo '<th>' . e($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($r as $c) echo '<td>' . e(is_float($c) ? number_format($c, 0, ',', '.') : (string)$c) . '</td>';
        echo '</tr>';
    }
    if ($totals) {
        echo '<tr>';
        foreach ($totals as $c) echo '<th>' . e(is_float($c) ? number_format($c, 0, ',', '.') : (string)$c) . '</th>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    audit('Export Excel', 'Laporan', null, null, ['type' => $type, 'rows' => count($rows)], 'Export data');
    exit;
}

/* ---------------- PDF (print view → Save as PDF) ---------------- */
if ($format === 'pdf') {
    audit('Export PDF', 'Laporan', null, null, ['type' => $type, 'rows' => count($rows)], 'Export data');
    ?>
    <!DOCTYPE html>
    <html lang="id"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
      body{background:#fff;padding:28px}
      .pdf-head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #C2185B;padding-bottom:12px;margin-bottom:18px;gap:16px;flex-wrap:wrap}
      .pdf-head h2{margin:0;color:#8E0E42}
      table.tbl th{background:#FDF2F7!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
      tfoot th{background:#FDF2F7}
      /* Dokumen ini sering dibuka langsung dari HP (tombol PDF di Riwayat Order),
         jadi tampilannya juga dirapikan untuk layar kecil — tabel lebar dapat
         digeser ke samping, bukan memaksa seluruh halaman melebar. */
      @media (max-width:900px){
        body{padding:14px}
        .pdf-head{flex-direction:column;align-items:flex-start}
        .pdf-head .small{text-align:left!important}
      }
      @media print{ .no-print{display:none!important} body{padding:0} @page{size:A4 landscape;margin:12mm} }
    </style></head>
    <body onload="window.print()">
    <div class="no-print" style="margin-bottom:14px"><button class="btn btn-primary" onclick="window.print()">Cetak / Simpan sebagai PDF</button>
      <a class="btn" href="javascript:window.close()">Tutup</a>
      <span class="muted" style="margin-left:10px">Pilih tujuan <strong>Save as PDF</strong> pada dialog cetak.</span></div>
    <div class="pdf-head">
      <div><?= brand_block() ?><h2 class="mt-1"><?= e($title) ?></h2><p class="muted"><?= $meta ?></p></div>
      <div class="small" style="text-align:right">Dicetak: <?= e(tglIndo(date('Y-m-d'))) ?> <?= date('H:i') ?><br>Oleh: <?= e($user['name']) ?> (<?= e($user['role_name']) ?>)</div>
    </div>
    <div class="table-wrap">
    <table class="tbl">
      <thead><tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= count($headers) ?>" class="center muted">Belum ada data.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr><?php foreach ($r as $c): ?><td><?= e(is_float($c) ? number_format($c, 0, ',', '.') : (string)$c) ?></td><?php endforeach; ?></tr>
      <?php endforeach; endif; ?>
      </tbody>
      <?php if ($totals): ?>
        <tfoot><tr><?php foreach ($totals as $c): ?><th><?= e(is_float($c) ? number_format($c, 0, ',', '.') : (string)$c) ?></th><?php endforeach; ?></tr></tfoot>
      <?php endif; ?>
    </table>
    </div>
    <?php if ($footnote !== ''): ?><div class="mt-3 small"><?= $footnote ?></div><?php endif; ?>
    <p class="small muted mt-3">Dokumen ini dihasilkan otomatis oleh <?= e(clinic_name()) ?> Management System.</p>
    </body></html>
    <?php
    exit;
}

http_response_code(400);
exit('Format export tidak dikenal.');
