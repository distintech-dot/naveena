<?php
/**
 * LOGIN MANAGEMENT — SIAPA YANG SEDANG MASUK & KENDALI PERANGKAT
 * =============================================================
 * Permintaan pemilik: di Developer Settings (di ATAS kartu "Ringkasan Cepat")
 * ada **Login Management** supaya Super Admin dapat melihat siapa saja yang
 * sedang masuk beserta:
 *   user · level · cabang · perangkat · browser · IP · waktu login · aktivitas
 *   terakhir · status sesi aktif,
 * dan dapat **mengeluarkan perangkat tertentu**, **memblokir perangkat**, atau
 * **mengeluarkan SEMUA perangkat milik satu pengguna**.
 *
 * CARA KERJA (sesi PHP berbasis berkas, jadi tidak bisa "dihapus" dari luar):
 *   • setiap kali seseorang BERHASIL masuk, sesinya dicatat di tabel `user_sessions`
 *     beserta sidik perangkat (cookie `nv_device`) + IP + user agent;
 *   • setiap permintaan memperbarui `last_activity` sesi yang bersangkutan;
 *   • "keluarkan" = menandai sesi itu `revoked`; pada permintaan berikutnya sesi
 *     tersebut mendapati dirinya dicabut lalu dipaksa keluar (lihat
 *     login_manage_boot()) — jadi tidak perlu menyentuh berkas sesi PHP sama sekali;
 *   • "blokir perangkat" mencatat sidik perangkat di `login_blocks`; percobaan masuk
 *     berikutnya dari perangkat itu DITOLAK dengan alasan yang jelas.
 *
 * Isinya GLOBAL (semua pengguna ada di basis data central), bukan per cabang.
 */

/* ------------------------------------------------------------------ *
 * SIDIK PERANGKAT
 * ------------------------------------------------------------------ */

/**
 * Sidik perangkat: cookie `nv_device` yang bertahan lama.
 *
 * Dipakai untuk memblokir perangkat, jadi harus STABIL antar sesi. IP tidak dipakai
 * karena bisa berubah (dan satu kantor biasanya berbagi satu IP publik — memblokir
 * berdasarkan IP bisa mengunci seluruh staf).
 */
