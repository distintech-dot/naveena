<?php
/**
 * Fungsi backup database (dipakai menu Backup Database, fitur "Hapus Semua
 * Data", dan pengisian data demo — semuanya membuat snapshot pengaman lebih
 * dulu).
 *
 * KOMPRESI: setiap backup ditulis sebagai gzip (.sql.gz) sehingga berkas jauh
 * lebih kecil (umumnya 8–15× lebih kecil dari .sql) tanpa kehilangan satu byte
 * pun — isinya adalah dump SQL yang sama, hanya dipadatkan. Membaca kembali
 * memakai backup_read_sql() yang mengenali .sql.gz MAUPUN .sql lama, jadi
 * berkas backup yang sudah ada tetap dapat direstore/download.
 *
 * BATAS PENYIMPANAN: jumlah/ukuran backup dibatasi (setelan `backup_max_mb`)
 * supaya penyimpanan aplikasi tidak habis. Saat sudah mendekati batas sistem
 * memberi PERINGATAN; saat sudah penuh, backup baru DITOLAK dengan pesan yang
 * menyuruh menghapus backup lama lebih dulu.
 */
declare(strict_types=1);

/** Folder backup (dapat dialihkan lewat NAVEENA_BACKUP_DIR — dipakai skrip uji
 *  supaya hasil uji tidak menumpuk di folder backup milik aplikasi). */
