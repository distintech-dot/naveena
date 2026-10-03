<?php
/**
 * KEAMANAN LOGIN — "Ingat saya", batas tidak aktif, verifikasi 2 langkah (2FA),
 * lupa password, dan kode pemulihan.
 * =====================================================================
 *
 * Permintaan pemilik klinik (ronde 38):
 *   1. Tombol "Ingat saya": bila dicentang sesi bertahan (bawaan 1 hari, pilihan
 *      1/3/7 hari); bila TIDAK dicentang, pengguna otomatis keluar setelah tidak
 *      ada aktivitas (bawaan 3 jam, pilihan 1/3/5/8 jam). Keduanya diatur di
 *      Developer Settings.
 *   2. Verifikasi 2 langkah memakai Google Authenticator (TOTP) — HANYA bisa
 *      diaktifkan setelah pemilik benar-benar memindai QR dan kodenya terbukti
 *      benar; bila email belum bisa mengirim, aktivasi TIDAK boleh diklaim aktif.
 *      Kode lewat email hanya menjadi CADANGAN. Level yang wajib 2FA dapat diatur
 *      (bawaan: Super Admin saja).
 *   3. Lupa password lewat tautan email + halaman ganti sandi bertoken.
 *   4. Kode pemulihan sekali-pakai (bekerja walau email belum dikonfigurasi).
 *
 * Catatan keamanan: seluruh token/kode disimpan dalam bentuk HASH (tidak pernah
 * teks asli), masa berlaku dibatasi, dan pemakaian dicatat di Audit Log.
 */
declare(strict_types=1);

/** Lingkup 2FA yang tersedia (level peran). */
function twofa_level_options(): array
{
    return [
        'super_admin' => 'Super Admin saja',
        'owner' => 'Super Admin + Direktur/Owner',
        'all' => 'Semua level (termasuk Kasir)',
        'none' => 'Tidak wajib untuk siapa pun',
    ];
}

/** Setelan keamanan login (dibaca dari Pengaturan/Developer Settings). */
function login_security(): array
{
    $scope = (string)setting('second_factor_levels', 'super_admin');
    if (!array_key_exists($scope, twofa_level_options())) $scope = 'super_admin';
    $remember = (int)setting('remember_days', '1');
    if (!in_array($remember, [1, 3, 7], true)) $remember = 1;
    $idle = (int)setting('idle_hours', '3');
    if (!in_array($idle, [1, 3, 5, 8], true)) $idle = 3;
    return [
        'enabled' => setting('login_security_enabled', '1') === '1',
        'scope' => $scope,
        'remember_days' => $remember,
        'idle_hours' => $idle,
        'idle_seconds' => $idle * 3600,
    ];
}

/** Apakah sebuah peran WAJIB memakai verifikasi 2 langkah? */
function twofa_required_for(?string $roleCode): bool
{
    $s = login_security();
    if ($s['scope'] === 'none') return false;
    $code = (string)$roleCode;
    if ($s['scope'] === 'super_admin') return $code === 'super_admin';
    if ($s['scope'] === 'owner') return in_array($code, ['super_admin', 'direktur'], true);
    return true;                                   // 'all'
}

/* ------------------------------------------------------------------ *
 * "INGAT SAYA" — token di cookie (bukan di sesi) supaya betul-betul
 * bertahan walau peramban ditutup, dengan masa berlaku yang diatur.
 * ------------------------------------------------------------------ */

/** Buat token "ingat saya" untuk seorang pengguna; mengembalikan nilai cookie. */
function remember_token_create(int $userId): string
{
    $selector = bin2hex(random_bytes(6));
    $validator = bin2hex(random_bytes(24));
    $days = login_security()['remember_days'];
    $exp = date('Y-m-d H:i:s', time() + $days * 86400);
    q('INSERT INTO login_remember (user_id, selector, token_hash, expires_at, created_at)
       VALUES (?,?,?,?,datetime("now","localtime"))',
        [$userId, $selector, hash('sha256', $validator), $exp]);
    return $selector . '.' . $validator;
}

