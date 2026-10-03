<?php
/**
 * PEMBUAT KODE QR (tanpa library eksternal) + TOTP GOOGLE AUTHENTICATOR.
 * =====================================================================
 *
 * Dipakai halaman Keamanan Login untuk menampilkan QR `otpauth://` yang dipindai
 * aplikasi Google Authenticator (atau aplikasi TOTP lain), beserta verifikasi
 * kodenya.
 *
 * Kode QR ditulis sendiri (mode byte, EC level L/M, versi 1–10) karena:
 *   • server ini tidak punya ekstensi/library QR, dan aplikasi ini sengaja
 *     tanpa dependensi luar;
 *   • hasilnya diverifikasi dengan pembaca QR pihak ketiga (`zbarimg`) pada
 *     skrip uji, jadi tidak sekadar "kelihatannya kotak-kotak".
 *
 * Kunci rahasia TOTP (base32) TIDAK PERNAH dikirim ke peramban selain saat
 * pemilik sendiri melakukan penyiapan (setup) — lihat two_factor.php.
 */
declare(strict_types=1);

/* =====================================================================
 * QR CODE — inti encoder
 * ===================================================================== */

/** Tabel kemampuan (versi 1–10) untuk tingkat koreksi L dan M. */
function qr_tables(): array
{
    /* [versi][ECL] => [ecWordsPerBlock, [jumlahBlok, dataWordsPerBlok], …] */
    return [
        1  => ['L' => [7,  [1, 19]],                                   'M' => [10, [1, 16]]],
        2  => ['L' => [10, [1, 34]],                                   'M' => [16, [1, 28]]],
        3  => ['L' => [15, [1, 55]],                                   'M' => [26, [1, 44]]],
        4  => ['L' => [20, [1, 80]],                                   'M' => [18, [2, 32]]],
        5  => ['L' => [26, [1, 108]],                                  'M' => [24, [2, 43]]],
        6  => ['L' => [18, [2, 68]],                                   'M' => [16, [4, 27]]],
        7  => ['L' => [20, [2, 78]],                                   'M' => [18, [4, 31]]],
        8  => ['L' => [24, [2, 97]],                                   'M' => [22, [2, 38, 2, 39]]],
        9  => ['L' => [30, [2, 116]],                                  'M' => [22, [3, 36, 2, 37]]],
        10 => ['L' => [18, [2, 68, 2, 69]],                            'M' => [26, [4, 43, 1, 44]]],
    ];
}

/** Titik tengah pola perataan (alignment) per versi. */
function qr_align_centers(int $v): array
{
    $map = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];
    return $map[$v] ?? [];
}

/** Ukuran modul (jumlah kotak per sisi) untuk satu versi. */
function qr_size(int $v): int { return 17 + 4 * $v; }

/** Bit tingkat koreksi untuk format info (L=01, M=00). */
function qr_ecl_bits(string $ecl): int { return $ecl === 'L' ? 1 : 0; }

