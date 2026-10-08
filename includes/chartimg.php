<?php
/**
 * Pembuat gambar grafik di sisi server (memakai GD).
 *
 * Dipakai untuk "Laporan Lengkap + Grafik" dalam bentuk Excel dan PDF, serta
 * lampiran email — karena grafik browser (Chart.js) tidak bisa ikut ke dalam
 * berkas yang dibuat server atau dikirim lewat email.
 *
 * Bila GD tidak tersedia, fungsi mengembalikan null dan dokumen tetap dibuat
 * tanpa grafik (dengan keterangan, bukan gagal diam-diam).
 */
declare(strict_types=1);

function chart_available(): bool
{
    return extension_loaded('gd') && function_exists('imagecreatetruecolor') && function_exists('imagepng');
}

/** Ukuran kanvas grafik (px). 2x untuk ketajaman saat dicetak. */
function chart_canvas(string $title, int $w = 900, int $h = 380): array
{
    $im = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefilledrectangle($im, 0, 0, $w, $h, $white);
    $t = theme_current();
    $colors = [
        'bg' => [255, 255, 255],
        'border' => [226, 226, 232],
        'text' => [43, 27, 39],
        'muted' => [107, 90, 101],
        'brand' => chart_hex2rgb($t['brand']),
        'brandMid' => chart_hex2rgb($t['brandMid']),
        'accent' => chart_hex2rgb($t['accent']),
        'accentDark' => chart_hex2rgb($t['accentDark']),
        'grid' => [238, 238, 242],
    ];
    if ($title !== '') {
        $tcol = imagecolorallocate($im, $colors['text'][0], $colors['text'][1], $colors['text'][2]);
        imagestring($im, 5, 18, 12, $title, $tcol);
    }
    return [$im, $colors];
}

function chart_hex2rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}
function chart_alloc($im, array $rgb): int
{
    return imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
}

/** Format ringkas angka rupiah untuk label sumbu (mis. 1,2 jt). */
function chart_money_short(float $v): string
{
    $a = abs($v);
    if ($a >= 1e9) return num($v / 1e9, 1) . ' M';
    if ($a >= 1e6) return num($v / 1e6, 1) . ' jt';
    if ($a >= 1e3) return num($v / 1e3, 0) . ' rb';
    return num($v, 0);
}

function chart_finish($im): string
{
    ob_start();
    imagepng($im, null, 8);
    $bytes = (string)ob_get_clean();
    imagedestroy($im);
    return $bytes;
}

/**
 * Grafik batang bertumpuk (Treatment + Skincare) dengan garis total.
 *
 * @param array $d ['labels'=>[], 'series'=>[['label'=>..,'data'=>[..],'color'=>..],..], 'line'=>['label'=>..,'data'=>[..]]]
 */
