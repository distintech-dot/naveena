<?php
/**
 * Pengolahan gambar unggahan: kompres otomatis + perkecil ukuran.
 *
 * Runtime aplikasi menyediakan GD (dengan dukungan JPEG & PNG), jadi kompresi
 * dilakukan sungguhan. Bila GD tidak tersedia (mis. saat pengujian di CLI),
 * fungsi tetap berjalan: berkas disimpan apa adanya setelah diperiksa ukurannya
 * — dan pesannya menyatakan hal itu, bukan mengklaim sudah dikompres.
 *
 * Ukuran maksimum diatur dari Pengaturan Sistem supaya bisa disesuaikan:
 *   photo_max_patient  : foto pasien (default 480 px)
 *   photo_max_staff    : foto dokter/terapis (default 480 px)
 *   photo_max_medical  : foto rekam medis (default 1400 px, detail klinis penting)
 *   photo_max_logo     : logo (default 800 px)
 *   photo_quality      : mutu JPEG (default 80)
 */
declare(strict_types=1);

function img_gd(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }
    return $ok;
}

/** Batas ukuran & mutu dari pengaturan, dengan nilai aman bila belum diisi. */
function img_limits(string $kind): array
{
    $map = [
        'patient' => ['dim' => 'photo_max_patient', 'def' => 480,  'max' => 800,  'bytes' => 120 * 1024],
        'staff'   => ['dim' => 'photo_max_staff',   'def' => 480,  'max' => 800,  'bytes' => 120 * 1024],
        'medical' => ['dim' => 'photo_max_medical', 'def' => 1400, 'max' => 2000, 'bytes' => 600 * 1024],
        'logo'    => ['dim' => 'photo_max_logo',    'def' => 800,  'max' => 2000, 'bytes' => 400 * 1024],
        /* Background kartu member: ukuran kartu standar 85,6 × 54 mm @300dpi = 1012 × 638 px.
           `dim` memakai KUNCI setelan (string) — bukan angka — karena img_limits()
           memanggil setting($c['dim']). */
        'membercard' => ['dim' => 'photo_max_membercard', 'def' => 1012, 'max' => 2024, 'bytes' => 500 * 1024],
    ];
    $c = $map[$kind] ?? $map['medical'];
    $dim = (int)setting($c['dim'], (string)$c['def']);
    if ($dim < 80) $dim = $c['def'];
    if ($dim > $c['max']) $dim = $c['max'];
    $q = (int)setting('photo_quality', '80');
    if ($q < 40 || $q > 95) $q = 80;
    return ['dim' => $dim, 'quality' => $q, 'targetBytes' => (int)setting('photo_target_kb', '0') * 1024 ?: $c['bytes']];
}

/**
 * Olah satu berkas gambar unggahan: perbaiki orientasi (EXIF), perkecil, lalu
 * simpan dalam bentuk terkompresi ke $destDir.
 *
 * @return array{file:string,width:int,height:int,bytes:int,mime:string,compressed:bool,saved:int}
 * @throws RuntimeException bila berkas bukan gambar yang didukung atau gagal diolah
 */
