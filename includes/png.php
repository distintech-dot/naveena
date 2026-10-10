<?php
/**
 * Pembaca PNG minimal (tanpa ekstensi GD/zip).
 * Mengubah byte PNG menjadi data RGB mentah 8-bit yang sudah dikompositkan
 * di atas latar putih — cukup untuk ditempelkan ke dalam PDF.
 *
 * Mendukung color type 0 (grayscale), 2 (RGB), 3 (palet), 4 (grayscale+alpha),
 * 6 (RGBA), bit depth 8 dan 16, serta tRNS. PNG interlace (Adam7) tidak
 * didukung dan ditolak dengan jelas (bukan gagal diam-diam).
 *
 * PENTING: berkas logo dari pengguna bisa berukuran sangat besar (mis. PNG
 * 4500x4500 = puluhan MB saat didekode). Karena itu pembaca ini dapat langsung
 * MENGECILKAN gambar saat membaca (parameter $targetH) sehingga pemakaian
 * memori tetap kecil dan tidak melebihi memory_limit PHP.
 */
declare(strict_types=1);

/** Batas dimensi berkas sumber (bukan hasil akhir) — sekadar penjaga kewarasan. */
const PNG_MAX_SOURCE_DIM = 20000;

/** Dimensi PNG tanpa mendekode seluruh berkas (hanya membaca IHDR). */
function png_dimensions(string $data): ?array
{
    if (strlen($data) < 24 || substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    if (substr($data, 12, 4) !== 'IHDR') return null;
    return ['width' => unpack('N', substr($data, 16, 4))[1], 'height' => unpack('N', substr($data, 20, 4))[1]];
}

/**
 * @param bool $trackBox Hitung kotak isi (piksel tidak transparan) sekaligus.
 * @return array{width:int,height:int,rgb:string,alpha:bool,box?:array}|null
 */
function png_to_rgb(string $data, ?int $targetH = null, bool $trackBox = false): ?array
{
    if (strlen($data) < 8 || substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;

    $pos = 8;
    $len = strlen($data);
    $ihdr = null;
    $plte = '';
    $trns = '';
    $idat = '';
    while ($pos + 8 <= $len) {
        $clen = unpack('N', substr($data, $pos, 4))[1];
        $type = substr($data, $pos + 4, 4);
        $body = substr($data, $pos + 8, $clen);
        $pos += 12 + $clen;   // 4 len + 4 type + body + 4 crc
        if ($type === 'IHDR') $ihdr = $body;
        elseif ($type === 'PLTE') $plte = $body;
        elseif ($type === 'tRNS') $trns = $body;
        elseif ($type === 'IDAT') $idat .= $body;
        elseif ($type === 'IEND') break;
    }
    if ($ihdr === null || strlen($ihdr) < 13) return null;

    $w = unpack('N', substr($ihdr, 0, 4))[1];
    $h = unpack('N', substr($ihdr, 4, 4))[1];
    $depth = ord($ihdr[8]);
    $color = ord($ihdr[9]);
    $interlace = ord($ihdr[12]);
    if ($w <= 0 || $h <= 0 || $w > PNG_MAX_SOURCE_DIM || $h > PNG_MAX_SOURCE_DIM) return null;
    if ($interlace !== 0) return null;                 // Adam7 tidak didukung
    if ($depth !== 8 && $depth !== 16) return null;     // sub-byte tidak didukung

    $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$color] ?? 0;
    if ($channels === 0) return null;
    if ($color === 3 && $plte === '') return null;

    $bytesPerSample = $depth === 16 ? 2 : 1;
    $bpp = $channels * $bytesPerSample;              // byte per piksel
    $stride = $w * $bpp;
    /* Ukuran data hasil dekompresi yang diharapkan. Untuk gambar besar angka ini
       bisa puluhan MB (mis. 4500x4500 RGBA ≈ 81 MB) sehingga TIDAK boleh
       didekompresi sekaligus — memory_limit PHP akan habis. Aliran zlib dibaca
       BERTAHAP dan diproses baris demi baris saat inflate_* tersedia. */
    $expected = ($stride + 1) * $h;
    $canStream = function_exists('inflate_init') && function_exists('inflate_add');
    if (!$canStream) {
        $limit = (int)preg_replace('/\D/', '', (string)ini_get('memory_limit'));
        $unit = strtoupper(substr((string)ini_get('memory_limit'), -1));
        if ($unit === 'M') $limit *= 1048576;
        elseif ($unit === 'G') $limit *= 1073741824;
        if ($limit > 0) {
            $free = $limit - memory_get_usage(true);
            // sisa harus cukup untuk hasil dekompresi + RGB keluaran + margin 8 MB
            if ($expected * 2 + 8 * 1048576 > $free) return null;
        }
    }

    // ---- siapkan tujuan (dengan/tanpa pengecilan) ----
    $scaled = false;
    $dw = $w;
    $dh = $h;
    if ($targetH !== null && $targetH > 0 && $targetH < $h) {
        $dh = (int)$targetH;
        $dw = max(1, (int)round($w * ($dh / $h)));
        $scaled = true;
    }

    /* Baris awal/akhir sumber untuk setiap baris tujuan (box filter vertikal). */
    $rowRange = function (int $oy) use ($dh, $h): array {
        $y0 = (int)floor($oy * $h / $dh);
        $y1 = (int)floor(($oy + 1) * $h / $dh);
        if ($y1 <= $y0) $y1 = $y0 + 1;
        return [$y0, min($y1, $h)];
    };

    $prev = str_repeat("\x00", $stride);
    $off = 0;
    $out = '';
    $oy = 0;
    $acc = null;        // akumulator nilai (float/int) untuk baris tujuan berjalan
    $accCnt = 0;
    $curY1 = 0;
    if ($scaled) {
        [$y0, $y1] = $rowRange(0);
        $curY1 = $y1;
        $acc = array_fill(0, $dw * 3, 0);
    }
    /* Pelacak kotak isi: banyak berkas logo berukuran besar menyimpan logo pada
       pita kecil di tengah dengan latar TRANSPARAN. Tanpa dipotong, logo menjadi
       nyaris tak terlihat setelah dikecilkan. */
    $boxX0 = null; $boxX1 = null; $boxY0 = null; $boxY1 = null;
    $alphaPresent = in_array($color, [4, 6], true) || $trns !== '';

    /* ---- pembaca bertahap: hasil inflate ditampung di $zbuf ----
       Catatan: build PHP di server ini punya fungsi inflate_* tetapi TIDAK punya
       konstanta ZLIB_ENCODING_ZLIB, jadi nilai 15 (format zlib) dipakai sebagai
       cadangan. Bila inflate_* tidak tersedia, dipakai jalur gzuncompress biasa
       (butuh memori sebesar ukuran gambar) dengan penjaga di bawah. */
    $inflate = null;
    if (function_exists('inflate_init') && function_exists('inflate_add')) {
        $zlibFmt = defined('ZLIB_ENCODING_ZLIB') ? (int)ZLIB_ENCODING_ZLIB : 15;
        $inflate = @inflate_init($zlibFmt);
        if ($inflate === false) $inflate = null;
    }
    $chunkSize = 65536;
    $idatLen = strlen($idat);
    $idatPos = 0;
    $zbuf = '';
    $zdone = false;

    /** Ambil tepat $n byte dari aliran; mengembalikan null bila data habis. */
    $take = function (int $n) use (&$zbuf, &$idat, &$idatPos, &$idatLen, &$inflate, &$zdone, $chunkSize): ?string {
        while (strlen($zbuf) < $n) {
            if ($idatPos >= $idatLen) {
                if ($inflate !== null && !$zdone) {
                    $tail = @inflate_add($inflate, '', ZLIB_FINISH);
                    $zdone = true;
                    if ($tail !== false && $tail !== '') $zbuf .= $tail;
                }
                if (strlen($zbuf) < $n) return null;
                break;
            }
            $chunk = substr($idat, $idatPos, $chunkSize);
            $idatPos += strlen($chunk);
            if ($inflate !== null) {
                $out = @inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);
                if ($out === false) return null;
                $zbuf .= $out;
            } else {
                /* Tanpa inflate_*: dekompresi seluruh aliran zlib sekaligus.
                   Hanya aman untuk gambar kecil; ukuran besar sudah ditolak oleh
                   penjaga memori sebelum sampai ke sini. */
                if (strlen($idat) > 0) {
                    $whole = @gzuncompress($idat);
                    if ($whole === false) $whole = @gzinflate($idat);
                    if ($whole === false) return null;
                    $zbuf .= $whole;
                    $idatLen = 0;
                    $idatPos = 0;
                    continue;
                }
                return null;
            }
        }
        $part = substr($zbuf, 0, $n);
        $zbuf = substr($zbuf, $n);
        return $part;
    };

    // tabel rasio horizontal (dihitung sekali) untuk jalur pengecilan
    $hRange = null;
    if ($scaled) {
        $hRange = [];
        for ($ox = 0; $ox < $dw; $ox++) {
            $x0 = (int)floor($ox * $w / $dw);
            $x1 = (int)floor(($ox + 1) * $w / $dw);
            if ($x1 <= $x0) $x1 = $x0 + 1;
            if ($x1 > $w) $x1 = $w;
            $hRange[$ox] = [$x0, $x1];
        }
    }

    for ($y = 0; $y < $h; $y++) {
        $rowData = $take(1 + $stride);
        if ($rowData === null) return null;
        $ft = ord($rowData[0]);
        $cur = substr($rowData, 1);
        // ---- unfilter baris ----
        if ($ft === 0) {
            $line = $cur;                       // jalur cepat: tanpa filter
        } else {
            $line = '';
            for ($i = 0; $i < $stride; $i++) {
                $x = ord($cur[$i]);
                /* PENTING: tetangga KIRI harus diambil dari baris yang SUDAH
                   direkonstruksi ($line), bukan dari byte mentah ($cur).
                   Memakai $cur membuat semua filter Sub/Average/Paeth salah:
                   logo yang berkas PNG-nya memakai filter tersebut terbaca
                   sebagai blok GELAP (gambar "rusak hitam") — persis keluhan
                   "logo di PDF hitam" pada struk/nota. Filter Up ($b/$c dari
                   baris sebelumnya) sudah benar karena $prev memang hasil rekonstruksi. */
                $a = $i >= $bpp ? ord($line[$i - $bpp]) : 0;
                $b = ord($prev[$i]);
                $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                switch ($ft) {
                    case 1: $v = $x + $a; break;
                    case 2: $v = $x + $b; break;
                    case 3: $v = $x + intdiv($a + $b, 2); break;
                    case 4:
                        $p = $a + $b - $c;
                        $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
                        $pr = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
                        $v = $x + $pr; break;
                    default: return null;
                }
                $line .= chr($v & 0xFF);
            }
        }
        $prev = $line;

        if (!$scaled) {
            // ---- tanpa pengecilan: konversi langsung ----
            $rgb = '';
            [$rr, $gg, $bb] = png_row_to_rgb($line, $w, $color, $depth, $plte, $trns);
            for ($x = 0; $x < $w; $x++) {
                $rgb .= chr($rr[$x] & 0xFF) . chr($gg[$x] & 0xFF) . chr($bb[$x] & 0xFF);
            }
            $out .= $rgb;
            continue;
        }

        // ---- lacak kotak isi (alpha > 16) pada baris sumber ----
        if ($trackBox && $alphaPresent) {
            $rowHasContent = false;
            for ($x = 0; $x < $w; $x++) {
                $i = $x * $bpp;
                if ($color === 6) $a = ord($line[$i + 3]);
                elseif ($color === 4) $a = ord($line[$i + 1]);
                else { $idx = ord($line[$i]); $a = ($trns !== '' && isset($trns[$idx])) ? ord($trns[$idx]) : 255; }
                if ($a > 16) {
                    if ($boxX0 === null || $x < $boxX0) $boxX0 = $x;
                    if ($boxX1 === null || $x > $boxX1) $boxX1 = $x;
                    $rowHasContent = true;
                }
            }
            if ($rowHasContent) {
                if ($boxY0 === null) $boxY0 = $y;
                $boxY1 = $y;
            }
        }

        // ---- dengan pengecilan: rata-rata horizontal lalu vertikal ----
        if ($y < $curY1) {
            /* Hanya piksel yang dibutuhkan yang dibaca (bukan seluruh lebar),
               sehingga untuk pengecilan besar (mis. 4500 -> 192 px) pekerjaan
               berkurang puluhan kali. */
            png_row_accumulate($line, $color, $depth, $plte, $trns, $stepForRow ?? null, $hRange, $acc);
            $accCnt++;
            if ($y === $curY1 - 1) {
                if ($accCnt === 0) $accCnt = 1;
                for ($ox = 0; $ox < $dw; $ox++) {
                    $out .= chr((int)round($acc[$ox * 3] / $accCnt) & 0xFF)
                         .  chr((int)round($acc[$ox * 3 + 1] / $accCnt) & 0xFF)
                         .  chr((int)round($acc[$ox * 3 + 2] / $accCnt) & 0xFF);
                }
                $oy++;
                if ($oy < $dh) {
                    [$y0, $y1] = $rowRange($oy);
                    $curY1 = $y1;
                    $acc = array_fill(0, $dw * 3, 0);
                    $accCnt = 0;
                }
            }
        }
    }

    $alpha = $alphaPresent;
    $res = ['width' => $dw, 'height' => $dh, 'rgb' => $out, 'alpha' => $alpha];
    if ($trackBox && $boxX0 !== null && $boxY0 !== null) {
        $sx = $dw / $w;
        $sy = $dh / $h;
        $res['box'] = [
            'x0' => (int)floor($boxX0 * $sx),
            'y0' => (int)floor($boxY0 * $sy),
            'x1' => (int)ceil(($boxX1 + 1) * $sx),
            'y1' => (int)ceil(($boxY1 + 1) * $sy),
        ];
    }
    return $res;
}

