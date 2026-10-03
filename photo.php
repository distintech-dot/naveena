<?php
/**
 * Menyajikan foto pasien / dokter / terapis.
 *
 * Foto adalah data privat, jadi WAJIB login dan dibatasi cabang (pasien dan
 * tenaga medis terikat cabang). Berkas disimpan di luar folder publik sehingga
 * tidak bisa diakses langsung lewat URL.
 */
require_once __DIR__ . '/includes/config.php';

$kind = (string)($_GET['t'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$sub = photo_subject($kind);
if (!$sub || $id <= 0) {
    http_response_code(404);
    exit('Foto tidak ditemukan.');
}
// Wajib login (halaman login sendiri tidak memakai foto orang).
$user = current_user();
if (!$user) {
    http_response_code(403);
    exit('Silakan masuk terlebih dahulu.');
}
$row = one('SELECT * FROM ' . $sub['table'] . ' WHERE id = ?', [$id]);
if (!$row || empty($row['photo_file'])) {
    http_response_code(404);
    exit('Foto belum tersedia.');
}
if (isset($row['branch_id'])) {
    $bid = (int)$row['branch_id'];
    $mine = user_branch();
    if ($mine !== null && $mine !== $bid) {
        deny('Anda tidak memiliki akses ke data cabang ini.');
    }
}
$store = $kind === 'patient' ? 'patient' : $sub['store'];
$base = basename((string)$row['photo_file']);
if ($base !== (string)$row['photo_file'] || !preg_match('/^[A-Za-z0-9._-]{1,120}$/', $base)) {
    http_response_code(400);
    exit('Nama berkas foto tidak valid.');
}
$path = photo_dir($store) . '/' . $base;
$real = realpath($path);
$root = realpath(photo_dir($store));
if ($real === false || $root === false || strpos($real, $root) !== 0 || !is_readable($real)) {
    http_response_code(404);
    exit('Berkas foto tidak ditemukan.');
}
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, max-age=600');
header('X-Content-Type-Options: nosniff');
readfile($real);