/** Data blob panjang modul sesuai versi & tabel (dipakai encoder). */
function qr_gen_matrix(string $text, string $ecl = 'M'): array
{
    if (!in_array($ecl, ['L', 'M'], true)) $ecl = 'M';
    $bytes = array_values(unpack('C*', $text));
    $len = count($bytes);
    $tables = qr_tables();

    /* 1) Pilih versi terkecil yang muat: mode(4) + jumlah(8 atau 16) + data. */
    $version = 0;
    foreach ($tables as $v => $perEcl) {
        $cntBits = $v <= 9 ? 8 : 16;
        $need = 4 + $cntBits + 8 * $len;
        $dataWords = 0;
        foreach (array_chunk($perEcl[$ecl][1], 2) as $g) $dataWords += $g[0] * $g[1];
        if ($need <= $dataWords * 8) { $version = $v; break; }
    }
    if ($version === 0) throw new RuntimeException('Teks terlalu panjang untuk kode QR versi 1–10.');

    $ecPerBlock = $tables[$version][$ecl][0];
    $groups = array_chunk($tables[$version][$ecl][1], 2);
    $totalData = 0;
    foreach ($groups as $g) $totalData += $g[0] * $g[1];

    /* 2) Aliran bit: mode 0100, panjang, isi, terminator, padding. */
    $bits = '0100';
    $bits .= str_pad(decbin($len), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    $bits .= str_repeat('0', min(4, $totalData * 8 - strlen($bits)));
    while (strlen($bits) % 8 !== 0) $bits .= '0';
    $dataWords = [];
    for ($i = 0; $i < strlen($bits); $i += 8) $dataWords[] = bindec(substr($bits, $i, 8));
    $pad = [0xEC, 0x11];
    $pi = 0;
    while (count($dataWords) < $totalData) { $dataWords[] = $pad[$pi % 2]; $pi++; }

    /* 3) Pecah per blok + hitung error correction (Reed–Solomon GF(256)). */
    $blocks = [];
    $offset = 0;
    foreach ($groups as $g) {
        for ($b = 0; $b < $g[0]; $b++) {
            $blok = array_slice($dataWords, $offset, $g[1]);
            $offset += $g[1];
            $blocks[] = ['data' => $blok, 'ec' => qr_rs($blok, $ecPerBlock)];
        }
    }
    /* 4) Interleave: data semua blok, lalu EC semua blok. */
    $seq = [];
    $maxData = 0;
    foreach ($blocks as $b) $maxData = max($maxData, count($b['data']));
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($blocks as $b) if (isset($b['data'][$i])) $seq[] = $b['data'][$i];
    }
    for ($i = 0; $i < $ecPerBlock; $i++) {
        foreach ($blocks as $b) if (isset($b['ec'][$i])) $seq[] = $b['ec'][$i];
    }

    /* 5) Susun matriks. */
    $size = qr_size($version);
    $m = [];
    $fn = [];                                   // penanda modul fungsi (tidak ikut masking)
    for ($i = 0; $i < $size; $i++) for ($j = 0; $j < $size; $j++) { $m[$i][$j] = 0; $fn[$i][$j] = false; }

    $setFinder = function (int $r, int $c) use (&$m, &$fn): void {
        for ($dr = -1; $dr <= 7; $dr++) {
            for ($dc = -1; $dc <= 7; $dc++) {
                $rr = $r + $dr; $cc = $c + $dc;
                if ($rr < 0 || $cc < 0 || $rr >= count($m) || $cc >= count($m)) continue;
                $dalam = ($dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6);
                $hitam = $dalam && (($dr === 0 || $dr === 6 || $dc === 0 || $dc === 6) || ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4));
                $m[$rr][$cc] = $hitam ? 1 : 0;
                $fn[$rr][$cc] = true;
            }
        }
    };
    $setFinder(0, 0); $setFinder(0, $size - 7); $setFinder($size - 7, 0);

    /* Timing pattern */
    for ($i = 8; $i < $size - 8; $i++) {
        $m[6][$i] = ($i % 2 === 0) ? 1 : 0; $fn[6][$i] = true;
        $m[$i][6] = ($i % 2 === 0) ? 1 : 0; $fn[$i][6] = true;
    }
    /* Alignment pattern */
    $centers = qr_align_centers($version);
    foreach ($centers as $cr) {
        foreach ($centers as $cc) {
            if (($cr === 6 && $cc === 6) || ($cr === 6 && $cc === $size - 7) || ($cr === $size - 7 && $cc === 6)) continue;
            for ($dr = -2; $dr <= 2; $dr++) {
                for ($dc = -2; $dc <= 2; $dc++) {
                    $rr = $cr + $dr; $ccx = $cc + $dc;
                    $hitam = (max(abs($dr), abs($dc)) !== 1);
                    $m[$rr][$ccx] = $hitam ? 1 : 0; $fn[$rr][$ccx] = true;
                }
            }
        }
    }
    /* Modul gelap + area format info (diisi kemudian) */
    $m[$size - 8][8] = 1; $fn[$size - 8][8] = true;
    for ($i = 0; $i <= 8; $i++) {
        if ($i !== 6) { $fn[8][$i] = true; $fn[$i][8] = true; }
    }
    for ($i = 0; $i < 8; $i++) { $fn[8][$size - 1 - $i] = true; $fn[$size - 1 - $i][8] = true; }
    if ($version >= 7) {
        for ($i = 0; $i < 6; $i++) for ($j = 0; $j < 3; $j++) {
            $fn[$size - 11 + $j][$i] = true; $fn[$i][$size - 11 + $j] = true;
        }
    }

    /* 6) Isi data dengan pola zigzag kanan→kiri. */
    $bitIdx = 0;
    $totalBits = count($seq) * 8;
    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) $col--;                 // kolom timing dilewati
        for ($n = 0; $n < $size; $n++) {
            $row = (($col + 1) & 2) === 0 ? $size - 1 - $n : $n;
            foreach ([$col, $col - 1] as $cc) {
                if ($fn[$row][$cc]) continue;
                $bit = 0;
                if ($bitIdx < $totalBits) {
                    $bit = (int)((ord(chr($seq[$bitIdx >> 3])) >> (7 - ($bitIdx & 7))) & 1);
                }
                $m[$row][$cc] = $bit;              // mask diterapkan setelah ini
                $bitIdx++;
            }
        }
    }

    /* 7) Format info: dicoba untuk tiap masker, pilih penalti terkecil. */
    $best = null; $bestScore = PHP_INT_MAX; $bestMask = 0;
    for ($mask = 0; $mask < 8; $mask++) {
        $t = $m;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($fn[$r][$c]) continue;
                if (qr_mask_bit($mask, $r, $c)) $t[$r][$c] ^= 1;
            }
        }
        qr_place_format($t, $ecl, $mask, $size);
        if ($version >= 7) qr_place_version($t, $version, $size);
        $score = qr_penalty($t, $size);
        if ($score < $bestScore) { $bestScore = $score; $best = $t; $bestMask = $mask; }
    }
    return ['matrix' => $best, 'size' => $size, 'version' => $version, 'mask' => $bestMask];
}

