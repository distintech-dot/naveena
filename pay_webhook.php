<?php
/**
 * NOTIFIKASI PEMBAYARAN dari payment gateway (Midtrans / Xendit).
 *
 * Halaman ini dipanggil OLEH GATEWAY (bukan pengguna), jadi tidak memakai sesi
 * maupun CSRF. Yang boleh dilakukannya hanya satu: menandai SATU permintaan
 * pembayaran (berdasarkan referensi yang kita buat sendiri) sebagai LUNAS lalu
 * membuat transaksinya lewat fungsi yang sama dengan kasir (order_create()).
 *
 * Daftarkan URL ini di dashboard gateway:
 *   <?= '(lihat Pengaturan → Pembayaran)' ?>
 *
 * Balasan selalu JSON singkat supaya mudah ditelusuri di log gateway.
 */
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json');

$raw = (string)file_get_contents('php://input');
$json = json_decode($raw, true);
if (!is_array($json)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Body bukan JSON.']);
    exit;
}

/* Ambil referensi milik kita: Midtrans memakai "order_id", Xendit "external_id"
   (atau id QR-nya untuk notifikasi QR). */
$ref = '';
$gatewayRef = '';
$paid = false;
$status = '';

if (isset($json['order_id'])) {                       // Midtrans
    $ref = (string)$json['order_id'];
    $gatewayRef = (string)($json['transaction_id'] ?? '');
    $status = strtolower((string)($json['transaction_status'] ?? ''));
    $paid = in_array($status, ['settlement', 'capture'], true);
} elseif (isset($json['external_id'])) {              // Xendit
    $ref = (string)$json['external_id'];
    $gatewayRef = (string)($json['id'] ?? '');
    $status = strtoupper((string)($json['status'] ?? ''));
    $paid = in_array($status, ['SUCCEEDED', 'COMPLETED'], true);
} elseif (isset($json['qr_code'])) {                  // Xendit (notifikasi QR)
    $gatewayRef = (string)($json['qr_code']['id'] ?? '');
    $status = strtoupper((string)($json['qr_code']['status'] ?? ''));
    $paid = in_array($status, ['SUCCEEDED', 'COMPLETED'], true);
}

if ($ref === '' && $gatewayRef !== '') {
    /* Referensi tidak ikut dikirim: cari berdasarkan id gateway yang tersimpan. */
    $row = one('SELECT ref FROM pay_pending WHERE gateway_ref = ? ORDER BY id DESC LIMIT 1', [$gatewayRef]);
    $ref = (string)($row['ref'] ?? '');
}

if ($ref === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Referensi pembayaran tidak dikenali.']);
    exit;
}

$p = pay_pending_by_ref($ref);
if (!$p) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Permintaan pembayaran tidak ditemukan: ' . $ref]);
    exit;
}

/* Simpan notifikasi apa adanya untuk penelusuran (tidak pernah disembunyikan). */
q('UPDATE pay_pending SET raw = ? WHERE id = ?',
    [json_encode(['notifikasi' => $json], JSON_UNESCAPED_UNICODE), (int)$p['id']]);

if (!$paid) {
    /* Belum lunas (mis. expired/pending) — hanya dicatat. */
    q('UPDATE pay_pending SET status = CASE WHEN status = "pending" THEN ? ELSE status END WHERE id = ?',
        [strtolower($status !== '' ? $status : 'pending'), (int)$p['id']]);
    echo json_encode(['ok' => true, 'ref' => $ref, 'status' => $status, 'paid' => false,
        'note' => 'Notifikasi diterima; pembayaran belum lunas sehingga transaksi belum dibuat.']);
    exit;
}

$res = pay_pending_mark_paid($ref, 'webhook ' . $status);
if (!$res['ok']) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'ref' => $ref, 'error' => $res['error']]);
    exit;
}
echo json_encode(['ok' => true, 'ref' => $ref, 'paid' => true, 'invoice' => $res['invoice'],
    'order_id' => $res['order_id']]);
