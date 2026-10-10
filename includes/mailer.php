<?php
/**
 * Pengiriman email laporan.
 *
 * Dua jalur pengiriman:
 *  1. HTTP API (HTTPS) — jalur yang BEKERJA di server ini. Port SMTP (25/465/587)
 *     diblokir oleh jaringan platform, sedangkan HTTPS (443) terbuka.
 *     Didukung: Resend, Brevo, SendGrid, Mailgun (US/EU), atau endpoint kustom.
 *  2. SMTP langsung — dipertahankan untuk hosting yang mengizinkan SMTP
 *     (mis. cPanel/Hostinger). Diuji dengan server SMTP tiruan.
 *
 * Fungsi di sini TIDAK PERNAH melaporkan "terkirim" bila provider menolak;
 * respons asli provider dikembalikan sebagai pesan kesalahan.
 */
declare(strict_types=1);

function email_providers(): array
{
    return [
        'resend'    => ['name' => 'Resend',   'url' => 'https://api.resend.com/emails', 'auth' => 'bearer'],
        'brevo'     => ['name' => 'Brevo (Sendinblue)', 'url' => 'https://api.brevo.com/v3/smtp/email', 'auth' => 'api-key'],
        'sendgrid'  => ['name' => 'SendGrid', 'url' => 'https://api.sendgrid.com/v3/mail/send', 'auth' => 'bearer'],
        'mailgun'   => ['name' => 'Mailgun (US)', 'url' => 'https://api.mailgun.net/v3/{domain}/messages', 'auth' => 'basic'],
        'mailgun_eu' => ['name' => 'Mailgun (EU)', 'url' => 'https://api.eu.mailgun.net/v3/{domain}/messages', 'auth' => 'basic'],
        'custom'    => ['name' => 'Endpoint kustom (JSON)', 'url' => '', 'auth' => 'bearer'],
    ];
}

function email_mode(): string
{
    $m = (string)setting('email_mode', 'auto');
    if ($m === 'api' || $m === 'smtp') return $m;
    // auto: pakai API bila URL-nya diisi, kalau tidak pakai SMTP
    return trim((string)setting('email_api_url')) !== '' ? 'api' : 'smtp';
}

/**
 * URL endpoint API email yang benar-benar dipakai.
 *
 * PERBAIKAN (ronde 38): provider bawaan (Resend/Brevo/SendGrid/Mailgun) sudah
 * membawa URL-nya sendiri di `email_providers()`, sehingga pengguna TIDAK perlu
 * mengetik endpoint manual. Sebelumnya `mail_configured()` menuntut
 * `email_api_url` terisi, akibatnya memilih Brevo/Resend + mengisi kunci tetap
 * dilaporkan "Email belum dikonfigurasi" — pengiriman tidak pernah jalan.
 * Field URL kini hanya untuk endpoint KUSTOM (provider "custom").
 */
function mail_api_url(): string
{
    $url = trim((string)setting('email_api_url'));
    if ($url !== '') return $url;
    $key = (string)setting('email_api_provider', 'custom');
    $prov = email_providers()[$key] ?? null;
    return $prov ? (string)$prov['url'] : '';
}

function mail_configured(): bool
{
    if (email_mode() === 'api') {
        /* Kunci API WAJIB: tanpa kunci, permintaan pasti ditolak provider —
           dulu kunci tidak diperiksa sehingga status bisa mengklaim "siap". */
        return mail_api_url() !== ''
            && trim((string)setting('email_api_key')) !== ''
            && trim((string)setting('email_sender')) !== '';
    }
    return trim(setting('smtp_host')) !== '' && trim(setting('email_sender')) !== '';
}

function mail_status_text(): string
{
    $sender = trim((string)setting('email_sender'));
    if (email_mode() === 'api') {
        if (!mail_configured()) {
            return 'Email belum dikonfigurasi — pilih provider (Resend/Brevo/…), isi kunci API, dan alamat pengirim.';
        }
        $key = (string)setting('email_api_provider', 'custom');
        $p = email_providers()[$key]['name'] ?? 'API';
        $txt = 'Aktif via ' . $p . ' (pengirim: ' . $sender . ') — dikirim lewat HTTPS.';
        /* Peringatan JUJUR bila pengirim belum terverifikasi di provider: tanpa ini
           status tampak "siap" padahal setiap email akan ditolak provider. */
        $chk = mail_sender_verified();
        if ($chk['checked'] && !$chk['ok']) {
            $txt .= ' PERHATIAN: alamat pengirim ini belum terverifikasi di ' . $p
                . ' — email akan DITOLAK. Pengirim yang terverifikasi: ' . implode(', ', $chk['senders']) . '.';
        }
        return $txt;
    }
    if (!mail_configured()) {
        return 'Email belum dikonfigurasi — isi host SMTP, pengguna, kata sandi, dan alamat pengirim.';
    }
    return 'Aktif via SMTP ' . setting('smtp_host') . ':' . setting('smtp_port') . ' (pengirim: ' . $sender . ').';
}

/** Alamat balasan (mis. Gmail klinik) supaya pasien/staf bisa membalas. */
function mail_reply_to(): string
{
    $r = trim((string)setting('email_reply_to'));
    return $r !== '' ? $r : '';
}

