<?php
/** Auth-gated streaming of medical record photos (never expose the raw storage URL). */
require_once __DIR__ . '/includes/config.php';
require_perm('medical.view');

$id = (int)($_GET['id'] ?? 0);
$ph = one('SELECT ph.*, m.branch_id FROM medical_record_photos ph JOIN medical_records m ON m.id = ph.medical_record_id WHERE ph.id = ?', [$id]);
if (!$ph) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}
assert_branch((int)$ph['branch_id']);

$mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf'];
$ext = strtolower(pathinfo((string)($ph['local_path'] ?: $ph['file_url']), PATHINFO_EXTENSION));
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

if ($ph['storage'] === 'local' && $ph['local_path']) {
    /* Foto/lampiran kini disimpan di subfolder (rekam-medis/), dengan cadangan
       di folder lama agar berkas yang sudah ada tetap terbaca. */
    $candidates = [
        photo_dir('medical') . '/' . basename($ph['local_path']),
        local_upload_dir() . '/' . basename($ph['local_path']),
    ];
    $path = '';
    foreach ($candidates as $c) { if (is_readable($c)) { $path = $c; break; } }
    if ($path === '') {
        http_response_code(404);
        exit('File tidak tersedia.');
    }
    if (!is_readable($path)) {
        http_response_code(404);
        exit('File tidak tersedia.');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=600');
    readfile($path);
    exit;
}

$url = (string)$ph['file_url'];
if ($url === '' || !preg_match('#^https?://#', $url)) {
    http_response_code(404);
    exit('File tidak tersedia.');
}
$data = false;
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
    $data = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) $data = false;
} else {
    $ctx = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]);
    $data = @file_get_contents($url, false, $ctx);
}
if ($data === false) {
    http_response_code(502);
    exit('Gagal mengambil file dari storage.');
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($data));
header('Cache-Control: private, max-age=600');
echo $data;
