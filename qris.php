<?php
/**
 * Menyajikan GAMBAR QRIS klinik (diunggah di Pengaturan → Pembayaran).
 *
 * Dipakai pada layar kasir (langkah Pembayaran) dan halaman pembayaran pasien,
 * jadi tidak memerlukan login — sama seperti logo klinik. Yang disajikan hanya
 * berkas yang namanya memang terdaftar pada pengaturan (nama divalidasi ketat),
 * sehingga halaman ini tidak bisa dipakai membaca berkas lain di server.
 */
require_once __DIR__ . '/includes/config.php';

$file = (string)setting('pay_qris_file');
if ($file === '') {
    http_response_code(404);
    exit('Gambar QRIS belum diunggah.');
}
$base = basename($file);
if ($base !== $file || !preg_match('/^[A-Za-z0-9._-]{1,120}$/', $base)) {
    http_response_code(400);
    exit('Nama berkas QRIS tidak valid.');
}
$path = local_upload_dir() . '/' . $base;
if (!is_readable($path)) {
    http_response_code(404);
    exit('Berkas QRIS tidak ditemukan.');
}
$ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
$mimeMap = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . (string)filesize($path));
readfile($path);
