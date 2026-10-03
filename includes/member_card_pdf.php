<?php
/**
 * GAMBAR KARTU MEMBER UNTUK PDF — satu tempat untuk DUA dokumen:
 *
 *   1) member_card.php?id=..&format=pdf        → 2 halaman UKURAN KARTU
 *      (halaman 1 sisi depan, halaman 2 sisi belakang).
 *   2) member_card.php?id=..&format=pdf_lengkap → 2 halaman A4:
 *      halaman 1 pratinjau KEDUA kartu seperti halaman "Kartu Member Digital",
 *      halaman 2 status member & aturan diskon yang berlaku.
 *
 * Semua ukuran diambil dari TATA LETAK PRATINJAU HTML (assets/css/app.css) yang
 * diukur langsung di peramban pada lebar kartu 490 px, lalu dikonversi ke titik
 * PDF ukuran kartu (85,6 × 54 mm = 242,6 × 153,1 pt). Faktor konversinya
 * `MCARD_K = 242,6 / 490`. Cara ini dipakai supaya hasil PDF benar-benar
 * "persis seperti pratinjau" — teks yang panjang MEMBUNGKUS seperti di layar
 * (bukan dipotong dengan "…" seperti versi lama), tulisan MEMBER di pita kiri
 * tersebar rata, dan tidak ada lagi baris tambahan yang tidak ada di pratinjau.
 */
declare(strict_types=1);

if (!defined('MCARD_W')) define('MCARD_W', 242.6);   // 85,6 mm
if (!defined('MCARD_H')) define('MCARD_H', 153.1);   // 54 mm
/** Lebar kartu saat pratinjau HTML diukur (px) → dasar konversi ke titik PDF. */
if (!defined('MCARD_PREVIEW_PX')) define('MCARD_PREVIEW_PX', 490.0);

/** Faktor px pratinjau → pt kartu. */
function mcard_k(): float
{
    return MCARD_W / MCARD_PREVIEW_PX;
}

/** Campur dua warna "#RRGGBB" (t = 0 → $a, t = 1 → $b). */
function mcard_mix(string $a, string $b, float $t): string
{
    $p = function (string $h): array {
        $h = ltrim($h, '#');
        if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    };
    [$r1, $g1, $b1] = $p($a);
    [$r2, $g2, $b2] = $p($b);
    $t = max(0.0, min(1.0, $t));
    return sprintf('#%02X%02X%02X',
        (int)round($r1 + ($r2 - $r1) * $t),
        (int)round($g1 + ($g2 - $g1) * $t),
        (int)round($b1 + ($b2 - $b1) * $t));
}

/**
 * Pecah teks menjadi baris yang muat pada lebar $maxW.
 * Seperti `overflow-wrap:anywhere` di CSS: kata yang lebih panjang dari satu
 * baris dipotong per karakter (bukan dibiarkan meluber keluar kartu).
 *
 * @return string[]
 */
function mcard_wrap(MiniPdf $pdf, string $text, float $maxW, float $size, bool $bold = false): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') return [];
    $lines = [];
    $cur = '';
    foreach (explode(' ', $text) as $word) {
        /* Kata tunggal yang tidak muat → potong per karakter. */
        while ($pdf->textWidth($word, $size, $bold) > $maxW && $word !== '') {
            $cut = max(1, (int)floor(strlen($word) * ($maxW / max(0.01, $pdf->textWidth($word, $size, $bold)))));
            $chunk = substr($word, 0, $cut);
            while ($chunk !== '' && $pdf->textWidth($chunk, $size, $bold) > $maxW) {
                $chunk = substr($chunk, 0, -1);
            }
            if ($chunk === '') break;
            if ($cur !== '') { $lines[] = $cur; $cur = ''; }
            $lines[] = $chunk;
            $word = substr($word, strlen($chunk));
        }
        if ($word === '') continue;
        $try = $cur === '' ? $word : $cur . ' ' . $word;
        if ($pdf->textWidth($try, $size, $bold) <= $maxW || $cur === '') {
            $cur = $try;
        } else {
            $lines[] = $cur;
            $cur = $word;
        }
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
}