function img_process_upload(string $tmpPath, string $origName, string $destDir, string $prefix, string $kind): array
{
    if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
        throw new RuntimeException('Berkas unggahan tidak ditemukan.');
    }
    if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        throw new RuntimeException('Folder penyimpanan foto tidak dapat dibuat.');
    }
    $origBytes = (int)@filesize($tmpPath);
    $lim = img_limits($kind);

    // --- Bila GD tidak ada: simpan apa adanya, tetapi batasi ukuran berkas ---
    if (!img_gd()) {
        $info = @getimagesize($tmpPath);
        if ($info === false) throw new RuntimeException('Berkas "' . $origName . '" bukan gambar yang dikenali.');
        if ($info[0] > $lim['dim'] * 2 || $info[1] > $lim['dim'] * 2) {
            throw new RuntimeException('Gambar ' . $info[0] . '×' . $info[1] . ' px terlalu besar dan server ini tidak dapat '
                . 'mengompresnya otomatis. Mohon perkecil dulu menjadi maksimal ' . $lim['dim'] . ' px.');
        }
        if ($origBytes > 3 * 1024 * 1024) {
            throw new RuntimeException('Ukuran gambar melebihi 3 MB dan kompresi otomatis tidak tersedia di server ini.');
        }
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) ?: 'jpg';
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) $ext = 'jpg';
        $file = $prefix . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!@rename($tmpPath, $destDir . '/' . $file) && !@copy($tmpPath, $destDir . '/' . $file)) {
            throw new RuntimeException('Gagal menyimpan berkas gambar.');
        }
        return ['file' => $file, 'width' => $info[0], 'height' => $info[1], 'bytes' => (int)@filesize($destDir . '/' . $file),
                'mime' => $info['mime'] ?? 'image/jpeg', 'compressed' => false, 'saved' => 0];
    }

    // --- Muat gambar ---
    $data = @file_get_contents($tmpPath);
    if ($data === false) throw new RuntimeException('Berkas gambar tidak dapat dibaca.');
    $src = @imagecreatefromstring($data);
    if ($src === false) throw new RuntimeException('Format gambar "' . $origName . '" tidak dapat diproses.');
    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw < 1 || $sh < 1) {
        imagedestroy($src);
        throw new RuntimeException('Gambar tidak valid.');
    }

    // --- Perbaiki orientasi dari EXIF (foto dari HP sering terbalik) ---
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmpPath);
        $ort = (int)($exif['Orientation'] ?? 0);
        if ($ort > 1) {
            $rot = 0;
            if ($ort === 3) $rot = 180;
            elseif ($ort === 6) $rot = -90;
            elseif ($ort === 8) $rot = 90;
            if ($rot !== 0) {
                $r = @imagerotate($src, $rot, 0);
                if ($r !== false) {
                    imagedestroy($src);
                    $src = $r;
                    $sw = imagesx($src);
                    $sh = imagesy($src);
                }
            }
        }
    }

    // --- Apakah gambar punya transparansi? (PNG/GIF/WebP dengan alpha) ---
    $isPng = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) === 'png';
    $hasAlpha = false;
    if ($isPng && function_exists('imagecolorat')) {
        // periksa beberapa titik: cukup untuk mendeteksi logo transparan
        $probe = [[0, 0], [intdiv($sw, 2), 0], [$sw - 1, $sh - 1], [intdiv($sw, 2), intdiv($sh, 2)]];
        foreach ($probe as [$px, $py]) {
            $c = @imagecolorat($src, $px, $py);
            if ($c !== false && (($c >> 24) & 0x7F) > 0) { $hasAlpha = true; break; }
        }
    }

    // --- Hitung ukuran tujuan (jangan pernah memperbesar) ---
    $dim = $lim['dim'];
    $scale = min(1.0, $dim / max($sw, $sh));
    $dw = max(1, (int)round($sw * $scale));
    $dh = max(1, (int)round($sh * $scale));

    $dst = imagecreatetruecolor($dw, $dh);
    if ($hasAlpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $trans = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefilledrectangle($dst, 0, 0, $dw, $dh, $trans);
    } else {
        // latar putih supaya JPEG tidak menjadi hitam pada area transparan
        imagefilledrectangle($dst, 0, 0, $dw, $dh, imagecolorallocate($dst, 255, 255, 255));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($src);

    // --- Encode & cari ukuran terkecil yang masih memenuhi target ---
    /* LOGO selalu disimpan sebagai PNG, walaupun sumbernya JPEG: logo ditempelkan
       ke struk/laporan PDF oleh penulis PDF sendiri (includes/png.php) yang hanya
       bisa membaca PNG. Kalau logo disimpan sebagai JPEG, logo akan hilang dari
       semua dokumen PDF tanpa pesan apa pun (pernah terjadi: unggahan .jpg). */
    $forcePng = ($kind === 'logo');
    $target = $lim['targetBytes'];
    $quality = $lim['quality'];
    $out = null;
    $ext = ($hasAlpha || $forcePng) ? 'png' : 'jpg';
    $mime = ($hasAlpha || $forcePng) ? 'image/png' : 'image/jpeg';

    if ($hasAlpha || $forcePng) {
        // PNG: atur level kompresi maksimum
        for ($lvl = 9; $lvl >= 6; $lvl--) {
            ob_start();
            imagepng($dst, null, $lvl);
            $out = ob_get_clean();
            if (strlen((string)$out) <= $target || $lvl === 6) break;
        }
        // Bila masih terlalu besar dan tidak butuh transparansi ketat -> JPEG
        // (khusus logo: TIDAK boleh, karena PDF hanya bisa menyisipkan PNG)
        if (!$forcePng && strlen((string)$out) > $target * 2) {
            $flat = imagecreatetruecolor($dw, $dh);
            imagefilledrectangle($flat, 0, 0, $dw, $dh, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $dst, 0, 0, 0, 0, $dw, $dh);
            imagedestroy($dst);
            $dst = $flat;
            $ext = 'jpg';
            $mime = 'image/jpeg';
            $out = null;
        }
    }
    if ($out === null) {
        for ($q = $quality; $q >= 45; $q -= 8) {
            ob_start();
            imagejpeg($dst, null, $q);
            $out = ob_get_clean();
            if (strlen((string)$out) <= $target) break;
        }
        // masih besar -> perkecil lagi dimensinya lalu coba ulang
        $guard = 0;
        while (strlen((string)$out) > $target && $guard++ < 3) {
            $nw = max(120, (int)round($dw * 0.75));
            $nh = max(120, (int)round($dh * 0.75));
            if ($nw >= $dw) break;
            $small = imagecreatetruecolor($nw, $nh);
            imagefilledrectangle($small, 0, 0, $nw, $nh, imagecolorallocate($small, 255, 255, 255));
            imagecopyresampled($small, $dst, 0, 0, 0, 0, $nw, $nh, $dw, $dh);
            imagedestroy($dst);
            $dst = $small;
            $dw = $nw; $dh = $nh;
            ob_start();
            imagejpeg($dst, null, max(45, $quality - 10));
            $out = ob_get_clean();
        }
    }
    imagedestroy($dst);
    if ($out === null || strlen((string)$out) === 0) {
        throw new RuntimeException('Gagal mengompres gambar.');
    }

    $file = $prefix . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (@file_put_contents($destDir . '/' . $file, $out) === false) {
        throw new RuntimeException('Gagal menyimpan gambar terkompresi.');
    }
    $newBytes = (int)strlen((string)$out);
    return [
        'file' => $file, 'width' => $dw, 'height' => $dh, 'bytes' => $newBytes,
        'mime' => $mime, 'compressed' => true,
        'saved' => max(0, $origBytes - $newBytes),
    ];
}

