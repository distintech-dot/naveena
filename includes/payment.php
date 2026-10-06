<?php
/**
 * PEMBAYARAN: pengaturan klinik (rekening/QRIS), kode unik transfer, dan
 * penghubung payment gateway (Midtrans / Xendit).
 *
 * Dipakai oleh:
 *   - Pengaturan Sistem (panel Pembayaran) → menyimpan nama bank, no. rekening,
 *     nama pemilik, gambar QRIS, serta kunci API gateway;
 *   - order_baru.php (langkah "Pembayaran" — wajib bayar dulu);
 *   - bayar.php (halaman pembayaran QRIS/transfer + menunggu pelunasan);
 *   - pay_webhook.php (notifikasi gateway).
 *
 * Tidak ada kredensial yang ditulis di kode: semuanya dari Pengaturan Sistem.
 * Bila kredensial belum diisi, jalur pembayaran OTOMATIS dinonaktifkan dan
 * sistem memakai jalur MANUAL (rekening/QRIS klinik + kode unik) — statusnya
 * dilaporkan apa adanya, tidak pernah diklaim "otomatis" bila belum siap.
 */
declare(strict_types=1);

/* ------------------------------------------------------------------ *
 * Pengaturan
 * ------------------------------------------------------------------ */

/** Daftar gateway yang didukung. */
function pay_gateways(): array
{
    return [
        'none'     => 'Tidak ada (transfer/QRIS manual)',
        'midtrans' => 'Midtrans',
        'xendit'   => 'Xendit',
    ];
}

function pay_gateway_key(): string
{
    $g = (string)setting('pay_gateway', 'none');
    return array_key_exists($g, pay_gateways()) ? $g : 'none';
}

function pay_gateway_name(): string
{
    return pay_gateways()[pay_gateway_key()];
}

function pay_gateway_env(): string
{
    return setting('pay_gateway_env', 'sandbox') === 'production' ? 'production' : 'sandbox';
}

/** Kredensial wajib sudah terisi? (kalau belum → jalur otomatis dimatikan) */
function pay_gateway_configured(): bool
{
    if (pay_gateway_key() === 'none') return false;
    return trim((string)setting('pay_gateway_server_key')) !== '';
}

/** Keterangan status untuk ditampilkan di layar/pengaturan (jujur apa adanya). */
function pay_gateway_status_text(): string
{
    $g = pay_gateway_key();
    if ($g === 'none') return 'Belum diaktifkan — pembayaran memakai jalur manual (transfer/QRIS klinik + kode unik).';
    if (!pay_gateway_configured()) {
        return pay_gateway_name() . ' dipilih tetapi kunci API belum diisi — jalur OTOMATIS belum aktif, '
            . 'sementara sistem memakai jalur manual.';
    }
    return 'Aktif via ' . pay_gateway_name() . ' (' . pay_gateway_env() . ') — QRIS/VA dibuat otomatis dan statusnya dicek ke gateway.';
}

/** Kode unik 3 digit diaktifkan untuk transfer/QRIS? */
function pay_unique_code_enabled(): bool
{
    return setting('pay_unique_code', '1') === '1';
}

/** Masa berlaku permintaan pembayaran otomatis (menit). */
function pay_expire_minutes(): int
{
    return max(5, min(180, (int)setting('pay_expire_minutes', '15')));
}

/* ------------------------------------------------------------------ *
 * Kode unik & informasi pembayaran klinik
 * ------------------------------------------------------------------ */

/**
 * Kode unik 3 digit untuk sebuah metode pembayaran.
 * Tunai tidak memakai kode unik (uang pas diterima di kasir).
 */
function pay_unique_code_for(string $method): int
{
    if (!pay_unique_code_enabled()) return 0;
    if (!in_array($method, ['Transfer', 'QRIS'], true)) return 0;
    return random_int(101, 999);
}

