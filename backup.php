<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/backup_lib.php';
/* platform_link() — tautan ke halaman Pro di akar host. */
require_once __DIR__ . '/includes/platform.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('backup.manage');
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'create') {
            $note = trim((string)($_POST['note'] ?? '')) ?: 'Backup manual';
            $res = backup_create($note, $user['id']);
            if (!$res['ok']) throw new RuntimeException($res['error']);
            audit('Backup Database', 'Pengaturan', null, null,
                ['file' => $res['file'], 'size' => $res['size'], 'raw_size' => $res['raw_size'],
                 'terkompres' => $res['compressed'] ? 1 : 0], 'Backup manual dibuat');
            flash('Backup dibuat: ' . $res['file'] . ' — ' . backup_size_text($res['size'], $res['raw_size'])
                . '. Berkas tetap dapat direstore/diunduh seperti biasa.');
        }
        if ($act === 'prune_old') {
            $r = backup_prune_to_fit();
            if ($r['count'] === 0) throw new RuntimeException('Tidak ada backup yang perlu dihapus — pemakaian penyimpanan masih di bawah ambang aman.');
            audit('Hapus Backup Lama', 'Pengaturan', null, null,
                ['berkas' => $r['count'], 'dibebaskan' => $r['freed'], 'daftar' => $r['names']],
                'Backup paling lama dihapus untuk mengosongkan penyimpanan');
            flash(num($r['count']) . ' backup paling lama dihapus (membebaskan '
                . num(round($r['freed'] / 1048576, 2), 2) . ' MB).'
                . ($r['targetReached'] ? ' Penyimpanan kembali di bawah ambang aman.' : ' Penyimpanan masih penuh — hapus backup lain yang tidak diperlukan.'),
                'warning');
            header('Location: backup.php');
            exit;
        }
        if ($act === 'delete') {
            $id = (int)$_POST['id'];
            $b = one('SELECT * FROM backups WHERE id = ?', [$id]);
            if (!$b) throw new RuntimeException('Data backup tidak ditemukan.');
            $path = backup_dir_ensure() . '/' . basename($b['filename']);
            if (is_file($path)) @unlink($path);
            q('DELETE FROM backups WHERE id = ?', [$id]);
            audit('Hapus Backup', 'Pengaturan', $id, $b, null, 'File backup dihapus');
            flash('Backup dihapus.');
        }
        if ($act === 'purge_orphans') {
            if (!is_super()) throw new RuntimeException('Membersihkan berkas backup hanya dapat dilakukan Super Admin.');
            $info = backup_orphan_files();
            if ($info['total'] === 0) throw new RuntimeException('Tidak ada berkas tak terdaftar untuk dibersihkan.');
            $n = backup_purge_orphans();
            audit('Bersihkan Berkas Backup', 'Pengaturan', null, null,
                ['berkas' => $n, 'ukuran' => $info['bytes']], 'Berkas backup tak terdaftar dibersihkan');
            flash(num($n) . ' berkas backup tak terdaftar dihapus (membebaskan '
                . num(round($info['bytes'] / 1048576, 1), 1) . ' MB). Backup yang terdaftar tidak disentuh.', 'warning');
            header('Location: backup.php');
            exit;
        }
        if ($act === 'create_package') {
            /* PAKET LENGKAP (ronde 54): central + SELURUH basis data cabang + manifest +
               checksum + metadata. Memakai sistem backup yang sama (folder, kuota,
               riwayat) — bukan sistem kedua. */
            $res = backup_create_package((string)($_POST['note'] ?? ''), (int)$user['id']);
            if (!$res['ok']) throw new RuntimeException($res['error']);
            audit('Backup Paket Lengkap', 'Backup', null, null,
                ['file' => $res['file'], 'database' => count($res['entries']),
                 'ukuran_asli' => (int)($res['asli'] ?? 0), 'ukuran_mampat' => (int)($res['hasil'] ?? 0)],
                'Paket backup central + cabang dibuat (terkompresi DEFLATE)');
            /* Laporan apa adanya: ukuran asli vs hasil setelah dimampatkan otomatis. */
            $asli = (int)($res['asli'] ?? 0);
            $hemat = $asli > 0 ? (int)round((1 - ((int)$res['hasil'] / max(1, $asli))) * 100) : 0;
            flash('Paket backup dibuat: ' . $res['file'] . ' (' . count($res['entries'])
                . ' basis data, ' . backup_size_text((int)$res['size']) . ')'
                . ($asli > 0 && $hemat > 0
                    ? ' — dimampatkan otomatis ' . $hemat . '% lebih kecil dari ' . backup_size_text($asli) . '.'
                    : '.'), 'success');
        }
        /* ---- PER-CABANG BACKUP & RESTORE (ronde 59) -------------------------------
           Pemilik ingin dapat memulihkan SATU cabang saja bila cabang itu bermasalah.
           Cadangan berupa salinan terkompresi berkas basis data cabang (skema + data),
           dan DIVERIFIKASI dapat dibaca ulang & sehat SEBELUM didaftarkan. */
        if ($act === 'backup_branch') {
            if (!is_super()) throw new RuntimeException('Backup per cabang hanya dapat dilakukan Super Admin.');
            $bid = (int)($_POST['branch_id'] ?? 0);
            if ($bid <= 0) throw new RuntimeException('Cabang tidak dipilih.');
            $note = trim((string)($_POST['note'] ?? '')) ?: 'Backup manual per cabang';
            $res = backup_create_branch($bid, $note, (int)$user['id']);
            if (!$res['ok']) throw new RuntimeException($res['error']);
            audit('Backup Basis Data Cabang', 'Backup', $bid, null,
                ['file' => $res['file'], 'size' => $res['size'], 'raw' => $res['raw_size']],
                'Cadangan basis data satu cabang dibuat');
            flash('Backup cabang dibuat: ' . $res['file'] . ' — '
                . backup_size_text((int)$res['size'], (int)$res['raw_size'])
                . '. Bila cabang ini bermasalah, pulihkan dari berkas ini saja.', 'success');
        }
        if ($act === 'restore_branch') {
            if (!is_super()) throw new RuntimeException('Restore basis data cabang hanya dapat dilakukan Super Admin.');
            $id = (int)($_POST['id'] ?? 0);
            $b = one('SELECT * FROM backups WHERE id = ?', [$id]);
            if (!$b) throw new RuntimeException('Data backup tidak ditemukan.');
            $bid = (int)($b['branch_id'] ?? 0);
            if ($bid <= 0 && preg_match('/^naveena-cabang-(\d+)-/', (string)$b['filename'], $m)) $bid = (int)$m[1];
            if ($bid <= 0) throw new RuntimeException('Berkas ini bukan cadangan sebuah cabang.');
            $r = backup_restore_branch((string)$b['filename'], $bid, (int)$user['id']);
            if (!$r['ok']) throw new RuntimeException('Restore cabang gagal: ' . $r['error']);
            audit('Restore Basis Data Cabang', 'Backup', $bid, null,
                ['file' => $b['filename'], 'snapshot' => $r['snapshot']],
                'Basis data satu cabang dipulihkan dari cadangan');
            flash('Basis data cabang DIPULIHKAN dari ' . $b['filename'] . '.'
                . ($r['snapshot'] !== '' ? ' Kondisi sebelumnya disimpan sebagai ' . $r['snapshot'] . '.' : '')
                . ' Muat ulang halaman agar data terbaru tampil.', 'success');
            header('Location: backup.php');
            exit;
        }
        if ($act === 'restore_package') {
            if (!is_super()) throw new RuntimeException('Restore paket hanya dapat dilakukan Super Admin.');
            $id = (int)($_POST['id'] ?? 0);
            $b = one('SELECT * FROM backups WHERE id = ?', [$id]);
            if (!$b) throw new RuntimeException('Data backup tidak ditemukan.');
            $r = backup_restore_package((string)$b['filename'], (int)$user['id']);
            if (!$r['ok']) throw new RuntimeException('Restore paket gagal: ' . $r['error']);
            audit('Restore Paket Lengkap', 'Backup', null, null,
                ['file' => $b['filename'], 'dipulihkan' => $r['restored'], 'snapshot' => $r['snapshot']],
                'Seluruh basis data (central + cabang) dipulihkan dari paket');
            flash('Paket DIPULIHKAN: ' . implode(', ', $r['restored']) . '.'
                . ($r['snapshot'] !== '' ? ' Kondisi sebelumnya disimpan sebagai ' . $r['snapshot'] . '.' : '')
                . ' Muat ulang halaman.', 'success');
            header('Location: backup.php');
            exit;
        }
        /* PEMERIKSAAN BASIS DATA (dipindah dari Developer Settings): membuat basis
           data cabang yang belum ada + memeriksa kesehatan seluruh basis data. */
        if ($act === 'db_branches') {
            if (!is_super()) deny('Hanya Super Admin.');
            $r = db_branches_ensure_all();
            flash('Basis data cabang: ' . num((int)$r['dibuat']) . ' baru dibuat, '
                . num((int)$r['diperiksa']) . ' diperiksa.', $r['ok'] ? 'success' : 'error');
            audit('Siapkan Basis Data Cabang', 'Database', null, null,
                ['dibuat' => (int)$r['dibuat'], 'diperiksa' => (int)$r['diperiksa']],
                'Pemeriksaan/pembuatan basis data cabang');
            header('Location: backup.php#database');
            exit;
        }
        if ($act === 'db_branch_cleanup') {
            /* BERSIHKAN TABEL GLOBAL DARI BERKAS CABANG (migrasi arsitektur):
               berkas cabang hanya boleh memuat data OPERASIONAL. Berkas buatan versi
               lama ikut membuat tabel global (kamus ICD 15.966 baris + pengaturan di
               setiap cabang) — tombol ini membuangnya. Snapshot pengaman dibuat lebih
               dulu dan penghapusan DITOLAK bila ada isi yang tidak ada di central. */
            if (!is_super()) deny('Hanya Super Admin.');
            $laporan = branch_globals_clean();
            if ($laporan['bersih']) {
                flash('Berkas cabang sudah ramping — tidak ada tabel global yang perlu dibuang.');
                header('Location: backup.php#database');
                exit;
            }
            $snap = backup_create_package('Snapshot sebelum membersihkan tabel global di berkas cabang',
                (int)$user['id'], ['enforce' => false]);
            if (!$snap['ok']) throw new RuntimeException('Snapshot pengaman gagal dibuat: ' . $snap['error']);
            $res = branch_global_purge(true);
            if (!$res['ok']) throw new RuntimeException($res['error'] . ' Snapshot pengaman: ' . $snap['file']);
            audit('Bersihkan Tabel Global di Berkas Cabang', 'Database', null,
                ['berkas_cabang' => count($res['cabang']), 'tabel_dibuang' => (int)$res['dibuang']],
                ['ukuran' => $res['ukuran'], 'snapshot' => $snap['file']],
                'Duplikasi data global (kamus ICD, pengaturan, biaya operasional) dibuang dari berkas cabang');
            flash('Berkas cabang dibersihkan: ' . num((int)$res['dibuang']) . ' tabel global dibuang · '
                . $res['ukuran'] . '. Snapshot pengaman: ' . $snap['file'] . '.', 'success');
            header('Location: backup.php#database');
            exit;
        }
        if ($act === 'db_health') {
            if (!is_super()) deny('Hanya Super Admin.');
            /* db_health_all() sekaligus menuliskan hasil pemeriksaannya ke db_registry
               (tanpa pemeriksaan tambahan) — panel & registry jadi tidak pernah
               menampilkan status yang berbeda. */
            $h = db_health_all();
            $rusak = [];
            foreach ($h as $k => $v) { if (empty($v['ok'])) $rusak[] = $k . ' (' . ($v['error'] ?: 'periksa') . ')'; }
            flash($rusak ? ('Health check: ' . count($rusak) . ' basis data perlu diperiksa — ' . implode(', ', array_slice($rusak, 0, 4)))
                        : ('Health check: seluruh ' . count($h) . ' basis data sehat (integritas ok, tanpa pelanggaran FK).'),
                $rusak ? 'warning' : 'success');
            audit('Health Check Basis Data', 'Database', null, null,
                ['diperiksa' => count($h), 'bermasalah' => count($rusak),
                 'registry' => db_registry_summary()],
                'Pemeriksaan integritas & FK (+ sinkronisasi registry)');
            header('Location: backup.php#registry');
            exit;
        }
        if ($act === 'db_registry') {
            /* SINKRONKAN REGISTRY (permintaan pemilik): menyegarkan versi skema,
               status migrasi, lokasi, ukuran, dan status kesehatan SETIAP basis data
               berdasarkan pemeriksaan NYATA. Tidak mengubah data operasional, tidak
               mengulang migrasi yang sudah selesai, dan tidak mereset basis data. */
            if (!is_super()) deny('Hanya Super Admin.');
            $r = db_registry_refresh(null, true);
            $sum = $r['ringkas'];
            $pesan = 'Registry disinkronkan: ' . num((int)$sum['jumlah']) . ' basis data diperiksa ulang.';
            if (!empty($sum['tertinggal'])) {
                $pesan .= ' Skema BELUM terkini di: ' . implode(', ', $sum['tertinggal']) . '.';
            }
            if (!empty($sum['gagal'])) {
                $pesan .= ' PERLU DIPERIKSA (gagal divalidasi): ' . implode(', ', $sum['gagal']) . '.';
            }
            if (empty($sum['tertinggal']) && empty($sum['gagal'])) {
                $pesan .= ' Seluruh basis data berstatus sehat & skema ' . SCHEMA_VERSION . ' terkini.';
            }
            flash($pesan, (empty($sum['gagal']) && empty($sum['tertinggal'])) ? 'success' : 'warning');
            audit('Sinkronkan Registry Basis Data', 'Database', null, null,
                ['jumlah' => (int)$sum['jumlah'], 'per_status' => $sum['per_status'],
                 'tertinggal' => $sum['tertinggal'], 'gagal' => $sum['gagal']],
                'Versi skema, status, ukuran & kesehatan seluruh basis data disegarkan dari pemeriksaan nyata');
            header('Location: backup.php#registry');
            exit;
        }
        if ($act === 'db_registry_clean') {
            /* Buang baris registry yang menunjuk basis data dari lokasi lain (sisa
               pemindahan folder / salinan uji). Basis data pemasangan ini tidak disentuh. */
            if (!is_super()) deny('Hanya Super Admin.');
            $n = db_registry_delete_foreign();
            flash($n > 0 ? ('Registry dirapikan: ' . num($n) . ' baris asing dibuang.')
                         : 'Tidak ada baris registry asing yang perlu dibuang.',
                'success');
            audit('Bersihkan Registry Asing', 'Database', null, null, ['dibuang' => $n],
                'Baris registry yang menunjuk basis data dari lokasi lain dibuang (bukan basis data pemasangan ini)');
            header('Location: backup.php#registry');
            exit;
        }
        if ($act === 'backup_cfg') {
            /* Diatur di panel Backup Database — khusus pemegang `backup.manage`
               (Super Admin). Direktur/Owner tidak boleh mengubahnya. */
            if (!has_perm('backup.manage')) {
                deny('Pengaturan Backup Database hanya dapat diubah oleh Super Admin.');
            }
            set_setting('backup_active', ($_POST['backup_active'] ?? '') === '1' ? '1' : '0');
            set_setting('backup_schedule', (string)($_POST['backup_schedule'] ?? 'harian'));
            $keep = (int)($_POST['backup_keep'] ?? 7);
            set_setting('backup_keep', (string)max(1, min(60, $keep > 0 ? $keep : 7)));
            /* CAKUPAN backup otomatis: paket lengkap, per cabang, atau keduanya. */
            $cakupan = (string)($_POST['backup_auto_scope'] ?? 'keduanya');
            if (!in_array($cakupan, ['paket', 'cabang', 'keduanya'], true)) $cakupan = 'keduanya';
            set_setting('backup_auto_scope', $cakupan);
            /* Batas total ukuran folder backup (MB). Bila tercapai, backup baru
               ditolak dengan pesan yang meminta menghapus backup lama dulu. */
            $maxMb = (int)($_POST['backup_max_mb'] ?? 500);
            set_setting('backup_max_mb', (string)max(50, min(20000, $maxMb > 0 ? $maxMb : 500)));
            /* Batas ukuran FOTO yang ikut ke dalam paket backup (0 = hanya metadata). */
            $mediaMb = (int)($_POST['backup_media_max_mb'] ?? 40);
            set_setting('backup_media_max_mb', (string)max(0, min(500, $mediaMb)));
            set_setting('backup_media_include_unused', ($_POST['backup_media_include_unused'] ?? '') === '1' ? '1' : '0');
            /* Langsung rapikan sekarang supaya batas jumlah benar-benar terasa saat
               diubah (dulu baru berlaku ketika jadwal berikutnya berjalan). */
            $dibuang = backup_prune_auto();
            audit('Ubah Pengaturan Backup', 'Pengaturan', null, null,
                ['aktif' => setting('backup_active'), 'batas_mb' => setting('backup_max_mb'),
                 'jumlah_disimpan' => setting('backup_keep'), 'cakupan' => setting('backup_auto_scope'),
                 'dibuang' => $dibuang],
                'Perubahan konfigurasi backup');
            flash('Jadwal backup disimpan. Cakupan: ' . backup_auto_scope_label(setting('backup_auto_scope')) . '. '
                . ($dibuang > 0
                    ? num($dibuang) . ' backup otomatis lama dihapus supaya jumlahnya tidak melebihi batas. '
                    : '')
                . 'Backup otomatis dijalankan saat aplikasi dibuka (server ini tidak menyediakan cron), '
                . 'sekali per periode jadwal' . (setting('backup_active') === '1' ? '.' : ' — saat ini NONAKTIF.'),
                $dibuang > 0 ? 'warning' : 'success');
        }        if ($act === 'restore') {
            if (!is_super()) throw new RuntimeException('Restore database hanya dapat dilakukan Super Admin.');
            $id = (int)$_POST['id'];
            $b = one('SELECT * FROM backups WHERE id = ?', [$id]);
            if (!$b) throw new RuntimeException('Data backup tidak ditemukan.');
            $path = backup_dir_ensure() . '/' . basename($b['filename']);
            if (!is_file($path)) throw new RuntimeException('File backup tidak ditemukan di storage.');
            /* backup_read_sql() mengenali berkas .sql.gz (terkompres) maupun .sql
               lama, jadi backup lama tetap dapat direstore. */
            $sql = backup_read_sql($path);
            if (trim($sql) === '') throw new RuntimeException('File backup kosong.');

            $snap = backup_create('Snapshot otomatis sebelum restore', $user['id'],
                ['prefix' => 'sebelum-restore-', 'enforce' => false]);
            if (!$snap['ok']) throw new RuntimeException('Snapshot pengaman sebelum restore gagal dibuat: ' . $snap['error']);
            $safety = $snap['file'];

            /* PEMULIHAN PADA ARSITEKTUR CENTRAL + PER CABANG:
               setiap basis data dipulihkan lewat koneksinya SENDIRI (satu transaksi per
               basis data) — lihat backup_restore_sql_dump(). Pendekatan ini berlaku untuk
               berapa pun jumlah cabangnya (batas ATTACH SQLite 10 basis data tidak lagi
               menjadi batas pemulihan) dan tidak lagi gagal tanpa pesan. */
            $hasil = backup_restore_sql_dump($sql, (int)$user['id']);
            if (!$hasil['ok']) throw new RuntimeException('Restore gagal: ' . $hasil['error']);
            $n = (int)$hasil['total'];
            db_route_write_branch_clear();
            /* Tabel `backups` ikut dikembalikan isi backup, sehingga baris snapshot
               pengaman tadi hilang dari daftar (berkasnya tetap ada). Didaftarkan
               ulang supaya berkas pengaman TIDAK dianggap "berkas tak terdaftar"
               dan tidak ikut dibersihkan oleh tombol Bersihkan. */
            if ((int)scalar('SELECT COUNT(*) FROM backups WHERE filename = ?', [$safety]) === 0) {
                q('INSERT INTO backups (filename, size, note, created_by, kind) VALUES (?,?,?,?,?)',
                    [$safety, (int)filesize(backup_dir_ensure() . '/' . $safety),
                     'Snapshot otomatis sebelum restore (didaftarkan ulang setelah restore)', $user['id'], 'sql']);
            }
            settings(true);
            audit('Restore Database', 'Pengaturan', $id, null, ['file' => $b['filename'], 'statements' => $n], 'Restore dari backup ' . $b['filename']);
            flash('Restore berhasil (' . $n . ' baris dimasukkan ke central + basis data cabang).'
                . ' Snapshot sebelum restore disimpan sebagai ' . $safety
                . '. Untuk memulihkan SATU cabang saja, pakai "Backup per Cabang" di atas.');
            header('Location: backup.php');
            exit;
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: backup.php');
    exit;
}

