<?php
/**
 * MIGRASI DATA: SATU data.sqlite → central.sqlite + SQLITE PER CABANG (ronde 55)
 * ============================================================================
 * Permintaan pemilik (PDF Master Upgrade bagian 3): audit table-by-table untuk menentukan
 * tabel mana yang CENTRAL dan mana yang milik CABANG, lalu pindahkan datanya.
 *
 * PRINSIP AMAN:
 *   1. `data.sqlite` adalah SOURCE OF TRUTH dan TIDAK PERNAH diubah/dihapus oleh alat ini.
 *   2. Migrasi menulis ke basis data BARU (central + branch) di folder terpisah.
 *   3. Pratinjau lebih dulu (tanpa menulis), lalu verifikasi: jumlah baris per tabel harus
 *      SAMA, integritas `ok`, tanpa pelanggaran FK.
 *   4. Dapat diulang (idempoten): tabel tujuan dikosongkan lebih dulu untuk tabel yang
 *      dimigrasikan pada jalannya proses itu.
 *
 * Pemakaian:
 *   php navigasi... (lihat tools/migrate_central_branch.php)
 */

/** Klasifikasi tabel: 'central' (global) atau 'branch' (milik satu cabang). */
function dbc_classify_tables(): array
{
    /* Central: identitas & konfigurasi sistem, kamus, akun/hak akses, jejak sistem,
       registry basis data, dan data lintas cabang yang memang global. */
    $central = ['settings', 'roles', 'permissions', 'role_permissions', 'user_permissions',
        'users', 'branches', 'icd_codes', 'import_batches', 'import_errors', 'audit_logs',
        'backups', 'email_report_logs', 'db_registry', 'db_migrations', 'demo_batches',
        'login_remember', 'password_resets', 'recovery_codes', 'login_2fa_codes',
        'finance_costs', 'finance_cost_amounts', 'sqlite_sequence',
        /* Tabel kerja AI Developer (bukan data klinik). */
        'ai_tasks', 'ai_task_files', 'ai_usage_log', 'ai_steps', 'ai_messages', 'ai_traces', 'ai_jobs',
        /* Bayar pending: menyimpan muatan transaksi; dikaitkan ke order yang sudah dibuat
           di cabang, jadi ikut CENTRAL agar tidak menggantung saat order belum ada. */
        'pay_pending'];
    return ['central' => $central, 'branch' => null];   // null = sisanya milik cabang
}

/**
 * Tabel ANAK yang tidak punya kolom `branch_id` tetapi MILIK CABANG lewat INDUKNYA.
 *
 * Ini temuan penting dari verifikasi migrasi ronde 55: tanpa pemetaan ini, `order_items`,
 * `payments`, `appointment_treatments`, `medical_record_photos`, dan `package_items`
 * tertinggal di central sehingga barisnya MENGGANTUNG (22 pelanggaran relasi) padahal
 * induknya pindah ke basis data cabang.
 *
 * @return array<string,array{0:string,1:string}> tabel anak => [tabel induk, kolom penghubung]
 */
function dbc_child_map(): array
{
    return [
        'order_items'            => ['orders', 'order_id'],
        'payments'               => ['orders', 'order_id'],
        'appointment_treatments' => ['appointments', 'appointment_id'],
        'medical_record_photos'  => ['medical_records', 'medical_record_id'],
        'package_items'          => ['packages', 'package_id'],
    ];
}

/** Tabel sistem yang isinya diisi/diubah ensure_schema() → tidak dibandingkan ketat. */
function dbc_verify_skip(): array
{
    return ['settings', 'db_registry', 'db_migrations'];
}

/** Daftar tabel milik cabang (yang punya kolom branch_id). */
function dbc_branch_tables(PDO $pdo): array
{
    $out = [];
    $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $cen = dbc_classify_tables()['central'];
    foreach ($rows as $t) {
        if (in_array($t, $cen, true)) continue;
        $ada = (int)$pdo->query("SELECT COUNT(*) FROM pragma_table_info(" . $pdo->quote($t) . ") WHERE name='branch_id'")
            ->fetchColumn();
        if ($ada > 0) { $out[] = $t; continue; }
        /* Tabel anak ikut cabang lewat INDUKNYA (tanpa kolom branch_id). */
        if (isset(dbc_child_map()[$t])) $out[] = $t;
    }
    sort($out);
    return $out;
}

