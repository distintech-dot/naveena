<?php
/**
 * Naveena Skincare — Website Management System
 * Core bootstrap: database, session, auth, permissions, helpers.
 */
declare(strict_types=1);

/* Zona waktu klinik (Kaliwungu/Cepiring/Batang — Jawa Tengah, WIB/UTC+7).
 *
 * PENTING: date_default_timezone_set() hanya mengubah jam versi PHP. Fungsi
 * tanggal SQLite ('localtime' / datetime('now','localtime')) mengikuti zona
 * waktu PROSES (TZ), bukan pengaturan PHP. Karena server berjalan di UTC,
 * penulisan waktu lewat SQLite sebelumnya tersimpan 7 jam lebih lambat
 * daripada jam yang ditampilkan PHP — sehingga jam transaksi di Riwayat Order
 * tidak sesuai jam WIB. putenv('TZ=...') menyelaraskan keduanya. */
define('APP_TIMEZONE', 'Asia/Jakarta');
date_default_timezone_set(APP_TIMEZONE);
putenv('TZ=' . APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', '0');

define('APP_NAME', 'Naveena Skincare');
/**
 * ---- FORMAT PENOMORAN (permintaan pemilik klinik) ----
 * Semua nomor memakai 7 angka dan dimulai dari 1001001:
 *   pasien     : DP-<KODE>-1001001, 1001002, …   (awalan DP = Data Pasien)
 *   member     : MC-<KODE>-1001001, 1001002, …   (awalan MC = Member Card)
 *   rekam medis: RM-<KODE>-1001001, 1001002, …
 *   invoice    : OR-<KODE>-<YYMMDD>-1001001, … (tanggal 2 digit tahun + bulan + tanggal; awalan OR = Order)
 *   reservasi  : RES-<KODE>-<YYMMDD>-1001001, …
 * Nomor invoice & reservasi TIDAK direset per hari — serinya terus bertambah
 * (permintaan pemilik), sedangkan bagian tanggal tetap menunjukkan kapan
 * dokumen itu dibuat.
 */
const SEQ_START = 1001001;
const SEQ_DIGITS = 7;
define('APP_DIR', dirname(__DIR__));

/* ------------------------------------------------------------------ *
 * JALUR BASIS DATA = CENTRAL + SATU BERKAS PER CABANG
 * ------------------------------------------------------------------ *
 * Arsitektur aplikasi ini HANYA memakai `central.sqlite` (data global/sistem) +
 * satu `branch_XXX.sqlite` per cabang (data operasional) — lihat includes/db_route.php.
 * Tidak ada lagi satu berkas `data.sqlite`: jalur lamanya sudah DIHAPUS dari kode
 * supaya tidak ada satu pun jalur runtime yang dapat jatuh kembali ke sana.
 *
 * `DB_PATH` (konstanta lama yang masih dipakai beberapa tempat untuk
 * "identitas pemasangan" & nama berkas kunci pekerja) kini menunjuk basis data
 * CENTRAL — didefinisikan SETELAH modul manajer basis data dimuat (di bawah),
 * karena jalurnya berasal dari sana.
 */


/**
 * AKAR PROSES TERISOLASI (pengaman anti-kontaminasi produksi).
 *
 * Bila `NAVEENA_DB` diarahkan ke berkas LAIN (yang selalu dilakukan skrip uji &
 * alat baris perintah), maka SELURUH folder turunan — basis data central/cabang,
 * unggahan, dan backup — WAJIB ikut diarahkan ke sekitar berkas itu. Kalau tidak,
 * proses pembantu hanya mengalihkan "jalur identitas" sedangkan folder penyimpanan
 * tetap milik aplikasi TERBIT, sehingga pekerjaan uji menulis ke basis data
 * PRODUKSI. Ini bukan hipotetis: pernah terjadi — 24 pasien contoh
 * ("Pasien Responsif 1-1" …) beserta transaksinya masuk ke produksi karena satu
 * proses pembantu dijalankan dengan `NAVEENA_DB=…` tanpa pengalihan folder.
 *
 * @return string folder akar terisolasi, atau '' bila proses ini pemasangan normal.
 */
function nv_isolated_root(): string
{
    static $akar = null;
    if ($akar !== null) return $akar;
    $akar = '';
    $db = (string)getenv('NAVEENA_DB');
    if ($db === '') {
        /* Sebagian skrip uji hanya mengatur folder basis data (NAVEENA_DB_ROOT).
           Proses seperti itu juga TERISOLASI — jangan sampai dianggap pemasangan
           terbit (penjaga produksi pada skrip seed bergantung pada nilai ini). */
        $root = (string)getenv('NAVEENA_DB_ROOT');
        return $root !== '' ? rtrim($root, '/') : '';
    }
    $akar = rtrim(dirname($db), '/') . '/dbroot-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', basename($db));
    if (!is_dir($akar)) @mkdir($akar, 0770, true);
    return $akar;
}

/** Apakah proses ini BERJALAN TERISOLASI (bukan aplikasi terbit). */
function nv_isolated_mode(): bool
{
    return nv_isolated_root() !== '';
}

define('BACKUP_DIR', dirname(APP_DIR) . '/naveena_backups');
define('SCHEMA_VERSION', '1.37.1');
/** Naikkan angka ini setiap kali isi data/icd10.tsv atau data/icd9cm.tsv berubah,
 *  agar kamus pada database yang sudah terpasang ikut dimuat ulang otomatis. */
define('ICD_DATASET_VERSION', '2');

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/image.php';
require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/member.php';
require_once __DIR__ . '/appointment.php';
require_once __DIR__ . '/clinic.php';
require_once __DIR__ . '/patient.php';
require_once __DIR__ . '/retention.php';
/* AI Developer (ronde 41): bantu revisi/perbaikan/tambah fitur — hanya Super Admin. */
require_once __DIR__ . '/ai_playbook.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/ai_jobs.php';
require_once __DIR__ . '/db_manager.php';
/* `DB_PATH` = basis data UTAMA pemasangan ini = central.sqlite (lihat catatan di atas). */
define('DB_PATH', db_central_path());
require_once __DIR__ . '/template_default.php';
/* Gambar latar web (wallpaper daring, acak tiap halaman dimuat) — ronde 64. */
require_once __DIR__ . '/wallpaper.php';
require_once __DIR__ . '/db_route.php';
/* Modul migrasi lama (`db_migrate.php` & `db_route_migrate.php`) sudah DIHAPUS:
   keduanya hanya bertugas memindahkan data dari satu berkas `data.sqlite` yang kini
   tidak ada lagi, dan tidak dipanggil dari mana pun setelah arsitektur central+branch
   menjadi satu-satunya arsitektur. */
/* Pembersih tabel GLOBAL dari berkas cabang (migrasi arsitektur central+branch). */
require_once __DIR__ . '/branch_cleanup.php';
require_once __DIR__ . '/db_audit.php';
require_once __DIR__ . '/demo_filter.php';
/* Keamanan login: "ingat saya", batas tidak aktif, 2FA, lupa password, dan
   kode pemulihan. Dimuat lebih awal karena penjaga sesinya dijalankan di bawah. */
require_once __DIR__ . '/login_security.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/order_create.php';
require_once __DIR__ . '/upload_gc.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/package.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    // The system default (e.g. /var/lib/php/sessions) is not always present or
    // writable by the web user. Keep sessions in a writable directory that is
    // NOT inside the publicly served app folder, falling back to temp dir.
    $sessCandidates = [
        dirname(APP_DIR) . '/naveena_sessions',
        rtrim(sys_get_temp_dir(), '/') . '/naveena_sessions',
    ];
    foreach ($sessCandidates as $sessDir) {
        if (!is_dir($sessDir)) @mkdir($sessDir, 0770, true);
        if (is_dir($sessDir) && is_writable($sessDir)) {
            session_save_path($sessDir);
            break;
        }
    }
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_name('naveena_sid');
    session_start();
}

/* PRATINJAU AI DEVELOPER (ronde 44): saat aplikasi dijalankan sebagai SALINAN
   PRATINJAU (`ai_preview.php` memanggil berkas halaman lewat PHP CLI), sesi tidak
   ada sehingga setiap halaman akan mengalihkan ke halaman masuk. Konstanta ini
   hanya didefinisikan oleh proses pratinjau tersebut — aplikasi normal tidak
   pernah mendefinisikannya, jadi tidak ada perubahan perilaku di produksi.
   Pratinjau memakai SALINAN BASIS DATA di folder salinannya sendiri, sehingga
   tidak mungkin mengubah data asli. */
if (defined('AI_PREVIEW_USER_ID')) {
    $_SESSION['user_id'] = (int)AI_PREVIEW_USER_ID;
    $_SESSION['last_seen'] = time();
}

require_once __DIR__ . '/schema.php';

/* ------------------------------------------------------------------ *
 * Database
 * ------------------------------------------------------------------ */
/**
 * KONEKSI APLIKASI — SELALU central + satu basis data per cabang.
 *
 * Satu koneksi PDO dengan `main = central.sqlite`; setiap berkas cabang di-ATTACH
 * (`b1`, `b2`, …) dan tabel operasional disajikan lewat TEMP VIEW (lihat
 * includes/db_route.php). Tidak ada lagi mode "satu berkas data.sqlite".
 */
function db(): PDO
{
    return db_route_conn();
}

function q(string $sql, array $params = []): PDOStatement
{
    /* Pernyataan TULIS ke tabel operasional dikualifikasi ke berkas cabang yang benar;
       query BACA dilayani TEMP VIEW (lihat includes/db_route.php).
       Pemeriksaan awal yang murah menghindari pemrosesan regex pada setiap SELECT.
       Operasi massal tanpa pembatas cabang oleh akun lintas cabang (mis. menu
       "Hapus Semua Data") dipecah menjadi satu pernyataan per berkas cabang. */
    $daftar = [$sql];
    if (isset($sql[0])) {
        $c = $sql[0];
        if ($c === 'I' || $c === 'i' || $c === 'U' || $c === 'u' || $c === 'R' || $c === 'r'
            || $c === 'D' || $c === 'd' || $c === ' ' || $c === '\t' || $c === '\n') {
            $daftar = db_route_prepare_all($sql, $params);
        }
    }
    $st = null;
    foreach ($daftar as $satu) {
        $st = db()->prepare($satu);
        $st->execute($params);
    }
    return $st;
}
function one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}
function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}
function scalar(string $sql, array $params = [], $default = 0)
{
    $v = q($sql, $params)->fetchColumn();
    return ($v === false || $v === null) ? $default : $v;
}