/**
 * Akumulasi satu baris sumber langsung ke akumulator baris tujuan (rata-rata
 * horizontal), tanpa membuat array per piksel. Dipakai saat mengecilkan gambar.
 *
 * @param int[] $acc  akumulator panjang dw*3 (diubah di tempat)
 * @param array<int,array{0:int,1:int}> $hRange rentang kolom sumber per kolom tujuan
 */
function png_row_accumulate(string $row, int $color, int $depth, string $plte, string $trns, ?array $unused, array $hRange, array &$acc): void
{
    $p16 = $depth === 16;
    $step = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$color] * ($p16 ? 2 : 1);
    foreach ($hRange as $ox => $r) {
        [$x0, $x1] = $r;
        $sr = $sg = $sb = 0; $n = 0;
        for ($x = $x0; $x < $x1; $x++) {
            $i = $x * $step;
            $al = 255; $hasAlpha = false;
            if ($color === 0) { $g = ord($row[$i]); $r2 = $g; $b2 = $g; }
            elseif ($color === 4) { $g = ord($row[$i]); $al = ord($row[$i + 1]); $hasAlpha = true; $r2 = $g; $b2 = $g; }
            elseif ($color === 2) { $r2 = ord($row[$i]); $g = ord($row[$i + 1]); $b2 = ord($row[$i + 2]); }
            elseif ($color === 6) {
                $r2 = ord($row[$i]); $g = ord($row[$i + 1]); $b2 = ord($row[$i + 2]);
                $al = ord($row[$i + 3]); $hasAlpha = true;
            } else {
                $idx = ord($row[$i]);
                $r2 = ord($plte[$idx * 3] ?? "\xFF");
                $g  = ord($plte[$idx * 3 + 1] ?? "\xFF");
                $b2 = ord($plte[$idx * 3 + 2] ?? "\xFF");
                if ($trns !== '' && isset($trns[$idx])) { $al = ord($trns[$idx]); $hasAlpha = true; }
            }
            if ($hasAlpha && $al < 255) {
                $r2 = intdiv($r2 * $al + 255 * (255 - $al), 255);
                $g  = intdiv($g * $al + 255 * (255 - $al), 255);
                $b2 = intdiv($b2 * $al + 255 * (255 - $al), 255);
            }
            $sr += $r2; $sg += $g; $sb += $b2; $n++;
        }
        if ($n === 0) $n = 1;
        $acc[$ox * 3] += $sr / $n;
        $acc[$ox * 3 + 1] += $sg / $n;
        $acc[$ox * 3 + 2] += $sb / $n;
    }
}