/** Nama cookie "ingat saya" (dibuat dari nama aplikasi agar tidak bentrok). */
function remember_cookie_name(): string
{
    return 'nv_remember';
}

/** Set cookie "ingat saya" (dipakai baik saat login maupun saat diperpanjang). */
function remember_cookie_set(string $value, int $days): void
{
    $opt = [
        'expires' => time() + $days * 86400,
        'path' => '/',
        'secure' => (($_SERVER['HTTPS'] ?? '') !== ''),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        setcookie(remember_cookie_name(), $value, $opt);
    } else {
        setcookie(remember_cookie_name(), $value, $opt['expires'], '/', '', $opt['secure'], true);
    }
}

/** Hapus cookie & token "ingat saya" (dipakai saat logout). */
function remember_token_clear(?string $cookie = null): void
{
    $cookie = $cookie ?? (string)($_COOKIE[remember_cookie_name()] ?? '');
    if ($cookie !== '' && strpos($cookie, '.') !== false) {
        [$selector, ] = explode('.', $cookie, 2);
        try { q('DELETE FROM login_remember WHERE selector = ?', [$selector]); } catch (Throwable $e) { /* abaikan */ }
    }
    $opt = ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax'];
    if (PHP_VERSION_ID >= 70300) setcookie(remember_cookie_name(), '', $opt);
    else setcookie(remember_cookie_name(), '', time() - 3600, '/', '', false, true);
}

/**
 * Periksa cookie "ingat saya" dan kembalikan user_id yang sah (atau null).
 * Token lama (kedaluwarsa) dibersihkan.
 */
function remember_token_check(?string $cookie = null): ?int
{
    $cookie = $cookie ?? (string)($_COOKIE[remember_cookie_name()] ?? '');
    /* Cookie harus berbentuk "selector.validator" — tanpa titik berarti tidak sah. */
    if ($cookie === '' || strpos($cookie, '.') === false) return null;
    [$selector, $validator] = explode('.', $cookie, 2);
    try {
        q('DELETE FROM login_remember WHERE expires_at < datetime("now","localtime")');
        $row = one('SELECT * FROM login_remember WHERE selector = ?', [$selector]);
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) return null;
    if (!hash_equals((string)$row['token_hash'], hash('sha256', $validator))) {
        /* Validator tidak cocok (cookie dipalsukan) → buang semua token selector itu. */
        q('DELETE FROM login_remember WHERE selector = ?', [$selector]);
        return null;
    }
    if ((string)$row['expires_at'] < date('Y-m-d H:i:s')) {
        q('DELETE FROM login_remember WHERE id = ?', [(int)$row['id']]);
        return null;
    }
    q('UPDATE login_remember SET last_used_at = datetime("now","localtime") WHERE id = ?', [(int)$row['id']]);
    return (int)$row['user_id'];
}

/* ------------------------------------------------------------------ *
 * SESI: batas tidak aktif & auto-login dari cookie
 * ------------------------------------------------------------------ */

/** Tandai sesi aktif (dipanggil tiap permintaan saat pengguna sedang login). */
function session_mark_activity(): void
{
    if (empty($_SESSION['user_id'])) return;
    $_SESSION['last_seen'] = time();
}

/** Akhiri sesi karena tidak aktif / cookie habis, lalu arahkan ke halaman masuk. */
function session_force_logout(string $reason): void
{
    remember_token_clear();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['logout_notice'] = $reason;
    $target = 'login.php';
    if (!headers_sent()) {
        if (function_exists('wants_json_response') && wants_json_response()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $reason, 'relogin' => true], JSON_UNESCAPED_UNICODE);
        } else {
            $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            if ($path !== '' && basename($path) !== 'login.php') $target .= '?next=' . rawurlencode($path);
            header('Location: ' . $target);
        }
        exit;
    }
}