/** Daftar rekening bank & QRIS klinik (dari Pengaturan Sistem). */
function pay_clinic_info(): array
{
    $qris = trim((string)setting('pay_qris_file'));
    return [
        'bank_name'    => trim((string)setting('pay_bank_name')),
        'bank_account' => trim((string)setting('pay_bank_account')),
        'bank_holder'  => trim((string)setting('pay_bank_holder')),
        'note'         => trim((string)setting('pay_note')),
        'qris_file'    => $qris,
        /* URL gambar QRIS yang SIAP DIPAKAI di halaman mana pun (termasuk dari
           dalam modal Order Baru). Ditulis RELATIF terhadap halaman — bukan URL
           absolut — supaya tetap benar di alamat sub-folder maupun domain sendiri. */
        'qris_url'     => $qris !== '' ? 'qris.php?v=' . rawurlencode(substr(md5($qris), 0, 6)) : '',
    ];
}

/** Apakah data pembayaran manual (rekening/QRIS) sudah diisi? */
function pay_clinic_ready(): bool
{
    $i = pay_clinic_info();
    return ($i['bank_name'] !== '' && $i['bank_account'] !== '') || $i['qris_file'] !== '';
}

/* ------------------------------------------------------------------ *
 * Payment gateway: buat tagihan & cek status
 * ------------------------------------------------------------------ */

/** Alamat dasar API gateway sesuai lingkungan. */
function pay_gateway_base(): string
{
    /* URL kustom (opsional) dipakai lebih dulu — untuk endpoint khusus/regional
       atau pengujian dengan server tiruan. */
    $custom = trim((string)setting('pay_gateway_base_url'));
    if ($custom !== '') return rtrim($custom, '/');
    if (pay_gateway_key() === 'midtrans') {
        return pay_gateway_env() === 'production'
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';
    }
    return 'https://api.xendit.co';
}

/** Permintaan HTTP JSON sederhana (curl bila ada, jika tidak pakai stream). */
function pay_http(string $method, string $url, array $payload, string $auth, ?string &$err = null): array
{
    $body = $payload === [] ? '' : (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Authorization: ' . $auth];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
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
        if ($res === false && $curlErr !== '') { $err = 'Gagal menghubungi ' . $url . ': ' . $curlErr; return []; }
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $body, 'timeout' => 30, 'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
    }
    $json = json_decode((string)$res, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($json) ? (string)($json['status_message'] ?? $json['message'] ?? $json['error_code'] ?? '') : trim((string)$res);
        $err = 'Gateway menolak (HTTP ' . $code . '). ' . short_text($msg, 240);
        return [];
    }
    return is_array($json) ? $json : [];
}

/**
 * Buat tagihan QRIS (dan VA bila tersedia) di gateway.
 *
 * @return array{ok:bool,qr_string:string,qr_url:string,va:string,gateway_ref:string,expires:string,error:string}
 */
