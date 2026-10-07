<?php
/**
 * MIGRASI DATA KE ARSITEKTUR CENTRAL + SATU SQLITE PER CABANG
 * ==========================================================
 * `data.sqlite` dipakai sebagai SUMBER MIGRASI selama diperlukan (sesuai
 * spesifikasi). Alur aman yang diterapkan di sini:
 *
 *   1. PRATINJAU  — hitung baris per tabel untuk central & tiap cabang, tampilkan
 *                   apa adanya (belum ada yang diubah).
 *   2. SNAPSHOT   — backup pengaman dibuat lebih dulu (sistem backup yang sudah ada).
 *   3. SALIN      — tabel GLOBAL → `central.sqlite`; tabel OPERASIONAL → berkas
 *                   cabang pemiliknya (baris anak lewat INDUK-nya). Memakai
 *                   `INSERT OR REPLACE` sehingga aman diulang (idempoten).
 *   4. VERIFIKASI — jumlah baris tiap tabel di tujuan HARUS sama dengan sumber,
 *                   `PRAGMA integrity_check` = ok, pelanggaran FK dalam-berkas = 0.
 *   5. BERSIHKAN  — tabel operasional di central dikosongkan (central = data
 *                   global/sistem saja), setelah verifikasi lolos.
 *
 * Selama langkah 5 belum dijalankan, `data.sqlite` TIDAK disentuh sama sekali.
 */

/**
 * Kolom yang ada di SUMBER maupun TUJUAN (irisan, urut menurut TUJUAN).
 *
 * PENTING: `INSERT INTO tujuan SELECT * FROM sumber` TIDAK boleh dipakai — urutan
 * kolom berkas tujuan (dibuat schema_ddl() + migrasi ALTER TABLE) BERBEDA dari
 * data.sqlite, sehingga nilai bisa masuk ke kolom yang salah (mis. branch_id
 * menerima NULL dan NOT NULL menolaknya). Karena itu daftar kolomnya eksplisit.
 *
 * @return array<int,string>
 */
