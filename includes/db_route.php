<?php
/**
 * ROUTING BASIS DATA: CENTRAL + SATU SQLITE PER CABANG
 * ===================================================
 * Spesifikasi wajib (PDF Master Upgrade bagian 3 & 4): aplikasi memakai
 *   • `central.sqlite` untuk data GLOBAL/SISTEM, dan
 *   • satu `branch_XXX.sqlite` per cabang untuk data OPERASIONAL,
 * dengan branch isolation yang benar untuk READ maupun WRITE, pembuatan basis
 * data cabang otomatis (skema + migrasi), serta seluruh READ/WRITE di semua
 * modul (dashboard, laporan, API, webhook, demo, impor/ekspor, backup/restore)
 * memakai konteks basis data yang tepat.
 *
 * CARA KERJA (satu koneksi, tanpa menulis ulang 800+ query):
 *   1. Satu koneksi PDO: **main = central.sqlite**, lalu setiap basis data cabang
 *      di-ATTACH sebagai `b1`, `b2`, … (SQLite mendukung transaksi lintas berkas
 *      pada satu koneksi, jadi `BEGIN IMMEDIATE` yang sudah ada tetap benar).
 *   2. Untuk SETIAP tabel operasional dibuat **TEMP VIEW** yang menunjuk berkas
 *      cabang — jadi seluruh query lama (`SELECT … FROM orders o JOIN branches b`)
 *      tetap bekerja apa adanya, dengan dua jaminan:
 *        • cakupan BACA = satu cabang  → view hanya berisi cabang itu (isolasi
 *          struktural: query yang lupa memfilter `branch_id` pun tidak dapat
 *          membaca cabang lain);
 *        • cakupan BACA = semua cabang → view = UNION ALL gabungan seluruh cabang
 *          (level owner, dashboard/laporan semua cabang).
 *   3. Tabel GLOBAL tetap dibaca & ditulis langsung di `main` (central).
 *   4. Karena view tidak dapat ditulisi, setiap pernyataan TULIS ke tabel
 *      operasional **dikualifikasi** ke berkas cabang yang benar
 *      (`INSERT INTO b2.orders …`) — ditentukan dari: konteks yang ditetapkan
 *      pemanggil → nilai `branch_id` pada pernyataan → letak barisnya (UPDATE/DELETE
 *      dicari ke seluruh berkas cabang) → cakupan akun.
 *
 * ARSITEKTUR INI ADALAH SATU-SATUNYA YANG DIPAKAI: tidak ada mode "legacy", tidak ada
 * fallback ke satu berkas `data.sqlite`, dan berkas itu sudah dihapus dari instalasi.
 * Bila central belum ada, ia DIBUAT lalu skema diterapkan (lihat db_route_conn()).
 */

/** Tabel yang hanya boleh hidup di basis data CENTRAL (data global/sistem). */
function db_route_global_tables(): array
{
    return ['branches', 'users', 'roles', 'permissions', 'role_permissions', 'user_permissions',
        'settings', 'audit_logs', 'backups', 'icd_codes', 'import_batches', 'import_errors',
        'email_report_logs', 'pay_pending', 'finance_costs', 'finance_cost_amounts',
        'db_registry', 'db_migrations', 'demo_batches',
        'login_remember', 'login_2fa_codes', 'password_resets', 'recovery_codes', 'twofa',
        /* LOGIN MANAGEMENT (ronde 64e) — sesi & blokir perangkat adalah data GLOBAL. */
        'user_sessions', 'login_blocks',
        'ai_tasks', 'ai_task_files', 'ai_usage_log', 'ai_steps', 'ai_messages', 'ai_traces', 'ai_jobs'];
}

/** Tabel OPERASIONAL: hidup di basis data masing-masing CABANG. */
function db_route_branch_tables(): array
{
    return ['patients', 'medical_records', 'medical_record_photos', 'appointments',
        'appointment_treatments', 'orders', 'order_items', 'payments',
        'inventory', 'inventory_movements', 'treatments', 'skincare_products',
        'treatment_materials', 'suppliers', 'doctors', 'therapists', 'packages', 'package_items',
        /* Riwayat naik level kartu member (ronde 64d) — data per pasien/cabang. */
        'member_upgrades'];
}

/**
 * Tabel yang punya kolom `branch_id` TETAPI juga menunjuk INDUK milik cabang
 * (`appointments.patient_id`, `orders.patient_id`, `medical_records.patient_id`).
 *
 * Untuk tabel seperti ini, cabang tujuan penulisan WAJIB mengikuti cabang INDUK-nya,
 * bukan kolom `branch_id` yang dikirim formulir. Alasannya: relasi (FK) di dalam
 * satu berkas basis data cabang — pasien cabang 2 TIDAK ADA di `branch_001.sqlite`,
 * sehingga menyimpan reservasi ber-`branch_id=1` untuk pasien cabang 2 selalu DITOLAK
 * ("FOREIGN KEY constraint failed"). Ini sekaligus menegakkan aturan bisnis
 * "cabang data mengikuti pasien" (ronde 34/36/37).
 */
function db_route_parent_refs(): array
{
    return ['appointments' => ['patients', 'patient_id'],
        'orders' => ['patients', 'patient_id'],
        'medical_records' => ['patients', 'patient_id'],
        /* Riwayat naik level mengikuti cabang PASIEN yang bersangkutan. */
        'member_upgrades' => ['patients', 'patient_id']];
}

/** Tabel tanpa kolom `branch_id` — cabangnya ditentukan dari tabel INDUK. */
function db_route_child_tables(): array
{
    return [
        'order_items'            => ['orders', 'order_id'],
        'payments'               => ['orders', 'order_id'],
        'appointment_treatments' => ['appointments', 'appointment_id'],
        'medical_record_photos'  => ['medical_records', 'medical_record_id'],
        'package_items'          => ['packages', 'package_id'],
    ];
}

/** Ruang lingkup sebuah tabel: 'branch' atau 'global' (bawaan: global = aman). */
function db_route_scope_of(string $table): string
{
    static $branch = null;
    if ($branch === null) $branch = array_flip(db_route_branch_tables());
    return isset($branch[strtolower($table)]) ? 'branch' : 'global';
}

/* ------------------------------------------------------------------ *
 * MODE: SELALU CENTRAL + SATU BASIS DATA PER CABANG
 * ------------------------------------------------------------------ *
 * Seluruh baca/tulis aplikasi memakai central (data global/sistem) + berkas cabang
 * (data operasional). `DB_PATH` (konstanta lama) kini MENUNJUK central — tidak ada
 * satu pun jalur yang dapat kembali ke `data.sqlite`.
 */

/* ------------------------------------------------------------------ *
 * CAKUPAN BACA & CABANG TULIS
 * ------------------------------------------------------------------ */

/**
 * Cakupan baca dihitung dari basis data CENTRAL (tanpa koneksi routed).
 *
 * Aturannya SAMA dengan `user_branch()` + `scope_branch()`:
 *   • belum ada akun aktif (bootstrap/CLI) → semua cabang (null);
 *   • akun dengan peran owner (super_admin/direktur):
 *       – `?branch=` diisi  → cabang itu ('all' → semua),
 *       – ada cabang aktif di sesi → cabang itu,
 *       – selain itu → semua cabang (null);
 *   • akun lain → cabang akunnya (0/-1 diperlakukan sebagai semua cabang).
 */
function db_route_scope_central(): ?int
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return null;
    $u = db_route_scope_user($uid);
    if (!$u) return null;
    $owner = in_array((string)$u['role_code'], ['super_admin', 'direktur'], true);
    if ($owner) {
        $b = (string)($_GET['branch'] ?? $_POST['branch'] ?? '');
        if ($b !== '') return $b === 'all' ? null : ((int)$b > 0 ? (int)$b : null);
        $aktif = (int)($_SESSION['active_branch'] ?? 0);
        return $aktif > 0 ? $aktif : null;
    }
    $bid = (int)($u['branch_id'] ?? 0);
    return $bid > 0 ? $bid : null;
}