/* ------------------------------------------------------------------ *
 * Query pada KONEKSI TERTENTU (basis data per cabang)
 * ------------------------------------------------------------------ *
 * Padanan q()/one()/all()/scalar() tetapi memakai koneksi yang diberikan. Dipakai modul
 * "anak" bersama db_conn_for_record() (includes/db_manager.php) supaya query-nya berjalan
 * pada basis data CABANG yang benar.
 *
 * PENTING: tabel operasional kini disajikan sebagai TEMP VIEW pada koneksi aplikasi
 * (central + berkas cabang di-ATTACH). Pernyataan TULIS lewat koneksi itu WAJIB
 * dikualifikasi ke berkas cabangnya — kalau tidak, SQLite menolaknya dengan
 * "cannot modify … because it is a view". Kesalahan ini pernah membuat status pasien
 * otomatis ("Baru" → "Lama") TIDAK PERNAH berjalan karena kegagalannya ditelan
 * try/catch di pemanggil. Karena itu q_on() menempuh jalur routing yang sama dengan q().
 */
function q_on(PDO $pdo, string $sql, array $params = []): PDOStatement
{
    $daftar = [$sql];
    $c = isset($sql[0]) ? $sql[0] : '';
    if (($c === 'I' || $c === 'i' || $c === 'U' || $c === 'u' || $c === 'R' || $c === 'r'
         || $c === 'D' || $c === 'd')
        && function_exists('db_route_prepare_all')) {
        try {
            /* Hanya untuk KONEKSI APLIKASI — koneksi berkas tersendiri (mis. dipakai
               pemeriksaan/ migrasi basis data) tidak boleh dialihkan. */
            if ($pdo === db()) $daftar = db_route_prepare_all($sql, $params);
        } catch (Throwable $e) { $daftar = [$sql]; }
    }
    $st = null;
    foreach ($daftar as $satu) {
        $st = $pdo->prepare($satu);
        $st->execute($params);
    }
    return $st;
}
function one_on(PDO $pdo, string $sql, array $params = []): ?array
{
    $r = q_on($pdo, $sql, $params)->fetch();
    return $r === false ? null : $r;
}
function all_on(PDO $pdo, string $sql, array $params = []): array
{
    return q_on($pdo, $sql, $params)->fetchAll();
}
function scalar_on(PDO $pdo, string $sql, array $params = [], $default = 0)
{
    $v = q_on($pdo, $sql, $params)->fetchColumn();
    return ($v === false || $v === null) ? $default : $v;
}

/* ------------------------------------------------------------------ *
 * Output helpers
 * ------------------------------------------------------------------ */
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function money($n, bool $prefix = true): string
{
    return ($prefix ? 'Rp ' : '') . number_format((float)$n, 0, ',', '.');
}
function num($n, int $dec = 0): string
{
    return number_format((float)$n, $dec, ',', '.');
}
/**
 * Jumlah barang (stok / qty / pemakaian) dengan desimal seperlunya.
 *
 * Penting karena stok bisa berupa pecahan — mis. bahan treatment 15 liter yang
 * dipakai 0,5 liter. `num()` membulatkan ke bilangan bulat (0,5 → "1"), jadi
 * SEMUA tampilan jumlah barang harus memakai helper ini.
 */
function qty_text($n): string
{
    $v = (float)$n;
    $dec = (abs($v - round($v)) < 0.00001) ? 0 : 2;
    $s = number_format($v, $dec, ',', '.');
    if ($dec === 2) $s = rtrim(rtrim($s, '0'), ',');   // 0,50 -> 0,5
    return $s;
}
/** Sama seperti qty_text(), tetapi menambahkan satuan bila diisi (mis. "0,5 liter"). */
function qty_unit($n, $unit = ''): string
{
    $s = qty_text($n);
    $u = trim((string)$unit);
    return $u === '' ? $s : $s . ' ' . $u;
}
/**
 * Membaca jumlah yang diketik pengguna: menerima "0,5" (koma desimal Indonesia),
 * "0.5", dan pemisah ribuan ("1.500"). Dipakai agar kolom jumlah pada Order Baru
 * bisa diisi 0,5 liter — PHP sendiri membaca "0,5" sebagai 0.
 */
function qty_parse($raw): float
{
    $s = preg_replace('/\s+/', '', trim((string)$raw));
    if ($s === '') return 0.0;
    $s = preg_replace('/[^0-9.,\-]/', '', $s);
    $dot = strrpos($s, '.');
    $com = strrpos($s, ',');
    if ($dot !== false && $com !== false) {
        if ($com > $dot) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        else { $s = str_replace(',', '', $s); }
    } elseif ($com !== false) {
        $s = str_replace(',', '.', $s);
    }
    return (float)$s;
}
function tgl($v, bool $withTime = false): string
{
    if (!$v) return '-';
    $ts = strtotime((string)$v);
    if (!$ts) return (string)$v;
    $bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $s = date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    if ($withTime) $s .= ' ' . date('H:i', $ts);
    return $s;
}
function tglIndo($v): string
{
    $ts = $v ? strtotime((string)$v) : time();
    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}
function badge($text, string $tone = 'gray'): string
{
    return '<span class="badge badge-' . e($tone) . '">' . e($text) . '</span>';
}
/** Warna badge untuk kode role (dipakai daftar user & matriks hak akses). */
/**
 * Keterangan level akun yang sedang masuk — dipakai pada pesan penolakan akses
 * supaya pengguna tahu SIAPA yang sedang masuk dan kenapa ditolak.
 */
function deny_role_detail(string $butuh = 'Super Admin'): string
{
    $u = current_user();
    if (!$u) return '';
    $peran = (string)($u['role_name'] ?? '');
    $cab = (string)($u['branch_name'] ?? '');
    return 'Akun Anda saat ini berlevel ' . ($peran !== '' ? $peran : (string)($u['role_code'] ?? '-'))
        . ($cab !== '' ? ' (' . $cab . ')' : '') . '. Halaman ini memerlukan level ' . $butuh . '. '
        . 'Bila Anda perlu memakainya, minta Super Admin menaikkan level akun Anda (Manajemen User → Hak Akses) '
        . 'atau masuk memakai akun yang berhak.';
}

function user_role_tone(string $code): string
{
    return [
        'super_admin'  => 'pink',
        'direktur'     => 'yellow',
        'admin_dokter' => 'blue',
        'kasir'        => 'green',
    ][$code] ?? 'gray';
}

/* ------------------------------------------------------------------ *
 * Settings
 * ------------------------------------------------------------------ */
function settings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (all('SELECT key, value FROM settings') as $r) {
            $cache[$r['key']] = $r['value'];
        }
    }
    return $cache;
}
function setting(string $key, $default = ''): string
{
    $s = settings();
    return array_key_exists($key, $s) ? (string)$s[$key] : (string)$default;
}
function set_setting(string $key, $value): void
{
    q('INSERT INTO settings (key, value) VALUES (:k, :v)
       ON CONFLICT(key) DO UPDATE SET value = excluded.value',
       [':k' => $key, ':v' => (string)$value]);
    settings(true);
}

/**
 * Alamat dasar aplikasi untuk menyusun tautan (mis. tautan struk di email).
 *
 * PENTING: web server platform memotong awalan slug (`/naveena`) SEBELUM
 * permintaan sampai ke PHP, sehingga $_SERVER['SCRIPT_NAME'] = '/email_struk.php'
 * sehingga tautan yang disusun dari dirname(SCRIPT_NAME) kehilangan awalan slug
 * dan menghasilkan 404 — ini pernah benar-benar terjadi pada tautan struk email.
 * Yang masih memuat awalan tersebut adalah $_SERVER['REQUEST_URI'], jadi awalan
 * dihitung sebagai selisih keduanya. Urutan sumber:
 *   1. setelan `public_base_url` (bila admin ingin memaksa alamat tertentu),
 *   2. header `X-Public-Base-Path` (dipakai sebagian proxy/platform),
 *   3. selisih REQUEST_URI dengan SCRIPT_NAME (cara yang bekerja di platform ini),
 *   4. folder skrip (perkiraan terakhir, mis. saat dijalankan tanpa proxy).
 */
function app_public_base(): string
{
    $set = trim((string)setting('public_base_url', ''));
    if ($set !== '') return rtrim($set, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') return '';

    $prefix = null;
    if (array_key_exists('HTTP_X_PUBLIC_BASE_PATH', $_SERVER)) {
        $prefix = '/' . trim((string)$_SERVER['HTTP_X_PUBLIC_BASE_PATH'], '/');
    } else {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $uri = (string)strtok($uri, '?');
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script !== '' && $uri !== '' && substr($uri, -strlen($script)) === $script) {
            $prefix = substr($uri, 0, strlen($uri) - strlen($script));
        } elseif ($script !== '') {
            $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
            $prefix = ($dir === '/' || $dir === '.') ? '' : $dir;
        }
    }
    if ($prefix === '/') $prefix = '';
    return ($https ? 'https://' : 'http://') . $host . (string)$prefix;
}

/* ------------------------------------------------------------------ *
 * Auth & permissions
 * ------------------------------------------------------------------ */
