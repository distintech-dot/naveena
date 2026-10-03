<?php
/**
 * Endpoint AJAX: siapkan struk PDF lalu kirim ke WhatsApp pasien.
 *
 * - PDF struk dibuat server-side (PDF asli, bukan tautan cetak browser).
 * - Bila WhatsApp API klinik dikonfigurasi: pesan dikirim otomatis (berisi tautan
 *   struk bila diaktifkan) dan statusnya dilaporkan apa adanya.
 * - Bila belum dikonfigurasi: pesan + tautan disiapkan, lalu browser membuka
 *   WhatsApp (wa.me) memakai nomor Naveena yang login di perangkat tersebut.
 *   WhatsApp tidak mengizinkan lampiran dokumen lewat tautan — ini keterbatasan
 *   platform, dan sistem tidak mengklaim sebaliknya.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';    // wa_api_send(), wa_api_configured()
require_once __DIR__ . '/includes/receipt.php';

header('Content-Type: application/json');
$user = require_login();
require_perm('order.view');
verify_csrf();

function json_out(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $id = (int)($_POST['id'] ?? 0);
    $phone = trim((string)($_POST['phone'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $wantLink = ($_POST['link'] ?? '1') === '1';

    $o = receipt_data($id);
    if (!$o) throw new RuntimeException('Transaksi tidak ditemukan.');
    assert_branch((int)$o['branch_id']);

    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) < 8) throw new RuntimeException('Nomor WhatsApp pasien tidak valid.');
    if ($message === '') $message = wa_receipt_template($o);

    /* ---- 1. buat PDF struk ---- */
    $pdf = receipt_pdf($o, false);

    /* ---- 2. tautan publik PDF (opsional) ----
     * Diunggah ke penyimpanan media platform. URL bersifat acak-panjang namun
     * dapat dibuka siapa pun yang memiliki tautannya, jadi hanya disertakan bila
     * petugas mencentangnya. Bila gagal, pengiriman tetap jalan tanpa tautan. */
    $pdfLink = '';
    $pdfError = '';
    if ($wantLink) {
        if (trim((string)$o['receipt_url']) !== '') {
            $pdfLink = (string)$o['receipt_url'];      // sudah pernah dibuat, pakai ulang
        } else {
            try {
                $pdfLink = media_upload_bytes($pdf['bytes'], $pdf['filename']);
                q('UPDATE orders SET receipt_url = ? WHERE id = ?', [$pdfLink, $id]);
            } catch (Throwable $ex) {
                $pdfError = $ex->getMessage();
            }
        }
    }

    /* ---- 3. susun pesan final ---- */
    $finalMsg = wa_receipt_finalize($message, $pdfLink);
    if ($finalMsg === '') throw new RuntimeException('Isi pesan WhatsApp tidak boleh kosong.');

    /* ---- 4. kirim ---- */
    $sender = wa_sender_number();
    $sent = false;
    $sendError = '';
    if (wa_api_configured()) {
        $sent = wa_api_send($phone, $finalMsg, $sendError);
    }

    $via = wa_api_configured() ? 'WhatsApp API' : 'WhatsApp (wa.me, nomor ' . ($sender !== '' ? $sender : 'belum diatur') . ')';
    /* Hanya tandai "terkirim" bila server benar-benar mengirimkannya. Mode deep link
       hanya MENYIAPKAN pesan — dicatat sebagai 'prepared' supaya tidak menyesatkan. */
    q('UPDATE orders SET receipt_sent_at = datetime("now","localtime"), receipt_sent_to = ?, receipt_sent_via = ?, receipt_status = ? WHERE id = ?',
        [$phone, $via, $sent ? 'sent' : 'prepared', $id]);

    audit($sent ? 'Kirim Struk WhatsApp' : 'Siapkan Struk WhatsApp', 'Kasir', $id, null, [
        'invoice' => $o['invoice_number'], 'tujuan' => $phone, 'tautan_pdf' => $pdfLink !== '',
        'cara' => $via, 'terkirim' => $sent,
    ], $sent ? 'Struk dikirim otomatis via WhatsApp API' : 'Struk disiapkan untuk dikirim dari aplikasi WhatsApp');

    json_out([
        'ok' => true,
        'sent' => $sent,
        'phone' => $phone,
        'sender' => $sender,
        'pdf_link' => $pdfLink,
        'pdf_error' => $pdfError,
        'pdf_name' => $pdf['filename'],
        'wa_link' => $sent ? '' : wa_link($phone, $finalMsg),
        'status_text' => wa_receipt_status_text(),
        'error' => $sendError,
    ]);
} catch (Throwable $ex) {
    json_out(['ok' => false, 'error' => $ex->getMessage()], 400);
}

/* ------------------------------------------------------------------ *
 * Unggah byte langsung ke penyimpanan media platform (kontrak proxy).
 * ------------------------------------------------------------------ */
function media_upload_bytes(string $bytes, string $filename): string
{
    $token = media_token();
    if ($token === '') {
        throw new RuntimeException('Penyimpanan media belum dikonfigurasi (token tidak ditemukan). '
            . 'Matikan opsi tautan PDF untuk mengirim tanpa tautan, atau hubungi Super Admin.');
    }
    $url = 'http://127.0.0.1:4310/api/app-media/upload?filename=' . rawurlencode($filename);
    $code = 0;
    $res = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $bytes,
            CURLOPT_HTTPHEADER => ["X-App-Media-Token: {$token}", 'Content-Type: application/octet-stream'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException('Gagal menghubungi penyimpanan media: ' . $err); }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "X-App-Media-Token: {$token}\r\nContent-Type: application/octet-stream\r\n",
            'content' => $bytes, 'timeout' => 60, 'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
    }
    $json = json_decode((string)$res, true);
    if ($code !== 200 || !is_array($json) || empty($json['ok']) || empty($json['url'])) {
        $msg = is_array($json) ? (string)($json['error'] ?? $json['message'] ?? '') : '';
        throw new RuntimeException($msg !== '' ? $msg : 'Unggah struk PDF gagal (HTTP ' . $code . ').');
    }
    return (string)$json['url'];
}