/** Identitas akun (cabang + kode peran) dari CENTRAL, di-cache per permintaan. */
function db_route_scope_user(int $uid): ?array
{
    static $cache = [];
    if (array_key_exists($uid, $cache)) return $cache[$uid];
    $rows = db_route_raw_central_many(
        'SELECT u.id, u.branch_id, r.code AS role_code
           FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1', [$uid]);
    $cache[$uid] = $rows ? $rows[0] : null;
    return $cache[$uid];
}

/** Cabang yang dipakai untuk MEMBACA (null = semua cabang / level owner). */
function db_route_read_scope(): ?int
{
    /* Hasil di-cache dengan KUNCI keadaan (akun + cabang aktif + filter ?branch=).
       Dua alasan: (a) scope_branch() sendiri membutuhkan informasi akun yang bisa
       memicu query → tanpa cache akan terjadi rekursi tak berujung lewat db();
       (b) nilainya memang tetap sepanjang satu permintaan. */
    static $kunci = null;
    static $nilai = null;
    $kunciSkrg = (string)($_SESSION['user_id'] ?? '0') . '|'
        . (string)($_SESSION['active_branch'] ?? '') . '|'
        . (string)($_GET['branch'] ?? '') . '|' . (string)($_POST['branch'] ?? '');
    if ($kunci === $kunciSkrg) return $nilai;
    if (!empty($GLOBALS['DB_ROUTE_SCOPE_BUSY'])) return null;   // sedang dihitung → hindari rekursi
    $GLOBALS['DB_ROUTE_SCOPE_BUSY'] = true;
    try {
        /* PENTING (ronde 65): identitas akun dibaca dari CENTRAL LANGSUNG, bukan lewat
           `scope_branch()`/`current_user()` — keduanya memakai koneksi routed (`db()`),
           sehingga pada permintaan PERTAMA terjadi lingkaran: db() → attach → scope →
           db(). Dulu lingkaran itu diputus dengan menganggap cakupannya "semua cabang",
           dan AKIBATNYA setiap permintaan meng-ATTACH sampai 9 berkas cabang walau
           akunnya dipin ke satu cabang saja. */
        $nilai = db_route_scope_central();
    } finally {
        unset($GLOBALS['DB_ROUTE_SCOPE_BUSY']);
    }
    $kunci = $kunciSkrg;
    return $nilai;
}

/** Tetapkan cabang TUJUAN penulisan secara eksplisit (mis. saat mengisi data cabang lain). */
function db_route_write_branch_set(?int $branchId): void
{
    $GLOBALS['DB_ROUTE_WRITE_BRANCH'] = $branchId === null ? null : (int)$branchId;
}
/** Lepaskan penetapan eksplisit (kembali ke penentuan otomatis). */
function db_route_write_branch_clear(): void
{
    $GLOBALS['DB_ROUTE_WRITE_BRANCH'] = null;
}
/** Cabang penulisan bawaan menurut akun/cakupan (dipakai bila tak dapat ditentukan lain). */
function db_route_default_branch(): int
{
    $b = function_exists('user_branch') ? user_branch() : null;
    if ($b !== null && (int)$b > 0) return (int)$b;   // -1/0 = belum ditentukan
    $s = function_exists('scope_branch') ? scope_branch() : null;
    if ($s !== null && (int)$s > 0) return (int)$s;
    $ids = db_route_attached();
    if ($ids) return (int)array_key_first($ids);
    $row = db_route_raw_central('SELECT id FROM branches ORDER BY id LIMIT 1');
    return (int)($row ?? 1);
}

/* ------------------------------------------------------------------ *
 * KONEKSI ROUTED (central + seluruh cabang di-ATTACH)
 * ------------------------------------------------------------------ */

/** Batas ATTACH SQLite (10 total, termasuk main) → maksimum 9 berkas cabang. */
const DB_ROUTE_MAX_ATTACH = 9;

/** Cache koneksi dalam satu permintaan. */
/**
 * MODE PERIKSA — dipakai alat pemeriksa & skrip uji yang hanya MEMBACA basis data.
 *
 * Saat aktif, koneksi routed TIDAK pernah menjalankan `ensure_schema()` (migrasi +
 * penulisan versi skema). Penting: tanpa mode ini, sekadar membaca lewat
 * `nvdb()` membuat rantai `db_route_read_scope() → scope_branch() → current_user()`
 * memanggil `db()` dan MENJALANKAN MIGRASI — sehingga uji yang sengaja menurunkan
 * versi skema selalu melihat versi dikembalikan (gagal palsu).
 */
function db_route_set_inspect(bool $on = true): void
{
    $GLOBALS['DB_ROUTE_INSPECT'] = $on;
}
function db_route_inspect(): bool
{
    return !empty($GLOBALS['DB_ROUTE_INSPECT']);
}

function db_route_conn(bool $siapkanSkema = true): PDO
{
    static $pdo = null;
    static $scopeViews = null;
    static $skemaSiap = false;

    /* PERMINTAAN MEMBANGUN ULANG KONEKSI (ronde 65).
       Dipakai setelah PEMULIHAN BACKUP: berkas basis data diganti di disk, sedangkan
       koneksi yang lama masih memegang berkas (inode) lama berikut berkas `-wal`-nya.
       Tanpa membangun ulang, penulisan berikutnya bisa MENGHIDUPKAN KEMBALI baris lama
       dari WAL (pernah benar-benar terjadi pada uji pemulihan: jumlah baris kembali
       seperti SEBELUM pemulihan). */
    if (!empty($GLOBALS['DB_ROUTE_RESET'])) {
        $GLOBALS['DB_ROUTE_RESET'] = false;
        $pdo = null;
        $scopeViews = null;
        $skemaSiap = false;
        $GLOBALS['DB_ROUTE_ATTACHED'] = [];
        $GLOBALS['DB_ROUTE_ATTACHING'] = true;      // cegah pemanggilan ulang saat membangun
    }

    /* Mode periksa: jangan sentuh skema, tetapi tetap hitung kesiapan supaya
       pemanggil yang meminta skema nanti tidak menjalankan migrasi diam-diam. */
    if ($siapkanSkema && db_route_inspect()) {
        $siapkanSkema = false;
        $skemaSiap = true;
    }

    if (!$pdo instanceof PDO) {
        $GLOBALS['DB_ROUTE_ATTACHING'] = true;
        try {
        $path = db_central_path();
        if (!is_file($path)) {
            /* Central belum ada: buat + terapkan skema (global) lebih dulu. */
            db_schema_apply($path, 'central');
            $skemaSiap = true;
        }
        $pdo = db_open($path);
        if ($siapkanSkema) {
            ensure_schema($pdo);            // tabel global + migrasi + seed sistem
            $skemaSiap = true;
        }
        db_route_attach_all($pdo);
        db_route_install_views($pdo);
        } finally { unset($GLOBALS['DB_ROUTE_ATTACHING']); }
        $scopeViews = db_route_read_scope();
        return $pdo;
    }
    /* Koneksi dibuka TANPA menyiapkan skema (dipakai alat pemeriksa/uji supaya
       tidak mengubah versi skema saat sekadar membaca), lalu diminta menyiapkannya. */
    if ($siapkanSkema && !$skemaSiap) {
        ensure_schema($pdo);
        $skemaSiap = true;
    }
    /* Cakupan baca bisa berubah SETELAH koneksi dibuka (mis. di baris perintah akun
       ditetapkan belakangan, atau alur berpindah cabang). View dibangun ulang bila
       cakupannya berbeda supaya ISOLASI BACA tidak pernah bergantung urutan. */
    $sekarang = db_route_read_scope();
    if ($sekarang !== $scopeViews) {
        db_route_install_views($pdo);
        $scopeViews = $sekarang;
    }
    return $pdo;
}

/**
 * ATTACH seluruh basis data cabang (dibuat otomatis bila belum ada).
 *
 * Urutan prioritas bila cabang lebih banyak daripada batas ATTACH: cabang yang
 * sedang dilihat/dituju lebih dulu, lalu sisanya menurut urutan id. Keterbatasan
 * ini DILAPORKAN apa adanya lewat route_report(), bukan disembunyikan.
 */
function db_route_attach_all(PDO $pdo): void
{
    $ids = db_route_branch_ids();
    $scope = db_route_read_scope();
    $tulis = isset($GLOBALS['DB_ROUTE_WRITE_BRANCH']) ? (int)$GLOBALS['DB_ROUTE_WRITE_BRANCH'] : 0;
    $akun = function_exists('user_branch') ? (int)user_branch() : 0;

    /* ---- ATTACH SELEKTIF (ronde 65) -------------------------------------
       Dulu SETIAP permintaan meng-ATTACH sampai 9 berkas cabang — termasuk akun
       yang dipin ke SATU cabang (kasir/admin). Dengan 10–15 cabang itu berarti
       membuka 10–16 berkas basis data + membuat 19 TEMP VIEW tiap permintaan,
       padahal data yang dibutuhkan hanya satu cabang. Sekarang:
         • cakupan SATU cabang (akun cabang, atau owner yang memilih cabang) →
           hanya berkas cabang itu yang di-ATTACH (+ cabang tulis bila berbeda);
         • cakupan SEMUA cabang (owner lintas cabang) → sampai batas ATTACH,
           cabang yang sedang aktif didahulukan.
       Query laporan lintas cabang untuk cabang yang TIDAK ter-ATTACH dilayani
       fan-out (`db_branch_each()`): satu berkas cabang pada satu waktu, hasilnya
       digabung di PHP — jadi tidak ada data transaksi yang dipindahkan ke central. */
    if ($scope !== null && (int)$scope > 0) {
        $urutan = [(int)$scope];
        foreach ([$tulis, $akun] as $extra) {
            if ((int)$extra > 0 && (int)$extra !== (int)$scope) $urutan[] = (int)$extra;
        }
    } else {
        $prioritas = [];
        foreach ([$akun, $tulis] as $p) {
            if ((int)$p > 0 && in_array((int)$p, $ids, true)) $prioritas[] = (int)$p;
        }
        $urutan = array_values(array_unique(array_merge($prioritas, $ids)));
    }

    $terpasang = [];
    foreach ($urutan as $i => $bid) {
        if (count($terpasang) >= DB_ROUTE_MAX_ATTACH) break;
        if (!in_array((int)$bid, $ids, true)) continue;           // cabang tidak terdaftar
        db_route_attach_one($pdo, (int)$bid, $terpasang);
    }
    $GLOBALS['DB_ROUTE_ATTACHED'] = $terpasang;
    $GLOBALS['DB_ROUTE_ATTACH_SKIPPED'] = array_values(array_diff($ids, array_keys($terpasang)));
    $GLOBALS['DB_ROUTE_ATTACH_ALL_MODE'] = ($scope === null);
}

/**
 * ATTACH satu berkas cabang ke sebuah koneksi (+ skema/migrasinya bila perlu).
 * Idempoten: cabang yang sudah terpasang tidak di-ATTACH ulang.
 *
 * @param array<int,string> $terpasang daftar [id cabang => alias] yang sudah ada
 */
function db_route_attach_one(PDO $pdo, int $bid, array &$terpasang): string
{
    if (isset($terpasang[$bid])) return $terpasang[$bid];
    if (count($terpasang) >= DB_ROUTE_MAX_ATTACH) return '';
    $file = db_branch_path($bid);
    if (!is_file($file)) db_branch_create($bid);          // cabang baru → otomatis dibuat
    if (!is_file($file)) return '';
    $alias = 'b' . $bid;
    try {
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote($file) . ' AS ' . $alias);
    } catch (Throwable $e) {
        return '';
    }
    /* Skema/migrasi cabang hanya diperiksa untuk cabang yang benar-benar dipakai —
       itulah sebabnya pemeriksaan ini dipindah ke sini (dulu dilakukan untuk semua
       cabang yang di-ATTACH, termasuk yang tidak diperlukan permintaan ini). */
    db_route_ensure_branch_schema($pdo, $alias, $bid);
    $terpasang[$bid] = $alias;
    return $alias;
}

/**
 * Pastikan cabang TUJUAN TULIS ter-ATTACH; bila slot penuh, lepaskan berkas yang
 * tidak diperlukan (bukan cabang tulis, bukan cakupan baca sekarang).
 *
 * @param array<int,string> $attached daftar terpasang saat ini
 * @return array<int,string> daftar terpasang sesudahnya
 */
function db_route_attach_for_write(int $branchId, array $attached): array
{
    if (isset($attached[$branchId])) return $attached;
    $pdo = db_route_conn();
    if (count($attached) < DB_ROUTE_MAX_ATTACH) {
        if (db_route_attach_one($pdo, $branchId, $attached) !== '') {
            $GLOBALS['DB_ROUTE_ATTACHED'] = $attached;
            db_route_install_views($pdo);
        }
        return $attached;
    }
    /* Slot penuh → longgarkan: pertahankan cabang tulis + cakupan baca. */
    $scope = db_route_read_scope();
    $pertahankan = [$branchId => true];
    if ($scope !== null && (int)$scope > 0) $pertahankan[(int)$scope] = true;
    foreach (array_keys($attached) as $bid) {
        if (isset($pertahankan[(int)$bid])) continue;
        $alias = $attached[(int)$bid];
        try {
            $pdo->exec('DETACH DATABASE ' . $pdo->quote($alias));
        } catch (Throwable $e) {
            continue;
        }
        unset($attached[(int)$bid]);
        break;                                  // satu slot cukup
    }
    if (db_route_attach_one($pdo, $branchId, $attached) !== '') {
        $GLOBALS['DB_ROUTE_ATTACHED'] = $attached;
        $GLOBALS['DB_ROUTE_ATTACH_SKIPPED'] = array_values(array_diff(db_route_branch_ids(), array_keys($attached)));
        db_route_install_views($pdo);
    }
    return $attached;
}

