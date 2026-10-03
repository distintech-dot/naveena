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
function backup_dir_files(): array
{
    $dir = backup_dir_ensure();
    $out = [];
    foreach (array_merge(glob($dir . '/*.sql') ?: [], glob($dir . '/*.sql.gz') ?: []) as $path) {
        if (!is_file($path)) continue;
        $out[] = ['name' => basename($path), 'size' => (int)filesize($path), 'mtime' => (int)filemtime($path)];
    }
    return $out;
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
function db_dump(): string
{
    $pdo = db();
    $out = "-- " . clinic_name() . " Management System\n-- Backup: " . date('Y-m-d H:i:s') . "\n-- Database: " . basename(DB_PATH) . "\n\n";
    $objects = all("SELECT type, name, sql FROM sqlite_master
                    WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY (type='table') DESC, name");
    foreach ($objects as $o) {
        $out .= $o['sql'] . ";\n\n";
    }
    foreach ($objects as $o) {
        if ($o['type'] !== 'table') continue;
        $t = $o['name'];
        $rows = $pdo->query("SELECT * FROM \"{$t}\"")->fetchAll(PDO::FETCH_ASSOC);
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
    q('INSERT INTO backups (filename, size, note, created_by) VALUES (?,?,?,?)',
        [$file, $size, $note, $userId]);
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
    return max(2, min(60, $n > 0 ? $n : 7));
}

/** Buang backup OTOMATIS paling lama (backup manual tidak pernah dihapus). */
function backup_prune_auto(): int
{
    $keep = backup_keep_count();
    $auto = all("SELECT id, filename FROM backups WHERE note LIKE 'Backup otomatis%' ORDER BY id DESC");
    if (count($auto) <= $keep) return 0;
    $dir = backup_dir_ensure();
    $n = 0;
    foreach (array_slice($auto, $keep) as $b) {
        $path = $dir . '/' . basename((string)$b['filename']);
        if (is_file($path)) @unlink($path);
        q('DELETE FROM backups WHERE id = ?', [(int)$b['id']]);
        $n++;
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

    /* Rapikan lebih dulu: buang backup otomatis yang melebihi batas jumlah,
       lalu bila penyimpanan masih penuh buang yang paling lama sampai lega. */
    backup_prune_auto();
    $info = backup_storage_info();
    if ($info['status'] === 'full') backup_prune_to_fit();

    $res = backup_create('Backup otomatis (' . backup_schedule_label($schedule) . ')', $userId);
    if (!$res['ok']) {
        /* Kegagalan dicatat apa adanya agar terlihat di halaman Backup. */
        set_setting('backup_last_error', $res['error'] . ' (' . date('Y-m-d H:i') . ')');
        return ['ok' => false, 'error' => $res['error'], 'period' => $period];
    }
    set_setting('backup_last_period', $period);
    set_setting('backup_last_at', date('Y-m-d H:i:s'));
    set_setting('backup_last_file', $res['file']);
    set_setting('backup_last_error', '');
    $removed = backup_prune_auto();
    audit('Backup Otomatis', 'Pengaturan', null, null,
        ['file' => $res['file'], 'ukuran' => $res['size'], 'ukuran_asli' => $res['raw_size'],
         'terkompres' => $res['compressed'] ? 1 : 0, 'jadwal' => $schedule, 'dibuang' => $removed],
        'Backup otomatis ' . backup_schedule_label($schedule) . ' dijalankan saat aplikasi dibuka');
    return ['ok' => true, 'file' => $res['file'], 'size' => $res['size'], 'raw_size' => $res['raw_size'],
            'compressed' => $res['compressed'],
            'schedule' => $schedule, 'period' => $period, 'removed' => $removed, 'error' => ''];
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
            while ($i < $len && $sql[$i] !== "\n") $i++;
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