function chart_bar(array $d, string $title = '', int $w = 900, int $h = 380): ?string
{
    if (!chart_available()) return null;
    [$im, $c] = chart_canvas($title, $w, $h);
    $labels = $d['labels'] ?? [];
    $series = $d['series'] ?? [];
    $line = $d['line'] ?? null;
    if (!$labels) { imagestring($im, 3, 30, (int)($h / 2), 'Belum ada data', chart_alloc($im, $c['muted'])); return chart_finish($im); }

    $padL = 86; $padR = 24; $padT = 52; $padB = 64;
    $plotW = $w - $padL - $padR;
    $plotH = $h - $padT - $padB;

    // hitung nilai maksimum (termasuk garis total)
    $max = 0.0;
    foreach ($series as $s) foreach ($s['data'] as $v) $max = max($max, (float)$v);
    if ($line) foreach ($line['data'] as $v) $max = max($max, (float)$v);
    if ($max <= 0) $max = 1;
    $steps = 4;
    $max = ceil($max / $steps) * $steps;

    // sumbu & grid
    $grid = chart_alloc($im, $c['grid']);
    $muted = chart_alloc($im, $c['muted']);
    for ($i = 0; $i <= $steps; $i++) {
        $y = $padT + $plotH - (int)round($plotH * $i / $steps);
        imageline($im, $padL, $y, $w - $padR, $y, $grid);
        imagestring($im, 2, 6, $y - 6, chart_money_short($max * $i / $steps), $muted);
    }
    imageline($im, $padL, $padT + $plotH, $w - $padR, $padT + $plotH, chart_alloc($im, $c['border']));

    $n = count($labels);
    $slot = $plotW / max(1, $n);
    $barW = max(2, (int)($slot * 0.46));
    $baseY = $padT + $plotH;

    /* Batang BERJAJARAN (bukan bertumpuk) supaya nilai Treatment & Skincare
       masing-masing terbaca, dan garis total tidak melompat keluar area. */
    $nSer = max(1, count($series));
    $groupW = (int)($slot * 0.78);
    $barW = max(2, (int)($groupW / $nSer) - 2);
    for ($i = 0; $i < $n; $i++) {
        $cx = (int)round($padL + $slot * ($i + 0.5));
        $x0 = (int)round($cx - $groupW / 2);
        foreach (array_values($series) as $k => $s) {
            $v = (float)($s['data'][$i] ?? 0);
            $col = chart_alloc($im, $s['color'] ?? $c['brand']);
            $bx = $x0 + $k * ($barW + 2);
            if ($v <= 0) continue;                 // batang nol tidak digambar
            $bh = (int)round($plotH * $v / $max);
            imagefilledrectangle($im, $bx, $baseY - $bh, $bx + $barW, $baseY, $col);
        }
        // label sumbu x (maks 12 label agar tidak bertumpuk)
        if ($n <= 12 || $i % (int)ceil($n / 12) === 0) {
            $lbl = (string)$labels[$i];
            $tw = imagefontwidth(2) * strlen($lbl);
            imagestring($im, 2, max(0, $cx - intdiv($tw, 2)), $baseY + 6, $lbl, $muted);
        }
    }

    // garis total
    if ($line) {
        /* Warna garis Total mengikuti Pengaturan → Warna Grafik (dulu coklat
           tetap #6D4C41). */
        $cols = chart_alloc($im, chart_hex2rgb(chart_series_color('total')));
        $prev = null;
        for ($i = 0; $i < $n; $i++) {
            $v = (float)($line['data'][$i] ?? 0);
            $x = (int)round($padL + $slot * ($i + 0.5));
            $y = $baseY - (int)round($plotH * $v / $max);
            if ($prev) imageline($im, $prev[0], $prev[1], $x, $y, $cols);
            imagefilledellipse($im, $x, $y, 7, 7, $cols);
            $prev = [$x, $y];
        }
    }

    // legenda
    $lx = $padL; $ly = $h - 24;
    $legend = [];
    foreach ($series as $s) $legend[] = [$s['label'] ?? '', $s['color'] ?? $c['brand']];
    if ($line) $legend[] = [$line['label'] ?? 'Total', chart_hex2rgb(chart_series_color('total'))];
    foreach ($legend as [$lab, $rgb]) {
        imagefilledrectangle($im, $lx, $ly - 2, $lx + 14, $ly + 8, chart_alloc($im, $rgb));
        imagestring($im, 2, $lx + 20, $ly - 3, (string)$lab, $muted);
        $lx += 26 + imagefontwidth(2) * strlen((string)$lab) + 18;
        if ($lx > $w - 120) { $lx = $padL; $ly += 14; }
    }
    return chart_finish($im);
}