/**
 * Pastikan sebuah cabang TER-ATTACH pada koneksi utama (dipakai bila alurnya
 * ternyata membutuhkan cabang lain — mis. membuka rekam medis pasien cabang lain
 * oleh akun lintas cabang). View dibangun ulang setelahnya.
 */
function db_route_require_branch(int $bid): bool
{
    $bid = (int)$bid;
    if ($bid <= 0) return false;
    $attached = db_route_attached();
    if (isset($attached[$bid])) return true;
    $pdo = db_route_conn();
    $baru = db_route_attach_one($pdo, $bid, $attached);
    if ($baru === '') return false;
    $GLOBALS['DB_ROUTE_ATTACHED'] = $attached;
    $GLOBALS['DB_ROUTE_ATTACH_SKIPPED'] = array_values(array_diff(db_route_branch_ids(), array_keys($attached)));
    db_route_install_views($pdo);
    return true;
}

/** Cabang yang berhasil di-ATTACH pada permintaan ini: [id => alias]. */
/**
 * Tulis isi WAL ke berkas utama untuk SEMUA basis data: central (main) DAN setiap
 * berkas cabang yang ter-ATTACH.
 *
 * KENAPA WAJIB: aplikasi memakai mode WAL, sehingga perubahan terbaru bisa masih
 * berada di berkas `-wal` dan BELUM masuk berkas utamanya. Penyalinan berkas
 * (backup, pratinjau, migrasi) yang hanya membaca berkas utama akan KEHILANGAN
 * data terbaru. `PRAGMA wal_checkpoint` biasa hanya mengenai basis data `main`
 * (central), sedangkan berkas cabang perlu `PRAGMA <alias>.wal_checkpoint`.
 * Bug ini nyata: backup paket sempat berisi berkas cabang TANPA data terbaru.
 */
function db_checkpoint_all(): void
{
    try { db()->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable $e) { /* lanjut */ }
    foreach (db_route_attached() as $alias) {
        try { db()->exec('PRAGMA ' . $alias . '.wal_checkpoint(TRUNCATE)'); } catch (Throwable $e) { /* lanjut */ }
    }
}

function db_route_attached(): array
{
    /* PENJAGA RE-ENTRANCY (penting): saat koneksi SEDANG dibangun (`DB_ROUTE_ATTACHING`),
       pemanggilan `db_route_conn()` dari sini akan memulai pembangunan koneksi yang
       sama sekali lagi — berputar tanpa henti sampai memori habis. Ini benar-benar
       terjadi (Allowed memory size exhausted) ketika penyiapan skema/migrasi cabang
       memanggil kembali lapisan query. Selama pembangunan, daftar yang sudah terpasang
       dikembalikan apa adanya (boleh kosong). */
    if (empty($GLOBALS['DB_ROUTE_ATTACHING'])
        && (!isset($GLOBALS['DB_ROUTE_ATTACHED']) || !is_array($GLOBALS['DB_ROUTE_ATTACHED']))) {
        db_route_conn();
    }
    return is_array($GLOBALS['DB_ROUTE_ATTACHED'] ?? null) ? $GLOBALS['DB_ROUTE_ATTACHED'] : [];
}

/* ------------------------------------------------------------------ *
 * FAN-OUT: MEMBACA CABANG SATU PER SATU (untuk 10–15 cabang)
 * ------------------------------------------------------------------ *
 * Batas ATTACH SQLite hanya 10 basis data per koneksi (central + 9 cabang) dan
 * TIDAK dapat dinaikkan saat berjalan. Karena itu laporan/agregasi lintas cabang
 * TIDAK boleh bergantung pada "semua cabang ter-ATTACH": berkas cabang dibuka satu
 * per satu pada koneksi terpisah (central + 1 cabang), query yang SAMA dijalankan
 * di sana, lalu hasilnya digabung di PHP.
 *
 * Keuntungan: jumlah berkas terbuka tetap 2 pada satu waktu, berlaku untuk berapa
 * pun cabangnya, dan TIDAK ada data transaksi yang dipindahkan ke central.
 */

/**
 * Koneksi ber-cakupan SATU cabang (central + berkas cabang itu, view disaring ke
 * cabang tersebut). Dipakai fan-out; hasilnya di-cache terbatas (2 terakhir) supaya
 * jumlah berkas terbuka tetap kecil.
 *
 * @return PDO|null null bila berkas cabangnya tidak ada
 */
function db_scope_conn(int $branchId, ?array $hanyaTabel = null): ?PDO
{
    $branchId = (int)$branchId;
    if ($branchId <= 0) return null;
    /* Koneksi ber-cakupan SATU cabang tanpa TEMP VIEW lengkap (dipakai fan-out):
       sasaran tabelnya diteruskan supaya hanya view yang dibutuhkan dibuat. */
    $ringkas = $hanyaTabel !== null;
    if (!isset($GLOBALS['DB_SCOPE_POOL']) || !is_array($GLOBALS['DB_SCOPE_POOL'])) {
        $GLOBALS['DB_SCOPE_POOL'] = [];
    }
    $pool = $GLOBALS['DB_SCOPE_POOL'];
    if ($ringkas) return db_fanout_conn($branchId);
    if (isset($pool[$branchId])) {
        /* Sentuh urutan pemakaian supaya yang terakhir dipakai tidak dibuang. */
        unset($GLOBALS['DB_SCOPE_POOL'][$branchId]);
        $GLOBALS['DB_SCOPE_POOL'][$branchId] = $pool[$branchId];
        return $pool[$branchId];
    }
    $file = db_branch_path($branchId);
    if (!is_file($file)) return null;
    $central = db_central_path();
    try {
        $pdo = db_open($central);
        /* `$att` HARUS variabel tersendiri: parameter ke-3 `db_route_attach_one()`
           diteruskan by-reference, sehingga ekspresi bawaan di dalam pemanggilan
           ditolak PHP ("cannot be passed by reference"). */
        $att = [];
        db_route_attach_one($pdo, $branchId, $att);
        if (!$att) return null;
        db_route_install_views($pdo, $att, $branchId);
        $GLOBALS['DB_SCOPE_POOL'][$branchId] = $pdo;
        /* Batasi: hanya 2 koneksi cabang yang disimpan (yang lain dilepas). Berkas
           basis data ditutup sendiri oleh SQLite begitu PDO-nya tidak lagi dirujuk,
           sehingga jumlah berkas terbuka tetap kecil walau cabangnya banyak. */
        while (count($GLOBALS['DB_SCOPE_POOL']) > 2) {
            $kunci = array_key_first($GLOBALS['DB_SCOPE_POOL']);
            unset($GLOBALS['DB_SCOPE_POOL'][$kunci]);
        }
        return $pdo;
    } catch (Throwable $e) {
        /* Dilaporkan apa adanya supaya fan-out yang gagal tidak pernah diam-diam
           membuat angka laporan tampak lebih kecil. */
        $GLOBALS['DB_SCOPE_LAST_ERROR'] = 'Cabang ' . $branchId . ': ' . $e->getMessage();
        return null;
    }
}

/**
 * KONEKSI FAN-OUT BERSAMA — SATU koneksi untuk membaca SELURUH cabang satu per satu.
 *
 * MENGAPA begini: membuka koneksi baru (central 7 MB) untuk setiap cabang membuat
 * laporan lintas cabang pada 15 cabang memakan ~45 ms hanya untuk membuka berkas.
 * Sebagai gantinya:
 *   • koneksi dibuka SEKALI dan view-nya dibuat SEKALI (alias tetap `bf`);
 *   • untuk tiap cabang berkasnya cukup DILEPAS lalu DIPASANG ULANG sebagai `bf`,
 *     dan view tetap sah karena menunjuk NAMA alias (bukan berkas).
 * Hasil ukur: 6 cabang fan-out turun dari ~46 ms menjadi beberapa milidetik.
 * Aman: koneksi ini hanya dipakai BERURUTAN (satu cabang pada satu waktu).
 */
function db_fanout_conn(int $branchId): ?PDO
{
    if ($branchId <= 0) return null;
    $file = db_branch_path($branchId);
    if (!is_file($file)) return null;
    try {
        if (!isset($GLOBALS['DB_FANOUT_PDO']) || !($GLOBALS['DB_FANOUT_PDO'] instanceof PDO)) {
            $pdo = db_open(db_central_path());
            /* Berkas pertama dipasang SEBELUM view dibuat (SQLite memvalidasi nama
               tabel saat CREATE VIEW). */
            $pdo->exec('ATTACH DATABASE ' . $pdo->quote($file) . ' AS bf');
            /* SATU set view untuk SEMUA cabang: penyaring cabangnya membaca penanda
               `temp.nv_fan_branch`, jadi menukar cabang cukup menukar berkas + satu
               UPDATE. Kunci peta WAJIB id cabang (bukan alias) — pembuat view memakai
               kunci itu sebagai nilai cadangan pada penyaring. */
            $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS nv_fan_branch (v INTEGER)');
            $pdo->exec('INSERT INTO nv_fan_branch (v) VALUES (' . $branchId . ')');
            db_route_install_views($pdo, [$branchId => 'bf'], null, true, null,
                '(SELECT v FROM nv_fan_branch)');
            $GLOBALS['DB_FANOUT_PDO'] = $pdo;
            $GLOBALS['DB_FANOUT_FILE'] = $file;
            $GLOBALS['DB_FANOUT_BRANCH'] = $branchId;
            return $pdo;
        }
        $pdo = $GLOBALS['DB_FANOUT_PDO'];
        if (($GLOBALS['DB_FANOUT_FILE'] ?? '') === $file) return $pdo;
        /* Tukar berkas + penanda cabang (view tidak perlu dibuat ulang). */
        $pdo->exec('DETACH DATABASE bf');
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote($file) . ' AS bf');
        $pdo->exec('UPDATE nv_fan_branch SET v = ' . (int)$branchId);
        $GLOBALS['DB_FANOUT_FILE'] = $file;
        $GLOBALS['DB_FANOUT_BRANCH'] = $branchId;
        return $pdo;
    } catch (Throwable $e) {
        $GLOBALS['DB_SCOPE_LAST_ERROR'] = 'Cabang ' . $branchId . ': ' . $e->getMessage();
        unset($GLOBALS['DB_FANOUT_PDO'], $GLOBALS['DB_FANOUT_FILE']);
        return null;
    }
}