/**
 * Konversi satu baris PNG (sudah di-unfilter) menjadi tiga array channel 0..255.
 * Dipisah agar bisa dipakai baik untuk pembacaan penuh maupun pengecilan.
 *
 * @return array{0:int[],1:int[],2:int[]}
 */
function png_row_to_rgb(string $row, int $w, int $color, int $depth, string $plte, string $trns): array
{
    $p16 = $depth === 16;
    $step = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$color] * ($p16 ? 2 : 1);
    $rr = array_fill(0, $w, 255);
    $gg = array_fill(0, $w, 255);
    $bb = array_fill(0, $w, 255);

    for ($x = 0; $x < $w; $x++) {
        $i = $x * $step;
        $al = 255;
        $hasAlpha = false;
        if ($color === 0) {
            $g = ord($row[$i]);
            $r = $g; $b = $g;
        } elseif ($color === 4) {
            $g = ord($row[$i]); $al = ord($row[$i + 1]); $hasAlpha = true;
            $r = $g; $b = $g;
        } elseif ($color === 2) {
            $r = ord($row[$i]); $g = ord($row[$i + 1]); $b = ord($row[$i + 2]);
        } elseif ($color === 6) {
            $r = ord($row[$i]); $g = ord($row[$i + 1]); $b = ord($row[$i + 2]);
            $al = ord($row[$i + 3]); $hasAlpha = true;
        } else {                      // palet
            $idx = ord($row[$i]);
            $r = ord($plte[$idx * 3] ?? "\xFF");
            $g = ord($plte[$idx * 3 + 1] ?? "\xFF");
            $b = ord($plte[$idx * 3 + 2] ?? "\xFF");
            if ($trns !== '' && isset($trns[$idx])) { $al = ord($trns[$idx]); $hasAlpha = true; }
        }
        if ($hasAlpha && $al < 255) {
            // komposit di atas latar putih
            $r = intdiv($r * $al + 255 * (255 - $al), 255);
            $g = intdiv($g * $al + 255 * (255 - $al), 255);
            $b = intdiv($b * $al + 255 * (255 - $al), 255);
        }
        $rr[$x] = $r; $gg[$x] = $g; $bb[$x] = $b;
    }
    return [$rr, $gg, $bb];
}