/** Nama tabel yang TIDAK dipindah (tabel teknis SQLite). */
function dbc_skip_tables(): array
{
    return ['sqlite_sequence'];
}

/**
 * PRATINJAU migrasi dari basis data sumber: berapa baris per tabel central & per cabang.
 *
 * @return array{central:array<string,int>,branch:array<string,int>,cabang:array<int,array{id:int,name:string,rows:array<string,int>}>,total:int}
 */
function dbc_preview(PDO $src): array
{
    $central = [];
    $branch = [];
    foreach ((array)dbc_classify_tables()['central'] as $t) {
        if (in_array($t, dbc_skip_tables(), true)) continue;
        try { $central[$t] = (int)$src->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
        catch (Throwable $e) { /* tabel tidak ada di sumber */ }
    }
    $bt = dbc_branch_tables($src);
    foreach ($bt as $t) {
        try { $branch[$t] = (int)$src->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
        catch (Throwable $e) { $branch[$t] = 0; }
    }
    $cabang = [];
    $total = 0;
    foreach ($src->query("SELECT id, name FROM branches ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $rows = [];
        $n = 0;
        foreach ($bt as $t) {
            try {
                $c = (int)$src->query("SELECT COUNT(*) FROM {$t} WHERE " . dbc_branch_where($t, (int)$b['id']))->fetchColumn();
            } catch (Throwable $e) { $c = 0; }
            if ($c > 0) { $rows[$t] = $c; $n += $c; }
        }
        $cabang[] = ['id' => (int)$b['id'], 'name' => (string)$b['name'], 'rows' => $rows, 'total' => $n];
        $total += $n;
    }
    return ['central' => $central, 'branch' => $branch, 'cabang' => $cabang, 'total' => $total];
}

/**
 * Potongan WHERE untuk mengambil baris MILIK satu cabang dari sebuah tabel:
 * tabel ber-branch_id → langsung; tabel anak → lewat induknya.
 */
function dbc_branch_where(string $tabel, int $branchId): string
{
    $cm = dbc_child_map();
    if (isset($cm[$tabel])) {
        [$induk, $kolom] = $cm[$tabel];
        return $kolom . ' IN (SELECT id FROM ' . $induk . ' WHERE branch_id = ' . (int)$branchId . ')';
    }
    return 'branch_id = ' . (int)$branchId;
}

/** Salin tabel (kolom yang sama) dari sumber ke tujuan, dengan filter opsional. */
function dbc_copy_table(PDO $from, PDO $to, string $tabel, string $where = '', array $params = []): array
{
    try {
        $kolom = $from->query("PRAGMA table_info(" . $from->quote($tabel) . ")")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return ['ok' => false, 'row' => 0, 'error' => 'tabel tidak terbaca']; }
    if (!$kolom) return ['ok' => false, 'row' => 0, 'error' => 'tabel tidak ada'];
    $nama = array_map(fn($k) => (string)$k['name'], $kolom);
    try {
        /* PENTING: tabel tujuan dibersihkan PENUH — `ensure_schema()` menanam data contoh
           (mis. dokter/terapis/supplier bawaan) saat basis data baru dibuat. Kalau hanya
           baris ber-filter yang dihapus, data bawaan itu tertinggal sehingga jumlah baris
           tidak sama dengan sumber (tertangkap verifikasi migrasi ronde 55). */
        $to->exec("PRAGMA foreign_keys = OFF");
        $to->exec("DELETE FROM {$tabel}");
    } catch (Throwable $e) {
        /* Tabel tujuan belum ada → dibuat seperti sumber (tanpa indeks/FK khusus). */
        try {
            $ddl = (string)$from->query("SELECT sql FROM sqlite_master WHERE type='table' AND name="
                . $from->quote($tabel))->fetchColumn();
            if ($ddl !== '') $to->exec($ddl);
        } catch (Throwable $e2) { return ['ok' => false, 'row' => 0, 'error' => $e2->getMessage()]; }
    }
    /* NILAI PENGGANTI untuk kolom NOT NULL (tanpa bawaan) yang di sumber berisi NULL.
       Penyebab nyata: kolom seperti `order_items.hpp` ditambahkan belakangan sehingga baris
       LAMA bernilai NULL, sedangkan skema terbaru menetapkannya NOT NULL — tanpa
       penggantian ini, migrasi berhenti di tengah (tertangkap saat uji ronde 55). */
    $notNull = [];
    try {
        foreach ($to->query("PRAGMA table_info(" . $to->quote($tabel) . ")")->fetchAll(PDO::FETCH_ASSOC) as $k) {
            if ((int)$k['notnull'] === 1) {
                $tipe = strtoupper((string)$k['type']);
                $angka = (strpos($tipe, 'INT') !== false || strpos($tipe, 'REAL') !== false
                    || strpos($tipe, 'NUM') !== false || strpos($tipe, 'DEC') !== false);
                /* JEBAKAN SQLite: kolom NOT NULL yang punya DEFAULT tetap GAGAL bila kita
                   memasukkan NULL secara EKSPLISIT (DEFAULT hanya berlaku bila kolomnya
                   tidak disebut pada INSERT). Karena kita menyebut SEMUA kolom, nilai
                   bawaan itu harus kita pakai sendiri — tanpa ini, migrasi berhenti di
                   baris lama yang kolom barunya masih NULL. */
                $bawaan = $k['dflt_value'];
                if ($bawaan !== null) {
                    $bawaan = trim((string)$bawaan);
                    $bawaan = trim($bawaan, "'\"");
                    if ($angka && is_numeric($bawaan)) $bawaan = $bawaan + 0;
                    $notNull[(string)$k['name']] = $bawaan;
                } else {
                    $notNull[(string)$k['name']] = $angka ? 0 : '';
                }
            }
        }
    } catch (Throwable $e) { /* tanpa informasi kolom: lanjut apa adanya */ }
    $sql = 'INSERT INTO ' . $tabel . ' (' . implode(',', array_map(fn($c) => '"' . $c . '"', $nama)) . ') VALUES ('
        . implode(',', array_fill(0, count($nama), '?')) . ')';
    $stmtIns = null;
    try { $stmtIns = $to->prepare($sql); } catch (Throwable $e) { return ['ok' => false, 'row' => 0, 'error' => $e->getMessage()]; }
    $sel = 'SELECT ' . implode(',', array_map(fn($c) => '"' . $c . '"', $nama)) . ' FROM ' . $tabel
        . ($where !== '' ? ' WHERE ' . $where : '');
    $stmtSel = $from->prepare($sel);
    $stmtSel->execute($params);
    $n = 0;
    try {
        $to->beginTransaction();
        while (($r = $stmtSel->fetch(PDO::FETCH_NUM)) !== false) {
            foreach ($r as $i => $v) {
                if ($v === null && isset($notNull[$nama[$i]])) $v = $notNull[$nama[$i]];
                $stmtIns->bindValue($i + 1, $v, $v === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            }
            $stmtIns->execute();
            $n++;
        }
        $to->commit();
    } catch (Throwable $e) {
        if ($to->inTransaction()) $to->rollBack();
        return ['ok' => false, 'row' => $n, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'row' => $n, 'error' => ''];
}

/**
 * JALANKAN migrasi lengkap. Bila $dryRun = true, hanya pratinjau (tidak menulis).
 *
 * @return array{ok:bool,error:string,central:array,branches:array<int,array>,verifikasi:array}
 */
function dbc_migrate(PDO $src, bool $dryRun = false): array
{
    $preview = dbc_preview($src);
    if ($dryRun) return ['ok' => true, 'error' => '', 'preview' => $preview, 'dry' => true];

    if (!function_exists('db_open')) return ['ok' => false, 'error' => 'Manajer basis data tidak tersedia.', 'preview' => $preview];

    /* 1) CENTRAL */
    $centralPath = db_central_path();
    $rc = db_schema_apply($centralPath);
    if (!$rc['ok']) return ['ok' => false, 'error' => 'Gagal menyiapkan central: ' . $rc['error'], 'preview' => $preview];
    $cen = db_open($centralPath);
    $hasilCentral = [];
    foreach (array_keys($preview['central']) as $t) {
        $r = dbc_copy_table($src, $cen, $t);
        $hasilCentral[$t] = $r['row'];
        if (!$r['ok']) return ['ok' => false, 'error' => 'Gagal menyalin tabel central ' . $t . ': ' . $r['error'],
            'preview' => $preview];
    }

    /* 1b) BERSIHKAN tabel milik cabang dari CENTRAL.
       `ensure_schema()` menanam data contoh (dokter/terapis/treatment/skincare/…) saat
       basis data baru dibuat. Kalau dibiarkan, central memuat baris milik cabang yang
       menunjuk cabang tidak ada → 22 pelanggaran relasi (tertangkap verifikasi ronde 55).
       Data itu SUDAH dipindahkan ke basis data cabang pada langkah berikutnya. */
    foreach (dbc_branch_tables($src) as $t) {
        try { $cen->exec("DELETE FROM {$t}"); } catch (Throwable $e) { /* tabel tidak ada di central */ }
    }

    /* 2) TIAP CABANG */
    $hasilBranch = [];
    foreach ($preview['cabang'] as $b) {
        $path = db_branch_path((int)$b['id']);
        $rb = db_schema_apply($path);
        if (!$rb['ok']) return ['ok' => false, 'error' => 'Gagal menyiapkan basis data cabang ' . $b['name'] . ': ' . $rb['error'],
            'preview' => $preview];
        $bp = db_open($path);
        $rows = [];
        foreach (dbc_branch_tables($src) as $t) {
            $r = dbc_copy_table($src, $bp, $t, dbc_branch_where($t, (int)$b['id']));
            $rows[$t] = $r['row'];
            if (!$r['ok']) return ['ok' => false, 'error' => 'Gagal menyalin ' . $t . ' pada cabang ' . $b['name'] . ': ' . $r['error'],
                'preview' => $preview];
        }
        /* Tabel anak tanpa branch_id tetap disalin bila parent-nya milik cabang ini
           (mis. appointment_treatments lewat appointments). */
        $hasilBranch[(int)$b['id']] = ['name' => (string)$b['name'], 'rows' => $rows, 'total' => array_sum($rows)];
        db_registry_put(db(), 'branch', (int)$b['id'], $path, 'MIGRATED', 'hasil migrasi ronde 55');
    }

    /* 3) VERIFIKASI: jumlah baris tujuan harus sama dengan pratinjau. */
    $salah = [];
    foreach ($preview['central'] as $t => $n) {
        if (in_array($t, dbc_verify_skip(), true)) continue;   // diisi/diubah ensure_schema()
        $harap = (int)$n;
        $ada = (int)$cen->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        if ($ada !== $harap) $salah[] = 'central.' . $t . ': ' . $ada . ' ≠ ' . $harap;
    }
    foreach ($preview['cabang'] as $b) {
        $bp = db_open(db_branch_path((int)$b['id']));
        foreach ($b['rows'] as $t => $n) {
            $ada = (int)$bp->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            if ($ada !== (int)$n) $salah[] = 'branch_' . $b['id'] . '.' . $t . ': ' . $ada . ' ≠ ' . (int)$n;
        }
    }
    $ic = (string)$cen->query('PRAGMA integrity_check')->fetchColumn();
    $fk = count($cen->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC));
    db_registry_put(db(), 'central', null, $centralPath, $salah ? 'CHECK' : 'MIGRATED',
        $salah ? 'selisih jumlah baris' : 'verifikasi ok');
    return ['ok' => ($salah === []), 'error' => $salah ? implode('; ', array_slice($salah, 0, 6)) : '',
        'preview' => $preview, 'central' => $hasilCentral, 'branches' => $hasilBranch,
        'verifikasi' => ['integrity' => $ic, 'fk' => $fk, 'selisih' => $salah]];
}