function current_user(): ?array
{
    static $u = null;
    if ($u !== null) return $u ?: null;
    if (empty($_SESSION['user_id'])) return null;
    $u = one('SELECT u.*, r.name AS role_name, r.code AS role_code, b.name AS branch_name, b.code AS branch_code
              FROM users u
              JOIN roles r ON r.id = u.role_id
              LEFT JOIN branches b ON b.id = u.branch_id
              WHERE u.id = :id', [':id' => $_SESSION['user_id']]);
    if (!$u || $u['status'] !== 'active') {
        session_destroy();
        $u = null;
    }
    return $u ?: null;
}
function is_super(): bool
{
    $u = current_user();
    return $u && $u['role_code'] === 'super_admin';
}
/** Kode role pengguna yang sedang login ('super_admin' | 'direktur' | 'admin_dokter' | 'kasir'). */
function role_code(?array $u = null): string
{
    $u = $u ?: current_user();
    return $u ? (string)($u['role_code'] ?? '') : '';
}
/** Direktur / Owner — level manajemen di bawah Super Admin. */
function is_direktur(?array $u = null): bool
{
    return role_code($u) === 'direktur';
}
/**
 * Level "pusat" = Super Admin & Direktur/Owner.
 *
 * Dipakai untuk hal-hal yang memang berlaku lintas cabang: cakupan data semua
 * cabang, pemilih cabang di topbar/form, filter cabang pada daftar & laporan.
 * BUKAN untuk tindakan sistem yang merusak (hapus permanen, restore backup,
 * hapus semua data, maintenance, kelola cabang) — itu tetap khusus Super Admin.
 */
function is_owner_level(?array $u = null): bool
{
    $c = role_code($u);
    return $c === 'super_admin' || $c === 'direktur';
}
/** Level manajemen (pusat + admin cabang) — dipakai untuk label/penjelasan. */
function is_manager_level(?array $u = null): bool
{
    return in_array(role_code($u), ['super_admin', 'direktur', 'admin_dokter'], true);
}
/**
 * Daftar role dalam URUTAN LEVEL (bukan urutan id database).
 *
 * Penting: pada database yang sudah terpasang, role "direktur" dibuat belakangan
 * sehingga id-nya paling besar. Kalau daftar diambil `ORDER BY id`, kolom
 * Direktur/Owner di matriks hak akses (dan pilihan filter) akan muncul paling
 * kanan — membingungkan. Helper ini menjaga urutan: Super Admin → Direktur /
 * Owner → Admin/Dokter → Kasir → lainnya.
 */
function roles_ordered(): array
{
    $urutan = ['super_admin' => 0, 'direktur' => 1, 'admin_dokter' => 2, 'kasir' => 3];
    $rows = all('SELECT * FROM roles');
    usort($rows, function ($a, $b) use ($urutan) {
        $wa = $urutan[$a['code']] ?? 9;
        $wb = $urutan[$b['code']] ?? 9;
        if ($wa !== $wb) return $wa <=> $wb;
        return strcmp((string)$a['name'], (string)$b['name']);
    });
    return $rows;
}

function user_perms(?array $u = null): array
{
    static $cache = [];
    $u = $u ?: current_user();
    if (!$u) return [];
    $id = (int)$u['id'];
    if (!isset($cache[$id])) {
        $cache[$id] = array_column(all(
            'SELECT p.code FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id = :r', [':r' => $u['role_id']]), 'code');
        // extra per-user grants
        foreach (all('SELECT p.code FROM permissions p
                      JOIN user_permissions up ON up.permission_id = p.id
                      WHERE up.user_id = :u', [':u' => $id]) as $r) {
            $cache[$id][] = $r['code'];
        }
    }
    return $cache[$id];
}
function has_perm(string $code): bool
{
    $u = current_user();
    if (!$u) return false;
    if ($u['role_code'] === 'super_admin') return true;
    return in_array($code, user_perms($u), true);
}
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}
function require_perm(string $code): void
{
    require_login();
    if (!has_perm($code)) {
        deny('Anda tidak memiliki hak akses untuk modul ini.');
    }
}
/**
 * Tolak akses dengan penjelasan yang JUJUR dan jelas.
 *
 * Ada DUA keadaan yang berbeda dan tidak boleh dicampur (perbaikan ronde 48):
 *   1. Pengguna BELUM MASUK (sesi berakhir / keluar / belum pernah login) —
 *      dulu halaman ini menampilkan "Akses Ditolak (403): hanya dapat dibuka oleh
 *      Super Admin", padahal masalahnya sesi habis. Sekarang pengguna diarahkan ke
 *      halaman masuk dengan keterangan sesi berakhir + kembali ke halaman tujuan.
 *   2. Pengguna SUDAH MASUK tetapi levelnya tidak berhak — barulah 403 ditampilkan,
 *      dengan keterangan level yang dibutuhkan dan tombol yang jelas.
 *
 * @param string $msg    pesan singkat untuk pengguna
 * @param string $detail keterangan tambahan (mis. level/izin yang dibutuhkan)
 */
function deny(string $msg = 'Anda tidak memiliki hak akses untuk halaman ini.', string $detail = ''): void
{
    $u = current_user();
    if (!$u) {
        /* Belum masuk / sesi berakhir. */
        if (is_ajax()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'relogin' => true,
                'error' => 'Sesi Anda sudah berakhir. Silakan masuk kembali.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['logout_notice'] = 'Sesi Anda sudah berakhir (atau Anda baru keluar dari aplikasi). '
            . 'Silakan masuk kembali untuk melanjutkan.';
        $target = 'login.php';
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $q = (string)($_SERVER['QUERY_STRING'] ?? '');
        if ($path !== '' && basename($path) !== 'login.php') {
            $target .= '?next=' . rawurlencode($path . ($q !== '' ? '?' . $q : ''));
        }
        if (!headers_sent()) { header('Location: ' . $target); exit; }
        http_response_code(401);
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Sesi berakhir</title>'
            . '<p style="font:15px system-ui;padding:24px">Sesi Anda sudah berakhir. '
            . '<a href="' . e($target) . '">Masuk kembali</a>.</p>';
        exit;
    }
    http_response_code(403);
    if (is_ajax()) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $msg, 'detail' => $detail]);
        exit;
    }
    $code = 403;
    include __DIR__ . '/error_page.php';
    exit;
}
function is_ajax(): bool
{
    return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || (($_GET['ajax'] ?? '') === '1');
}
/**
 * Branch scope of the logged-in user: null = all branches.
 *
 * Super Admin dan Direktur/Owner memakai cakupan semua cabang (dan dapat
 * mempersempitnya sendiri lewat pemilih cabang di topbar → $_SESSION['active_branch']).
 * Role lain selalu terpaut pada cabang miliknya.
 */
function user_branch(?array $u = null): ?int
{
    $u = $u ?: current_user();
    if (!$u) return -1;
    if (is_owner_level($u) && empty($_SESSION['active_branch'])) return null;
    if (is_owner_level($u) && !empty($_SESSION['active_branch'])) {
        return (int)$_SESSION['active_branch'];
    }
    return (int)($u['branch_id'] ?? 0);
}
/**
 * Returns [sql, params] to append to a WHERE clause for branch restriction.
 * $column e.g. "o.branch_id"
 */
function branch_sql(string $column, ?array $u = null): array
{
    $bid = user_branch($u);
    if ($bid === null) return ['', []];
    if ($bid === 0) return [' AND 1=0', []];
    return [" AND {$column} = ?", [$bid]];
}
/**
 * Branch scope that ALSO honours an explicit ?branch= filter.
 * Hanya Super Admin & Direktur/Owner yang boleh memakai filter ini; role lain
 * selalu dipin ke cabangnya walau URL memaksa ?branch=.
 */