/**
 * Kirim email. $attachments = [['name'=>..., 'mime'=>..., 'data'=>raw bytes], ...]
 * Mengembalikan true bila provider menerima; $err berisi alasan asli bila gagal.
 */
function send_email(string $to, string $subject, string $html, ?string &$err = null, array $attachments = []): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $err = 'Alamat email tujuan tidak valid: ' . $to;
        return false;
    }
    if (!mail_configured()) {
        $err = 'Email belum dikonfigurasi. Buka Pengaturan Sistem > Email Laporan Otomatis. '
             . 'Catatan: server ini memblokir port SMTP, jadi gunakan jalur HTTPS API.';
        return false;
    }
    /* PENGAMAN PENTING (ronde 39): provider seperti Brevo MENJAWAB SUKSES (HTTP 201)
       walau pengirimnya belum diverifikasi — pesannya lalu DITOLAK di belakang layar
       ("Sending has been rejected because the sender you used … was not found").
       Akibatnya aplikasi melaporkan "terkirim" padahal email tidak pernah sampai.
       Karena itu pengirim diperiksa lebih dulu terhadap daftar pengirim TERVERIFIKASI
       milik provider; kalau tidak cocok, kita MENOLAK mengirim dengan pesan jelas
       (bukan mengklaim sukses). */
    if (email_mode() === 'api') {
        $chk = mail_sender_verified();
        if (!$chk['checked']) { /* provider tidak dapat diperiksa → lanjutkan seperti semula */ }
        elseif (!$chk['ok']) {
            $err = $chk['error'];
            return false;
        }
    }
    $sent = email_mode() === 'api'
        ? send_email_api($to, $subject, $html, $err, $attachments)
        : send_email_smtp($to, $subject, $html, $err, $attachments);
    if ($sent) {
        /* Simpan jejak keberhasilan terakhir supaya status di Pengaturan tidak
           hanya mengandalkan pengaturan, tetapi pengiriman yang benar-benar terjadi. */
        try { set_setting('email_last_success_at', date('Y-m-d H:i:s')); } catch (Throwable $e) { /* abaikan */ }
    }
    return $sent;
}

/**
 * Periksa apakah ALAMAT PENGIRIM terdaftar & terverifikasi di provider.
 *
 * Khusus Brevo (provider yang memakai pengirim milik akunnya sendiri). Daftar
 * pengirim diambil dari API dan disimpan sementara (1 jam) agar tidak memanggil
 * API setiap kali mengirim email.
 *
 * @return array{checked:bool,ok:bool,error:string,senders:array<int,string>}
 */
function mail_sender_verified(bool $force = false): array
{
    $out = ['checked' => false, 'ok' => true, 'error' => '', 'senders' => []];
    if ((string)setting('email_api_provider') !== 'brevo') return $out;   // hanya Brevo
    $from = strtolower(trim((string)setting('email_sender')));
    if ($from === '') return $out;
    $cache = (string)setting('email_senders_cache', '');
    $umur = time() - (int)setting('email_senders_checked_at', '0');
    $list = [];
    if (!$force && $cache !== '' && $umur < 3600) {
        $decoded = json_decode($cache, true);
        if (is_array($decoded)) $list = $decoded;
    }
    if (!$list) {
        $key = (string)setting('email_api_key');
        $res = mail_http_get('https://api.brevo.com/v3/senders', ['api-key: ' . $key]);
        if ($res['code'] >= 200 && $res['code'] < 300) {
            $j = json_decode((string)$res['body'], true);
            foreach ((array)($j['senders'] ?? []) as $sd) {
                if (!empty($sd['email'])) $list[] = strtolower((string)$sd['email']);
            }
            set_setting('email_senders_cache', json_encode($list));
            set_setting('email_senders_checked_at', (string)time());
        }
    }
    if (!$list) return $out;                       // tidak bisa memastikan → jangan blokir
    $out['checked'] = true;
    $out['senders'] = $list;
    if (in_array($from, $list, true)) return $out;
    $out['ok'] = false;
    $out['error'] = 'Alamat pengirim "' . $from . '" BELUM terverifikasi di penyedia email, '
        . 'jadi email pasti ditolak walau permintaannya diterima. Pengirim yang terverifikasi: '
        . implode(', ', $list) . '. Ubah "Alamat Pengirim" di Pengaturan Sistem → Email menjadi salah '
        . 'satu alamat itu (atau tambahkan & verifikasi alamat ini di dasbor penyedia email).';
    return $out;
}

/** Permintaan GET sederhana (dipakai pemeriksaan pengirim email). */
function mail_http_get(string $url, array $headers = []): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => (string)$body];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
    return ['code' => $code, 'body' => (string)$body];
}

/* ------------------------------------------------------------------ *
 * PENGAMAN KIRIM EMAIL BERULANG (ronde 39)
 *
 * Permintaan pemilik: bila tombol "kirim email" ditekan lagi (klik tak sengaja
 * atau lupa sudah pernah mengirim), munculkan peringatan
 * "sudah mengirim 1x/2x — mau kirim lagi?" supaya tidak dobel.
 *
 * Riwayat dibaca dari tabel `email_report_logs` yang SUDAH dipakai mencatat
 * setiap pengiriman (kolom `period` = kunci keperluan, mis. "struk OR-KW-…").
 * Karena pemeriksaan ada di SERVER, klik ganda tidak mungkin lolos walau
 * JavaScript dimatikan.
 * ------------------------------------------------------------------ */