/** Ringkasan hasil kompresi untuk pesan ke pengguna. */
function img_result_text(array $r): string
{
    $kb = fn(int $b) => num(round($b / 1024, 1), 1) . ' KB';
    if (empty($r['compressed'])) {
        return 'disimpan tanpa kompresi (' . $kb((int)$r['bytes']) . ') — server ini tidak menyediakan GD';
    }
    $txt = 'dikompres menjadi ' . $r['width'] . '×' . $r['height'] . ' px, ' . $kb((int)$r['bytes']);
    if (($r['saved'] ?? 0) > 0) $txt .= ' (hemat ' . $kb((int)$r['saved']) . ')';
    return $txt;
}

/* ------------------------------------------------------------------ *
 * Avatar / foto orang (pasien, dokter, terapis)
 * ------------------------------------------------------------------ */
/** Subfolder penyimpanan foto per jenis. */
function photo_dir(string $kind): string
{
    $base = local_upload_dir();
    $sub = ['patient' => 'pasien', 'doctor' => 'dokter', 'therapist' => 'terapis', 'medical' => 'rekam-medis'][$kind] ?? 'lain';
    $dir = $base . '/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/** Tabel & kolom foto per jenis orang. */
function photo_subject(string $kind): ?array
{
    return [
        'patient'   => ['table' => 'patients',   'label' => 'Pasien',   'perm' => 'patient.view',   'store' => 'patient'],
        'doctor'    => ['table' => 'doctors',    'label' => 'Dokter',   'perm' => 'staff.manage',   'store' => 'doctor'],
        'therapist' => ['table' => 'therapists', 'label' => 'Terapis',  'perm' => 'staff.manage',   'store' => 'therapist'],
    ][$kind] ?? null;
}

/** Simpan foto seseorang (dipakai pasien, dokter, terapis). */
function photo_save_person(string $kind, int $id, array $file): array
{
    $sub = photo_subject($kind);
    if (!$sub) throw new RuntimeException('Jenis foto tidak dikenal.');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Gagal mengunggah berkas foto (kode ' . (int)($file['error'] ?? -1) . ').');
    }
    $row = one('SELECT * FROM ' . $sub['table'] . ' WHERE id = ?', [$id]);
    if (!$row) throw new RuntimeException($sub['label'] . ' tidak ditemukan.');
    if (isset($row['branch_id'])) assert_branch((int)$row['branch_id']);

    $kindStore = $kind === 'patient' ? 'patient' : $sub['store'];
    $res = img_process_upload((string)$file['tmp_name'], (string)$file['name'],
        photo_dir($kindStore), $sub['store'] . $id, $kind === 'patient' ? 'patient' : 'staff');

    // hapus foto lama
    if (!empty($row['photo_file'])) {
        $old = photo_dir($kindStore) . '/' . basename((string)$row['photo_file']);
        if (is_file($old)) @unlink($old);
    }
    q('UPDATE ' . $sub['table'] . ' SET photo_file = ?, photo_updated_at = datetime("now","localtime") WHERE id = ?',
        [$res['file'], $id]);
    audit('Unggah Foto ' . $sub['label'], $sub['label'], $id, null,
        ['file' => $res['file'], 'ukuran' => $res['bytes'], 'dimensi' => $res['width'] . 'x' . $res['height']],
        'Foto ' . strtolower($sub['label']) . ' ' . img_result_text($res));
    return $res;
}

