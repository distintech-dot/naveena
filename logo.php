<?php
/**
 * Menyajikan logo klinik yang diunggah Super Admin.
 *
 * Sengaja TIDAK memerlukan login: logo dipakai juga di halaman login dan pada
 * dokumen cetak. Yang disajikan hanya berkas logo yang memang terdaftar di
 * pengaturan (nama berkas divalidasi ketat), tidak pernah path sembarang.
 */
require_once __DIR__ . '/includes/config.php';

$file = (string)setting('logo_file');
$url  = (string)setting('logo_url');

/* Salinan lokal diutamakan (cepat, satu domain, tetap jalan walau CDN bermasalah);
   alihkan ke cadangan media hanya bila salinan lokal tidak tersedia. */
if ($file === '' && $url !== '' && preg_match('#^https?://#', $url)) {
    header('Location: ' . $url, true, 302);
    exit;
}
if ($file === '' || $file === null) {
    http_response_code(404);
    exit('Logo belum diunggah.');
}
// Nama berkas harus sederhana: huruf/angka/titik/strip/garis bawah saja.
$base = basename((string)$file);
if ($base !== (string)$file || !preg_match('/^[A-Za-z0-9._-]{1,120}$/', $base)) {
    http_response_code(400);
    exit('Nama berkas logo tidak valid.');
}
$path = local_upload_dir() . '/' . $base;
$real = realpath($path);
$root = realpath(local_upload_dir());
if ($real === false || $root === false || strpos($real, $root) !== 0 || !is_readable($real)) {
    http_response_code(404);
    exit('Berkas logo tidak ditemukan.');
}
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
          'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, max-age=300');
// SVG dari admin bisa memuat skrip -> jangan izinkan dieksekusi sebagai HTML.
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
readfile($real);