function scope_branch(): ?int
{
    if (!is_owner_level()) return user_branch();
    $b = gp('branch');
    if ($b === '') return user_branch();
    if ($b === 'all') return null;
    return (int)$b;
}
function bscope(string $column): array
{
    $bid = scope_branch();
    if ($bid === null) return ['', []];
    if ($bid === 0) return [' AND 1=0', []];
    return [" AND {$column} = ?", [$bid]];
}
/** Guard: refuse any attempt to touch another branch's data. */
function assert_branch(int $branch_id): void
{
    $bid = user_branch();
    if ($bid !== null && $bid !== $branch_id) {
        deny('Anda tidak memiliki akses ke cabang ini.');
    }
}
function branch_code(int $branch_id): string
{
    static $c = [];
    if (!isset($c[$branch_id])) {
        $c[$branch_id] = (string)scalar('SELECT code FROM branches WHERE id = ?', [$branch_id], 'XX');
    }
    return $c[$branch_id];
}
function branches(): array
{
    static $b = null;
    if ($b === null) $b = all('SELECT * FROM branches ORDER BY id');
    return $b;
}
function selectable_branches(): array
{
    $bid = user_branch();
    $out = [];
    foreach (branches() as $b) {
        if ($bid === null || (int)$b['id'] === $bid) $out[] = $b;
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 * CSRF
 * ------------------------------------------------------------------ */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="_once" value="' . e(once_token()) . '">';
}

/**
 * Token SEKALI-PAKAI untuk mencegah data ganda (permintaan pemilik).
 *
 * Kasus nyata: jaringan lambat, pengguna mengklik tombol Simpan berkali-kali →
 * dua permintaan yang sama terkirim dan data tersimpan DUA KALI (mis. reservasi
 * ganda). Penjaga di sisi peramban saja tidak cukup (klik ganda, tombol Enter,
 * atau pengiriman ulang halaman), jadi token ini juga diperiksa di server.
 */
function once_token(): string
{
    $tok = bin2hex(random_bytes(8));
    $store = $_SESSION['once_tokens'] ?? [];
    $now = time();
    foreach ($store as $k => $ts) if ($ts < $now - 7200) unset($store[$k]);   // pangkas > 2 jam
    $store[$tok] = $now;
    if (count($store) > 60) {                                                // batasi pertumbuhan sesi
        asort($store);
        $store = array_slice($store, -60, null, true);
    }
    $_SESSION['once_tokens'] = $store;
    return $tok;
}

/** Apakah permintaan ini mengharapkan balasan JSON (AJAX), bukan halaman? */
function wants_json_response(): bool
{
    $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if ($xrw === 'xmlhttprequest') return true;
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (strpos($accept, 'application/json') !== false) return true;
    if ((string)($_POST['ajax'] ?? '') === '1' || (string)($_GET['ajax'] ?? '') === '1') return true;
    return false;
}

/**
 * Tolak permintaan GANDA (token `_once` yang sudah dipakai).
 *
 * Dipanggil otomatis dari verify_csrf() sehingga berlaku untuk SEMUA aksi
 * tambah/hapus/ubah di aplikasi ini tanpa perlu diubah satu per satu.
 */
function guard_single_submit(): void
{
    $tok = (string)($_POST['_once'] ?? '');
    /* Tanpa token (mis. AJAX lama yang hanya mengirim _csrf) tidak dijaga —
       supaya tidak ada pemanggil yang tiba-tiba gagal. */
    if ($tok === '') return;
    $now = time();
    $seen = $_SESSION['once_seen'] ?? [];
    foreach ($seen as $k => $ts) if ($ts < $now - 7200) unset($seen[$k]);      // pangkas > 2 jam
    $tokens = $_SESSION['once_tokens'] ?? [];

    if (isset($seen[$tok])) {                       // token ini SUDAH dipakai → duplikat
        $_SESSION['once_seen'] = $seen;
        if (wants_json_response()) {
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'duplicate' => true,
                'error' => 'Permintaan ini sudah diproses — data tidak dibuat dua kali.'],
                JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash('Permintaan ini sudah diproses sebelumnya, jadi TIDAK diproses ulang — data tidak dibuat dua kali (pencegahan duplikat).', 'warning');
        /* Kembali ke halaman asal (hanya path + query, aman dari pengalihan luar). */
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $query = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
        if ($path === '' || strpos($path, '..') !== false) $path = 'dashboard.php';
        header('Location: ' . $path . ($query !== '' ? '?' . $query : ''));
        exit;
    }
    /* Token tidak dikenal (halaman lama / sesi kedaluwarsa) → biarkan verifikasi
       CSRF yang memutuskan; jangan memblokir di sini. */
    if (!isset($tokens[$tok])) return;

    unset($tokens[$tok]);                            // sekali pakai
    $seen[$tok] = $now;
    $_SESSION['once_tokens'] = $tokens;
    $_SESSION['once_seen'] = $seen;
}

function verify_csrf(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!$t || !hash_equals($_SESSION['csrf'] ?? '', (string)$t)) {
        /* Pesan dibedakan supaya pengguna tahu apa yang harus dilakukan:
           • $_POST benar-benar KOSONG  → biasanya halaman dibuka ulang tanpa data
             (mis. alat uji/peramban mengirim ulang permintaan tanpa isi) atau sesi
             sudah berganti → cukup muat ulang halaman lalu ulangi tindakan;
           • $_POST ada isinya tetapi tokennya tidak cocok → sesi berganti di tengah
             jalan (mis. login ulang di tab lain). */
        if (!$t) {
            deny('Permintaan Anda tidak terkirim lengkap sehingga tidak dapat diproses.',
                'Halaman yang dikirim tidak memuat data apa pun. Ini biasanya terjadi bila '
                . 'halaman dimuat ulang tanpa mengisi form, atau sesi Anda baru berganti. '
                . 'Muat ulang halaman (tombol di bawah), lalu ulangi tindakan tadi — '
                . 'data Anda TIDAK tersimpan setengah jalan.');
        }
        deny('Sesi Anda berganti sehingga tombol ini tidak dapat diproses (keamanan).',
            'Token keamanan halaman ini tidak lagi cocok dengan sesi Anda — biasanya karena '
            . 'Anda masuk ulang di tab/perangkat lain. Muat ulang halaman lalu ulangi tindakan '
            . 'tadi. Tidak ada data yang tersimpan setengah jalan.');
    }
    /* Pencegahan data ganda (mis. klik Simpan berkali-kali di jaringan lambat). */
    guard_single_submit();
}

/* ------------------------------------------------------------------ *
 * Flash messages
 * ------------------------------------------------------------------ */
function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}
function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------------ *
 * Audit log & inventory movement
 * ------------------------------------------------------------------ */
