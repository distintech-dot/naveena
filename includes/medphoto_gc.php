<?php
/**
 * PEMBERSIH FOTO REKAM MEDIS ELEKTRONIK
 * =====================================
 * Permintaan pemilik: di Developer Settings (di ATAS kartu "Hapus Semua Data")
 * tersedia tombol **Hapus/Bersihkan File Foto Rekam Medis** dengan skala pilihan:
 *   • hapus SEKARANG (semua foto rekam medis),
 *   • hapus foto yang sudah LAMA menurut periode (3 / 5 / 7 / 10 tahun),
 *   • hapus berkas yang **ketinggalan / sudah tidak terpakai** (sisa berkas di disk
 *     yang tidak lagi dirujuk catatan mana pun — termasuk berkas cache).
 *
 * ATURAN YANG DIJAGA:
 *   1. Berkas yang dihapus DIPASTIKAN sudah tidak dirujuk catatan lagi (untuk mode
 *      periode/sekarang, barisnya dihapus lebih dulu di dalam transaksi).
 *   2. Hanya berkas DI DALAM folder foto rekam medis yang disentuh — tidak pernah
 *      mengusik foto pasien/dokter/terapis, logo, QRIS, atau berkas lain.
 *   3. Setiap nama berkas diperiksa ulang dengan `basename()` tepat sebelum dihapus
 *      (jangan sampai path menembus ke luar folder).
 *   4. Hasilnya selalu dilaporkan apa adanya (jumlah catatan & berkas + ukuran).
 */

/** Pilihan mode pembersihan (kunci => label). */
function medphoto_gc_modes(): array
{
    return [
        'sekarang'     => 'Hapus semua foto rekam medis SEKARANG',
        'periode'      => 'Hapus foto yang lebih lama dari periode tertentu',
        'tak_terpakai' => 'Hanya berkas yang sudah tidak terpakai (sisa/ketinggalan)',
    ];
}

/** Pilihan periode (tahun) untuk mode 'periode'. */
function medphoto_gc_periods(): array
{
    return [3 => '3 tahun', 5 => '5 tahun', 7 => '7 tahun', 10 => '10 tahun'];
}

/** Folder foto rekam medis (selalu folder milik aplikasi ini). */
function medphoto_gc_dir(): string
{
    return photo_dir('medical');
}

/**
 * Semua baris foto rekam medis (cakupan seluruh cabang — kartu ini hanya untuk
 * Super Admin, dan berkasnya memang satu folder bersama).
 * Diambil dari VIEW gabungan sehingga otomatis memuat seluruh cabang.
 */