/**
 * Baca gambar dan kecilkan, dengan CACHE di berkas agar gambar besar hanya
 * diproses sekali. Tanpa cache, PNG 4500x4500 perlu ~70 detik setiap kali
 * PDF dibuat — terlalu lama untuk satu permintaan web.
 *
 * Berkas cache: <gambar>.c<targetH>-<mtime>-<size>.raw
 * Format: 8 byte lebar + 8 byte tinggi + 4 byte penanda versi + data RGB mentah.
 *
 * PENANDA VERSI (PNG_CACHE_MAGIC) penting: cache LAMA yang dibuat sebelum
 * perbaikan pendekode PNG non-GD (filter Sub/Average/Paeth) memuat gambar
 * GELAP/garbage. Tanpa penanda versi, cache rusak itu akan terus dipakai
 * selamanya sehingga logo tetap "hitam" di PDF walau kodenya sudah benar.
 * Cache tanpa penanda dianggap tidak sah dan dibuat ulang.
 */
const PNG_CACHE_MAGIC = 'NVC2';

/** Bongkar isi berkas cache → ['width','height','rgb'], atau null bila tidak sah. */
function png_cache_parse(string $blob): ?array
{
    if (strlen($blob) <= 20 || substr($blob, 16, 4) !== PNG_CACHE_MAGIC) return null;
    $w = (int)(unpack('J', substr($blob, 0, 8))[1] ?? 0);
    $h = (int)(unpack('J', substr($blob, 8, 8))[1] ?? 0);
    $rgb = substr($blob, 20);
    if ($w <= 0 || $h <= 0 || strlen($rgb) !== $w * $h * 3) return null;
    return ['width' => $w, 'height' => $h, 'rgb' => $rgb];
}