function device_id(): string
{
    static $id = null;
    if ($id !== null) return $id;
    $nama = 'nv_device';
    $cookie = (string)($_COOKIE[$nama] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $cookie)) { $id = $cookie; return $id; }
    /* Belum ada / tidak sah → buat baru dan kirim sebagai cookie jangka panjang. */
    $baru = bin2hex(random_bytes(16));
    if (!headers_sent()) {
        setcookie($nama, $baru, [
            'expires' => time() + 400 * 86400,
            'path' => '/',
            'secure' => (($_SERVER['HTTPS'] ?? '') !== ''),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $_COOKIE[$nama] = $baru;
    $id = $baru;
    return $id;
}

/** Keterangan perangkat/peramban dari User-Agent (tanpa pustaka luar). */
function device_parse(?string $ua = null): array
{
    $ua = (string)($ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $sistem = 'Perangkat tidak dikenal';
    foreach ([
        'Windows NT 10' => 'Windows 10/11', 'Windows' => 'Windows', 'Android' => 'Android',
        'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Mac OS X' => 'macOS', 'Macintosh' => 'macOS',
        'Linux' => 'Linux', 'CrOS' => 'ChromeOS',
    ] as $cari => $label) {
        if (stripos($ua, $cari) !== false) { $sistem = $label; break; }
    }
    $browser = 'Peramban lain';
    $versi = '';
    foreach ([
        'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Chrome/' => 'Chrome', 'Firefox/' => 'Firefox',
        'Version/' => 'Safari', 'MSIE ' => 'Internet Explorer',
    ] as $cari => $label) {
        if (($pos = stripos($ua, $cari)) !== false) {
            $browser = $label;
            if (preg_match('/' . preg_quote($cari, '/') . '([0-9]+(?:\.[0-9]+)?)/i', $ua, $m)) $versi = $m[1];
            break;
        }
    }
    /* URUTAN PENTING: iPad lebih dulu daripada "Mobile" — UA iPad MEMUAT kata
       "Mobile" sehingga pemeriksaan ponsel yang dijalankan lebih dulu membuat iPad
       terdeteksi sebagai Ponsel. */
    $jenis = 'Komputer';
    if (preg_match('/iPad|Tablet/i', $ua)) $jenis = 'Tablet';
    elseif (preg_match('/Mobile|Android|iPhone/i', $ua)) $jenis = 'Ponsel';

    $model = device_model_name($ua);
    /* Label: MODEL perangkat lebih dulu (permintaan pemilik: "tahu tipe perangkat
       yang digunakan seperti iPhone 14 Pro / Tecno Pova / Samsung S25"), lalu
       peramban — nama sistem TIDAK diulang bila sudah tercakup oleh modelnya
       (dulu terbaca "iPhone 14 Pro Max · iPhone · Peramban lain"). */
    $peramban = $browser . ($versi !== '' ? ' ' . $versi : '');
    $utama = $model !== '' ? $model : $sistem;
    if ($model !== '' && $sistem !== '' && stripos($model, $sistem) === false && stripos($sistem, 'Android') === false) {
        $utama .= ' (' . $sistem . ')';
    }
    $label = $utama . ' · ' . $peramban;
    return ['sistem' => $sistem, 'browser' => $browser, 'versi' => $versi,
        'jenis' => $jenis, 'label' => $label, 'ua' => $ua, 'model' => $model];
}

/**
 * NAMA MODEL perangkat dari User-Agent (best effort) — dipakai kartu Login
 * Management supaya Super Admin tahu perangkat apa yang sedang masuk.
 *
 * Batasnya JUJUR: hanya iPhone/iPad yang menyebut modelnya sendiri di UA
 * (`iPhone15,3`). Ponsel Android TIDAK menyebut model di UA (contoh nyata:
 * `Android 14; SM-S931B` — kode modelnya yang muncul, bukan "Samsung S25"),
 * jadi nama pemasaran dipetakan dari kode model untuk merek yang umum
 * (Samsung/Xiaomi/Oppo/Vivo/Realme/Infinix/Tecno) dan sisanya dilaporkan
 * sebagai kode model apa adanya — tidak pernah dikarang.
 */
function device_model_name(string $ua): string
{
    if ($ua === '') return '';

    /* ---- Apple: UA iPhone menyebut generasi perangkat keras (iPhone15,3) ---- */
    if (preg_match('/iPhone(\d+),(\d+)/', $ua, $m)) {
        $kode = 'iPhone' . $m[1] . ',' . $m[2];
        $peta = [
            'iPhone14,7' => 'iPhone 14', 'iPhone14,8' => 'iPhone 14 Plus',
            'iPhone15,2' => 'iPhone 14 Pro', 'iPhone15,3' => 'iPhone 14 Pro Max',
            'iPhone14,2' => 'iPhone 13 Pro', 'iPhone14,3' => 'iPhone 13 Pro Max',
            'iPhone14,5' => 'iPhone 13', 'iPhone14,4' => 'iPhone 13 mini',
            'iPhone13,2' => 'iPhone 12', 'iPhone13,3' => 'iPhone 12 Pro',
            'iPhone13,4' => 'iPhone 12 Pro Max', 'iPhone13,1' => 'iPhone 12 mini',
            'iPhone12,1' => 'iPhone 11', 'iPhone12,3' => 'iPhone 11 Pro',
            'iPhone12,5' => 'iPhone 11 Pro Max',
            'iPhone11,2' => 'iPhone XS', 'iPhone11,4' => 'iPhone XS Max',
            'iPhone11,6' => 'iPhone XS Max', 'iPhone11,8' => 'iPhone XR',
            'iPhone10,3' => 'iPhone X', 'iPhone10,6' => 'iPhone X',
            'iPhone16,1' => 'iPhone 15 Pro', 'iPhone16,2' => 'iPhone 15 Pro Max',
            'iPhone15,4' => 'iPhone 15', 'iPhone15,5' => 'iPhone 15 Plus',
            'iPhone17,3' => 'iPhone 16', 'iPhone17,4' => 'iPhone 16 Plus',
            'iPhone17,1' => 'iPhone 16 Pro', 'iPhone17,2' => 'iPhone 16 Pro Max',
        ];
        return $peta[$kode] ?? ('iPhone (' . $kode . ')');
    }
    if (preg_match('/iPad(\d+),(\d+)/', $ua, $m)) return 'iPad (' . $m[1] . ',' . $m[2] . ')';
    if (stripos($ua, 'Macintosh') !== false) return 'Mac';

    /* ---- Android: ambil kode model dari UA ---- */
    $kode = '';
    if (preg_match('/Android[^;]*;\s*([^;)]+?)(?:\s+Build|\s+MIUI|\s+OPM|;|\))/i', $ua, $m)) {
        $kode = trim((string)$m[1]);
    }
    if ($kode === '' && preg_match('/;\s*([A-Za-z0-9][A-Za-z0-9 _.+-]{2,30})\s+Build\//i', $ua, $m)) {
        $kode = trim((string)$m[1]);
    }
    if ($kode === '') return '';

    /* Kode model Samsung bukan nomor telepon; jangan ikutkan yang bentuknya angka murni. */
    $kodeBersih = preg_replace('/\s+/', ' ', $kode);
    $kodeBersih = preg_replace('/^(id|in|en|en-US)$/i', '', $kodeBersih);
    $kodeBersih = trim((string)$kodeBersih);
    if ($kodeBersih === '' || preg_match('/^\d+$/', $kodeBersih)) return '';

    /* Samsung: SM-S931B → Galaxy S25 (dipetakan untuk seri yang umum). */
    $up = strtoupper($kodeBersih);
    $samsung = [
        'SM-S938' => 'Samsung Galaxy S25 Ultra', 'SM-S936' => 'Samsung Galaxy S25+',
        'SM-S931' => 'Samsung Galaxy S25',
        'SM-S928' => 'Samsung Galaxy S24 Ultra', 'SM-S926' => 'Samsung Galaxy S24+',
        'SM-S921' => 'Samsung Galaxy S24',
        'SM-S918' => 'Samsung Galaxy S23 Ultra', 'SM-S916' => 'Samsung Galaxy S23+',
        'SM-S911' => 'Samsung Galaxy S23',
        'SM-A546' => 'Samsung Galaxy A54', 'SM-A556' => 'Samsung Galaxy A55',
        'SM-A356' => 'Samsung Galaxy A35', 'SM-A156' => 'Samsung Galaxy A15',
        'SM-A057' => 'Samsung Galaxy A05s', 'SM-A065' => 'Samsung Galaxy A06',
        'SM-N986' => 'Samsung Galaxy Note 20 Ultra', 'SM-N981' => 'Samsung Galaxy Note 20',
    ];
    foreach ($samsung as $pref => $nama) {
        if (strpos($up, $pref) === 0) return $nama . ' (' . $kodeBersih . ')';
    }
    if (strpos($up, 'SM-') === 0) return 'Samsung (' . $kodeBersih . ')';

    /* Merek lain: kode model apa adanya — TIDAK diterjemahkan supaya tidak salah. */
    $merek = '';
    foreach (['TECNO' => 'Tecno', 'INFINIX' => 'Infinix', 'REDMI' => 'Redmi', 'POCO' => 'POCO',
              'M2101' => 'Xiaomi', 'M2007' => 'Xiaomi', 'M2012' => 'Xiaomi',
              'CPH' => 'Oppo', 'V21' => 'Vivo', 'RMX' => 'Realme', 'NOKIA' => 'Nokia',
              'MOTOROLA' => 'Motorola', 'OPPO' => 'Oppo', 'VIVO' => 'Vivo'] as $cari => $label) {
        if (stripos($kodeBersih, $cari) === 0 || stripos($ua, $cari) !== false) { $merek = $label; break; }
    }
    /* Tecno/Infinix biasanya menyebut namanya di UA (mis. "TECNO KJ5"). */
    if (preg_match('/TECNO\s+([A-Za-z0-9-]+)/i', $ua, $m)) return 'Tecno ' . strtoupper($m[1]);
    if (preg_match('/Infinix\s+([A-Za-z0-9-]+)/i', $ua, $m)) return 'Infinix ' . strtoupper($m[1]);
    return ($merek !== '' ? $merek . ' ' : '') . $kodeBersih;
}

/**
 * Alamat IP pengguna (hanya untuk keterangan di daftar sesi).
 *
 * TIDAK dipakai sebagai dasar pemblokiran — satu kantor biasanya berbagi satu IP
 * publik sehingga memblokir berdasarkan IP bisa mengunci seluruh staf.
 */
function client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip !== '' ? $ip : '-';
}

/* ------------------------------------------------------------------ *
 * PENCATATAN SESI
 * ------------------------------------------------------------------ */

/** Sidik sesi PHP saat ini (hash — isi sesi tidak pernah disimpan). */
function session_sid_hash(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    $sid = (string)session_id();
    return $sid !== '' ? hash('sha256', $sid) : '';
}

/**
 * Catat sesi yang BARU masuk (dipanggil dari login.php setelah autentikasi).
 * Idempoten: bila sidik sesi ini sudah tercatat, barisnya diperbarui saja.
 */
function session_register(int $userId, string $via = 'password'): void
{
    try {
        $sid = session_sid_hash();
        if ($sid === '') return;
        $dev = device_id();
        $p = device_parse();
        $bid = (int)scalar('SELECT branch_id FROM users WHERE id = ?', [$userId], 0);
        $ada = one('SELECT id FROM user_sessions WHERE sid_hash = ? ORDER BY id DESC LIMIT 1', [$sid]);
        if ($ada) {
            q('UPDATE user_sessions SET user_id = ?, branch_id = ?, device_id = ?, ip = ?, user_agent = ?,
                      device_label = ?, browser = ?, platform = ?, jenis = ?, via = ?,
                      login_at = datetime("now","localtime"), last_activity = datetime("now","localtime"),
                      logout_at = NULL, revoked = 0, revoke_reason = NULL
                WHERE id = ?',
                [$userId, $bid, $dev, client_ip(), $p['ua'], $p['label'], $p['browser'], $p['sistem'],
                 $p['jenis'], $via, (int)$ada['id']]);
            return;
        }
        q('INSERT INTO user_sessions
            (user_id, branch_id, sid_hash, device_id, ip, user_agent, device_label, browser, platform,
             jenis, via, login_at, last_activity)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime("now","localtime"),datetime("now","localtime"))',
            [$userId, $bid, $sid, $dev, client_ip(), $p['ua'], $p['label'], $p['browser'], $p['sistem'],
             $p['jenis'], $via]);
    } catch (Throwable $e) {
        /* Jangan pernah menggagalkan proses login karena pencatatan sesi. */
    }
}

/** Perbarui waktu aktivitas sesi yang sedang dipakai (dipanggil tiap permintaan). */
function session_touch(): void
{
    static $sudah = false;
    if ($sudah) return;
    $sudah = true;
    try {
        $sid = session_sid_hash();
        if ($sid === '') return;
        q('UPDATE user_sessions SET last_activity = datetime("now","localtime")
            WHERE sid_hash = ? AND logout_at IS NULL AND revoked = 0', [$sid]);
    } catch (Throwable $e) { /* abaikan */ }
}

/** Tandai sesi yang sedang dipakai berakhir (dipanggil dari logout.php). */
function session_end_current(string $alasan = 'Keluar sendiri'): void
{
    try {
        $sid = session_sid_hash();
        if ($sid === '') return;
        q('UPDATE user_sessions SET logout_at = datetime("now","localtime"), revoke_reason = ?
            WHERE sid_hash = ? AND logout_at IS NULL', [$alasan, $sid]);
    } catch (Throwable $e) { /* abaikan */ }
}

/**
 * Apakah sesi yang sedang dipakai sudah DICABUT / perangkatnya diblokir?
 * Dipanggil setiap permintaan; bila ya, sesi dipaksa berakhir.
 *
 * @return array{alasan:string,jenis:string} jenis: '' (aman) · 'revoked' · 'blocked'
 */
function session_revocation_check(): array
{
    try {
        $sid = session_sid_hash();
        if ($sid === '') return ['alasan' => '', 'jenis' => ''];
        $row = one('SELECT * FROM user_sessions WHERE sid_hash = ? ORDER BY id DESC LIMIT 1', [$sid]);
        if (!$row) return ['alasan' => '', 'jenis' => ''];
        if ((int)$row['revoked'] === 1) {
            return ['alasan' => trim((string)$row['revoke_reason']) !== ''
                ? (string)$row['revoke_reason']
                : 'Sesi ini diakhiri oleh Super Admin.', 'jenis' => 'revoked'];
        }
        $blk = device_block_for((int)$row['user_id'], (string)$row['device_id']);
        if ($blk) {
            return ['alasan' => 'Perangkat ini DIBLOKIR' . (trim((string)$blk['reason']) !== ''
                ? ': ' . trim((string)$blk['reason']) : '.') . ' Hubungi Super Admin bila menurut Anda ini keliru.',
                'jenis' => 'blocked'];
        }
    } catch (Throwable $e) { /* abaikan */ }
    return ['alasan' => '', 'jenis' => ''];
}

/* ------------------------------------------------------------------ *
 * BLOKIR PERANGKAT
 * ------------------------------------------------------------------ */

/**
 * Blokir yang berlaku untuk pengguna tertentu. `user_id = 0` berarti blokir untuk
 * SEMUA pengguna pada perangkat itu.
 */
function device_block_for(int $userId, string $deviceId): ?array
{
    if ($deviceId === '') return null;
    $rows = all('SELECT * FROM login_blocks WHERE device_id = ? AND active = 1 ORDER BY id DESC', [$deviceId]);
    foreach ($rows as $r) {
        if ((int)$r['user_id'] === 0 || (int)$r['user_id'] === $userId) return $r;
    }
    return null;
}

/** Apakah perangkat ini diblokir untuk seorang pengguna (dipakai saat login). */
function device_blocked_reason(int $userId, ?string $deviceId = null): string
{
    $b = device_block_for($userId, $deviceId ?? device_id());
    if (!$b) return '';
    return 'Perangkat ini sedang DIBLOKIR'
        . ((int)$b['user_id'] === 0 ? ' untuk semua akun' : ' untuk akun ini')
        . (trim((string)$b['reason']) !== '' ? ': ' . trim((string)$b['reason']) : '.')
        . ' Hubungi Super Admin klinik.';
}

/** Tambahkan blokir perangkat (idempoten untuk kombinasi yang sama). */
function device_block_add(string $deviceId, int $userId, string $reason, int $oleh): int
{
    if ($deviceId === '') return 0;
    $ada = one('SELECT id FROM login_blocks WHERE device_id = ? AND user_id = ? AND active = 1',
        [$deviceId, $userId]);
    if ($ada) {
        q('UPDATE login_blocks SET reason = ?, created_by = ? WHERE id = ?', [$reason, $oleh, (int)$ada['id']]);
        return (int)$ada['id'];
    }
    q('INSERT INTO login_blocks (device_id, user_id, reason, created_by, active, created_at)
       VALUES (?,?,?,?,1,datetime("now","localtime"))', [$deviceId, $userId, $reason, $oleh]);
    return (int)db()->lastInsertId();
}

/** Cabut blokir perangkat. */
function device_block_remove(int $blockId, int $oleh): bool
{
    $b = one('SELECT * FROM login_blocks WHERE id = ?', [$blockId]);
    if (!$b) return false;
    q('UPDATE login_blocks SET active = 0, removed_by = ?, removed_at = datetime("now","localtime") WHERE id = ?',
        [$oleh, $blockId]);
    return true;
}

/* ------------------------------------------------------------------ *
 * MENGELUARKAN SESI
 * ------------------------------------------------------------------ */

/** Keluarkan satu sesi (perangkat) — sesi lain tidak tersentuh. */
function session_revoke(int $sessionId, int $oleh, string $alasan = 'Dikeluarkan Super Admin'): bool
{
    $s = one('SELECT * FROM user_sessions WHERE id = ?', [$sessionId]);
    if (!$s) return false;
    q('UPDATE user_sessions SET revoked = 1, revoked_by = ?, revoked_at = datetime("now","localtime"),
              revoke_reason = ? WHERE id = ?', [$oleh, $alasan, $sessionId]);
    return true;
}

/** Keluarkan SEMUA sesi aktif milik seorang pengguna. */
function session_revoke_user(int $userId, int $oleh, string $alasan = 'Semua sesi dikeluarkan Super Admin'): int
{
    $n = (int)scalar('SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND logout_at IS NULL AND revoked = 0',
        [$userId], 0);
    q('UPDATE user_sessions SET revoked = 1, revoked_by = ?, revoked_at = datetime("now","localtime"),
              revoke_reason = ? WHERE user_id = ? AND logout_at IS NULL AND revoked = 0',
        [$oleh, $alasan, $userId]);
    /* Token "ingat saya" ikut dibuang — tanpa ini perangkat yang dikeluarkan akan
       langsung masuk kembali dari cookie-nya. */
    try { q('DELETE FROM login_remember WHERE user_id = ?', [$userId]); } catch (Throwable $e) { /* abaikan */ }
    return $n;
}

/** Keluarkan satu sesi + buang token "ingat saya" milik pengguna itu. */
function session_revoke_with_remember(int $sessionId, int $oleh, string $alasan): bool
{
    $s = one('SELECT * FROM user_sessions WHERE id = ?', [$sessionId]);
    if (!$s) return false;
    $ok = session_revoke($sessionId, $oleh, $alasan);
    try { q('DELETE FROM login_remember WHERE user_id = ?', [(int)$s['user_id']]); } catch (Throwable $e) { /* abaikan */ }
    return $ok;
}

/* ------------------------------------------------------------------ *
 * DAFTAR & RINGKASAN (untuk halaman)
 * ------------------------------------------------------------------ */

/**
 * Daftar sesi terbaru. `$f` = ['aktif' => bool, 'q' => string, 'user' => int]
 *
 * Sebuah sesi dianggap AKTIF bila belum logout, belum dicabut, dan aktivitas
 * terakhirnya masih dalam batas `login_stale_minutes` (bawaan 15 menit) — sesi
 * yang perambannya sudah ditutup tanpa menekan Keluar tidak lagi disebut aktif.
 */
function session_list(array $f = [], int $limit = 100): array
{
    $w = ['1=1'];
    $p = [];
    if (!empty($f['aktif'])) {
        $w[] = 's.logout_at IS NULL AND s.revoked = 0 AND s.last_activity >= ?';
        $p[] = date('Y-m-d H:i:s', time() - login_stale_minutes() * 60);
    }
    if (!empty($f['q'])) {
        $w[] = '(u.name LIKE ? OR u.email LIKE ? OR s.ip LIKE ? OR s.device_label LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like, $like);
    }
    if (!empty($f['user'])) { $w[] = 's.user_id = ?'; $p[] = (int)$f['user']; }
    $limit = max(1, min(500, $limit));
    $where = implode(' AND ', $w);
    return all("SELECT s.*, u.name, u.email, u.status AS user_status, r.name AS role_name, r.code AS role_code,
                       b.name AS branch_name
                FROM user_sessions s
                JOIN users u ON u.id = s.user_id
                LEFT JOIN roles r ON r.id = u.role_id
                LEFT JOIN branches b ON b.id = s.branch_id
                WHERE {$where}
                ORDER BY (CASE WHEN s.logout_at IS NULL AND s.revoked = 0 THEN 0 ELSE 1 END),
                         s.last_activity DESC, s.id DESC
                LIMIT {$limit}", $p);
}

/** Ringkasan angka untuk kartu Login Management. */
function session_summary(): array
{
    $batas = date('Y-m-d H:i:s', time() - login_stale_minutes() * 60);
    $r = one("SELECT
        SUM(CASE WHEN logout_at IS NULL AND revoked = 0 AND last_activity >= ? THEN 1 ELSE 0 END) aktif,
        SUM(CASE WHEN logout_at IS NULL AND revoked = 0 AND last_activity <  ? THEN 1 ELSE 0 END) diam,
        SUM(CASE WHEN revoked = 1 THEN 1 ELSE 0 END) dicabut,
        SUM(CASE WHEN logout_at IS NOT NULL THEN 1 ELSE 0 END) keluar,
        COUNT(*) total FROM user_sessions", [$batas, $batas]);
    $blk = (int)scalar('SELECT COUNT(*) FROM login_blocks WHERE active = 1', [], 0);
    $usr = (int)scalar("SELECT COUNT(DISTINCT user_id) FROM user_sessions
                        WHERE logout_at IS NULL AND revoked = 0 AND last_activity >= ?", [$batas], 0);
    return ['aktif' => (int)($r['aktif'] ?? 0), 'diam' => (int)($r['diam'] ?? 0),
        'dicabut' => (int)($r['dicabut'] ?? 0), 'keluar' => (int)($r['keluar'] ?? 0),
        'total' => (int)($r['total'] ?? 0), 'blokir' => $blk, 'pengguna_aktif' => $usr];
}

/** Batas "sesi masih dianggap aktif" (menit) — dapat diatur Super Admin. */
function login_stale_minutes(): int
{
    $v = (int)setting('login_stale_minutes', '15');
    return $v >= 1 && $v <= 240 ? $v : 15;
}

/** Sesi mana yang milik pengguna yang sedang membuka halaman (untuk menandai "ini Anda"). */
function session_current_id(): int
{
    try {
        $sid = session_sid_hash();
        if ($sid === '') return 0;
        return (int)scalar('SELECT id FROM user_sessions WHERE sid_hash = ? ORDER BY id DESC LIMIT 1', [$sid], 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Daftar blokir aktif + nama pengguna yang diblokir. */
function device_block_list(): array
{
    return all('SELECT lb.*, u.name AS user_name, u.email AS user_email, a.name AS oleh_name
                FROM login_blocks lb
                LEFT JOIN users u ON u.id = lb.user_id
                LEFT JOIN users a ON a.id = lb.created_by
                WHERE lb.active = 1 ORDER BY lb.id DESC LIMIT 200');
}

/** Daftar pengguna (untuk pilihan "keluarkan semua perangkat user"). */
function login_manage_users(): array
{
    return all('SELECT u.id, u.name, u.email, u.status, r.name AS role_name, r.code AS role_code
                FROM users u LEFT JOIN roles r ON r.id = u.role_id
                ORDER BY u.name');
}

/* ------------------------------------------------------------------ *
 * PENJAGA PER PERMINTAAN (dipanggil dari login_security_boot())
 * ------------------------------------------------------------------ */

/**
 * Diperiksa setiap permintaan saat pengguna sedang login:
 *   1. sesinya dicabut / perangkatnya diblokir → paksa keluar dengan alasan jelas;
 *   2. sebaliknya, waktu aktivitasnya diperbarui.
 *
 * Aman: seluruh kesalahan diabaikan (tidak pernah mengganggu aplikasi).
 */
function login_manage_boot(): void
{
    if (empty($_SESSION['user_id'])) return;
    $cek = session_revocation_check();
    if ($cek['jenis'] !== '') {
        $_SESSION['login_blocked_notice'] = $cek['alasan'];
        session_force_logout($cek['alasan']);
        return;
    }
    session_touch();
}

/** Apakah seorang pengguna masih punya sesi aktif (dipakai untuk keterangan). */
function user_session_active_count(int $userId): int
{
    $batas = date('Y-m-d H:i:s', time() - login_stale_minutes() * 60);
    return (int)scalar('SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND logout_at IS NULL
                        AND revoked = 0 AND last_activity >= ?', [$userId, $batas], 0);
}