/** Cabang yang perlu dilayani fan-out = semua cabang TERDAFTAR (bukan hanya yang ter-ATTACH). */
function db_route_all_branch_ids(): array
{
    return db_route_branch_ids();
}

/** Jumlah SELURUH cabang yang terdaftar (untuk keterangan batas teknis). */
function db_route_branches_semua(): array
{
    return db_route_branch_ids();
}

/**
 * Cabang yang TIDAK ter-ATTACH pada koneksi utama (perlu fan-out).
 *
 * Dihitung dari KEADAAN SEKARANG (seluruh cabang terdaftar − yang ter-ATTACH), bukan
 * dari daftar "terlewat" yang disimpan saat koneksi dibangun. Daftar simpanan itu
 * menjadi BASI begitu ada cabang baru (proses panjang / permintaan yang baru membuat
 * cabang) — akibatnya laporan lintas cabang kehilangan cabang baru TANPA pesan
 * kesalahan apa pun.
 */
function db_route_missing_branches(): array
{
    if (empty($GLOBALS['DB_ROUTE_ATTACH_ALL_MODE'])) return [];
    $terpasang = array_map('intval', array_keys(db_route_attached()));
    return array_values(array_diff(db_route_branch_ids(), $terpasang));
}

/**
 * KONEKSI FAN-OUT BERKELOMPOK — beberapa berkas cabang di-ATTACH SEKALIGUS.
 *
 * MENGAPA: cara lama membuka basis data cabang SATU PER SATU pada satu koneksi
 * (DETACH/ATTACH tiap cabang). Untuk laporan lintas cabang pada 25 cabang, satu
 * halaman Laporan menukar berkas ratusan kali sehingga waktunya habis di buka-tutup
 * berkas, bukan di query-nya.
 *
 * Sekarang cabang-cabang yang tidak ter-ATTACH dikelompokkan menjadi beberapa koneksi
 * (masing-masing memuat sampai batas teknis SQLite = 9 berkas) dan SETIAP koneksi
 * memakai view UNION ALL yang tiap lengannya disaring ke cabang pemilik berkasnya.
 * Dengan begitu satu query melayani seluruh cabang dalam kelompok itu, dan hasilnya
 * tetap dipisah per cabang karena setiap lengan membawa `branch_id` sendiri.
 *
 * Koneksinya di-cache sepanjang permintaan (isinya tidak berubah).
 *
 * @param array<int> $ids cabang yang perlu dilayani
 * @return array<int,PDO> daftar koneksi; setiap koneksi memuat `$GROUP` cabang
 */
function db_fanout_conn_group(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $kunci = implode(',', $ids);
    if (isset($GLOBALS['DB_FANOUT_GROUPS'][$kunci])) return $GLOBALS['DB_FANOUT_GROUPS'][$kunci];
    $out = [];
    foreach (array_chunk($ids, DB_ROUTE_MAX_ATTACH) as $chunk) {
        $pdo = null;
        try {
            $pdo = db_open(db_central_path());
            $att = [];
            foreach ($chunk as $bid) {
                db_route_attach_one($pdo, (int)$bid, $att);
            }
            if (!$att) { $pdo = null; continue; }
            /* View = UNION ALL seluruh lengan; setiap lengan disaring ke cabang
               pemilik berkasnya (lihat db_route_view_sql()). */
            db_route_install_views($pdo, $att, null);
            $out[] = $pdo;
        } catch (Throwable $e) {
            $GLOBALS['DB_SCOPE_LAST_ERROR'] = $e->getMessage();
            $pdo = null;
        }
    }
    /* Batasi jumlah koneksi yang disimpan (proses panjang) — hanya kelompok yang
       baru saja dipakai yang disimpan. */
    if (!isset($GLOBALS['DB_FANOUT_GROUPS']) || count($GLOBALS['DB_FANOUT_GROUPS']) > 3) {
        $GLOBALS['DB_FANOUT_GROUPS'] = [];
    }
    $GLOBALS['DB_FANOUT_GROUPS'][$kunci] = $out;
    return $out;
}

/**
 * Jalankan satu query pada SELURUH cabang yang tidak ter-ATTACH, berkelompok.
 *
 * View setiap koneksi berupa UNION ALL, sehingga satu query melayani seluruh cabang
 * dalam kelompok itu (bukan satu query per cabang).
 *
 * @return array<int,array> seluruh baris hasil (gabungan semua kelompok)
 */
function db_fanout_run(string $sql, array $params): array
{
    $ids = db_cross_fanout_ids($sql, $params);
    if (!$ids) return [];
    $out = [];
    foreach (db_fanout_conn_group($ids) as $pdo) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll() as $r) $out[] = $r;
        } catch (Throwable $e) {
            $GLOBALS['DB_SCOPE_LAST_ERROR'] = $e->getMessage();
        }
    }
    return $out;
}

/**
 * Jalankan sebuah callback untuk SETIAP cabang (satu per satu, koneksi ber-cakupan).
 *
 * @param callable(PDO,int):mixed $fn    menerima (koneksi ber-cakupan, id cabang)
 * @param array<int>|null          $ids  daftar cabang; null = cabang yang TIDAK ter-ATTACH
 * @return array<int,mixed> hasil per cabang (kunci = id cabang)
 */
function db_branch_each(callable $fn, ?array $ids = null, ?array $hanyaTabel = null): array
{
    $ids = $ids ?? db_route_missing_branches();
    $out = [];
    foreach ($ids as $bid) {
        $pdo = db_scope_conn((int)$bid, $hanyaTabel);
        if (!$pdo) continue;
        try {
            $out[(int)$bid] = $fn($pdo, (int)$bid);
        } catch (Throwable $e) {
            $out[(int)$bid] = null;
        }
    }
    return $out;
}

/**
 * JUMLAHKAN hasil query agregat (satu baris) dari cabang-cabang yang tidak ter-ATTACH.
 *
 * Dipakai laporan/dashboard/keuangan supaya angka lintas cabang tetap BENAR walau
 * cabangnya lebih banyak daripada batas ATTACH. Kolom non-numerik diabaikan.
 *
 * @param string   $sql          query agregat yang sama dengan yang dipakai laporan
 * @param array    $params       parameter query
 * @param string[] $kolomAngka   nama kolom yang dijumlahkan
 * @return array<string,float>   total tambahan per kolom
 */
/**
 * Cabang yang perlu dilayani fan-out untuk sebuah query.
 *
 * Bila query-nya SUDAH menyebut satu cabang (`branch_id = ?`/literal), fan-out cukup
 * ke cabang itu saja (dan tidak perlu bila cabangnya sudah ter-ATTACH). Ini yang membuat
 * LOOPS per cabang (mis. ringkasan keuangan 25 cabang) tetap murah: tanpa pemetaan ini
 * setiap panggilan akan mem-fan-out ke SELURUH cabang yang tidak ter-ATTACH.
 *
 * @return array<int>
 */
/**
 * Cache hasil fan-out dalam SATU permintaan.
 *
 * Satu halaman (mis. Laporan) memanggil query yang SAMA lebih dari sekali —
 * `report_bundle()` menjalankan `report_daily()` lalu `report_monthly()` yang di
 * dalamnya memanggil `report_daily()` lagi. Tanpa cache, setiap pemanggilan membaca
 * ulang seluruh berkas cabang di luar batas ATTACH (pada 25 cabang: 16 berkas).
 * Isi basis data tidak berubah selama satu permintaan, jadi hasilnya boleh disimpan.
 * Cache dibatalkan saat tulisan terjadi (`db_route_cache_flush()`).
 */
function db_cross_cache_get(string $kunci)
{
    if (!empty($GLOBALS['DB_CROSS_CACHE_FLUSHED'])) return null;
    return $GLOBALS['DB_CROSS_CACHE'][$kunci] ?? null;
}
function db_cross_cache_put(string $kunci, $nilai): void
{
    if (!empty($GLOBALS['DB_CROSS_CACHE_FLUSHED'])) return;
    $GLOBALS['DB_CROSS_CACHE'][$kunci] = $nilai;
    /* Batasi ukuran cache supaya tidak tumbuh tanpa batas pada proses panjang. */
    if (count($GLOBALS['DB_CROSS_CACHE']) > 400) array_shift($GLOBALS['DB_CROSS_CACHE']);
}
/** Buang cache lintas cabang (dipanggil setiap kali ada tulisan data operasional). */
function db_route_cache_flush(): void
{
    $GLOBALS['DB_CROSS_CACHE'] = [];
    $GLOBALS['DB_CROSS_CACHE_FLUSHED'] = false;
}

function db_cross_fanout_ids(string $sql, array $params): array
{
    if (!function_exists('db_route_missing_branches')) return [];
    $target = db_route_branch_from_sql($sql, $params);
    if ($target !== null && $target > 0) {
        $att = array_map('intval', array_keys(db_route_attached()));
        return in_array($target, $att, true) ? [] : [$target];
    }
    return db_route_missing_branches();
}

function db_cross_sum(string $sql, array $params, array $kolomAngka): array
{
    $total = array_fill_keys($kolomAngka, 0.0);
    if (!$kolomAngka) return $total;
    $kunci = 'sum|' . md5($sql . '|' . json_encode($params));
    $cache = db_cross_cache_get($kunci);
    if (is_array($cache)) {
        foreach ($kolomAngka as $k) $total[$k] = (float)($cache[$k] ?? 0);
        return $total;
    }
    foreach (db_fanout_conn_group(db_cross_fanout_ids($sql, $params)) as $pdo) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $baris = $st->fetch() ?: [];
        } catch (Throwable $e) { $baris = []; }
        foreach ($kolomAngka as $k) {
            if (isset($baris[$k])) $total[$k] += (float)$baris[$k];
        }
    }
    db_cross_cache_put($kunci, $total);
    return $total;
}

/**
 * Query DAFTAR berurut & berbatas yang LENGKAP untuk seluruh cabang.
 *
 * Dipakai daftar seperti "8 transaksi terbaru" / "stok menipis": query dijalankan pada
 * koneksi utama (cabang yang ter-ATTACH) DAN pada setiap cabang yang tidak ter-ATTACH
 * (satu berkas pada satu waktu), lalu SELURUH barisnya diurutkan & dibatasi ULANG —
 * sehingga daftarnya benar-benar memuat baris teratas dari SELURUH cabang, bukan hanya
 * dari 9 cabang pertama.
 *
 * @param string $urut  nama kolom hasil untuk pengurutan menurun
 * @param int    $limit jumlah baris akhir
 */
