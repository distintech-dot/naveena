<?php
/**
 * MANAJER BASIS DATA: CENTRAL + SQLITE PER CABANG (ronde 54)
 * ========================================================
 * Permintaan pemilik (PDF Master Upgrade bagian 3): aplikasi harus dapat memakai
 * `central.sqlite` + satu `branch_XXX.sqlite` per cabang, dengan pembuatan basis data
 * cabang OTOMATIS saat cabang baru dibuat (schema + migrasi + PRAGMA + integrity/FK +
 * registrasi + health check).
 *
 * PRINSIP AMAN (penting):
 *   1. `data.sqlite` yang ADA tetap SOURCE OF TRUTH. Mode bawaan tetap `legacy`
 *      (satu basis data) sehingga aplikasi produksi TIDAK berubah perilakunya sampai
 *      pemilik menekan TERAPKAN dan mengaktifkan mode central/branch.
 *   2. Berkas baru dibuat di folder terpisah (`storage/databases/...`) — tidak ada
 *      pemindahan/penghapusan data yang dilakukan otomatis.
 *   3. Seluruh fungsi di sini bersifat idempoten & aman diulang.
 */
require_once __DIR__ . '/config.php';

/** Mode arsitektur basis data: `legacy` (bawaan) atau `central_branch`. */
function db_arch_mode(): string
{
    /* Arsitektur yang berlaku sekarang: central.sqlite (data global) + satu basis data
       per cabang (data operasional). Tidak ada lagi mode "legacy" satu berkas —
       fungsi ini dipertahankan supaya pemeriksaan/ekspor lama tetap membaca nilai
       yang benar. */
    return 'central_branch';
}

/** Akar folder penyimpanan basis data baru (di LUAR folder aplikasi yang disajikan). */
function db_store_root(): string
{
    $env = getenv('NAVEENA_DB_ROOT');
    if ($env) {
        if (!is_dir($env)) @mkdir($env, 0770, true);
        return rtrim($env, '/');
    }
    /* PENGAMAN (lihat nv_isolated_root): proses uji/CLI yang mengalihkan NAVEENA_DB
       TIDAK boleh memakai folder penyimpanan aplikasi terbit — tanpa ini pekerjaan uji
       menulis ke basis data PRODUKSI. */
    $iso = nv_isolated_root();
    if ($iso !== '') {
        foreach ([$iso, $iso . '/databases', $iso . '/databases/branches'] as $d) {
            if (!is_dir($d)) @mkdir($d, 0770, true);
        }
        return $iso;
    }
    $base = dirname(APP_DIR) . '/naveena_storage';
    foreach ([$base, $base . '/databases', $base . '/databases/branches'] as $d) {
        if (!is_dir($d)) @mkdir($d, 0770, true);
    }
    return $base;
}

/** Path central.sqlite. */
function db_central_path(): string { return db_store_root() . '/databases/central.sqlite'; }

/**
 * LANTAI ID PER CABANG.
 *
 * Pada arsitektur satu basis data per cabang, kolom id dihasilkan sendiri-sendiri
 * oleh tiap berkas — jadi id cabang 1 bisa bertabrakan dengan id cabang 2. Untuk
 * menghindari itu, setiap berkas cabang diberi "lantai id": cabang ke-N memakai id
 * mulai dari N × 1.000.000 (cabang 1 → 1.000.000; cabang 2 → 2.000.000; …).
 *
 * Kenapa penting: view gabungan (cakupan semua cabang) dan pencarian `WHERE id = ?`
 * akan menemukan baris yang SALAH bila dua cabang memiliki id yang sama.
 * Nomor dokumen yang dilihat pengguna (DP-…, OR-…) TIDAK terpengaruh.
 */
function db_branch_id_floor(int $branchId): int
{
    return max(1, $branchId) * 1000000;
}

/**
 * Naikkan urutan id sebuah berkas cabang sampai lantai id-nya (tidak pernah
 * menurunkan dan tidak pernah menyentuh baris yang sudah ada).
 *
 * @return array{tabel:int,dinaikkan:array<string,int>}
 */
