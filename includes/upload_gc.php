<?php
/**
 * PEMBERSIH BERKAS GAMBAR TIDAK TERPAKAI (folder unggahan)
 * =======================================================
 *
 * Folder unggahan (`local_upload_dir()`, mis. `../naveena_uploads`) menyimpan:
 *   - foto pasien/dokter/terapis/rekam medis  → ada di SUB-FOLDER (pasien/, dokter/,
 *     terapis/, rekam-medis/) dan selalu terkait baris database;
 *   - logo klinik & latar kartu member        → ada di AKAR folder dengan nama
 *     berawalan `logo-…` / `membercard…`, terkait lewat SETELAN
 *     (`logo_file`, `member_card_bg_file`);
 *   - cache turunan berkas di atas (`.c<tinggi>-<mtime>-<ukuran>.raw` dan `.pdf.png`)
 *     yang dibuat otomatis saat mencetak PDF.
 *
 * Setiap kali logo/latar diganti, berkas LAMA beserta cache-nya tidak lagi dipakai
 * siapa pun, tetapi tetap tertinggal di folder. Uji otomatis juga meninggalkan
 * berkas serupa. Ini yang membuat folder unggahan membengkak (pernah 78 MB dari
 * 105 MB hanya berisi berkas tak terpakai).
 *
 * Fungsi di sini hanya menyentuh berkas di AKAR folder yang namanya berawalan
 * `logo` / `membercard` DAN tidak dirujuk oleh setelan maupun kolom berkas di
 * database — foto pasien/staf/rekam medis di sub-folder tidak pernah diusik.
 * Berkas yang baru diubah (< 60 menit) selalu dilewati supaya unggahan yang
 * sedang berjalan tidak terhapus sebelum setelannya tersimpan.
 */
declare(strict_types=1);

/** Awalan nama berkas yang dikelola di sini (dihasilkan pengunggah logo/latar kartu). */
function upload_gc_prefixes(): array
{
    return ['logo', 'membercard'];
}

/**
 * Ubah nama cache → nama berkas induknya.
 * Contoh: `logo-ab.png.c175-1790602138-37616.raw` → `logo-ab.png`
 *         `logo-ab.png.pdf.png`                    → `logo-ab.png`
 */
function upload_gc_base_name(string $name): string
{
    if (preg_match('/^(.+)\.c\d+-\d+-\d+\.raw$/', $name, $m)) return $m[1];
    if (preg_match('/^(.+)\.pdf\.png$/i', $name, $m)) return $m[1];
    return $name;
}

/** Apakah berkas ini kandidat pembersihan (hanya berkas akar berawalan tertentu). */
function upload_gc_is_candidate(string $name): bool
{
    $base = upload_gc_base_name($name);
    foreach (upload_gc_prefixes() as $p) {
        if (strlen($base) > strlen($p) && stripos($base, $p) === 0
            && in_array($base[strlen($p)], ['-', '.', '_'], true)) return true;
    }
    return false;
}

/**
 * Semua nama berkas yang MASIH dipakai (setelan + kolom berkas di database),
 * digabung menjadi satu teks untuk pemeriksaan cepat.
 */
function upload_gc_referenced_haystack(): string
{
    $parts = [];
    foreach (settings(true) as $k => $v) {
        if (is_string($v) && $v !== '') $parts[] = $v;
    }
    /* Cache logo PDF & latar kartu aktif juga dirujuk lewat setelannya, jadi
       cukup nilai setelan. Tambahkan kolom berkas di database sebagai pengaman
       (mis. bila suatu saat nama berkas logo dipakai di tempat lain). */
    foreach ([
        ['patients', 'photo_file'], ['doctors', 'photo_file'], ['therapists', 'photo_file'],
        ['medical_record_photos', 'file_url'], ['medical_record_photos', 'local_path'],
        ['backups', 'filename'],
    ] as [$tbl, $col]) {
        try {
            foreach (all("SELECT DISTINCT {$col} AS v FROM {$tbl} WHERE {$col} IS NOT NULL AND {$col} <> '' LIMIT 20000") as $r) {
                $parts[] = (string)$r['v'];
            }
        } catch (Throwable $ex) {
            /* tabel/kolom tidak ada di database ini → lewati */
        }
    }
    return implode("\n", array_unique($parts));
}

