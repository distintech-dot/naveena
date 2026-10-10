<?php
/**
 * FAVICON KLINIK (permintaan pemilik)
 * ===================================
 * Ikon kecil di tab peramban / bookmark / ikon aplikasi saat dipasang di HP.
 *
 * Aturan yang ditegakkan di sini:
 *   1. Format yang diterima: **.ico**, **.png**, dan **.svg** — tiga format yang
 *      benar-benar dipakai peramban untuk favicon (JPG tidak didukung favicon).
 *   2. Ukuran berkas maksimal **15 KB** (batas di `favicon_max_kb()`); berkas yang
 *      lebih besar DITOLAK dengan pesan yang menyebut batasnya, bukan dipotong
 *      diam-diam.
 *   3. Isi berkas diperiksa lewat tanda-tanda (magic bytes) formatnya, supaya
 *      berkas yang hanya berganti nama tidak bisa lolos.
 *   4. Berkas disimpan di folder unggahan aplikasi (DI LUAR folder yang disajikan
 *      publik) dan disajikan lewat `favicon.php` — nama berkas wajib terdaftar di
 *      pengaturan, jadi tidak mungkin menyajikan berkas lain.
 *   5. Bila belum diunggah, aplikasi memakai ikon bawaan (`assets/img/favicon.png`)
 *      sehingga tab peramban tidak pernah kosong.
 */
declare(strict_types=1);

/** Batas ukuran berkas favicon (KB) — angka tunggal, dipakai server & keterangan. */
function favicon_max_kb(): int
{
    return 15;
}

/** Ekstensi yang diterima (juga dipakai atribut `accept` pada kolom unggahan). */
function favicon_exts(): array
{
    return ['ico', 'png', 'svg'];
}

/** Jenis MIME per ekstensi. */
function favicon_mime(string $ext): string
{
    $ext = strtolower($ext);
    return $ext === 'ico' ? 'image/x-icon' : ($ext === 'svg' ? 'image/svg+xml' : 'image/png');
}

/** Nama berkas favicon yang tersimpan di pengaturan ('' bila belum ada). */
function favicon_file_name(): string
{
    $f = (string)setting('favicon_file');
    if ($f === '') return '';
    $base = basename($f);
    return ($base === $f && preg_match('/^[A-Za-z0-9._-]{1,120}$/', $base)) ? $base : '';
}

/** Path lengkap favicon yang diunggah ('' bila tidak ada / tidak terbaca). */
function favicon_path(): string
{
    $base = favicon_file_name();
    if ($base === '') return '';
    $p = local_upload_dir() . '/' . $base;
    return is_readable($p) ? $p : '';
}

/**
 * URL RELATIF favicon yang dipakai di HTML (tanpa garis miring awal supaya benar
 * di alamat aplikasi berbasis sub-folder). Diambil dari berkas unggahan bila ada,
 * kalau tidak dari ikon bawaan aplikasi.
 */
function favicon_url(): string
{
    if (favicon_path() !== '') {
        return 'favicon.php?v=' . substr(md5((string)setting('favicon_file')), 0, 6);
    }
    return 'assets/img/favicon.png';
}

/** Tag <link rel="icon"> untuk disisipkan ke bagian <head> setiap halaman. */
function favicon_link_tag(): string
{
    $path = favicon_path();
    $ext = $path !== '' ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : 'png';
    $mime = favicon_mime($ext);
    $out = '<link rel="icon" href="' . e(favicon_url()) . '" type="' . e($mime) . '">';
    /* Safari/iOS memakai apple-touch-icon; ikon bawaan berupa PNG. */
    if ($ext !== 'ico') {
        $out .= '<link rel="apple-touch-icon" href="' . e(favicon_url()) . '">';
    }
    return $out;
}

/**
 * Periksa isi berkas favicon terhadap formatnya (magic bytes).
 * Mengembalikan pesan kesalahan, atau '' bila isinya sesuai.
 */
function favicon_content_error(string $path, string $ext): string
{
    $head = (string)@file_get_contents($path, false, null, 0, 512);
    $ext = strtolower($ext);
    if ($ext === 'png') {
        if (substr($head, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return 'Berkas .png yang diunggah bukan gambar PNG yang sah (isi berkasnya tidak dikenali).';
        }
        return '';
    }
    if ($ext === 'ico') {
        /* ICO: 00 00 01 00 (icon) atau 00 00 02 00 (cursor). */
        if (substr($head, 0, 4) !== "\x00\x00\x01\x00") {
            return 'Berkas .ico yang diunggah isinya bukan ikon ICO yang sah.';
        }
        return '';
    }
    if ($ext === 'svg') {
        $t = ltrim(substr($head, 0, 512), "\xEF\xBB\xBF \t\r\n");
        if (stripos($t, '<svg') === false && stripos($t, '<?xml') === false) {
            return 'Berkas .svg yang diunggah isinya bukan gambar SVG yang sah.';
        }
        return '';
    }
    return 'Format favicon harus .ico, .png, atau .svg.';
}

/**
 * Simpan favicon yang diunggah dan kembalikan nama berkasnya.
 *
 * @return array{file:string,bytes:int,ext:string}
 */
function favicon_store(string $tmpPath, string $origName): array
{
    if (!is_file($tmpPath)) throw new RuntimeException('Berkas favicon tidak ditemukan.');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, favicon_exts(), true)) {
        throw new RuntimeException('Format favicon harus .ico, .png, atau .svg (format .jpg/.jpeg tidak dipakai peramban untuk favicon).');
    }
    $bytes = (int)@filesize($tmpPath);
    $max = favicon_max_kb() * 1024;
    if ($bytes <= 0) throw new RuntimeException('Berkas favicon kosong.');
    if ($bytes > $max) {
        throw new RuntimeException('Ukuran favicon melebihi ' . num(favicon_max_kb()) . ' KB — berkas yang diunggah '
            . num((int)round($bytes / 1024), 0) . ' KB. Mohon perkecil berkasnya (favicon hanya tampil kecil, '
            . '64×64 px sudah cukup) lalu unggah kembali.');
    }
    $err = favicon_content_error($tmpPath, $ext);
    if ($err !== '') throw new RuntimeException($err);
    $dir = local_upload_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Folder penyimpanan tidak dapat dibuat.');
    }
    $file = 'favicon-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!@move_uploaded_file($tmpPath, $dir . '/' . $file) && !@copy($tmpPath, $dir . '/' . $file)) {
        throw new RuntimeException('Gagal menyimpan berkas favicon.');
    }
    @chmod($dir . '/' . $file, 0644);
    return ['file' => $file, 'bytes' => (int)@filesize($dir . '/' . $file), 'ext' => $ext];
}

/** Nama berkas ikon bawaan aplikasi (dipakai bila belum ada unggahan). */
function favicon_builtin_path(): string
{
    $p = APP_DIR . '/assets/img/favicon.png';
    return is_readable($p) ? $p : APP_DIR . '/assets/img/logo-naveena.png';
}