function backup_dir_ensure(): string
{
    $custom = trim((string)getenv('NAVEENA_BACKUP_DIR'));
    /* PENGAMAN (lihat nv_isolated_root): proses uji/CLI yang mengalihkan NAVEENA_DB
       tidak boleh menulis backup ke folder backup aplikasi terbit (pernah menumpuk
       400+ berkas uji ≈ 1,3 GB di sana). */
    if ($custom === '' && function_exists('nv_isolated_root')) {
        $iso = nv_isolated_root();
        if ($iso !== '') $custom = $iso . '/backups';
    }
    $dir = $custom !== '' ? $custom : BACKUP_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/* ------------------------------------------------------------------ *
 * KOMPRESI
 * ------------------------------------------------------------------ */

/** Apakah gzip tersedia di PHP ini (modul zlib). */
function backup_gzip_available(): bool
{
    return function_exists('gzencode') && function_exists('gzdecode');
}

/**
 * Apakah KOMPRESI PAKET (DEFLATE di dalam ZIP) tersedia di PHP yang sedang berjalan?
 *
 * Dipakai untuk menyatakan keadaan APA ADANYA di halaman Backup: bila zlib tidak
 * dimuat, paket tetap dibuat tetapi TANPA kompresi (jangan diklaim terkompresi).
 * Diperiksa lengkap: `gzdeflate` (untuk menulis) dan `gzinflate` (untuk membaca).
 */
function backup_zip_deflate_available(): bool
{
    return function_exists('gzdeflate') && function_exists('gzinflate');
}

/** Padatkan dump SQL menjadi gzip. Bila zlib tidak ada, dikembalikan apa adanya. */
function backup_compress(string $sql): string
{
    if (!backup_gzip_available()) return $sql;
    /* Level 9 = pemadatan maksimum. Deterministik dan lossless: hasilnya
       selalu dapat dikembalikan PERSIS seperti isi aslinya. */
    $gz = gzencode($sql, 9);
    return $gz === false ? $sql : $gz;
}

/** Baca isi berkas backup (.sql ataupun .sql.gz) menjadi teks SQL. */
function backup_read_sql(string $path): string
{
    if (!is_file($path)) throw new RuntimeException('Berkas backup tidak ditemukan: ' . basename($path));
    $raw = (string)file_get_contents($path);
    if ($raw === '') throw new RuntimeException('Berkas backup kosong.');
    /* Deteksi dari isi (bukan hanya nama berkas) supaya berkas .sql.gz yang
       namanya berubah tetap terbaca, dan berkas .sql biasa tidak salah baca. */
    if (strlen($raw) > 2 && ord($raw[0]) === 0x1f && ord($raw[1]) === 0x8b) {
        if (!function_exists('gzdecode')) throw new RuntimeException('Berkas backup terkompres tetapi modul gzip tidak tersedia di server ini.');
        $sql = @gzdecode($raw);
        if ($sql === false) throw new RuntimeException('Berkas backup terkompres rusak (gagal dibuka).');
        return (string)$sql;
    }
    return $raw;
}

/** Nama berkas backup yang dijamin belum dipakai (mencegah nama kembar). */
function backup_file_path(string $prefix, string $ext = '.sql.gz'): array
{
    $dir = backup_dir_ensure();
    $base = $prefix . date('Ymd-His');
    $name = $base . $ext;
    $i = 1;
    /* Nama backup beresolusi DETIK, jadi dua backup dalam detik yang sama dulu
       menghasilkan nama IDENTIK: baris kedua menimpa berkas baris pertama, dan
       saat salah satu dipangkas berkas milik baris lain ikut terhapus sehingga
       berkas "terdaftar" hilang dari disk. Sekarang nama wajib unik. */
    while (is_file($dir . '/' . $name)
        || (int)scalar('SELECT COUNT(*) FROM backups WHERE filename = ?', [$name]) > 0) {
        $i++;
        $name = $base . '-' . $i . $ext;
    }
    return [$dir, $name];
}

/* ------------------------------------------------------------------ *
 * BATAS PENYIMPANAN BACKUP
 * ------------------------------------------------------------------ */

/** Batas ukuran total folder backup (MB) — dapat diatur di Pengaturan Sistem. */
function backup_quota_mb(): int
{
    $mb = (int)setting('backup_max_mb', '500');
    if ($mb <= 0) $mb = 500;
    return max(50, min(20000, $mb));
}
function backup_quota_bytes(): int
{
    return backup_quota_mb() * 1048576;
}

/** Semua berkas backup di folder (terdaftar maupun tidak). */
/**
 * Daftar berkas backup di folder backup (SEMUA jenis yang diproduksi aplikasi).
 *
 * JEBAKAN YANG SUDAH DIPERBAIKI (ronde 64c): fungsi ini dulu hanya memakai pola
 * `*.sql` dan `*.sql.gz`, sehingga **backup PAKET (`.zip`) dan backup per cabang
 * (`.sqlite.gz`) tidak pernah terlihat**. Akibatnya:
 *   • tombol "Bersihkan" (berkas tak terdaftar) tidak pernah bisa membuang sisa
 *     paket/per-cabang — di produksi menumpuk 15 berkas / ±135 MB yang tak
 *     terpakai tetapi tampak "0 berkas sisa" di layar;
 *   • peringatan penyimpanan hampir penuh tidak pernah menyebut berkas itu.
 * Semua jenis backup yang dibuat `backup_create_package()` / `backup_branch_create()`
 * kini ikut dihitung, sementara berkas yang TERDAFTAR di tabel `backups` tetap
 * dilindungi oleh `backup_orphan_files()`.
 */
function backup_dir_files(): array
{
    $dir = backup_dir_ensure();
    $out = [];
    /* Urutan penting: `*.sql.gz` harus diperiksa sebelum `*.sql` agar tidak ada
       berkas yang terdaftar dua kali (glob `*.sql` tidak cocok untuk `.sql.gz`). */
    foreach (['*.sql', '*.sql.gz', '*.sqlite.gz', '*.zip'] as $pola) {
        foreach (glob($dir . '/' . $pola) ?: [] as $path) {
            if (!is_file($path)) continue;
            $out[basename($path)] = ['name' => basename($path), 'size' => (int)filesize($path),
                'mtime' => (int)filemtime($path)];
        }
    }
    return array_values($out);
}

/**
 * Ringkasan pemakaian penyimpanan backup + status peringatan.
 *
 * Yang dihitung terhadap BATAS adalah berkas backup yang TERDAFTAR di tabel
 * `backups` (backup yang dikelola aplikasi). Berkas sisa/tak terdaftar
 * dihitung terpisah (biasanya sisa uji atau salinan lama) karena bisa
 * dibersihkan sendiri lewat tombol "Bersihkan" tanpa memengaruhi backup resmi.
 */
function backup_storage_info(): array
{
    $dir = backup_dir_ensure();
    $used = 0;
    $registered = 0;
    $missing = 0;
    foreach (all('SELECT filename FROM backups') as $r) {
        $p = $dir . '/' . basename((string)$r['filename']);
        if (is_file($p)) { $used += (int)filesize($p); $registered++; } else { $missing++; }
    }
    $orph = backup_orphan_files();
    $quota = backup_quota_bytes();
    $pct = $quota > 0 ? ($used / $quota) * 100 : 0;
    $status = $pct >= 100 ? 'full' : ($pct >= 80 ? 'warn' : 'ok');
    return [
        'files' => $registered, 'used' => $used, 'quota' => $quota,
        'quota_mb' => backup_quota_mb(), 'pct' => $pct, 'status' => $status,
        'free' => max(0, $quota - $used),
        'missing' => $missing,
        'orphan_files' => (int)$orph['total'], 'orphan_bytes' => (int)$orph['bytes'],
        'used_total' => $used + (int)$orph['bytes'],
    ];
}

/** Pesan peringatan siap tampil saat penyimpanan mendekati/penuh. */
function backup_storage_notice(?array $info = null): array
{
    $info = $info ?? backup_storage_info();
    $usedTxt = num(round($info['used'] / 1048576, 1), 1) . ' MB';
    $quotaTxt = $info['quota_mb'] . ' MB';
    if ($info['status'] === 'full') {
        return ['tone' => 'full', 'info' => $info, 'message' =>
            'Penyimpanan backup SUDAH PENUH: ' . $usedTxt . ' dari batas ' . $quotaTxt . ' ('
            . num(round($info['pct']), 0) . '%). Backup baru tidak dapat dibuat sebelum Anda menghapus backup lama — '
            . 'pakai tombol "Hapus Backup Lama" atau tombol Hapus pada baris backup yang tidak diperlukan lagi.'];
    }
    if ($info['status'] === 'warn') {
        return ['tone' => 'warn', 'info' => $info, 'message' =>
            'Penyimpanan backup hampir penuh: ' . $usedTxt . ' dari batas ' . $quotaTxt . ' ('
            . num(round($info['pct']), 0) . '%). Buat salinan berkas bila masih diperlukan, lalu hapus backup lama '
            . 'supaya backup berikutnya tetap dapat dibuat.'];
    }
    if ($info['orphan_bytes'] > 0 && $info['used_total'] > (int)floor($info['quota'] * 0.8)) {
        return ['tone' => 'warn', 'info' => $info, 'message' =>
            'Penyimpanan aplikasi hampir penuh bila berkas sisa dihitung: ' . $usedTxt . ' backup terdaftar + '
            . num(round($info['orphan_bytes'] / 1048576, 1), 1) . ' MB berkas backup tak terdaftar. '
            . 'Gunakan tombol "Bersihkan" pada daftar di bawah untuk membebaskan berkas sisa.'];
    }
    return ['tone' => 'ok', 'message' => '', 'info' => $info];
}

/**
 * Hapus backup PALING LAMA sampai pemakaian turun ke ambang aman (80%).
 * Dipakai tombol "Hapus Backup Lama" dan otomatis sebelum backup otomatis.
 *
 * @return array{count:int,freed:int,names:array<int,string>,targetReached:bool}
 */
function backup_prune_to_fit(): array
{
    $info = backup_storage_info();
    $target = (int)floor($info['quota'] * 0.8);
    $dir = backup_dir_ensure();
    $rows = all('SELECT id, filename, size FROM backups ORDER BY id ASC');
    $count = 0;
    $freed = 0;
    $names = [];
    foreach ($rows as $b) {
        if ($info['used'] - $freed <= $target) break;
        $path = $dir . '/' . basename((string)$b['filename']);
        $size = is_file($path) ? (int)filesize($path) : 0;
        if (is_file($path) && !@unlink($path)) continue;
        q('DELETE FROM backups WHERE id = ?', [(int)$b['id']]);
        $freed += $size;
        $count++;
        $names[] = (string)$b['filename'];
    }
    $after = backup_storage_info();
    return ['count' => $count, 'freed' => $freed, 'names' => $names,
            'targetReached' => $after['used'] <= $target];
}

/** Berkas di folder backup yang TIDAK terdaftar di tabel `backups` (sisa/berkas lama). */
function backup_orphan_files(): array
{
    $dir = backup_dir_ensure();
    $known = array_map(fn($r) => (string)$r['filename'], all('SELECT filename FROM backups'));
    $out = [];
    $bytes = 0;
    foreach (backup_dir_files() as $f) {
        if (in_array($f['name'], $known, true)) continue;
        $out[] = ['name' => $f['name'], 'size' => $f['size']];
        $bytes += $f['size'];
    }
    return ['files' => $out, 'total' => count($out), 'bytes' => $bytes];
}

/** Hapus berkas backup yang tidak terdaftar (pembersih). Mengembalikan jumlah berkas. */
function backup_purge_orphans(): int
{
    $dir = backup_dir_ensure();
    $n = 0;
    foreach (backup_orphan_files()['files'] as $f) {
        $path = $dir . '/' . basename($f['name']);
        if (is_file($path) && @unlink($path)) $n++;
    }
    return $n;
}

/* ------------------------------------------------------------------ *
 * DUMP & PEMBUATAN BACKUP
 * ------------------------------------------------------------------ */

/** Dump schema + data (teks SQL) — selalu utuh, dipadatkan saat ditulis. */
/**
 * DUMP SELURUH DATA: CENTRAL + SETIAP BERKAS CABANG.
 *
 * PENTING (FINAL AUDIT): pada arsitektur central + satu basis data per cabang, satu
 * koneksi hanya melihat SEBAGIAN basis data. `sqlite_master` pada koneksi aplikasi
 * menunjuk basis data `main` (central), sehingga dump yang membaca satu koneksi saja
 * TIDAK memuat tabel operasional (pasien/transaksi) sama sekali — backup akan tampak
 * berhasil tetapi tidak dapat memulihkan data klinik.
 *
 * Karena itu dump menyusuri SETIAP basis data:
 *   • bagian central ditandai `-- @@BRANCH 0`;
 *   • bagian tiap cabang ditandai `-- @@BRANCH <id cabang>`.
 * Penanda itu dipakai RESTORE untuk menetapkan cabang tujuan penulisan secara
 * eksplisit — sehingga tabel ANAK (order_items, payments, …) yang tidak punya
 * `branch_id` pun mendarat di berkas cabang yang benar (tanpa penanda, cabangnya
 * ditentukan dari baris INDUK yang mungkin belum tersisip).
 *
 * Berkas lama (tanpa penanda) tetap dapat dipulihkan — restore jatuh ke cara lama
 * (menentukan cabang dari nilai `branch_id` pada pernyataan).
 */
function db_dump(): string
{
    $pdo = db();
    $out = "-- " . clinic_name() . " Management System\n-- Backup: " . date('Y-m-d H:i:s')
        . "\n-- Arsitektur: central.sqlite (data global) + satu basis data per cabang (data operasional)"
        . "\n-- Isi dump: SELURUH data (global + semua cabang) dengan penanda -- @@BRANCH <id>"
        . "\n\n";

    /* ---------- 1. CENTRAL (data global/sistem) ----------
       Penanda baris ini HARUS persis `-- @@BRANCH <angka>` (tanpa teks tambahan) karena
       pemecah pernyataan hanya mempertahankan bentuk itu untuk dipakai restore. */
    $out .= "-- @@BRANCH 0\n";
    $out .= db_dump_section($pdo, null, []);

    /* ---------- 2. SETIAP BERKAS CABANG (data operasional) ---------- */
    foreach (db_route_branch_ids() as $bid) {
        $path = db_branch_path((int)$bid);
        if (!is_file($path)) continue;
        $out .= "\n-- @@BRANCH " . (int)$bid . "\n";
        try {
            /* Koneksi TERPISAH ke berkas cabang: tidak dibatasi jumlah ATTACH dan
               tidak terpengaruh cakupan baca — isi berkas dibaca apa adanya. */
            $c = db_open($path);
            $out .= db_dump_section($c, (int)$bid, schema_branch_table_list());
            $c = null;
        } catch (Throwable $e) {
            $out .= "-- PERINGATAN: cabang " . (int)$bid . " tidak dapat dibaca: " . $e->getMessage() . "\n";
        }
    }
    return $out;
}

/**
 * Satu bagian dump (DDL + data) dari sebuah koneksi.
 *
 * @param PDO        $pdo
 * @param int|null   $branchId null = central
 * @param string[]   $hanya    bila tidak kosong, hanya tabel ini yang diikutkan
 */
function db_dump_section(PDO $pdo, ?int $branchId, array $hanya): string
{
    $filter = $hanya ? array_flip(array_map('strtolower', $hanya)) : null;
    $semua = $pdo->query("SELECT type, name, sql FROM sqlite_master
                          WHERE type='table' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%'
                          ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $tabel = [];
    foreach ($semua as $o) {
        $nama = (string)$o['name'];
        if ($filter !== null) {
            if (!isset($filter[strtolower($nama)])) continue;
        } else {
            /* Bagian central TIDAK memuat tabel operasional (tidak ada di central). */
            if (db_route_scope_of($nama) === 'branch') continue;
        }
        $tabel[] = $o;
    }
    $out = '';
    foreach ($tabel as $o) {
        $out .= $o['sql'] . ";\n";
    }
    $out .= "\n";
    foreach ($tabel as $o) {
        $t = (string)$o['name'];
        try {
            $rows = $pdo->query('SELECT * FROM "' . $t . '"')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            continue;
        }
        if (!$rows) continue;
        $out .= "-- data: {$t} (" . count($rows) . " baris)\n";
        foreach ($rows as $r) {
            $cols = [];
            $vals = [];
            foreach ($r as $c => $v) {
                $cols[] = '"' . $c . '"';
                $vals[] = $v === null ? 'NULL' : $pdo->quote((string)$v);
            }
            $out .= 'INSERT INTO "' . $t . '" (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
        }
        $out .= "\n";
    }
    return $out;
}

/**
 * Buat satu berkas backup TERKOMPRES (dipakai backup manual, backup otomatis,
 * maupun snapshot pengaman sebelum tindakan berisiko).
 *
 * @param array $opts prefix  : awalan nama berkas (bawaan 'naveena-backup-')
 *                    enforce : true (bawaan) = tolak bila penyimpanan penuh;
 *                              false = snapshot pengaman tetap dibuat
 * @return array{ok:bool,file:string,size:int,raw_size:int,ratio:float,error:string,compressed:bool}
 */
function backup_create(string $note, ?int $userId = null, array $opts = []): array
{
    $prefix = (string)($opts['prefix'] ?? 'naveena-backup-');
    $enforce = $opts['enforce'] ?? true;

    $info = backup_storage_info();
    if ($enforce && $info['status'] === 'full') {
        $notice = backup_storage_notice($info);
        return ['ok' => false, 'file' => '', 'size' => 0, 'raw_size' => 0, 'ratio' => 0,
                'compressed' => false, 'error' => $notice['message']];
    }

    $sql = db_dump();
    $rawSize = strlen($sql);
    $payload = backup_compress($sql);
    $compressed = $payload !== $sql;

    [$dir, $file] = backup_file_path($prefix, $compressed ? '.sql.gz' : '.sql');
    $tmp = $dir . '/.' . $file . '.part';
    if (@file_put_contents($tmp, $payload) === false) {
        return ['ok' => false, 'file' => '', 'size' => 0, 'raw_size' => $rawSize, 'ratio' => 0,
                'compressed' => $compressed, 'error' => 'Gagal menulis berkas backup (periksa izin folder).'];
    }
    /* Berkas ditulis ke nama sementara lalu dipindahkan sekaligus: berkas
       setengah jadi tidak pernah terlihat sebagai backup yang "siap direstore". */
    if (!@rename($tmp, $dir . '/' . $file)) {
        @unlink($tmp);
        return ['ok' => false, 'file' => '', 'size' => 0, 'raw_size' => $rawSize, 'ratio' => 0,
                'compressed' => $compressed, 'error' => 'Gagal menyimpan berkas backup ke folder tujuan.'];
    }
    /* Uji baca kembali: berkas terkompres WAJIB dapat dibuka dan isinya sama.
       Kalau gagal, berkas dibuang supaya tidak ada backup yang dikira baik. */
    if ($compressed) {
        $back = @gzdecode((string)file_get_contents($dir . '/' . $file));
        if ($back === false || strlen($back) !== $rawSize) {
            @unlink($dir . '/' . $file);
            return ['ok' => false, 'file' => '', 'size' => 0, 'raw_size' => $rawSize, 'ratio' => 0,
                    'compressed' => false, 'error' => 'Berkas backup terkompres gagal diverifikasi — backup dibatalkan.'];
        }
        unset($back);
    }

    $size = (int)filesize($dir . '/' . $file);
    q('INSERT INTO backups (filename, size, note, created_by, kind, auto_run) VALUES (?,?,?,?,?,?)',
        [$file, $size, $note, $userId, 'sql', (string)($opts['auto_run'] ?? '')]);
    return ['ok' => true, 'file' => $file, 'size' => $size, 'raw_size' => $rawSize,
            'ratio' => $rawSize > 0 ? $size / $rawSize : 0, 'compressed' => $compressed, 'error' => ''];
}

/* ------------------------------------------------------------------ *
 * BACKUP OTOMATIS (tanpa cron)
 * ------------------------------------------------------------------ */

/** Awal periode jadwal backup (dipakai sebagai penanda "sudah jalan"). */
function backup_period_key(string $schedule, ?int $ts = null): string
{
    $ts = $ts ?? time();
    switch ($schedule) {
        case 'mingguan': return date('o-\WW', $ts);          // pekan ISO
        case 'bulanan':  return date('Y-m', $ts);
        case 'harian':
        default:         return date('Y-m-d', $ts);
    }
}

function backup_schedule_label(string $schedule): string
{
    return ['harian' => 'Harian', 'mingguan' => 'Mingguan', 'bulanan' => 'Bulanan'][$schedule] ?? 'Harian';
}

/** Berapa backup OTOMATIS terakhir yang disimpan (yang lama dibuang). */
function backup_keep_count(): int
{
    $n = (int)setting('backup_keep', '7');
    return max(1, min(60, $n > 0 ? $n : 7));
}

/** Cakupan backup OTOMATIS: 'paket' (semua basis data dalam 1 berkas), 'cabang'
 *  (satu berkas tiap cabang) atau 'keduanya'. */
function backup_auto_scope(): string
{
    $v = (string)setting('backup_auto_scope', 'keduanya');
    return in_array($v, ['paket', 'cabang', 'keduanya'], true) ? $v : 'keduanya';
}
function backup_auto_scope_label(string $v): string
{
    return ['paket' => 'Paket lengkap (satu berkas berisi central + semua cabang)',
        'cabang' => 'Per cabang (satu berkas tiap cabang)',
        'keduanya' => 'Keduanya (paket lengkap + satu berkas tiap cabang)'][$v] ?? $v;
}

/**
 * Buang backup OTOMATIS paling lama sampai jumlahnya <= setelan.
 *
 * WAJIB memangkas per SET (`auto_run`), bukan per baris: satu kali jadwal berjalan
 * dapat menghasilkan beberapa berkas (paket lengkap + berkas tiap cabang) yang
 * saling melengkapi — kalau dipangkas per baris, satu set bisa terhapus separuh
 * sehingga pemulihan menjadi tidak lengkap. Backup MANUAL / snapshot pengaman
 * (note bukan berawalan "Backup otomatis") TIDAK PERNAH dihapus di sini.
 */
function backup_prune_auto(): int
{
    $keep = backup_keep_count();
    $auto = all("SELECT id, filename, COALESCE(NULLIF(auto_run, ''), 'lama-' || date(created_at)) AS set_key
                 FROM backups WHERE note LIKE 'Backup otomatis%' ORDER BY id DESC");
    if (count($auto) <= $keep) return 0;

    /* Urutkan set menurut kemunculan TERBARU-nya, lalu sisakan $keep baris terbaru
       dan buang SELURUH sisa set yang sudah melewati batas. */
    $urut = [];
    foreach ($auto as $b) {
        $k = (string)$b['set_key'];
        if (!isset($urut[$k])) $urut[$k] = [];
        $urut[$k][] = $b;
    }
    $dir = backup_dir_ensure();
    $n = 0;
    $baris = 0;
    $pertama = true;
    foreach ($urut as $k => $isi) {
        /* Set TERBARU selalu disimpan walau satu setnya sendiri sudah melebihi batas
           — kalau tidak, dengan batas kecil (mis. 2) dan 3 berkas per set, SEMUA
           backup otomatis bisa terhapus sekaligus (tidak ada cadangan sama sekali). */
        if ($pertama || $baris + count($isi) <= $keep) {
            $baris += count($isi);
            $pertama = false;
            continue;
        }
        foreach ($isi as $b) {
            $path = $dir . '/' . basename((string)$b['filename']);
            if (is_file($path)) @unlink($path);
            q('DELETE FROM backups WHERE id = ?', [(int)$b['id']]);
            $n++;
        }
    }
    return $n;
}

/**
 * Backup OTOMATIS tanpa cron: dipanggil saat petugas membuka aplikasi.
 *
 * Platform ini TIDAK menyediakan cron untuk aplikasi, jadi (sama seperti
 * laporan email otomatis) jadwalnya diperiksa saat ada yang login: bila
 * periode jadwal (harian/mingguan/bulanan) sudah berganti dan belum pernah
 * dibuat, sistem membuat backup sekarang. Dijaga setelan
 * `backup_last_period` supaya tidak berulang dalam periode yang sama.
 *
 * Sebelum membuat, backup otomatis lama dipangkas lebih dulu supaya tidak
 * menabrak batas penyimpanan; bila batas tetap penuh, backup dilewati dengan
 * pesan jujur (tidak pernah diklaim berhasil).
 *
 * @return array|null null bila tidak perlu/tidak aktif; array hasil bila baru dibuat
 */
function backup_auto_run(?int $userId = null): ?array
{
    if (setting('backup_active') !== '1') return null;
    $schedule = (string)setting('backup_schedule', 'harian');
    if (!in_array($schedule, ['harian', 'mingguan', 'bulanan'], true)) $schedule = 'harian';

    $period = backup_period_key($schedule);
    if (setting('backup_last_period') === $period) return null;   // sudah dibuat periode ini

    /* Rapikan lebih dulu: buang set backup otomatis yang melebihi batas jumlah,
       lalu bila penyimpanan masih penuh buang yang paling lama sampai lega. */
    backup_prune_auto();
    $info = backup_storage_info();
    if ($info['status'] === 'full') backup_prune_to_fit();

    /* CAKUPAN backup otomatis (permintaan pemilik): per cabang DAN/ATAU paket lengkap.
       Setiap berkas terkompresi: paket = ZIP, per cabang = .sqlite.gz. */
    $scope = backup_auto_scope();
    $label = 'Backup otomatis (' . backup_schedule_label($schedule) . ')';
    $opsi = ['auto_run' => $period, 'enforce' => false];
    $hasil = [];
    $gagal = [];

    if ($scope === 'paket' || $scope === 'keduanya') {
        $r = backup_create_package($label, $userId, $opsi);
        $r['ok'] ? $hasil[] = ['jenis' => 'paket', 'file' => $r['file'], 'size' => (int)$r['size'],
                'raw' => 0, 'database' => count((array)($r['entries'] ?? []))]
            : $gagal[] = 'paket lengkap: ' . $r['error'];
    }
    if ($scope === 'cabang' || $scope === 'keduanya') {
        foreach (db_route_branch_ids() as $bid) {
            $r = backup_create_branch((int)$bid, $label, $userId, $opsi);
            $r['ok'] ? $hasil[] = ['jenis' => 'cabang', 'branch_id' => (int)$bid, 'file' => $r['file'],
                    'size' => (int)$r['size'], 'raw' => (int)$r['raw_size']]
                : $gagal[] = 'cabang ' . $bid . ': ' . $r['error'];
        }
    }
    if (!$hasil) {
        $pesan = $gagal ? implode('; ', $gagal) : 'Tidak ada basis data yang dapat dibackup.';
        set_setting('backup_last_error', $pesan . ' (' . date('Y-m-d H:i') . ')');
        return ['ok' => false, 'error' => $pesan, 'period' => $period];
    }

    $totalUkuran = array_sum(array_column($hasil, 'size'));
    set_setting('backup_last_period', $period);
    set_setting('backup_last_at', date('Y-m-d H:i:s'));
    set_setting('backup_last_file', (string)$hasil[0]['file']);
    set_setting('backup_last_error', $gagal ? ('Sebagian gagal — ' . implode('; ', $gagal)) : '');
    $removed = backup_prune_auto();
    audit('Backup Otomatis', 'Pengaturan', null, null,
        ['jadwal' => $schedule, 'cakupan' => $scope, 'berkas' => count($hasil),
         'ukuran' => $totalUkuran, 'dibuang' => $removed],
        'Backup otomatis ' . backup_schedule_label($schedule) . ' dijalankan saat aplikasi dibuka'
        . ' (' . count($hasil) . ' berkas, cakupan ' . backup_auto_scope_label($scope) . ')');
    return ['ok' => true, 'file' => (string)$hasil[0]['file'], 'size' => $totalUkuran, 'raw_size' => 0,
        'compressed' => true, 'schedule' => $schedule, 'period' => $period, 'scope' => $scope,
        'berkas' => $hasil, 'gagal' => $gagal, 'removed' => $removed, 'error' => ''];
}

/**
 * Pecah isi berkas backup menjadi pernyataan SQL yang siap dijalankan.
 *
 * PENTING (bug yang pernah terjadi): baris komentar (`-- ...`) TIDAK boleh
 * dibuang bersama pernyataannya. Berkas backup diawali komentar header dan
 * komentar itu menempel pada pernyataan pertama, sehingga aturan lama
 * "lewati pernyataan yang diawali --" membuang CREATE TABLE pertama
 * (appointment_treatments) — akibatnya CREATE INDEX-nya gagal
 * ("no such table") dan RESTORE SELALU GAGAL.
 *
 * Di sini setiap baris komentar dibuang lebih dulu, lalu pernyataan dipisah
 * pada tanda titik-koma di akhir baris.
 */
function backup_split_statements(string $sql): array
{
    /* Pemecah pernyataan yang MENGERTI isi SQL: titik-koma di dalam teks
       (string/identifier) — dan juga di dalam KOMENTAR — tidak boleh memecah
       pernyataan. Ini penting karena `db_dump()` menulis isi sqlite_master apa
       adanya, TERMASUK komentar di dalam CREATE TABLE: sejak ronde 33 ada
       komentar berisi titik-koma di dalam CREATE TABLE finance_costs sehingga
       versi lama fungsi ini membelah tabel menjadi dua pernyataan dan restore
       gagal ("incomplete input").
       Aturan: lewati string berkutip (kutip ganda di dalamnya = escape),
       komentar baris, dan komentar blok. Pisah pada ; di luar semuanya. */
    $statements = [];
    $buf = '';
    $len = strlen($sql);
    $i = 0;
    while ($i < $len) {
        $ch = $sql[$i];
        $next = ($i + 1 < $len) ? $sql[$i + 1] : '';
        /* komentar baris */
        if ($ch === '-' && $next === '-') {
            $mulai = $i;
            while ($i < $len && $sql[$i] !== "\n") $i++;
            /* PENANDA BAGIAN BASIS DATA dipertahankan sebagai pernyataan tersendiri
               (`@@BRANCH <id>`): dipakai restore untuk menetapkan cabang tujuan
               penulisan. Komentar lain dibuang seperti biasa. */
            $komentar = trim(substr($sql, $mulai, $i - $mulai));
            if (preg_match('/^--\s*@@BRANCH\s+\d+\s*$/i', $komentar)) {
                if (trim($buf) !== '') { $statements[] = trim($buf); $buf = ''; }
                $statements[] = strtoupper(preg_replace('/^--\s*/', '', $komentar));
            }
            continue;
        }
        /* komentar blok */
        if ($ch === '/' && $next === '*') {
            $i += 2;
            while ($i < $len && !($sql[$i] === '*' && ($i + 1 < $len) && $sql[$i + 1] === '/')) $i++;
            $i = min($len, $i + 2);
            continue;
        }
        /* string / identifier berkutip */
        if ($ch === "'" || $ch === '"') {
            $buf .= $ch;
            $i++;
            while ($i < $len) {
                $c = $sql[$i];
                if ($c === $ch) {
                    /* '' atau "" = kutip ter-escape */
                    if ($i + 1 < $len && $sql[$i + 1] === $ch) { $buf .= $ch . $ch; $i += 2; continue; }
                    $buf .= $ch; $i++;
                    break;
                }
                $buf .= $c; $i++;
            }
            continue;
        }
        if ($ch === ';') {
            $st = trim($buf);
            if ($st !== '') $statements[] = $st;
            $buf = '';
            $i++;
            continue;
        }
        $buf .= $ch;
        $i++;
    }
    $st = trim($buf);
    if ($st !== '') $statements[] = $st;
    return $statements;
}

/** Keterangan ukuran berkas backup: "1,2 MB (asli 9,4 MB · hemat 87%)". */
function backup_size_text(int $size, int $rawSize = 0): string
{
    $txt = num(round($size / 1048576, 2), 2) . ' MB';
    if ($rawSize > 0 && $rawSize > $size) {
        $pct = 100 - ($size / $rawSize * 100);
        $txt .= ' (asli ' . num(round($rawSize / 1048576, 2), 2) . ' MB · terkompres '
             . num(round($pct), 0) . '% lebih kecil)';
    }
    return $txt;
}

/* ------------------------------------------------------------------ *
 * PAKET BACKUP CENTRAL + BRANCH (ronde 54)
 * ------------------------------------------------------------------ *
 * Permintaan pemilik: SISTEM BACKUP YANG SUDAH ADA di-upgrade agar menangani
 * central.sqlite + SELURUH basis data cabang, lengkap dengan manifest, checksum,
 * metadata, riwayat, unduhan dan restore — BUKAN sistem backup kedua.
 *
 * Berkas paket: naveena-backup-YYYYMMDD-HHMMSS.zip
 *   backup/central.sqlite            (basis data utama / central)
 *   backup/branches/branch_00X.sqlite
 *   manifest.json                    daftar berkas + ukuran
 *   checksums.json                   sha256 tiap berkas
 *   metadata.json                    versi skema, mode arsitektur, waktu, jumlah cabang
 * ------------------------------------------------------------------ */

/**
 * Versi skema untuk metadata paket backup — dari PEMERIKSAAN NYATA.
 * Bila ada basis data yang versinya berbeda dari kode aplikasi, hal itu ditulis apa
 * adanya (mis. "central 1.41.0 · 2 cabang @ 1.41.0" atau "… (BELUM terkini)").
 */
function backup_schema_version_text(): string
{
    if (!function_exists('db_schema_version_of')) {
        return (string)setting('schema_version', SCHEMA_VERSION);
    }
    try {
        $vc = db_schema_version_of(db_central_path());
        $bagian = ['central ' . ($vc['version'] !== '' ? $vc['version'] : '(tidak diketahui)')];
        $peta = [];
        foreach (db_route_branch_ids() as $bid) {
            $v = db_schema_version_of(db_branch_path((int)$bid));
            $kunci = $v['version'] !== '' ? $v['version'] : '(tidak diketahui)';
            $peta[$kunci] = ($peta[$kunci] ?? 0) + 1;
        }
        foreach ($peta as $v => $n) $bagian[] = $n . ' cabang @ ' . $v;
        return implode(' · ', $bagian);
    } catch (Throwable $e) {
        return (string)setting('schema_version', SCHEMA_VERSION);
    }
}

/** Daftar basis data yang harus masuk paket backup (central + tiap cabang). */
function backup_db_inventory(): array
{
    $out = [];
    /* Basis data CENTRAL — SATU-SATUNYA sumber data global/sistem pada arsitektur
       central + satu berkas per cabang.
       JEBAKAN YANG PERNAH TERJADI (dan merusak data): di sini dulu dipakai `DB_PATH`
       yang saat itu menunjuk berkas `naveena_data/data.sqlite` (jalur lama). Akibatnya
       paket backup menyimpan berkas LAMA itu dengan label "central.sqlite", dan saat
       paket dipulihkan berkas warisan itu MENIMPA central yang sebenarnya — seluruh
       perubahan data global sesudah pemisahan (jejak audit, setelan, dsb.) hilang.
       Karena itu central WAJIB diambil dari `db_central_path()`. */
    /* Basis data central — SATU-SATUNYA sumber data global. Tidak ada lagi fallback
       ke jalur lama `data.sqlite`: berkas itu sudah dihapus dari instalasi. */
    $centralPath = db_central_path();
    $out[] = ['kind' => 'central', 'branch_id' => null, 'label' => 'central.sqlite',
        'path' => $centralPath, 'ada' => is_file($centralPath)];
    /* Basis data per cabang (arsitektur central/branch). */
    if (function_exists('db_branch_path')) {
        foreach (branches() as $b) {
            $p = db_branch_path((int)$b['id']);
            $out[] = ['kind' => 'branch', 'branch_id' => (int)$b['id'],
                'label' => 'branches/branch_' . str_pad((string)(int)$b['id'], 3, '0', STR_PAD_LEFT) . '.sqlite',
                'path' => $p, 'ada' => is_file($p), 'code' => (string)$b['code']];
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 * BERKAS MEDIA (foto) — IKUT DIBACKUP
 * ------------------------------------------------------------------ *
 * Permintaan pemilik: "Integrasikan file upload foto itu ke semua yang
 * terintegrasi, seperti bagian backup — dalam bentuk metadata/path juga harus
 * terbackup seperti file foto rekam medis."
 *
 * Foto pasien/dokter/terapis dan lampiran klinis rekam medis disimpan sebagai
 * BERKAS di folder unggahan (di luar folder aplikasi), sedangkan basis datanya
 * hanya menyimpan nama/path. Karena itu backup yang hanya memuat basis data akan
 * kehilangan gambarnya saat dipulihkan di tempat lain.
 *
 * Di sini disediakan:
 *   · `backup_media_inventory()` — daftar SEMUA berkas media + apakah dirujuk
 *     basis data (metadata/path, ukuran, hash) — selalu ikut ke dalam paket
 *     sebagai `media.json`, walau berkas gambarnya tidak diikutkan.
 *   · berkas gambarnya sendiri IKUT ke dalam paket (folder `backup/media/…`)
 *     selama totalnya tidak melewati batas `backup_media_max_mb`.
 *   · pemulihan paket menuliskan kembali berkas media ke folder unggahan.
 * ------------------------------------------------------------------ */

/** Akar folder unggahan yang berlaku (satu sumber dengan aplikasi). */
function backup_uploads_root(): string
{
    return function_exists('local_upload_dir') ? local_upload_dir() : BACKUP_DIR . '/..';
}

/** Batas total ukuran media yang IKUT ke dalam paket backup (MB). 0 = tanpa media. */
function backup_media_max_mb(): int
{
    $v = (int)setting('backup_media_max_mb', '40');
    return max(0, min(500, $v));
}

/**
 * Ikutkan juga berkas media yang TIDAK dirujuk basis data (sisa berkas lama)?
 *
 * Bawaannya TIDAK: foto yang benar-benar dipakai sudah cukup untuk memulihkan
 * aplikasi, sedangkan berkas sisa (mis. hasil uji/unggahan yang dibatalkan) hanya
 * membuat paket backup membengkak. Daftar lengkapnya tetap dicatat di `media.json`.
 */
function backup_media_include_unused(): bool
{
    return setting('backup_media_include_unused', '0') === '1';
}

/**
 * Kumpulkan seluruh berkas media: foto orang, lampiran rekam medis, dan gambar
 * branding (logo/latar kartu/QRIS). Setiap baris menyebut apakah berkas itu
 * DIRUJUK basis data (dipakai) atau tidak — jadi metadata/path-nya tetap tercatat
 * pada backup walau berkasnya sendiri tidak diikutkan karena batas ukuran.
 *
 * @return array{files:array<int,array<string,mixed>>,total_bytes:int,used_bytes:int,
 *               jumlah:int,jumlah_tak_terpakai:int}
 */
function backup_media_inventory(): array
{
    $root = backup_uploads_root();
    if (!is_dir($root)) return ['files' => [], 'total_bytes' => 0, 'used_bytes' => 0,
        'jumlah' => 0, 'jumlah_tak_terpakai' => 0];

    /* --- Peta berkas yang DIRUJUK basis data: relatif → keterangan pemiliknya --- */
    $rujukan = [];
    $tambah = function (string $sub, $nama, string $oleh) use (&$rujukan) {
        $nama = basename((string)$nama);
        if ($nama === '') return;
        $rel = ($sub !== '' ? $sub . '/' : '') . $nama;
        if (isset($rujukan[$rel])) return;
        $rujukan[$rel] = $oleh;
    };
    foreach ([['patients', 'patient', 'Pasien'], ['doctors', 'doctor', 'Dokter'],
              ['therapists', 'therapist', 'Terapis']] as [$tbl, $kind, $label]) {
        $sub = ['patient' => 'pasien', 'doctor' => 'dokter', 'therapist' => 'terapis'][$kind];
        try {
            foreach (all("SELECT id, name, photo_file FROM {$tbl} WHERE photo_file IS NOT NULL AND photo_file <> ''") as $r) {
                $tambah($sub, $r['photo_file'], $label . ' #' . (int)$r['id'] . ' — ' . (string)$r['name']);
            }
        } catch (Throwable $e) { /* tabel/kolom tidak ada */ }
    }
    try {
        foreach (all("SELECT id, local_path, file_url FROM medical_record_photos") as $r) {
            if ((string)($r['local_path'] ?? '') !== '') $tambah('rekam-medis', basename((string)$r['local_path']), 'Foto rekam medis #' . (int)$r['id']);
            else {
                $u = (string)($r['file_url'] ?? '');
                if ($u !== '' && strpos($u, 'http') !== 0) $tambah('rekam-medis', basename($u), 'Foto rekam medis #' . (int)$r['id']);
            }
        }
    } catch (Throwable $e) { /* tabel belum ada */ }
    foreach (['logo_file' => 'Logo klinik', 'member_card_bg_file' => 'Latar kartu member',
              'pay_qris_file' => 'Gambar QRIS'] as $key => $label) {
        $val = (string)setting($key, '');
        if ($val !== '') $tambah('', $val, $label);
    }

    $files = [];
    $total = 0;
    $used = 0;
    $walk = function (string $dir, string $rel = '') use (&$walk, &$files, &$total, &$used, $rujukan) {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $abs = $dir . '/' . $f;
            $relNow = $rel !== '' ? $rel . '/' . $f : $f;
            if (is_dir($abs)) { $walk($abs, $relNow); continue; }
            if (!is_file($abs)) continue;
            $bytes = (int)filesize($abs);
            /* Berkas CACHE turunan (mis. `.raw` hasil pengecilan PNG dan
               `logo-….pdf.png`) tidak perlu ikut — dapat dibuat ulang otomatis. */
            $cache = (bool)preg_match('/\.(raw|c\d+-\d+-\d+\.raw)$/i', $f);
            $pemilik = $rujukan[$relNow] ?? '';
            if ($pemilik === '' && $cache) {
                $dasar = preg_replace('/\.(c\d+-\d+-\d+\.raw|raw)$/i', '', $f);
                $pemilik = $rujukan[($rel !== '' ? $rel . '/' : '') . $dasar] ?? '';
            }
            $terpakai = $pemilik !== '';
            $total += $bytes;
            if ($terpakai) $used += $bytes;
            $files[] = ['rel' => $relNow, 'abs' => $abs, 'bytes' => $bytes,
                'mtime' => (int)filemtime($abs), 'terpakai' => $terpakai,
                'rujukan' => $pemilik, 'cache' => $cache];
        }
    };
    $walk($root);
    usort($files, fn($a, $b) => strcmp($a['rel'], $b['rel']));
    return ['files' => $files, 'total_bytes' => $total, 'used_bytes' => $used,
        'jumlah' => count($files),
        'jumlah_tak_terpakai' => count(array_filter($files, fn($x) => empty($x['terpakai'])))];
}

/**
 * Daftar berkas media yang IKUT ke dalam paket (bila batas ukuran mencukupi).
 *
 * URUTAN PRIORITAS: berkas yang DIPAKAI (dirujuk basis data) lebih dulu, lalu —
 * hanya bila diizinkan setelan — berkas yang tidak dirujuk. Berkas CACHE (dapat
 * dibuat ulang otomatis) selalu dilewati supaya paket tidak membengkak.
 *
 * @return array{files:array<int,array>,bytes:int,dilewati:array<int,array>,max_mb:int,unused:bool}
 */
function backup_media_selection(): array
{
    $inv = backup_media_inventory();
    $maxBytes = backup_media_max_mb() * 1048576;
    $ikutUnused = backup_media_include_unused();
    $urut = $inv['files'];
    usort($urut, function ($a, $b) {
        $ta = !empty($a['terpakai']) ? 0 : 1;
        $tb = !empty($b['terpakai']) ? 0 : 1;
        if ($ta !== $tb) return $ta - $tb;
        return strcmp($a['rel'], $b['rel']);
    });
    $pilih = [];
    $dilewati = [];
    $bytes = 0;
    foreach ($urut as $f) {
        $boleh = $maxBytes > 0 && empty($f['cache']) && (!empty($f['terpakai']) || $ikutUnused);
        if (!$boleh || $bytes + $f['bytes'] > $maxBytes) { $dilewati[] = $f; continue; }
        $pilih[] = $f;
        $bytes += $f['bytes'];
    }
    return ['files' => $pilih, 'bytes' => $bytes, 'dilewati' => $dilewati,
        'max_mb' => backup_media_max_mb(), 'unused' => $ikutUnused];
}

/** Ringkasan media untuk ditampilkan di halaman Backup (tanpa memuat isinya). */
function backup_media_summary(): array
{
    $inv = backup_media_inventory();
    $sel = backup_media_selection();
    return [
        'jumlah' => (int)$inv['jumlah'],
        'total_bytes' => (int)$inv['total_bytes'],
        'used_bytes' => (int)$inv['used_bytes'],
        'tak_terpakai' => (int)$inv['jumlah_tak_terpakai'],
        'ikut' => count($sel['files']),
        'ikut_bytes' => (int)$sel['bytes'],
        'dilewati' => count($sel['dilewati']),
        'max_mb' => (int)$sel['max_mb'],
        'ikut_tak_terpakai' => !empty($sel['unused']),
    ];
}

/** Tulis berkas ZIP tanpa ekstensi zip (penulis ZIP mandiri). */
/**
 * Tulis berkas ZIP **TERKOMPRES** (penulis ZIP mandiri, tanpa ekstensi zip).
 *
 * KOMPRESI OTOMATIS (permintaan pemilik: "file paket lengkap masih cukup besar"):
 * setiap berkas DIMAMPATKAN dengan DEFLATE (`gzdeflate`, metode ZIP 8). Sebelumnya
 * penulis ini memakai metode STORE (0 = tanpa kompresi), sehingga paket berisi 3
 * basis data bisa mencapai ~19 MB padahal isinya sangat dapat dimampatkan.
 *
 * Aturan yang dijaga:
 *   • bila hasil mampat TIDAK lebih kecil dari aslinya (mis. berkas yang sudah
 *     terkompres), entri disimpan apa adanya (STORE) — tidak ada yang membengkak;
 *   • CRC32 & ukuran asli tetap dicatat sehingga pembaca ZIP biasa (unzip,
 *     Windows Explorer, 7-Zip) dapat membukanya seperti biasa;
 *   • penulis PENERJEMAH (`backup_package_read`) membaca KEDUA metode, jadi paket
 *     lama (versi STORE) tetap dapat dipulihkan.
 *
 * @return array{ok:bool,error:string,dimampatkan:int,asli:int,hasil:int}
 */
function backup_zip_create(string $zipPath, array $entries): array
{
    $fh = @fopen($zipPath, 'wb');
    if (!$fh) return ['ok' => false, 'error' => 'Gagal membuat berkas paket backup.',
        'dimampatkan' => 0, 'asli' => 0, 'hasil' => 0];
    $central = [];
    $offset = 0;
    $asli = 0; $hasil = 0; $dimampatkan = 0;
    /* Batas rasio keamanan ZIP64 dilewati dengan aman: bila keluaran mampat melebihi
       4 GB (tidak mungkin untuk basis data aplikasi ini) entri disimpan apa adanya. */
    $maxU = 0xFFFFFFFE;
    foreach ($entries as $nama => $isi) {
        $nama = str_replace('\\', '/', (string)$nama);
        $crc = crc32($isi);
        $len = strlen($isi);
        $mtime = getdate();
        $dosTime = (($mtime['hours'] << 11) | ($mtime['minutes'] << 5) | ($mtime['seconds'] >> 1)) & 0xffff;
        $dosDate = ((($mtime['year'] - 1980) << 9) | ($mtime['mon'] << 5) | $mtime['mday']) & 0xffff;
        /* ---- KOMPRESI ---- */
        $method = 0;
        $data = $isi;
        $csize = $len;
        if ($len > 0 && $len <= $maxU && function_exists('gzdeflate')) {
            $deflated = @gzdeflate($isi, 6);
            if ($deflated !== false && $deflated !== '' && strlen($deflated) < $len) {
                $method = 8;                 // 8 = DEFLATE
                $data = $deflated;
                $csize = strlen($deflated);
                $dimampatkan++;
            }
        }
        $asli += $len; $hasil += $csize;
        $local = "PK\x03\x04" . pack('v', 20) . pack('v', 0) . pack('v', $method) . pack('v', $dosTime)
            . pack('v', $dosDate) . pack('V', $crc) . pack('V', $csize) . pack('V', $len)
            . pack('v', strlen($nama)) . pack('v', 0) . $nama;
        fwrite($fh, $local);
        fwrite($fh, $data);
        $central[] = ['nama' => $nama, 'crc' => $crc, 'csize' => $csize, 'len' => $len,
            'method' => $method, 'off' => $offset, 'time' => $dosTime, 'date' => $dosDate];
        $offset += strlen($local) + $csize;
    }
    $cdStart = $offset;
    foreach ($central as $e) {
        $cd = "PK\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', $e['method'])
            . pack('v', $e['time']) . pack('v', $e['date']) . pack('V', $e['crc'])
            . pack('V', $e['csize']) . pack('V', $e['len']) . pack('v', strlen($e['nama']))
            . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('V', 0)
            . pack('V', $e['off']) . $e['nama'];
        fwrite($fh, $cd);
        $offset += strlen($cd);
    }
    $end = "PK\x05\x06" . pack('v', 0) . pack('v', 0) . pack('v', count($central))
        . pack('v', count($central)) . pack('V', $offset - $cdStart) . pack('V', $cdStart) . pack('v', 0);
    fwrite($fh, $end);
    fclose($fh);
    return ['ok' => true, 'error' => '', 'dimampatkan' => $dimampatkan,
        'asli' => $asli, 'hasil' => $hasil];
}

/**
 * Buat PAKET BACKUP lengkap (central + seluruh cabang + manifest + checksum + metadata).
 * Memakai sistem backup yang sudah ada (folder, kuota, riwayat) — bukan sistem kedua.
 */
function backup_create_package(string $note, ?int $userId = null, array $opts = []): array
{
    $enforce = $opts['enforce'] ?? true;
    $info = backup_storage_info();
    if ($enforce && $info['status'] === 'full') {
        $notice = backup_storage_notice($info);
        return ['ok' => false, 'file' => '', 'size' => 0, 'error' => $notice['message'], 'entries' => []];
    }
    $inv = backup_db_inventory();
    $ada = array_values(array_filter($inv, fn($x) => !empty($x['ada'])));
    if (!$ada) return ['ok' => false, 'file' => '', 'size' => 0, 'error' => 'Tidak ada basis data yang dapat dibackup.', 'entries' => []];

    $entries = [];
    $manifest = [];
    $checks = [];
    /* PENTING (WAL): tulis isi WAL ke berkas utama untuk central DAN setiap berkas
       cabang SEBELUM disalin. Tanpa ini, berkas cabang yang disalin bisa kehilangan
       transaksi terbaru (masih di `-wal`) — paket backup tampak "berhasil" tetapi
       isinya basi. */
    if (function_exists('db_checkpoint_all')) db_checkpoint_all();
    elseif (function_exists('ai_db_checkpoint')) ai_db_checkpoint();
    foreach ($ada as $db) {
        $isi = @file_get_contents($db['path']);
        if ($isi === false) continue;
        $nama = 'backup/' . $db['label'];
        $entries[$nama] = $isi;
        $manifest[] = ['kind' => $db['kind'], 'branch_id' => $db['branch_id'],
            'file' => $nama, 'bytes' => strlen($isi)];
        $checks[$nama] = hash('sha256', $isi);
    }
    if (!$entries) return ['ok' => false, 'file' => '', 'size' => 0,
        'error' => 'Basis data tidak dapat dibaca.', 'entries' => []];

    $meta = [
        'aplikasi' => function_exists('clinic_name') ? clinic_name() : 'Naveena',
        'kompresi' => 'DEFLATE (ZIP metode 8) — otomatis pada tiap berkas bila hasilnya lebih kecil',
        /* Versi skema diambil dari PEMERIKSAAN NYATA basis datanya (central →
           `settings.schema_version`; berkas cabang → `PRAGMA user_version`) supaya
           metadata paket tidak ikut menuliskan versi yang sudah tidak berlaku. */
        'schema_version' => backup_schema_version_text(),
        'arsitektur' => 'central_branch',
        'dibuat' => date('Y-m-d H:i:s'),
        'jumlah_database' => count($manifest),
        'jumlah_cabang' => count(array_filter($manifest, fn($m) => $m['kind'] === 'branch')),
        'catatan' => $note,
        'php' => PHP_VERSION,
    ];

    /* ---------------- BERKAS MEDIA (foto) IKUT DIBACKUP ----------------
       Metadata/path SELALU dicatat di `media.json` (walau berkas gambarnya tidak
       diikutkan karena batas ukuran), dan berkas gambarnya sendiri disalin ke
       `backup/media/…` supaya pemulihan di tempat lain tetap lengkap. */
    $mediaSel = backup_media_selection();
    $mediaMeta = [];
    foreach ($mediaSel['files'] as $f) {
        $isi = @file_get_contents($f['abs']);
        if ($isi === false) continue;
        $nama = 'backup/media/' . $f['rel'];
        $entries[$nama] = $isi;
        $checks[$nama] = hash('sha256', $isi);
        $mediaMeta[] = ['file' => $nama, 'rel' => $f['rel'], 'bytes' => $f['bytes'],
            'rujukan' => (string)$f['rujukan']];
    }
    $mediaInv = backup_media_inventory();
    $entries['media.json'] = json_encode([
        'keterangan' => 'Daftar SELURUH berkas media (foto) beserta rujukan basis datanya. '
            . 'Berkas yang ada di dalam paket berada di folder backup/media/.',
        'batas_mb' => backup_media_max_mb(),
        'jumlah_total' => count($mediaInv['files']),
        'total_bytes' => $mediaInv['total_bytes'],
        'ikut_ke_paket' => count($mediaMeta),
        'tidak_ikut' => array_map(fn($f) => ['rel' => $f['rel'], 'bytes' => $f['bytes'],
            'terpakai' => !empty($f['terpakai']), 'cache' => !empty($f['cache'])],
            $mediaSel['dilewati']),
        'berkas' => array_map(fn($f) => ['rel' => $f['rel'], 'bytes' => $f['bytes'],
            'terpakai' => !empty($f['terpakai']), 'cache' => !empty($f['cache']),
            'rujukan' => (string)$f['rujukan'],
            'sha256' => is_file($f['abs']) ? (string)hash_file('sha256', $f['abs']) : ''],
            $mediaInv['files']),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $meta['media'] = ['jumlah_total' => count($mediaInv['files']),
        'total_bytes' => $mediaInv['total_bytes'], 'ikut_ke_paket' => count($mediaMeta),
        'batas_mb' => backup_media_max_mb()];

    $entries['manifest.json'] = json_encode(['files' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $entries['checksums.json'] = json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $entries['metadata.json'] = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    [$dir, $file] = backup_file_path('naveena-backup-', '.zip');
    $tmp = $dir . '/.' . $file . '.part';
    $z = backup_zip_create($tmp, $entries);
    if (!$z['ok']) { @unlink($tmp); return ['ok' => false, 'file' => '', 'size' => 0, 'error' => $z['error'], 'entries' => []]; }
    /* VERIFIKASI: paket wajib dapat dibaca ulang dan isinya sama sebelum didaftarkan. */
    $back = backup_package_read($tmp);
    if (!$back['ok'] || count($back['entries']) < count($entries)) {
        @unlink($tmp);
        return ['ok' => false, 'file' => '', 'size' => 0,
            'error' => 'Paket backup gagal diverifikasi — backup dibatalkan.', 'entries' => []];
    }
    if (!@rename($tmp, $dir . '/' . $file)) {
        @unlink($tmp);
        return ['ok' => false, 'file' => '', 'size' => 0, 'error' => 'Gagal menyimpan paket backup.', 'entries' => []];
    }
    $size = (int)filesize($dir . '/' . $file);
    $catatanKomp = $z['asli'] > 0
        ? ' [paket · dimampatkan ' . (int)round((1 - ($z['hasil'] / max(1, $z['asli']))) * 100) . '%]'
        : ' [paket]';
    q('INSERT INTO backups (filename, size, note, created_by, kind, auto_run) VALUES (?,?,?,?,?,?)',
        [$file, $size, ($note !== '' ? $note : 'Paket lengkap basis data') . $catatanKomp, $userId, 'package',
         (string)($opts['auto_run'] ?? '')]);
    return ['ok' => true, 'file' => $file, 'size' => $size, 'error' => '', 'entries' => $manifest, 'meta' => $meta,
        'asli' => (int)$z['asli'], 'hasil' => (int)$z['hasil'], 'dimampatkan' => (int)$z['dimampatkan']];
}

/**
 * Baca kembali paket backup (penulis ZIP mandiri → pembacanya juga mandiri).
 * @return array{ok:bool,entries:array<string,string>,error:string}
 */
function backup_package_read(string $path): array
{
    $isi = @file_get_contents($path);
    if ($isi === false || substr($isi, 0, 2) !== 'PK') return ['ok' => false, 'entries' => [], 'error' => 'bukan paket zip yang sah'];
    $entries = [];
    $pos = 0; $len = strlen($isi);
    while ($pos + 30 <= $len) {
        $sig = substr($isi, $pos, 4);
        if ($sig === "PK\x03\x04") {
            $hdr = unpack('vver/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($isi, $pos + 4, 26));
            if (!$hdr) break;
            $nama = substr($isi, $pos + 30, $hdr['nlen']);
            /* Ukuran data di berkas = `csize` (SETELAH dimampatkan), sedangkan
               `usize` = ukuran asli. Memakai `usize` pada entri termampat membuat
               pembacaan entri berikutnya meleset dan isinya rusak — karena itu
               dibedakan sesuai metode (8 = DEFLATE, 0 = tanpa kompresi). */
            $mentah = substr($isi, $pos + 30 + $hdr['nlen'] + $hdr['elen'], (int)$hdr['csize']);
            $data = $mentah;
            if ((int)$hdr['method'] === 8) {
                $inflated = function_exists('gzinflate') ? @gzinflate($mentah) : false;
                if ($inflated === false) {
                    return ['ok' => false, 'entries' => [], 'error' => 'isi paket tidak dapat dibuka (kompresi rusak)'];
                }
                $data = $inflated;
            }
            $entries[$nama] = $data;
            $pos += 30 + $hdr['nlen'] + $hdr['elen'] + (int)$hdr['csize'];
            continue;
        }
        if ($sig === "PK\x01\x02") { $pos += 46; continue; }   // masuk central directory
        if ($sig === "PK\x05\x06") break;
        $pos++;
    }
    return ['ok' => (bool)$entries, 'entries' => $entries, 'error' => $entries ? '' : 'tidak ada berkas di dalam paket'];
}

/**
 * BACKUP SATU CABANG (permintaan pemilik): salinan terkompresi berkas basis data
 * cabang, sehingga bila satu cabang bermasalah cukup cabang ITU yang dipulihkan.
 *
 * Berisikan SELURUH isi basis data cabang (skema + data), bukan dump SQL — jadi
 * pemulihannya persis seperti paket lengkap, hanya untuk satu berkas.
 *
 * @return array{ok:bool,file:string,size:int,raw_size:int,error:string,branch_id:int}
 */
function backup_create_branch(int $branchId, string $note = '', ?int $userId = null, array $opts = []): array
{
    $enforce = $opts['enforce'] ?? true;
    $info = storage_info_or_default($enforce);
    if ($enforce && $info['status'] === 'full') {
        return ['ok' => false, 'file' => '', 'size' => 0, 'raw_size' => 0,
            'error' => backup_storage_notice($info)['message'], 'branch_id' => $branchId];
    }
    $path = db_branch_path($branchId);
    if (!is_file($path)) {
        return ['ok' => false, 'file' => '', 'size' => 0, 'raw_size' => 0,
            'error' => 'Basis data cabang belum ada.', 'branch_id' => $branchId];
    }
    /* Basis data mode WAL: checkpoint dulu supaya perubahan terbaru ikut tersalin. */
    try {
        $c = db_open($path);
        $c->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Throwable $e) { /* tetap lanjut — berkas -wal ikut digabung di bawah */ }
    $isi = @file_get_contents($path);
    if ($isi === false) {
        return ['ok' => false, 'file' => '', 'size' => 0, 'raw_size' => 0,
            'error' => 'Basis data cabang tidak dapat dibaca.', 'branch_id' => $branchId];
    }
    $raw = strlen($isi);
    $data = backup_compress($isi);
    $pad = str_pad((string)max(0, $branchId), 3, '0', STR_PAD_LEFT);
    [$dir, $file] = backup_file_path('naveena-cabang-' . $pad . '-', '.sqlite.gz');
    $tmp = $dir . '/.' . $file . '.part';
    if (@file_put_contents($tmp, $data) === false) {
        return ['ok' => false, 'file' => '', 'size' => 0, 'raw_size' => $raw,
            'error' => 'Gagal menulis berkas backup cabang.', 'branch_id' => $branchId];
    }
    /* VERIFIKASI: berkas wajib dapat dibaca ulang & berupa basis data SQLite yang sehat. */
    $cek = backup_branch_file_check($tmp);
    if (!$cek['ok']) {
        @unlink($tmp);
        return ['ok' => false, 'file' => '', 'size' => 0, 'raw_size' => $raw,
            'error' => 'Backup cabang gagal diverifikasi: ' . $cek['error'], 'branch_id' => $branchId];
    }
    if (!@rename($tmp, $dir . '/' . $file)) {
        @unlink($tmp);
        return ['ok' => false, 'file' => '', 'size' => 0, 'raw_size' => $raw,
            'error' => 'Gagal menyimpan berkas backup cabang.', 'branch_id' => $branchId];
    }
    $size = (int)filesize($dir . '/' . $file);
    $namaCabang = '';
    try { $b = one('SELECT name FROM branches WHERE id = ?', [$branchId]); $namaCabang = (string)($b['name'] ?? ''); }
    catch (Throwable $e) { /* nama opsional */ }
    q('INSERT INTO backups (filename, size, note, created_by, kind, branch_id, auto_run) VALUES (?,?,?,?,?,?,?)',
        [$file, $size,
         ($note !== '' ? $note . ' — ' : '') . 'Basis data cabang ' . ($namaCabang !== '' ? $namaCabang : $branchId)
            . ' (' . num(round($raw / 1048576, 2), 2) . ' MB → ' . num(round($size / 1048576, 2), 2) . ' MB)',
         $userId, 'branch', $branchId, (string)($opts['auto_run'] ?? '')]);
    return ['ok' => true, 'file' => $file, 'size' => $size, 'raw_size' => $raw, 'error' => '',
        'branch_id' => $branchId];
}

/** Info penyimpanan backup dengan pengaman (dipakai semua jalur pembuatan backup). */
function storage_info_or_default(bool $hitung = true): array
{
    try { return backup_storage_info(); }
    catch (Throwable $e) { return ['status' => 'ok', 'pct' => 0, 'bytes' => 0, 'limit' => 0, 'files' => 0]; }
}

/**
 * Periksa berkas backup cabang: harus berupa gzip (atau sqlite mentah) yang berisi
 * basis data SQLite sehat sehingga aman dipakai untuk pemulihan.
 *
 * @return array{ok:bool,error:string}
 */
function backup_branch_file_check(string $path): array
{
    $isi = backup_read_sql($path);          // menangani .gz maupun berkas mentah
    if (strlen($isi) < 100) return ['ok' => false, 'error' => 'isi berkas terlalu kecil'];
    if (substr($isi, 0, 16) !== "SQLite format 3\0") return ['ok' => false, 'error' => 'bukan berkas basis data SQLite'];
    $tmp = $path . '.cek';
    if (@file_put_contents($tmp, $isi) === false) return ['ok' => false, 'error' => 'tidak dapat menulis berkas pemeriksaan'];
    try {
        $p = new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $ic = (string)$p->query('PRAGMA integrity_check')->fetchColumn();
        $p = null;
        @unlink($tmp);
        return $ic === 'ok' ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'integritas: ' . $ic];
    } catch (Throwable $e) {
        @unlink($tmp);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * PULIHKAN SATU CABANG dari berkas backup cabang.
 *
 * Aman: (1) berkas backup diperiksa lebih dulu, (2) kondisi sekarang disalin sebagai
 * backup cabang baru ('sebelum-restore'), (3) penggantian memakai berkas sementara
 * lalu `rename()` sehingga tidak ada kondisi setengah jadi.
 *
 * @return array{ok:bool,error:string,snapshot:string}
 */
function backup_restore_branch(string $file, int $branchId, ?int $userId = null): array
{
    $src = backup_dir_ensure() . '/' . basename($file);
    if (!is_file($src)) return ['ok' => false, 'error' => 'Berkas backup tidak ditemukan di storage.', 'snapshot' => ''];
    $cek = backup_branch_file_check($src);
    if (!$cek['ok']) return ['ok' => false, 'error' => 'Berkas backup tidak layak dipulihkan: ' . $cek['error'], 'snapshot' => ''];
    $isi = backup_read_sql($src);
    $target = db_branch_path($branchId);
    if (!is_dir(dirname($target))) @mkdir(dirname($target), 0770, true);

    /* Snapshot pengaman kondisi SEKARANG (kalau ada isinya). */
    $snap = '';
    if (is_file($target) && (int)filesize($target) > 100) {
        $r = backup_create_branch($branchId, 'Snapshot otomatis sebelum restore cabang', $userId, ['enforce' => false]);
        $snap = $r['ok'] ? (string)$r['file'] : '';
    }
    $tmp = dirname($target) . '/.restore-' . $branchId . '-' . getmypid() . '.sqlite';
    if (@file_put_contents($tmp, $isi) === false) {
        return ['ok' => false, 'error' => 'Gagal menyiapkan berkas pemulihan.', 'snapshot' => $snap];
    }
    foreach (['', '-wal', '-shm'] as $akhiran) {
        if (is_file($target . $akhiran)) @unlink($target . $akhiran);
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'Gagal mengganti berkas basis data cabang.', 'snapshot' => $snap];
    }
    @chmod($target, 0664);
    /* Pastikan skema cabang terkini (migrasi + FK lintas berkas dibersihkan). */
    try {
        $GLOBALS['DB_SCHEMA_BRANCH_ID'] = $branchId;
        try { db_schema_apply($target, 'branch'); } finally { unset($GLOBALS['DB_SCHEMA_BRANCH_ID']); }
        db_branch_strip_cross_fk_tables($branchId);
        db_branch_apply_id_floor($branchId);
    } catch (Throwable $e) { /* dilaporkan lewat hasil pemulihan */ }
    return ['ok' => true, 'error' => '', 'snapshot' => $snap];
}

/**
 * PULIHKAN SELURUH BASIS DATA dari paket lengkap (zip central + cabang).
 *
 * @return array{ok:bool,error:string,restored:array<int,string>,snapshot:string}
 */
function backup_restore_package(string $file, ?int $userId = null): array
{
    $path = backup_dir_ensure() . '/' . basename($file);
    if (!is_file($path)) return ['ok' => false, 'error' => 'Berkas paket tidak ditemukan di storage.', 'restored' => [], 'snapshot' => ''];
    $val = backup_package_validate($path);
    if (!$val['ok']) return ['ok' => false, 'error' => 'Paket tidak lolos verifikasi: ' . $val['error'], 'restored' => [], 'snapshot' => ''];
    $read = backup_package_read($path);

    /* Snapshot pengaman: paket lengkap kondisi SEKARANG. */
    $snap = '';
    $s = backup_create_package('Snapshot otomatis sebelum restore paket', $userId, ['enforce' => false]);
    if (!empty($s['ok'])) $snap = (string)$s['file'];

    /* Tulis isi WAL ke berkas utama untuk central & seluruh berkas cabang SEBELUM
       apa pun diganti, lalu buang sisa `-wal`/`-shm` agar SQLite tidak memutar ulang
       transaksi lama saat berkas barunya dibuka. */
    $berkasDiganti = [];
    if (function_exists('db_checkpoint_all')) { try { db_checkpoint_all(); } catch (Throwable $e) { /* lanjut */ } }

    $dipulihkan = [];
    $dilewati = [];
    $mediaKembali = 0;
    foreach (($read['entries'] ?? []) as $nama => $isi) {
        /* ---------------- BERKAS MEDIA (foto) ----------------
           Ditulis kembali ke folder unggahan supaya foto pasien/dokter/terapis dan
           lampiran rekam medis TIDAK hilang saat data dipulihkan di tempat lain. */
        if (strpos((string)$nama, 'backup/media/') === 0) {
            $rel = substr((string)$nama, strlen('backup/media/'));
            $rel = str_replace('\\', '/', $rel);
            if ($rel === '' || strpos($rel, '..') !== false) continue;
            $tujuan = backup_uploads_root() . '/' . $rel;
            if (!is_dir(dirname($tujuan))) @mkdir(dirname($tujuan), 0775, true);
            $tmpM = $tujuan . '.part-' . getmypid();
            if (@file_put_contents($tmpM, $isi) !== false && @rename($tmpM, $tujuan)) {
                @chmod($tujuan, 0664);
                $mediaKembali++;
            } else {
                @unlink($tmpM);
            }
            continue;
        }
        if (strpos((string)$nama, 'backup/') !== 0) continue;
        $label = basename((string)$nama);
        if ($label === 'central.sqlite') {
            $target = db_central_path();
        } elseif (preg_match('/^branch_(\d+)\.sqlite$/', $label, $m)) {
            $target = db_branch_path((int)$m[1]);
        } else {
            continue;
        }
        if (strlen($isi) < 100) continue;
        /* PENGAMAN ISI PAKET: berkas basis data yang akan menggantikan basis data
           aplikasi WAJIB benar-benar SQLite dan punya tabel inti (settings + users).
           Tanpa pemeriksaan ini, paket yang keliru (pernah terjadi: berkas warisan
           `data.sqlite` berlabel "central.sqlite") menimpa basis data sebenarnya dan
           data global sesudahnya hilang. Lebih baik MENOLAK daripada menimpa. */
        $cek = backup_db_bytes_looks_valid($isi, $label === 'central.sqlite');
        if (!$cek['ok']) {
            $dilewati[] = $label . ': ' . $cek['alasan'];
            continue;
        }
        if (!is_dir(dirname($target))) @mkdir(dirname($target), 0770, true);
        $tmp = dirname($target) . '/.restore-pkg-' . getmypid() . '-' . $label;
        if (@file_put_contents($tmp, $isi) === false) continue;
        foreach (['', '-wal', '-shm'] as $akhiran) {
            if (is_file($target . $akhiran)) @unlink($target . $akhiran);
        }
        if (@rename($tmp, $target)) {
            @chmod($target, 0664);
            $dipulihkan[] = $label;
            $berkasDiganti[] = $target;
        }
    }
    /* JEBAKAN YANG SUDAH DIPERBAIKI (ronde 65): koneksi aplikasi yang masih terbuka
       memegang berkas (inode) LAMA beserta `-wal`-nya. Bila tidak dilepas, penulisan
       berikutnya dapat MEMUTAR ULANG WAL lama sehingga baris yang sudah dipulihkan
       muncul kembali (terbukti pada uji pemulihan: jumlah baris kembali seperti sebelum
       pemulihan). Karena itu: sisa `-wal`/`-shm` dibuang LAGI, lalu koneksi routed
       diminta dibangun ulang pada permintaan berikutnya. */
    if ($berkasDiganti && function_exists('db_route_clear_wal')) {
        foreach ($berkasDiganti as $t) db_route_clear_wal($t);
        if (function_exists('db_route_reset')) db_route_reset();
    }
    if ($mediaKembali > 0) $dipulihkan[] = $mediaKembali . ' berkas foto';
    /* Central ikut dipulihkan: pastikan skema & data contoh sistem siap. */
    try { db_central_registry_ready(); } catch (Throwable $e) { /* informatif */ }
    return ['ok' => (bool)$dipulihkan, 'error' => $dipulihkan ? '' : 'Tidak ada basis data yang dipulihkan dari paket.',
        'restored' => $dipulihkan, 'snapshot' => $snap, 'media' => $mediaKembali,
        'dilewati' => $dilewati];
}

/**
 * Periksa isi berkas basis data SEBELUM dipakai menimpa basis data aplikasi.
 *
 * Hanya berkas SQLite yang punya tabel inti yang diterima. Pemeriksaan dilakukan
 * pada berkas SEMENTARA (tidak menyentuh berkas aplikasi).
 *
 * @param bool $wajibInti untuk `central.sqlite`: tabel inti wajib ada DAN berkasnya
 *                        tidak boleh memuat data operasional (pasien/transaksi),
 *                        karena pada arsitektur central+branch data itu milik berkas
 *                        cabang. Berkas yang memuatnya berarti berkas WARISAN
 *                        (`data.sqlite` sebelum pemisahan) — memulihkannya akan
 *                        menimpa central dan menghilangkan perubahan terbaru.
 * @return array{ok:bool,alasan:string}
 */
function backup_db_bytes_looks_valid(string $isi, bool $wajibInti = true): array
{
    if (substr($isi, 0, 16) !== "SQLite format 3\0") {
        return ['ok' => false, 'alasan' => 'bukan berkas SQLite yang sah'];
    }
    $tmp = sys_get_temp_dir() . '/nv-bkpcek-' . bin2hex(random_bytes(4)) . '.sqlite';
    if (@file_put_contents($tmp, $isi) === false) return ['ok' => false, 'alasan' => 'berkas sementara gagal dibuat'];
    try {
        $p = new PDO('sqlite:' . $tmp);
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ic = (string)$p->query('PRAGMA integrity_check')->fetchColumn();
        if ($ic !== 'ok') return ['ok' => false, 'alasan' => 'integritas berkas tidak sehat (' . $ic . ')'];
        $tabel = (int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
        if ($tabel < 5) return ['ok' => false, 'alasan' => 'berkas hampir kosong (' . $tabel . ' tabel)'];
        if ($wajibInti) {
            $inti = (int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('settings','users','roles')")->fetchColumn();
            if ($inti < 3) return ['ok' => false, 'alasan' => 'tabel inti (settings/users/roles) tidak lengkap'];
            /* Data operasional TIDAK boleh ada di berkas central. */
            foreach (['patients', 'orders'] as $t) {
                $ada = (int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE name = '{$t}'")->fetchColumn();
                if (!$ada) continue;
                $n = (int)$p->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
                if ($n > 0) {
                    return ['ok' => false, 'alasan' => 'berkas ini memuat data ' . $t . ' (' . $n . ' baris) — '
                        . 'itu berkas WARISAN satu-basis-data, bukan central. Pemulihan dibatalkan supaya '
                        . 'basis data central yang dipakai sekarang tidak tertimpa.'];
                }
            }
        }
        return ['ok' => true, 'alasan' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'alasan' => 'berkas tidak dapat dibaca (' . $e->getMessage() . ')'];
    } finally {
        @unlink($tmp);
    }
}

/**
 * Kosongkan SELURUH baris sebuah tabel di SEMUA cabang (dipakai pemulihan dari dump).
 * `DELETE FROM t` biasa hanya mengenai satu cabang, sehingga pemulihan penuh butuh ini.
 */
function backup_clear_table(string $table): int
{
    $total = 0;
    if (db_route_scope_of($table) === 'branch') {
        /* SELURUH berkas cabang (bukan hanya yang ter-ATTACH): pada 10+ cabang,
           tabel yang tidak ter-ATTACH tidak akan dikosongkan oleh DELETE biasa —
           akibatnya pemulihan menyisakan baris lama di cabang tersebut. */
        foreach (db_route_branch_ids() as $bid) {
            $path = db_branch_path((int)$bid);
            if (!is_file($path)) continue;
            try {
                $c = db_open($path);
                $total += (int)$c->exec('DELETE FROM "' . $table . '"');
                $c = null;
            } catch (Throwable $e) { /* tabel belum ada di berkas itu */ }
        }
    } else {
        try { $total += (int)db()->exec('DELETE FROM "' . $table . '"'); } catch (Throwable $e) { /* - */ }
    }
    return $total;
}

/**
 * PULIHKAN DUMP SQL (central + setiap cabang) — per basis data, atomik.
 *
 * MENGAPA begini (FINAL AUDIT): pada arsitektur central + satu basis data per cabang,
 * satu koneksi aplikasi hanya dapat meng-ATTACH maksimum 9 berkas cabang (batas SQLite
 * yang tidak dapat dinaikkan). Pemulihan yang mengandalkan satu transaksi lintas berkas
 * karena itu:
 *   • meninggalkan cabang di luar batas ATTACH dengan ISI LAMA (pemulihan tampak
 *     berhasil padahal datanya tidak kembali), dan
 *   • menulis lewat koneksi terpisah SELAGI transaksi pemulihan terbuka — SQLite
 *     mengunci SELURUH basis data yang ter-ATTACH pada `BEGIN IMMEDIATE`, sehingga
 *     pengosongan tabel gagal tanpa pesan (kesalahannya ditelan pemanggil).
 *
 * Cara yang dipakai sekarang: setiap basis data dipulihkan lewat koneksinya SENDIRI
 * (`BEGIN IMMEDIATE` → kosongkan tabel bagian itu → sisipkan barisnya → `COMMIT`).
 * Dengan begitu berlaku untuk berapa pun cabangnya dan tidak ada data yang tertinggal.
 *
 * Dump lama tanpa penanda `@@BRANCH` tetap didukung: barisnya ditempatkan menurut nilai
 * `branch_id` pada pernyataan (perilaku sebelumnya).
 *
 * @return array{ok:bool,error:string,per_db:array<string,int>,total:int}
 */
function backup_restore_sql_dump(string $sql, ?int $userId = null): array
{
    $statements = backup_split_statements($sql);
    if (!$statements) return ['ok' => false, 'error' => 'Dump kosong.', 'per_db' => [], 'total' => 0];

    /* ---- Bagi pernyataan menjadi bagian per basis data ---- */
    $bagian = [];              // label => ['branch' => int|null, 'ddl' => [], 'insert' => []]
    $pakaiPenanda = false;
    $aktif = 'legacy';
    $bagian[$aktif] = ['branch' => null, 'ddl' => [], 'insert' => []];
    foreach ($statements as $st) {
        if (preg_match('/^@@BRANCH\s+(\d+)$/i', $st, $m)) {
            $pakaiPenanda = true;
            $bid = (int)$m[1];
            $aktif = $bid > 0 ? 'b' . $bid : 'central';
            if (!isset($bagian[$aktif])) $bagian[$aktif] = ['branch' => $bid > 0 ? $bid : null, 'ddl' => [], 'insert' => []];
            continue;
        }
        if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\[]?(\w+)/i', $st)) {
            $bagian[$aktif]['ddl'][] = $st;
            continue;
        }
        if (preg_match('/^\s*(INSERT|REPLACE)\b/i', $st)) $bagian[$aktif]['insert'][] = $st;
    }
    if (!$pakaiPenanda) {
        /* Dump lama: tempatkan menurut nilai `branch_id` pada pernyataan. */
        $peta = ['legacy' => ['branch' => 0, 'ddl' => [], 'insert' => $bagian['legacy']['ddl']]];
        foreach ($bagian['legacy']['insert'] as $st) {
            $bid = db_route_branch_from_sql($st, []);
            $tabel = null;
            if (preg_match('/^\s*(?:INSERT|REPLACE)\s+(?:OR\s+\w+\s+)?INTO\s+[`"\[]?(\w+)/i', $st, $m)) $tabel = $m[1];
            $kunci = ($tabel !== null && db_route_scope_of($tabel) === 'branch')
                ? 'b' . ($bid !== null && $bid > 0 ? $bid : 0)
                : 'central';
            if (!isset($bagian[$kunci])) $bagian[$kunci] = ['branch' => $kunci === 'central' ? null : (int)substr($kunci, 1),
                'ddl' => [], 'insert' => []];
            $bagian[$kunci]['insert'][] = $st;
        }
        unset($bagian['legacy']);
    }

    /* ---- Pulihkan satu basis data pada satu waktu ---- */
    $perDb = [];
    $total = 0;
    $gagal = [];
    foreach ($bagian as $label => $b) {
        if (!$b['insert'] && !$b['ddl']) continue;
        $bid = $b['branch'] ?? null;
        if ($bid !== null && (int)$bid <= 0) continue;                  // tidak dapat ditentukan
        $target = ($bid === null) ? db_central_path() : db_branch_path((int)$bid);
        if ($bid !== null && !is_file($target)) {
            db_branch_create((int)$bid);                                // cabang baru pada dump
        }
        if (!is_file($target)) { $gagal[] = $label . ' (berkas tidak ada)'; continue; }
        try {
            $pdo = db_open($target);
            /* Skema TIDAK diubah: dikelola ensure_schema() — pernyataan DDL dilewati. */
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                /* Kosongkan HANYA tabel yang ada di bagian ini (bukan seluruh tabel),
                   supaya tabel yang tidak ikut ke dump tidak ikut hilang. */
                $tabel = [];
                foreach ($b['ddl'] as $st) {
                    if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\[]?(\w+)/i', $st, $m)) {
                        $tabel[strtolower($m[1])] = true;
                    }
                }
                foreach ($b['insert'] as $st) {
                    if (preg_match('/^\s*(?:INSERT|REPLACE)\s+(?:OR\s+\w+\s+)?INTO\s+[`"\[]?(\w+)/i', $st, $m)) {
                        $tabel[strtolower($m[1])] = true;
                    }
                }
                foreach (array_keys($tabel) as $t) {
                    try { $pdo->exec('DELETE FROM "' . $t . '"'); } catch (Throwable $e) { /* tabel belum ada */ }
                }
                $n = 0;
                foreach ($b['insert'] as $st) {
                    try { $pdo->exec($st); $n++; }
                    catch (Throwable $e) { throw new RuntimeException(basename($target) . ': ' . $e->getMessage()); }
                }
                $pdo->exec('COMMIT');
                $perDb[$label] = $n;
                $total += $n;
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            } finally {
                try { $pdo->exec('PRAGMA foreign_keys = ON'); } catch (Throwable $e) { }
                /* Isi WAL ditulis ke berkas utama lalu dipotong: berkas basis data
                   dikembalikan dalam keadaan lengkap tanpa sisa WAL yang menggantung.
                   JANGAN menghapus berkas `-wal` secara paksa — koneksi aplikasi
                   (yang dibuka lebih dulu pada berkas yang sama) masih memegangnya,
                   dan menghapusnya membuat SQLite melaporkan "disk I/O error". */
                try { $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable $e) { }
            }
            $pdo = null;
        } catch (Throwable $e) {
            $gagal[] = $label . ': ' . $e->getMessage();
        }
    }

    /* Koneksi aplikasi DIBANGUN ULANG supaya membaca isi berkas yang baru saja
       dipulihkan (skema/versi maupun datanya). Berkas `-wal` TIDAK dihapus paksa —
       lihat catatan di dalam loop di atas. */
    if (function_exists('db_route_reset')) db_route_reset();
    if (function_exists('settings')) settings(true);

    if ($gagal) {
        return ['ok' => false, 'error' => 'Sebagian basis data gagal dipulihkan — ' . implode('; ', $gagal),
            'per_db' => $perDb, 'total' => $total];
    }
    return ['ok' => true, 'error' => '', 'per_db' => $perDb, 'total' => $total];
}

/** Verifikasi paket: manifest + checksum harus cocok. */
function backup_package_validate(string $path): array
{
    $r = backup_package_read($path);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'files' => [], 'meta' => []];
    $manifest = json_decode((string)($r['entries']['manifest.json'] ?? '{}'), true) ?: [];
    $checks = json_decode((string)($r['entries']['checksums.json'] ?? '{}'), true) ?: [];
    $meta = json_decode((string)($r['entries']['metadata.json'] ?? '{}'), true) ?: [];
    $salah = [];
    foreach ((array)($manifest['files'] ?? []) as $f) {
        $nama = (string)($f['file'] ?? '');
        if ($nama === '' || !isset($r['entries'][$nama])) { $salah[] = $nama . ' (tidak ada di paket)'; continue; }
        $harap = (string)($checks[$nama] ?? '');
        if ($harap !== '' && hash('sha256', $r['entries'][$nama]) !== $harap) $salah[] = $nama . ' (checksum tidak cocok)';
    }
    return ['ok' => !$salah && !empty($manifest['files']), 'error' => $salah ? implode('; ', $salah) : '',
        'files' => (array)($manifest['files'] ?? []), 'meta' => $meta];
}