function db_cross_top(string $sql, array $params, string $urut, int $limit, bool $naik = false): array
{
    $ck = 'top|' . md5($sql . '|' . json_encode($params) . '|' . $urut . '|' . $limit . '|' . (int)$naik);
    $cache = db_cross_cache_get($ck);
    if (is_array($cache)) return $cache;
    $kumpul = [];
    foreach (all($sql, $params) as $r) $kumpul[] = $r;
    foreach (db_fanout_run($sql, $params) as $r) $kumpul[] = $r;
    if ($urut !== '') {
        usort($kumpul, function ($a, $b) use ($urut, $naik) {
            $x = $a[$urut] ?? null; $y = $b[$urut] ?? null;
            if (is_numeric($x) && is_numeric($y)) return $naik ? ($x <=> $y) : ($y <=> $x);
            return $naik ? strcmp((string)$x, (string)$y) : strcmp((string)$y, (string)$x);
        });
    }
    $out = $limit > 0 ? array_slice($kumpul, 0, $limit) : $kumpul;
    db_cross_cache_put($ck, $out);
    return $out;
}

/**
 * Tabel OPERASIONAL yang disebut sebuah query (untuk membuat view seperlunya saja).
 *
 * Dipakai fan-out: laporan yang hanya menyentuh `orders` + `order_items` cukup
 * dibuatkan 2 view per cabang, bukan 19.
 */
function db_route_sql_tables(string $sql): array
{
    $ada = [];
    foreach (db_route_branch_tables() as $t) {
        if (preg_match('/\b' . preg_quote($t, '/') . '\b/i', $sql)) $ada[] = $t;
    }
    return $ada ?: db_route_branch_tables();
}

/**
 * Jumlahkan baris hasil query DAFTAR (banyak baris) dari cabang yang tidak ter-ATTACH.
 * Dipakai rekap seperti "penjualan per cabang" yang memang butuh baris, bukan skalar.
 *
 * @return array<int,array> baris gabungan
 */
function db_cross_rows(string $sql, array $params = []): array
{
    return db_fanout_run($sql, $params);
}

/**
 * Satu baris AGGREGAT yang LENGKAP untuk seluruh cabang (view + fan-out dijumlahkan).
 *
 * Padanan `one()` untuk angka yang harus mencakup SELURUH cabang, termasuk cabang di
 * luar batas ATTACH SQLite. Seluruh kolom pada `$kolomAngka` dijumlahkan.
 *
 * @return array<string,mixed> baris gabungan (kolom yang tidak disebut tetap dari sumber pertama)
 */
function db_cross_one(string $sql, array $params, array $kolomAngka): array
{
    $kunci = 'one|' . md5($sql . '|' . json_encode($params));
    $cache = db_cross_cache_get($kunci);
    if (is_array($cache)) return $cache;
    $hasil = [];
    $pertama = one($sql, $params);
    if (is_array($pertama)) $hasil = $pertama;
    foreach ($kolomAngka as $k) if (!isset($hasil[$k])) $hasil[$k] = 0;
    $ids = db_cross_fanout_ids($sql, $params);
    if (!$ids) return $hasil;
    foreach (db_fanout_conn_group($ids) as $pdo) {
        try { $baris = one_on($pdo, $sql, $params) ?? []; } catch (Throwable $e) { $baris = []; }
        if (!is_array($baris)) continue;
        foreach ($kolomAngka as $k) {
            if (isset($baris[$k]) && is_numeric($baris[$k])) {
                $hasil[$k] = (is_numeric($hasil[$k] ?? 0) ? (float)$hasil[$k] : 0) + (float)$baris[$k];
            }
        }
    }
    db_cross_cache_put($kunci, $hasil);
    return $hasil;
}

/**
 * Query AGGREGAT (GROUP BY kunci) yang LENGKAP untuk seluruh cabang.
 *
 * MASALAH yang diselesaikan: TEMP VIEW hanya memuat cabang yang ter-ATTACH (maksimum
 * 9 berkas karena batas SQLite). Query agregat yang dijalankan sekali pada koneksi
 * utama karena itu **kehilangan cabang ke-10 dan seterusnya TANPA pesan kesalahan**
 * — tabel "per cabang", grafik harian/bulanan, dan rekap kasir tampak "hampir benar"
 * tetapi jumlahnya kurang. Helper ini menjalankan query yang SAMA pada setiap cabang
 * yang tidak ter-ATTACH (satu berkas pada satu waktu) lalu MENGGABUNGKAN barisnya per
 * nilai kunci dengan menjumlahkan seluruh kolom numerik.
 *
 * Benar karena id baris memakai blok per cabang (`db_branch_id_floor`), sehingga
 * `COUNT(DISTINCT id)` per cabang tidak mungkin tumpang tindih.
 *
 * @param string   $sql    query agregat
 * @param array    $params parameter
 * @param string[] $kunci  kolom penentu identitas baris (mis. ['branch_id'] atau ['d'])
 * @param string   $urut   kolom numerik untuk pengurutan menurun ('' = tanpa urut ulang)
 * @param int      $limit  batasi jumlah baris hasil (0 = tanpa batas)
 * @return array<int,array>
 */
function db_cross_group(string $sql, array $params, array $kunci, string $urut = '', int $limit = 0): array
{
    $ck = 'grp|' . md5($sql . '|' . json_encode($params) . '|' . implode(',', $kunci) . '|' . $urut . '|' . $limit);
    $cache = db_cross_cache_get($ck);
    if (is_array($cache)) return $cache;
    $gabung = [];
    $tambah = function (array $rows) use (&$gabung, $kunci) {
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $k = [];
            foreach ($kunci as $c) $k[] = (string)($r[$c] ?? '');
            $ks = implode("\x1f", $k);
            if (!isset($gabung[$ks])) { $gabung[$ks] = $r; continue; }
            foreach ($r as $col => $val) {
                /* Kolom numerik dijumlahkan; kolom teks dipertahankan apa adanya. */
                if (isset($gabung[$ks][$col]) && is_numeric($val) && is_numeric($gabung[$ks][$col])) {
                    $gabung[$ks][$col] = $gabung[$ks][$col] + $val;
                } elseif (!isset($gabung[$ks][$col])) {
                    $gabung[$ks][$col] = $val;
                }
            }
        }
    };
    $tambah(all($sql, $params));
    $tambah(db_fanout_run($sql, $params));
    $out = array_values($gabung);
    if ($urut !== '') {
        usort($out, fn($a, $b) => ((float)($b[$urut] ?? 0)) <=> ((float)($a[$urut] ?? 0)));
    }
    if ($limit > 0) $out = array_slice($out, 0, $limit);
    db_cross_cache_put($ck, $out);
    return $out;
}

/**
 * Lepaskan koneksi routed (& koneksi fan-out) supaya permintaan berikutnya membangun
 * ulang dari berkas yang ADA DI DISK SEKARANG.
 *
 * WAJIB dipanggil setiap kali berkas basis data diganti dari luar aplikasi
 * (pemulihan backup/paket). Tanpa ini, koneksi lama masih memegang berkas lama
 * beserta `-wal`-nya sehingga penulisan berikutnya dapat memunculkan kembali
 * baris-baris yang sudah dipulihkan.
 */
function db_route_reset(): void
{
    $GLOBALS['DB_ROUTE_RESET'] = true;
    $GLOBALS['DB_SCOPE_POOL'] = [];
    unset($GLOBALS['DB_FANOUT_PDO'], $GLOBALS['DB_FANOUT_FILE']);
    $GLOBALS['DB_ROUTE_ATTACHED'] = [];
    $GLOBALS['DB_ROUTE_ATTACH_SKIPPED'] = [];
    db_route_branches_changed();
    /* `db_scope_conn()` menyimpan koneksinya di static → dipaksa lepas juga. */
    if (function_exists('db_scope_conn_pool_clear')) db_scope_conn_pool_clear();
}

/**
 * Bersihkan sisa WAL/SHM berkas basis data dari disk.
 *
 * Dipakai setelah pemulihan: berkas `-wal` lama yang tertinggal akan DIPUTAR ULANG
 * oleh SQLite saat berkasnya dibuka lagi, sehingga dapat mengembalikan data lama.
 */
function db_route_clear_wal(string $path): void
{
    foreach (['-wal', '-shm'] as $akhiran) {
        if (is_file($path . $akhiran)) @unlink($path . $akhiran);
    }
}

/** Cabang yang TIDAK kebagian slot ATTACH (dilaporkan, bukan disembunyikan). */
function db_route_attach_skipped(): array
{
    return $GLOBALS['DB_ROUTE_ATTACH_SKIPPED'] ?? [];
}

/**
 * Daftar id cabang (dibaca langsung dari central, tanpa lewat dispatcher).
 *
 * Disimpan di `$GLOBALS` (bukan `static`) supaya dapat DIBATALKAN saat daftar cabang
 * berubah — lihat `db_route_branches_changed()`. Dengan `static`, proses yang berjalan
 * lama (pekerja latar, pengisian data demo) atau permintaan yang MEMBUAT cabang baru
 * tetap memakai daftar lama sehingga cabang barunya tidak ikut laporan/fan-out.
 */
function db_route_branch_ids(): array
{
    if (isset($GLOBALS['DB_ROUTE_BRANCH_IDS']) && is_array($GLOBALS['DB_ROUTE_BRANCH_IDS'])) {
        return $GLOBALS['DB_ROUTE_BRANCH_IDS'];
    }
    $rows = db_route_raw_central_many('SELECT id FROM branches ORDER BY id');
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    if (!$ids) $ids = [1];
    return $GLOBALS['DB_ROUTE_BRANCH_IDS'] = $ids;
}

/**
 * Daftar cabang berubah (cabang ditambah/diubah/dihapus) → buang cache daftar cabang
 * supaya laporan & fan-out pada permintaan/proses yang sama langsung ikut melihatnya.
 */
function db_route_branches_changed(): void
{
    unset($GLOBALS['DB_ROUTE_BRANCH_IDS']);
}

/**
 * Koneksi LANGSUNG ke central untuk kebutuhan bootstrap (tanpa dispatcher).
 *
 * Tahan gagal: bila berkasnya ada tetapi skemanya belum terbentuk (pernah terjadi —
 * berkas dibuat lebih dulu oleh db_open, skema baru menyusul), skema diterapkan
 * lebih dulu. Tanpa ini, halaman Developer Settings mati dengan
 * "no such table: branches" pada basis data baru.
 */
function db_route_raw_central_pdo(): ?PDO
{
    static $pdo = null;
    static $gagal = false;
    if ($pdo instanceof PDO) return $pdo;
    if ($gagal) return null;
    try {
        $p = db_central_path();
        if (!is_file($p)) db_schema_apply($p, 'central');
        $pdo = db_open($p);
        try {
            $pdo->query('SELECT 1 FROM branches LIMIT 1');
        } catch (Throwable $e) {
            db_schema_with_scope('central', function () use ($pdo) { ensure_schema($pdo); });
        }
        return $pdo;
    } catch (Throwable $e) {
        $gagal = true;
        return null;
    }
}

