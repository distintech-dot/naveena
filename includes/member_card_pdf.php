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

/**
 * BARIS PASIEN YANG LENGKAP (dengan data cabang) untuk dokumen kartu.
 *
 * JEBAKAN YANG SUDAH DIPERBAIKI (ronde 64d): dokumen ini dulu hanya berjalan bila
 * pemanggilnya mengirim baris pasien hasil JOIN dengan `branches` (memuat
 * `branch_name`/`branch_phone`/`branch_code`). Begitu dokumen dipakai dari jalur
 * LAIN (email Membership Upgrade), PHP menulis "Undefined array key branch_name"
 * dan nama cabang pada kartu menjadi kosong. Sekarang barisnya dilengkapi sendiri.
 */
function mcard_patient_row(array $p): array
{
    $perlu = ['branch_name', 'branch_phone', 'branch_code'];
    $kurang = array_filter($perlu, fn($k) => !isset($p[$k]));
    if (!$kurang || (int)($p['id'] ?? 0) <= 0) return $p;
    $row = one('SELECT p.*, b.name AS branch_name, b.address AS branch_address,
                       b.phone AS branch_phone, b.code AS branch_code
                  FROM patients p JOIN branches b ON b.id = p.branch_id WHERE p.id = ?', [(int)$p['id']]);
    return $row ?: $p;
}

/** Konteks gambar kartu (dipakai kedua dokumen PDF).
 *
 * Fungsi ini dulu didefinisikan di halaman `member_card.php`; dipindah ke pustaka
 * (ronde 64d) supaya dokumen dapat dibangun dari mana pun — termasuk pengirim email
 * otomatis — tanpa bergantung pada berkas halaman yang sedang dibuka. */
function mcard_ctx(array $p, array $level, array $levels, array $theme, string $since, string $note,
                   bool $hasBg, string $logo, bool $border): array
{
    return [
        'p' => $p, 'level' => $level, 'levels' => $levels, 'theme' => $theme, 'since' => $since,
        'note' => $note, 'hasBg' => $hasBg, 'logo' => $logo, 'border' => $border,
        'company' => clinic_name(),
        'phone' => (string)(($p['branch_phone'] ?? '') ?: setting('company_phone')),
    ];
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
    /* Padding MENGIKUTI pratinjau HTML (CSS ronde 73: `padding:5% 5% 5% 4.5%`,
       `padding-left:5%` bila kartu memakai gambar latar). Setiap kali tata letak
       pratinjau diubah, ANGKA DI SINI WAJIB ikut diubah — dijaga uji
       `kartu_layout_check.js` (membandingkan posisi tiap blok pratinjau vs PDF). */
    $containing = $hasBg ? $W : $bodyW;
    $padTopBot  = 0.05 * $containing + 0.5;
    $padL       = ($hasBg ? 0.05 : 0.045) * $containing + 0.5;
    $padR       = 0.05 * $containing + 0.5;
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
        $logoMaxH = min(28, 0.06 * MCARD_PREVIEW_PX) * $k * $scale;   // CSS: clamp(14px,6cqw,28px)
        $logoMaxW = 0.62 * $contentW;
        $logoSize = ['w' => 0.0, 'h' => 0.0];
        if ($logoPath !== '') $logoSize = $pdf->imagePngSize($logoPath, $logoMaxW, $logoMaxH);
        $coBlock = ['lines' => [], 'size' => 0.0, 'lineH' => 0.0];
        if ($logoSize['w'] <= 0) {
            $coBlock = mcard_block($pdf, (string)$c['company'], $contentW, 15.56 * $k * $scale, 2, 1.2, 4.0, true);
        }
        /* CSS: gap clamp(3px,2cqw,10px) — 2cqw dari lebar KARTU (preview 490px). */
        $gapLine = min(10.0, 0.02 * MCARD_PREVIEW_PX) * $k;
        $lineH   = max(1.0, 2 * $k);
        $g1 = ($logoSize['w'] > 0 ? $logoSize['h'] : mcard_block_h($coBlock)) + $gapLine + $lineH;

        /* CSS: clamp(.7rem, 2.9cqw + .42rem, 1.12rem) → maksimum 1,12rem = 17,92 px. */
        $name  = mcard_block($pdf, (string)$c['p']['name'], $contentW, 17.92 * $k * $scale, 3, 1.14, 5.0, true);
        $level = mcard_block($pdf, 'Member Card - ' . (string)$c['level']['label'], $contentW,
                             12.48 * $k * $scale, 1, 1.55, 3.6, true);
        $gapId = 0.6;    // CSS: .mcard-id{gap:1%}
        $g2 = mcard_block_h($name) + $gapId + mcard_block_h($level);

        /* Rincian 2 × 2: label (huruf besar) di atas nilainya. */
        $labelSize = 9.92 * $k * $scale;
        $valueSize = 13.44 * $k * $scale;
        $colGap = 10 * $k;                           // CSS: gap 4% 10px → column-gap 10 px
        $colW = ($contentW - $colGap) / 2;
        $items = [
            ['No. Member', (string)$c['p']['member_number']],
            ['No. Pasien', (string)$c['p']['patient_number']],
            ['Member Sejak', tglIndo((string)$c['since'])],
            ['Cabang', (string)$c['p']['branch_name']],
        ];
        $cells = [];
        $rowHs = [0.0, 0.0];
        /* Tinggi baris (line-height) disamakan dengan pratinjau CSS: label 1,25 dan
           nilai 1,3 — sebelumnya 1,55 sehingga blok rincian di PDF lebih tinggi
           daripada pratinjau (terukur 9,2% selisih pada uji tata letak kartu). */
        foreach ($items as $i => $it) {
            $lab = mcard_block($pdf, strtoupper($it[0]), $colW, $labelSize, 1, 1.25, 3.2, false);
            $val = mcard_block($pdf, $it[1], $colW, $valueSize, 2, 1.30, 3.6, true);
            $cells[$i] = ['label' => $lab, 'value' => $val];
            $h = mcard_block_h($lab) + mcard_block_h($val);
            /* baris = pasangan (item 0,1) lalu (item 2,3) — BUKAN i % 2. */
            $rowHs[intdiv($i, 2)] = max($rowHs[intdiv($i, 2)], $h);
        }
        /* CSS: row-gap 4% dari tinggi kotak grid → dipakai sebagai porsi dari tinggi
           baris rincian supaya proporsinya sama dengan pratinjau. */
        $rowGap = 0.04 * max(0.01, $rowHs[0] + $rowHs[1]);
        $g3 = $rowHs[0] + $rowGap + $rowHs[1];
        /* Jarak antara kelompok nama/level dan blok rincian.
           CSS ronde 74: `.mcard .mcard-body{gap:clamp(4px,2.4cqw,12px)}`. */
        $gapBody = min(12.0, 0.024 * MCARD_PREVIEW_PX) * $k;

        return ['g1' => $g1, 'g2' => $g2, 'g3' => $g3, 'gapBody' => $gapBody,
            'logoSize' => $logoSize, 'co' => $coBlock,
            'name' => $name, 'level' => $level, 'cells' => $cells, 'rowHs' => $rowHs, 'rowGap' => $rowGap,
            'gapLine' => $gapLine, 'lineH' => $lineH, 'gapId' => $gapId, 'colW' => $colW, 'colGap' => $colGap,
            'logoMaxH' => $logoMaxH];
    };

    /* ============================================================================
     * PEMUSATAN VERTIKAL KELOMPOK INFORMASI (permintaan pemilik, ronde 74)
     * ----------------------------------------------------------------------------
     * Pratinjau web memakai `.mcard-body{flex:1;justify-content:center}`: kelompok
     * (nama, level, rincian) mengambil SELURUH tinggi sisa di bawah garis pink lalu
     * dipusatkan, sehingga ruang kosong di atas kelompok = di bawahnya. Angka di sini
     * meniru perhitungan yang sama supaya PDF PERSIS seperti pratinjau — bukan
     * memasang jarak tetap dari atas.
     * ========================================================================== */
    $b = $build(1.0);
    $contentH = $contentTop - $contentBot;
    /* Tinggi bagian KEPALA (logo + jarak + garis) dan tinggi KELOMPOK isi. */
    $headH = $b['g1'];
    $needH = function (array $x): float { return $x['g2'] + $x['gapBody'] + $x['g3']; };
    $areaH = $contentH - $headH;                      // area di bawah garis pink
    if ($needH($b) > $areaH - 1.0) {                  // teks panjang → kecilkan proporsional
        $scale = max(0.62, ($areaH - 1.0) / max(0.01, $needH($b)));
        $b = $build($scale);
    }
    $grupH = $needH($b);
    /* Ruang kosong dibagi SAMA ke atas & ke bawah kelompok (pusat). */
    $ruangAtas = max(0.0, ($areaH - $grupH) / 2);
    $grupTop = $contentTop - $headH - $ruangAtas;      // tepi atas kelompok

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

    /* Identitas: dimulai dari tepi atas KELOMPOK yang sudah dipusatkan. */
    $idTop = $grupTop;
    mcard_put($pdf, $contentX, $idTop, $b['name'], true, '#000000');
    mcard_put($pdf, $contentX, $idTop - mcard_block_h($b['name']) - $b['gapId'], $b['level'], true, '#000000');

    /* Rincian: tepat di bawah kelompok identitas (jarak = gapBody yang sama
       dengan `gap` pada pratinjau), BUKAN ditempel ke dasar kartu. */
    $rowsTop = $grupTop - $b['g2'] - $b['gapBody'];
    $row1Top = $rowsTop;
    $row2Top = $row1Top - $b['rowHs'][0] - $b['rowGap'];
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

    /* Susunan pratinjau (ronde 74): judul → garis → daftar level → garis → ketentuan,
       SELURUHNYA dipusatkan vertikal dengan jarak konsisten. Garis diberi rongganya
       sendiri (`ROW_GAP`) supaya tulisan tidak rapat dengan garis.
       Cara: SEMUA blok disusun lebih dulu (tanpa menggambar) untuk menghitung tinggi
       totalnya, lalu titik awalnya ditentukan agar terpusat — jadi bila keterangan
       bertambah/membungkus, susunannya tetap rapi dan tidak ada yang terpotong. */
    $titleSize = 14.4 * $k;
    $benSize   = 12.16 * $k;
    $noteSize  = 10.88 * $k;
    $ROW_GAP   = min(8.0, 0.014 * MCARD_PREVIEW_PX) * $k;    // CSS: gap clamp(2px,1.4cqw,8px)
    $LINE_GAP  = min(6.0, 0.009 * MCARD_PREVIEW_PX) * $k;    // CSS: margin garis clamp(1px,.9cqw,6px)
    $lineH     = max(1.0, 2 * $k);
    $benGap    = 0.39;                                        // jarak antarbaris daftar level

    /* ---- 1. Susun blok ---- */
    $title = mcard_block($pdf, 'KEUNTUNGAN LEVEL MEMBER', $cw, $titleSize, 1, 1.55, 4.2, true);
    $bens = [];
    foreach ((array)$c['levels'] as $l) {
        $on = (string)$l['key'] === (string)$c['level']['key'];
        $txt = ($on ? '* ' : '') . (string)$l['label'] . ' ~ Diskon '
            . num((float)$l['pct'], (float)$l['pct'] == (int)$l['pct'] ? 0 : 1) . '%';
        $blk = mcard_block($pdf, $txt, $cw, $benSize, 1, 1.30, 3.4, $on);
        $bens[] = ['blk' => $blk, 'on' => $on];
    }
    $noteTxt = 'Benefit member aktif: setiap transaksi minimal ' . money(member_min_transaction())
        . ' mendapat diskon otomatis sesuai level kartu (' . member_scope_text() . ').';
    $noteBlk = mcard_block($pdf, $noteTxt, $cw, $noteSize, 8, 1.35, 3.4, false);
    $klinik = trim((string)($c['note'] ?? ''));
    $klinikBlk = $klinik !== '' ? mcard_block($pdf, $klinik, $cw, $noteSize, 6, 1.35, 3.4, false) : null;

    /* ---- 2. Fungsi tinggi total untuk sekumpulan blok ---- */
    $hitung = function (array $bens, ?array $noteBlk, ?array $klinikBlk, array $title)
        use ($ROW_GAP, $LINE_GAP, $lineH, $benGap): float {
        $h = mcard_block_h($title) + $ROW_GAP + $LINE_GAP + $lineH + $LINE_GAP + $ROW_GAP;
        $n = count($bens);
        foreach ($bens as $i => $b) {
            $h += mcard_block_h($b['blk']);
            if ($i + 1 < $n) $h += $benGap;
        }
        $h += $ROW_GAP + $LINE_GAP + $lineH + $LINE_GAP + $ROW_GAP;
        if ($noteBlk) $h += mcard_block_h($noteBlk);
        if ($klinikBlk) $h += mcard_block_h($klinikBlk) + 2.0;
        return $h;
    };

    /* ---- 3. Menyesuaikan bila terlalu tinggi (beri ruang 1 pt) ---- */
    $avail = ($top - $bot) - 1.0;
    $total = $hitung($bens, $noteBlk, $klinikBlk, $title);
    while ($total > $avail && count($bens) > 1) {           // kurangi baris level dari bawah
        array_pop($bens);
        $total = $hitung($bens, $noteBlk, $klinikBlk, $title);
    }
    if ($total > $avail && $klinikBlk) {                    // batasi baris catatan klinik
        $klinikBlk = mcard_block($pdf, $klinik, $cw, $noteSize,
            max(1, (int)floor((mcard_block_h($klinikBlk) - ($total - $avail)) / ($noteSize * 1.35))),
            1.35, 3.4, false);
        $total = $hitung($bens, $noteBlk, $klinikBlk, $title);
    }

    /* ---- 4. Pusatkan lalu gambar ---- */
    $y = ($top + $bot) / 2 + $total / 2;
    mcard_put($pdf, $x, $y, $title, true, '#000000');
    $y -= mcard_block_h($title) + $ROW_GAP + $LINE_GAP;
    $pdf->fillRect($x, $y - $lineH, $cw, $lineH, $brand);
    $y -= $lineH + $LINE_GAP + $ROW_GAP;
    $n = count($bens);
    foreach ($bens as $i => $b) {
        mcard_put($pdf, $x, $y, $b['blk'], $b['on'], '#000000');
        $y -= mcard_block_h($b['blk']);
        if ($i + 1 < $n) $y -= $benGap;
    }
    $y -= $ROW_GAP + $LINE_GAP;
    $pdf->fillRect($x, $y - $lineH, $cw, $lineH, $brand);
    $y -= $lineH + $LINE_GAP + $ROW_GAP;
    mcard_put($pdf, $x, $y, $noteBlk, false, '#333333');
    $y -= mcard_block_h($noteBlk);
    if ($klinikBlk) {
        $y -= 2.0;
        mcard_put($pdf, $x, $y, $klinikBlk, false, '#444444');
    }

    if (!empty($c['border'])) {
        $pdf->lineAt($ox, $oy, $ox + $W, $oy, 0.82);
        $pdf->lineAt($ox, $oy + $H, $ox + $W, $oy + $H, 0.82);
        $pdf->lineAt($ox, $oy, $ox, $oy + $H, 0.82);
        $pdf->lineAt($ox + $W, $oy, $ox + $W, $oy + $H, 0.82);
    }
}