/** Rumus masker QR (0–7). */
function qr_mask_bit(int $mask, int $r, int $c): bool
{
    switch ($mask) {
        case 0: return ($r + $c) % 2 === 0;
        case 1: return $r % 2 === 0;
        case 2: return $c % 3 === 0;
        case 3: return ($r + $c) % 3 === 0;
        case 4: return ((intdiv($r, 2) + intdiv($c, 3)) % 2) === 0;
        case 5: return (($r * $c) % 2 + ($r * $c) % 3) === 0;
        case 6: return ((($r * $c) % 2 + ($r * $c) % 3) % 2) === 0;
        default: return ((($r + $c) % 2 + ($r * $c) % 3) % 2) === 0;
    }
}

/** Tulis 15 bit format info (ECL + masker) ke matriks. */
function qr_place_format(array &$m, string $ecl, int $mask, int $size): void
{
    $data = (qr_ecl_bits($ecl) << 3) | $mask;
    $bch = $data << 10;
    $gen = 0x537;
    for ($i = 14; $i >= 10; $i--) {
        if ((($bch >> $i) & 1) !== 0) $bch ^= ($gen << ($i - 10));
    }
    $bits = (($data << 10) | $bch) ^ 0x5412;
    $get = fn(int $i) => ($bits >> $i) & 1;

    /* Urutan bit mengikuti standar: bit TERTINGGI (14) ada di (8,0), dan bit
       terendah (0) di (0,8) / (size-1,8). Urutan terbalik membuat kode QR tidak
       terbaca sama sekali (diverifikasi dengan pembaca QR pihak ketiga). */
    for ($i = 0; $i <= 5; $i++) $m[8][$i] = $get(14 - $i);      // (8,0)…(8,5)
    $m[8][7] = $get(8);
    $m[8][8] = $get(7);
    $m[7][8] = $get(6);
    for ($i = 0; $i <= 5; $i++) $m[5 - $i][8] = $get(5 - $i);   // (5,8)…(0,8)
    /* Salinan kedua: kolom kiri-bawah + baris kanan-atas. */
    for ($i = 0; $i <= 6; $i++) $m[$size - 1 - $i][8] = $get($i);
    for ($i = 7; $i <= 14; $i++) $m[8][$size - 15 + $i] = $get($i);
    $m[$size - 8][8] = 1;                       // modul gelap tetap hitam
}

/** Tulis 18 bit info versi (untuk versi ≥ 7). */
function qr_place_version(array &$m, int $version, int $size): void
{
    $bch = $version << 12;
    $gen = 0x1F25;
    for ($i = 17; $i >= 12; $i--) {
        if ((($bch >> $i) & 1) !== 0) $bch ^= ($gen << ($i - 12));
    }
    $bits = ($version << 12) | $bch;
    for ($i = 0; $i < 18; $i++) {
        $bit = ($bits >> $i) & 1;
        $row = intdiv($i, 3);
        $col = $i % 3;
        $m[$size - 11 + $col][$row] = $bit;
        $m[$row][$size - 11 + $col] = $bit;
    }
}