/** URL foto seseorang (auth-gated) atau '' bila belum ada. */
function photo_url(string $kind, array $row): string
{
    if (empty($row['photo_file'])) return '';
    $id = (int)($row['id'] ?? 0);
    $v = substr(md5((string)$row['photo_file'] . (string)($row['photo_updated_at'] ?? '')), 0, 6);
    return 'photo.php?t=' . urlencode($kind) . '&id=' . $id . '&v=' . $v;
}

/** Tampilkan foto (bila ada) atau inisial nama sebagai cadangan. */
function person_avatar(string $kind, array $row, int $size = 40): string
{
    $src = photo_url($kind, $row);
    $name = (string)($row['name'] ?? '?');
    $st = 'width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;object-fit:cover;flex:0 0 ' . $size . 'px';
    if ($src !== '') {
        return '<img class="avatar-img" src="' . e($src) . '" alt="' . e($name) . '" style="' . $st . '">';
    }
    $ini = strtoupper(substr(trim($name) !== '' ? trim($name) : '?', 0, 1));
    return '<span class="avatar" style="' . $st . ';font-size:' . max(11, (int)($size * 0.42)) . 'px">' . e($ini) . '</span>';
}

/* ------------------------------------------------------------------ *
 * FOTO UNTUK DOKUMEN (Excel / PDF)
 * ------------------------------------------------------------------ */
/**
 * Ubah berkas foto menjadi PNG kecil yang bisa ditempelkan ke dokumen Excel.
 *
 * Pembuat XLSX aplikasi hanya menyimpan gambar sebagai PNG, sedangkan foto
 * pasien/lampiran klinis umumnya JPEG — karena itu di sini dikonversi (dan
 * dikecilkan sekaligus supaya berkas Excel tidak membengkak). Tanpa GD,
 * hanya berkas PNG yang bisa dipakai apa adanya.
 *
 * @return array{png:string,w:int,h:int}|null null bila foto tidak dapat dibaca
 */
function photo_png_bytes(string $path, int $maxW = 420, int $maxH = 420): ?array
{
    if (!is_readable($path)) return null;
    $data = (string)file_get_contents($path);
    if ($data === '') return null;

    if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
        $src = @imagecreatefromstring($data);
        if ($src !== false) {
            $w = imagesx($src); $h = imagesy($src);
            if ($w <= 0 || $h <= 0) { imagedestroy($src); return null; }
            $scale = min(1.0, $maxW / $w, $maxH / $h);
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);   // latar putih (PNG transparan)
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagepng($dst, null, 6);
            $png = (string)ob_get_clean();
            imagedestroy($dst);
            imagedestroy($src);
            if ($png !== '') return ['png' => $png, 'w' => $nw, 'h' => $nh];
            return null;
        }
    }
    /* Tanpa GD: hanya PNG yang bisa dipakai langsung (dan itu pun bila kecil). */
    if (strlen($data) <= 3 * 1024 * 1024 && substr($data, 0, 8) === "\x89PNG\r\n\x1a\n") {
        /* getimagesizefromstring() tersedia di core PHP; png_dimensions() dipakai
           sebagai cadangan bila berkasnya belum dimuat. */
        $dim = function_exists('getimagesizefromstring') ? @getimagesizefromstring($data) : null;
        if ($dim && !empty($dim[0]) && !empty($dim[1])) {
            return ['png' => $data, 'w' => (int)$dim[0], 'h' => (int)$dim[1]];
        }
        if (function_exists('png_dimensions')) {
            $d2 = png_dimensions($data);
            if ($d2 !== null) return ['png' => $data, 'w' => (int)$d2['width'], 'h' => (int)$d2['height']];
        }
    }
    return null;
}