/** Riwayat pengiriman satu keperluan: ['count' => int, 'last' => string|null]. */
function email_send_history(string $key): array
{
    try {
        $r = one('SELECT COUNT(*) n, MAX(created_at) last_at FROM email_report_logs
                  WHERE period = ? AND status = "sent"', [$key]);
    } catch (Throwable $e) {
        return ['count' => 0, 'last' => null];
    }
    return ['count' => (int)($r['n'] ?? 0), 'last' => $r['last_at'] ?? null];
}

/**
 * Apakah pengiriman untuk kunci ini perlu DIKONFIRMASI ulang?
 *
 * @return array{needs:bool,count:int,last:?string,notice:string,blocked:bool,block_msg:string}
 */
function email_resend_notice(string $key, string $label = 'Email ini'): array
{
    $h = email_send_history($key);
    $out = ['needs' => false, 'count' => $h['count'], 'last' => $h['last'], 'notice' => '',
            'blocked' => false, 'block_msg' => ''];
    if ($h['count'] <= 0) return $out;
    $kapan = $h['last'] ? tgl((string)$h['last'], true) : 'sebelumnya';
    $out['needs'] = true;
    $out['notice'] = $label . ' sudah dikirim ' . $h['count'] . '× (terakhir ' . $kapan . ').\n\n'
        . 'Kirim LAGI ke alamat yang sama?';
    /* Klik ganda sangat cepat (< 20 detik) dianggap tidak sengaja: ditolak walau
       pengguna sudah menekan "Ya" pada konfirmasi. */
    if ($h['last'] && (time() - (int)strtotime((string)$h['last'])) < 20) {
        $out['blocked'] = true;
        $out['block_msg'] = 'Email ini baru saja dikirim (' . $kapan . '). Tunggu ±20 detik '
            . 'lalu coba lagi bila memang ingin mengirim ulang.';
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 * Jalur 1: HTTP API (HTTPS)
 * ------------------------------------------------------------------ */
function send_email_api(string $to, string $subject, string $html, ?string &$err, array $attachments = []): bool
{
    $providerKey = (string)setting('email_api_provider', 'custom');
    $providers = email_providers();
    $prov = $providers[$providerKey] ?? $providers['custom'];
    /* URL dari setelan (khusus endpoint kustom) atau dari provider terpilih. */
    $url = mail_api_url();
    if ($url === '') {
        $err = 'URL endpoint API email belum diisi.';
        return false;
    }
    $key = (string)setting('email_api_key');
    $from = trim((string)setting('email_sender'));
    $fromName = trim((string)setting('email_sender_name')) ?: clinic_name();
    $replyTo = mail_reply_to();
    $domain = trim((string)setting('email_api_domain'));   // untuk Mailgun

    /* Bentuk payload menyesuaikan provider (perbedaan nama field API masing-masing). */
    switch ($providerKey) {
        case 'brevo':
            $payload = [
                'sender' => ['email' => $from, 'name' => $fromName],
                'to' => [['email' => $to]],
                'subject' => $subject,
                'htmlContent' => $html,
            ];
            if ($replyTo !== '') $payload['replyTo'] = ['email' => $replyTo];
            foreach ($attachments as $a) {
                $payload['attachment'][] = ['name' => $a['name'], 'content' => base64_encode($a['data'])];
            }
            break;

        case 'sendgrid':
            $payload = [
                'personalizations' => [['to' => [['email' => $to]]]],
                'from' => ['email' => $from, 'name' => $fromName],
                'subject' => $subject,
                'content' => [['type' => 'text/html', 'value' => $html]],
            ];
            if ($replyTo !== '') $payload['reply_to'] = ['email' => $replyTo];
            foreach ($attachments as $a) {
                $payload['attachments'][] = ['content' => base64_encode($a['data']), 'filename' => $a['name'], 'type' => $a['mime']];
            }
            break;

        case 'mailgun':
        case 'mailgun_eu':
            $payload = [
                'from' => $fromName . ' <' . $from . '>',
                'to' => $to,
                'subject' => $subject,
                'html' => $html,
            ];
            if ($replyTo !== '') $payload['h:Reply-To'] = $replyTo;
            foreach ($attachments as $a) {
                $payload['attachment'][] = ['filename' => $a['name'], 'content' => base64_encode($a['data'])];
            }
            if ($domain !== '') $url = str_replace('{domain}', $domain, $url);
            break;

        case 'resend':
        default:
            $payload = [
                'from' => $fromName . ' <' . $from . '>',
                'to' => [$to],
                'subject' => $subject,
                'html' => $html,
            ];
            if ($replyTo !== '') $payload['reply_to'] = $replyTo;
            foreach ($attachments as $a) {
                $payload['attachments'][] = ['filename' => $a['name'], 'content' => base64_encode($a['data'])];
            }
            break;
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        $err = 'Gagal menyusun data email: ' . json_last_error_msg();
        return false;
    }

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    switch ($prov['auth']) {
        case 'api-key': $headers[] = 'api-key: ' . $key; break;
        case 'basic':
            $headers[] = 'Authorization: Basic ' . base64_encode('api:' . $key);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $body = http_build_query($payload);
            break;
        default: $headers[] = 'Authorization: Bearer ' . $key;
    }

    $code = 0;
    $res = false;
    $curlErr = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = (string)curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers),
            'content' => $body, 'timeout' => 30, 'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
    }
    if ($res === false && $curlErr !== '') {
        $err = 'Gagal menghubungi ' . $url . ': ' . $curlErr;
        return false;
    }
    if ($code >= 200 && $code < 300) return true;

    $msg = trim((string)$res);
    $j = json_decode($msg, true);
    if (is_array($j)) {
        $msg = (string)($j['message'] ?? $j['error'] ?? $j['errors'][0]['message'] ?? $msg);
    }
    $err = 'Provider email menolak (HTTP ' . $code . '). ' . short_text($msg, 300);
    return false;
}

/** Alias lama (dipertahankan agar kode lain tetap jalan). */
if (!function_exists('mb_substr_fallback')) {
    function mb_substr_fallback(string $s, int $n): string
    {
        return function_exists('short_text') ? short_text($s, $n) : (strlen($s) > $n ? substr($s, 0, $n - 1) . '…' : $s);
    }
}

/* ------------------------------------------------------------------ *
 * Jalur 2: SMTP langsung (untuk hosting yang mengizinkan SMTP)
 * ------------------------------------------------------------------ */
function send_email_smtp(string $to, string $subject, string $html, ?string &$err, array $attachments = []): bool
{
    $host = trim(setting('smtp_host'));
    $port = (int)setting('smtp_port', '587');
    $secure = strtolower(trim(setting('smtp_secure', 'tls')));
    $user = trim(setting('smtp_user'));
    $pass = setting('smtp_pass');
    $from = trim(setting('email_sender'));
    $fromName = trim(setting('email_sender_name')) ?: clinic_name();
    $replyTo = mail_reply_to();

    $target = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $fp = @stream_socket_client($target, $eno, $estr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $err = 'Tidak dapat terhubung ke SMTP ' . $host . ':' . $port . ' (' . $estr . '). '
             . 'Bila jaringan server memblokir port SMTP, gunakan pengiriman lewat HTTPS API.';
        return false;
    }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) > 3 && ($line[3] === ' ' || $line[3] === "\n")) break;
        }
        return $data;
    };
    $cmd = function (string $c, array $expect = [250]) use ($fp, $read, &$err): bool {
        fwrite($fp, $c . "\r\n");
        $r = $read();
        $code = (int)substr(trim($r), 0, 3);
        if ($expect && !in_array($code, $expect, true)) {
            $err = 'SMTP menolak perintah "' . substr(strtok($c, "\r\n"), 0, 24) . '": ' . trim($r);
            return false;
        }
        return true;
    };

    $banner = $read();
    if ((int)substr(trim($banner), 0, 3) !== 220) {
        $err = 'SMTP tidak merespons dengan benar: ' . trim($banner);
        fclose($fp);
        return false;
    }
    $hostname = 'naveena.local';
    if (!$cmd('EHLO ' . $hostname, [250])) { fclose($fp); return false; }
    if ($secure === 'tls') {
        if (!$cmd('STARTTLS', [220])) { fclose($fp); return false; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $err = 'Gagal memulai TLS dengan server SMTP.';
            fclose($fp);
            return false;
        }
        if (!$cmd('EHLO ' . $hostname, [250])) { fclose($fp); return false; }
    }
    if ($user !== '') {
        if (!$cmd('AUTH LOGIN', [334])) { fclose($fp); return false; }
        if (!$cmd(base64_encode($user), [334])) { fclose($fp); return false; }
        if (!$cmd(base64_encode($pass), [235, 503])) { fclose($fp); return false; }
    }
    if (!$cmd('MAIL FROM:<' . $from . '>', [250])) { fclose($fp); return false; }
    if (!$cmd('RCPT TO:<' . $to . '>', [250, 251])) { fclose($fp); return false; }
    if (!$cmd('DATA', [354])) { fclose($fp); return false; }

    $headers = 'From: ' . mail_encode_header($fromName) . ' <' . $from . ">\r\n"
        . 'To: <' . $to . ">\r\n"
        . 'Subject: ' . mail_encode_header($subject) . "\r\n"
        . ($replyTo !== '' ? 'Reply-To: <' . $replyTo . ">\r\n" : '')
        . 'Date: ' . date('r') . "\r\n"
        . 'MIME-Version: 1.0' . "\r\n";

    if (!$attachments) {
        $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $body = $html;
    } else {
        $boundary = 'nv' . bin2hex(random_bytes(8));
        $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";
        $body = '--' . $boundary . "\r\n"
            . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n");
        foreach ($attachments as $a) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Type: ' . $a['mime'] . '; name="' . $a['name'] . '"' . "\r\n"
                . 'Content-Transfer-Encoding: base64' . "\r\n"
                . 'Content-Disposition: attachment; filename="' . $a['name'] . '"' . "\r\n\r\n"
                . chunk_split(base64_encode($a['data']), 76, "\r\n");
        }
        $body .= '--' . $boundary . "--\r\n";
    }
    $body = preg_replace('/^\./m', '..', $body);
    fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
    $r = $read();
    if ((int)substr(trim($r), 0, 3) !== 250) {
        $err = 'SMTP menolak pesan: ' . trim($r);
        fclose($fp);
        return false;
    }
    $cmd('QUIT', [221]);
    fclose($fp);
    return true;
}