function audit(string $action, string $module, $record_id = null, $old = null, $new = null, string $reason = ''): void
{
    $u = current_user();
    q('INSERT INTO audit_logs (user_id, user_name, user_role, branch_id, action, module, record_id, old_value, new_value, reason, ip_address, created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime("now","localtime"))', [
        $u['id'] ?? null,
        $u['name'] ?? 'system',
        $u['role_name'] ?? '',
        $u['branch_id'] ?? null,
        $action,
        $module,
        $record_id !== null ? (string)$record_id : null,
        $old === null ? null : (is_string($old) ? $old : json_encode($old, JSON_UNESCAPED_UNICODE)),
        $new === null ? null : (is_string($new) ? $new : json_encode($new, JSON_UNESCAPED_UNICODE)),
        $reason ?: null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

const MOVEMENT_TYPES = ['Stok Awal', 'Pembelian', 'Penjualan', 'Penambahan', 'Pengurangan', 'Adjustment', 'Return', 'Barang Rusak', 'Koreksi', 'Pemakaian Internal'];

/**
 * Teks "Dokter / Terapis" untuk sebuah baris data.
 *
 * Satu pemeriksaan/reservasi bisa melibatkan DOKTER dan TERAPIS sekaligus,
 * jadi keduanya ditampilkan (dulu hanya salah satu karena memakai
 * COALESCE(d.name, th.name) — terapis tidak pernah terlihat bila ada dokter).
 */
function staff_both_text(?string $doctor, ?string $therapist, string $sep = ' · '): string
{
    $d = trim((string)$doctor);
    $t = trim((string)$therapist);
    if ($d !== '' && $t !== '') return $d . $sep . $t;
    if ($d !== '') return $d;
    if ($t !== '') return $t;
    return '';
}
const PAY_METHODS = ['Cash', 'Transfer', 'QRIS', 'Debit', 'Kredit', 'Lainnya'];
const RES_STATUSES = ['Menunggu', 'Confirmed', 'Hadir', 'Selesai', 'Cancel', 'No Show'];

/**
 * Apply a signed stock change to a skincare product or treatment material,
 * record the inventory movement, and keep the mirrored inventory row in sync.
 * MUST be called inside a transaction.
 */
function inv_apply(string $item_type, int $item_id, float $qty, string $type, string $reason = '', array $extra = []): array
{
    $table = $item_type === 'skincare' ? 'skincare_products' : 'treatment_materials';
    $row = one("SELECT * FROM {$table} WHERE id = ?", [$item_id]);
    if (!$row) throw new RuntimeException('Item inventory tidak ditemukan.');
    $before = (float)$row['stock'];
    $after  = $before + $qty;
    if ($after < 0 && empty($extra['allow_negative'])) {
        if ($qty < 0) {
            throw new RuntimeException('Stok tidak mencukupi. Stok tersedia: ' . qty_unit($before, $row['unit']));
        }
        $after = 0;
    }
    q("UPDATE {$table} SET stock = ?, updated_at = datetime('now','localtime') WHERE id = ?", [$after, $item_id]);
    $inv = one('SELECT id FROM inventory WHERE item_type = ? AND item_id = ?', [$item_type, $item_id]);
    if (!$inv) {
        q('INSERT INTO inventory (item_type, item_id, branch_id, stock, minimum_stock, status) VALUES (?,?,?,?,?,?)',
          [$item_type, $item_id, $row['branch_id'], $after, $row['minimum_stock'], $row['status']]);
        $inv_id = (int)db()->lastInsertId();
    } else {
        q('UPDATE inventory SET stock = ?, minimum_stock = ?, status = ? WHERE id = ?',
          [$after, $row['minimum_stock'], $row['status'], $inv['id']]);
        $inv_id = (int)$inv['id'];
    }
    $u = current_user();
    q('INSERT INTO inventory_movements (inventory_id, item_type, item_id, item_name, item_code, type, quantity, stock_before, stock_after, reason, ref_type, ref_id, user_id, user_name, branch_id, created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime("now","localtime"))', [
        $inv_id, $item_type, $item_id, $row['name'], $row['code'], $type, $qty, $before, $after,
        $reason ?: null, $extra['ref_type'] ?? null, $extra['ref_id'] ?? null,
        $u['id'] ?? null, $u['name'] ?? null, $row['branch_id'],
    ]);
    return ['before' => $before, 'after' => $after, 'name' => $row['name'], 'unit' => $row['unit']];
}

/* ------------------------------------------------------------------ *
 * Number generators
 * ------------------------------------------------------------------ */
/**
 * Nomor berurutan berikutnya.
 *
 * @param string $scanLike pola untuk MENCARI nomor yang sudah ada (mis.
 *        'OR-KW-%' untuk invoice — sengaja tanpa bagian tanggal supaya serinya
 *        tidak direset saat hari berganti).
 * @param string $outPrefix awal nomor yang DIBUAT (boleh memuat tanggal).
 * @param array  $legacyScanLike pola TAMBAHAN untuk nomor format lama (mis.
 *        'NS-KW-%' setelah kode invoice berubah menjadi OR-). Ikut dihitung
 *        supaya seri TIDAK mengulang dari awal dan nomor baru tidak terlihat
 *        mundur/tabrakan dengan dokumen lama yang masih beredar.
 *
 * Nilai terbesar dicari dari ANGKA TERAKHIR tiap nomor (bukan baris terakhir
 * menurut id), supaya nomor tidak kembar walau data lama/import masuk tidak
 * urut.
 */
function seq_next(string $scanLike, string $outPrefix, string $table, string $column,
                  int $pad = 4, int $start = 1, array $legacyScanLike = []): string
{
    $n = $start - 1;
    $patterns = array_merge([$scanLike], $legacyScanLike);
    foreach ($patterns as $p) {
        foreach (all("SELECT {$column} AS v FROM {$table} WHERE {$column} LIKE ?", [$p]) as $row) {
            $parts = explode('-', (string)$row['v']);
            $n = max($n, (int)end($parts));
        }
    }
    for ($i = 0; $i < 200; $i++) {
        $candidate = $outPrefix . str_pad((string)($n + 1 + $i), $pad, '0', STR_PAD_LEFT);
        $exists = (int)scalar("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?", [$candidate]);
        if (!$exists) return $candidate;
    }
    return $outPrefix . str_pad((string)random_int($start, $start + 8999999), $pad, '0', STR_PAD_LEFT);
}

/** Nomor urut sederhana dengan prefix tetap (mis. NSP-KW-1001001). */
function seq_number(string $prefix, string $table, string $column, int $pad = 4, int $start = 1): string
{
    return seq_next($prefix . '%', $prefix, $table, $column, $pad, $start);
}

/**
 * Invoice/Order: OR-<KODE>-<YYMMDD>-1001001 (mis. OR-KW-260928-1001001).
 * Awalan "OR" = singkatan **Order** (sebelumnya "NS").
 * Tanggal memakai tahun 2 digit; SERI TIDAK DIRESET per hari (terus bertambah
 * lintas hari — permintaan pemilik klinik), jadi nomor diambil dari seluruh
 * invoice cabang itu.
 *
 * Nomor LAMA yang masih berawalan "NS-" ikut dihitung agar serinya
 * melanjutkan (tidak mengulang dari 1001001).
 */
function next_invoice(int $branch_id): string
{
    $prefix = 'OR-' . branch_code($branch_id) . '-';
    $legacy = 'NS-' . branch_code($branch_id) . '-';
    return seq_next($prefix . '%', $prefix . date('ymd') . '-', 'orders', 'invoice_number',
        SEQ_DIGITS, SEQ_START, [$legacy . '%']);
}
/**
 * Nomor pasien & member memakai AWALAN HURUF KODE CABANG (format klinik):
 *   pasien : DP-<KODE>-1001001    (mis. DP-KW-1001001)  — "DP" = Data Pasien
 *   member : MC-<KODE>-1001001    (mis. MC-KW-1001001)  — "MC" = Member Card
 * Kode cabang diambil dari tabel `branches` (KW = Kaliwungu, CP = Cepiring,
 * BT = Batang) sehingga nomor langsung menunjukkan asal pendaftaran; seri
 * berjalan per cabang (nomor sama di cabang lain tidak bertabrakan).
 *
 * Nomor pasien LAMA berawalan "NSP-" ikut dihitung supaya serinya melanjutkan.
 */
function next_patient_number(int $branch_id = 0): string
{
    $code = branch_code($branch_id);
    return seq_next('DP-' . $code . '-%', 'DP-' . $code . '-', 'patients', 'patient_number',
        SEQ_DIGITS, SEQ_START, ['NSP-' . $code . '-%']);
}
/**
 * Nomor member: MC-<KODE>-1001001 (mis. MC-KW-1001001) — "MC" = **Member Card**
 * (sebelumnya "NSM"). Nomor LAMA berawalan "NSM-" ikut dihitung supaya serinya
 * melanjutkan (tidak mengulang dari 1001001).
 */
function next_member_number(int $branch_id = 0): string
{
    $code = branch_code($branch_id);
    return seq_next('MC-' . $code . '-%', 'MC-' . $code . '-', 'patients', 'member_number',
        SEQ_DIGITS, SEQ_START, ['NSM-' . $code . '-%']);
}
/** Reservasi: RES-<KODE>-<YYMMDD>-1001001 (seri juga tidak direset per hari). */
function next_appointment_number(int $branch_id): string
{
    $prefix = 'RES-' . branch_code($branch_id) . '-';
    return seq_next($prefix . '%', $prefix . date('ymd') . '-', 'appointments', 'appointment_number', SEQ_DIGITS, SEQ_START);
}
/** Rekam medis: RM-<KODE>-1001001 (mulai 1001001, 7 angka). */
function next_medical_number(int $branch_id): string
{
    return seq_number('RM-' . branch_code($branch_id) . '-', 'medical_records', 'record_number', SEQ_DIGITS, SEQ_START);
}

/* ------------------------------------------------------------------ *
 * Kamus ICD (ICD-10 diagnosis & ICD-9-CM tindakan)
 * ------------------------------------------------------------------ */
function icd_kinds(): array
{
    return ['icd10' => 'ICD-10 (Diagnosis)', 'icd9cm' => 'ICD-9-CM (Tindakan/Prosedur)'];
}
function icd_norm(string $code): string
{
    return strtoupper(str_replace(['.', ' '], '', trim($code)));
}
/**
 * Cari kode ICD dari kamus lokal (hasil pencarian resmi, lihat data/*.tsv).
 * Mencocokkan kode (dengan/tanpa titik), nama Indonesia, dan nama Inggris.
 */
function icd_search(string $kind, string $term, int $limit = 25): array
{
    $kind = $kind === 'icd9cm' ? 'icd9cm' : 'icd10';
    $term = trim($term);
    if ($term === '') return [];
    $norm = icd_norm($term);
    $like = '%' . $term . '%';
    $likeNorm = $norm . '%';
    // Prioritas: kode diawali persis -> kode mengandung -> nama diawali -> nama mengandung
    return all(
        "SELECT * FROM icd_codes
          WHERE kind = ?
            AND (code_norm LIKE ? OR code LIKE ? OR name_id LIKE ? OR name_en LIKE ? OR code_norm LIKE ?)
          ORDER BY
            CASE WHEN code_norm = ? THEN 0
                 WHEN code_norm LIKE ? THEN 1
                 WHEN name_id LIKE ? OR name_en LIKE ? THEN 2
                 ELSE 3 END,
            LENGTH(code), code
          LIMIT " . (int)$limit,
        [$kind, $likeNorm, $like, $like, $like, '%' . $norm . '%', $norm, $likeNorm, $norm . '%', $norm . '%']
    );
}
function icd_lookup(string $kind, string $code): ?array
{
    $code = trim($code);
    if ($code === '') return null;
    $kind = $kind === 'icd9cm' ? 'icd9cm' : 'icd10';
    $r = one('SELECT * FROM icd_codes WHERE kind = ? AND code_norm = ? LIMIT 1', [$kind, icd_norm($code)]);
    if (!$r) $r = one('SELECT * FROM icd_codes WHERE kind = ? AND code = ? LIMIT 1', [$kind, $code]);
    return $r ?: null;
}
function icd_count(string $kind): int
{
    return (int)scalar('SELECT COUNT(*) FROM icd_codes WHERE kind = ?', [$kind === 'icd9cm' ? 'icd9cm' : 'icd10']);
}
function icd_label(array $r): string
{
    $name = $r['name_id'] ?: $r['name_en'];
    return $r['code'] . ' — ' . $name;
}
/** Nama tampil + keterangan bila hanya tersedia judul resmi berbahasa Inggris. */
function icd_name_html(array $r): string
{
    if (!empty($r['name_id'])) {
        $extra = (strcasecmp((string)$r['name_id'], (string)$r['name_en']) === 0)
            ? '' : ' <span class="small muted">' . e($r['name_en']) . '</span>';
        return e($r['name_id']) . $extra;
    }
    return e($r['name_en']) . ' ' . badge('EN', 'gray');
}
function icd_translated_count(): int
{
    return (int)scalar("SELECT COUNT(*) FROM icd_codes WHERE kind = 'icd10' AND name_id IS NOT NULL AND name_id <> ''");
}

/* ------------------------------------------------------------------ *
 * Integrasi Satu Sehat (Kemenkes) — hanya status, tidak memalsukan
 * ------------------------------------------------------------------ */
function satu_sehat_configured(): bool
{
    return trim(setting('satu_sehat_org_id')) !== ''
        && trim(setting('satu_sehat_client_id')) !== ''
        && trim(setting('satu_sehat_client_secret')) !== '';
}
function satu_sehat_status_text(): string
{
    if (!satu_sehat_configured()) {
        return 'Integrasi Satu Sehat belum dikonfigurasi (kredensial Organization ID / Client ID / Client Secret belum diisi).';
    }
    if (setting('satu_sehat_active') !== '1') {
        return 'Kredensial Satu Sehat sudah diisi, tetapi status integrasi masih Nonaktif.';
    }
    return 'Integrasi Satu Sehat aktif — lingkungan ' . setting('satu_sehat_env', 'sandbox') . '.';
}
/**
 * Uji kredensial sungguhan ke endpoint token Satu Sehat.
 * Tidak pernah melaporkan "berhasil" kalau tidak benar-benar berhasil.
 */
function satu_sehat_test_token(?string &$err = null, ?string &$token = null): bool
{
    if (!satu_sehat_configured()) {
        $err = 'Kredensial Satu Sehat belum lengkap. Isi Organization ID, Client ID, dan Client Secret terlebih dahulu.';
        return false;
    }
    $url = trim(setting('satu_sehat_token_url'));
    if ($url === '' || !preg_match('#^https?://#', $url)) {
        $err = 'URL token Satu Sehat belum valid.';
        return false;
    }
    $post = http_build_query([
        'client_id' => trim(setting('satu_sehat_client_id')),
        'client_secret' => setting('satu_sehat_client_secret'),
    ]);
    $headers = "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n";
    $code = 0;
    $res = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) $err = 'Gagal menghubungi ' . $url . ': ' . curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => $headers, 'content' => $post, 'timeout' => 25, 'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
        if ($res === false && $err === null) $err = 'Gagal menghubungi ' . $url . '.';
    }
    $json = json_decode((string)$res, true);
    if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['access_token'])) {
        $token = (string)$json['access_token'];
        return true;
    }
    $msg = '';
    if (is_array($json)) {
        $msg = (string)($json['error_description'] ?? $json['error'] ?? $json['message'] ?? '');
    }
    if ($msg === '') $msg = trim(substr((string)$res, 0, 200));
    $err = 'Satu Sehat menolak permintaan token (HTTP ' . $code . ').' . ($msg !== '' ? ' ' . $msg : '');
    return false;
}

/* ------------------------------------------------------------------ *
 * Cabang tujuan input
 * ------------------------------------------------------------------ */