/**
 * Penjaga sesi yang dijalankan pada SETIAP permintaan (dari config.php).
 *
 *   • pengguna sudah login  → perbarui penanda aktivitas; bila TIDAK memakai
 *     "ingat saya" dan melewati batas tidak aktif, sesi diakhiri;
 *   • belum login + ada cookie "ingat saya" yang sah → masuk otomatis
 *     (token langsung diperpanjang supaya tetap hidup selama dipakai).
 */
function login_security_boot(): void
{
    try {
        $s = login_security();
        if (!empty($_SESSION['user_id'])) {
            if (!$s['enabled']) { $_SESSION['last_seen'] = time(); return; }
            $remember = !empty($_SESSION['remember_me']);
            if (!$remember) {
                $last = (int)($_SESSION['last_seen'] ?? 0);
                if ($last > 0 && (time() - $last) > $s['idle_seconds']) {
                    session_force_logout('Anda keluar otomatis karena tidak ada aktivitas selama '
                        . $s['idle_hours'] . ' jam. Silakan masuk kembali (centang "Ingat saya" bila ingin '
                        . 'tetap masuk tanpa batas tidak aktif).');
                }
            }
            session_mark_activity();
            return;
        }
        if (!$s['enabled']) return;
        $uid = remember_token_check();
        if ($uid === null) return;
        $u = one('SELECT id, status FROM users WHERE id = ?', [$uid]);
        if (!$u || (string)$u['status'] !== 'active') { remember_token_clear(); return; }
        /* Masuk otomatis dari cookie: sesi baru + token diperpanjang. */
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$u['id'];
        $_SESSION['remember_me'] = true;
        $_SESSION['last_seen'] = time();
        q("UPDATE users SET last_login = datetime('now','localtime') WHERE id = ?", [(int)$u['id']]);
    } catch (Throwable $e) {
        /* Jangan pernah mematikan aplikasi karena masalah tabel keamanan. */
    }
}

/* ------------------------------------------------------------------ *
 * VERIFIKASI 2 LANGKAH (2FA)
 * ------------------------------------------------------------------ */

/** Apakah pengguna ini sudah mengaktifkan & terverifikasi TOTP? */
function user_2fa_active(array $u): bool
{
    return (int)($u['totp_enabled'] ?? 0) === 1
        && trim((string)($u['totp_secret'] ?? '')) !== ''
        && trim((string)($u['totp_confirmed_at'] ?? '')) !== '';
}

/**
 * Apakah pengguna ini DIMINTA kode verifikasi saat login?
 * Syarat: levelnya wajib 2FA DAN perangkat authenticator-nya sudah terpasang &
 * terverifikasi. Jadi 2FA TIDAK PERNAH mengunci pengguna yang belum menyiapkan
 * perangkatnya (penting: email belum tentu bisa mengirim).
 */
function user_2fa_pending(array $u): bool
{
    return twofa_required_for((string)($u['role_code'] ?? '')) && user_2fa_active($u);
}