function mail_encode_header(string $s): string
{
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

/** Hasil uji koneksi terakhir (ditampilkan di Pengaturan). */
function email_last_result(): ?array
{
    $t = setting('email_last_test_at');
    if ($t === '') return null;
    return ['at' => $t, 'ok' => setting('email_last_test_ok') === '1', 'msg' => setting('email_last_test_msg')];
}
function email_record_result(bool $ok, string $msg, bool $emailSent = false): void
{
    set_setting('email_last_test_at', date('Y-m-d H:i:s'));
    set_setting('email_last_test_ok', $ok ? '1' : '0');
    set_setting('email_last_test_msg', short_text($msg, 300));
    if ($emailSent) set_setting('email_last_success_at', date('Y-m-d H:i:s'));
}

/* ------------------------------------------------------------------ *
 * WhatsApp API (tetap di sini karena dipakai bersama modul lain)
 * ------------------------------------------------------------------ */
function wa_api_configured(): bool
{
    return setting('wa_api_active') === '1' && trim(setting('wa_api_url')) !== '';
}
function wa_status_text(): string
{
    if (wa_api_configured()) return 'WhatsApp API aktif (' . setting('wa_api_url') . ').';
    if (trim(setting('wa_api_url')) === '') return 'WhatsApp API belum dikonfigurasi — memakai deep link wa.me.';
    return 'WhatsApp API dikonfigurasi tetapi berstatus nonaktif — memakai deep link wa.me.';
}
function wa_api_send(string $phone, string $message, ?string &$err = null): bool
{
    if (!wa_api_configured()) {
        $err = wa_status_text();
        return false;
    }
    $number = wa_number($phone);
    if ($number === '') {
        $err = 'Nomor WhatsApp pasien tidak valid.';
        return false;
    }
    $url = str_replace(['{number}', '{message}'], [rawurlencode($number), rawurlencode($message)], setting('wa_api_url'));
    $token = setting('wa_api_token');
    $ctxCfg = ['timeout' => 20, 'ignore_errors' => true];
    if (strpos(setting('wa_api_url'), '{message}') !== false) {
        $ctxCfg['method'] = 'GET';
        if ($token !== '') $ctxCfg['header'] = 'Authorization: Bearer ' . $token . "\r\n";
        $ctx = stream_context_create(['http' => $ctxCfg]);
        $res = @file_get_contents($url, false, $ctx);
    } else {
        $payload = json_encode(['sender' => setting('wa_api_sender'), 'number' => $number, 'message' => $message], JSON_UNESCAPED_UNICODE);
        $ctxCfg['method'] = 'POST';
        $ctxCfg['header'] = "Content-Type: application/json\r\n" . ($token !== '' ? 'Authorization: Bearer ' . $token . "\r\n" : '');
        $ctxCfg['content'] = $payload;
        $ctx = stream_context_create(['http' => $ctxCfg]);
        $res = @file_get_contents($url, false, $ctx);
    }
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
    if ($res === false || ($code !== 0 && ($code < 200 || $code >= 300))) {
        $err = 'Pengiriman WhatsApp gagal' . ($code ? ' (HTTP ' . $code . ')' : '') . '. Periksa konfigurasi API di Pengaturan Sistem.';
        return false;
    }
    return true;
}

/* ------------------------------------------------------------------ *
 * Bingkai HTML email (dipakai laporan bulanan, kode verifikasi, dll)
 * ------------------------------------------------------------------ */
/** Bingkai HTML email agar tampil rapi di Gmail/Outlook. */
function email_wrap_html(string $title, string $bodyHtml, array $rows = []): string
{
    $t = theme_current();
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#2B1B27">'
        . '<div style="background:linear-gradient(135deg,' . $t['brandDark'] . ',' . $t['brand'] . ');padding:18px 22px;border-radius:12px 12px 0 0">'
        . '<div style="color:#fff;font-size:19px;font-weight:700">' . e(clinic_name()) . '</div>'
        . '<div style="color:rgba(255,255,255,.85);font-size:13px">' . e($title) . '</div>'
        . '</div><div style="border:1px solid #E8E8E8;border-top:0;border-radius:0 0 12px 12px;padding:20px 22px">'
        . $bodyHtml;
    /* Dua bentuk masukan yang sah:
       (1) daftar baris siap pakai: [['head'=>bool,'cells'=>[..]], ...] (laporan bulanan),
       (2) peta label => nilai: ['Invoice' => 'NS-...', ...] (email struk).
       Bentuk (2) diterima supaya email struk tidak error — dulu pemanggilnya
       mengirim peta label=>nilai sehingga terjadi "Cannot access offset of type
       string on string" dan PENGIRIMAN EMAIL STRUK SELALU GAGAL. */
    $norm = [];
    foreach ($rows as $k => $r) {
        if (is_array($r)) { $norm[] = $r; continue; }
        $norm[] = ['cells' => [e((string)$k), e((string)$r)]];
    }
    if ($norm) {
        $html .= '<table cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;font-size:13px;margin-top:6px">';
        foreach ($norm as $r) {
            $isHead = !empty($r['head']);
            $bg = $isHead ? '#F7F7F9' : '#fff';
            $html .= '<tr style="background:' . $bg . '">';
            foreach ($r['cells'] as $i => $c) {
                $html .= '<td style="border-bottom:1px solid #EDEDED;' . ($i > 0 ? 'text-align:right;' : '') . ($isHead ? 'font-weight:700;' : '') . '">' . $c . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    $html .= '<p style="font-size:12px;color:#6B5A65;margin-top:18px">'
        . 'Dikirim otomatis oleh ' . e(clinic_name()) . ' Management System pada ' . e(tglIndo(date('Y-m-d'))) . '.</p>'
        . '</div></div>';
    return $html;
}


/* ------------------------------------------------------------------ *
 * EMAIL STRUK / TRANSAKSI ke pasien
 *
 * Dipakai tombol "Email" pada halaman detail transaksi (setelah kasir selesai
 * input order) dan pada daftar Riwayat Order. Subjek & isi pesan diambil dari
 * Pengaturan Sistem (dapat diubah), dengan variabel {nama} {invoice} {total}
 * {tanggal} {klinik} {cabang} {metode}. Struk PDF dilampirkan bila tersedia.
 * ------------------------------------------------------------------ */

/* ------------------------------------------------------------------ *
 * TAUTAN STRUK PUBLIK (bertoken)
 *
 * Email dikirim ke pasien yang TIDAK login, jadi struknya tidak bisa memakai
 * struk.php (wajib login). Tautannya ditandatangani (HMAC + masa berlaku),
 * sehingga tautan hanya berlaku untuk satu transaksi dan kedaluwarsa sendiri.
 * ------------------------------------------------------------------ */

/** Kunci tanda tangan tautan; dibuat sekali otomatis bila belum ada. */
function receipt_link_secret(): string
{
    $s = (string)setting('receipt_link_secret', '');
    if ($s === '') {
        $s = bin2hex(random_bytes(16));
        set_setting('receipt_link_secret', $s);
    }
    return $s;
}

/** Masa berlaku tautan struk (hari). */
function receipt_link_days(): int
{
    return max(1, min(365, (int)setting('receipt_link_days', '30')));
}

/** Token untuk satu transaksi: berisi id + kedaluwarsa + tanda tangan. */
function receipt_link_token(int $orderId, ?int $expires = null): string
{
    $exp = $expires ?? (time() + receipt_link_days() * 86400);
    $payload = $orderId . '|' . $exp;
    $sig = hash_hmac('sha256', $payload, receipt_link_secret());
    return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
}

/** Periksa token → id transaksi, atau null bila tidak sah/kedaluwarsa. */
function receipt_link_verify(string $token): ?int
{
    if ($token === '') return null;
    $raw = base64_decode(strtr($token, '-_', '+/'), true);
    if ($raw === false) return null;
    $parts = explode('|', $raw);
    if (count($parts) !== 3) return null;
    [$id, $exp, $sig] = $parts;
    /* ctype_digit() TIDAK tersedia di build PHP server ini (seperti mb_*) —
       pakai preg_match supaya halaman tautan struk tidak error 500. */
    if (!preg_match('/^\d+$/', $id) || !preg_match('/^\d+$/', $exp)) return null;
    if ((int)$exp < time()) return null;
    $expect = hash_hmac('sha256', $id . '|' . $exp, receipt_link_secret());
    if (!hash_equals($expect, $sig)) return null;
    return (int)$id;
}

/** Tautan unduh struk PDF untuk satu transaksi. */
function receipt_public_link(array $o): string
{
    $base = app_public_base();
    $url = ($base !== '' ? $base . '/' : '') . 'struk_link.php?t=' . urlencode(receipt_link_token((int)$o['id']));
    return $url;
}

/** Subjek email struk (dari Pengaturan, dengan variabel terisi). */
function receipt_email_subject(array $o): string
{
    $tpl = (string)setting('email_receipt_subject');
    if (trim($tpl) === '') $tpl = receipt_email_default_subject();
    return receipt_email_replace($tpl, $o);
}

/** Isi email struk dalam bentuk teks (lalu dibungkus template HTML email). */
/**
 * Isi pesan BAWAAN email struk.
 *
 * Dipakai bila Pengaturan Sistem → Email belum diisi, dan juga ditampilkan
 * sebagai nilai awal pada kolom pengaturannya supaya template selalu "sudah
 * terisi" dan bisa langsung diedit pemilik klinik.
 */
function receipt_email_default_body(): string
{
    return "Halo {nama},\n\nTerima kasih telah melakukan perawatan di {klinik} {cabang}.\n"
         . "Berikut rincian transaksi Anda:\nNo. Invoice: {invoice}\nTanggal: {tanggal}\n"
         . "Total: {total}\nMetode: {metode}\n\n"
         . "Struk PDF juga dapat diunduh pada tautan berikut (berlaku " . receipt_link_days() . " hari):\n{link}\n\n"
         . "Salam sehat,\n{klinik}";
}

function receipt_email_body(array $o): string
{
    $tpl = trim((string)setting('email_receipt_body'));
    if ($tpl === '') $tpl = receipt_email_default_body();
    return receipt_email_replace($tpl, $o);
}

/** Subjek bawaan email struk (juga dipakai sebagai nilai awal di Pengaturan). */
function receipt_email_default_subject(): string
{
    return 'Struk {invoice} — {klinik}';
}

/** Ganti variabel pada subjek/isi email struk. */
function receipt_email_replace(string $tpl, array $o): string
{
    $methods = '';
    if (!empty($o['payments'])) {
        $methods = implode(', ', array_map(fn($p) => (string)$p['method'], $o['payments']));
    }
    $disc = [];
    if ((float)($o['discount'] ?? 0) > 0) $disc[] = 'Diskon ' . money($o['discount']);
    if ((float)($o['member_discount'] ?? 0) > 0) {
        $disc[] = 'Diskon member ' . money($o['member_discount'])
            . (!empty($o['member_tier']) ? ' (' . $o['member_tier'] . ')' : '');
    }
    /* Rincian & catatan PROMO hidup di includes/receipt.php; dijaga dengan
       function_exists supaya pemanggil yang belum memuat berkas itu tetap aman. */
    $rincian = function_exists('receipt_items_text') ? receipt_items_text($o) : '';
    $ptxt = function_exists('receipt_promo_text') ? receipt_promo_text($o) : '';
    return str_replace(
        ['{nama}', '{pasien}', '{invoice}', '{tanggal}', '{total}', '{subtotal}', '{diskon}',
         '{metode}', '{klinik}', '{cabang}', '{alamat}', '{link}', '{rincian}', '{promo}'],
        [
            (string)($o['patient_name'] ?? ''),
            (string)($o['patient_name'] ?? ''),
            (string)($o['invoice_number'] ?? ''),
            tgl((string)($o['created_at'] ?? ''), true),
            money($o['total'] ?? 0),
            money($o['subtotal'] ?? 0),
            $disc ? implode(' + ', $disc) : '-',
            $methods !== '' ? $methods : '-',
            clinic_name(),
            (string)($o['branch_name'] ?? ''),
            (string)($o['branch_address'] ?? ''),
            receipt_public_link($o),
            $rincian,
            $ptxt !== '' ? $ptxt : '-',
        ],
        $tpl
    );
}

/* ============================================================================
 * EMAIL KONFIRMASI RESERVASI
 * ============================================================================
 * Dipakai tombol "Email Pasien" / "Email Dokter" pada halaman Reservasi.
 * Sama polanya dengan email struk: teks diambil dari Pengaturan Sistem
 * (`email_reservation_subject` / `email_reservation_body`) — bila belum diisi
 * dipakai teks bawaan, dan petugas masih boleh mengubahnya sebelum dikirim.
 */

/** Subjek email reservasi bawaan (dipakai sebagai nilai awal Pengaturan). */
function reservation_email_default_subject(): string
{
    return 'Konfirmasi Reservasi {no_reservasi} — {klinik}';
}

/** Isi email reservasi bawaan. */
function reservation_email_default_body(): string
{
    return "Halo {nama},\n\nBerikut detail reservasi Anda di {klinik} {cabang}:\n"
         . "No. Reservasi: {no_reservasi}\nTanggal: {tanggal}\nJam: {jam}\n"
         . "Treatment: {treatment}\nDokter/Terapis: {staff}\n"
         . "Catatan: {catatan}\n\n"
         . "Mohon konfirmasi kehadiran Anda. Terima kasih.\n\nSalam sehat,\n{klinik}";
}

/** Ganti variabel pada subjek/isi email reservasi. */
function reservation_email_replace(string $tpl, array $a): string
{
    $treatment = trim((string)($a['treatments_all'] ?? ''));
    if ($treatment === '') $treatment = trim((string)($a['treatment_name'] ?? ''));
    $staff = function_exists('staff_both_text')
        ? staff_both_text((string)($a['doctor_name'] ?? ''), (string)($a['therapist_name'] ?? ''))
        : trim((string)($a['doctor_name'] ?? '') . ' ' . (string)($a['therapist_name'] ?? ''));
    return str_replace(
        ['{nama}', '{pasien}', '{no_reservasi}', '{tanggal}', '{jam}', '{treatment}',
         '{dokter}', '{terapis}', '{staff}', '{catatan}', '{telepon}', '{klinik}', '{cabang}', '{alamat}'],
        [
            (string)($a['patient_name'] ?? ''),
            (string)($a['patient_name'] ?? ''),
            (string)($a['appointment_number'] ?? ''),
            isset($a['date']) ? tgl((string)$a['date']) : '',
            substr((string)($a['time'] ?? ''), 0, 5),
            $treatment !== '' ? $treatment : '-',
            (string)($a['doctor_name'] ?? ''),
            (string)($a['therapist_name'] ?? ''),
            $staff !== '' ? $staff : '-',
            trim((string)($a['notes'] ?? '')) !== '' ? trim((string)$a['notes']) : '-',
            (string)($a['patient_phone'] ?? ''),
            clinic_name(),
            (string)($a['branch_name'] ?? ''),
            (string)($a['branch_address'] ?? ''),
        ],
        $tpl
    );
}

/** Subjek email reservasi (dari Pengaturan, variabel terisi). */
function reservation_email_subject(array $a): string
{
    $tpl = trim((string)setting('email_reservation_subject'));
    if ($tpl === '') $tpl = reservation_email_default_subject();
    return reservation_email_replace($tpl, $a);
}

/** Isi email reservasi (dari Pengaturan, variabel terisi). */
function reservation_email_body(array $a): string
{
    $tpl = trim((string)setting('email_reservation_body'));
    if ($tpl === '') $tpl = reservation_email_default_body();
    return reservation_email_replace($tpl, $a);
}

/**
 * Kirim struk (PDF) ke email pasien.
 * @return array{ok:bool,error:string,subject:string,to:string}
 */
/**
 * Ringkasan keadaan "struk lewat EMAIL" untuk sebuah transaksi.
 *
 * Dipakai halaman Detail Transaksi & halaman struk supaya pemilik/kasir dapat
 * melihat APA ADANYA: sudah terkirim ke alamat mana, pernah gagal, atau pasien
 * memang belum punya email. Terpisah dari status WhatsApp (`receipt_*`).
 *
 * @return array{status:string,label:string,tone:string,to:string,at:string,catatan:string,alamatValid:bool}
 */
function receipt_email_state(array $o): array
{
    $to = trim((string)($o['receipt_email_to'] ?? ''));
    $st = trim((string)($o['receipt_email_status'] ?? ''));
    $at = trim((string)($o['receipt_email_sent_at'] ?? ''));
    $alamatValid = $to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) !== false;
    $catatan = '';
    if ($st === 'sent') {
        $label = 'Terkirim'; $tone = 'green';
        $catatan = 'Email diserahkan ke layanan pengiriman. Pengiriman ke kotak masuk tetap tergantung '
            . 'penyedia email tujuan (alamat yang tidak benar akan MEMANTUL, dan pantulan itu tidak dapat '
            . 'diketahui langsung oleh aplikasi ini).';
    } elseif ($st === 'failed') {
        $label = 'Gagal terakhir'; $tone = 'red';
        $catatan = 'Percobaan pengiriman terakhir DITOLAK oleh layanan email. Lihat "Riwayat Pengiriman Email" '
            . 'di Pengaturan Sistem untuk pesan aslinya, lalu coba kirim ulang.';
    } elseif ($st === 'no_email') {
        $label = 'Tanpa email'; $tone = 'yellow';
        $catatan = 'Pasien ini belum punya alamat email, jadi struk tidak dikirim. Isi email di '
            . 'Data Pasien (atau saat mengirim struk) terlebih dahulu.';
    } else {
        $label = 'Belum dikirim'; $tone = 'gray';
        $catatan = 'Struk belum pernah dikirim lewat email untuk transaksi ini.';
    }
    if ($to !== '' && !$alamatValid) {
        $catatan .= ' Alamat yang tersimpan TIDAK berbentuk email yang sah, sehingga pengiriman akan ditolak.';
    }
    return ['status' => $st, 'label' => $label, 'tone' => $tone, 'to' => $to, 'at' => $at,
            'catatan' => $catatan, 'alamatValid' => $alamatValid];
}

function send_receipt_email(array $o, string $to, ?string $subjectOverride = null): array
{
    $to = trim($to);
    $subject = $subjectOverride !== null && trim($subjectOverride) !== ''
        ? receipt_email_replace($subjectOverride, $o)
        : receipt_email_subject($o);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Alamat email tujuan tidak valid.', 'subject' => $subject, 'to' => $to];
    }
    if (!mail_configured()) {
        return ['ok' => false, 'error' => 'Email belum dikonfigurasi di Pengaturan Sistem (lihat panel Email). '
            . 'Server ini memblokir port SMTP, jadi gunakan jalur HTTPS API (mis. Resend/Brevo).',
            'subject' => $subject, 'to' => $to];
    }
    $body = receipt_email_body($o);
    $html = email_wrap_html('Struk ' . (string)$o['invoice_number'], nl2br(e($body)), [
        'Invoice' => (string)$o['invoice_number'],
        'Tanggal' => tgl((string)$o['created_at'], true),
        'Pasien' => (string)$o['patient_name'],
        'Cabang' => (string)($o['branch_name'] ?? ''),
        'Total' => money($o['total'] ?? 0),
    ]);

    $attachments = [];
    try {
        require_once __DIR__ . '/receipt.php';
        $bytes = receipt_pdf($o)['bytes'];
        $attachments[] = ['name' => 'struk-' . preg_replace('/[^A-Za-z0-9\-]/', '-', (string)$o['invoice_number']) . '.pdf',
            'mime' => 'application/pdf', 'data' => $bytes];
    } catch (Throwable $e) {
        /* PDF gagal dibuat bukan alasan membatalkan pengiriman email. */
    }

    $err = null;
    $ok = send_email($to, $subject, $html, $err, $attachments);
    return ['ok' => $ok, 'error' => $ok ? '' : (string)$err, 'subject' => $subject, 'to' => $to];
}