/** Grafik lingkaran (donut) dengan legenda persentase. */
function chart_pie(array $rows, string $title = '', int $w = 900, int $h = 380): ?string
{
    if (!chart_available()) return null;
    [$im, $c] = chart_canvas($title, $w, $h);
    $muted = chart_alloc($im, $c['muted']);
    $rows = array_values(array_filter($rows, fn($r) => (float)$r['value'] > 0));
    if (!$rows) { imagestring($im, 3, 30, (int)($h / 2), 'Belum ada data', $muted); return chart_finish($im); }

    $total = array_sum(array_map(fn($r) => (float)$r['value'], $rows)) ?: 1;
    $cx = (int)($w * 0.30); $cy = (int)($h / 2) + 10;
    $rad = (int)(min($w * 0.26, $h * 0.36));
    /* Satu warna per baris (bukan `idx % 10`) supaya dua juring tidak pernah
       memakai warna yang sama walau cabang/metodenya lebih dari 10. */
    $palette = [];
    foreach (chart_colors(count($rows)) as $hex) $palette[] = chart_hex2rgb($hex);

    $start = -90.0;
    $idx = 0;
    foreach ($rows as $r) {
        $share = (float)$r['value'] / $total;
        $end = $start + $share * 360.0;
        $col = chart_alloc($im, $palette[$idx % count($palette)]);   // aman: 1 warna/baris
        // isi juring
        $steps = max(2, (int)ceil(($end - $start) / 2));
        for ($s = 0; $s < $steps; $s++) {
            $a1 = deg2rad($start + ($end - $start) * $s / $steps);
            $a2 = deg2rad($start + ($end - $start) * ($s + 1) / $steps + 0.6);
            $pts = [$cx, $cy];
            for ($a = $a1; $a <= $a2; $a += 0.05) {
                $pts[] = (int)round($cx + cos($a) * $rad);
                $pts[] = (int)round($cy + sin($a) * $rad);
            }
            $pts[] = (int)round($cx + cos($a2) * $rad);
            $pts[] = (int)round($cy + sin($a2) * $rad);
            if (count($pts) >= 6) imagefilledpolygon($im, $pts, $col);
        }
        $start = $end;
        $idx++;
    }
    // lubang donut
    imagefilledellipse($im, $cx, $cy, (int)($rad * 1.1), (int)($rad * 1.1), chart_alloc($im, [255, 255, 255]));

    // legenda di kanan
    $lx = (int)($w * 0.56); $ly = 62;
    $idx = 0;
    foreach ($rows as $r) {
        $share = (float)$r['value'] / $total * 100;
        imagefilledrectangle($im, $lx, $ly, $lx + 14, $ly + 12, chart_alloc($im, $palette[$idx % count($palette)]));
        $nm = (string)$r['label'];
        if (strlen($nm) > 34) $nm = substr($nm, 0, 33) . '…';
        imagestring($im, 3, $lx + 22, $ly - 1, $nm, chart_alloc($im, $c['text']));
        imagestring($im, 2, $lx + 22, $ly + 15, num($r['value'], 0) . '  (' . num($share, 1) . '%)', $muted);
        $ly += 34;
        $idx++;
        if ($ly > $h - 30) break;
    }
    return chart_finish($im);
}

/** Grafik garis sederhana (mis. tren pertumbuhan). */
function chart_line(array $d, string $title = '', int $w = 900, int $h = 340, bool $percent = false): ?string
{
    if (!chart_available()) return null;
    [$im, $c] = chart_canvas($title, $w, $h);
    $labels = $d['labels'] ?? [];
    $data = $d['data'] ?? [];
    $muted = chart_alloc($im, $c['muted']);
    if (!$labels) { imagestring($im, 3, 30, (int)($h / 2), 'Belum ada data', $muted); return chart_finish($im); }

    $padL = 70; $padR = 22; $padT = 50; $padB = 56;
    $plotW = $w - $padL - $padR; $plotH = $h - $padT - $padB;
    $max = 0.0; $min = 0.0;
    foreach ($data as $v) { $max = max($max, (float)$v); $min = min($min, (float)$v); }
    if ($max <= 0 && $min >= 0) $max = 1;
    if ($min < 0) { $range = $max - $min; } else { $range = $max; }
    if ($range <= 0) $range = 1;
    $max = $max + $range * 0.12;

    $grid = chart_alloc($im, $c['grid']);
    $steps = 4;
    for ($i = 0; $i <= $steps; $i++) {
        $y = $padT + $plotH - (int)round($plotH * $i / $steps);
        imageline($im, $padL, $y, $w - $padR, $y, $grid);
        $val = ($min < 0 ? $min : 0) + ($max - ($min < 0 ? $min : 0)) * $i / $steps;
        $txt = $percent ? num($val, 0) . '%' : chart_money_short($val);
        imagestring($im, 2, 6, $y - 6, $txt, $muted);
    }
    $zeroY = $padT + $plotH - (int)round($plotH * (0 - ($min < 0 ? $min : 0)) / ($max - ($min < 0 ? $min : 0)));
    imageline($im, $padL, $zeroY, $w - $padR, $zeroY, chart_alloc($im, $c['border']));

    $n = count($labels);
    $slot = $plotW / max(1, $n);
    $brand = chart_alloc($im, $c['brand']);
    $prev = null;
    for ($i = 0; $i < $n; $i++) {
        $v = (float)($data[$i] ?? 0);
        $x = (int)round($padL + $slot * ($i + 0.5));
        $base = ($min < 0 ? $min : 0);
        $y = $zeroY - (int)round($plotH * ($v - $base) / ($max - $base));
        if ($prev) imageline($im, $prev[0], $prev[1], $x, $y, $brand);
        imagefilledellipse($im, $x, $y, 8, 8, $brand);
        if ($n <= 14 || $i % (int)ceil($n / 14) === 0) {
            $lbl = (string)$labels[$i];
            $tw = imagefontwidth(2) * strlen($lbl);
            imagestring($im, 2, max(0, $x - intdiv($tw, 2)), $padT + $plotH + 8, $lbl, $muted);
        }
        $prev = [$x, $y];
    }
    return chart_finish($im);
}