/** Skor penalti standar (semakin kecil semakin baik). */
function qr_penalty(array $m, int $size): int
{
    $score = 0;
    /* Aturan 1: deretan 5+ modul sama warna. */
    for ($r = 0; $r < $size; $r++) {
        $run = 1;
        for ($c = 1; $c < $size; $c++) {
            if ($m[$r][$c] === $m[$r][$c - 1]) { $run++; }
            else { if ($run >= 5) $score += 3 + ($run - 5); $run = 1; }
        }
        if ($run >= 5) $score += 3 + ($run - 5);
    }
    for ($c = 0; $c < $size; $c++) {
        $run = 1;
        for ($r = 1; $r < $size; $r++) {
            if ($m[$r][$c] === $m[$r - 1][$c]) { $run++; }
            else { if ($run >= 5) $score += 3 + ($run - 5); $run = 1; }
        }
        if ($run >= 5) $score += 3 + ($run - 5);
    }
    /* Aturan 2: blok 2×2 warna sama. */
    for ($r = 0; $r < $size - 1; $r++) {
        for ($c = 0; $c < $size - 1; $c++) {
            $v = $m[$r][$c];
            if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) $score += 3;
        }
    }
    /* Aturan 3: pola mirip finder (1:1:3:1:1). */
    $pat = [1, 0, 1, 1, 1, 0, 1];
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c + 7 <= $size; $c++) {
            $ok = true;
            for ($k = 0; $k < 7; $k++) if ($m[$r][$c + $k] !== $pat[$k]) { $ok = false; break; }
            if ($ok) {
                $sebelum = ($c >= 4);
                $sesudah = ($c + 11 <= $size);
                $matchSblm = true; $matchSsdh = true;
                for ($k = 0; $k < 4; $k++) {
                    if ($sebelum && $m[$r][$c - 4 + $k] !== [1, 0, 1, 0][$k]) $matchSblm = false;
                    if ($sesudah && $m[$r][$c + 7 + $k] !== [0, 1, 0, 1][$k]) $matchSsdh = false;
                }
                if ($sebelum && $matchSblm) $score += 40;
                if ($sesudah && $matchSsdh) $score += 40;
            }
        }
    }
    for ($c = 0; $c < $size; $c++) {
        for ($r = 0; $r + 7 <= $size; $r++) {
            $ok = true;
            for ($k = 0; $k < 7; $k++) if ($m[$r + $k][$c] !== $pat[$k]) { $ok = false; break; }
            if ($ok) {
                $sebelum = ($r >= 4);
                $sesudah = ($r + 11 <= $size);
                $matchSblm = true; $matchSsdh = true;
                for ($k = 0; $k < 4; $k++) {
                    if ($sebelum && $m[$r - 4 + $k][$c] !== [1, 0, 1, 0][$k]) $matchSblm = false;
                    if ($sesudah && $m[$r + 7 + $k][$c] !== [0, 1, 0, 1][$k]) $matchSsdh = false;
                }
                if ($sebelum && $matchSblm) $score += 40;
                if ($sesudah && $matchSsdh) $score += 40;
            }
        }
    }
    /* Aturan 4: keseimbangan gelap/terang. */
    $gelap = 0;
    for ($r = 0; $r < $size; $r++) for ($c = 0; $c < $size; $c++) $gelap += $m[$r][$c];
    $persen = $gelap * 100 / ($size * $size);
    $score += (int)(floor(abs($persen - 50) / 5) * 10);
    return $score;
}

/** Perkalian/penjumlahan GF(256) untuk Reed–Solomon. */
function qr_gf_mul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) return 0;
    $r = 0;
    for ($i = 7; $i >= 0; $i--) {
        if ((($b >> $i) & 1) !== 0) $r ^= ($a << $i);
    }
    for ($i = 14; $i >= 8; $i--) {
        if ((($r >> $i) & 1) !== 0) $r ^= (0x11D << ($i - 8));
    }
    return $r & 0xFF;
}

/** Kodeword koreksi galat (Reed–Solomon) untuk satu blok data. */
function qr_rs(array $data, int $ecLen): array
{
    /* Polinomial generator untuk ecLen kodeword. */
    $gen = [1];
    for ($i = 0; $i < $ecLen; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $k) {
            $next[$j] ^= $k;
            $next[$j + 1] ^= qr_gf_mul($k, qr_gf_pow(2, $i));
        }
        $gen = $next;
    }
    $res = array_merge($data, array_fill(0, $ecLen, 0));
    foreach ($data as $i => $d) {
        $coef = $res[$i];
        if ($coef === 0) continue;
        foreach ($gen as $j => $g) $res[$i + $j] ^= qr_gf_mul($g, $coef);
    }
    return array_slice($res, count($data));
}