/** Kode cadangan lewat email: buat 6 angka, simpan sebagai hash. */
function twofa_email_code_send(array $u, ?string &$err = null): bool
{
    require_once __DIR__ . '/mailer.php';
    $to = trim((string)($u['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $err = 'Akun ini belum punya alamat email yang sah.';
        return false;
    }
    if (!mail_configured()) {
        $err = 'Email belum dikonfigurasi, jadi kode tidak dapat dikirim. Gunakan kode dari aplikasi '
            . 'Google Authenticator atau kode pemulihan.';
        return false;
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q('DELETE FROM login_2fa_codes WHERE user_id = ? AND purpose = "email"', [(int)$u['id']]);
    q('INSERT INTO login_2fa_codes (user_id, purpose, code_hash, expires_at, created_at)
       VALUES (?, "email", ?, ?, datetime("now","localtime"))',
        [(int)$u['id'], hash('sha256', $code), date('Y-m-d H:i:s', time() + 600)]);
    $html = '<p>Halo ' . e((string)$u['name']) . ',</p>'
        . '<p>Kode verifikasi (2 langkah) untuk masuk ke <strong>' . e(clinic_name()) . '</strong>:</p>'
        . '<p style="font-size:26px;letter-spacing:6px;font-weight:700;color:#B3261E">' . e($code) . '</p>'
        . '<p>Kode berlaku 10 menit. Bila Anda tidak merasa melakukan ini, segera ubah kata sandi Anda.</p>';
    $e = '';
    $ok = send_email($to, 'Kode Verifikasi Masuk — ' . clinic_name(), $html, $e);
    if (!$ok) $err = $e !== '' ? $e : 'Gagal mengirim kode.';
    audit('Kirim Kode 2FA Email', 'Auth', (int)$u['id'], null, ['terkirim' => $ok],
        $ok ? 'Kode verifikasi dikirim ke email terdaftar' : ('Gagal mengirim kode: ' . $e));
    return $ok;
}

/** Verifikasi kode TOTP (aplikasi authenticator) untuk seorang pengguna. */
function twofa_check_totp(array $u, string $kode): bool
{
    require_once __DIR__ . '/qr.php';
    $secret = (string)($u['totp_secret'] ?? '');
    if ($secret === '') return false;
    return totp_verify($secret, $kode);
}

/** Verifikasi KODE PEMULIHAN (sekali pakai) milik pengguna. */
function twofa_check_recovery(array $u, string $kode): bool
{
    $kode = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $kode)));
    if (strlen($kode) < 8) return false;
    return recovery_code_redeem((int)$u['id'], $kode);
}