/**
 * Kumpulan grafik laporan (dipakai Excel & PDF & email).
 * @return array<string,array{title:string,png:string}> nama => grafik
 */
function report_charts(array $B): array
{
    if (!chart_available()) return [];
    $f = $B['filters'];
    $tot = $B['totals'];
    $monthly = $B['monthly'];
    $out = [];
    $t = theme_current();

    /* Progres: DUA gambar — (1) batang berjajaran Treatment vs Skincare dan
       (2) Total Pendapatan dengan sumbunya sendiri. Sebelumnya keduanya
       digabung dalam satu gambar: garis Total menempel di puncak batang
       (seolah hilang) dan nilai skincare nyaris tak terlihat karena sumbunya
       ikut dibatasi nilai total. Susunan ini sama dengan grafik di layar. */
    $png = chart_bar([
        'labels' => $monthly['labels'],
        'series' => [
            ['label' => 'Treatment', 'data' => $monthly['tr'], 'color' => chart_hex2rgb(chart_series_color('treatment'))],
            ['label' => 'Skincare', 'data' => $monthly['sk'], 'color' => chart_hex2rgb(chart_series_color('skincare'))],
        ],
    ], 'Progres ' . ($monthly['granularity'] === 'harian' ? 'Harian' : 'Bulanan') . ' — Treatment vs Skincare ('
       . $f['ps'] . ' s.d. ' . $f['pe'] . ')');
    if ($png) $out['progres'] = ['title' => 'Progres Pendapatan — Treatment vs Skincare', 'png' => $png];

    $png = chart_line(['labels' => $monthly['labels'], 'data' => $monthly['total']],
        'Progres ' . ($monthly['granularity'] === 'harian' ? 'Harian' : 'Bulanan') . ' — Total Pendapatan');
    if ($png) $out['progres_total'] = ['title' => 'Progres Pendapatan — Total', 'png' => $png];

    /* Grafik "Asal Total Pendapatan": komposisi angka total (treatment, skincare,
       paket, diskon manual, diskon member, kode unik) — supaya keterangan rumusnya
       JUGA terlihat pada berkas gambar (Excel/PDF/email), bukan hanya di layar. */
    /* Fungsi rincian berasal dari reports.php; penjagaan ini menghindari fatal error
       bila suatu saat berkas ini dipakai tanpa memuat reports.php lebih dulu. */
    $inc = $B['income'] ?? null;
    if ($inc === null && $f && function_exists('report_income_breakdown')) {
        $inc = report_income_breakdown($f);
    }
    if ($inc) {
        $komp = [['label' => 'Treatment', 'value' => (float)$inc['tr']],
                 ['label' => 'Skincare', 'value' => (float)$inc['sk']]];
        if ((float)$inc['pkg'] > 0) $komp[] = ['label' => 'Paket', 'value' => (float)$inc['pkg']];
        if ((float)$inc['unique'] > 0) $komp[] = ['label' => 'Kode unik', 'value' => (float)$inc['unique']];
        $png = chart_bar([
            'labels' => array_column($komp, 'label'),
            'series' => [['label' => 'Pendapatan', 'data' => array_column($komp, 'value')]],
        ], 'Asal Total Pendapatan: treatment + skincare + paket - diskon - diskon member + kode unik');
        if ($png) $out['income_breakdown'] = ['title' => 'Asal Total Pendapatan', 'png' => $png];
    }

    if (!empty($monthly['branches']) && count($monthly['branches']) > 1) {
        $series = [];
        foreach ($monthly['branches'] as $name => $data) {
            $series[] = ['label' => branch_short_label((string)$name), 'data' => $data];
        }
        /* Warna tiap cabang diambil dari palet kategorikal kontras tinggi —
           dulu memakai palet tema (5 warna pertama satu keluarga) sehingga
           garis cabang ke-1..ke-3 nyaris sama. */
        $pal = array_map('chart_hex2rgb', chart_colors(count($series)));
        foreach ($series as $i => $s) $series[$i]['color'] = $pal[$i];
        $png = chart_bar(['labels' => $monthly['labels'], 'series' => $series],
            'Perbandingan Progres Antar Cabang');
        if ($png) $out['cabang_progres'] = ['title' => 'Progres Per Cabang', 'png' => $png];
    }

    $png = chart_pie([
        ['label' => 'Pendapatan Treatment', 'value' => $tot['tr']],
        ['label' => 'Penjualan Skincare', 'value' => $tot['sk']],
    ], 'Komposisi Pendapatan');
    if ($png) $out['komposisi'] = ['title' => 'Komposisi Pendapatan', 'png' => $png];

    if (!empty($B['branches'])) {
        $png = chart_pie(array_map(fn($b) => [
            'label' => branch_short_label((string)$b['name']),
            'value' => (float)$b['total'],
        ], $B['branches']), 'Kontribusi Pendapatan per Cabang');
        if ($png) $out['kontribusi_cabang'] = ['title' => 'Kontribusi Cabang', 'png' => $png];

        $png = chart_bar([
            'labels' => array_map(fn($b) => branch_short_label((string)$b['name']), $B['branches']),
            'series' => [
                ['label' => 'Treatment', 'data' => array_map(fn($b) => (float)$b['tr'], $B['branches']), 'color' => chart_hex2rgb(chart_series_color('treatment'))],
                ['label' => 'Skincare', 'data' => array_map(fn($b) => (float)$b['sk'], $B['branches']), 'color' => chart_hex2rgb(chart_series_color('skincare'))],
            ],
        ], 'Perbandingan Treatment vs Skincare per Cabang');
        if ($png) $out['cabang_komposisi'] = ['title' => 'Perbandingan Cabang', 'png' => $png];
    }

    if (!empty($B['methods'])) {
        $png = chart_pie(array_map(fn($m) => ['label' => (string)$m['method'], 'value' => (float)$m['total']], $B['methods']),
            'Metode Pembayaran');
        if ($png) $out['metode'] = ['title' => 'Metode Pembayaran', 'png' => $png];
    }

    /* CATATAN (permintaan pemilik): pasangan grafik "Pergerakan Periode" DIHAPUS karena
       pada periode panjang ia memakai ringkasan bulanan — sama persis dengan grafik
       "Progres" di atas. Kini hanya ada SATU pasang grafik periode (progres), baik di
       layar, dokumen cetak, Excel, PDF, maupun lampiran email. */

    $topTr = array_slice($B['treatments'] ?? [], 0, 5);
    if ($topTr) {
        $png = chart_pie(array_map(fn($r) => ['label' => (string)$r['nama'], 'value' => (float)$r['s']], $topTr),
            'Top 5 Penjualan Treatment');
        if ($png) $out['top_treatment'] = ['title' => 'Top 5 Treatment', 'png' => $png];
    }
    $topSk = array_slice($B['skincares'] ?? [], 0, 5);
    if ($topSk) {
        $png = chart_pie(array_map(fn($r) => ['label' => (string)$r['nama'], 'value' => (float)$r['s']], $topSk),
            'Top 5 Penjualan Skincare');
        if ($png) $out['top_skincare'] = ['title' => 'Top 5 Skincare', 'png' => $png];
    }

    if (!empty($B['cashiers'])) {
        $png = chart_bar([
            'labels' => array_map(fn($c) => (string)$c['nama'], $B['cashiers']),
            'series' => [
                ['label' => 'Pendapatan', 'data' => array_map(fn($c) => (float)$c['total'], $B['cashiers']), 'color' => chart_hex2rgb($t['brand'])],
            ],
        ], 'Kinerja Kasir (Pendapatan)');
        if ($png) $out['kasir'] = ['title' => 'Kinerja Kasir', 'png' => $png];
    }
    return $out;
}