/**
 * Siapkan satu blok teks: mengecilkan huruf bila jumlah barisnya melebihi
 * $maxLines (mis. nama panjang atau nama cabang yang panjang).
 *
 * @return array{lines:string[],size:float,lineH:float}
 */
function mcard_block(MiniPdf $pdf, string $text, float $maxW, float $size, int $maxLines = 1,
                    float $lhFactor = 1.55, float $minSize = 3.4, bool $bold = false): array
{
    $s = $size;
    $lines = [];
    for ($i = 0; $i < 24; $i++) {
        $lines = mcard_wrap($pdf, $text, $maxW, $s, $bold);
        if (count($lines) <= $maxLines || $s <= $minSize) break;
        $s = round(max($minSize, $s - 0.12), 3);
    }
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $last = array_pop($lines);
        $lines[] = rtrim((string)$last) . '...';
    }
    return ['lines' => $lines, 'size' => $s, 'lineH' => $s * $lhFactor];
}

/** Tinggi blok teks (titik) dari hasil mcard_block(). */
function mcard_block_h(array $b): float
{
    return count($b['lines']) * $b['lineH'];
}

/**
 * Gambar blok teks pada koordinat absolut ($yTop = batas ATAS kotak teks, sama
 * seperti kotak elemen di halaman pratinjau).
 * Mengembalikan garis dasar baris terakhir.
 */
function mcard_put(MiniPdf $pdf, float $x, float $yTop, array $b, bool $bold = false, string $hex = ''): float
{
    /* Huruf diletakkan di TENGAH kotak barisnya (setengah leading di atas),
       sebagaimana peramban menata teks. Tanpa ini huruf menempel ke tepi atas
       kotak sehingga isi kartu di PDF bergeser beberapa titik dari pratinjau. */
    $lead = ($b['lineH'] - $b['size']) / 2;
    $y = $yTop - $lead - 0.79 * $b['size'];
    foreach ($b['lines'] as $i => $ln) {
        $pdf->textAt($x, $y - $i * $b['lineH'], $ln, $b['size'], $bold, $hex);
    }
    return $y - (count($b['lines']) - 1) * $b['lineH'];
}

/**
 * Sisi DEPAN kartu.
 *
 * @param array $c konteks: p, level, theme, company, logo, hasBg, border
 */