function pay_gateway_create(string $ref, float $amount, string $desc): array
{
    $out = ['ok' => false, 'qr_string' => '', 'qr_url' => '', 'va' => '', 'gateway_ref' => '',
            'expires' => '', 'error' => ''];
    if (!pay_gateway_configured()) {
        $out['error'] = 'Pembayaran otomatis belum siap: kunci API ' . pay_gateway_name()
            . ' belum diisi di Pengaturan Sistem → Pembayaran. Gunakan jalur manual (transfer/QRIS klinik).';
        return $out;
    }
    $key = trim((string)setting('pay_gateway_server_key'));
    $g = pay_gateway_key();
    $err = null;

    if ($g === 'midtrans') {
        /* Core API: charge QRIS. Kunci server dipakai sebagai Basic auth (user = key). */
        $payload = [
            'payment_type' => 'qris',
            'transaction_details' => ['order_id' => $ref, 'gross_amount' => (int)round($amount)],
            'qris' => ['acquirer' => 'gopay'],
            'custom_field1' => short_text($desc, 50),
        ];
        $res = pay_http('POST', pay_gateway_base() . '/v2/charge', $payload,
            'Basic ' . base64_encode($key . ':'), $err);
        if (!$res) { $out['error'] = (string)$err; return $out; }
        $out['gateway_ref'] = (string)($res['transaction_id'] ?? $ref);
        $out['qr_string'] = (string)($res['qr_string'] ?? '');
        foreach ((array)($res['actions'] ?? []) as $a) {
            if (($a['name'] ?? '') === 'generate-qr-code') $out['qr_url'] = (string)($a['url'] ?? '');
        }
        $out['expires'] = (string)($res['expiry_time'] ?? '');
        $out['ok'] = ($out['qr_string'] !== '' || $out['qr_url'] !== '');
        if (!$out['ok']) $out['error'] = 'Gateway tidak mengembalikan kode QRIS.';
        return $out;
    }

    /* Xendit: QR Code API. */
    $payload = [
        'external_id' => $ref,
        'type' => 'DYNAMIC',
        'callback_url' => app_public_base() . '/pay_webhook.php',
        'amount' => (int)round($amount),
        'description' => short_text($desc, 100),
    ];
    $res = pay_http('POST', 'https://api.xendit.co/qr_codes', $payload,
        'Basic ' . base64_encode($key . ':'), $err);
    if (!$res) { $out['error'] = (string)$err; return $out; }
    $out['gateway_ref'] = (string)($res['id'] ?? $ref);
    $out['qr_string'] = (string)($res['qr_string'] ?? '');
    $out['expires'] = (string)($res['expires_at'] ?? '');
    $out['ok'] = $out['qr_string'] !== '';
    if (!$out['ok']) $out['error'] = 'Gateway tidak mengembalikan kode QRIS.';
    return $out;
}

/**
 * Cek status sebuah tagihan ke gateway.
 *
 * @return array{ok:bool,paid:bool,status:string,error:string}
 */
function pay_gateway_status(string $ref, string $gatewayRef = ''): array
{
    $out = ['ok' => false, 'paid' => false, 'status' => '', 'error' => ''];
    if (!pay_gateway_configured()) {
        $out['error'] = 'Pembayaran otomatis belum siap (kunci API belum diisi).';
        return $out;
    }
    $key = trim((string)setting('pay_gateway_server_key'));
    $err = null;

    if (pay_gateway_key() === 'midtrans') {
        /* Midtrans: GET /v2/{order_id}/status */
        $url = pay_gateway_base() . '/v2/' . rawurlencode($ref) . '/status';
        $res = pay_http('GET', $url, [], 'Basic ' . base64_encode($key . ':'), $err);
        if (!$res) { $out['error'] = (string)$err; return $out; }
        $st = strtolower((string)($res['transaction_status'] ?? ''));
        $out['status'] = $st;
        $out['ok'] = true;
        $out['paid'] = in_array($st, ['settlement', 'capture'], true);
        return $out;
    }

    /* Xendit: GET /qr_codes/{id} */
    $id = $gatewayRef !== '' ? $gatewayRef : $ref;
    $res = pay_http('GET', 'https://api.xendit.co/qr_codes/' . rawurlencode($id), [], 'Basic ' . base64_encode($key . ':'), $err);
    if (!$res) { $out['error'] = (string)$err; return $out; }
    $st = strtoupper((string)($res['status'] ?? ''));
    $out['status'] = $st;
    $out['ok'] = true;
    $out['paid'] = $st === 'SUCCEEDED' || $st === 'COMPLETED';
    return $out;
}

/** URL notifikasi (webhook) yang perlu didaftarkan di dashboard gateway. */
function pay_webhook_url(): string
{
    return app_public_base() . '/pay_webhook.php';
}

/* ------------------------------------------------------------------ *
 * Permintaan pembayaran yang menunggu pelunasan (jalur OTOMATIS)
 * ------------------------------------------------------------------ */

/** Buat permintaan pembayaran; transaksi BELUM dibuat sebelum lunas. */
function pay_pending_create(array $payload, int $branchId, int $userId): array
{
    $ref = 'PAY-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $amount = (float)($payload['pay_amount'] ?? 0);
    $method = (string)($payload['method'] ?? 'QRIS');
    q('INSERT INTO pay_pending (ref, order_payload, amount, method, status, gateway, branch_id, user_id, created_at, expires_at)
       VALUES (?,?,?,?,"pending",?,?,?,datetime("now","localtime"),datetime("now","localtime",?))',
       [$ref, json_encode($payload, JSON_UNESCAPED_UNICODE), $amount, $method,
        pay_gateway_key(), $branchId, $userId, '+' . pay_expire_minutes() . ' minutes']);
    return one('SELECT * FROM pay_pending WHERE ref = ?', [$ref]) ?: [];
}