/** Susun isi berkas cache dari data RGB. */
function png_cache_build(array $png): string
{
    return pack('J', $png['width']) . pack('J', $png['height']) . PNG_CACHE_MAGIC . $png['rgb'];
}

function png_cache_exists(string $path, int $targetH): bool
{
    $key = png_cache_path($path, $targetH);
    if ($key === null || !is_readable($key)) return false;
    return png_cache_parse((string)@file_get_contents($key)) !== null;
}

/** Nama berkas cache untuk kombinasi gambar + tinggi tujuan. */
function png_cache_path(string $path, int $targetH): ?string
{
    if (!is_readable($path)) return null;
    $st = @stat($path);
    return $path . '.c' . $targetH . '-' . ($st['mtime'] ?? 0) . '-' . ($st['size'] ?? 0) . '.raw';
}

/**
 * Penskalaan cepat memakai GD (bila tersedia): jauh lebih ringan daripada
 * pendekodean PNG bertahap untuk berkas besar (4500x4500: ~0,9 detik vs ~68 detik).
 * Alpha dikompositkan di atas putih karena hasilnya ditempelkan ke PDF.
 */
function png_scaled_gd(string $path, int $targetH): ?array
{
    if (!extension_loaded('gd') || !function_exists('imagecreatefrompng')) return null;
    $src = @imagecreatefrompng($path);
    if ($src === false) return null;
    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw < 1 || $sh < 1) { imagedestroy($src); return null; }
    $dh = max(1, min($targetH, $sh));
    $dw = max(1, (int)round($sw * $dh / $sh));

    $dst = imagecreatetruecolor($dw, $dh);
    imagefilledrectangle($dst, 0, 0, $dw, $dh, imagecolorallocate($dst, 255, 255, 255));
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($src);

    // cari kotak isi (piksel yang bukan latar putih) supaya logo pada pita
    // tengah gambar besar tetap terlihat penuh
    $x0 = $dw; $y0 = $dh; $x1 = -1; $y1 = -1;
    for ($y = 0; $y < $dh; $y++) {
        for ($x = 0; $x < $dw; $x++) {
            $c = imagecolorat($dst, $x, $y);
            $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
            if ($r < 246 || $g < 246 || $b < 246) {
                if ($x < $x0) $x0 = $x;
                if ($x > $x1) $x1 = $x;
                if ($y < $y0) $y0 = $y;
                if ($y > $y1) $y1 = $y;
            }
        }
    }
    if ($x1 >= $x0 && $y1 >= $y0) {
        $pad = 2;
        $cx0 = max(0, $x0 - $pad); $cy0 = max(0, $y0 - $pad);
        $cx1 = min($dw - 1, $x1 + $pad); $cy1 = min($dh - 1, $y1 + $pad);
        $cw = $cx1 - $cx0 + 1; $ch = $cy1 - $cy0 + 1;
        // hanya potong bila bagian kosongnya memang besar (menghindari memotong
        // berkas yang isinya memang penuh)
        if ($cw < $dw || $ch < $dh) {
            $crop = imagecreatetruecolor($cw, $ch);
            imagecopy($crop, $dst, 0, 0, $cx0, $cy0, $cw, $ch);
            imagedestroy($dst);
            $dst = $crop;
            $dw = $cw; $dh = $ch;
        }
    }

    $rgb = '';
    for ($y = 0; $y < $dh; $y++) {
        for ($x = 0; $x < $dw; $x++) {
            $c = imagecolorat($dst, $x, $y);
            $rgb .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
        }
    }
    imagedestroy($dst);
    return ['width' => $dw, 'height' => $dh, 'rgb' => $rgb, 'alpha' => false];
}