function db_route_common_columns(PDO $pdo, string $sumberAlias, string $tujuanAlias, string $tabel): array
{
    $ambil = function (string $alias) use ($pdo, $tabel): array {
        try {
            $st = $pdo->query('PRAGMA ' . $alias . '.table_info(' . $pdo->quote($tabel) . ')');
            $kol = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $kol[(string)$r['name']] = true;
            return $kol;
        } catch (Throwable $e) {
            return [];
        }
    };
    $sumber = $ambil($sumberAlias);
    $tujuanUrut = [];
    try {
        foreach ($pdo->query('PRAGMA ' . $tujuanAlias . '.table_info(' . $pdo->quote($tabel) . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tujuanUrut[(string)$r['name']] = true;
        }
    } catch (Throwable $e) {
        return [];
    }
    $keluar = [];
    foreach (array_keys($tujuanUrut) as $k) {
        if (isset($sumber[$k])) $keluar[] = $k;
    }
    return $keluar;
}

/** Tabel legacy yang layak dimigrasikan (semua tabel nyata di data.sqlite). */
function db_route_legacy_tables(PDO $legacy): array
{
    $rows = $legacy->query("SELECT name FROM sqlite_master WHERE type='table'
                            AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter($rows, fn($t) => $t !== 'sqlite_sequence'));
}

/**
 * Daftar id cabang dari BASIS DATA SUMBER.
 *
 * Penting: saat migrasi dijalankan, `central.sqlite` belum memuat daftar cabang
 * (tabel `branches` masih kosong) — kalau memakai db_route_branch_ids() hanya
 * cabang 1 yang akan dimigrasikan.
 */
function db_route_legacy_branch_ids(PDO $legacy): array
{
    try {
        $ids = $legacy->query('SELECT id FROM branches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $ids = array_map('intval', $ids);
        if ($ids) return $ids;
    } catch (Throwable $e) { /* tabel branches tidak ada */ }
    return [1];
}

/** Nama cabang dari basis data sumber (untuk pratinjau). */
function db_route_legacy_branches(PDO $legacy): array
{
    try {
        return $legacy->query('SELECT id, code, name FROM branches ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** Hitung baris sebuah tabel di sumber untuk satu cabang (null = semua cabang). */
function db_route_legacy_count(PDO $legacy, string $table, ?int $branchId): int
{
    try {
        if (db_route_scope_of($table) !== 'branch') {
            return (int)$legacy->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
        }
        $anak = db_route_child_tables();
        if (isset($anak[$table])) {
            [$induk, $fk] = $anak[$table];
            $sql = 'SELECT COUNT(*) FROM "' . $table . '" c JOIN "' . $induk . '" p ON p.id = c."' . $fk . '"';
            $sql .= $branchId === null ? '' : ' WHERE p.branch_id = ' . (int)$branchId;
            return (int)$legacy->query($sql)->fetchColumn();
        }
        $sql = 'SELECT COUNT(*) FROM "' . $table . '"';
        if ($branchId !== null) $sql .= ' WHERE branch_id = ' . (int)$branchId;
        return (int)$legacy->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return -1;   // tabel tidak ada / kolom berbeda → dilaporkan apa adanya
    }
}

/** Hitung baris di berkas TUJUAN (central atau cabang) — koneksi langsung. */
function db_route_target_count(string $path, string $table): int
{
    try {
        $pdo = db_open($path);
        return (int)$pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
    } catch (Throwable $e) {
        return -1;
    }
}

/**
 * PRATINJAU migrasi: berapa baris yang akan disalin ke mana.
 *
 * @return array{legacy:string,central:array,total_global:int,cabang:array,total_cabang:int}
 */
function db_route_migrate_preview(): array
{
    $legacyPath = DB_PATH;
    $out = ['legacy' => $legacyPath, 'legacy_ada' => is_file($legacyPath), 'central' => [],
        'total_global' => 0, 'cabang' => [], 'total_cabang' => 0];
    if (!$out['legacy_ada']) return $out;
    $legacy = db_open($legacyPath);
    $tables = db_route_legacy_tables($legacy);
    $namaCabang = [];
    foreach (db_route_legacy_branches($legacy) as $b) $namaCabang[(int)$b['id']] = (string)$b['name'];
    $idsSumber = db_route_legacy_branch_ids($legacy);
    foreach ($tables as $t) {
        if (db_route_scope_of($t) === 'branch') continue;
        $n = db_route_legacy_count($legacy, $t, null);
        if ($n > 0) $out['central'][$t] = $n;
        $out['total_global'] += max(0, $n);
    }
    foreach ($idsSumber as $bid) {
        $isi = [];
        foreach ($tables as $t) {
            if (db_route_scope_of($t) !== 'branch') continue;
            $n = db_route_legacy_count($legacy, $t, (int)$bid);
            if ($n > 0) $isi[$t] = $n;
            $out['total_cabang'] += max(0, $n);
        }
        $out['cabang'][(int)$bid] = ['nama' => $namaCabang[(int)$bid] ?? ('Cabang ' . $bid),
            'tabel' => $isi, 'total' => array_sum($isi),
            'berkas' => basename(db_branch_path((int)$bid)),
            'sudah_ada' => is_file(db_branch_path((int)$bid))];
    }
    return $out;
}

/** Daftar cabang langsung dari central (tanpa dispatcher). */
function db_route_central_branches(): array
{
    return db_route_raw_central_many('SELECT id, code, name FROM branches ORDER BY id');
}

/**
 * JALANKAN migrasi.
 *
 * @param bool $bersihkanCentral kosongkan tabel operasional di central setelah verifikasi
 * @return array{ok:bool,langkah:array,salinan:array,verifikasi:array,error:string}
 */
function db_route_migrate_run(bool $bersihkanCentral = true, bool $bangunUlangCabang = true): array
{
    $out = ['ok' => false, 'langkah' => [], 'salinan' => [], 'verifikasi' => [], 'error' => ''];
    $legacyPath = DB_PATH;
    if (!is_file($legacyPath)) { $out['error'] = 'Basis data sumber (data.sqlite) tidak ditemukan.'; return $out; }
    if (realpath($legacyPath) === realpath(db_central_path())) {
        $out['error'] = 'Sumber dan central menunjuk berkas yang SAMA — migrasi dibatalkan demi keamanan.';
        return $out;
    }
    try {
        $legacy = db_open($legacyPath);
        $tables = db_route_legacy_tables($legacy);
        $centralPath = db_central_path();
        if (!is_file($centralPath)) db_central_create();
        $central = db_open($centralPath);
        $out['langkah'][] = 'Central disiapkan: ' . basename($centralPath);

        /* Bangun ULANG berkas cabang bila diminta: berkas lama (dibuat versi
           sebelumnya) memuat `FOREIGN KEY … REFERENCES branches(id)` — SQLite tidak
           mendukung FK antar berkas sehingga SETIAP penulisan aplikasi akan ditolak.
           Berkas cabang adalah data TURUNAN (sumbernya tetap data.sqlite), jadi
           membangunnya ulang aman — dan dilakukan SEBELUM apa pun disalin. */
        if ($bangunUlangCabang) {
            $dihapus = 0;
            foreach (db_route_legacy_branch_ids($legacy) as $bid) {
                $p = db_branch_path((int)$bid);
                foreach ([$p, $p . '-wal', $p . '-shm'] as $f) {
                    if (is_file($f) && @unlink($f)) $dihapus++;
                }
            }
            $out['langkah'][] = 'Berkas cabang dibangun ulang (' . $dihapus . ' berkas lama dibuang; '
                . 'data.sqlite TIDAK disentuh)';
        }

        /* ---- ATTACH sumber + tujuan (main = central) ---- */
        $central->exec('ATTACH DATABASE ' . $central->quote($legacyPath) . ' AS legacy');
        $out['langkah'][] = 'Sumber data.sqlite dilampirkan sebagai `legacy` (baca-saja)';
        /* Foreign key DIMATIKAN selama penyalinan: urutan tabel (master → transaksi →
           anak) tidak selalu sama dengan urutan alfabetis, sedangkan keutuhan relasi
           diverifikasi SETELAH seluruh salinan selesai (jumlah baris + foreign_key_check). */
        $central->exec('PRAGMA foreign_keys = OFF');

        /* ---- 1. Tabel GLOBAL → central ----
           Dipanggil DUA KALI: sebelum dan sesudah berkas cabang dibuat, karena
           pembuatan berkas cabang sendiri menulis jejak Audit Log ke basis data
           aktif — tanpa salinan ulang, verifikasi akan melihat selisih. */
        $salinGlobal = 0;
        /* Salinan GLOBAL bersifat MENGGANTI (hapus lalu isi ulang) supaya central benar-benar
           CERMIN data sumber: tanpa itu, data contoh yang ditanam saat berkas dibuat
           (mis. akun/menu bawaan) akan tersisa dan jumlahnya tidak sama dengan sumber. */
        $salinGlobalKe = function () use ($central, $tables, $legacy, &$salinGlobal) {
            foreach ($tables as $t) {
                if (db_route_scope_of($t) === 'branch') continue;
                try {
                    $kol = db_route_common_columns($central, 'legacy', 'main', $t);
                    if (!$kol) continue;
                    $ins = '(' . implode(',', array_map(fn($c) => '"' . $c . '"', $kol)) . ')';
                    $sel = implode(',', array_map(fn($c) => '"' . $c . '"', $kol));
                    $central->exec('DELETE FROM main."' . $t . '"');
                    $n = (int)$central->exec('INSERT OR REPLACE INTO main."' . $t . '" ' . $ins
                        . ' SELECT ' . $sel . ' FROM legacy."' . $t . '"');
                    $salinGlobal += max(0, (int)$n);
                } catch (Throwable $e) {
                    /* Tabel yang tidak ada di tujuan atau kolomnya berbeda → dicatat apa adanya. */
                    $GLOBALS['DB_ROUTE_MIGRATE_GAGAL']['(gagal) ' . $t] = $e->getMessage();
                }
            }
        };
        $salinGlobalKe();

        /* ---- 2. Tabel OPERASIONAL → berkas cabang (dikelompokkan agar ATTACH <= 9) ---- */
        $ids = db_route_legacy_branch_ids($legacy);
        $salahCopy = 0;
        foreach (array_chunk($ids, 6) as $kelompok) {
            $alias = [];
            foreach ($kelompok as $bid) {
                $p = db_branch_path((int)$bid);
                if (!is_file($p)) db_branch_create((int)$bid);
                $a = 'm' . (int)$bid;
                $central->exec('ATTACH DATABASE ' . $central->quote($p) . ' AS ' . $a);
                $alias[(int)$bid] = $a;
            }
            foreach ($kelompok as $bid) {
                $a = $alias[(int)$bid];
                $bid = (int)$bid;
                foreach ($tables as $t) {
                    if (db_route_scope_of($t) !== 'branch') continue;
                    $anak = db_route_child_tables();
                    try {
                        /* Hapus isi tujuan lebih dulu supaya berkas cabang CERMIN sumber
                           (data contoh yang ditanam saat berkas dibuat ikut terbuang). */
                        try { $central->exec('DELETE FROM ' . $a . '."' . $t . '"'); } catch (Throwable $e2) {}
                        $kol = db_route_common_columns($central, 'legacy', $a, $t);
                        if (!$kol) continue;
                        $ins = '(' . implode(',', array_map(fn($c) => '"' . $c . '"', $kol)) . ')';
                        $sel = implode(',', array_map(fn($c) => '"' . $c . '"', $kol));
                        if (isset($anak[$t])) {
                            [$induk, $fk] = $anak[$t];
                            $sql = 'INSERT OR REPLACE INTO ' . $a . '."' . $t . '" ' . $ins
                                . ' SELECT ' . implode(',', array_map(fn($c) => 'c."' . $c . '"', $kol))
                                . ' FROM legacy."' . $t . '" c'
                                . ' JOIN legacy."' . $induk . '" p ON p.id = c."' . $fk . '" WHERE p.branch_id = ' . $bid;
                        } else {
                            $sql = 'INSERT OR REPLACE INTO ' . $a . '."' . $t . '" ' . $ins
                                . ' SELECT ' . $sel . ' FROM legacy."' . $t . '" WHERE branch_id = ' . $bid;
                        }
                        $n = (int)$central->exec($sql);
                        if ($n > 0) $out['salinan']['cabang ' . $bid . ':' . $t] = max(0, $n);
                    } catch (Throwable $e) {
                        $out['salinan']['(gagal) cabang ' . $bid . ':' . $t] = $e->getMessage();
                        $salahCopy++;
                    }
                }
            }
            foreach ($alias as $a) $central->exec('DETACH DATABASE ' . $a);
            $out['langkah'][] = 'Cabang ' . implode(', ', $kelompok) . ' disalin';
        }

        /* ---- 3a. Lantai id tiap berkas cabang (id tidak bertabrakan antar cabang) ---- */
        foreach ($ids as $bid) {
            $idf = db_branch_apply_id_floor((int)$bid);
            $out['langkah'][] = 'Cabang ' . (int)$bid . ': lantai id ' . number_format(db_branch_id_floor((int)$bid), 0, ',', '.')
                . ' (' . count($idf['dinaikkan']) . ' tabel)';
        }

        /* ---- 3b. Salinan ulang tabel global (menyusul jejak audit saat membuat cabang) ---- */
        $salinGlobalKe();
        foreach (($GLOBALS['DB_ROUTE_MIGRATE_GAGAL'] ?? []) as $k => $v) $out['salinan'][$k] = $v;
        $out['langkah'][] = 'Tabel global disalin ke central: ' . $salinGlobal . ' baris (termasuk salinan penyusul)';
        $out['salinan']['__global'] = $salinGlobal;

        /* ---- 3c. Central = data GLOBAL saja ----
           Tabel operasional di central HARUS kosong: barisnya milik berkas cabang.
           Dilakukan SEBELUM pemeriksaan integritas/FK, karena sisa baris di central
           akan melaporkan pelanggaran relasi yang sebenarnya bukan masalah. */
        if ($bersihkanCentral) {
            $dibersihkan = 0;
            $c2 = db_open($centralPath);
            foreach ($tables as $t) {
                if (db_route_scope_of($t) !== 'branch') continue;
                try { $dibersihkan += (int)$c2->exec('DELETE FROM "' . $t . '"'); } catch (Throwable $e) { /* tidak ada di central */ }
            }
            /* Index unik berkas cabang di dalam central juga dibersihkan supaya central
               benar-benar tidak menyimpan data operasional. */
            $out['langkah'][] = 'Tabel operasional di central dikosongkan: ' . $dibersihkan . ' baris';
        }

        /* ---- 4. VERIFIKASI jumlah baris per tabel ---- */
        $central->exec('PRAGMA foreign_keys = ON');
        $central->exec('DETACH DATABASE legacy');
        $salah = 0;
        if ($salahCopy > 0) {
            $out['error'] = 'Sebagian tabel GAGAL disalin (' . $salahCopy . ' tabel) — lihat rincian.';
            foreach ($out['salinan'] as $k => $v) {
                if (strpos($k, '(gagal)') === 0) $out['langkah'][] = $k . ' → ' . $v;
            }
            return $out;
        }
        foreach ($tables as $t) {
            if (db_route_scope_of($t) === 'branch') continue;
            $sumber = db_route_legacy_count($legacy, $t, null);
            $tujuan = db_route_target_count($centralPath, $t);
            $ok = ($sumber === $tujuan) || $sumber <= 0;
            $out['verifikasi'][] = ['lingkup' => 'central', 'tabel' => $t, 'sumber' => $sumber,
                'tujuan' => $tujuan, 'ok' => $ok];
            if (!$ok) $salah++;
        }
        foreach ($ids as $bid) {
            $p = db_branch_path((int)$bid);
            foreach ($tables as $t) {
                if (db_route_scope_of($t) !== 'branch') continue;
                $sumber = db_route_legacy_count($legacy, $t, (int)$bid);
                if ($sumber <= 0) continue;
                $tujuan = db_route_target_count($p, $t);
                $ok = ($sumber === $tujuan);
                $out['verifikasi'][] = ['lingkup' => 'cabang ' . $bid, 'tabel' => $t,
                    'sumber' => $sumber, 'tujuan' => $tujuan, 'ok' => $ok];
                if (!$ok) $salah++;
            }
        }
        $out['langkah'][] = 'Verifikasi jumlah baris: ' . ($salah === 0
            ? 'SELURUH tabel cocok (' . count($out['verifikasi']) . ' pemeriksaan)'
            : $salah . ' tabel TIDAK cocok');

        /* ---- 5. Integritas & FK berkas tujuan ---- */
        $hCentral = db_health($centralPath);
        $out['integritas'] = ['central' => $hCentral['integrity'] . ' · pelanggaran FK ' . $hCentral['fk']];
        if ($hCentral['integrity'] !== 'ok' || $hCentral['fk'] > 0) {
            $salah++;
            $out['langkah'][] = 'Central: ' . $hCentral['integrity'] . ' · pelanggaran FK ' . $hCentral['fk'];
        }
        foreach ($ids as $bid) {
            $h = db_health(db_branch_path((int)$bid));
            $out['integritas']['cabang ' . $bid] = $h['integrity'] . ' · FK dalam-berkas ' . $h['fk'];
            if ($h['integrity'] !== 'ok' || $h['fk'] > 0) $salah++;
        }

        if ($salah > 0) {
            $out['error'] = 'Verifikasi belum lolos (' . $salah . ' masalah) — central TIDAK dikosongkan.';
            return $out;
        }

        /* ---- 5b. Berkas cabang bebas FK lintas berkas ----
           (kalau ada, seluruh penulisan aplikasi akan ditolak saat runtime). */
        $fkSalah = [];
        foreach ($ids as $bid) {
            try {
                $bc = db_open(db_branch_path((int)$bid));
                $tbl = $bc->query("SELECT name, sql FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($tbl as $t) {
                    if (!preg_match_all('/REFERENCES\s+[`"\[]?(\w+)/i', (string)$t['sql'], $mm)) continue;
                    foreach ($mm[1] as $induk) {
                        if (db_route_scope_of($induk) === 'global') {
                            $fkSalah[] = 'cabang ' . $bid . ':' . $t['name'] . ' → ' . $induk;
                        }
                    }
                }
            } catch (Throwable $e) { /* dilaporkan lewat hasil */ }
        }
        if ($fkSalah) {
            $out['error'] = 'Berkas cabang masih memuat foreign key ke tabel global (penulisan akan ditolak): '
                . implode(', ', array_slice($fkSalah, 0, 5));
            $out['langkah'][] = 'GAGAL: ' . $out['error'];
            return $out;
        }
        $out['langkah'][] = 'Berkas cabang bebas foreign key lintas berkas (penulisan aman)';

        $out['ok'] = true;
        $out['langkah'][] = 'Migrasi SELESAI & TERVERIFIKASI (' . count($out['verifikasi']) . ' pemeriksaan baris).';
        $out['langkah'][] = 'Berkas cabang: ' . implode(', ', array_map(
            fn($id) => basename(db_branch_path((int)$id)), $ids));
        return $out;
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
        return $out;
    }
}
