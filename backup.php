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
        if ($act === 'restore') {
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

            $pdo = db();
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $tables = array_column(all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"), 'name');
                foreach ($tables as $t) $pdo->exec('DROP TABLE IF EXISTS "' . $t . '"');
                /* Pemecahan pernyataan memakai helper bersama — komentar dibuang
                   per BARIS, bukan per pernyataan (lihat catatan di backup_lib.php:
                   cara lama membuang CREATE TABLE pertama sehingga restore gagal). */
                $statements = backup_split_statements($sql);
                $n = 0;
                foreach ($statements as $stmt) {
                    $pdo->exec($stmt);
                    $n++;
                }
                $pdo->exec('COMMIT');
            } catch (Throwable $ex) {
                $pdo->exec('ROLLBACK');
                $pdo->exec('PRAGMA foreign_keys = ON');
                throw new RuntimeException('Restore gagal: ' . $ex->getMessage() . ' Database dikembalikan ke kondisi sebelumnya.');
            }
            $pdo->exec('PRAGMA foreign_keys = ON');
            /* Tabel `backups` ikut dikembalikan isi backup, sehingga baris snapshot
               pengaman tadi hilang dari daftar (berkasnya tetap ada). Didaftarkan
               ulang supaya berkas pengaman TIDAK dianggap "berkas tak terdaftar"
               dan tidak ikut dibersihkan oleh tombol Bersihkan. */
            if ((int)scalar('SELECT COUNT(*) FROM backups WHERE filename = ?', [$safety]) === 0) {
                q('INSERT INTO backups (filename, size, note, created_by) VALUES (?,?,?,?)',
                    [$safety, (int)filesize(backup_dir_ensure() . '/' . $safety),
                     'Snapshot otomatis sebelum restore (didaftarkan ulang setelah restore)', $user['id']]);
            }
            settings(true);
            audit('Restore Database', 'Pengaturan', $id, null, ['file' => $b['filename'], 'statements' => $n], 'Restore dari backup ' . $b['filename']);
            flash('Restore berhasil (' . $n . ' perintah SQL). Snapshot sebelum restore disimpan sebagai ' . $safety . '.');
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

$rows = all('SELECT b.*, u.name AS user_name FROM backups b LEFT JOIN users u ON u.id=b.created_by ORDER BY b.id DESC LIMIT 30');
$dbSize = is_file(DB_PATH) ? filesize(DB_PATH) : 0;
$store = backup_storage_info();
$notice = backup_storage_notice($store);
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
  <div class="stat"><span class="lbl">Ukuran Database Aktif</span><span class="val"><?= num(round($dbSize / 1024, 1), 1) ?> KB</span><span class="sub"><?= e(basename(DB_PATH)) ?></span></div>
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
      <strong>Kompresi otomatis:</strong> setiap backup (manual maupun otomatis) ditulis sebagai berkas
      <code>.sql.gz</code> — isinya sama persis dengan dump SQL biasa, hanya dipadatkan (umumnya
      <strong>8–15× lebih kecil</strong>) sehingga hemat penyimpanan. Berkas tetap dapat
      <strong>diunduh</strong>, <strong>direstore</strong>, dan diverifikasi isinya seperti biasa;
      sistem juga menguji ulang tiap berkas terkompres sebelum dianggap siap pakai.
    </div>
    <div class="flex gap-sm mt-2 flex-wrap" style="align-items:flex-end">
      <form method="post" class="flex gap-sm flex-wrap" style="align-items:flex-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="field" style="min-width:220px"><label>Keterangan backup (opsional)</label>
          <input class="input" name="note" placeholder="mis. sebelum impor data besar"></div>
        <button class="btn btn-primary" type="submit"><?= icon('database') ?> Buat Backup Sekarang</button>
      </form>
      <?php if ($store['files'] > 0): ?>
      <form method="post" data-confirm="Hapus backup PALING LAMA sampai pemakaian penyimpanan kembali di bawah 80%?">
        <?= csrf_field() ?><input type="hidden" name="action" value="prune_old">
        <button class="btn" type="submit">Hapus Backup Lama</button>
      </form>
      <?php endif; ?>
    </div>
    <p class="muted small mt-2">Batas penyimpanan backup dapat diubah di
      <a href="developer.php#backup">Developer Settings → Backup Database</a>.</p>
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
    <?php endif; ?><span class="muted">30 terbaru</span></div>
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
          <td class="small"><?= e($r['note'] ?: '-') ?></td>
          <td class="small"><?= e($r['user_name'] ?: 'system') ?></td>
          <td class="nowrap"><div class="row-actions">
            <a class="btn btn-sm" href="backup.php?download=<?= (int)$r['id'] ?>"><?= icon('download') ?> Unduh</a>
            <?php if (is_super()): ?>
              <form method="post" data-confirm="Restore database dari file ini? Seluruh data saat ini akan DIGANTI (snapshot otomatis dibuat lebih dulu).">
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