function png_scaled_cached(string $path, int $targetH): ?array
{
    if (!is_readable($path)) return null;
    $key = png_cache_path($path, $targetH);
    if ($key !== null && is_readable($key)) {
        $cache = png_cache_parse((string)file_get_contents($key));
        if ($cache !== null) {
            return $cache + ['alpha' => false, 'cached' => true];
        }
    }
    /* Jalur cepat: GD (tersedia di runtime aplikasi) — sub-detik untuk gambar
       besar, dan sudah memotong bagian kosong. */
    $viaGd = png_scaled_gd($path, $targetH);
    if ($viaGd !== null) {
        if ($key !== null) @file_put_contents($key, png_cache_build($viaGd));
        return $viaGd;
    }

    /* Cadangan tanpa GD: dua tahap —
       1. dekode ke ukuran kerja (maks 600 px) sambil melacak kotak isi gambar,
       2. potong bagian kosong lalu kecilkan ke tinggi yang diminta. */
    $workH = 600;
    $data = (string)file_get_contents($path);
    $dim = png_dimensions($data);
    $target = $targetH;
    $work = null;
    if ($dim !== null && $dim['height'] > $workH) {
        $work = png_to_rgb($data, $workH, true);
        if ($work !== null && !empty($work['box'])) {
            $b = $work['box'];
            $cropped = png_crop_rgb($work, $b['x0'], $b['y0'], $b['x1'], $b['y1']);
            // setelah dipotong, kecilkan lagi ke tinggi tujuan
            $work = png_scale_rgb($cropped, null, $target);
        } elseif ($work !== null) {
            $work = png_scale_rgb($work, null, $target);
        }
    } else {
        $work = png_to_rgb($data, $target, true);
        if ($work !== null && !empty($work['box'])) {
            $b = $work['box'];
            $work = png_crop_rgb($work, $b['x0'], $b['y0'], $b['x1'], $b['y1']);
        }
    }
    $png = $work;
    if ($png === null) return null;
    if ($key !== null) @file_put_contents($key, png_cache_build($png));
    return $png;
}