/**
 * Tentukan cabang tujuan dari input form.
 *
 * Sebelumnya form yang tidak mengirim branch_id (mis. Super Admin menyimpan
 * saat cakupan "Semua Cabang") akan memakai 0 dan gagal dengan
 * "FOREIGN KEY constraint failed" yang membingungkan. Sekarang dipilih cabang
 * yang masuk akal, dan bila tetap tidak ada -> pesan kesalahan yang jelas.
 */
function resolve_branch_input($posted = 0): int
{
    $b = (int)$posted;
    if ($b <= 0) {
        $scope = scope_branch();
        if ($scope !== null && $scope > 0) $b = $scope;
    }
    if ($b <= 0) {
        $all = branches();
        if ($all) $b = (int)$all[0]['id'];
    }
    if ($b <= 0) {
        $own = user_branch();
        if ($own !== null && $own > 0) $b = (int)$own;
    }
    if ($b <= 0 || !one('SELECT id FROM branches WHERE id = ?', [$b])) {
        throw new RuntimeException('Cabang tujuan tidak valid. Pilih cabang terlebih dahulu pada form.');
    }
    return $b;
}

/**
 * Potong teks dengan aman (pakai mbstring bila tersedia). Dulu fungsi ini
 * tertinggal di mailer.php sehingga modul yang tidak memuat mailer.php
 * (mis. pembuat PDF) gagal dengan "undefined function".
 */
function short_text(string $s, int $n, string $ellipsis = '…'): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if ($n <= 1) return '';
    if (function_exists('mb_substr')) {
        return mb_strlen($s, 'UTF-8') > $n ? mb_substr($s, 0, $n - 1, 'UTF-8') . $ellipsis : $s;
    }
    return strlen($s) > $n ? substr($s, 0, $n - 1) . $ellipsis : $s;
}

/* ------------------------------------------------------------------ *
 * Status badges (shared across modules)
 * ------------------------------------------------------------------ */
function order_status_badge(string $s): string
{
    $map = ['paid' => ['Lunas', 'green'], 'void' => ['Void', 'red'], 'refund' => ['Refund', 'yellow'], 'pending' => ['Pending', 'gray']];
    $m = $map[$s] ?? [$s, 'gray'];
    return badge($m[0], $m[1]);
}
function appointment_status_badge(string $s): string
{
    $map = ['Menunggu' => 'yellow', 'Confirmed' => 'blue', 'Hadir' => 'pink', 'Selesai' => 'green', 'Cancel' => 'red', 'No Show' => 'gray'];
    return badge($s, $map[$s] ?? 'gray');
}
/**
 * Status penanganan rekam medis (rekam jejak klinis):
 *  - final     : catatan sudah difinalkan (kunci/amendment)
 *  - Proses    : treatment sedang berjalan (1x treatment)
 *  - Selesai   : seluruh treatment yang direncanakan selesai
 *  - Terjadwal : sudah ada jadwal treatment berikutnya
 * 'draft'/'amendment' tetap didukung untuk data lama.
 */
const RECORD_STATUSES = ['Proses', 'Selesai', 'Terjadwal'];
function record_status_badge(string $s): string
{
    $map = [
        'Proses' => ['Proses', 'yellow'],
        'Selesai' => ['Selesai', 'green'],
        'Terjadwal' => ['Terjadwal', 'blue'],
        'amendment' => ['Amendment', 'pink'],
        'draft' => ['Draft', 'gray'],
        'final' => ['Final', 'green'],
    ];
    $m = $map[$s] ?? [$s, 'gray'];
    return badge($m[0], $m[1]);
}
/** Status yang dipilih pengguna pada form (Proses/Selesai/Terjadwal). */
function record_status_label(string $s): string
{
    return in_array($s, RECORD_STATUSES, true) ? $s : 'Proses';
}
function payment_status_badge(string $s): string
{
    $map = ['valid' => ['Valid', 'green'], 'void' => ['Void', 'red'], 'refunded' => ['Refunded', 'yellow']];
    $m = $map[$s] ?? [$s, 'gray'];
    return badge($m[0], $m[1]);
}
function item_short(string $name, int $len = 28): string
{
    return strlen($name) > $len ? substr($name, 0, $len - 1) . '…' : $name;
}

/* ------------------------------------------------------------------ *
 * Query-string / filter helpers
 * ------------------------------------------------------------------ */
function gp(string $key, $default = '')
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}
function per_page(): int
{
    $p = (int)gp('per_page', (int)setting('default_per_page', '25'));
    return in_array($p, [10, 25, 50, 100], true) ? $p : 25;
}
function page_no(): int
{
    return max(1, (int)gp('page', 1));
}
function qs(array $overrides = [], array $drop = []): string
{
    $q = $_GET;
    unset($q['ajax']);
    foreach ($drop as $d) unset($q[$d]);
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]); else $q[$k] = $v;
    }
    return http_build_query($q);
}
function hsc($s): string { return e($s); }
/** Safe JSON for embedding inside <script> blocks. */
function js_json($v): string
{
    $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return $j === false ? 'null' : $j;
}

/**
 * Kontrol halaman (prev/next + nomor) untuk sebuah daftar.
 *
 * `$key` = nama PARAMETER halaman pada tautan. Satu halaman boleh memuat BEBERAPA
 * daftar berhalaman (mis. Detail Pasien: transaksi, reservasi, rekam medis, bahan) —
 * masing-masing memakai kunci sendiri (`page_trx`, `page_app`, …) supaya membuka
 * halaman 2 pada satu kartu TIDAK menggeser kartu lain. Nilai parameter lain
 * (termasuk nomor halaman kartu lain) selalu dipertahankan oleh `qs()`.
 *
 * Atribut `data-pg` dipakai skrip halaman (app.js) untuk mengganti HANYA daftar
 * yang diklik tanpa memuat ulang halaman — pencocokan berdasarkan kunci ini,
 * bukan berdasarkan urutan (daftar yang kosong tidak merusak kartu setelahnya).
 */
function pagination(int $total, int $per_page, int $page, string $key = 'page'): string
{
    $pages = max(1, (int)ceil($total / $per_page));
    if ($total <= 0) return '';
    $kunci = $key !== '' ? $key : 'page';
    $drop = $kunci === 'page' ? [] : ['page'];      // jangan tinggalkan `page` lama
    $out = '<div class="pagination" data-pg="' . e($kunci) . '">'
        . '<div class="pg-info">Menampilkan ' . num(($page - 1) * $per_page + 1) . '–'
        . num(min($page * $per_page, $total)) . ' dari ' . num($total) . ' data</div><div class="pg-links">';
    $mk = function ($p, $label, $active = false, $disabled = false) use ($kunci, $drop) {
        if ($disabled) return '<span class="pg disabled">' . e($label) . '</span>';
        $c = $active ? 'pg active' : 'pg';
        return '<a class="' . $c . '" href="?' . e(qs([$kunci => $p], $drop)) . '">' . e($label) . '</a>';
    };
    $out .= $mk(max(1, $page - 1), '‹', false, $page <= 1);
    $start = max(1, $page - 2);
    $end   = min($pages, $start + 4);
    $start = max(1, $end - 4);
    for ($i = $start; $i <= $end; $i++) $out .= $mk($i, (string)$i, $i === $page);
    $out .= $mk(min($pages, $page + 1), '›', false, $page >= $pages);
    $out .= '</div></div>';
    return $out;
}
/**
 * Nomor halaman aktif untuk sebuah daftar berhalaman.
 *
 * @param string $key nama parameter pada URL (mis. `page`, `page_trx`)
 */
function pagination_page(string $key = 'page'): int
{
    return max(1, (int)gp($key !== '' ? $key : 'page', 1));
}

/**
 * Pemilih "per halaman" TANPA <form> sendiri.
 *
 * WAJIB dipakai di dalam form filter (per_page_select() di bawah membungkus
 * pilihannya dengan <form> sendiri sehingga menjadi FORM BERSARANG — HTML
 * melarangnya: browser mengabaikan <form> dalam tetapi MENYERTAKAN input
 * hidden-nya, sehingga filter terkirim dua kali dan nilai LAMA menang; itulah
 * penyebab filter di beberapa menu tidak menerapkan nilai baru).
 */
function per_page_inline(): string
{
    $pp = per_page();
    $out = '<select name="per_page" class="input input-sm" onchange="this.form.submit()"'
         . ' title="Jumlah data per halaman">';
    foreach ([10, 25, 50, 100] as $n) {
        $out .= '<option value="' . $n . '"' . ($pp === $n ? ' selected' : '') . '>' . $n . ' / halaman</option>';
    }
    return $out . '</select>';
}