function db_branch_apply_id_floor(int $branchId): array
{
    $path = db_branch_path($branchId);
    $out = ['tabel' => 0, 'dinaikkan' => [], 'gagal' => [], 'error' => ''];
    if (!is_file($path)) return $out;
    try {
        $pdo = db_open($path);
        return db_branch_apply_id_floor_pdo($pdo, $branchId);
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
        return $out;
    }
}

/**
 * Lantai id pada KONEKSI tertentu — dipakai saat SKEMA CABANG BARU sedang dibuat,
 * yaitu SEBELUM data contoh ditanam. Tanpa urutan ini, id data contoh (treatment,
 * skincare, bahan, dokter) dimulai dari 1 di SETIAP cabang sehingga bertabrakan
 * antar cabang (dulu menyebabkan "bukan milik cabang ini" pada Order Baru).
 */
function db_branch_apply_id_floor_pdo(PDO $pdo, int $branchId): array
{
    $out = ['tabel' => 0, 'dinaikkan' => [], 'gagal' => [], 'error' => ''];
    try {
        $floor = db_branch_id_floor($branchId);
        $tabel = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='table'
                              AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC);
        $adaSeq = (bool)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='sqlite_sequence'")->fetchColumn();
        $tabel = $tabel ?: [];
        foreach ($tabel as $t) {
            if (stripos((string)$t['sql'], 'AUTOINCREMENT') === false) continue;
            $out['tabel']++;
            $nama = (string)$t['name'];
            if (!$adaSeq) { $out['dinaikkan'][$nama] = $floor; continue; }   // belum ada baris apa pun
            try {
                /* sqlite_sequence TIDAK punya indeks unik pada `name`, jadi upsert
                   `ON CONFLICT(name)` tidak sah — dipakai UPDATE lalu INSERT. */
                $st = $pdo->prepare('UPDATE sqlite_sequence SET seq = MAX(seq, ?) WHERE name = ?');
                $st->execute([$floor, $nama]);
                if ($st->rowCount() === 0) {
                    $pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)')->execute([$nama, $floor]);
                }
                $out['dinaikkan'][$nama] = $floor;
            } catch (Throwable $e) {
                $out['gagal'][$nama] = $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        /* Kegagalan pengaturan lantai id dilaporkan lewat hasil (bukan mematikan aplikasi). */
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/** Path basis data sebuah cabang. */
function db_branch_path(int $branchId): string
{
    return db_store_root() . '/databases/branches/branch_' . str_pad((string)max(0, $branchId), 3, '0', STR_PAD_LEFT) . '.sqlite';
}

/**
 * Buka koneksi SQLite dengan PRAGMA yang benar (busy_timeout, FK, WAL, synchronous).
 * Dipakai untuk basis data BARU (central/branch) — `db()` tetap untuk `data.sqlite`.
 */
function db_open(string $path): PDO
{
    $fresh = !file_exists($path);
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    if ($fresh) @chmod($path, 0664);
    return $pdo;
}

/**
 * TABEL REGISTRY (selalu ada di basis data yang sedang dipakai) — mencatat basis data
 * central & tiap cabang beserta keadaan kesehatannya.
 */
/**
 * Catat satu baris riwayat migrasi pada basis data CENTRAL (langsung, tanpa
 * dispatcher). Dipakai db_schema_apply() yang juga berjalan saat bootstrap.
 */
function db_registry_log_migration(string $path, string $note = ''): void
{
    try {
        $p = db_central_path();
        if (!is_file($p)) return;
        $pdo = db_open($p);
        $versi = '';
        try { $versi = (string)$pdo->query("SELECT value FROM settings WHERE key='schema_version'")->fetchColumn(); } catch (Throwable $e) {}
        $st = $pdo->prepare('INSERT INTO db_migrations (db_path, version, note) VALUES (?,?,?)');
        $st->execute([$path, $versi !== '' ? $versi : SCHEMA_VERSION, $note]);
    } catch (Throwable $e) {
        /* riwayat migrasi bersifat informatif — jangan sampai menggagalkan aplikasi */
    }
}

/**
 * Koneksi LANGSUNG ke central.sqlite, TANPA lewat dispatcher `db()`.
 *
 * Penting: dipakai saat bootstrap (membuat/memeriksa basis data) supaya tidak
 * terjadi rekursi — `db()` memanggil db_route_conn(), yang pada gilirannya
 * memastikan berkas cabang ada lewat db_branch_create().
 */
function db_central_conn(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = db_open(db_central_path());
    return $pdo;
}

/** Pastikan tabel riwayat migrasi ada pada CENTRAL (langsung). */
function db_central_registry_ready(): void
{
    try { db_registry_ensure(db_central_conn()); } catch (Throwable $e) { /* informatif saja */ }
}

function db_registry_ensure(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS db_registry (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL,
        branch_id INTEGER,
        path TEXT,
        schema_version TEXT,
        size INTEGER DEFAULT 0,
        status TEXT DEFAULT 'ACTIVE',
        health TEXT,
        last_check TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_db_registry_path ON db_registry(path)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS db_migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        db_path TEXT,
        version TEXT,
        note TEXT,
        applied_at TEXT DEFAULT (datetime('now','localtime'))
    )");
}

/** Catat/perbarui satu basis data pada registry. */
function db_registry_put(PDO $pdo, string $kind, ?int $branchId, string $path, string $status = 'ACTIVE', string $health = ''): void
{
    db_registry_ensure($pdo);
    $ver = (string)setting('schema_version', SCHEMA_VERSION);
    $size = is_file($path) ? (int)filesize($path) : 0;
    /* PENTING: baris registry ditulis pada KONEKSI yang diberikan (bukan lewat
       dispatcher q()), supaya selalu mendarat di basis data yang dimaksud —
       dulu memakai q() sehingga barisnya bisa tertulis ke basis data lain. */
    $st = $pdo->prepare('INSERT INTO db_registry (kind, branch_id, path, schema_version, size, status, health, last_check)
       VALUES (?,?,?,?,?,?,?,datetime("now","localtime"))
       ON CONFLICT(path) DO UPDATE SET kind=excluded.kind, branch_id=excluded.branch_id,
         schema_version=excluded.schema_version, size=excluded.size, status=excluded.status,
         health=excluded.health, last_check=datetime("now","localtime")');
    $st->execute([$kind, $branchId, $path, $ver, $size, $status, $health]);
}

/**
 * Daftar basis data terdaftar — SELALU dibaca dari central (langsung).
 *
 * Registry & riwayat migrasi adalah catatan tingkat sistem: ditulis ke central
 * dan dibaca dari central, sehingga tetap konsisten baik pengalihan routing
 * sedang aktif maupun belum.
 */
function db_central_all(string $sql, array $params = []): array
{
    try {
        db_central_registry_ready();
        $st = db_central_conn()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function db_registry_list(): array
{
    try { db_central_registry_ready(); return db_central_all('SELECT * FROM db_registry ORDER BY kind, branch_id'); }
    catch (Throwable $e) { return []; }
}

/**
 * PEMERIKSAAN KESEHATAN satu basis data: ada/tidak, ukuran, jumlah tabel, jumlah baris
 * `patients` (bila ada), integritas (`PRAGMA integrity_check`) dan pelanggaran FK
 * (`PRAGMA foreign_key_check`).
 *
 * @return array{ok:bool,path:string,tables:int,patients:int,size:int,integrity:string,fk:int,error:string}
 */
/**
 * Bangun ULANG tabel berkas cabang yang masih memuat foreign key ke tabel GLOBAL.
 *
 * Mengapa perlu: `run_migrations()` membuat beberapa tabel dengan DDL langsung (bukan
 * lewat schema_ddl()), sehingga klausa `REFERENCES <tabel global>` ikut tertanam.
 * SQLite menolak SEMUA penulisan pada tabel seperti itu karena induknya hidup di
 * berkas lain ("FOREIGN KEY constraint failed") — jadi tabelnya dibuat ulang tanpa
 * klausa tersebut (data disalin apa adanya, kolomnya identik).
 *
 * @return array{tabel:int,dibangun:array<int,string>,error:string}
 */
function db_branch_strip_cross_fk_tables(int $branchId): array
{
    $out = ['tabel' => 0, 'dibangun' => [], 'error' => ''];
    $path = db_branch_path($branchId);
    if (!is_file($path)) return $out;
    try {
        $pdo = db_open($path);
        $daftar = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='table'
                              AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($daftar as $t) {
            $sql = (string)$t['sql'];
            $nama = (string)$t['name'];
            $out['tabel']++;
            if (!preg_match_all('/REFERENCES\s+[`"\[]?(\w+)/i', $sql, $mm)) continue;
            $adaGlobal = false;
            foreach ($mm[1] as $induk) {
                if (db_route_scope_of($induk) === 'global') { $adaGlobal = true; break; }
            }
            if (!$adaGlobal) continue;

            $baru = 'nav_build_' . $nama;
            $ddlBaru = db_route_strip_cross_fk(preg_replace(
                '/^\s*CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?[`"\[]?' . preg_quote($nama, '/') . '[`"\]]?/i',
                'CREATE TABLE ' . $baru, $sql, 1) ?? $sql);
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $pdo->exec('DROP TABLE IF EXISTS ' . $baru);
                $pdo->exec($ddlBaru);
                $kol = db_route_common_columns_local($pdo, $nama, $baru);
                if ($kol) {
                    $ins = '(' . implode(',', array_map(fn($c) => '"' . $c . '"', $kol)) . ')';
                    $sel = implode(',', array_map(fn($c) => '"' . $c . '"', $kol));
                    $pdo->exec('INSERT OR REPLACE INTO ' . $baru . ' ' . $ins . ' SELECT ' . $sel . ' FROM ' . $nama);
                }
                $pdo->exec('DROP TABLE ' . $nama);
                $pdo->exec('ALTER TABLE ' . $baru . ' RENAME TO ' . $nama);
                $pdo->exec('COMMIT');
                $out['dibangun'][] = $nama;
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK');
                $out['error'] = $nama . ': ' . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/** Kolom yang sama antara dua tabel dalam SATU berkas (urut menurut tabel tujuan). */
function db_route_common_columns_local(PDO $pdo, string $dari, string $ke): array
{
    try {
        $a = [];
        foreach ($pdo->query('PRAGMA table_info(' . $pdo->quote($dari) . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) $a[(string)$r['name']] = true;
        $keluar = [];
        foreach ($pdo->query('PRAGMA table_info(' . $pdo->quote($ke) . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (string)$r['name'];
            if (isset($a[$n])) $keluar[] = $n;
        }
        return $keluar;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Buang baris milik CABANG LAIN dari sebuah berkas cabang (sisa data contoh saat
 * berkas dibuat). View sudah menyaring, ini menjaga berkas tetap bersih & kecil.
 *
 * @return array{tabel:int,dihapus:int}
 */
function db_branch_purge_foreign_rows(int $branchId): array
{
    $out = ['tabel' => 0, 'dihapus' => 0];
    $path = db_branch_path($branchId);
    if (!is_file($path)) return $out;
    try {
        $pdo = db_open($path);
        $anak = db_route_child_tables();
        foreach (db_route_branch_tables() as $t) {
            if (isset($anak[$t])) continue;      // tabel anak ikut induknya
            try {
                $n = $pdo->exec('DELETE FROM "' . $t . '" WHERE branch_id IS NOT NULL AND branch_id <> ' . (int)$branchId);
                if ((int)$n > 0) { $out['tabel']++; $out['dihapus'] += (int)$n; }
            } catch (Throwable $e) { /* tabel tidak ada */ }
        }
        /* Tabel GLOBAL (pengguna, peran, izin, cabang, audit, dll.) TIDAK dipakai di
           berkas cabang — sumbernya central. Isinya dibersihkan supaya berkas cabang
           benar-benar hanya memuat data operasional. `settings` DIPERTAHANKAN karena
           menyimpan versi skema yang dipakai jalur cepat ensure_schema(). */
        foreach (db_route_global_tables() as $t) {
            if (in_array($t, ['settings'], true)) continue;
            try {
                $n = $pdo->exec('DELETE FROM "' . $t . '"');
                if ((int)$n > 0) { $out['tabel']++; $out['dihapus'] += (int)$n; }
            } catch (Throwable $e) { /* tabel tidak ada di berkas ini */ }
        }
    } catch (Throwable $e) { /* dilaporkan lewat hasil */ }
    return $out;
}

/** Apakah berkas ini berkas basis data CABANG (bukan central/legacy)? */
function db_health_is_branch_file(string $path): bool
{
    return strpos($path, '/databases/branches/') !== false || strpos($path, '/branches/') !== false;
}

function db_health(string $path): array
{
    $out = ['ok' => false, 'path' => $path, 'tables' => 0, 'patients' => 0, 'size' => 0,
        'integrity' => '', 'fk' => 0, 'error' => ''];
    if (!is_file($path)) { $out['error'] = 'berkas basis data tidak ditemukan'; return $out; }
    $out['size'] = (int)filesize($path);
    try {
        $pdo = db_open($path);
        $out['tables'] = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
        $ic = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        $out['integrity'] = (string)$ic;
        /* Pada berkas CABANG, foreign key ke tabel GLOBAL tidak mungkin ditegakkan
           (SQLite tidak mendukung FK antar berkas) → pelanggaran seperti itu
           dilaporkan TERPISAH, bukan sebagai kerusakan. Pada berkas CENTRAL seluruh
           induknya ada di berkas yang sama, jadi tidak ada yang dikecualikan. */
        $semuaFk = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
        $lintas = 0;
        if (db_health_is_branch_file($path)) {
            foreach ($semuaFk as $f) {
                if (isset($f['parent']) && db_route_scope_of((string)$f['parent']) === 'global') $lintas++;
            }
        }
        $out['fk_lintas_berkas'] = $lintas;
        $out['fk'] = count($semuaFk) - $lintas;
        try { $out['patients'] = (int)$pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn(); } catch (Throwable $e) { /* tabel belum ada */ }
        $out['ok'] = ($out['integrity'] === 'ok' && $out['fk'] === 0);
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/**
 * SIAPKAN SKEMA pada basis data baru (central atau branch).
 *
 * Cara aman: memakai `ensure_schema()` yang SAMA dengan aplikasi (satu sumber definisi
 * skema, dijalankan dalam satu transaksi) — bukan menyalin DDL secara manual yang bisa
 * menyimpang. Setelah itu dijalankan migrasi, PRAGMA, integrity & FK check.
 *
 * @return array{ok:bool,error:string,tables:int,integrity:string,fk:int}
 */
function db_schema_apply(string $path, string $scope = 'central'): array
{
    try {
        $pdo = db_open($path);
        /* ensure_schema() menerima PDO sebagai parameter dan membuat seluruh tabel +
           menjalankan migrasi (idempoten, satu transaksi). `$scope = 'branch'`
           memakai DDL berkas cabang (FK lintas berkas dibuang, tanpa seed contoh). */
        db_schema_with_scope($scope, function () use ($pdo) { ensure_schema($pdo); });
        $tables = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
        $ic = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
        $semuaFk = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
        $fk = 0;
        foreach ($semuaFk as $f) {
            if (isset($f['parent']) && db_route_scope_of((string)$f['parent']) === 'global') continue;
            $fk++;
        }
        /* Catat migrasi yang diterapkan pada basis data ini (riwayat versi).
           Pencatatan dilakukan pada CENTRAL secara langsung supaya tidak
           bergantung pada pengalihan routing saat bootstrap. */
        db_central_registry_ready();
        db_registry_log_migration($path, 'skema diterapkan otomatis (db_schema_apply) · scope=' . $scope);
        return ['ok' => ($ic === 'ok' && $fk === 0), 'error' => '', 'tables' => $tables,
            'integrity' => $ic, 'fk' => $fk];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'tables' => 0, 'integrity' => '', 'fk' => 0];
    }
}

/**
 * BUAT BASIS DATA CENTRAL (idempoten).
 * @return array{ok:bool,path:string,error:string,laporan:array}
 */
function db_central_create(): array
{
    $path = db_central_path();
    $baru = !is_file($path);
    $r = db_schema_apply($path, 'central');
    db_central_registry_ready();
    db_registry_put(db_central_conn(), 'central', null, $path, $r['ok'] ? 'ACTIVE' : 'ERROR',
        $r['ok'] ? 'integritas ok' : $r['error']);
    return ['ok' => $r['ok'], 'path' => $path, 'error' => $r['error'],
        'baru' => $baru, 'laporan' => $r];
}

/**
 * BUAT BASIS DATA SEBUAH CABANG selengkap alurnya (idempoten).
 *
 * Alur sesuai spesifikasi: create file → apply schema terbaru → apply migrations →
 * PRAGMA configuration → integrity/FK check → register DB → health check → ACTIVE.
 *
 * @return array{ok:bool,path:string,branch_id:int,error:string,langkah:array<int,string>}
 */
function db_branch_create(int $branchId, string $branchCode = ''): array
{
    $langkah = [];
    $branchId = max(0, $branchId);
    if ($branchId <= 0) return ['ok' => false, 'path' => '', 'branch_id' => 0,
        'error' => 'id cabang tidak sah', 'langkah' => []];
    $path = db_branch_path($branchId);
    $baru = !is_file($path);
    $langkah[] = ($baru ? 'membuat' : 'menggunakan') . ' berkas ' . basename($path);
    /* 1) skema + migrasi + PRAGMA (DDL khusus berkas CABANG: FK lintas berkas dibuang) */
    $GLOBALS['DB_SCHEMA_BRANCH_ID'] = $branchId;
    $r = db_schema_apply($path, 'branch');
    unset($GLOBALS['DB_SCHEMA_BRANCH_ID']);
    if (!$r['ok']) {
        db_central_registry_ready();
        db_registry_put(db_central_conn(), 'branch', $branchId, $path, 'ERROR', $r['error']);
        return ['ok' => false, 'path' => $path, 'branch_id' => $branchId, 'error' => $r['error'], 'langkah' => $langkah];
    }
    $langkah[] = 'skema & migrasi diterapkan (' . $r['tables'] . ' tabel)';
    /* Tabel dengan foreign key ke tabel GLOBAL dibuat ulang (kalau tidak, SELURUH
       penulisan aplikasi ke tabel itu ditolak oleh SQLite). */
    $fixFk = db_branch_strip_cross_fk_tables($branchId);
    if ($fixFk['dibangun']) $langkah[] = 'tabel FK lintas berkas diperbaiki: ' . implode(', ', $fixFk['dibangun']);
    if ($fixFk['error'] !== '') $langkah[] = 'PERINGATAN perbaikan tabel: ' . $fixFk['error'];
    /* Baris milik cabang lain (ikut tertanam saat data contoh dibuat) dibuang. */
    $purge = db_branch_purge_foreign_rows($branchId);
    if ($purge['dihapus'] > 0) $langkah[] = 'baris cabang lain dibuang: ' . $purge['dihapus'];
    /* Lantai id cabang: id tidak pernah bertabrakan antar berkas cabang. */
    $idf = db_branch_apply_id_floor($branchId);
    $langkah[] = 'lantai id ' . number_format(db_branch_id_floor($branchId), 0, ',', '.')
        . ' diterapkan pada ' . count($idf['dinaikkan']) . ' tabel';
    /* 2) health check */
    $h = db_health($path);
    $langkah[] = 'integritas ' . ($h['integrity'] !== '' ? $h['integrity'] : '-') . ' · pelanggaran FK ' . $h['fk'];
    /* 3) register + status */
    db_central_registry_ready();
    db_registry_put(db_central_conn(), 'branch', $branchId, $path, $h['ok'] ? 'ACTIVE' : 'CHECK',
        $h['ok'] ? 'sehat' : ('perlu diperiksa: ' . ($h['error'] !== '' ? $h['error'] : 'integritas/FK')));
    $langkah[] = 'didaftarkan & berstatus ' . ($h['ok'] ? 'ACTIVE' : 'CHECK');
    audit('Basis Data Cabang', 'Database', $branchId,
        ['path' => $path], ['ok' => $h['ok']],
        'Basis data cabang ' . ($branchCode !== '' ? $branchCode . ' ' : '') . ($baru ? 'dibuat' : 'diperiksa'));
    return ['ok' => $h['ok'], 'path' => $path, 'branch_id' => $branchId, 'error' => '', 'langkah' => $langkah];
}

/** Pemeriksaan kesehatan SELURUH basis data terdaftar. */
function db_health_all(): array
{
    $out = [];
    $central = db_central_path();
    if (is_file($central)) $out['central'] = db_health($central);
    foreach (branches() as $b) {
        $p = db_branch_path((int)$b['id']);
        if (is_file($p)) $out['branch_' . (int)$b['id']] = db_health($p);
        else $out['branch_' . (int)$b['id']] = ['ok' => false, 'path' => $p, 'error' => 'belum dibuat',
            'tables' => 0, 'patients' => 0, 'size' => 0, 'integrity' => '', 'fk' => 0];
    }
    return $out;
}

/**
 * Ringkasan status basis data untuk Developer Settings.
 * @return array{mode:string,central:array,branches:array<int,array>,jumlah_branch:int,perlu_dibuat:int}
 */
function db_status_summary(): array
{
    $central = ['path' => db_central_path(), 'ada' => is_file(db_central_path())];
    if ($central['ada']) $central = array_merge($central, db_health(db_central_path()));
    $branchesOut = [];
    $perlu = 0;
    foreach (branches() as $b) {
        $p = db_branch_path((int)$b['id']);
        $ada = is_file($p);
        if (!$ada) $perlu++;
        $branchesOut[] = array_merge(['id' => (int)$b['id'], 'code' => (string)$b['code'],
            'name' => (string)$b['name'], 'path' => $p, 'ada' => $ada],
            $ada ? db_health($p) : ['ok' => false, 'error' => 'belum dibuat', 'tables' => 0,
                'patients' => 0, 'size' => 0, 'integrity' => '', 'fk' => 0]);
    }
    return ['mode' => db_arch_mode(), 'central' => $central, 'branches' => $branchesOut,
        'jumlah_branch' => count($branchesOut), 'perlu_dibuat' => $perlu];
}

/* ------------------------------------------------------------------ *
 * KONEKSI PER CABANG (resolver) + PENJAGA ISOLASI
 * ------------------------------------------------------------------ */

/**
 * Koneksi basis data untuk sebuah cabang.
 *
 * Mode `legacy`: mengembalikan koneksi utama (`db()`) — perilaku aplikasi TIDAK berubah.
 * Mode `central_branch`: membuka (dan membuat bila perlu) basis data cabang.
 */
function db_for_branch(int $branchId): PDO
{
    static $cache = [];
    $branchId = max(0, $branchId);
    if (isset($cache[$branchId])) return $cache[$branchId];
    $path = db_branch_path($branchId);
    if (!is_file($path)) db_branch_create($branchId);
    $cache[$branchId] = db_open($path);
    return $cache[$branchId];
}

/**
 * Koneksi operasional untuk sebuah CABANG.
 *
 * Mode `legacy` (bawaan) mengembalikan koneksi utama, sehingga perilaku aplikasi TIDAK
 * berubah. Mode `central_branch` mengembalikan basis data cabang itu.
 *
 * Dipakai modul yang cabangnya sudah diketahui (mis. dari induk record) supaya cakupan
 * cabang EKSPLISIT dan dikenali alat audit isolasi (includes/db_audit.php).
 */
function dbo(?int $branchId = null): PDO
{
    if ($branchId === null || $branchId <= 0) return db();
    return db_for_branch($branchId);
}

/**
 * Tabel ANAK (tanpa kolom `branch_id`) → [tabel INDUK, kolom penghubung].
 * Dipakai untuk menentukan cabang sebuah record anak dari INDUK-nya.
 */
function db_child_parent_map(): array
{
    return [
        'order_items'            => ['orders', 'order_id'],
        'payments'               => ['orders', 'order_id'],
        'appointment_treatments' => ['appointments', 'appointment_id'],
        'medical_record_photos'  => ['medical_records', 'medical_record_id'],
        'package_items'          => ['packages', 'package_id'],
    ];
}

/** Tabel yang boleh dibaca lewat db_branch_of_record() (daftar putih, bukan input pengguna). */
function db_record_tables(): array
{
    return ['orders', 'patients', 'medical_records', 'appointments', 'treatments',
        'skincare_products', 'treatment_materials', 'packages', 'suppliers', 'doctors',
        'therapists', 'inventory_movements', 'users'];
}

/**
 * Cabang pemilik sebuah record.
 *
 * - tabel operasional (punya kolom `branch_id`) → dibaca langsung;
 * - tabel ANAK (order_items, package_items, …) → lewat INDUK-nya (db_child_parent_map());
 * - tabel / id yang tidak dikenal → null (berarti "tanpa cabang khusus").
 *
 * @return int|null
 */
function db_branch_of_record(string $table, int $id): ?int
{
    if ($id <= 0 || !in_array($table, db_record_tables(), true)) return null;
    $anak = db_child_parent_map();
    if (isset($anak[$table])) {
        [$induk, $fk] = $anak[$table];
        $r = one("SELECT p.branch_id FROM {$induk} p JOIN {$table} c ON c.{$fk} = p.id WHERE c.id = ? LIMIT 1", [$id]);
        return $r ? (int)$r['branch_id'] : null;
    }
    $r = one("SELECT branch_id FROM {$table} WHERE id = ? LIMIT 1", [$id]);
    return $r ? (int)$r['branch_id'] : null;
}

/**
 * Koneksi basis data untuk sebuah RECORD milik cabang.
 *
 * Inilah mekanisme resmi agar modul "anak" (mis. includes/package.php, includes/patient.php)
 * bekerja pada basis data cabang yang benar tanpa harus tahu menahu soal isu arsitektur:
 *
 *   $c = db_conn_for_record('patients', $patientId);
 *   $row = one_on($c, 'SELECT …');
 *
 * Sejak arsitektur ini berlaku SELALU (tidak ada lagi mode satu berkas), koneksi yang
 * dikembalikan adalah koneksi aplikasi (`db()`): `main` = central, seluruh berkas cabang
 * ter-ATTACH, dan tabel operasional disajikan lewat TEMP VIEW yang SUDAH dibatasi cakupan
 * cabang pemakai. Jadi isolasi cabang tetap berlaku tanpa koneksi tambahan.
 */
function db_conn_for_record(string $table, int $id): PDO
{
    return db();
}

/**
 * PENJAGA ISOLASI CABANG — dipakai modul/uji untuk memastikan sebuah query benar-benar
 * dibatasi pada cabang yang diizinkan (mencegah kebocoran data antar cabang).
 *
 * @return array{ok:bool,alasan:string}
 */
function db_scope_check(string $sql, ?int $branchId): array
{
    $sql = strtolower($sql);
    if ($branchId === null) return ['ok' => true, 'alasan' => 'cakupan semua cabang (level owner)'];
    /* Query lintas-branch yang disengaja (laporan owner) ditandai komentar khusus. */
    if (strpos($sql, '/* cross-branch */') !== false) return ['ok' => true, 'alasan' => 'cross-branch disengaja'];
    if (preg_match('/\bbranch_id\s*(=|in|\?)/', $sql)) return ['ok' => true, 'alasan' => 'dibatasi branch_id'];
    return ['ok' => false, 'alasan' => 'query tidak menyebut branch_id — berpotensi membaca data cabang lain'];
}

/* ------------------------------------------------------------------ *
 * PEMBUATAN BASIS DATA CABANG OTOMATIS
 * ------------------------------------------------------------------ */

/**
 * Pastikan SEMUA cabang memiliki basis data (dipanggil dari Developer Settings /
 * setelah menambah cabang). Hanya berjalan pada mode central_branch — pada mode legacy
 * fungsi ini tidak melakukan apa pun supaya produksi tidak tersentuh.
 *
 * @return array{ok:bool,dibuat:int,diperiksa:int,hasil:array<int,array>}
 */
function db_branches_ensure_all(): array
{
    $dibuat = 0; $hasil = [];
    foreach (branches() as $b) {
        $p = db_branch_path((int)$b['id']);
        $ada = is_file($p);
        $r = db_branch_create((int)$b['id'], (string)$b['code']);
        if (!$ada && $r['ok']) $dibuat++;
        $hasil[] = ['id' => (int)$b['id'], 'code' => (string)$b['code'], 'ok' => $r['ok'],
            'baru' => !$ada, 'langkah' => $r['langkah'], 'error' => $r['error']];
    }
    return ['ok' => true, 'dibuat' => $dibuat, 'diperiksa' => count($hasil), 'hasil' => $hasil];
}
