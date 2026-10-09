<?php
/**
 * MANAJER BASIS DATA: CENTRAL + SQLITE PER CABANG (ronde 54)
 * ========================================================
 * Permintaan pemilik (PDF Master Upgrade bagian 3): aplikasi harus dapat memakai
 * `central.sqlite` + satu `branch_XXX.sqlite` per cabang, dengan pembuatan basis data
 * cabang OTOMATIS saat cabang baru dibuat (schema + migrasi + PRAGMA + integrity/FK +
 * registrasi + health check).
 *
 * ARSITEKTUR YANG BERLAKU: central + satu basis data per cabang adalah SATU-SATUNYA
 * arsitektur. Berkas tunggal `data.sqlite` sudah DIHAPUS — tidak ada mode `legacy`
 * dan tidak ada jalur mundur.
 *
 * Prinsip:
 *   1. Data GLOBAL/sistem (pengguna, peran & izin, pengaturan, cabang, kamus ICD,
 *      biaya operasional, audit, backup, AI) hanya ada di `central.sqlite`.
 *   2. Data OPERASIONAL cabang hanya ada di `branch_XXX.sqlite` masing-masing.
 *   3. Seluruh fungsi di sini bersifat idempoten & aman diulang.
 */
require_once __DIR__ . '/config.php';

/**
 * Mode arsitektur basis data. Nilainya SELALU `central_branch`.
 *
 * Fungsi ini dipertahankan karena pemeriksaan kesehatan basis data, laporan, dan
 * uji otomatis membacanya — tetapi tidak ada lagi mode lain: berkas tunggal
 * `data.sqlite` sudah dihapus dan tidak ada jalur mundur.
 */