function pay_pending_by_ref(string $ref): array
{
    return one('SELECT * FROM pay_pending WHERE ref = ?', [$ref]) ?: [];
}

/** Simpan hasil pembuatan tagihan di gateway. */
function pay_pending_set_gateway(int $id, array $created): void
{
    q('UPDATE pay_pending SET gateway_ref = ?, qr_string = ?, raw = ? WHERE id = ?', [
        (string)($created['gateway_ref'] ?? ''), (string)($created['qr_string'] ?? ''),
        json_encode($created, JSON_UNESCAPED_UNICODE), $id,
    ]);
}

/**
 * Tandai LUNAS dan buat transaksinya (dipakai tombol "Cek Status"/konfirmasi
 * kasir pada halaman bayar dan oleh pay_webhook.php).
 *
 * @return array{ok:bool,order_id:int,invoice:string,error:string}
 */
function pay_pending_mark_paid(string $ref, string $via = 'manual'): array
{
    $out = ['ok' => false, 'order_id' => 0, 'invoice' => '', 'error' => ''];
    $p = pay_pending_by_ref($ref);
    if (!$p) { $out['error'] = 'Permintaan pembayaran tidak ditemukan.'; return $out; }
    if ($p['status'] === 'paid' && (int)$p['order_id'] > 0) {
        $row = one('SELECT invoice_number FROM orders WHERE id = ?', [(int)$p['order_id']]);
        return ['ok' => true, 'order_id' => (int)$p['order_id'], 'invoice' => (string)($row['invoice_number'] ?? ''), 'error' => ''];
    }
    if ($p['status'] !== 'pending') { $out['error'] = 'Permintaan pembayaran sudah berstatus ' . $p['status'] . '.'; return $out; }

    $payload = json_decode((string)$p['order_payload'], true);
    if (!is_array($payload)) { $out['error'] = 'Data transaksi pada permintaan ini rusak.'; return $out; }
    $payload['method'] = (string)$p['method'];
    $payload['pay_verified'] = '1';
    $payload['unique_code'] = (int)($payload['unique_code'] ?? 0);
    $payload['ref_no'] = $ref;

    $user = ['id' => (int)$p['user_id'], 'name' => (string)scalar('SELECT name FROM users WHERE id = ?', [(int)$p['user_id']], 'System')];
    try {
        $res = order_create($payload, $user, (int)$p['branch_id']);
    } catch (Throwable $e) {
        $out['error'] = 'Pembayaran lunas, tetapi transaksi gagal dibuat: ' . $e->getMessage();
        q('UPDATE pay_pending SET status = "paid_no_order", paid_at = datetime("now","localtime"), raw = ? WHERE id = ?',
            [json_encode(['error' => $e->getMessage(), 'via' => $via], JSON_UNESCAPED_UNICODE), (int)$p['id']]);
        return $out;
    }
    q('UPDATE pay_pending SET status = "paid", paid_at = datetime("now","localtime"), order_id = ?, raw = ? WHERE id = ?',
        [(int)$res['order_id'], json_encode(['via' => $via, 'invoice' => $res['invoice']], JSON_UNESCAPED_UNICODE), (int)$p['id']]);
    audit('Pembayaran Diterima', 'Kasir', (int)$res['order_id'],
        ['ref' => $ref, 'status' => 'pending'], ['status' => 'paid', 'via' => $via],
        'Pembayaran ' . (string)$p['method'] . ' lunas — transaksi ' . $res['invoice'] . ' dibuat');
    return ['ok' => true, 'order_id' => (int)$res['order_id'], 'invoice' => (string)$res['invoice'], 'error' => ''];
}
