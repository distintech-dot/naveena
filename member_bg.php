<?php
/**
 * Menyajikan background kartu member (gambar di luar folder publik).
 * Wajib login — hanya untuk user yang berhak (dipakai pratinjau di Pengaturan).
 */
require_once __DIR__ . '/includes/config.php';
require_perm('settings.manage');

$path = member_card_bg_path();
if ($path === '') {
    http_response_code(404);
    exit('Background belum diunggah.');
}
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
         'gif' => 'image/gif', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
readfile($path);
