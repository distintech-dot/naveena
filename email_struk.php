<?php
/**
 * Kirim struk transaksi lewat EMAIL ke pasien (server-side).
 *
 * Dipakai tombol "Email" pada detail transaksi (setelah kasir selesai input
 * order) dan pada daftar Riwayat Order. Subjek & isi pesan diisi otomatis dari
 * Pengaturan Sistem dan boleh diubah petugas sebelum dikirim.
 *
 * Mode:
 *   GET  ?id=<order>              → kembalikan JSON (subjek & isi otomatis + email pasien)
 *   POST  id, to, subject, body   → kirim sungguhan; hasilnya dilaporkan apa adanya
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/receipt.php';
require_perm('order.view');
$user = current_user();

$id = (int)(gp('id') ?: ($_POST['id'] ?? 0));
$o = receipt_data($id);
if (!$o) {
    if (is_ajax()) { http_response_code(404); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Transaksi tidak ditemukan.']); exit; }
    flash('Transaksi tidak ditemukan.', 'error');
    header('Location: order.php');
    exit;
}
assert_branch((int)$o['branch_id']);

$patient = one('SELECT id, name, email FROM patients WHERE id = ?', [(int)$o['patient_id']]);

/* ---- Permintaan data awal (subjek & isi otomatis) ---- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'invoice' => (string)$o['invoice_number'],
        'patient' => (string)$o['patient_name'],
        'email' => (string)($patient['email'] ?? ''),
        'subject' => receipt_email_subject($o),
        'body' => receipt_email_body($o),
        'configured' => mail_configured(),
        'status' => mail_status_text(),
        'link' => receipt_public_link($o),
        'auto' => setting('email_receipt_auto') === '1',
        /* Riwayat pengiriman struk ini → halaman menampilkan peringatan bila
           sudah pernah dikirim (mencegah kirim dobel karena klik berulang). */
        'sent_count' => email_send_history('struk ' . (string)$o['invoice_number'])['count'],
        'resend_notice' => email_resend_notice('struk ' . (string)$o['invoice_number'], 'Struk ini')['notice'],
        'pdf' => 'struk-' . preg_replace('/[^A-Za-z0-9\-]/', '-', (string)$o['invoice_number']) . '.pdf',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- Kirim email ---- */
verify_csrf();
if (!has_perm('order.manage')) deny('Anda tidak memiliki izin mengirim email transaksi.');
header('Content-Type: application/json');
$to = trim((string)($_POST['to'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? ''));
/* PENGAMAN KIRIM ULANG: pemeriksaan di SERVER (bukan hanya di peramban) supaya
   klik ganda tetap tidak menghasilkan dua email. */
$resend = email_resend_notice('struk ' . (string)$o['invoice_number'], 'Struk ini');
if ($resend['blocked']) {
    echo json_encode(['ok' => false, 'error' => $resend['block_msg']], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($resend['needs'] && (string)($_POST['confirm_resend'] ?? '') !== '1') {
    echo json_encode(['ok' => false, 'needs_confirm' => true, 'sent_count' => $resend['count'],
        'notice' => $resend['notice']], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $res = send_receipt_email($o, $to, $subject);
    /* Simpan email pasien bila belum ada supaya pengiriman berikutnya otomatis terisi. */
    if ($res['ok'] && $to !== '' && trim((string)($patient['email'] ?? '')) === '' && $patient) {
        q('UPDATE patients SET email = ?, updated_at = datetime("now","localtime") WHERE id = ?', [$to, (int)$patient['id']]);
    }
    /* Hasil pengiriman struk LEWAT EMAIL dicatat pada kolom EMAIL (`receipt_email_*`),
       TERPISAH dari kolom WhatsApp — supaya di halaman Detail Transaksi jelas mana
       yang dikirim lewat WhatsApp dan mana yang lewat email. Kegagalan tetap dicatat
       (status 'failed') agar terlihat apa adanya, bukan hanya di log. */
    q('UPDATE orders SET receipt_email_status = ?, receipt_email_to = ?' .
      ($res['ok'] ? ', receipt_email_sent_at = datetime("now","localtime")' : '') . '
       WHERE id = ?', [$res['ok'] ? 'sent' : 'failed', $to !== '' ? $to : null, $id]);
    q('INSERT INTO email_report_logs (recipient, period, status, message, created_at)
       VALUES (?,?,?,?,datetime("now","localtime"))',
        [$to, 'struk ' . $o['invoice_number'], $res['ok'] ? 'sent' : 'failed',
         $res['ok'] ? 'Struk dikirim: ' . $subject : ('Gagal: ' . $res['error'])]);
    audit($res['ok'] ? 'Kirim Struk Email' : 'Gagal Kirim Struk Email', 'Kasir', $id,
        null, ['to' => $to, 'subject' => $subject], $res['ok'] ? 'Struk dikirim ke email pasien' : $res['error']);
    echo json_encode(['ok' => $res['ok'], 'error' => $res['error'], 'subject' => $res['subject'], 'to' => $res['to'],
        'status' => mail_status_text()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    /* Kesalahan disimpan di log server supaya bisa ditelusuri, sedangkan pesan
       ke pengguna tetap ringkas (tidak membocorkan isi sistem). */
    error_log('email_struk gagal: ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
}