/** Verifikasi kode email (cadangan) milik pengguna. */
function twofa_check_email(array $u, string $kode): bool
{
    $kode = preg_replace('/\D/', '', $kode);
    if ($kode === '') return false;
    $row = one('SELECT * FROM login_2fa_codes WHERE user_id = ? AND purpose = "email"
                ORDER BY id DESC LIMIT 1', [(int)$u['id']]);
    if (!$row) return false;
    if ((string)$row['expires_at'] < date('Y-m-d H:i:s')) return false;
    if (!hash_equals((string)$row['code_hash'], hash('sha256', $kode))) return false;
    q('DELETE FROM login_2fa_codes WHERE id = ?', [(int)$row['id']]);
    return true;
}

/* ------------------------------------------------------------------ *
 * KODE PEMULIHAN (recovery codes) — bekerja TANPA email
 * ------------------------------------------------------------------ */

/** Buat ulang kode pemulihan untuk seorang pengguna (mengembalikan kode asli). */
function recovery_codes_generate(int $userId, int $jumlah = 8): array
{
    q('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
    $out = [];
    for ($i = 0; $i < $jumlah; $i++) {
        /* Format mudah dibaca: 4-4 huruf/angka tanpa karakter mirip (0/O, 1/I). */
        $abjad = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $kode = '';
        for ($k = 0; $k < 8; $k++) $kode .= $abjad[random_int(0, strlen($abjad) - 1)];
        $out[] = substr($kode, 0, 4) . '-' . substr($kode, 4);
        q('INSERT INTO recovery_codes (user_id, code_hash, created_at)
           VALUES (?,?,datetime("now","localtime"))',
            [$userId, password_hash($kode, PASSWORD_DEFAULT)]);
    }
    audit('Buat Kode Pemulihan', 'Auth', $userId, null, ['jumlah' => $jumlah],
        'Kode pemulihan sekali-pakai dibuat ulang');
    return $out;
}

/** Pakai satu kode pemulihan (sekali pakai). */
function recovery_code_redeem(int $userId, string $kode): bool
{
    $kode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $kode));
    if (strlen($kode) !== 8) return false;
    $rows = all('SELECT * FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
    foreach ($rows as $r) {
        if (password_verify($kode, (string)$r['code_hash'])) {
            q('UPDATE recovery_codes SET used_at = datetime("now","localtime") WHERE id = ?', [(int)$r['id']]);
            audit('Pakai Kode Pemulihan', 'Auth', $userId, null, ['id' => (int)$r['id']],
                'Satu kode pemulihan dipakai untuk masuk');
            return true;
        }
    }
    return false;
}

/** Sisa kode pemulihan yang belum dipakai. */
function recovery_codes_remaining(int $userId): int
{
    try {
        return (int)scalar('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId], 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/* ------------------------------------------------------------------ *
 * LUPA PASSWORD (tautan ke email)
 * ------------------------------------------------------------------ */

/** Buat token ganti sandi untuk seorang pengguna (mengembalikan token asli). */
function password_reset_create(int $userId, int $menit = 60): string
{
    $token = bin2hex(random_bytes(32));
    q('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL', [$userId]);
    q('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
       VALUES (?,?,?,datetime("now","localtime"))',
        [$userId, hash('sha256', $token), date('Y-m-d H:i:s', time() + $menit * 60)]);
    return $token;
}

/** Cari permintaan ganti sandi yang sah dari token (belum dipakai & belum kedaluwarsa). */
function password_reset_find(string $token): ?array
{
    $token = trim($token);
    if (strlen($token) < 32) return null;
    $row = one('SELECT * FROM password_resets WHERE token_hash = ?', [hash('sha256', $token)]);
    if (!$row) return null;
    if ($row['used_at'] !== null) return null;
    if ((string)$row['expires_at'] < date('Y-m-d H:i:s')) return null;
    return $row;
}

/** Tandai token sudah dipakai (sekali pakai). */
function password_reset_consume(int $id): void
{
    q('UPDATE password_resets SET used_at = datetime("now","localtime") WHERE id = ?', [$id]);
}

/**
 * Kirim tautan ganti sandi ke email akun.
 *
 * Selalu mengembalikan true bila akun ada & email terkonfigurasi; pemanggil
 * TIDAK boleh membocorkan apakah sebuah alamat email terdaftar atau tidak.
 */
function password_reset_send(array $u, ?string &$err = null): bool
{
    require_once __DIR__ . '/mailer.php';
    $to = trim((string)($u['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { $err = 'Email akun tidak sah.'; return false; }
    if (!mail_configured()) {
        $err = 'Layanan email belum dikonfigurasi, jadi tautan tidak dapat dikirim. '
            . 'Minta Super Admin menyetel ulang kata sandi dari menu Manajemen User, atau gunakan kode pemulihan.';
        return false;
    }
    $token = password_reset_create((int)$u['id'], 60);
    $link = app_public_base() . '/reset_password.php?t=' . urlencode($token);
    $html = '<p>Halo ' . e((string)$u['name']) . ',</p>'
        . '<p>Ada permintaan mengganti kata sandi akun <strong>' . e($to) . '</strong> pada '
        . e(clinic_name()) . '.</p>'
        . '<p><a href="' . e($link) . '" style="display:inline-block;padding:11px 18px;background:#C2185B;'
        . 'color:#fff;border-radius:9px;text-decoration:none;font-weight:700">Ganti Kata Sandi</a></p>'
        . '<p>Tautan berlaku <strong>60 menit</strong> dan hanya dapat dipakai sekali. Bila Anda tidak '
        . 'meminta ini, abaikan email ini — kata sandi Anda tidak berubah.</p>'
        . '<p>Bila tombol di atas tidak bisa diklik, salin tautan ini ke peramban:<br>' . e($link) . '</p>';
    $e = '';
    $ok = send_email($to, 'Ganti Kata Sandi — ' . clinic_name(), $html, $e);
    if (!$ok) $err = $e !== '' ? $e : 'Gagal mengirim email.';
    audit('Permintaan Lupa Password', 'Auth', (int)$u['id'], null, ['terkirim' => $ok],
        $ok ? 'Tautan ganti sandi dikirim ke email terdaftar' : ('Gagal mengirim tautan: ' . $e));
    return $ok;
}