function mcard_front(MiniPdf $pdf, float $ox, float $oy, array $c): void
{
    $W = MCARD_W; $H = MCARD_H; $k = mcard_k();
    $hasBg = !empty($c['hasBg']);
    $brand = (string)($c['theme']['brand'] ?? '#C2185B');
    $dark  = (string)($c['theme']['brandDark'] ?? '#8E0E42');

    $stripeW = 0.25 * $W;
    $bodyW   = $W - $stripeW;
    /* Padding CSS memakai persen terhadap lebar kotak induk: tanpa background
       induknya kolom isi (75% lebar kartu), dengan background seluruh kartu. */
    $containing = $hasBg ? $W : $bodyW;
    $padTopBot  = 0.06 * $containing + 0.5;
    $padL       = ($hasBg ? 0.06 : 0.05) * $containing + 0.5;
    $padR       = 0.055 * $containing + 0.5;
    $contentX   = $ox + ($hasBg ? 0 : $stripeW) + $padL;
    $contentW   = ($hasBg ? $W : $bodyW) - $padL - $padR;
    $contentTop = $oy + $H - $padTopBot;
    $contentBot = $oy + $padTopBot;

    /* ---- Pita kiri: gradasi warna + tulisan MEMBER vertikal ---- */
    if (!$hasBg) {
        $steps = 48;
        for ($i = 0; $i < $steps; $i++) {
            $t = $i / max(1, $steps - 1);
            $pdf->fillRect($ox, $oy + $H - ($i + 1) * ($H / $steps), $stripeW, $H / $steps + 0.4,
                mcard_mix($dark, $brand, $t));
        }
        $letters = ['M', 'E', 'M', 'B', 'E', 'R'];
        $sizeTag = 19.2 * $k;                       // 9,51 pt
        $padTag  = 0.07 * $stripeW;                 // CSS: padding 7% dari lebar pita
        $topTag  = $oy + $H - $padTag;
        $botTag  = $oy + $padTag;
        $gapTag  = (($topTag - $botTag) - count($letters) * $sizeTag) / max(1, count($letters) - 1);
        $gapTag  = max($gapTag, 0.5);
        foreach ($letters as $i => $ch) {
            $boxTop = $topTag - $i * ($sizeTag + $gapTag);
            $w = $pdf->textWidth($ch, $sizeTag, true);
            $pdf->textAt($ox + ($stripeW - $w) / 2, $boxTop - 0.79 * $sizeTag, $ch, $sizeTag, true, '#FFFFFF');
        }
    }

    /* ---- Susunan isi: kepala (logo + garis), identitas, rincian ---- */
    $build = function (float $scale) use ($pdf, $c, $contentW, $k): array {
        $logoPath = (string)($c['logo'] ?? '');
        $logoMaxH = 32 * $k * $scale;               // CSS: max-height 32 px (clamp)
        $logoMaxW = 0.62 * $contentW;
        $logoSize = ['w' => 0.0, 'h' => 0.0];
        if ($logoPath !== '') $logoSize = $pdf->imagePngSize($logoPath, $logoMaxW, $logoMaxH);
        $coBlock = ['lines' => [], 'size' => 0.0, 'lineH' => 0.0];
        if ($logoSize['w'] <= 0) {
            $coBlock = mcard_block($pdf, (string)$c['company'], $contentW, 15.56 * $k * $scale, 2, 1.2, 4.0, true);
        }
        $gapLine = 14 * $k;                          // CSS: gap(clamp) 14 px
        $lineH   = max(1.0, 2 * $k);
        $g1 = ($logoSize['w'] > 0 ? $logoSize['h'] : mcard_block_h($coBlock)) + $gapLine + $lineH;

        $name  = mcard_block($pdf, (string)$c['p']['name'], $contentW, 18.88 * $k * $scale, 2, 1.15, 5.0, true);
        $level = mcard_block($pdf, 'Member Card - ' . (string)$c['level']['label'], $contentW,
                             12.48 * $k * $scale, 1, 1.55, 3.6, true);
        $gapId = 0.6;
        $g2 = mcard_block_h($name) + $gapId + mcard_block_h($level);

        /* Rincian 2 × 2: label (huruf besar) di atas nilainya. */
        $labelSize = 9.92 * $k * $scale;
        $valueSize = 13.44 * $k * $scale;
        $colGap = 12 * $k;                           // CSS: column-gap 12 px
        $colW = ($contentW - $colGap) / 2;
        $items = [
            ['No. Member', (string)$c['p']['member_number']],
            ['No. Pasien', (string)$c['p']['patient_number']],
            ['Member Sejak', tglIndo((string)$c['since'])],
            ['Cabang', (string)$c['p']['branch_name']],
        ];
        $cells = [];
        $rowHs = [0.0, 0.0];
        foreach ($items as $i => $it) {
            $lab = mcard_block($pdf, strtoupper($it[0]), $colW, $labelSize, 1, 1.55, 3.2, false);
            $val = mcard_block($pdf, $it[1], $colW, $valueSize, 2, 1.55, 3.6, true);
            $cells[$i] = ['label' => $lab, 'value' => $val];
            $h = mcard_block_h($lab) + mcard_block_h($val);
            /* baris = pasangan (item 0,1) lalu (item 2,3) — BUKAN i % 2. */
            $rowHs[intdiv($i, 2)] = max($rowHs[intdiv($i, 2)], $h);
        }
        $rowGap = 1.4;
        $g3 = $rowHs[0] + $rowGap + $rowHs[1];

        return ['g1' => $g1, 'g2' => $g2, 'g3' => $g3, 'logoSize' => $logoSize, 'co' => $coBlock,
            'name' => $name, 'level' => $level, 'cells' => $cells, 'rowHs' => $rowHs, 'rowGap' => $rowGap,
            'gapLine' => $gapLine, 'lineH' => $lineH, 'gapId' => $gapId, 'colW' => $colW, 'colGap' => $colGap,
            'logoMaxH' => $logoMaxH];
    };

    $b = $build(1.0);
    $contentH = $contentTop - $contentBot;
    $total = $b['g1'] + $b['g2'] + $b['g3'];
    $free = $contentH - $total;
    if ($free < 6.0) {                               // teks panjang → kecilkan sedikit
        $scale = max(0.72, ($contentH - 6.0) / max(0.01, $total));
        $b = $build($scale);
        $total = $b['g1'] + $b['g2'] + $b['g3'];
        $free = max(6.0, $contentH - $total);
    }
    $gapGrp = $free / 2;

    /* Kepala */
    $headTop = $contentTop;
    if ($b['logoSize']['w'] > 0 && $hasBg) {
        /* Pratinjau menaruh logo di atas lempeng putih bila kartu memakai gambar latar. */
        $plateL = 4.5; $plateR = 4.5; $plateV = 2.2;
        $pdf->fillRect($contentX - $plateL, $headTop - $b['logoSize']['h'] - $plateV,
            $b['logoSize']['w'] + $plateL + $plateR, $b['logoSize']['h'] + 2 * $plateV, '#FFFFFF');
    }
    if ($b['logoSize']['w'] > 0) {
        $pdf->imagePngAt((string)$c['logo'], $contentX, $headTop, $b['logoSize']['w'] + 0.01, $b['logoSize']['h'] + 0.01);
    } elseif ($b['co']['lines']) {
        mcard_put($pdf, $contentX, $headTop, $b['co'], true, '#000000');
    }
    $lineTop = $headTop - ($b['logoSize']['w'] > 0 ? $b['logoSize']['h'] : mcard_block_h($b['co'])) - $b['gapLine'];
    $pdf->fillRect($contentX, $lineTop - $b['lineH'], $b['colW'] * 2 + $b['colGap'], $b['lineH'], $brand);

    /* Identitas */
    $idTop = $headTop - $b['g1'] - $gapGrp;
    mcard_put($pdf, $contentX, $idTop, $b['name'], true, '#000000');
    mcard_put($pdf, $contentX, $idTop - mcard_block_h($b['name']) - $b['gapId'], $b['level'], true, '#000000');

    /* Rincian */
    $row2Top = $contentBot + $b['rowHs'][1];
    $row1Top = $row2Top + $b['rowHs'][0] + $b['rowGap'];
    foreach ([0 => $row1Top, 1 => $row2Top] as $rowIdx => $rTop) {
        foreach ([0, 1] as $col) {
            $i = $rowIdx * 2 + $col;
            if (!isset($b['cells'][$i])) continue;
            $x = $contentX + $col * ($b['colW'] + $b['colGap']);
            mcard_put($pdf, $x, $rTop, $b['cells'][$i]['label'], false, '#444444');
            mcard_put($pdf, $x, $rTop - mcard_block_h($b['cells'][$i]['label']), $b['cells'][$i]['value'],
                true, '#000000');
        }
    }

    if (!empty($c['border'])) {
        $pdf->lineAt($ox, $oy, $ox + $W, $oy, 0.82);
        $pdf->lineAt($ox, $oy + $H, $ox + $W, $oy + $H, 0.82);
        $pdf->lineAt($ox, $oy, $ox, $oy + $H, 0.82);
        $pdf->lineAt($ox + $W, $oy, $ox + $W, $oy + $H, 0.82);
    }
}