function db_arch_mode(): string
{
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
 * Dipakai saat menyiapkan skema basis data (central atau berkas cabang).
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
/**
 * Catat satu baris riwayat migrasi pada basis data CENTRAL (langsung).
 *
 * PENTING: `$version` boleh diberikan EKSPLISIT. Sebelumnya versinya selalu dibaca
 * dari `settings.schema_version` milik CENTRAL, sehingga riwayat untuk berkas CABANG
 * ikut memakai versi central — bisa salah ketika central dan berkas cabang berada pada
 * versi yang berbeda (mis. migrasi cabang mendahului central).
 *
 * @param string|null $version versi skema yang BARU SAJA diterapkan (null = versi central)
 * @param bool $perbaruiWaktu false = jangan sentuh `applied_at` bila barisnya sudah ada.
 *        Dipakai jalur "dipastikan sudah pada versi ini": tanpa ini, sekadar membuka
 *        halaman Backup akan menulis ulang waktu riwayat sehingga riwayat MIGRASI
 *        mencerminkan kapan halaman dibuka, bukan kapan migrasi terjadi.
 */
function db_registry_log_migration(string $path, string $note = '', ?string $version = null,
                                    bool $perbaruiWaktu = true): void
{
    try {
        $p = db_central_path();
        if (!is_file($p)) return;
        /* PENTING: memakai koneksi central yang sudah dibuka (statis) — dulu fungsi
           ini membuka koneksi PDO BARU setiap kali dipanggil, dan karena dipanggil
           dari jalur per-permintaan itu berarti satu koneksi + satu fsync tambahan
           pada setiap permintaan HTTP. */
        $pdo = db_central_conn();
        db_registry_ensure($pdo);
        if ($version === null || trim($version) === '') {
            $version = (string)scalar_schema_setting($pdo, 'schema_version');
        }
        $sql = 'INSERT INTO db_migrations (db_path, version, note) VALUES (?,?,?)'
            . ($perbaruiWaktu
                ? ' ON CONFLICT(db_path, version) DO UPDATE SET note = excluded.note,
                                                               applied_at = excluded.applied_at'
                : ' ON CONFLICT(db_path, version) DO NOTHING');
        $st = $pdo->prepare($sql);
        $st->execute([db_path_label($path), $version !== '' ? $version : SCHEMA_VERSION, $note]);
    } catch (Throwable $e) {
        /* riwayat migrasi bersifat informatif — jangan sampai menggagalkan aplikasi */
    }
}

/* ------------------------------------------------------------------ *
 * SINKRONISASI REGISTRY (db_registry) — SELALU DARI PEMERIKSAAN NYATA
 * ------------------------------------------------------------------ *
 * MASALAH YANG DIPERBAIKI (laporan pemilik): `db_registry` menampilkan versi skema
 * `1.35.2` padahal aplikasinya sudah `1.41.0`. Tiga sebabnya:
 *   1. `db_registry_put()` HANYA dipanggil saat basis data DIBUAT — jalur yang jauh
 *      lebih sering (migrasi pada basis data yang sudah ada) tidak pernah menulis
 *      registry sama sekali;
 *   2. versinya diambil dari `setting('schema_version')` (nilai setelan/cache), bukan
 *      dari berkas basis data yang diperiksa — untuk berkas CABANG versi sebenarnya
 *      ada di `PRAGMA user_version` (berkas cabang tidak punya tabel `settings`);
 *   3. status/hasil kesehatan hanya ditulis sekali saat pembuatan, jadi ukuran,
 *      keutuhan, dan status bisa BASI tanpa batas.
 *
 * Sekarang: satu fungsi sinkronisasi (`db_registry_sync()`) yang membaca VERSI NYATA,
 * ukuran, hasil `integrity_check` + `foreign_key_check` dari berkasnya, lalu menulis
 * status yang benar-benar tervalidasi. Status `MIGRATED` hanya diberikan bila skema
 * baru saja diterapkan **dan** validasinya LULUS.
 */

/** Status registry yang sah (dan artinya). */
function db_registry_statuses(): array
{
    return [
        'ACTIVE'   => 'berkas sehat & skema terkini',
        'MIGRATED' => 'skema baru diterapkan & validasi lolos',
        'CHECK'    => 'sehat, tetapi skema belum terkini',
        'ERROR'    => 'GAGAL divalidasi (integritas/relasi)',
        'MISSING'  => 'berkas basis data belum dibuat',
    ];
}

/** Apakah status registry menandakan basis data siap dipakai? */
function db_registry_status_ok(string $status): bool
{
    return in_array(strtoupper($status), ['ACTIVE', 'MIGRATED'], true);
}

/**
 * VERSI SKEMA NYATA sebuah basis data — dibaca dari berkasnya sendiri.
 *
 * • central → `settings.schema_version`;
 * • berkas cabang → `PRAGMA user_version` (kode) yang dibandingkan dengan kode yang
 *   diharapkan aplikasi sekarang; bila cocok, versinya = `SCHEMA_VERSION`. Bila TIDAK
 *   cocok, labelnya diambil dari riwayat migrasi terakhir basis data itu dan hasilnya
 *   ditandai `terkini = false` (jujur — bukan dianggap versi terkini).
 *
 * @return array{version:string,code:int,terkini:bool,sumber:string,error:string}
 */
function db_schema_version_of(string $path): array
{
    $out = ['version' => '', 'code' => 0, 'terkini' => false, 'sumber' => '', 'error' => ''];
    if (!is_file($path)) { $out['error'] = 'berkas basis data tidak ditemukan'; return $out; }
    try {
        $c = db_open($path);
        if (db_health_is_branch_file($path)) {
            $out['code'] = (int)$c->query('PRAGMA user_version')->fetchColumn();
            $out['sumber'] = 'PRAGMA user_version';
            $out['terkini'] = ($out['code'] === schema_version_code());
            $out['version'] = $out['terkini']
                ? SCHEMA_VERSION
                : db_schema_version_from_history($path);
        } else {
            $out['sumber'] = 'settings.schema_version';
            $v = (string)scalar_schema_setting($c, 'schema_version');
            $out['version'] = $v;
            $out['terkini'] = ($v === SCHEMA_VERSION);
            if (!$out['terkini'] && $v === '') $out['version'] = db_schema_version_from_history($path);
        }
        $c = null;
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/** Label versi terakhir yang TERCATAT di riwayat migrasi untuk basis data ini. */
function db_schema_version_from_history(string $path): string
{
    try {
        db_central_registry_ready();
        $st = db_central_conn()->prepare('SELECT version FROM db_migrations WHERE db_path = ?
                                          ORDER BY applied_at DESC, id DESC LIMIT 1');
        $st->execute([db_path_label($path)]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? '' : (string)$v;
    } catch (Throwable $e) {
        return '';
    }
}

/** Tulis satu baris registry pada CENTRAL (langsung, tanpa dispatcher). */
function db_registry_store(array $r): void
{
    try {
        $pdo = db_central_conn();
        db_registry_ensure($pdo);
        $st = $pdo->prepare('INSERT INTO db_registry (kind, branch_id, path, schema_version, size, status, health, last_check)
           VALUES (?,?,?,?,?,?,?,datetime("now","localtime"))
           ON CONFLICT(path) DO UPDATE SET kind=excluded.kind, branch_id=excluded.branch_id,
             schema_version=excluded.schema_version, size=excluded.size, status=excluded.status,
             health=excluded.health, last_check=datetime("now","localtime")');
        $st->execute([(string)$r['kind'], $r['branch_id'], (string)$r['path'], (string)$r['version'],
            (int)$r['size'], (string)$r['status'], (string)$r['health']]);
    } catch (Throwable $e) {
        /* registry bersifat informatif — jangan sampai menggagalkan aplikasi */
    }
}

/**
 * SINKRONKAN satu basis data pada registry dengan KONDISI NYATA-nya.
 *
 * @param string      $path
 * @param string|null $kind      'central' | 'branch' | null (ditebak dari jalur)
 * @param int|null    $branchId
 * @param array       $opts      health   : hasil db_health() yang SUDAH dihitung (hemat)
 *                               migrated : true bila skema baru saja diterapkan sekarang
 *                               note     : catatan tambahan pada keterangan
 * @return array ringkasan hasil sinkronisasi
 */
function db_registry_sync(string $path, ?string $kind = null, ?int $branchId = null, array $opts = []): array
{
    if ($kind === null) $kind = db_health_is_branch_file($path) ? 'branch' : 'central';
    /* Id cabang DITENTUKAN dari nama berkas bila tidak diberikan atau tidak masuk akal
       (<= 0). Tanpa normalisasi ini, migrasi yang dipicu tanpa konteks cabang menulis
       `branch_id = 0` sehingga registry menampilkan "branch#0" untuk berkas cabang.
       Pemanggil seperti `db_schema_apply()` boleh mengirim 0/null — hasilnya tetap benar. */
    if (($branchId === null || $branchId <= 0) && $kind === 'branch'
        && preg_match('/branch_(\d+)\.sqlite$/', $path, $m)) {
        $branchId = (int)$m[1];
    }
    if ($branchId !== null && $branchId <= 0) $branchId = null;
    $out = ['path' => $path, 'kind' => $kind, 'branch_id' => $branchId, 'ada' => is_file($path),
        'version' => '', 'terkini' => false, 'size' => 0, 'status' => '', 'health' => '', 'ok' => false];

    if (!is_file($path)) {
        $out['status'] = 'MISSING';
        $out['version'] = db_schema_version_from_history($path);
        $out['health'] = 'berkas basis data belum dibuat';
        if (!empty($opts['note'])) $out['health'] .= ' · ' . (string)$opts['note'];
        db_registry_store($out);
        return $out;
    }

    $out['size'] = (int)filesize($path);
    $v = db_schema_version_of($path);
    $out['version'] = $v['version'] !== '' ? $v['version'] : '(tidak diketahui)';
    $out['terkini'] = (bool)$v['terkini'];
    $out['sumber_versi'] = $v['sumber'];

    $h = $opts['health'] ?? db_health($path);
    $sehat = !empty($h['ok']);
    $err = trim((string)($h['error'] ?? '') . ' ' . (string)$v['error']);

    /* ------------------------------------------------------------------
       STATUS = HASIL VALIDASI. `MIGRATED` TIDAK PERNAH diberikan bila
       validasi gagal — dalam keadaan itu statusnya ERROR.
       ------------------------------------------------------------------ */
    if ($err !== '' || !$sehat) {
        $out['status'] = 'ERROR';
        $out['health'] = 'GAGAL DIVALIDASI: ' . ($err !== ''
            ? $err
            : ('integritas=' . (((string)($h['integrity'] ?? '')) !== '' ? (string)$h['integrity'] : '?')
               . ', pelanggaran relasi=' . (int)($h['fk'] ?? 0)));
    } elseif (!empty($opts['migrated'])) {
        $out['status'] = 'MIGRATED';
        $out['health'] = 'skema ' . $out['version'] . ' diterapkan & validasi LOLOS'
            . ' (integritas ok, pelanggaran relasi 0)';
    } elseif (!$out['terkini']) {
        $out['status'] = 'CHECK';
        $out['health'] = 'skema ' . $out['version'] . ' BELUM terkini — aplikasi memakai '
            . SCHEMA_VERSION . '. Migrasi dijalankan otomatis saat berkas ini dipakai.';
    } else {
        $out['status'] = 'ACTIVE';
        $out['health'] = 'sehat · skema ' . $out['version'] . ' terkini'
            . ' (integritas ok, pelanggaran relasi 0)';
    }
    $out['ok'] = db_registry_status_ok($out['status']);
    if (!empty($opts['note'])) $out['health'] .= ' · ' . (string)$opts['note'];
    db_registry_store($out);

    /* RIWAYAT MIGRASI ikut diselaraskan: bila basis data ini NYATA-nyata sudah pada
       versi aplikasi sekarang tetapi riwayatnya belum memuat versi itu (mis. basis data
       yang dimigrasikan sebelum perbaikan jalur cepat), catat satu baris dengan
       keterangan yang JUJUR — "dipastikan sudah pada versi ini", bukan klaim bahwa
       migrasi baru saja dijalankan. Tidak ada DDL/DML data operasional di sini; hanya
       pencatatan fakta versi. Idempoten (INSERT … ON CONFLICT). */
    if ($out['terkini'] && empty($opts['migrated']) && !empty($out['version'])
        && $out['version'] === SCHEMA_VERSION) {
        db_registry_log_migration($path,
            'dipastikan sudah pada versi ini saat pemeriksaan registry (tanpa perubahan skema)',
            SCHEMA_VERSION, false);
    }
    return $out;
}

/**
 * Sinkronkan SELURUH registry (central + setiap cabang) dari pemeriksaan nyata.
 *
 * Dipakai: tombol "Sinkronkan Registry", tombol Health Check, dan saat panel Database
 * Center dibuka (dengan pembatas waktu `$minJeda` detik supaya tidak menulis pada
 * setiap permintaan). Pembaruan SETELAH MIGRASI tidak lewat sini, melainkan langsung
 * dari `db_schema_apply()` (hasil validasinya sudah tersedia — tidak dihitung ulang).
 *
 * @param string|null $path     batasi ke satu basis data (null = semua)
 * @param bool        $force    abaikan penanda "baru saja disinkronkan"
 * @param int         $minJeda  jeda minimum (detik) antar sinkronisasi otomatis
 * @return array{dilewati:bool,alasan:string,hasil:array,ringkas:array}
 */
function db_registry_refresh(?string $path = null, bool $force = false, int $minJeda = 60): array
{
    if (!$force) {
        $terakhir = (int)db_registry_synced_at();
        $selisih = time() - $terakhir;
        if ($terakhir > 0 && $selisih >= 0 && $selisih < $minJeda) {
            return ['dilewati' => true, 'hasil' => [],
                'alasan' => 'registry baru disinkronkan ' . $selisih . ' detik lalu (jeda ' . $minJeda . ' detik)',
                'ringkas' => db_registry_summary()];
        }
    }
    $hasil = [];
    if ($path !== null) {
        $hasil[] = db_registry_sync($path, null, null, ['migrated' => false]);
    } else {
        $hasil[] = db_registry_sync(db_central_path(), 'central', null, ['migrated' => false]);
        foreach (branches() as $b) {
            $bid = (int)$b['id'];
            $hasil[] = db_registry_sync(db_branch_path($bid), 'branch', $bid, ['migrated' => false]);
        }
        db_registry_prune_stale();
    }
    db_registry_synced_at_set((string)time());
    return ['dilewati' => false, 'hasil' => $hasil, 'ringkas' => db_registry_summary()];
}

/** Sinkronkan dari hasil pemeriksaan yang SUDAH dihitung (tanpa integritas ulang). */
function db_registry_sync_from_health(array $healthMap, ?int $branchId = null): void
{
    if (isset($healthMap['central'])) {
        db_registry_sync(db_central_path(), 'central', null, ['health' => $healthMap['central']]);
    }
    foreach ($healthMap as $kunci => $h) {
        if (!preg_match('/^branch_(\d+)$/', (string)$kunci, $m)) continue;
        $bid = (int)$m[1];
        if ($branchId !== null && $bid !== $branchId) continue;
        db_registry_sync(db_branch_path($bid), 'branch', $bid, ['health' => $h]);
    }
}

/**
 * Buang baris registry yang SUDAH TIDAK ADA BERKASNYA dan bukan basis data pemasangan
 * ini (mis. sisa pemindahan folder — dulu baris lama tetap tertinggal sehingga registry
 * menampilkan dua baris untuk basis data yang sama).
 *
 * Aman: hanya baris yang `path`-nya bukan berkas mana pun yang dihapus, dan basis data
 * yang sedang dipakai (central + setiap cabang terdaftar) TIDAK PERNAH dihapus.
 *
 * @return int jumlah baris yang dibuang
 */
function db_registry_prune_stale(): int
{
    $aktif = [db_central_path() => true];
    foreach (db_route_branch_ids() as $bid) $aktif[db_branch_path((int)$bid)] = true;
    $n = 0;
    try {
        $pdo = db_central_conn();
        db_registry_ensure($pdo);
        foreach ($pdo->query('SELECT id, kind, branch_id, path FROM db_registry')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = (string)$r['path'];
            if (isset($aktif[$p])) continue;              // basis data aktif → jangan disentuh
            if (is_file($p)) continue;                    // berkasnya masih ada → biarkan
            try {
                $st = $pdo->prepare('DELETE FROM db_registry WHERE id = ?');
                $st->execute([(int)$r['id']]);
                $n += (int)$st->rowCount();
            } catch (Throwable $e) { /* lanjut */ }
        }
    } catch (Throwable $e) { /* informatif */ }
    return $n;
}

/** Waktu sinkronisasi registry terakhir (epoch, 0 = belum pernah). */
function db_registry_synced_at(): int
{
    try {
        db_central_registry_ready();
        return (int)scalar_schema_setting(db_central_conn(), 'db_registry_synced_at');
    } catch (Throwable $e) {
        return 0;
    }
}
function db_registry_synced_at_set(string $epoch): void
{
    try {
        $pdo = db_central_conn();
        db_registry_ensure($pdo);
        $st = $pdo->prepare("INSERT INTO settings (key, value) VALUES ('db_registry_synced_at', ?)
                             ON CONFLICT(key) DO UPDATE SET value = excluded.value");
        $st->execute([$epoch]);
    } catch (Throwable $e) { /* informatif */ }
}

/**
 * Ringkasan keadaan registry: berapa basis data, mana yang tertinggal versinya,
 * mana yang gagal divalidasi.
 *
 * @return array{jumlah:int,per_status:array<int,int>,tertinggal:array<int,string>,
 *               gagal:array<int,string>,disinkronkan:string}
 */
function db_registry_summary(): array
{
    $out = ['jumlah' => 0, 'per_status' => [], 'tertinggal' => [], 'gagal' => [],
        'disinkronkan' => ''];
    foreach (db_registry_list() as $r) {
        $out['jumlah']++;
        $st = strtoupper((string)$r['status']);
        $out['per_status'][$st] = ($out['per_status'][$st] ?? 0) + 1;
        $label = (string)($r['kind'] === 'central' ? 'central' : ('cabang ' . (int)$r['branch_id']));
        if ($st === 'ERROR' || $st === 'MISSING') $out['gagal'][] = $label;
        elseif ($st === 'CHECK' || ((string)$r['schema_version']) !== SCHEMA_VERSION) {
            $out['tertinggal'][] = $label . ' (' . (string)$r['schema_version'] . ')';
        }
    }
    $t = db_registry_synced_at();
    $out['disinkronkan'] = $t > 0 ? date('Y-m-d H:i:s', $t) : 'belum pernah';
    return $out;
}

/**
 * Label jalur basis data yang STABIL untuk riwayat migrasi & registry.
 *
 * Kenapa perlu: berkas yang sama dapat terlihat sebagai dua jalur berbeda
 * (`/home/<user>/workspace/hudafa/...` dan jalur asli btrfs
 * `/var/lib/.../workspace-btrfs/mnt/hudafa/...`) tergantung proses yang membukanya.
 * Dulu hal itu membuat riwayat migrasi memuat beberapa baris untuk berkas yang SAMA.
 * Label dipakai jalur relatif terhadap folder induk aplikasi — stabil di semua proses.
 */
function db_path_label(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $akar = rtrim(str_replace('\\', '/', dirname(APP_DIR)), '/');
    if ($akar !== '' && strpos($path, $akar . '/') === 0) {
        return ltrim(substr($path, strlen($akar) + 1), '/');
    }
    /* Jalan pintas lain (mis. mount btrfs) → pakai bagian setelah nama folder aplikasi. */
    foreach (['/naveena_storage/', '/databases/'] as $penanda) {
        $pos = strpos($path, $penanda);
        if ($pos !== false) return ltrim(substr($path, $pos + 1), '/');
    }
    return basename($path);
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
    /* KEUNIKAN riwayat migrasi (FINAL AUDIT): satu baris per (basis data, versi).
       Tanpa ini, satu bug jalur cepat pernah membuat tabel ini memuat 1.945 baris
       kembar (2 baris per permintaan HTTP). Pemeriksaan "indeks sudah ada" hanya
       satu query murah; pembersihan data lama dijalankan SEKALI saja. */
    try {
        $adaIdx = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index'
                                    AND name='idx_db_migrations_unique'")->fetchColumn();
        $dinorm = (string)scalar_schema_setting($pdo, 'db_migrations_norm');
    } catch (Throwable $e) {
        $adaIdx = 1; $dinorm = '1';      // jangan mengganggu bila tidak dapat diperiksa
    }
    if (!$adaIdx || $dinorm !== '1') {
        db_migrations_cleanup($pdo);
    }
}

/**
 * Rapikan riwayat migrasi: normalisasi jalur basis data, gabungkan baris kembar,
 * lalu pasang indeks unik (db_path, version).
 *
 * @return array{sebelum:int,sesudah:int,dihapus:int}
 */
function db_migrations_cleanup(PDO $pdo): array
{
    $hasil = ['sebelum' => 0, 'sesudah' => 0, 'dihapus' => 0];
    try {
        $baris = $pdo->query('SELECT id, db_path, version, applied_at FROM db_migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $hasil['sebelum'] = count($baris);
        $kunci = [];      // "label|versi" => id yang dipertahankan
        $hapus = [];
        foreach ($baris as $r) {
            $label = db_path_label((string)$r['db_path']);
            $k = $label . '|' . (string)$r['version'];
            if (!isset($kunci[$k])) {
                $kunci[$k] = ['id' => (int)$r['id'], 'label' => $label, 'versi' => (string)$r['version']];
            } else {
                $hapus[] = (int)$r['id'];
            }
        }
        if ($hapus) {
            foreach (array_chunk($hapus, 400) as $bagian) {
                $pdo->exec('DELETE FROM db_migrations WHERE id IN (' . implode(',', array_map('intval', $bagian)) . ')');
            }
            $hasil['dihapus'] = count($hapus);
        }
        /* Normalisasi jalur baris yang dipertahankan supaya tidak ada dua bentuk jalur. */
        foreach ($kunci as $v) {
            $pdo->prepare('UPDATE db_migrations SET db_path = ? WHERE id = ?')->execute([$v['label'], $v['id']]);
        }
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_db_migrations_unique ON db_migrations(db_path, version)');
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('db_migrations_norm', '1')
                    ON CONFLICT(key) DO UPDATE SET value = '1'");
        $hasil['sesudah'] = (int)$pdo->query('SELECT COUNT(*) FROM db_migrations')->fetchColumn();
    } catch (Throwable $e) {
        /* pembersihan bersifat informatif */
    }
    return $hasil;
}

/**
 * Catat/perbarui satu basis data pada registry — VERSI SELALU DARI PEMERIKSAAN NYATA.
 *
 * Fungsi ini dipertahankan sebagai pintu lama (dipakai jalur pembuatan basis data),
 * tetapi tidak lagi memakai `setting('schema_version')` sebagai versi: versinya dibaca
 * dari berkas basis data yang bersangkutan (`db_schema_version_of()`). Status yang
 * diminta tetap dihormati HANYA bila validasinya lolos; bila tidak, statusnya
 * diturunkan menjadi `ERROR` beserta alasannya (permintaan pemilik: jangan pernah
 * melaporkan `MIGRATED`/`ACTIVE` bila validasi gagal).
 *
 * @param array $validasi hasil pemeriksaan yang sudah dihitung (opsional):
 *              ['ok'=>bool,'integrity'=>string,'fk'=>int,'error'=>string]
 */
function db_registry_put(PDO $pdo, string $kind, ?int $branchId, string $path,
                         string $status = 'ACTIVE', string $health = '', array $validasi = []): void
{
    db_registry_ensure($pdo);
    $v = db_schema_version_of($path);
    $ver = $v['version'] !== '' ? $v['version'] : '(tidak diketahui)';
    $size = is_file($path) ? (int)filesize($path) : 0;

    /* Bila pemanggil tidak memberi hasil validasi, status yang diminta hanya boleh
       berupa status "aman" (tidak menyatakan sehat tanpa bukti). */
    $status = strtoupper($status);
    if (!isset(db_registry_statuses()[$status])) $status = 'CHECK';
    if ($validasi && empty($validasi['ok'])) {
        $status = 'ERROR';
        $health = 'GAGAL DIVALIDASI: ' . (trim((string)($validasi['error'] ?? '')) !== ''
            ? (string)$validasi['error']
            : ('integritas=' . (string)($validasi['integrity'] ?? '?') . ', pelanggaran relasi='
               . (int)($validasi['fk'] ?? 0)));
    }
    if (!is_file($path)) { $status = 'MISSING'; $health = 'berkas basis data belum dibuat'; }
    if ($health === '') $health = db_registry_statuses()[$status] ?? '';

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

/**
 * Daftar basis data terdaftar.
 *
 * @param bool $hanyaPemasanganIni (bawaan) saring ke basis data PEMASANGAN INI saja
 *        (central + setiap cabang yang terdaftar). Baris lain tidak disembunyikan —
 *        dilaporkan terpisah oleh db_registry_foreign_rows() — tetapi TIDAK ikut
 *        ditampilkan sebagai basis data pemasangan (dulu baris sisa folder lain ikut
 *        tampil sehingga registry terlihat memuat basis data yang sama dua kali).
 */
function db_registry_list(bool $hanyaPemasanganIni = true): array
{
    try {
        db_central_registry_ready();
        $rows = db_central_all('SELECT * FROM db_registry ORDER BY kind, branch_id');
    } catch (Throwable $e) {
        return [];
    }
    if (!$hanyaPemasanganIni) return $rows;
    $aktif = [db_central_path() => true];
    foreach (db_route_branch_ids() as $bid) $aktif[db_branch_path((int)$bid)] = true;
    return array_values(array_filter($rows, fn($r) => isset($aktif[(string)$r['path']])));
}

/** Baris registry yang BUKAN bagian pemasangan ini (sisa folder lain / pemindahan). */
function db_registry_foreign_rows(): array
{
    $semua = db_registry_list(false);
    $ini = [];
    foreach (db_registry_list(true) as $r) $ini[(int)$r['id']] = true;
    return array_values(array_filter($semua, fn($r) => !isset($ini[(int)$r['id']])));
}

/** Hapus baris registry yang bukan bagian pemasangan ini (kunci `path` tetap unik). */
function db_registry_delete_foreign(): int
{
    $n = 0;
    try {
        $pdo = db_central_conn();
        db_registry_ensure($pdo);
        foreach (db_registry_foreign_rows() as $r) {
            $st = $pdo->prepare('DELETE FROM db_registry WHERE id = ?');
            $st->execute([(int)$r['id']]);
            $n += (int)$st->rowCount();
        }
    } catch (Throwable $e) { /* informatif */ }
    return $n;
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
        return db_branch_strip_cross_fk_tables_pdo(db_open($path), $branchId);
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
        return $out;
    }
}

/**
 * Sama seperti db_branch_strip_cross_fk_tables(), tetapi memakai KONEKSI yang sudah
 * ada. Dipakai jalur per-permintaan (`db_route_ensure_branch_schema()`) supaya berkas
 * cabang tidak dibuka DUA kali untuk pemeriksaan yang sama.
 */
function db_branch_strip_cross_fk_tables_pdo(PDO $pdo, int $branchId): array
{
    $out = ['tabel' => 0, 'dibangun' => [], 'error' => ''];
    if (!is_file(db_branch_path($branchId))) return $out;
    try {
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

/** Apakah berkas ini berkas basis data CABANG (bukan central)? */
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
        /* JALUR CEPAT (FINAL AUDIT): bila skema basis data ini SUDAH terkini, tidak
           ada apa pun yang perlu ditulis — termasuk TIDAK mencatat riwayat migrasi.
           Sebelumnya pencatatan dilakukan tanpa syarat sehingga setiap pemanggilan
           (dulu: setiap permintaan HTTP) menambah satu baris `db_migrations` dan
           satu penulisan ke central — sumber 1.945 baris riwayat kembar sekaligus
           bottleneck penulisan di central. */
        $sudah = db_schema_with_scope($scope, fn() => schema_is_current($pdo));
        if ($sudah) {
            return ['ok' => true, 'error' => '', 'tables' => db_sqlite_table_count($pdo),
                'integrity' => 'ok', 'fk' => 0, 'changed' => false];
        }
        /* CATATAN: jalur cepat di atas TIDAK menulis apa pun (penting supaya riwayat
           migrasi tidak bertambah & tidak ada fsync pada setiap permintaan). Registry
           tetap disegarkan lewat `db_registry_sync()` di akhir fungsi — dan itu hanya
           terjadi ketika skema BENAR-BENAR diterapkan. */
        /* ensure_schema() menerima PDO sebagai parameter dan membuat seluruh tabel +
           menjalankan migrasi (idempoten, satu transaksi). `$scope = 'branch'`
           memakai DDL berkas cabang (FK lintas berkas dibuang, tanpa seed contoh). */
        db_schema_with_scope($scope, function () use ($pdo) { ensure_schema($pdo); });
        $tables = db_sqlite_table_count($pdo);
        $ic = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
        $semuaFk = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
        $fk = 0;
        foreach ($semuaFk as $f) {
            if (isset($f['parent']) && db_route_scope_of((string)$f['parent']) === 'global') continue;
            $fk++;
        }
        /* Catat migrasi HANYA ketika skema benar-benar diterapkan (riwayat versi).
           Pencatatan dilakukan pada CENTRAL secara langsung supaya tidak
           bergantung pada pengalihan routing saat bootstrap.
           VERSI dikirim EKSPLISIT (`SCHEMA_VERSION` = versi yang baru saja diterapkan):
           dulu versinya diambil dari setelan CENTRAL sehingga riwayat berkas cabang bisa
           memakai versi central yang berbeda. */
        db_central_registry_ready();
        db_registry_log_migration($path, 'skema diterapkan otomatis (db_schema_apply) · scope=' . $scope,
            SCHEMA_VERSION);
        /* ------------------------------------------------------------------
           REGISTRY DIPERBARUI SETELAH MIGRASI BERHASIL (permintaan pemilik).
           Hasil validasi yang sudah dihitung di atas dipakai apa adanya — tidak
           dihitung ulang — dan status `MIGRATED` hanya diberikan bila validasinya
           LOLOS. Bila gagal, statusnya ERROR beserta alasan yang sebenarnya.
           ------------------------------------------------------------------ */
        db_registry_sync($path, $scope === 'branch' ? 'branch' : 'central',
            ($scope === 'branch' ? (db_schema_branch_id() ?: null) : null),
            ['migrated' => true,
             'health' => ['ok' => ($ic === 'ok' && $fk === 0), 'integrity' => $ic, 'fk' => $fk,
                 'error' => '', 'tables' => $tables, 'size' => is_file($path) ? (int)filesize($path) : 0]]);
        return ['ok' => ($ic === 'ok' && $fk === 0), 'error' => '', 'tables' => $tables,
            'integrity' => $ic, 'fk' => $fk, 'changed' => true];
    } catch (Throwable $e) {
        /* Migrasi GAGAL → registry TIDAK boleh mengaku sehat: status ERROR + alasan. */
        try {
            db_registry_sync($path, $scope === 'branch' ? 'branch' : 'central',
                ($scope === 'branch' ? (db_schema_branch_id() ?: null) : null),
                ['health' => ['ok' => false, 'integrity' => '', 'fk' => 0, 'error' => $e->getMessage()]]);
        } catch (Throwable $e2) { /* registry informatif */ }
        return ['ok' => false, 'error' => $e->getMessage(), 'tables' => 0, 'integrity' => '', 'fk' => 0,
            'changed' => false];
    }
}

/** Jumlah tabel sebuah basis data (tanpa membuka apa pun yang berat). */
function db_sqlite_table_count(PDO $pdo): int
{
    try { return (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/**
 * BUAT BASIS DATA CENTRAL (idempoten).
 * @return array{ok:bool,path:string,error:string,laporan:array}
 */
function db_central_create(): array
{
    $path = db_central_path();
    $baru = !is_file($path);
    /* db_schema_apply() sudah menyinkronkan registry setelah migrasi (versi nyata +
       status hasil validasi). Pemeriksaan kesehatan tetap dijalankan saat berkasnya
       MEMANG baru dibuat supaya baris registry mencerminkan pemeriksaan penuh. */
    $r = db_schema_apply($path, 'central');
    db_central_registry_ready();
    if ($baru) {
        db_registry_sync($path, 'central', null, ['health' => db_health($path),
            'note' => 'basis data central dibuat']);
    }
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
        /* Migrasi/validasi GAGAL → registry TIDAK boleh mengaku sehat. */
        db_registry_put(db_central_conn(), 'branch', $branchId, $path, 'ERROR',
            'GAGAL DIVALIDASI: ' . (string)$r['error'],
            ['ok' => false, 'error' => (string)$r['error']]);
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
    /* 3) register + status — VERSI dari berkasnya, STATUS dari hasil validasi.
       `MIGRATED` hanya bila skema memang baru saja diterapkan DAN validasinya lolos. */
    db_central_registry_ready();
    $reg = db_registry_sync($path, 'branch', $branchId, [
        'health' => $h,
        'migrated' => !empty($r['changed']),
        'note' => $baru ? 'basis data cabang dibuat' : 'diperiksa ulang',
    ]);
    $langkah[] = 'didaftarkan & berstatus ' . $reg['status'] . ' (skema ' . $reg['version'] . ')';
    audit('Basis Data Cabang', 'Database', $branchId,
        ['path' => $path], ['ok' => $h['ok']],
        'Basis data cabang ' . ($branchCode !== '' ? $branchCode . ' ' : '') . ($baru ? 'dibuat' : 'diperiksa'));
    return ['ok' => $h['ok'], 'path' => $path, 'branch_id' => $branchId, 'error' => '', 'langkah' => $langkah];
}

/**
 * Pemeriksaan kesehatan SELURUH basis data terdaftar.
 *
 * @param bool $sinkron tuliskan hasilnya ke `db_registry` (bawaan: ya). Hasilnya
 *        SUDAH dihitung di sini, jadi tidak ada pemeriksaan tambahan — registry
 *        karena itu tidak pernah ketinggalan dari pemeriksaan yang ditampilkan.
 */
function db_health_all(bool $sinkron = true): array
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
    if ($sinkron) {
        db_registry_sync_from_health($out);
        db_registry_synced_at_set((string)time());
    }
    return $out;
}

/**
 * Ringkasan status basis data untuk panel Database Center.
 *
 * Hasil pemeriksaannya juga DITULIS ke `db_registry` (tanpa biaya tambahan), sehingga
 * panel dan registry tidak pernah menampilkan angka/status yang berbeda.
 *
 * @param bool $sinkron tuliskan ke registry (bawaan: ya)
 * @return array{mode:string,central:array,branches:array<int,array>,jumlah_branch:int,perlu_dibuat:int}
 */
function db_status_summary(bool $sinkron = true): array
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
    if ($sinkron) {
        $peta = [];
        if (isset($central['integrity']) || isset($central['error'])) $peta['central'] = $central;
        foreach ($branchesOut as $b) {
            $peta['branch_' . (int)$b['id']] = $b;
        }
        db_registry_sync_from_health($peta);
        db_registry_synced_at_set((string)time());
    }
    return ['mode' => db_arch_mode(), 'central' => $central, 'branches' => $branchesOut,
        'jumlah_branch' => count($branchesOut), 'perlu_dibuat' => $perlu];
}

/* ------------------------------------------------------------------ *
 * KONEKSI PER CABANG (resolver) + PENJAGA ISOLASI
 * ------------------------------------------------------------------ */

/**
 * Koneksi basis data untuk sebuah cabang (membuka, dan membuat berkasnya bila perlu).
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
 * Selalu mengembalikan koneksi basis data CABANG tersebut (dibuat bila belum ada).
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
        'therapists', 'inventory_movements', 'users', 'member_upgrades'];
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
 * setelah menambah cabang).
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
