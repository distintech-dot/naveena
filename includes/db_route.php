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
        'ai_tasks', 'ai_task_files', 'ai_usage_log', 'ai_steps', 'ai_messages', 'ai_traces', 'ai_jobs'];
}

/** Tabel OPERASIONAL: hidup di basis data masing-masing CABANG. */
function db_route_branch_tables(): array
{
    return ['patients', 'medical_records', 'medical_record_photos', 'appointments',
        'appointment_treatments', 'orders', 'order_items', 'payments',
        'inventory', 'inventory_movements', 'treatments', 'skincare_products',
        'treatment_materials', 'suppliers', 'doctors', 'therapists', 'packages', 'package_items'];
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
        'medical_records' => ['patients', 'patient_id']];
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
        $b = function_exists('scope_branch') ? scope_branch() : null;
        /* Hanya id cabang POSITIF yang dianggap cakupan satu cabang. null berarti
           "semua cabang" (level owner); 0/-1 (belum ada akun aktif) juga diperlakukan
           sebagai semua cabang supaya tidak ada cabang yang luput. */
        $nilai = ($b !== null && (int)$b > 0) ? (int)$b : null;
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

    /* Mode periksa: jangan sentuh skema, tetapi tetap hitung kesiapan supaya
       pemanggil yang meminta skema nanti tidak menjalankan migrasi diam-diam. */
    if ($siapkanSkema && db_route_inspect()) {
        $siapkanSkema = false;
        $skemaSiap = true;
    }

    if (!$pdo instanceof PDO) {
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
    $prioritas = [];
    $scope = db_route_read_scope();
    $tulis = isset($GLOBALS['DB_ROUTE_WRITE_BRANCH']) ? (int)$GLOBALS['DB_ROUTE_WRITE_BRANCH'] : 0;
    $akun = function_exists('user_branch') ? (int)user_branch() : 0;
    foreach ([$scope, $tulis, $akun] as $p) {
        if ($p !== null && (int)$p > 0 && in_array((int)$p, $ids, true)) $prioritas[] = (int)$p;
    }
    $urutan = array_values(array_unique(array_merge($prioritas, $ids)));
    $terpasang = [];
    foreach ($urutan as $i => $bid) {
        if ($i >= DB_ROUTE_MAX_ATTACH) break;
        $file = db_branch_path((int)$bid);
        if (!is_file($file)) db_branch_create((int)$bid);          // cabang baru → otomatis dibuat
        if (!is_file($file)) continue;
        $alias = 'b' . (int)$bid;
        try {
            $pdo->exec('ATTACH DATABASE ' . $pdo->quote($file) . ' AS ' . $alias);
        } catch (Throwable $e) {
            continue;
        }
        db_route_ensure_branch_schema($pdo, $alias, (int)$bid);     // skema + migrasi cabang
        $terpasang[(int)$bid] = $alias;
    }
    $GLOBALS['DB_ROUTE_ATTACHED'] = $terpasang;
    $GLOBALS['DB_ROUTE_ATTACH_SKIPPED'] = array_values(array_diff($ids, array_keys($terpasang)));
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
    if (!isset($GLOBALS['DB_ROUTE_ATTACHED']) || !is_array($GLOBALS['DB_ROUTE_ATTACHED'])) {
        db_route_conn();
    }
    return $GLOBALS['DB_ROUTE_ATTACHED'] ?? [];
}

/** Cabang yang TIDAK kebagian slot ATTACH (dilaporkan, bukan disembunyikan). */
function db_route_attach_skipped(): array
{
    return $GLOBALS['DB_ROUTE_ATTACH_SKIPPED'] ?? [];
}

/** Daftar id cabang (dibaca langsung dari central, tanpa lewat dispatcher). */
function db_route_branch_ids(): array
{
    static $ids = null;
    if ($ids !== null) return $ids;
    $rows = db_route_raw_central_many('SELECT id FROM branches ORDER BY id');
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    if (!$ids) $ids = [1];
    return $ids;
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
           `backups.auto_run` → backup gagal dibuat di produksi). */
        if (!schema_is_current($c)) {
            $GLOBALS['DB_SCHEMA_BRANCH_ID'] = $branchId;
            try { db_schema_apply($path, 'branch'); } finally { unset($GLOBALS['DB_SCHEMA_BRANCH_ID']); }
        }
        /* Migrasi bisa membuat tabel dengan FK ke tabel global → dibuat ulang. */
        db_branch_strip_cross_fk_tables($branchId);
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
function db_route_install_views(PDO $pdo): void
{
    $attached = db_route_attached();
    $scope = db_route_read_scope();
    /* Setiap "lengan" view DISARING ke cabang pemilik berkasnya, sehingga baris
       milik cabang lain TIDAK PERNAH terlihat — walau berkas cabangnya memuat
       baris sisa (mis. sisa data contoh saat berkas dibuat). Inilah jaminan
       ISOLASI BACA yang bersifat struktural, bukan bergantung pada filter
       `branch_id` di masing-masing query. */
    $lengan = function (string $alias, int $bid, string $t, bool $ikutTanpaCabang) use ($pdo): string {
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
                . ' p WHERE p.id = c.' . $fk . ' AND (p.branch_id = ' . (int)$bid . $tanpa . '))';
        }
        return 'SELECT * FROM ' . $pdo->quote($alias) . '.' . $t
            . ' WHERE branch_id = ' . (int)$bid . $tanpa;
    };
    foreach (db_route_branch_tables() as $t) {
        $pdo->exec('DROP VIEW IF EXISTS temp.' . $t);
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
        if (!$bagian) continue;
        $pdo->exec('CREATE TEMP VIEW ' . $t . ' AS ' . implode(' UNION ALL ', $bagian));
    }
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
    [$tabel, $mulai, $panjang, $kutip] = $tulis;
    if (db_route_scope_of($tabel) !== 'branch') return [$sql];
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
        $lintas = in_array(strtoupper(substr(ltrim($sql), 0, 6)), ['UPDATE', 'DELETE'], true);
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
            $GLOBALS['DB_ROUTE_LAST_ERROR'] = 'Cabang ' . $branch . ' untuk tabel ' . $tabel
                . ' tidak ter-ATTACH (batas ' . DB_ROUTE_MAX_ATTACH . ' berkas cabang) — penulisan dibatalkan.';
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
       tidak mungkin "mengubah 0 baris" karena salah berkas). */
    if (preg_match('/^\s*(UPDATE|DELETE\s+FROM)\b/i', $sql)) {
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
    $kata = strtoupper(substr(ltrim($sql), 0, 6));
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