/** Pangkat 2^n di GF(256). */
function qr_gf_pow(int $base, int $exp): int
{
    $r = 1;
    for ($i = 0; $i < $exp; $i++) $r = qr_gf_mul($r, $base);
    return $r;
}

/* =====================================================================
 * QR CODE — render (SVG & PNG)
 * ===================================================================== */

/** Kode QR sebagai SVG (tanpa GD). */
function qr_svg(string $text, int $moduleSize = 4, string $ecl = 'M', int $quiet = 4): string
{
    $g = qr_gen_matrix($text, $ecl);
    $size = $g['size'];
    $full = ($size + 2 * $quiet) * $moduleSize;
    $rect = '';
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($g['matrix'][$r][$c] !== 1) continue;
            $x = ($c + $quiet) * $moduleSize;
            $y = ($r + $quiet) * $moduleSize;
            $rect .= '<rect x="' . $x . '" y="' . $y . '" width="' . $moduleSize . '" height="' . $moduleSize . '"/>';
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $full . '" height="' . $full
        . '" viewBox="0 0 ' . $full . ' ' . $full . '" shape-rendering="crispEdges">'
        . '<rect width="100%" height="100%" fill="#fff"/><g fill="#000">' . $rect . '</g></svg>';
}

/** Kode QR sebagai PNG (memerlukan GD, tersedia di runtime aplikasi). */
function qr_png(string $text, int $moduleSize = 6, string $ecl = 'M', int $quiet = 4): ?string
{
    if (!function_exists('imagecreatetruecolor')) return null;
    $g = qr_gen_matrix($text, $ecl);
    $size = $g['size'];
    $full = ($size + 2 * $quiet) * $moduleSize;
    $im = imagecreatetruecolor($full, $full);
    $putih = imagecolorallocate($im, 255, 255, 255);
    $hitam = imagecolorallocate($im, 0, 0, 0);
    imagefilledrectangle($im, 0, 0, $full, $full, $putih);
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($g['matrix'][$r][$c] !== 1) continue;
            $x1 = ($c + $quiet) * $moduleSize;
            $y1 = ($r + $quiet) * $moduleSize;
            imagefilledrectangle($im, $x1, $y1, $x1 + $moduleSize - 1, $y1 + $moduleSize - 1, $hitam);
        }
    }
    ob_start();
    imagepng($im);
    $data = (string)ob_get_clean();
    imagedestroy($im);
    return $data;
}

/* =====================================================================
 * TOTP — Google Authenticator (RFC 6238)
 * ===================================================================== */

/** Kunci acak base32 (20 byte = 32 huruf base32, standar Google Authenticator). */
function totp_secret(int $bytes = 20): string
{
    $raw = '';
    for ($i = 0; $i < $bytes; $i++) $raw .= chr(random_int(0, 255));
    return base32_encode($raw);
}

/** Base32 tanpa padding (alfabet RFC 4648 — dipakai aplikasi TOTP). */
function base32_encode(string $raw): string
{
    $abjad = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($raw) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $part) {
        $part = str_pad($part, 5, '0', STR_PAD_RIGHT);
        $out .= $abjad[bindec($part)];
    }
    return $out;
}

/** Ubah base32 kembali menjadi byte mentah. */
function base32_decode(string $b32): string
{
    $abjad = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $ch) {
        $idx = strpos($abjad, $ch);
        if ($idx === false) continue;
        $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $part) {
        if (strlen($part) < 8) break;
        $out .= chr(bindec($part));
    }
    return $out;
}

/** Kode TOTP 6 angka untuk kunci & waktu tertentu. */
function totp_code(string $secret, ?int $time = null, int $step = 30, int $digits = 6): string
{
    $time = $time ?? time();
    $counter = (int)floor($time / $step);
    $bin = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $bin, base32_decode($secret), true);
    $offset = ord($hash[19]) & 0x0F;
    $part = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    $code = $part % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

/**
 * Verifikasi kode TOTP dengan toleransi ±1 langkah (30 detik) supaya jam yang
 * sedikit berbeda antar perangkat tidak membuat pengguna gagal masuk.
 */
function totp_verify(string $secret, string $input, int $window = 1): bool
{
    $input = preg_replace('/\D/', '', $input);
    if ($secret === '' || strlen($input) !== 6) return false;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $now + ($i * 30)), $input)) return true;
    }
    return false;
}

/** URI otpauth:// yang dipindai aplikasi authenticator. */
function totp_uri(string $secret, string $label, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}