/**
 * Sisi BELAKANG kartu (keuntungan level & catatan) — mengikuti pratinjau:
 * judul, garis, daftar level, garis, keterangan, catatan klinik.
 *
 * @param array $c konteks: level, levels, theme, company, note, phone, bg, hasBg, border
 */
function mcard_back(MiniPdf $pdf, float $ox, float $oy, array $c): void
{
    $W = MCARD_W; $H = MCARD_H; $k = mcard_k();
    $brand = (string)($c['theme']['brand'] ?? '#C2185B');
    $padTopBot = 0.05 * $W + 0.5;
    $padSide   = 0.06 * $W + 0.5;
    $x = $ox + $padSide;
    $cw = $W - 2 * $padSide;
    $top = $oy + $H - $padTopBot;
    $bot = $oy + $padTopBot;

    $title = mcard_block($pdf, 'KEUNTUNGAN LEVEL MEMBER', $cw, 14.4 * $k, 1, 1.55, 4.2, true);
    $gap = 2.55;
    $lineH = max(1.0, 2 * $k);

    $y = $top;
    mcard_put($pdf, $x, $y, $title, true, '#000000');
    $y -= mcard_block_h($title) + $gap;
    $pdf->fillRect($x, $y - $lineH, $cw, $lineH, $brand);
    $y -= $lineH + $gap;

    /* Daftar level: yang berlaku ditandai bintang (seperti pratinjau). */
    $benSize = 12.16 * $k;
    foreach ((array)$c['levels'] as $l) {
        $on = (string)$l['key'] === (string)$c['level']['key'];
        $txt = ($on ? '* ' : '') . (string)$l['label'] . ' ~ Diskon '
            . num((float)$l['pct'], (float)$l['pct'] == (int)$l['pct'] ? 0 : 1) . '%';
        $blk = mcard_block($pdf, $txt, $cw, $benSize, 1, 1.30, 3.4, $on);
        if ($y - mcard_block_h($blk) < $bot + 30) break;      // sisakan ruang keterangan
        mcard_put($pdf, $x, $y, $blk, $on, '#000000');
        $y -= mcard_block_h($blk) + 0.39;
    }
    $y += 0.39;
    $pdf->fillRect($x, $y - $lineH, $cw, $lineH, $brand);
    $y -= $lineH + $gap;

    $noteTxt = 'Benefit member aktif: setiap transaksi minimal ' . money(member_min_transaction())
        . ' mendapat diskon otomatis sesuai level kartu (' . member_scope_text() . ').';
    $blk = mcard_block($pdf, $noteTxt, $cw, 10.88 * $k, 8, 1.35, 3.4, false);
    mcard_put($pdf, $x, $y, $blk, false, '#333333');
    $y -= mcard_block_h($blk) + 2.0;

    $note = trim((string)($c['note'] ?? ''));
    if ($note !== '' && $y > $bot + 6) {
        $blk = mcard_block($pdf, $note, $cw, 10.88 * $k, 6, 1.35, 3.4, false);
        if ($y - mcard_block_h($blk) < $bot) {
            $blk = mcard_block($pdf, $note, $cw, 10.88 * $k, max(1, (int)floor(($y - $bot) / (10.88 * $k * 1.35))),
                1.35, 3.4, false);
        }
        mcard_put($pdf, $x, $y, $blk, false, '#444444');
    }

    if (!empty($c['border'])) {
        $pdf->lineAt($ox, $oy, $ox + $W, $oy, 0.82);
        $pdf->lineAt($ox, $oy + $H, $ox + $W, $oy + $H, 0.82);
        $pdf->lineAt($ox, $oy, $ox, $oy + $H, 0.82);
        $pdf->lineAt($ox + $W, $oy, $ox + $W, $oy + $H, 0.82);
    }
}