/** Pemilih "per halaman" mandiri (form sendiri) — hanya untuk DI LUAR form filter. */
function per_page_select(): string
{
    $pp = per_page();
    $out = '<form method="get" class="inline-form">';
    foreach ($_GET as $k => $v) {
        if (in_array($k, ['per_page', 'page'], true) || !is_scalar($v)) continue;
        $out .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    $out .= '<select name="per_page" class="input input-sm" onchange="this.form.submit()">';
    foreach ([10, 25, 50, 100] as $n) {
        $out .= '<option value="' . $n . '"' . ($pp === $n ? ' selected' : '') . '>' . $n . ' / halaman</option>';
    }
    return $out . '</select></form>';
}

/* ------------------------------------------------------------------ *
 * Reference data helpers (form selects)
 * ------------------------------------------------------------------ */

/**
 * LARANG PEMINDAHAN CABANG pada baris yang SUDAH ADA.
 *
 * Arsitektur sekarang menyimpan data operasional di BERKAS basis data per cabang:
 * sebuah baris hidup di berkas cabangnya, sedangkan kolom `branch_id` ikut disaring
 * oleh view cakupan. Kalau `branch_id` sebuah baris lama diubah tanpa memindahkan
 * berkasnya, baris itu "menghilang" dari daftar (view menyaringnya) dan relasinya
 * dengan data anak di berkas lama menjadi menggantung. Karena itu pemindahan
 * dilarang dengan pesan yang jelas — bukan dibiarkan merusak data diam-diam.
 *
 * @param string $tabel   nama tabel (patients, treatments, suppliers, doctors, …)
 * @param int    $id      id baris (0 = baris baru → selalu boleh)
 * @param int    $tujuan  cabang yang diminta
 */
function assert_branch_unchanged(string $tabel, int $id, int $tujuan): void
{
    if ($id <= 0 || $tujuan <= 0) return;
    $bolehTabel = ['patients', 'doctors', 'therapists', 'treatments', 'skincare_products',
        'treatment_materials', 'suppliers', 'packages'];
    if (!in_array($tabel, $bolehTabel, true)) return;
    $row = one('SELECT branch_id FROM ' . $tabel . ' WHERE id = ?', [$id]);
    if (!$row) return;
    $lama = (int)($row['branch_id'] ?? 0);
    if ($lama > 0 && $lama !== $tujuan) {
        throw new RuntimeException('Cabang ' . $tabel . ' ini tidak dapat dipindah dari '
            . (branch_name_of($lama) !== '' ? branch_name_of($lama) : ('cabang ' . $lama)) . ' ke '
            . (branch_name_of($tujuan) !== '' ? branch_name_of($tujuan) : ('cabang ' . $tujuan))
            . '. Data operasional tersimpan di basis data cabang masing-masing, sehingga '
            . 'perpindahan cabang akan membuat riwayatnya menggantung. Silakan buat data baru '
            . 'pada cabang yang dituju.');
    }
}

/** Nama cabang dari id-nya ('' bila tidak ada) — untuk pesan/penjelasan ke pengguna. */
function branch_name_of(int $branchId): string
{
    static $cache = [];
    if (array_key_exists($branchId, $cache)) return $cache[$branchId];
    $b = $branchId > 0 ? one('SELECT name FROM branches WHERE id = ?', [$branchId]) : null;
    return $cache[$branchId] = ($b ? (string)$b['name'] : '');
}

function opt_branches($selected = null, bool $all_option = false, string $all_label = 'Semua Cabang'): string
{
    $out = $all_option ? '<option value="">' . e($all_label) . '</option>' : '';
    foreach (selectable_branches() as $b) {
        $out .= '<option value="' . (int)$b['id'] . '"' . ((string)$selected === (string)$b['id'] ? ' selected' : '') . '>' . e($b['name']) . '</option>';
    }
    return $out;
}
/**
 * Kolom FILTER CABANG untuk halaman daftar (permintaan pemilik: setiap menu yang
 * menyimpan data per cabang harus punya pilihan cabang + "Semua Cabang").
 *
 * Hanya dirender untuk level OWNER (Super Admin & Direktur) — akun yang dipin satu
 * cabang tidak perlu memilih karena seluruh datanya memang cabang itu. Nilainya
 * dibaca `scope_branch()` yang sudah dipakai seluruh halaman, sehingga cukup
 * menambahkan kolom ini agar filter benar-benar berlaku.
 *
 * Dipakai DI DALAM `<form class="filter-bar">` (mengembalikan satu `<div class="field">`
 * saja — tidak membuat form baru, jadi tidak ada form bersarang).
 */
function branch_filter_field(string $label = 'Cabang'): string
{
    if (!is_owner_level()) return '';
    $now = scope_branch();
    $out = '<div class="field"><label>' . e($label) . '</label>'
        . '<select class="input input-sm" name="branch">'
        . '<option value="all"' . ($now === null ? ' selected' : '') . '>Semua Cabang</option>';
    foreach (branches() as $b) {
        $out .= '<option value="' . (int)$b['id'] . '"'
            . ($now === (int)$b['id'] ? ' selected' : '') . '>' . e((string)$b['name']) . '</option>';
    }
    return $out . '</select></div>';
}

/**
 * Pilihan supplier: dropdown dari supplier yang TERDAFTAR di menu Supplier,
 * ditambah opsi mengisi nama baru secara manual (agar tetap fleksibel).
 * Sebelumnya memakai <datalist> yang dianggap tidak berfungsi oleh pengguna.
 */
function opt_supplier(string $name, string $selected = '', string $prefix = 'sup'): string
{
    /* PEMBATASAN CABANG (audit isolasi ronde 55): daftar nama supplier mengikuti cabang
       akun — akun yang dipin satu cabang tidak melihat supplier cabang lain. Pemilik
       (cakupan semua cabang) tetap melihat seluruhnya. */
    $sc = bscope('branch_id');
    $list = all('SELECT DISTINCT name FROM suppliers WHERE status = "active" ' . $sc[0] . ' ORDER BY name', $sc[1]);
    $selected = trim($selected);
    $known = false;
    foreach ($list as $r) {
        if (strcasecmp((string)$r['name'], $selected) === 0) { $known = true; break; }
    }
    $id = e($prefix) . '_supplier';
    $newId = e($prefix) . '_supplier_new';
    $out = '<select class="input" name="' . e($name) . '__pick" id="' . $id . '" data-supplier-select="' . $newId . '">';
    $out .= '<option value="">— pilih supplier terdaftar —</option>';
    foreach ($list as $r) {
        $sel = (strcasecmp((string)$r['name'], $selected) === 0) ? ' selected' : '';
        $out .= '<option value="' . e($r['name']) . '"' . $sel . '>' . e($r['name']) . '</option>';
    }
    $out .= '<option value="__new__"' . (!$known && $selected !== '' ? ' selected' : '') . '>+ Isi manual / supplier baru…</option>';
    $out .= '</select>';
    $out .= '<input class="input mt-1' . ($known || $selected === '' ? ' hide' : '') . '" name="' . e($name) . '__new" id="' . $newId . '"'
         . ' value="' . e($known ? '' : $selected) . '" placeholder="Ketik nama supplier baru">';
    return $out;
}

/**
 * Ambil nilai supplier dari form: prioritas isi manual (bila dipilih user),
 * jika tidak pakai nama supplier terdaftar yang dipilih.
 */
function supplier_input(string $field = 'supplier_name'): string
{
    $manual = trim((string)($_POST[$field . '__new'] ?? ''));
    if ($manual !== '') return $manual;
    $pick = trim((string)($_POST[$field . '__pick'] ?? ''));
    return $pick === '__new__' ? '' : $pick;
}

function opt_period($selected = ''): string
{
    $opts = ['today' => 'Hari ini', '7d' => '7 hari terakhir', 'month' => 'Bulan ini', '3m' => '3 bulan', 'year' => 'Tahun ini', 'custom' => 'Custom tanggal'];
    $out = '';
    foreach ($opts as $k => $v) $out .= '<option value="' . $k . '"' . ($selected === $k ? ' selected' : '') . '>' . e($v) . '</option>';
    return $out;
}
/** Resolve a period selection into [start, end] inclusive date strings. */
function resolve_period(string $period, string $start = '', string $end = ''): array
{
    $t = date('Y-m-d');
    switch ($period) {
        case 'today': return [$t, $t];
        case '7d':    return [date('Y-m-d', strtotime('-6 days')), $t];
        case '3m':    return [date('Y-m-d', strtotime('-3 months +1 day')), $t];
        case 'year':  return [date('Y-01-01'), $t];
        case 'custom':
            return [$start !== '' ? $start : $t, $end !== '' ? $end : $t];
        /* 'all' = seluruh riwayat (tanpa batas). Dipakai tombol filter periode
           pada Detail Pasien; rentangnya sengaja sangat lebar, bukan kosong,
           supaya perbandingan tanggal di SQL tetap sederhana. */
        case 'all':   return ['1900-01-01', '2999-12-31'];
        case 'month':
        default:      return [date('Y-m-01'), $t];
    }
}
function stock_alerts(int $limit = 8): array
{
    [$bs, $bp] = branch_sql('s.branch_id');
    $sql = "SELECT s.id, s.name, s.code, s.stock, s.minimum_stock, s.unit, s.branch_id, 'skincare' AS kind, b.name AS branch_name
            FROM skincare_products s JOIN branches b ON b.id = s.branch_id
            WHERE s.status = 'active' AND s.stock <= s.minimum_stock {$bs}
            UNION ALL
            SELECT m.id, m.name, m.code, m.stock, m.minimum_stock, m.unit, m.branch_id, 'material' AS kind, b.name AS branch_name
            FROM treatment_materials m JOIN branches b ON b.id = m.branch_id
            WHERE m.status = 'active' AND m.stock <= m.minimum_stock " . str_replace('s.branch_id', 'm.branch_id', $bs) . "
            ORDER BY stock ASC LIMIT " . (int)$limit;
    $rows = [];
    $idx = 0;
    foreach (all($sql, array_merge($bp, $bp)) as $r) { $rows[] = $r; $idx++; }
    return $rows;
}
function stock_alert_count(): int
{
    [$bs, $bp] = branch_sql('s.branch_id');
    $a = (int)scalar("SELECT COUNT(*) FROM skincare_products s WHERE s.status='active' AND s.stock <= s.minimum_stock {$bs}", $bp);
    [$bs2, $bp2] = branch_sql('m.branch_id');
    $b = (int)scalar("SELECT COUNT(*) FROM treatment_materials m WHERE m.status='active' AND m.stock <= m.minimum_stock {$bs2}", $bp2);
    return $a + $b;
}

/* ------------------------------------------------------------------ *
 * WhatsApp helpers
 * ------------------------------------------------------------------ */
function wa_number(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone);
    if ($d === '') return '';
    if ($d[0] === '0') $d = '62' . substr($d, 1);
    elseif (strpos($d, '62') !== 0) $d = '62' . $d;
    return $d;
}
function wa_link(string $phone, string $text): string
{
    return 'https://wa.me/' . wa_number($phone) . '?text=' . rawurlencode($text);
}
/**
 * Pesan pengingat jadwal untuk DOKTER (bukan terapis — sesuai permintaan klinik).
 * Template bisa diatur di Pengaturan Sistem > WhatsApp.
 */
