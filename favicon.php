<?php
/**
 * Menyajikan FAVICON KLINIK yang diunggah Super Admin.
 *
 * Sengaja TIDAK memerlukan login (dipakai juga di halaman login & tab peramban).
 * Yang disajikan HANYA berkas favicon yang namanya terdaftar di pengaturan;
 * nama berkas divalidasi ketat sehingga tidak mungkin menyajikan berkas lain.
 */
require_once __DIR__ . '/includes/config.php';

$path = favicon_path();
if ($path === '') {
    /* Belum ada unggahan → pakai ikon bawaan aplikasi supaya tab peramban
       tetap berikon (bukan berkas kosong/404). */
    $b = favicon_builtin_path();
    if (!is_readable($b)) { http_response_code(404); exit('Favicon belum diunggah.'); }
    $ext = strtolower(pathinfo($b, PATHINFO_EXTENSION));
    header('Content-Type: ' . favicon_mime($ext));
    header('Content-Length: ' . (string)filesize($b));
    header('Cache-Control: public, max-age=86400');
    readfile($b);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . favicon_mime($ext));
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: public, max-age=86400');
/* SVG dari admin bisa memuat skrip → jangan izinkan dieksekusi sebagai HTML. */
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
readfile($path);