/**
 * Potong bagian tertentu dari data RGB.
 *
 * @param array{width:int,height:int,rgb:string,alpha:bool} $png
 * @return array{width:int,height:int,rgb:string,alpha:bool}
 */
function png_crop_rgb(array $png, int $x0, int $y0, int $x1, int $y1): array
{
    $w = $png['width']; $h = $png['height'];
    $x0 = max(0, min($x0, $w - 1));
    $y0 = max(0, min($y0, $h - 1));
    $x1 = max($x0 + 1, min($x1, $w));
    $y1 = max($y0 + 1, min($y1, $h));
    $nw = $x1 - $x0;
    $nh = $y1 - $y0;
    $src = $png['rgb'];
    $out = '';
    for ($y = $y0; $y < $y1; $y++) {
        $out .= substr($src, ($y * $w + $x0) * 3, $nw * 3);
    }
    return ['width' => $nw, 'height' => $nh, 'rgb' => $out, 'alpha' => $png['alpha']];
}

/**
 * Perkecil data RGB hasil png_to_rgb() dengan rata-rata kotak (box filter).
 * (Dipertahankan untuk pemakaian umum; pembacaan PNG sudah bisa mengecilkan
 * langsung lewat parameter $targetH yang lebih hemat memori.)
 *
 * @param array{width:int,height:int,rgb:string,alpha:bool} $png
 * @return array{width:int,height:int,rgb:string,alpha:bool}
 */
function png_scale_rgb(array $png, ?int $targetW, ?int $targetH): array
{
    $sw = $png['width'];
    $sh = $png['height'];
    if ($targetW === null && $targetH === null) return $png;
    $scale = $targetW !== null ? $targetW / $sw : $targetH / $sh;
    $dw = max(1, (int)round($sw * $scale));
    $dh = max(1, (int)round($sh * $scale));
    if ($dw >= $sw && $dh >= $sh) return $png;

    $src = $png['rgb'];
    $out = '';
    for ($y = 0; $y < $dh; $y++) {
        $y0 = (int)floor($y * $sh / $dh);
        $y1 = max($y0 + 1, (int)floor(($y + 1) * $sh / $dh));
        for ($x = 0; $x < $dw; $x++) {
            $x0 = (int)floor($x * $sw / $dw);
            $x1 = max($x0 + 1, (int)floor(($x + 1) * $sw / $dw));
            $r = $g = $b = $n = 0;
            for ($yy = $y0; $yy < $y1; $yy++) {
                $rowOff = $yy * $sw * 3;
                for ($xx = $x0; $xx < $x1; $xx++) {
                    $i = $rowOff + $xx * 3;
                    $r += ord($src[$i]); $g += ord($src[$i + 1]); $b += ord($src[$i + 2]); $n++;
                }
            }
            if ($n === 0) $n = 1;
            $out .= chr((int)round($r / $n) & 0xFF) . chr((int)round($g / $n) & 0xFF) . chr((int)round($b / $n) & 0xFF);
        }
    }
    return ['width' => $dw, 'height' => $dh, 'rgb' => $out, 'alpha' => $png['alpha']];
}