/* Download */
if (intval(gp('download')) > 0) {
    $b = one('SELECT * FROM backups WHERE id = ?', [(int)gp('download')]);
    if (!$b) {
        flash('Data backup tidak ditemukan.', 'error');
        header('Location: backup.php');
        exit;
    }
    $path = backup_dir_ensure() . '/' . basename($b['filename']);
    if (!is_file($path)) {
        flash('File backup tidak ada di storage.', 'error');
        header('Location: backup.php');
        exit;
    }
    audit('Download Backup', 'Pengaturan', (int)$b['id'], null, ['file' => $b['filename']], 'Unduh file backup');
    $gz = strtolower(substr((string)$b['filename'], -3)) === '.gz';
    header('Content-Type: ' . ($gz ? 'application/gzip' : 'application/sql'));
    header('Content-Disposition: attachment; filename="' . basename($b['filename']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/* Verifikasi kelengkapan: semua tabel beserta jumlah barisnya, supaya Super Admin
   dapat memastikan tidak ada data yang terlewat dari backup. */
$tableCheck = [];
foreach (all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $t) {
    $n = (int)scalar('SELECT COUNT(*) FROM "' . $t['name'] . '"');
    $tableCheck[$t['name']] = $n;
}
$totalRows = array_sum($tableCheck);

$rows = all('SELECT b.*, u.name AS user_name FROM backups b LEFT JOIN users u ON u.id=b.created_by ORDER BY b.id DESC LIMIT 60');
/* Ukuran basis data AKTIF = central + seluruh berkas cabang (bukan satu berkas). */
$dbInv = function_exists('backup_db_inventory') ? backup_db_inventory() : [];
$dbSize = 0;
$dbJumlah = 0;
foreach ($dbInv as $d) { if (!empty($d['ada'])) { $dbSize += (int)filesize((string)$d['path']); $dbJumlah++; } }
$routeRep = function_exists('db_route_report') ? db_route_report() : [];
$store = backup_storage_info();
$notice = backup_storage_notice($store);
/* Ringkasan BERKAS MEDIA (foto) — metadata/path-nya selalu dicatat pada paket backup.
   Ditampilkan di kartu Jadwal Backup supaya pemilik tahu berapa berkas yang ikut. */
$mediaInfo = function_exists('backup_media_summary') ? backup_media_summary()
    : ['jumlah' => 0, 'total_bytes' => 0, 'ikut' => 0, 'ikut_bytes' => 0, 'dilewati' => 0,
       'max_mb' => 0, 'tak_terpakai' => 0, 'used_bytes' => 0];
/* Perkiraan ruang yang dibutuhkan backup berikutnya ≈ ukuran backup terbesar
   yang tersimpan (dipakai untuk memberi tahu apakah backup masih muat). */
$lastSize = 0;
foreach ($rows as $r) $lastSize = max($lastSize, (int)$r['size']);
if ($lastSize === 0) foreach (backup_dir_files() as $f) $lastSize = max($lastSize, (int)$f['size']);

page_head('Backup Database', 'backup');
?>
<div class="page-head">
  <div><h2>Backup Database</h2><p class="muted">Snapshot manual berisi skema dan seluruh data operasional — otomatis dipadatkan (gzip) agar hemat penyimpanan.</p></div>
</div>

<?php if ($notice['tone'] !== 'ok'): ?>
<div class="alert <?= $notice['tone'] === 'full' ? 'alert-error' : 'alert-warning' ?>">
  <strong><?= $notice['tone'] === 'full' ? '⚠ Penyimpanan Backup Penuh' : '⚠ Penyimpanan Backup Hampir Penuh' ?></strong><br>
  <?= e($notice['message']) ?>
  <div class="progress mt-2" style="max-width:420px"><i style="width:<?= (int)min(100, round($store['pct'])) ?>%"></i></div>
  <?php if ($store['files'] > 0): ?>
    <div class="flex gap-sm mt-2 flex-wrap">
      <form method="post" data-confirm="Hapus backup PALING LAMA satu per satu sampai penyimpanan kembali di bawah 80%? Backup yang dihapus tidak dapat dikembalikan.">
        <?= csrf_field() ?><input type="hidden" name="action" value="prune_old">
        <button class="btn btn-sm btn-danger" type="submit"><?= icon('database') ?> Hapus Backup Lama</button>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid g3">
  <div class="stat"><span class="lbl">Ukuran Database Aktif</span><span class="val"><?= num(round($dbSize / 1048576, 1), 1) ?> MB</span>
    <span class="sub">central + <?= num($dbJumlah - 1) ?> basis data cabang</span></div>
  <div class="stat"><span class="lbl">Backup Tersimpan</span><span class="val"><?= num(count($rows)) ?></span><span class="sub">terkompres (gzip) — hemat ruang</span></div>
  <div class="stat <?= setting('backup_active') === '1' ? 'leaf' : '' ?>"><span class="lbl">Backup Otomatis</span>
    <span class="val"><?= setting('backup_active') === '1' ? 'Aktif' : 'Nonaktif' ?></span>
    <span class="sub"><?php if (setting('backup_active') !== '1'): ?>
        Atur di Developer Settings → Backup Database.
      <?php else: ?>
        Jadwal <?= e(backup_schedule_label((string)setting('backup_schedule', 'harian'))) ?>
        · dijalankan otomatis saat aplikasi dibuka (server ini tidak menyediakan cron)
        <?php if (setting('backup_last_at') !== ''): ?><br>Terakhir: <?= e(tgl(setting('backup_last_at'), true)) ?>
          <?= setting('backup_last_file') !== '' ? '(' . e(setting('backup_last_file')) . ')' : '' ?>
        <?php else: ?><br>Belum pernah berjalan sejak diaktifkan.<?php endif; ?>
        <?php if (setting('backup_last_error') !== ''): ?>
          <br><span style="color:var(--danger,#c62828)">Gagal terakhir: <?= e(setting('backup_last_error')) ?></span>
        <?php endif; ?>
      <?php endif; ?></span></div>
</div>

<?php /* ================= BACKUP PER CABANG (ronde 59) =================
   Permintaan pemilik: bila salah satu cabang bermasalah, cukup cabang ITU yang
   dipulihkan. Cadangannya adalah salinan utuh berkas basis data cabang. */ ?>
<div class="card" id="pengaturan-backup">
  <div class="card-head"><h3>Jadwal Backup Database</h3>
    <span><?= badge('Khusus Super Admin', 'pink') ?></span>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="backup_cfg">
    <input type="hidden" name="_anchor" value="pengaturan-backup">
    <div class="card-body">
      <div class="form-grid g2">
        <div class="field"><label>Backup Otomatis</label>
          <select class="input" name="backup_active">
            <option value="0"<?= setting('backup_active') === '0' ? ' selected' : '' ?>>Nonaktif</option>
            <option value="1"<?= setting('backup_active') === '1' ? ' selected' : '' ?>>Aktif</option>
          </select></div>
        <div class="field"><label>Jadwal Backup</label>
          <select class="input" name="backup_schedule">
            <?php foreach (['harian' => 'Harian', 'mingguan' => 'Mingguan', 'bulanan' => 'Bulanan'] as $k => $v): ?>
              <option value="<?= $k ?>"<?= setting('backup_schedule') === $k ? ' selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select></div>
        <?php /* CAKUPAN: pemilik ingin jadwal dapat membuat backup PER CABANG
                 (supaya satu cabang bisa dipulihkan sendiri) DAN paket lengkap. */ ?>
        <div class="field"><label>Cakupan Backup Otomatis</label>
          <select class="input" name="backup_auto_scope">
            <?php foreach (['keduanya' => 'Keduanya — paket lengkap + satu berkas tiap cabang',
                            'paket' => 'Paket lengkap saja (central + semua cabang dalam 1 berkas)',
                            'cabang' => 'Per cabang saja (satu berkas tiap cabang)'] as $k => $v): ?>
              <option value="<?= $k ?>"<?= backup_auto_scope() === $k ? ' selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Semua berkas disimpan <strong>terkompresi</strong> (paket = ZIP, per cabang = .sqlite.gz),
            tetap dapat <strong>diunduh</strong> dan <strong>dipulihkan</strong> dari daftar Riwayat Backup.</span></div>
        <div class="field"><label>Jumlah Backup Otomatis yang Disimpan</label>
          <input class="input" type="text" inputmode="numeric" name="backup_keep"
                 value="<?= e(setting('backup_keep', '7')) ?>">
          <span class="hint">1–60. Dihitung per <strong>SET</strong> (satu kali jadwal berjalan — bila cakupannya
            “keduanya”, satu set = 1 paket + 1 berkas tiap cabang). Set terlama dihapus otomatis begitu
            jumlahnya melewati batas ini, dan berlaku <strong>langsung</strong> saat disimpan.
            Backup manual &amp; snapshot pengaman tidak pernah dihapus di sini.</span></div>
        <div class="field"><label>Ukuran Maksimal Foto dalam Paket Backup (MB)</label>
          <input class="input" type="text" inputmode="numeric" name="backup_media_max_mb"
                 value="<?= e(setting('backup_media_max_mb', '40')) ?>">
          <span class="hint">0–500 MB. Foto pasien/dokter/terapis dan lampiran rekam medis disimpan sebagai
            <strong>berkas</strong> di luar basis data, jadi supaya paket backup dapat memulihkan gambarnya
            berkas itu ikut ke dalam paket (folder <code>backup/media/</code>). Isi 0 bila hanya ingin
            <strong>metadata/path</strong>-nya saja yang dicatat (<code>media.json</code>) — daftar lengkap
            tetap selalu tersimpan, jadi tahu berkas mana yang belum terbawa.
            Saat ini: <strong><?= num($mediaInfo['ikut']) ?></strong> dari <?= num($mediaInfo['jumlah']) ?> berkas
            (±<strong><?= e(num(round($mediaInfo['ikut_bytes'] / 1048576, 2), 2)) ?> MB</strong> dari
            <?= e(num(round($mediaInfo['total_bytes'] / 1048576, 2), 2)) ?> MB).</span>
          <label class="check mt-1"><input type="checkbox" name="backup_media_include_unused" value="1"
            <?= setting('backup_media_include_unused', '0') === '1' ? 'checked' : '' ?>>
            <span>Ikutkan juga berkas foto yang <strong>tidak dipakai</strong> (sisa berkas lama)</span></label>
          <span class="hint">Bawaannya tidak dicentang: foto yang benar-benar dipakai sudah cukup untuk memulihkan
            aplikasi. Daftar lengkap berkas tetap dicatat di <code>media.json</code>.</span></div>
        <div class="field"><label>Batas Ukuran Folder Backup (MB)</label>
          <input class="input" type="text" inputmode="numeric" name="backup_max_mb"
                 value="<?= e(setting('backup_max_mb', '500')) ?>">
          <span class="hint">50–20.000 MB. Dipakai untuk mengingatkan saat penyimpanan mendekati penuh:
            pada 80% muncul peringatan, pada 100% backup baru ditolak sampai backup lama dihapus.
            Ruang penyimpanan aplikasi pada paket standar 1 GB (10 GB bila berlangganan Pro), dan backup
            ikut memakai ruang tersebut — jadi atur batas ini dengan menyisakan ruang untuk data aplikasi.
            Pemakaian terkini tampil pada kartu <strong>Pemakaian Penyimpanan Backup</strong> di halaman ini.</span></div>
      </div>
      <div class="notice mt-2">
        <strong>Server ini tidak menyediakan cron</strong>, jadi backup otomatis dijalankan
        <strong>saat aplikasi dibuka</strong> (dicek setiap ada yang login) dan hanya sekali per periode jadwal —
        sama seperti pengiriman laporan email otomatis. Backup manual per cabang dan paket lengkap
        tersedia di halaman ini juga.
        <br><br>
        <strong>Kompresi otomatis:</strong> setiap berkas backup (paket lengkap maupun per cabang,
        manual maupun otomatis) disimpan <strong>terkompresi</strong> sehingga jauh lebih kecil, dan
        setiap berkas diverifikasi ulang (dapat dibaca &amp; sehat) sebelum dianggap siap pakai —
        jadi tetap dapat diunduh dan dipulihkan tanpa kerusakan.
        <?php if (setting('backup_active') === '1' && setting('backup_last_at') !== ''): ?>
          <div class="small mt-1">Terakhir dijalankan: <strong><?= e(tgl(setting('backup_last_at'), true)) ?></strong>
            <?= setting('backup_last_file') !== '' ? '· <code>' . e(setting('backup_last_file')) . '</code>' : '' ?></div>
        <?php elseif (setting('backup_active') === '1'): ?>
          <div class="small mt-1">Belum pernah berjalan — akan dijalankan otomatis pada login berikutnya.</div>
        <?php endif; ?>
        <?php if (setting('backup_last_error') !== ''): ?>
          <div class="small mt-1" style="color:var(--danger,#c62828)">Gagal terakhir: <?= e(setting('backup_last_error')) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)"><button class="btn btn-primary" type="submit">Simpan Jadwal Backup</button></div>
  </form>
</div>


<div class="card mt-2" id="percabang">
  <div class="card-head">
    <h3>Backup per Cabang</h3>
    <span><?= badge('Khusus Super Admin', 'pink') ?></span>
  </div>
  <div class="card-body">
    <p class="muted">Setiap cabang memiliki basis data sendiri (<code>branch_00N.sqlite</code>). Cadangan di bawah
      berisi <strong>seluruh isi satu cabang</strong> (skema + data), sehingga bila cabang itu bermasalah
      cukup cabang tersebut yang dipulihkan — cabang lain tidak tersentuh.</p>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Cabang</th><th>Berkas Basis Data</th><th>Ukuran</th><th>Kesehatan</th><th>Cadangan Terakhir</th><th></th></tr></thead>
        <tbody>
        <?php
        $branchRows = [];
        foreach (branches() as $br) {
            $bid = (int)$br['id'];
            $p = db_branch_path($bid);
            $h = is_file($p) ? db_health($p) : ['ok' => false, 'integrity' => '', 'fk' => 0, 'error' => 'belum dibuat', 'tables' => 0];
            $terakhir = one('SELECT filename, created_at FROM backups WHERE kind = ? AND branch_id = ? ORDER BY id DESC LIMIT 1',
                ['branch', $bid]);
            $branchRows[] = ['id' => $bid, 'nama' => (string)$br['name'], 'berkas' => basename($p),
                'ada' => is_file($p), 'ukuran' => is_file($p) ? (int)filesize($p) : 0,
                'sehat' => !empty($h['ok']), 'fk' => (int)($h['fk'] ?? 0), 'tabel' => (int)($h['tables'] ?? 0),
                'terakhir' => $terakhir ? (string)$terakhir['filename'] . ' · ' . tgl((string)$terakhir['created_at'], true) : ''];
        }
        foreach ($branchRows as $b): ?>
          <tr>
            <td><strong><?= e($b['nama']) ?></strong></td>
            <td class="small"><code><?= e($b['berkas']) ?></code></td>
            <td class="small nowrap"><?= $b['ada'] ? num(round($b['ukuran'] / 1048576, 2), 2) . ' MB' : '—' ?></td>
            <td class="small"><?= !$b['ada'] ? badge('belum dibuat', 'gray')
              : (badge($b['sehat'] ? 'sehat' : 'perlu diperiksa', $b['sehat'] ? 'green' : 'yellow')
                 . ' <span class="muted">' . num($b['tabel']) . ' tabel · FK ' . num($b['fk']) . '</span>') ?></td>
            <td class="small muted"><?= $b['terakhir'] !== '' ? e($b['terakhir']) : 'belum pernah' ?></td>
            <td class="nowrap">
              <?php if (is_super() && $b['ada']): ?>
                <form method="post" class="inline-form">
                  <?= csrf_field() ?><input type="hidden" name="action" value="backup_branch">
                  <input type="hidden" name="branch_id" value="<?= (int)$b['id'] ?>">
                  <input type="hidden" name="note" value="Backup manual cabang">
                  <button class="btn btn-sm btn-primary" type="submit"><?= icon('database') ?> Backup Cabang Ini</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="notice small mt-2">Pemulihan dilakukan dari <strong>Riwayat Backup</strong> di atas
      (tombol <em>Restore Cabang</em> pada cadangan berlabel &ldquo;Basis data cabang&rdquo;).
      Kondisi cabang sebelum dipulihkan otomatis disimpan sebagai cadangan baru sehingga pemulihan
      masih dapat dibatalkan.</div>
  </div>
</div>

<div class="card mt-2">
  <div class="card-head"><h3>Pemakaian Penyimpanan Backup</h3>
    <span><?= $store['status'] === 'full' ? badge('Penuh', 'pink') : ($store['status'] === 'warn' ? badge('Hampir penuh', 'yellow') : badge('Aman', 'green')) ?></span></div>
  <div class="card-body">
    <div class="flex gap-sm" style="align-items:baseline;justify-content:space-between">
      <strong><?= num(round($store['used'] / 1048576, 2), 2) ?> MB</strong>
      <span class="muted">dari batas <?= num($store['quota_mb']) ?> MB (<?= num(round($store['pct']), 0) ?>%)</span>
    </div>
    <div class="progress mt-1"><i style="width:<?= (int)min(100, round($store['pct'])) ?>%"></i></div>
    <p class="muted small mt-2">
      <?= num($store['files']) ?> berkas backup terdaftar · sisa ruang sampai batas:
      <strong><?= num(round($store['free'] / 1048576, 2), 2) ?> MB</strong>.
      Satu backup saat ini berukuran kurang-lebih <?= num(round($lastSize / 1048576, 2), 2) ?> MB.
      <?php if ($store['orphan_bytes'] > 0): ?>
        <br>Ada tambahan <strong><?= num($store['orphan_files']) ?> berkas tak terdaftar</strong>
        (<?= num(round($store['orphan_bytes'] / 1048576, 1), 1) ?> MB) di folder backup — dapat dibebaskan
        lewat tombol <strong>Bersihkan</strong> pada daftar di bawah.
      <?php endif; ?>
      <?php if ($store['missing'] > 0): ?>
        <br><span style="color:var(--danger,#c62828)"><?= num($store['missing']) ?> berkas backup tercatat di daftar
        tetapi berkasnya tidak ada di folder.</span>
      <?php endif; ?>
    </p>
    <div class="notice">
      <strong>Kompresi otomatis:</strong> tiap berkas backup disimpan <strong>terkompresi</strong>
      (paket = ZIP berisi berkas basis data <em>dimampatkan DEFLATE</em>, per cabang = <code>.sqlite.gz</code>)
      sehingga jauh lebih kecil, dan berkas tetap dapat <strong>diunduh</strong> serta <strong>dipulihkan</strong>.
      Setiap berkas diuji ulang (dibaca kembali &amp; diperiksa keutuhannya) sebelum dianggap siap pakai.
      <div class="mt-1">Status di server ini:
        <?php if (backup_zip_deflate_available()): ?>
          <strong>kompresi DEFLATE tersedia</strong> — paket backup otomatis dimampatkan
          (biasanya jauh lebih kecil; besarnya tercatat pada riwayat backup, mis. “dimampatkan 78%”).
        <?php else: ?>
          <strong>kompresi DEFLATE tidak tersedia</strong> di PHP server ini, sehingga paket backup
          disimpan tanpa pemampatan (berkas tetap sah &amp; dapat dipulihkan).
        <?php endif; ?>
      </div>
      <div class="mt-1">Tombol yang tersedia: <strong>Hapus Backup Lama</strong> (membebaskan ruang dengan
        membuang berkas paling lama) dan <strong>Bersihkan</strong> (membuang berkas tak terdaftar di folder).
        Untuk membuat cadangan baru, pakai <strong>Backup per Cabang</strong> atau
        <strong>Buat Paket Lengkap</strong> di bawah.</div>
    </div>
    <div class="flex gap-sm mt-2 flex-wrap" style="align-items:flex-end">
      <?php if ($store['files'] > 0): ?>
      <form method="post" data-confirm="Hapus backup PALING LAMA sampai pemakaian penyimpanan kembali di bawah 80%?">
        <?= csrf_field() ?><input type="hidden" name="action" value="prune_old">
        <button class="btn" type="submit">Hapus Backup Lama</button>
      </form>
      <?php endif; ?>
    </div>
    <?php
    /* ==========================================================================
     * PAKET LENGKAP: CENTRAL + SELURUH BASIS DATA CABANG (ronde 54)
     * Memakai SISTEM BACKUP YANG SAMA (folder, kuota, riwayat, restore) — hanya
     * bentuk paketnya yang lebih lengkap: manifest + checksum + metadata.
     * ======================================================================== */
    $inv = backup_db_inventory();
    $adaDb = array_values(array_filter($inv, fn($x) => !empty($x['ada'])));
    ?>
    <div class="section-title">Paket Lengkap (Central + Seluruh Cabang)</div>
    <div class="notice small">
      <strong>Satu paket berisi semua basis data:</strong>
      <code>backup/central.sqlite</code> + <code>backup/branches/branch_00X.sqlite</code>,
      disertai <code>manifest.json</code>, <code>checksums.json</code> (SHA-256 tiap berkas),
      dan <code>metadata.json</code> (versi skema, arsitektur, waktu, jumlah cabang).
      Paket diverifikasi ulang setelah dibuat — bila tidak terbaca, berkasnya dibuang.
      <div class="mt-1">
        <strong>Foto ikut terbawa:</strong> berkas foto pasien/dokter/terapis dan lampiran rekam medis
        disimpan <em>di luar</em> basis data, jadi paket ini juga memuatnya di folder
        <code>backup/media/…</code> (batas ukuran diatur pada kartu
        <a href="#pengaturan-backup">Jadwal Backup Database</a>). Selain itu <code>media.json</code>
        selalu berisi <strong>daftar lengkap</strong> setiap berkas media beserta rujukan datanya
        (pasien/dokter/terapis/rekam medis) dan SHA-256-nya — jadi meski ada berkas yang tidak
        terbawa karena batas ukuran, daftarnya tetap dapat ditelusuri. Saat paket dipulihkan,
        berkas fotonya ikut dikembalikan.
        <div class="mt-1">Kondisi sekarang:
          <strong><?= num($mediaInfo['jumlah']) ?></strong> berkas foto
          (±<?= e(num(round($mediaInfo['total_bytes'] / 1048576, 2), 2)) ?> MB) —
          <strong><?= num($mediaInfo['ikut']) ?></strong> ikut ke paket
          (±<?= e(num(round($mediaInfo['ikut_bytes'] / 1048576, 2), 2)) ?> MB),
          <?= num($mediaInfo['dilewati']) ?> tidak ikut (batas <?= num($mediaInfo['max_mb']) ?> MB / berkas cache).</div>
      </div>
    </div>
    <div class="table-wrap mt-2">
      <table class="tbl">
        <thead><tr><th>Basis data</th><th>Jenis</th><th>Keadaan</th><th>Ukuran</th></tr></thead>
        <tbody>
        <?php foreach ($inv as $d): ?>
          <tr>
            <td><code><?= e((string)$d['label']) ?></code></td>
            <td><?= $d['kind'] === 'central' ? badge('Central', 'blue') : badge('Cabang', 'green') ?></td>
            <td><?= !empty($d['ada'])
              ? badge('ada', 'green')
              : badge('belum dibuat', 'yellow') ?></td>
            <td class="small nowrap"><?= !empty($d['ada'])
              ? num(round((int)@filesize((string)$d['path']) / 1048576, 2), 2) . ' MB' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" class="flex gap-sm flex-wrap mt-2" style="align-items:flex-end">
      <?= csrf_field() ?><input type="hidden" name="action" value="create_package">
      <div class="field" style="min-width:220px"><label>Keterangan paket (opsional)</label>
        <input class="input" name="note" placeholder="mis. sebelum migrasi ke central/branch"></div>
      <button class="btn btn-primary" type="submit"<?= $adaDb ? '' : ' disabled' ?>>
        <?= icon('database') ?> Buat Paket Lengkap (<?= num(count($adaDb)) ?> basis data)</button>
      <span class="muted small">Restore tetap dilakukan di halaman Backup ini (bukan di halaman Impor Data).</span>
    </form>

  </div>
</div>

<div class="card tight mt-2">
  <div class="card-head"><h3>Riwayat Backup</h3>
    <?php $orph = backup_orphan_files(); ?>
    <?php if ($orph['total'] > 0 && is_super()): ?>
      <div class="flex gap-sm" style="align-items:center">
        <span class="badge badge-yellow" title="Berkas di folder backup yang tidak terdaftar di tabel backup (sisa berkas lama)"><?= num($orph['total']) ?> berkas tak terdaftar · <?= num(round($orph['bytes'] / 1048576, 1), 1) ?> MB</span>
        <form method="post" class="inline-form"
              data-confirm="Hapus <?= num($orph['total']) ?> berkas backup yang TIDAK terdaftar (membebaskan <?= num(round($orph['bytes'] / 1048576, 1), 1) ?> MB)? Backup yang terdaftar tidak disentuh.">
          <?= csrf_field() ?><input type="hidden" name="action" value="purge_orphans">
          <button class="btn btn-sm btn-danger" type="submit">Bersihkan</button>
        </form>
      </div>
    <?php endif; ?><span class="muted"><?= num(count($rows)) ?> terbaru</span></div>
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state('Belum ada backup. Buat backup pertama Anda sekarang.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Nama File</th><th>Waktu</th><th>Ukuran</th><th>Keterangan</th><th>Dibuat Oleh</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $gz = strtolower(substr((string)$r['filename'], -3)) === '.gz'; ?>
        <tr>
          <td class="small"><strong><?= e($r['filename']) ?></strong>
            <?php if ($gz): ?><br><?= badge('terkompres gzip', 'green') ?><?php endif; ?></td>
          <td class="small"><?= e(tgl($r['created_at'], true)) ?></td>
          <td class="small"><?= num(round(((int)$r['size']) / 1048576, 2), 2) ?> MB</td>
          <td class="small"><?php
            $jenisBadge = ['branch' => ['Basis data cabang', 'blue'], 'package' => ['Paket lengkap', 'pink'],
                           'sql' => ['Dump seluruh data', 'gray']][$jenis] ?? ['Dump', 'gray']; ?>
            <?= badge($jenisBadge[0], $jenisBadge[1]) ?><br><?= e($r['note'] ?: '-') ?></td>
          <td class="small"><?= e($r['user_name'] ?: 'system') ?></td>
          <td class="nowrap"><div class="row-actions">
            <a class="btn btn-sm" href="backup.php?download=<?= (int)$r['id'] ?>"><?= icon('download') ?> Unduh</a>
            <?php
            /* Tombol pemulihan menyesuaikan JENIS berkas:
               · 'branch'  → pulihkan cabang itu saja
               · 'package' → pulihkan central + seluruh cabang
               · 'sql'     → pulihkan seluruh data dari dump (baris dimasukkan ke
                             central + basis data cabang sesuai branch_id-nya) */
            $jenis = (string)($r['kind'] ?? 'sql');
            if ($jenis === '' || $jenis === '0') $jenis = 'sql';
            if (is_super() && $jenis === 'branch'): ?>
              <form method="post" data-confirm="Pulihkan BASIS DATA CABANG ini dari cadangan? Hanya cabang tsb yang diganti (snapshot otomatis dibuat lebih dulu).">
                <?= csrf_field() ?><input type="hidden" name="action" value="restore_branch"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Restore Cabang</button></form>
            <?php elseif (is_super() && $jenis === 'package'): ?>
              <form method="post" data-confirm="Pulihkan SELURUH basis data (central + semua cabang) dari paket ini? Snapshot otomatis dibuat lebih dulu.">
                <?= csrf_field() ?><input type="hidden" name="action" value="restore_package"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Restore Semua</button></form>
            <?php elseif (is_super()): ?>
              <form method="post" data-confirm="Pulihkan SELURUH DATA dari berkas ini? Seluruh data saat ini akan DIGANTI (snapshot otomatis dibuat lebih dulu).">
                <?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Restore</button></form>
            <?php endif; ?>
            <form method="post" data-confirm="Hapus file backup ini?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm" type="submit">Hapus</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php /* ================= DATABASE CENTER (dipindah dari Developer Settings) =================
   Pemilik hanya perlu DUA tombol di sini — sisanya informasi. Tombol lama (Siapkan
   Basis Data Central, Pratinjau Migrasi, Migrasi Data, Aktifkan/Kembali ke Mode Dasar,
   Aktifkan/Matikan Pengalihan) DIHAPUS pada ronde 59 karena aplikasi sekarang SELALU
   memakai central + satu basis data per cabang (tidak ada lagi mode lama). */ ?>
<?php
try {
    $dbStatus = db_status_summary();
} catch (Throwable $e) {
    $dbStatus = ['central' => ['path' => db_central_path(), 'ada' => false], 'branches' => [],
        'jumlah_branch' => 0, 'perlu_dibuat' => 0];
}
try {
    $auditDb = db_audit_repository();
} catch (Throwable $e) {
    $auditDb = ['berkas' => 0, 'query_diperiksa' => 0, 'perlu_diperiksa' => [],
        'tabel_terlibat' => [], 'branch_helper' => 0, 'anak' => [], 'cross_branch' => [], 'berkas_anak' => 0];
}
$routeRep = db_route_report();
?>
<div class="card tight mt-2" id="database">
  <div class="card-head">
    <h3>Central &amp; Basis Data Cabang</h3>
    <span><?= badge('Khusus Super Admin', 'pink') ?></span>
  </div>
  <div class="card-body">
    <div class="alert alert-info">
      <strong>Aplikasi memakai dua lapis basis data.</strong>
      <div class="mt-1">
        <code>central.sqlite</code> menyimpan data <strong>global/sistem</strong>: akun pengguna, peran &amp; izin,
        pengaturan, daftar cabang, kamus ICD, jejak audit, dan riwayat backup.
      </div>
      <div class="mt-1">
        <code>branch_00N.sqlite</code> (satu berkas per cabang) menyimpan data <strong>operasional</strong>:
        pasien, rekam medis, reservasi, transaksi, stok, dan master treatment/skincare/bahan.
        Akun yang dipin ke satu cabang <strong>hanya</strong> dapat membaca &amp; menulis cabang itu.
      </div>
    </div>

    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Basis data</th><th>Status</th><th>Tabel</th><th>Integritas</th>
          <th>Pelanggaran FK</th><th>Ukuran</th></tr></thead>
        <tbody>
          <tr>
            <td><strong>Central</strong> <span class="muted small">(<?= e(basename((string)$dbStatus['central']['path'])) ?>)</span>
              <div class="muted small">data global &amp; sistem</div></td>
            <td><?= !empty($dbStatus['central']['ada'])
              ? badge(!empty($dbStatus['central']['ok']) ? 'sehat' : 'perlu diperiksa', !empty($dbStatus['central']['ok']) ? 'green' : 'yellow')
              : badge('belum dibuat', 'gray') ?></td>
            <td class="small"><?= num((int)($dbStatus['central']['tables'] ?? 0)) ?></td>
            <td class="small"><?= e((string)($dbStatus['central']['integrity'] ?: '—')) ?></td>
            <td class="small"><?= num((int)($dbStatus['central']['fk'] ?? 0)) ?></td>
            <td class="small nowrap"><?= num(round(((int)($dbStatus['central']['size'] ?? 0)) / 1048576, 2), 2) ?> MB</td>
          </tr>
          <?php foreach ($dbStatus['branches'] as $b): ?>
            <tr>
              <td><strong><?= e((string)$b['name']) ?></strong>
                <span class="muted small">(branch_<?= str_pad((string)$b['id'], 3, '0', STR_PAD_LEFT) ?>.sqlite)</span>
                <div class="muted small">data operasional cabang ini</div></td>
              <td><?= !empty($b['ada'])
                ? badge(!empty($b['ok']) ? 'sehat' : 'perlu diperiksa', !empty($b['ok']) ? 'green' : 'yellow')
                : badge('belum dibuat', 'gray') ?></td>
              <td class="small"><?= num((int)($b['tables'] ?? 0)) ?></td>
              <td class="small"><?= e((string)($b['integrity'] ?: '—')) ?></td>
              <td class="small"><?= num((int)($b['fk'] ?? 0)) ?></td>
              <td class="small nowrap"><?= num(round(((int)($b['size'] ?? 0)) / 1048576, 2), 2) ?> MB</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="notice small mt-2">
      Jumlah cabang: <strong><?= num((int)$dbStatus['jumlah_branch']) ?></strong> ·
      basis data cabang yang <strong>belum dibuat</strong>: <?= num((int)$dbStatus['perlu_dibuat']) ?> ·
      cakupan baca permintaan ini: <strong><?= e((string)($routeRep['cakupan_baca'] ?? 'semua cabang')) ?></strong>
      <?php if (!empty($routeRep['terlewat'])): ?>
        <div class="mt-1"><strong>Perhatian:</strong> cabang <?= e(implode(', ', array_map('strval', (array)$routeRep['terlewat']))) ?>
          melebihi batas berkas yang dapat dibuka sekaligus, sehingga tidak ikut dalam pembacaan
          &ldquo;semua cabang&rdquo; pada permintaan itu.</div>
      <?php endif; ?>
      <?php if (!empty($routeRep['error'])): ?>
        <div class="mt-1"><strong>Catatan:</strong> <?= e((string)$routeRep['error']) ?></div>
      <?php endif; ?>
    </div>

    <div class="section-title">Tindakan yang tersedia</div>
    <div class="flex gap-sm flex-wrap" style="align-items:flex-start">
      <div style="max-width:340px">
        <form method="post" data-confirm="Periksa dan buat basis data untuk SEMUA cabang? Cabang yang belum punya basis data akan dibuatkan beserta skemanya.">
          <?= csrf_field() ?><input type="hidden" name="action" value="db_branches">
          <button class="btn" type="submit"><?= icon('layers') ?> Siapkan Basis Data Semua Cabang</button>
        </form>
        <p class="muted small mt-1">Membuatkan basis data + skema untuk cabang yang belum punya
          (mis. setelah menambah cabang baru), dan memeriksa yang sudah ada. Tidak menghapus data.</p>
      </div>
      <div style="max-width:340px">
        <form method="post" data-confirm="Jalankan pemeriksaan kesehatan (integritas &amp; pelanggaran relasi) pada seluruh basis data?">
          <?= csrf_field() ?><input type="hidden" name="action" value="db_health">
          <button class="btn" type="submit"><?= icon('shield') ?> Health Check</button>
        </form>
        <p class="muted small mt-1">Memeriksa keutuhan berkas (<code>integrity_check</code>) dan pelanggaran
          relasi (<code>foreign_key_check</code>) central + setiap cabang. Hanya memeriksa, tidak mengubah.</p>

        <?php
        /* KEADAAN BERKAS CABANG: apakah masih memuat tabel GLOBAL (duplikasi)?
           Berkas cabang seharusnya HANYA memuat data operasional — kamus ICD,
           pengaturan, biaya operasional, dsb. hanya ada di central. */
        try { $bcClean = branch_globals_clean(); }
        catch (Throwable $e) { $bcClean = ['bersih' => true, 'bermasalah' => []]; }
        ?>
        <div class="mt-1">
          <?php if ($bcClean['bersih']): ?>
            <p class="muted small"><?= icon('check') ?> <strong>Berkas cabang sudah ramping</strong> — hanya memuat data
              operasional cabang. Tabel global (pengguna, pengaturan, kamus ICD, biaya operasional) hanya ada di central.</p>
          <?php else: ?>
            <div class="alert alert-warning">
              <strong>Berkas cabang masih memuat <?= num(count($bcClean['bermasalah'])) ?> tabel GLOBAL</strong>
              (mis. <?= e(implode(', ', array_slice($bcClean['bermasalah'], 0, 6))) ?>).
              Data itu DUPLIKASI — tabel global seharusnya hanya ada di central, sedangkan berkas cabang hanya
              memuat data operasional. Duplikasi ini membengkakkan berkas cabang (kamus ICD 15.966 baris per cabang).
              <div class="mt-1">Tekan tombol di bawah untuk membuangnya: snapshot pengaman dibuat lebih dulu dan
                penghapusan DITOLAK bila ada isi yang tidak ada di central.</div>
            </div>
            <form method="post" class="mt-1"
                  data-confirm="Buang tabel GLOBAL (pengaturan, kamus ICD, biaya operasional, dst.) dari SELURUH berkas cabang? Tabel operasional (pasien, transaksi, rekam medis, stok) TIDAK disentuh. Snapshot pengaman dibuat lebih dulu.">
              <?= csrf_field() ?><input type="hidden" name="action" value="db_branch_cleanup">
              <button class="btn btn-primary" type="submit"><?= icon('layers') ?> Bersihkan Tabel Global di Berkas Cabang</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php
    /* ------------------------------------------------------------------ *
     * REGISTRY BASIS DATA (db_registry) — versi skema, status migrasi,
     * lokasi, ukuran, dan kesehatan SETIAP basis data.
     * ------------------------------------------------------------------
     * Nilainya berasal dari PEMERIKSAAN NYATA (bukan setelan/cache) dan sudah
     * disegarkan otomatis oleh db_status_summary() di atas — jadi tabel ini tidak
     * pernah berbeda dengan tabel Central & Basis Data Cabang di atasnya. */
    $regRows = db_registry_list();
    $regSum = db_registry_summary();
    $regAdaMasalah = !empty($regSum['gagal']) || !empty($regSum['tertinggal']);
    ?>
    <div class="section-title" id="registry">Registry Basis Data (versi · status · ukuran · kesehatan)</div>
    <div class="notice small" style="background:<?= $regAdaMasalah ? '#FFF6F5' : 'var(--tint)' ?>">
      <?php if ($regAdaMasalah): ?>
        <strong>Perlu perhatian:</strong>
        <?php if (!empty($regSum['gagal'])): ?>
          gagal divalidasi → <strong><?= e(implode(', ', $regSum['gagal'])) ?></strong>.
        <?php endif; ?>
        <?php if (!empty($regSum['tertinggal'])): ?>
          skema belum terkini → <strong><?= e(implode(', ', $regSum['tertinggal'])) ?></strong>
          (akan dimigrasikan otomatis saat basis data itu dipakai, atau tekan
          <em>Siapkan Basis Data Semua Cabang</em>).
        <?php endif; ?>
      <?php else: ?>
        <?= icon('check') ?> Seluruh <strong><?= num((int)$regSum['jumlah']) ?></strong> basis data
        berstatus sehat dan skema <strong><?= e(SCHEMA_VERSION) ?></strong> terkini.
      <?php endif; ?>
      <div class="mt-1">Terakhir disinkronkan: <strong><?= e((string)$regSum['disinkronkan']) ?></strong>.
        Nilai di tabel ini dibaca langsung dari setiap berkas basis data
        (central → <code>settings.schema_version</code>; berkas cabang →
        <code>PRAGMA user_version</code>) beserta hasil <code>integrity_check</code> dan
        <code>foreign_key_check</code>.</div>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Basis data</th><th>Lokasi</th><th>Versi skema</th><th>Status</th>
          <th class="num">Ukuran</th><th>Keterangan pemeriksaan</th><th>Terakhir diperiksa</th></tr></thead>
        <tbody>
        <?php if (!$regRows): ?>
          <tr><td colspan="7" class="muted small">Belum ada baris registry — tekan
            <em>Sinkronkan Registry</em> di bawah.</td></tr>
        <?php endif; ?>
        <?php foreach ($regRows as $r):
          $st = strtoupper((string)$r['status']);
          $tone = $st === 'ACTIVE' ? 'green' : ($st === 'MIGRATED' ? 'blue' : ($st === 'CHECK' ? 'yellow' : 'red'));
        ?>
          <tr>
            <td><strong><?= $r['kind'] === 'central' ? 'Central' : ('Cabang ' . (int)$r['branch_id']) ?></strong>
              <div class="muted small"><?= $r['kind'] === 'central' ? 'data global &amp; sistem' : 'data operasional' ?></div></td>
            <td class="small"><code><?= e(short_text((string)$r['path'], 58)) ?></code></td>
            <td class="small"><?= e((string)$r['schema_version']) ?>
              <?php if ((string)$r['schema_version'] !== SCHEMA_VERSION): ?>
                <div class="muted small">aplikasi: <?= e(SCHEMA_VERSION) ?></div>
              <?php endif; ?></td>
            <td><?= badge($st, $tone) ?></td>
            <td class="num small"><?= num(round(((int)$r['size']) / 1048576, 2), 2) ?> MB</td>
            <td class="small"><?= e(short_text((string)($r['health'] ?? ''), 110)) ?></td>
            <td class="small nowrap"><?= e(tgl((string)$r['last_check'], true)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="flex gap-sm flex-wrap mt-2" style="align-items:flex-start">
      <div style="max-width:380px">
        <form method="post"
              data-confirm="Sinkronkan registry dengan kondisi basis data yang sebenarnya? Versi skema, status, ukuran, dan kesehatan setiap basis data diperiksa ulang. TIDAK mengubah data operasional, TIDAK mengulang migrasi, dan TIDAK mereset basis data.">
          <?= csrf_field() ?><input type="hidden" name="action" value="db_registry">
          <button class="btn btn-primary" type="submit"><?= icon('refresh') ?> Sinkronkan Registry</button>
        </form>
        <p class="muted small mt-1">Membaca ulang versi skema, ukuran berkas, integritas
          (<code>integrity_check</code>), dan pelanggaran relasi (<code>foreign_key_check</code>)
          untuk central + setiap cabang, lalu menuliskan hasilnya ke registry.
          <strong>Tidak ada</strong> data operasional yang diubah dan tidak ada migrasi yang diulang.
          Registry juga disegarkan otomatis setiap kali migrasi berhasil dan setiap kali panel ini dibuka.</p>
      </div>
      <?php $regAsing = db_registry_foreign_rows(); ?>
      <?php if ($regAsing): ?>
        <div style="max-width:480px">
          <div class="alert alert-warning small" style="margin:0">
            Ada <strong><?= num(count($regAsing)) ?> baris registry</strong> yang menunjuk basis data
            dari lokasi LAIN (bukan central/cabang pemasangan ini) — biasanya sisa pemindahan folder
            atau salinan uji. Baris itu tidak dipakai aplikasi dan aman dibuang.
          </div>
          <form method="post" class="mt-1"
                data-confirm="Buang <?= num(count($regAsing)) ?> baris registry yang menunjuk basis data dari lokasi lain? Basis data pemasangan ini (central + setiap cabang) TIDAK tersentuh.">
            <?= csrf_field() ?><input type="hidden" name="action" value="db_registry_clean">
            <button class="btn" type="submit"><?= icon('trash') ?> Buang <?= num(count($regAsing)) ?> Baris Registry Asing</button>
          </form>
          <p class="muted small mt-1">Contoh: <code><?= e(short_text((string)($regAsing[0]['path'] ?? ''), 70)) ?></code></p>
        </div>
      <?php endif; ?>
    </div>

    <?php
    /* Riwayat migrasi & audit cakupan query: INFORMASI saja (tanpa tombol). */
    try {
        db_central_registry_ready();
        $riwayatMigrasi = db_central_all('SELECT * FROM db_migrations ORDER BY id DESC LIMIT 8');
    } catch (Throwable $e) { $riwayatMigrasi = []; }
    ?>
    <?php if ($riwayatMigrasi): ?>
      <details class="mt-2">
        <summary style="cursor:pointer">Riwayat migrasi basis data terakhir</summary>
        <div class="table-wrap mt-1">
          <table class="tbl">
            <thead><tr><th>Basis data</th><th>Versi</th><th>Catatan</th><th>Waktu</th></tr></thead>
            <tbody>
            <?php foreach ($riwayatMigrasi as $m): ?>
              <tr><td class="small"><code><?= e(basename((string)$m['db_path'])) ?></code></td>
                <td class="small"><?= e((string)$m['version']) ?></td>
                <td class="small"><?= e(short_text((string)($m['note'] ?? ''), 90)) ?></td>
                <td class="small"><?= e(tgl((string)$m['applied_at'], true)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endif; ?>
    <details class="mt-2">
      <summary style="cursor:pointer">Audit cakupan cabang pada seluruh query aplikasi</summary>
      <div class="notice small mt-1"><?= e(db_audit_summary_text($auditDb)) ?>
        <div class="mt-1">Diperiksa <strong><?= num((int)$auditDb['berkas']) ?> berkas</strong> ·
          <strong><?= num((int)$auditDb['query_diperiksa']) ?> query</strong> ·
          pembatas cabang dipakai di <strong><?= num((int)$auditDb['branch_helper']) ?> tempat</strong>.</div>
      </div>
      <?php if ($auditDb['perlu_diperiksa']): ?>
        <div class="table-wrap mt-1" style="max-height:360px;overflow:auto">
          <table class="tbl">
            <thead><tr><th>Berkas</th><th>Baris</th><th>Jenis</th><th>Tabel</th><th>Cuplikan query</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($auditDb['perlu_diperiksa'], 0, 120) as $f): ?>
              <tr><td class="small"><code><?= e((string)$f['file']) ?></code></td>
                <td class="small"><?= num((int)$f['line']) ?></td>
                <td class="small"><?= e((string)$f['jenis']) ?></td>
                <td class="small"><?= e((string)$f['tabel']) ?></td>
                <td class="small muted"><?= e(short_text((string)$f['cuplik'], 110)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <p class="muted small mt-1">Tiga kelompok diperiksa dan dinyatakan apa adanya:
        <strong>Temuan</strong> (<?= num(count((array)$auditDb['perlu_diperiksa'])) ?> — harus nol),
        <strong>Berkas anak</strong> (<?= num(count((array)($auditDb['anak'] ?? []))) ?> query — cakupan
        cabangnya diambil dari induknya, mis. <code>includes/patient.php</code>), dan
        <strong>operasi cross-branch</strong> (<?= num(count((array)($auditDb['cross_branch'] ?? []))) ?> tempat —
        mis. perubahan aturan kartu member yang memang berlaku semua cabang).</p>
    </details>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Verifikasi Kelengkapan</h3>
    <span class="muted"><?= num(count($tableCheck)) ?> tabel · <?= num($totalRows) ?> baris data</span></div>
  <div class="card-body">
    <p class="muted">Backup memuat <strong>skema seluruh tabel + seluruh baris data</strong> (termasuk pengaturan, user,
      pasien, rekam medis, transaksi, stok, audit log, dan riwayat import). Daftar di bawah dihitung langsung dari
      database saat ini sehingga Anda dapat membandingkan dengan isi berkas backup.</p>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Tabel</th><th class="num">Jumlah baris</th><th>Ikut di-backup</th></tr></thead>
        <tbody>
        <?php foreach ($tableCheck as $tname => $tn): ?>
          <tr><td class="small"><?= e($tname) ?></td><td class="num"><?= num($tn) ?></td>
            <td><?= badge('Ya', 'green') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th>Total</th><th class="num"><?= num($totalRows) ?></th><th></th></tr></tfoot>
      </table>
    </div>
    <div class="alert alert-info mt-2">
      <strong>Catatan penting:</strong> fitur di halaman ini adalah backup <em>operasional</em> (untuk pemulihan di dalam
      aplikasi). Untuk <strong>mengunduh salinan lengkap proyek</strong> (kode program) atau
      <strong>ekspor database untuk disimpan sendiri</strong>, gunakan fitur khusus di
      <a href="<?= e(platform_link('pro.php')) ?>" target="_blank" rel="noopener"><strong>halaman VibeCoder Pro</strong></a> — di sana tersedia
      <em>Download Source Code</em>, <em>Export Database</em>, dan <em>Push ke GitHub</em> dengan penanganan
      berkas besar serta kredensial yang aman. Bila belum berlangganan, lihat
      <a href="<?= e(platform_link('payment.php?buy=pro')) ?>" target="_blank" rel="noopener">halaman upgrade Pro</a>.
    </div>
  </div>
</div>

<div class="notice">File backup disimpan di luar folder publik aplikasi (<code>naveena_backups/</code>) sehingga tidak dapat diakses langsung dari internet — unduhan hanya melalui halaman ini.</div>
<?php page_foot(); ?>