/**
 * Pindai berkas di akar folder unggahan.
 *
 * @param bool $withList sertakan daftar berkas tak terpakai (untuk ditampilkan)
 * @return array{dir:string,prefixes:array,all_files:int,all_bytes:int,used_files:int,used_bytes:int,
 *               orphan_files:int,orphan_bytes:int,orphan:array<int,array{name:string,bytes:int,mtime:int,base:string,child:bool}>,
 *               young_files:int,subdirs:array<string,int>}|null
 */
function upload_gc_scan(bool $withList = true): ?array
{
    $dir = local_upload_dir();
    if (!is_dir($dir)) return null;
    $hay = strtolower(upload_gc_referenced_haystack());
    $now = time();
    $youngLimit = $now - 3600;              // berkas < 60 menit selalu dilewati

    $allFiles = 0; $allBytes = 0; $usedFiles = 0; $usedBytes = 0;
    $orphanFiles = 0; $orphanBytes = 0; $young = 0;
    $orphan = []; $subdirs = [];

    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . '/' . $name;
        if (is_dir($path)) {
            $n = 0;
            foreach (scandir($path) ?: [] as $f) {
                if ($f !== '.' && $f !== '..' && is_file($path . '/' . $f)) $n++;
            }
            $subdirs[$name] = $n;           // foto pasien/staf/RM — TIDAK pernah disentuh
            continue;
        }
        if (!is_file($path)) continue;
        if (!upload_gc_is_candidate($name)) continue;

        $size = (int)@filesize($path);
        $mtime = (int)@filemtime($path);
        $allFiles++; $allBytes += $size;

        $base = upload_gc_base_name($name);
        $isChild = ($base !== $name);
        /* Berkas induk & cache-nya dianggap terpakai bila namanya muncul di setelan/database. */
        $referenced = strpos($hay, strtolower($base)) !== false;
        if ($referenced || $mtime > $youngLimit) {
            if ($mtime > $youngLimit) $young++;
            $usedFiles++; $usedBytes += $size;
            continue;
        }
        $orphanFiles++; $orphanBytes += $size;
        if ($withList && count($orphan) < 5000) {
            $orphan[] = ['name' => $name, 'bytes' => $size, 'mtime' => $mtime,
                'base' => $base, 'child' => $isChild];
        }
    }
    /* Urutkan dari yang paling lama (perilaku daftar yang mudah dibaca). */
    usort($orphan, fn($a, $b) => $a['mtime'] <=> $b['mtime']);
    return [
        'dir' => $dir, 'prefixes' => upload_gc_prefixes(),
        'all_files' => $allFiles, 'all_bytes' => $allBytes,
        'used_files' => $usedFiles, 'used_bytes' => $usedBytes,
        'orphan_files' => $orphanFiles, 'orphan_bytes' => $orphanBytes,
        'orphan' => $orphan, 'young_files' => $young, 'subdirs' => $subdirs,
    ];
}

/**
 * Hapus berkas tak terpakai (hasil upload_gc_scan()).
 *
 * Dipakai tombol "Bersihkan Berkas Tidak Terpakai" di Developer Settings sehingga
 * pemilik klinik dapat membersihkannya sendiri tanpa bantuan pengembang.
 *
 * @return array{count:int,bytes:int,names:array<int,string>,failed:array<int,string>}
 */
function upload_gc_purge(): array
{
    $scan = upload_gc_scan(true);
    $out = ['count' => 0, 'bytes' => 0, 'names' => [], 'failed' => []];
    if (!$scan) return $out;
    $dir = $scan['dir'];
    foreach ($scan['orphan'] as $o) {
        $path = $dir . '/' . $o['name'];
        /* Pengaman terakhir: pastikan masih berkas akar dengan nama yang lolos pola. */
        if (basename($path) !== $o['name'] || !upload_gc_is_candidate($o['name']) || !is_file($path)) continue;
        if (@unlink($path)) {
            $out['count']++;
            $out['bytes'] += (int)$o['bytes'];
            if (count($out['names']) < 100) $out['names'][] = $o['name'];
        } else {
            $out['failed'][] = $o['name'];
        }
    }
    return $out;
}

/** Teks ukuran berkas yang ramah dibaca ("38 KB" / "1,2 MB"). */
function upload_gc_size(int $bytes): string
{
    if ($bytes >= 1048576) return num(round($bytes / 1048576, 1), 1) . ' MB';
    if ($bytes >= 1024) return num(round($bytes / 1024), 0) . ' KB';
    return $bytes . ' B';
}