function medphoto_gc_rows(): array
{
    return all('/* cross-branch */ SELECT id, medical_record_id, file_url, local_path, storage, created_at, file_size
                FROM medical_record_photos ORDER BY id');
}

/** Nama berkas lokal sebuah baris foto ('' bila tidak ada berkas lokal). */
function medphoto_gc_row_file(array $r): string
{
    $nama = trim((string)($r['local_path'] ?? ''));
    if ($nama === '') {
        /* Sebagian baris lama hanya menyimpan nama berkas pada file_url. */
        $nama = (string)basename((string)parse_url((string)($r['file_url'] ?? ''), PHP_URL_PATH));
    }
    return $nama !== '' ? basename($nama) : '';
}

/**
 * Pemindaian: apa yang AKAN terhapus (tanpa mengubah apa pun).
 *
 * @return array{mode:string,years:int,rows:int,files:int,bytes:int,contoh:array<int,string>,
 *               berkas_luar:int,catatan:string}
 */
function medphoto_gc_scan(string $mode, int $years = 3): array
{
    if (!isset(medphoto_gc_modes()[$mode])) $mode = 'sekarang';
    $periods = medphoto_gc_periods();
    if ($mode === 'periode' && !isset($periods[$years])) $years = 3;

    $out = ['mode' => $mode, 'years' => $mode === 'periode' ? $years : 0, 'rows' => 0, 'files' => 0,
        'bytes' => 0, 'contoh' => [], 'berkas_luar' => 0, 'catatan' => ''];

    if ($mode === 'tak_terpakai') {
        $sisa = medphoto_gc_orphan_files();
        $out['files'] = count($sisa['files']);
        $out['bytes'] = $sisa['bytes'];
        $out['contoh'] = array_slice(array_map(fn($f) => $f['name'], $sisa['files']), 0, 30);
        $out['catatan'] = 'Berkas di folder foto rekam medis yang namanya TIDAK muncul pada catatan foto mana pun '
            . '(termasuk berkas cache hasil pengecilan gambar).';
        return $out;
    }

    $rows = medphoto_gc_rows();
    if ($mode === 'periode') {
        $batas = date('Y-m-d', strtotime('-' . $years . ' years'));
        $rows = array_values(array_filter($rows,
            fn($r) => substr((string)$r['created_at'], 0, 10) !== '' && substr((string)$r['created_at'], 0, 10) < $batas));
        $out['catatan'] = 'Foto dengan tanggal unggah LEBIH LAMA dari ' . date('d/m/Y', strtotime($batas)) . '.';
    } else {
        $out['catatan'] = 'SELURUH foto rekam medis (semua cabang) akan dihapus, termasuk berkasnya.';
    }

    $dir = medphoto_gc_dir();
    $out['rows'] = count($rows);
    foreach ($rows as $r) {
        $nama = medphoto_gc_row_file($r);
        if ($nama === '') continue;
        $path = $dir . '/' . $nama;
        if (is_file($path)) {
            $out['files']++;
            $out['bytes'] += (int)filesize($path);
            if (count($out['contoh']) < 30) $out['contoh'][] = $nama;
        }
    }
    $out['berkas_luar'] = (int)($rows ? count(array_filter($rows,
        fn($r) => trim((string)$r['local_path']) === '')) : 0);
    return $out;
}

/** Berkas di folder foto rekam medis yang tidak dirujuk catatan mana pun. */
function medphoto_gc_orphan_files(): array
{
    $dir = medphoto_gc_dir();
    if (!is_dir($dir)) return ['files' => [], 'bytes' => 0];

    /* Nama berkas yang MASIH dipakai — dari catatan foto DAN dari seluruh jenis foto
       lain (pasien/dokter/terapis) supaya tidak pernah salah membuang. */
    $dipakai = [];
    foreach (medphoto_gc_rows() as $r) {
        $n = medphoto_gc_row_file($r);
        if ($n !== '') $dipakai[$n] = true;
    }
    foreach (['patients' => 'photo_file', 'doctors' => 'photo_file', 'therapists' => 'photo_file'] as $t => $c) {
        foreach (all("SELECT {$c} AS f FROM {$t} WHERE COALESCE({$c},'') <> ''") as $r) {
            $n = basename((string)$r['f']);
            if ($n !== '') $dipakai[$n] = true;
        }
    }
    /* Berkas branding & QRIS juga disimpan di akar folder unggahan, bukan di sini —
       tetapi namanya tetap dihormati agar tidak pernah ikut terbuang. */
    foreach (['logo_file', 'member_card_bg_file', 'pay_qris_file'] as $k) {
        $v = basename((string)setting($k, ''));
        if ($v !== '') $dipakai[$v] = true;
    }

    $files = [];
    $bytes = 0;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = $dir . '/' . $f;
        if (!is_file($path)) continue;
        /* Nama cache buatan `png.php` berbentuk `<induk>.c<n>-<mtime>-<ukuran>.raw`.
           Cache dianggap sisa bila BERKAS INDUKNYA tidak dirujuk catatan mana pun —
           tanpa syarat induknya sudah terhapus (dulu induk yang masih ada membuat
           cache-nya dianggap terpakai, sehingga setelah induknya dibuang sebagai
           sisa, cache-nya tertinggal selamanya). */
        $induk = preg_replace('/\.c\d+-\d+-\d+\.raw$/', '', $f);
        if ($induk !== $f) {
            if (!isset($dipakai[$induk])) {
                $files[] = ['name' => $f, 'size' => (int)filesize($path)];
                $bytes += (int)filesize($path);
            }
            continue;
        }
        if (isset($dipakai[$f])) continue;
        $files[] = ['name' => $f, 'size' => (int)filesize($path)];
        $bytes += (int)filesize($path);
    }
    usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));
    return ['files' => $files, 'bytes' => $bytes];
}

/**
 * Jalankan pembersihan.
 *
 * @param int $userId pelaksana (dicatat untuk jejak; audit dilakukan pemanggil)
 * @return array{rows:int,files:int,bytes:int,catatan:string}
 */