/** Panggilan langsung ke central (tanpa dispatcher — dipakai saat bootstrap). */
function db_route_raw_central(string $sql, array $params = [])
{
    $pdo = db_route_raw_central_pdo();
    if (!$pdo) return null;
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    } catch (Throwable $e) {
        return null;
    }
}
/** @return array<int,array> */
function db_route_raw_central_many(string $sql, array $params = []): array
{
    $pdo = db_route_raw_central_pdo();
    if (!$pdo) return [];
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Pastikan berkas cabang memakai skema terkini + view untuk dirinya.
 *
 * Migrasi dijalankan lewat koneksi TERPISAH ke berkas cabang itu (sehingga DDL
 * tidak menyentuh central) dan tanpa menanam data contoh.
 */
function db_route_ensure_branch_schema(PDO $pdo, string $alias, int $branchId): void
{
    static $sudah = [];
    if (isset($sudah[$branchId])) return;
    $sudah[$branchId] = true;
    $path = db_branch_path($branchId);
    try {
        $c = db_open($path);
        /* GERBANG MIGRASI memakai schema_is_current(): versi skema SAMA **dan**
           sidik jari daftar tambahan kolom cocok. Dulu hanya versi yang diperiksa,
           sehingga kolom baru pada tabel operasional tidak pernah sampai ke berkas
           cabang yang sudah ada bila SCHEMA_VERSION lupa dinaikkan (kejadian nyata:
           `backups.auto_run` → backup gagal dibuat di produksi).
           PENTING (FINAL AUDIT): pemeriksaan WAJIB dijalankan dalam ruang lingkup
           `branch` — berkas cabang tidak punya tabel `settings`, penandanya
           `PRAGMA user_version`. Sebelumnya fungsi ini dipanggil di luar ruang
           lingkup sehingga SELALU menjawab "belum terkini": skema diterapkan ulang
           setiap permintaan dan riwayat migrasi bertambah 2 baris/permintaan
           (terukur 1.945 baris `db_migrations` hanya dalam beberapa hari). */
        $belumTerkini = !db_schema_with_scope('branch', fn() => schema_is_current($c));
        if ($belumTerkini) {
            $GLOBALS['DB_SCHEMA_BRANCH_ID'] = $branchId;
            try { db_schema_apply($path, 'branch'); } finally { unset($GLOBALS['DB_SCHEMA_BRANCH_ID']); }
            /* Migrasi bisa membuat tabel dengan FK ke tabel global → dibuat ulang.
               Hanya perlu diperiksa setelah skema BENAR-BENAR diterapkan; sebelumnya
               pemeriksaan ini berjalan tiap permintaan (memindai sqlite_master +
               membuka berkas cabang lagi). */
            db_branch_strip_cross_fk_tables_pdo($c, $branchId);
        }
    } catch (Throwable $e) {
        /* Kegagalan migrasi cabang tidak boleh mematikan aplikasi: dilaporkan lewat route_report(). */
        $GLOBALS['DB_ROUTE_LAST_ERROR'] = 'Cabang ' . $branchId . ': ' . $e->getMessage();
    }
}

/**
 * Buat TEMP VIEW untuk setiap tabel operasional.
 *
 * Cakupan baca menentukan isi view:
 *   • satu cabang → hanya berkas cabang itu (ISOLASI STRUKTURAL untuk READ);
 *   • semua cabang → gabungan UNION ALL seluruh cabang yang ter-ATTACH.
 */
function db_route_install_views(PDO $pdo, ?array $attached = null, ?int $scope = null,
                                 bool $paksaSemua = false, ?array $hanyaTabel = null,
                                 string $idExpr = ''): void
{
    $attached = $attached ?? db_route_attached();
    $scope = $scope ?? db_route_read_scope();
    if ($paksaSemua) $scope = null;
    foreach (db_route_view_sql($pdo, $attached, $scope, null, $idExpr) as $t => $sql) {
        /* `$hanyaTabel` dipakai jalur fan-out: hanya tabel yang benar-benar disebut
           query yang dibuatkan view (19 view → 2–5 view per cabang), sehingga laporan
           lintas cabang pada 10–15 cabang jauh lebih ringan. */
        if ($hanyaTabel !== null && !in_array($t, $hanyaTabel, true)) continue;
        $pdo->exec('DROP VIEW IF EXISTS temp.' . $t);
        $pdo->exec('CREATE TEMP VIEW ' . $t . ' AS ' . $sql);
    }
}

/**
 * SQL setiap TEMP VIEW untuk sekumpulan berkas cabang yang ter-ATTACH.
 *
 * DIPISAH dari `db_route_install_views()` supaya pembuat view yang SAMA dapat dipakai
 * koneksi fan-out (`db_scope_conn()`) — inilah yang membuat query laporan lintas cabang
 * berjalan pada basis data cabang SATU PER SATU tanpa perlu meng-ATTACH semuanya.
 *
 * @return array<string,string> nama tabel => SQL view
 */
function db_route_view_sql(PDO $pdo, array $attached, ?int $scope, ?array $hanyaTabel = null,
                            string $idExpr = ''): array
{
    /* Setiap "lengan" view DISARING ke cabang pemilik berkasnya, sehingga baris
       milik cabang lain TIDAK PERNAH terlihat — walau berkas cabangnya memuat
       baris sisa (mis. sisa data contoh saat berkas dibuat). Inilah jaminan
       ISOLASI BACA yang bersifat struktural, bukan bergantung pada filter
       `branch_id` di masing-masing query. */
    $lengan = function (string $alias, int $bid, string $t, bool $ikutTanpaCabang) use ($pdo, $idExpr): string {
        /* `$idExpr` dipakai jalur FAN-OUT: penyaring cabangnya berupa subquery ke tabel
           penanda (`temp.nv_fan_branch`), sehingga SATU set view dapat dipakai untuk
           SEMUA cabang — cukup menukar berkas yang di-ATTACH + satu UPDATE penanda.
           Tanpa ini, view harus dibuat ulang per cabang (jauh lebih mahal). */
        $idSql = $idExpr !== '' ? $idExpr : (string)(int)$bid;
        $anak = db_route_child_tables();
        /* Baris TANPA cabang (branch_id NULL) = data bersama/lintas cabang
           (mis. supplier yang didaftarkan owner tanpa memilih cabang). Baris itu
           HARUS tetap terlihat di setiap cabang — kalau tidak, data yang dulu
           tampil mendadak "hilang". Supaya tidak berlipat ganda pada tampilan
           "semua cabang", baris tanpa cabang hanya diikutkan pada lengan PERTAMA. */
        $tanpa = $ikutTanpaCabang ? ' OR branch_id IS NULL' : '';
        if (isset($anak[$t])) {
            [$induk, $fk] = $anak[$t];
            /* Tabel anak tidak punya branch_id → dipastikan lewat INDUKnya. */
            $b = $pdo->quote($alias) . '.' . $t;
            return 'SELECT c.* FROM ' . $b . ' c WHERE EXISTS (SELECT 1 FROM ' . $pdo->quote($alias) . '.' . $induk
                . ' p WHERE p.id = c.' . $fk . ' AND (p.branch_id = ' . $idSql . $tanpa . '))';
        }
        return 'SELECT * FROM ' . $pdo->quote($alias) . '.' . $t
            . ' WHERE branch_id = ' . $idSql . $tanpa;
    };
    $out = [];
    foreach (db_route_branch_tables() as $t) {
        if ($hanyaTabel !== null && !in_array($t, $hanyaTabel, true)) continue;
        $bagian = [];
        if ($scope !== null && isset($attached[$scope])) {
            $bagian[] = $lengan($attached[$scope], (int)$scope, $t, true);
        } else {
            $pertama = true;
            foreach ($attached as $bid => $al) {
                $bagian[] = $lengan($al, (int)$bid, $t, $pertama);
                $pertama = false;
            }
        }
        if ($bagian) $out[$t] = implode(' UNION ALL ', $bagian);
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 * PENULISAN: KUALIFIKASI KEBERKAS CABANG
 * ------------------------------------------------------------------ */

/**
 * Bila `$sql` menulis ke tabel operasional, ubah targetnya menjadi berkas cabang
 * yang benar (`INSERT INTO b2.orders …`). Query baca (SELECT) dibiarkan apa adanya
 * karena sudah dilayani TEMP VIEW.
 */
function db_route_prepare(string $sql, array $params = []): string
{
    $daftar = db_route_prepare_all($sql, $params);
    return $daftar[0];
}

/**
 * Siapkan pernyataan TULIS: kembalikan DAFTAR SQL (satu per berkas cabang tujuan).
 *
 * Umumnya satu pernyataan. Namun operasi massal TANPA pembatas cabang oleh akun
 * LINTAS cabang (mis. menu "Hapus Semua Data") harus mengenai SEMUA cabang —
 * `DELETE FROM patients` tanpa filter hanya akan mengenai satu berkas bila tidak
 * dipecah. Hanya berlaku untuk UPDATE/DELETE (INSERT/REPLACE tidak dipecah supaya
 * tidak menggandakan baris).
 *
 * @return array<int,string>
 */
function db_route_prepare_all(string $sql, array $params = []): array
{
    $tulis = db_route_write_target($sql);
    if ($tulis === null) return [$sql];
    /* SETIAP pernyataan tulis membatalkan cache hasil lintas cabang (angka laporan
       tidak boleh basi setelah perubahan data). */
    if (function_exists('db_route_cache_flush')) db_route_cache_flush();
    [$tabel, $mulai, $panjang, $kutip] = $tulis;
    if (db_route_scope_of($tabel) !== 'branch') {
        /* Daftar CABANG berubah → cache daftar cabang dibatalkan supaya laporan &
           fan-out pada permintaan yang sama langsung ikut melihat cabang barunya. */
        if (strcasecmp($tabel, 'branches') === 0 && function_exists('db_route_branches_changed')) {
            db_route_branches_changed();
        }
        return [$sql];
    }
    /* Sudah dikualifikasi sebelumnya (mis. `b1.orders`) → jangan diubah lagi. */
    if ($mulai > 0 && substr($sql, $mulai - 1, 1) === '.') return [$sql];

    $perluSemua = false;
    try {
        $branch = db_route_write_branch_for($tabel, $sql, $params);
    } catch (Throwable $e) {
        throw $e;
    }
    if ($branch === 0) {
        /* Tidak dapat ditentukan cabangnya → boleh lintas cabang HANYA untuk
           UPDATE/DELETE oleh akun dengan cakupan semua cabang. */
        $lintas = in_array(db_route_sql_verb($sql), ['UPDATE', 'DELETE'], true);
        $akun = function_exists('user_branch') ? user_branch() : null;
        if ($lintas && $akun === null && db_route_default_branch() > 0) {
            $perluSemua = true;
        } else {
            $branch = db_route_default_branch();
        }
    }
    $attached = db_route_attached();
    if (!$perluSemua) {
        if (!isset($attached[$branch])) {
            /* Cabang tujuannya belum ter-ATTACH (mis. pemilik klinik dengan LEBIH dari
               9 cabang menulis data cabang ke-12). Berkasnya dipasang sesuai kebutuhan:
               bila slot penuh, berkas yang TIDAK diperlukan lagi (bukan cabang tulis,
               bukan cakupan baca saat ini) dilepas lebih dulu. Tanpa ini, penulisan ke
               cabang di luar batas ATTACH selalu gagal walaupun aplikasinya benar. */
            $attached = db_route_attach_for_write((int)$branch, $attached);
        }
        if (!isset($attached[$branch])) {
            $GLOBALS['DB_ROUTE_LAST_ERROR'] = 'Cabang ' . $branch . ' untuk tabel ' . $tabel
                . ' tidak dapat dibuka (berkas basis data cabang tidak ditemukan) — penulisan dibatalkan.';
            throw new RuntimeException($GLOBALS['DB_ROUTE_LAST_ERROR']);
        }
        $satu = substr($sql, 0, $mulai) . $attached[$branch] . '.' . $kutip . $tabel . $kutip
            . substr($sql, $mulai + $panjang);
        if (getenv('NAVEENA_DB_ROUTE_DEBUG') === '1') {
            $GLOBALS['DB_ROUTE_DEBUG'][] = $tabel . ' → cabang ' . $branch . ' | ' . preg_replace('/\s+/', ' ', trim($satu));
        }
        return [$satu];
    }
    /* Semua cabang: satu pernyataan per berkas cabang. */
    $keluar = [];
    foreach ($attached as $alias) {
        $keluar[] = substr($sql, 0, $mulai) . $alias . '.' . $kutip . $tabel . $kutip
            . substr($sql, $mulai + $panjang);
    }
    if (getenv('NAVEENA_DB_ROUTE_DEBUG') === '1') {
        $GLOBALS['DB_ROUTE_DEBUG'][] = $tabel . ' → SEMUA cabang (' . count($keluar) . ' berkas) | ' . preg_replace('/\s+/', ' ', trim($sql));
    }
    return $keluar ?: [$sql];
}

/**
 * Kata kerja sebuah pernyataan SQL (SELECT/INSERT/UPDATE/DELETE/REPLACE/…), dengan
 * KOMENTAR & spasi di AWAL pernyataan dilewati lebih dulu.
 *
 * KENAPA PENTING: `q()` memakai karakter PERTAMA untuk memutuskan apakah sebuah
 * pernyataan perlu dirutekan ke berkas cabang (`DELETE FROM orders` → `DELETE FROM
 * b2.orders`). Sebelumnya komentar di awal pernyataan — mis. penanda
 * `/* cross-branch *​/ DELETE FROM medical_record_photos …` — membuat pemeriksaan itu
 * meleset, sehingga DELETE dijalankan pada TEMP VIEW dan SQLite menolaknya
 * ("cannot modify … because it is a view"). Gunakan helper ini, jangan periksa
 * karakter pertama secara langsung.
 */
function db_route_sql_verb(string $sql): string
{
    $s = ltrim($sql);
    /* Lewati komentar blok & komentar baris berulang di awal pernyataan. */
    $ubah = true;
    while ($ubah && $s !== '') {
        $ubah = false;
        if (strncmp($s, '/*', 2) === 0) {
            $tutup = strpos($s, '*/');
            $s = $tutup === false ? '' : ltrim(substr($s, $tutup + 2));
            $ubah = true;
        } elseif (strncmp($s, '--', 2) === 0) {
            $baris = strpos($s, "\n");
            $s = $baris === false ? '' : ltrim(substr($s, $baris + 1));
            $ubah = true;
        }
    }
    if ($s === '') return '';
    if (!preg_match('/^([A-Za-z_]+)/', $s, $m)) return '';
    return strtoupper($m[1]);
}

/**
 * Kenali target pernyataan tulis.
 * @return array{0:string,1:int,2:int,3:string}|null [tabel, posisi, panjang, kutip]
 */
function db_route_write_target(string $sql): ?array
{
    $pola = '/\b(?:INSERT\s+(?:OR\s+\w+\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+([`"\[]?)([A-Za-z_][A-Za-z0-9_]*)\1/i';
    if (!preg_match($pola, $sql, $m, PREG_OFFSET_CAPTURE)) return null;
    $tabel = $m[2][0];
    /* `INSERT INTO b2.orders` sudah berkualifikasi. */
    $posTabel = (int)$m[2][1];
    if ($posTabel > 0 && substr($sql, $posTabel - 1, 1) === '.') return null;
    return [$tabel, (int)$m[1][1], strlen($m[1][0]) + strlen($tabel) + strlen($m[1][0]), $m[1][0]];
}

/**
 * Tentukan cabang tujuan penulisan.
 *
 * Urutan: penetapan eksplisit → nilai `branch_id` pada pernyataan →
 * letak barisnya (UPDATE/DELETE) → cakupan akun.
 */
function db_route_write_branch_for(string $tabel, string $sql, array $params = []): int
{
    /* 1. Penetapan eksplisit (dipakai alur sistem: migrasi, data demo, alur owner). */
    if (isset($GLOBALS['DB_ROUTE_WRITE_BRANCH']) && (int)$GLOBALS['DB_ROUTE_WRITE_BRANCH'] > 0) {
        return (int)$GLOBALS['DB_ROUTE_WRITE_BRANCH'];
    }
    /* 1b. Tabel yang menunjuk INDUK milik cabang → ikuti cabang INDUK (lihat
       db_route_parent_refs()); dihitung SEBELUM nilai branch_id pada pernyataan
       supaya tidak pernah menulis ke berkas cabang yang tidak memuat induknya. */
    $dariInduk = null;
    $pref = db_route_parent_refs();
    if (isset($pref[$tabel])) {
        [$induk, $fk] = $pref[$tabel];
        $pid = db_route_col_value($sql, $fk, $params);
        if ($pid !== null && $pid > 0) {
            $b = db_route_branch_of_id($induk, $pid);
            if ($b !== null && $b > 0) $dariInduk = $b;
        }
    }
    /* 2. ISOLASI TULIS: akun yang dipin ke satu cabang SELALU menulis ke cabangnya.
       Bila pernyataannya (atau induk datanya) berada di cabang LAIN, penulisan
       DITOLAK — bukan dialihkan diam-diam ke cabang akun (pengalihan senyap pernah
       membuat nomor dokumen bertanda cabang lain tetapi barisnya mendarat di cabang
       akun). Penegakan ini di lapisan basis data, jadi berlaku walau halaman lupa
       memeriksa. */
    $akun = function_exists('user_branch') ? user_branch() : null;
    if ($akun !== null && (int)$akun > 0) {
        $dariSql2 = db_route_branch_from_sql($sql, $params);
        $diminta = ($dariSql2 !== null && $dariSql2 > 0) ? $dariSql2 : $dariInduk;
        if ($diminta !== null && (int)$diminta > 0 && (int)$diminta !== (int)$akun) {
            $pesan = 'Anda tidak memiliki akses ke cabang ini — penulisan ke cabang lain ditolak.';
            $GLOBALS['DB_ROUTE_WRITE_BLOCKED'][] = $tabel . ' (diminta cabang ' . (int)$diminta
                . ', akun di cabang ' . (int)$akun . ')';
            throw new RuntimeException($pesan);
        }
        return (int)$akun;
    }
    /* Cabang mengikuti INDUK (aturan "cabang data mengikuti pasien"). */
    if ($dariInduk !== null) return $dariInduk;
    $dariSql = db_route_branch_from_sql($sql, $params);
    if ($dariSql !== null && $dariSql > 0) return $dariSql;

    /* UPDATE/DELETE: cari di berkas cabang mana baris itu berada (paling dapat dipercaya —
       tidak mungkin "mengubah 0 baris" karena salah berkas). Kata kerja dibaca lewat
       db_route_sql_verb() supaya komentar di awal pernyataan tidak membuatnya meleset. */
    if (in_array(db_route_sql_verb($sql), ['UPDATE', 'DELETE'], true)) {
        $id = db_route_col_value($sql, 'id', $params);
        if ($id !== null && $id > 0) {
            $ketemu = db_route_branch_of_id($tabel, $id);
            if ($ketemu !== null && $ketemu > 0) return $ketemu;
        }
    }
    /* Tabel anak: cabang ditentukan dari INDUK yang dirujuk pernyataannya. */
    $anak = db_route_child_tables();
    if (isset($anak[$tabel])) {
        [$induk, $fk] = $anak[$tabel];
        $pid = db_route_col_value($sql, $fk, $params);
        if ($pid !== null && $pid > 0) {
            $b = db_route_branch_of_id($induk, $pid);
            if ($b !== null && $b > 0) return $b;
        }
    }
    /* Tidak dapat ditentukan dari pernyataan.
       · UPDATE/DELETE oleh akun LINTAS CABANG → 0 = "semua cabang" (operasi massal
         seperti menu "Hapus Semua Data" memang harus mengenai seluruh cabang;
         subquery seperti `... WHERE patient_id IN (SELECT id FROM patients)` tidak
         menyebut nilai cabang sehingga tidak dapat dipetakan ke satu berkas).
       · Selain itu (INSERT/REPLACE, atau akun yang dipin satu cabang) → cabang
         bawaan supaya perilakunya tetap dapat diprediksi. */
    $kata = db_route_sql_verb($sql);
    if ($kata === 'UPDATE' || $kata === 'DELETE') return 0;
    return db_route_default_branch();
}

/**
 * Nilai integer sebuah kolom pada pernyataan (`col = ?` atau `col = 123`).
 *
 * Nomor parameter dihitung dari JUMLAH tanda tanya sebelum posisi nilai itu —
 * bukan diasumsikan parameter pertama (kalau salah, penulisan bisa mendarat di
 * berkas cabang yang salah).
 */
function db_route_sql_value_for(string $sql, string $kolom, array $params = []): ?int
{
    /* Menerima `kolom = ?`, `kolom = 12`, dan `kolom = '12'` (dump backup menulis
       nilai sebagai literal berkutip). */
    $pola = '/\b' . preg_quote($kolom, '/') . "\\s*=\\s*(\\?|'?-?\\d+'?|-?\\d+)/i";
    if (!preg_match($pola, $sql, $m, PREG_OFFSET_CAPTURE)) return null;
    return db_route_placeholder_value($sql, (string)$m[1][0], (int)$m[1][1], $params);
}

/**
 * Nilai sebuah kolom pada pernyataan — baik bentuk INSERT (daftar kolom + VALUES)
 * maupun bentuk penugasan (`kolom = ?` / `kolom = 123`).
 *
 * PENTING: tabel anak (order_items, payments, …) sering ditulis dalam bentuk INSERT
 * dengan daftar kolom. Tanpa mengenali bentuk itu, cabangnya jatuh ke cabang bawaan
 * dan barisnya mendarat di berkas cabang yang SALAH (dulu menyebabkan
 * "FOREIGN KEY constraint failed" pada payments cabang 2).
 */
function db_route_col_value(string $sql, string $kolom, array $params = []): ?int
{
    if (preg_match('/INSERT\s+(?:OR\s+\w+\s+)?INTO\s+[`"\[]?(\w+)[`"\]]?\s*\(([^)]*)\)\s*VALUES\s*\(/is', $sql, $m, PREG_OFFSET_CAPTURE)) {
        $cols = array_map(fn($c) => strtolower(trim($c, " `\"\t\n")), explode(',', $m[2][0]));
        $idx = array_search(strtolower($kolom), $cols, true);
        if ($idx !== false) {
            $mulaiNilai = (int)$m[0][1] + strlen($m[0][0]);
            $nilai = db_route_split_values(substr($sql, $mulaiNilai));
            if (isset($nilai[$idx])) {
                [$teks, $offsetRelatif] = $nilai[$idx];
                $v = db_route_placeholder_value($sql, trim($teks), $mulaiNilai + $offsetRelatif, $params);
                if ($v !== null) return $v;
            }
        }
    }
    return db_route_sql_value_for($sql, $kolom, $params);
}

/** Ambil nilai branch_id dari pernyataan (INSERT … VALUES / UPDATE … SET / WHERE). */
function db_route_branch_from_sql(string $sql, array $params = []): ?int
{
    return db_route_col_value($sql, 'branch_id', $params);
}

/**
 * Nilai sebuah nilai SQL: placeholder (`?` → dari $params), angka, atau ANGKA
 * BERKUTIP (`'1'` / `"1"`).
 *
 * Penting: dump backup menulis nilai sebagai literal berkutip (mis.
 * `... ,'1','active')`), sehingga tanpa mengenali bentuk ini setiap baris pemulihan
 * dianggap "tidak punya cabang" lalu semuanya mendarat di satu berkas cabang —
 * pemulihan tampak berhasil padahal datanya bertumpuk di satu cabang.
 */
function db_route_placeholder_value(string $sql, string $teks, int $posisi, array $params): ?int
{
    $teks = trim($teks);
    /* Buang kutip pembungkus (literal SQL). */
    if (strlen($teks) >= 2
        && ($teks[0] === "'" || $teks[0] === '"')
        && substr($teks, -1) === $teks[0]) {
        $teks = trim(substr($teks, 1, -1));
    }
    if ($teks !== '' && preg_match('/^-?\d+$/', $teks)) {
        $n = (int)$teks;
        return $n > 0 ? $n : null;
    }
    if ($teks === '?' || $teks === '') {
        /* Nomor parameter = jumlah tanda tanya SEBELUM posisi ini. */
        $idx = substr_count(substr($sql, 0, $posisi), '?');
        $v = $params[$idx] ?? null;
        if ($v === null) return null;
        $n = (int)$v;
        return $n > 0 ? $n : null;
    }
    return null;
}

/**
 * Pecah daftar nilai pada satu tuple `(a, b, c)` menjadi [ [teks, offset], … ]
 * dengan memperhatikan tanda kurung & kutip.
 */
function db_route_split_values(string $s): array
{
    $keluar = [];
    $buf = '';
    $offset = 0;
    $mulai = 0;
    $dalam = 0;
    $kutip = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($kutip !== '') {
            if ($c === $kutip) $kutip = '';
            $buf .= $c;
            continue;
        }
        if ($c === "'" || $c === '"') { $kutip = $c; $buf .= $c; continue; }
        if ($c === '(') { $dalam++; }
        elseif ($c === ')') {
            if ($dalam === 0) {          // akhir tuple
                $keluar[] = [$buf, $mulai];
                return $keluar;
            }
            $dalam--;
        }
        if ($c === ',' && $dalam === 0) {
            $keluar[] = [$buf, $mulai];
            $buf = '';
            $i++;
            while ($i < $len && preg_match('/\s/', $s[$i])) $i++;   // ctype_space() tidak tersedia di build ini
            $mulai = $i;
            $i--;
            continue;
        }
        $buf .= $c;
    }
    $keluar[] = [$buf, $mulai];
    return $keluar;
}

/** Berkas cabang mana yang memiliki baris `$tabel.id = $id`? */
function db_route_branch_of_id(string $tabel, int $id): ?int
{
    if ($id <= 0) return null;
    $cacheKey = $tabel . ':' . $id;
    static $cache = [];
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    $hasil = null;
    foreach (db_route_attached() as $bid => $alias) {
        try {
            $ada = (int)db_route_scalar_raw('SELECT COUNT(*) FROM ' . $alias . '.' . $tabel . ' WHERE id = ?', [$id]);
        } catch (Throwable $e) {
            continue;
        }
        if ($ada > 0) { $hasil = (int)$bid; break; }
    }
    $cache[$cacheKey] = $hasil;
    return $hasil;
}

/** Query langsung pada koneksi routed (tanpa dispatcher) — untuk pemeriksaan internal. */
function db_route_scalar_raw(string $sql, array $params = [])
{
    static $pdo = null;
    if (!$pdo) $pdo = db_route_conn();
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}
/** @return array<int,array> */
function db_route_all_raw(string $sql, array $params = []): array
{
    static $pdo = null;
    if (!$pdo) $pdo = db_route_conn();
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/* ------------------------------------------------------------------ *
 * DDL UNTUK BERKAS CABANG: TANPA FOREIGN KEY LINTAS BERKAS
 * ------------------------------------------------------------------ */

/**
 * SQLite TIDAK mendukung foreign key antar berkas: klausa `REFERENCES branches(id)`
 * pada tabel cabang akan MENOLAK setiap insert (induknya hidup di central).
 * Karena itu klausa FK yang menunjuk tabel GLOBAL dibuang dari DDL berkas cabang;
 * FK yang menunjuk tabel cabang tetap dipertahankan (orders↔patients, order_items↔orders, …).
 */
function db_route_strip_cross_fk(string $ddl): string
{
    if (stripos($ddl, 'REFERENCES') === false) return $ddl;
    $buka = strpos($ddl, '(');
    $tutup = strrpos($ddl, ')');
    if ($buka === false || $tutup === false || $tutup <= $buka) return $ddl;
    $kepala = substr($ddl, 0, $buka + 1);
    $isi = substr($ddl, $buka + 1, $tutup - $buka - 1);
    $ekor = substr($ddl, $tutup);
    $bagian = db_route_split_ddl_items($isi);
    $sisa = [];
    foreach ($bagian as $item) {
        $bersih = $item;
        /* a) klausa FOREIGN KEY (...) REFERENCES global(...) → buang seluruh klausa */
        if (preg_match('/^\s*FOREIGN\s+KEY\b/i', $item)) {
            if (preg_match('/REFERENCES\s+[`"\[]?(\w+)/i', $item, $m)
                && db_route_scope_of($m[1]) === 'global') continue;
            $sisa[] = $bersih;
            continue;
        }
        /* b) kolom dengan REFERENCES global → buang hanya klausa REFERENCES-nya */
        if (preg_match('/REFERENCES\s+[`"\[]?(\w+)/i', $item, $m) && db_route_scope_of($m[1]) === 'global') {
            $bersih = preg_replace('/\s*REFERENCES\s+[`"\[]?\w+[`"\]]?\s*(\([^)]*\))?(\s+ON\s+(DELETE|UPDATE)\s+[A-Z ]+)*/i', '', $item);
        }
        $sisa[] = $bersih;
    }
    return $kepala . implode(',', $sisa) . $ekor;
}

/** Pecah isi daftar kolom DDL pada koma tingkat atas. */
function db_route_split_ddl_items(string $isi): array
{
    $keluar = [];
    $buf = '';
    $dalam = 0;
    $kutip = '';
    $len = strlen($isi);
    for ($i = 0; $i < $len; $i++) {
        $c = $isi[$i];
        if ($kutip !== '') {
            if ($c === $kutip) $kutip = '';
            $buf .= $c;
            continue;
        }
        if ($c === "'" || $c === '"') { $kutip = $c; $buf .= $c; continue; }
        if ($c === '(') $dalam++;
        if ($c === ')') $dalam--;
        if ($c === ',' && $dalam === 0) { $keluar[] = $buf; $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $keluar[] = $buf;
    return $keluar;
}

/* ------------------------------------------------------------------ *
 * LAPORAN KEADAAN (untuk Developer Settings & audit)
 * ------------------------------------------------------------------ */

/** Ringkasan keadaan routing basis data (dipakai UI & uji). */
function db_route_report(): array
{
    try {
        return db_route_report_raw();
    } catch (Throwable $e) {
        return ['central' => db_central_path(), 'central_ada' => is_file(db_central_path()),
            'cabang' => [], 'max_attach' => DB_ROUTE_MAX_ATTACH,
            'global_tables' => count(db_route_global_tables()),
            'branch_tables' => count(db_route_branch_tables()),
            'terlewat' => [], 'error' => 'gagal membaca keadaan basis data: ' . $e->getMessage()];
    }
}

function db_route_report_raw(): array
{
    $out = [
        'central' => db_central_path(),
        'central_ada' => is_file(db_central_path()),
        'cabang' => [],
        'max_attach' => DB_ROUTE_MAX_ATTACH,
        'global_tables' => count(db_route_global_tables()),
        'branch_tables' => count(db_route_branch_tables()),
        'terlewat' => [],
        'error' => $GLOBALS['DB_ROUTE_LAST_ERROR'] ?? '',
    ];
    foreach (db_route_branch_ids() as $bid) {
        $p = db_branch_path((int)$bid);
        $out['cabang'][] = ['id' => (int)$bid, 'berkas' => basename($p), 'ada' => is_file($p),
            'ukuran' => is_file($p) ? (int)filesize($p) : 0];
    }
    $out['terlewat'] = db_route_attach_skipped();
    $out['cabang_terpasang'] = array_keys(db_route_attached());
    $scope = db_route_read_scope();
    $out['cakupan_baca'] = $scope === null ? 'semua cabang' : 'cabang ' . $scope;
    return $out;
}