/**
 * DOKUMEN KARTU MEMBER LENGKAP (2 halaman A4) — mengembalikan byte PDF.
 *
 * Halaman 1: pratinjau KEDUA kartu (persis halaman Kartu Member Digital).
 * Halaman 2: status member & aturan diskon yang berlaku.
 *
 * DIANGKAT dari `member_card.php?format=pdf_lengkap` (ronde 64d) supaya halaman itu
 * dan pengirim email otomatis (Membership Upgrade) memakai SATU sumber kode yang
 * sama — dokumen yang dilampirkan ke email pasti identik dengan yang dicetak pemilik.
 */
function mcard_full_pdf(array $p, array $level, array $levels, array $theme, string $since,
                        string $note, string $cardBg, string $cardLogo): string
{
    $company = clinic_name();
    /* $status HARUS dihitung di dalam fungsi ini. Dulu blok ini berada di dalam
       `member_card.php` sehingga tanpa sengaja memakai variabel halaman `$status`;
       setelah dipindah ke fungsi, variabel itu tidak lagi terjangkau — halaman 2
       kehilangan keterangan periode/akumulasi/level berikutnya (tanpa error, hanya
       kosong). Ditemukan lewat pemeriksaan log peringatan PHP. */
    $p = mcard_patient_row($p);        // lengkapi data cabang bila pemanggil belum menyertakannya
    $status = member_status($p);
    $pdf = new MiniPdf(595.28, 841.89, 40);          // A4
    $hasBg = $cardBg !== '';
    $printedAt = tglIndo(date('Y-m-d')) . ' ' . date('H:i');

    /* Jarak antar bagian dokumen — dikumpulkan di satu tempat supaya rapi dan
       proporsional (permintaan pemilik). Sebelumnya judul menempel logo karena
       setelah gambar hanya tersisa tinggi baris (jarak nyata cuma 1,9 pt). */
    $GAP_LOGO_TITLE = 12.0;     // bawah gambar logo → huruf atas judul
    $GAP_TITLE_SUB  = 3.0;      // judul → baris keterangan (nama · nomor · cabang)
    $GAP_SUB_RULE   = 10.0;     // keterangan → garis pemisah
    $GAP_RULE_CARD  = 14.0;     // garis → tepi atas kartu
    $GAP_CARD_CAP   = 16.0;     // bawah kartu → keterangan sisi kartu
    $GAP_CAP_NOTE   = 15.0;     // keterangan sisi → paragraf penjelasan
    $docFoot = function (string $s) use ($pdf): void {
        $pdf->text(40, $s, 7.6, false, 34);
    };

    /**
     * Kepala dokumen (kop): logo tengah-atas, judul, keterangan, garis pemisah.
     * Jaraknya eksplisit sehingga tidak pernah lagi menempel seperti sebelumnya.
     */
    $docHead = function (string $title, string $sub) use ($pdf, $company, $cardLogo,
        $GAP_LOGO_TITLE, $GAP_TITLE_SUB, $GAP_SUB_RULE): void {
        $pakaiLogo = false;
        if ($cardLogo !== '') {
            $s = $pdf->imagePngCentered($cardLogo, 132, 38);
            $pakaiLogo = $s['w'] > 0;
        }
        if (!$pakaiLogo) {
            $pdf->line($company, 14, true, 0, true);
            $pdf->gap($GAP_LOGO_TITLE);
        } else {
            /* imagePngCentered() sudah menurunkan kursor sebesar tinggi baris
               (tanpa itu judul menempel gambar). Tinggi huruf kapital ±0,72 em,
               jadi jarak nyata gambar→judul = GAP_LOGO_TITLE. */
            $pdf->gap($GAP_LOGO_TITLE + 0.72 * 13.5 - $pdf->lineHeight);
        }
        $pdf->line($title, 13.5, true, 0, true);
        $pdf->gap($GAP_TITLE_SUB);
        $pdf->line($sub, 8.6, false, 0, true);
        $pdf->gap($GAP_SUB_RULE);
        $pdf->hr(0);
    };

    /* ---------------- HALAMAN 1: pratinjau dua kartu ---------------- */
    $docHead('KARTU MEMBER DIGITAL', (string)$p['name'] . '  ·  ' . (string)$p['member_number']
        . '  ·  ' . (string)$p['branch_name']);
    $pdf->gap($GAP_RULE_CARD);

    $gapCards = 24.0;
    $card1X = (595.28 - (2 * MCARD_W + $gapCards)) / 2;
    $card2X = $card1X + MCARD_W + $gapCards;
    $cardTop = $pdf->y;
    $cardY = $cardTop - MCARD_H;

    /* Latar kartu (bila diunggah) digambar TEPAT di kotak kartunya — memakai
       imagePngAt() yang kini benar-benar menghormati koordinat mutlak. */
    if ($hasBg) {
        foreach ([$card1X, $card2X] as $cx) {
            $pdf->fillRect($cx, $cardY, MCARD_W, MCARD_H, '#FFFFFF');
            $pdf->imagePngAt($cardBg, $cx, $cardTop, MCARD_W + 0.01, MCARD_H + 0.01);
        }
    }
    $ctx = mcard_ctx($p, $level, $levels, $theme, $since, $note, $hasBg, $cardLogo, true);
    mcard_front($pdf, $card1X, $cardY, $ctx);
    mcard_back($pdf, $card2X, $cardY, $ctx);

    $capY = $cardY - $GAP_CARD_CAP;
    $pdf->text($card1X, 'Sisi depan — identitas pemegang kartu', 8.4, false, $capY);
    $pdf->text($card2X, 'Sisi belakang — keuntungan level & catatan', 8.4, false, $capY);
    $pdf->y = $capY - $GAP_CAP_NOTE;
    $pdf->paragraph('Ukuran tiap kartu pada halaman ini 85,6 x 54 mm (seukuran kartu ATM) dan kedua sisi '
        . 'ditampilkan bersebelahan. Saat mencetak, pilih ukuran kertas A4 dengan skala "100% / ukuran '
        . 'sebenarnya" agar kartu dapat langsung digunting. Halaman 2 memuat status member dan aturan '
        . 'diskon yang sedang berlaku.', 8.4);
    $docFoot($company . '  ·  ' . (string)($p['branch_phone'] ?: setting('company_phone'))
        . '  ·  dicetak ' . $printedAt);

    /* ---------------- HALAMAN 2: status member & aturan diskon ---------------- */
    $pdf->newPage();
    $docHead('STATUS MEMBER & ATURAN DISKON YANG BERLAKU', (string)$p['name'] . '  ·  '
        . (string)$p['member_number'] . '  ·  ' . (string)$p['branch_name']);
    $pdf->gap(6);

    /** Satu baris "label + nilai" seperti daftar di halaman kartu. */
    $dl = function (string $label, string $value) use ($pdf): void {
        $pdf->line($label, 8.6, true);
        $pdf->gap(1);
        $pdf->paragraph($value, 9.4);
        $pdf->gap(6);
    };

    $pdf->line('STATUS MEMBER', 10.5, true);
    $pdf->gap(3);
    $pdf->paragraph('Data per ' . $printedAt . '. Level dan akumulasi dihitung otomatis dari transaksi '
        . 'berstatus dibayar pada periode ' . member_period_label() . '.', 8.6);
    $pdf->gap(4);
    $dl('Level saat ini', $level['label'] . ' - diskon '
        . num((float)$level['pct'], (float)$level['pct'] == (int)$level['pct'] ? 0 : 1) . '% untuk '
        . member_scope_text() . '.');
    $dl('Akumulasi ' . member_period_label() . ' (' . tglIndo((string)$status['period']['start']) . ' s.d. '
        . tglIndo((string)$status['period']['end']) . ')', money((float)$status['year_total'])
        . ' (dihitung dari ' . num((int)$status['trx']) . ' transaksi berstatus dibayar pada periode ini).');
    if ($status['next']) {
        $kurang = max(0.0, (float)$status['next']['min_year'] - (float)$status['year_total']);
        $dl('Menuju ' . $status['next']['label'], 'Perlu akumulasi ' . money((float)$status['next']['min_year'])
            . ' - kurang ' . money($kurang) . ' lagi (diskon level itu '
            . num((float)$status['next']['pct'], (float)$status['next']['pct'] == (int)$status['next']['pct'] ? 0 : 1)
            . '%).');
    } else {
        $dl('Level tertinggi', 'Sudah mencapai level member tertinggi yang berlaku di klinik ini.');
    }
    $dl('Diperoleh dari', member_source_text((string)$p['member_source']));
    $dl('Member sejak', tglIndo($since) . '  ·  cabang ' . (string)$p['branch_name']);

    $pdf->gap(4);
    $pdf->line('ATURAN DISKON YANG BERLAKU', 10.5, true);
    $pdf->gap(3);
    foreach ($levels as $l) {
        $on = (string)$l['key'] === (string)$level['key'];
        $pdf->kv(($on ? '* ' : '') . (string)$l['label'] . ' - diskon '
            . num((float)$l['pct'], (float)$l['pct'] == (int)$l['pct'] ? 0 : 1) . '%',
            'akumulasi ' . member_period_label() . ' >= ' . money((float)$l['min_year']));
    }
    $pdf->hr(0);
    $pdf->gap(2);
    $pdf->paragraph('Kartu member terbuka otomatis bila satu transaksi mencapai '
        . money(member_activate_amount()) . ' atau akumulasi ' . member_period_label() . ' mencapai '
        . money((float)$levels[0]['min_year']) . '. Diskon dihitung otomatis pada setiap transaksi '
        . 'dengan nilai minimal ' . money(member_min_transaction()) . ' untuk '
        . member_scope_text() . '. Besaran diskon selalu dihitung ulang oleh sistem dari data '
        . 'transaksi, bukan dari angka yang diisi petugas.', 9.0);
    if (member_period_years() > 0) {
        $pdf->gap(4);
        $pdf->line('Periode akumulasi member', 8.6, true);
        $pdf->gap(1);
        $pdf->paragraph('Akumulasi dihitung per periode ' . member_period_label()
            . ' dan direset bersama untuk semua member setiap 1 Januari. Periode berjalan: '
            . tglIndo((string)$status['period']['start']) . ' s.d. ' . tglIndo((string)$status['period']['end'])
            . ', reset berikutnya ' . tglIndo((string)$status['period']['next_reset']) . '. Saat periode berakhir'
            . (member_downgrade_steps() > 0
                ? ', level kartu turun ' . num(member_downgrade_steps()) . ' tingkat'
                : ', level kartu TIDAK diturunkan')
            . ' dan akumulasi mulai dari nol; nomor member tidak berubah.', 9.0);
    } else {
        $pdf->gap(4);
        $pdf->paragraph('Akumulasi member pada klinik ini TIDAK direset (tanpa periode): level dihitung dari '
            . 'seluruh transaksi berbayar yang pernah tercatat untuk pasien ini.', 9.0);
    }
    if ($note !== '') {
        $pdf->gap(4);
        $pdf->line('Catatan kartu', 8.6, true);
        $pdf->gap(1);
        $pdf->paragraph($note, 9.0);
    }
    if (!member_card_enabled()) {
        $pdf->gap(6);
        $pdf->paragraph('PEMBERITAHUAN: fitur kartu member sedang TIDAK AKTIF, sehingga diskon member '
            . 'tidak berlaku sampai admin klinik mengaktifkannya kembali. Dokumen ini dicetak sebagai arsip.', 9.0);
    }
    $docFoot($company . '  ·  dicetak ' . $printedAt . '  ·  halaman 2 dari 2');

    return $pdf->output();
}