function medphoto_gc_purge(string $mode, int $years = 3, int $userId = 0): array
{
    if (!isset(medphoto_gc_modes()[$mode])) $mode = 'sekarang';
    $dir = medphoto_gc_dir();
    $hasil = ['rows' => 0, 'files' => 0, 'bytes' => 0, 'catatan' => ''];

    /* ---- MODE: berkas tak terpakai (tidak menyentuh catatan sama sekali) ---- */
    if ($mode === 'tak_terpakai') {
        $sisa = medphoto_gc_orphan_files();
        foreach ($sisa['files'] as $f) {
            $path = $dir . '/' . basename($f['name']);      // basename WAJIB (jangan path menembus)
            if (is_file($path) && @unlink($path)) {
                $hasil['files']++;
                $hasil['bytes'] += (int)$f['size'];
            }
        }
        $hasil['catatan'] = 'Berkas sisa/ketinggalan dibersihkan; tidak ada catatan foto yang diubah.';
        return $hasil;
    }

    /* ---- MODE: sekarang / periode → hapus CATATAN lalu berkasnya ---- */
    $rows = medphoto_gc_rows();
    if ($mode === 'periode') {
        $batas = date('Y-m-d', strtotime('-' . $years . ' years'));
        $rows = array_values(array_filter($rows,
            fn($r) => substr((string)$r['created_at'], 0, 10) !== '' && substr((string)$r['created_at'], 0, 10) < $batas));
    }
    if (!$rows) {
        $hasil['catatan'] = 'Tidak ada foto rekam medis yang cocok dengan pilihan ini.';
        return $hasil;
    }

    $berkas = [];
    foreach ($rows as $r) {
        $n = medphoto_gc_row_file($r);
        if ($n !== '') $berkas[$n] = true;
    }

    /* Catatan dihapus lebih dulu, satu transaksi per berkas cabang (pernyataan tanpa
       pembatas cabang otomatis dipecah ke seluruh berkas cabang — lihat db_route). */
    db_route_write_branch_set(null);
    q('BEGIN IMMEDIATE');
    try {
        foreach ($rows as $r) {
            /* Penghapusan per id — id-nya berasal dari daftar SELURUH CABANG di atas
               (pernyataan tanpa pembatas cabang dipecah ke seluruh berkas cabang oleh
               lapisan routing). */
            q('/* cross-branch */ DELETE FROM medical_record_photos WHERE id = ?', [(int)$r['id']]);
            $hasil['rows']++;
        }
        q('COMMIT');
    } catch (Throwable $e) {
        try { q('ROLLBACK'); } catch (Throwable $x) { /* abaikan */ }
        throw $e;
    }

    /* Baru setelah catatannya benar-benar hilang, berkasnya dibuang — dan hanya bila
       memang TIDAK ada lagi baris (mana pun) yang menunjuk nama berkas itu. */
    $masih = [];
    foreach (medphoto_gc_rows() as $r) {
        $n = medphoto_gc_row_file($r);
        if ($n !== '') $masih[$n] = true;
    }
    foreach (array_keys($berkas) as $nama) {
        if (isset($masih[$nama])) continue;
        $path = $dir . '/' . basename($nama);
        if (!is_file($path)) continue;
        $ukuran = (int)filesize($path);
        if (@unlink($path)) {
            $hasil['files']++;
            $hasil['bytes'] += $ukuran;
        }
        /* Cache hasil pengecilan gambar milik berkas itu ikut dibuang. */
        foreach (glob($dir . '/' . basename($nama) . '.c*.raw') ?: [] as $c) {
            if (@unlink($c)) $hasil['files']++;
        }
    }
    $hasil['catatan'] = $mode === 'periode'
        ? 'Foto rekam medis yang lebih lama dari ' . $years . ' tahun dihapus beserta berkasnya.'
        : 'Seluruh foto rekam medis dihapus beserta berkasnya.';
    return $hasil;
}

/** Ringkasan keadaan folder foto rekam medis (untuk keterangan di kartu). */
function medphoto_gc_totals(): array
{
    $rows = medphoto_gc_rows();
    $dir = medphoto_gc_dir();
    $berkasDisk = 0;
    $bytesDisk = 0;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_file($p)) { $berkasDisk++; $bytesDisk += (int)filesize($p); }
    }
    return [
        'catatan' => count($rows),
        'berkas_disk' => $berkasDisk,
        'bytes_disk' => $bytesDisk,
        'tak_terpakai' => count(medphoto_gc_orphan_files()['files']),
        'folder' => $dir,
    ];
}