function wa_doctor_message(array $a): string
{
    $tpl = setting('wa_template_doctor');
    if (trim($tpl) === '') {
        /* Template bawaan memakai nama klinik AKTIF (lihat includes/clinic.php). */
        $cn = clinic_name();
        $tpl = "Selamat pagi/siang Dokter {dokter},\n\nPengingat jadwal praktik di {$cn} {cabang}:\n"
             . "Tanggal: {tanggal}\nJam: {jam}\nPasien: {nama}\nTreatment: {treatment}\n\n"
             . "Mohon konfirmasi ketersediaannya. Terima kasih.";
    }
    return str_replace(
        /* {klinik} = nama klinik AKTIF (mengikuti Pengaturan → Nama Klinik),
           supaya pemilik dapat menulis {klinik} sendiri di template WhatsApp
           (dulu hanya {cabang} yang diganti sehingga {klinik} tampil apa adanya). */
        ['{dokter}', '{nama}', '{klinik}', '{cabang}', '{tanggal}', '{jam}', '{treatment}'],
        [
            (string)($a['doctor_name'] ?? 'Dokter'),
            (string)($a['patient_name'] ?? ''),
            clinic_name(),
            (string)($a['branch_name'] ?? ''),
            tgl((string)($a['date'] ?? '')),
            (string)($a['time'] ?? ''),
            ($a['treatments_all'] ?? '') !== '' ? (string)$a['treatments_all'] : (string)($a['treatment_name'] ?? '-'),
        ],
        $tpl
    );
}

function wa_reservation_message(array $a): string
{
    $cn = clinic_name();
    $tpl = setting('wa_template', "Halo Kak {nama}\n\nKami dari {$cn} {cabang}.\nMengingatkan reservasi Kakak:\nTanggal: {tanggal}\nJam: {jam}\nTreatment: {treatment}\nDokter/Terapis: {dokter}\n\nMohon konfirmasi kehadirannya ya\nTerima kasih ❤️");
    return str_replace(
        ['{nama}', '{klinik}', '{cabang}', '{tanggal}', '{jam}', '{treatment}', '{dokter}'],
        [$a['patient_name'] ?? '', $cn, $a['branch_name'] ?? '', tgl($a['date'] ?? ''), ($a['time'] ?? ''),
         /* Sebut SEMUA treatment reservasi (utama + tambahan) bila tersedia. */
         ($a['treatments_all'] ?? '') !== '' ? $a['treatments_all'] : ($a['treatment_name'] ?? '-'),
         $a['staff_name'] ?? '-'],
        $tpl
    );
}

/* ------------------------------------------------------------------ *
 * File / media helpers
 * ------------------------------------------------------------------ */
function media_token(): string
{
    $f = APP_DIR . '/.vibecoder-media-token';
    return is_readable($f) ? trim((string)file_get_contents($f)) : '';
}
function media_kind(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) return 'image';
    if (in_array($ext, ['mp4', 'webm', 'mov'], true)) return 'video';
    return 'document';
}
/** Upload visitor file through the platform media proxy. Returns CDN url. */
function media_upload(string $tmp_path, string $filename): string
{
    $token = media_token();
    if ($token === '') {
        throw new RuntimeException('Media storage belum dikonfigurasi (token tidak ditemukan). Hubungi Super Admin.');
    }
    $url = 'http://127.0.0.1:4310/api/app-media/upload?filename=' . rawurlencode($filename);
    $data = file_get_contents($tmp_path);
    if ($data === false) throw new RuntimeException('Gagal membaca file yang diunggah.');
    $headers = "X-App-Media-Token: {$token}\r\nContent-Type: application/octet-stream\r\n";
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => ["X-App-Media-Token: {$token}", 'Content-Type: application/octet-stream'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => $headers, 'content' => $data,
            'timeout' => 120, 'ignore_errors' => true,
        ]]);
        $res  = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
    }
    $json = json_decode((string)$res, true);
    if ($code !== 200 || !is_array($json) || empty($json['ok']) || empty($json['url'])) {
        $msg = $json['error'] ?? $json['message'] ?? '';
        throw new RuntimeException($msg !== '' ? $msg : 'Upload media gagal (HTTP ' . $code . ').');
    }
    return (string)$json['url'];
}
function allowed_upload(string $filename, int $size): void
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $limits = [
        'jpg' => 15, 'jpeg' => 15, 'png' => 15, 'gif' => 15, 'webp' => 15, 'svg' => 15,
        'mp4' => 300, 'webm' => 300, 'mov' => 300,
        'pdf' => 50, 'docx' => 50, 'xlsx' => 50, 'pptx' => 50, 'csv' => 50, 'txt' => 50, 'zip' => 50,
    ];
    if (!isset($limits[$ext])) throw new RuntimeException('Tipe file .' . $ext . ' tidak diizinkan.');
    if ($size > $limits[$ext] * 1024 * 1024) throw new RuntimeException('Ukuran file melebihi batas ' . $limits[$ext] . ' MB.');
}
/**
 * Path berkas logo lokal (dipakai untuk merender logo di dalam PDF, karena PDF
 * tidak bisa mengambil berkas dari URL). Kosong bila logo hanya tersimpan di
 * penyimpanan media atau belum diunggah.
 */
function logo_local_path(): string
{
    $f = (string)setting('logo_file');
    if ($f !== '') {
        $base = basename($f);
        if ($base === $f && preg_match('/^[A-Za-z0-9._-]{1,120}$/', $base)) {
            $path = local_upload_dir() . '/' . $base;
            if (is_readable($path)) return $path;
        }
    }
    /* Logo bawaan aplikasi (dipakai bila Super Admin belum mengunggah logo
       sendiri) HANYA selama nama klinik masih nama bawaan — sama seperti
       brand_logo_src() pada tampilan layar. Tanpa syarat ini, logo bawaan yang
       memuat tulisan merek lama tetap muncul di struk/laporan/kartu member PDF
       padahal di layar sudah memakai nama klinik baru. */
    if (function_exists('brand_is_default_name') && !brand_is_default_name()) return '';
    $builtin = APP_DIR . '/assets/img/logo-naveena.png';
    return is_readable($builtin) ? $builtin : '';
}

/**
 * Path logo dalam bentuk PNG untuk ditempelkan ke PDF ('' bila tidak ada).
 *
 * Penulis PDF aplikasi (includes/pdf.php + png.php) HANYA bisa menyisipkan PNG.
 * Logo yang diunggah sebagai JPEG/GIF/WebP karena itu dikonversi sekali ke PNG
 * memakai GD lalu hasilnya di-cache di folder unggahan (`<nama>.pdf.png`).
 * Tanpa ini, logo berformat selain PNG akan hilang dari struk/laporan PDF.
 */
function logo_pdf_path(): string
{
    $src = logo_local_path();
    if ($src === '') return '';
    if (strtolower(pathinfo($src, PATHINFO_EXTENSION)) === 'png') return $src;
    if (!img_gd()) return '';   // tanpa GD tidak bisa mengonversi -> fallback teks
    $cache = $src . '.pdf.png';
    if (is_readable($cache) && @filemtime($cache) >= @filemtime($src)) return $cache;
    $img = @imagecreatefromstring((string)@file_get_contents($src));
    if ($img === false) return '';
    $ok = false;
    if (function_exists('imagepng')) {
        $ok = @imagepng($img, $cache, 9);
    }
    imagedestroy($img);
    return $ok && is_readable($cache) ? $cache : '';
}

function local_upload_dir(): string
{
    // Keep uploaded files OUTSIDE the publicly served app folder so they can
    // only ever be read through media.php (which enforces auth + branch access).
    /* Lokasi folder unggahan dapat dialihkan lewat NAVEENA_UPLOAD_DIR — dipakai
       skrip uji agar dapat memeriksa/membersihkan berkas di folder SENDIRI,
       bukan folder unggahan aplikasi terbit (sama polanya dengan NAVEENA_DB /
       NAVEENA_BACKUP_DIR). */
    $env = getenv('NAVEENA_UPLOAD_DIR');
    if ($env) {
        if (!is_dir($env)) @mkdir($env, 0770, true);
        return rtrim($env, '/');
    }
    /* PENGAMAN (lihat nv_isolated_root): proses uji/CLI yang mengalihkan NAVEENA_DB
       memakai folder unggahan sendiri — jangan menulis ke folder unggahan aplikasi
       terbit (pernah menumpuk 600+ berkas sisa uji di sana). */
    $iso = nv_isolated_root();
    if ($iso !== '') {
        $d = $iso . '/uploads';
        if (!is_dir($d)) @mkdir($d, 0770, true);
        return $d;
    }
    $dirs = [dirname(APP_DIR) . '/naveena_uploads', APP_DIR . '/storage/photos'];
    foreach ($dirs as $d) {
        if (!is_dir($d)) @mkdir($d, 0770, true);
        if (is_dir($d) && is_writable($d)) return $d;
    }
    return APP_DIR . '/storage/photos';
}

/* ------------------------------------------------------------------ *
 * Penjaga sesi login (ronde 38)
 *
 * Dijalankan pada SETIAP permintaan, SETELAH sesi dimulai:
 *   • memperbarui penanda aktivitas & mengakhiri sesi yang melewati batas
 *     tidak aktif (bila pengguna TIDAK mencentang "Ingat saya");
 *   • masuk otomatis dari cookie "Ingat saya" yang masih sah.
 * Jangan dipindah ke atas session_start() — cookie & $_SESSION belum tersedia.
 * ------------------------------------------------------------------ */
/*
 * Penegakan mode pemeliharaan (maintenance)
 *
 * Dijalankan otomatis pada SETIAP request. Saat mode pemeliharaan aktif,
 * Super Admin tetap bebas sedangkan level lain hanya dapat MELIHAT data:
 * semua aksi tulis (POST) dan halaman impor/ekspor/backup/hapus ditolak,
 * dan pengguna diarahkan ke halaman pemeliharaan yang menjelaskan keadaan.
 * Karena penegakannya di sini (bootstrap), halaman baru otomatis ikut
 * terlindungi tanpa perlu memanggil apa pun secara manual.
 * ------------------------------------------------------------------ */
/* Penjaga sesi dijalankan LEBIH DULU daripada penegakan mode pemeliharaan
   supaya sesi yang sudah kedaluwarsa dibersihkan dulu. */
login_security_boot();
maintenance_gate();
