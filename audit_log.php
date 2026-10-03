<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('audit.view');
$scope = scope_branch();

/** Statistik audit log pada rentang tanggal tertentu (untuk pratinjau sebelum hapus). */
function audit_range_stats(string $from, string $to): array
{
    $w = 'date(created_at) BETWEEN ? AND ?';
    $p = [$from, $to];
    $n = (int)scalar("SELECT COUNT(*) FROM audit_logs WHERE {$w}", $p);
    $mods = all("SELECT module, COUNT(*) n FROM audit_logs WHERE {$w} GROUP BY module ORDER BY n DESC", $p);
    $range = one("SELECT MIN(created_at) a, MAX(created_at) b FROM audit_logs WHERE {$w}", $p);
    return ['count' => $n, 'modules' => $mods, 'first' => $range['a'] ?? null, 'last' => $range['b'] ?? null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act !== 'purge') {
            throw new RuntimeException('Audit Log tidak dapat diubah atau dihapus satu per satu oleh peran Anda.');
        }
        if (!is_super()) deny('Hanya Super Admin yang boleh menghapus Audit Log.');
        $from = trim((string)($_POST['from'] ?? ''));
        $to   = trim((string)($_POST['to'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new RuntimeException('Rentang tanggal tidak valid.');
        }
        if (strtotime($from) > strtotime($to)) throw new RuntimeException('Tanggal awal tidak boleh melebihi tanggal akhir.');
        /* Pengaman: rentang terlalu luas harus dipersempit supaya tidak menghapus
           seluruh riwayat karena salah pilih tanggal. */
        $days = (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1;
        if ($days > 400) throw new RuntimeException('Rentang maksimal 400 hari per proses. Persempit rentang tanggal, lalu ulangi bila perlu.');
        if ($reason === '') throw new RuntimeException('Alasan penghapusan Audit Log wajib diisi (untuk jejak siapa menghapus dan mengapa).');

        $stats = audit_range_stats($from, $to);
        if ($stats['count'] === 0) {
            throw new RuntimeException('Tidak ada catatan audit pada rentang ' . tgl($from) . ' — ' . tgl($to) . '.');
        }
        q('DELETE FROM audit_logs WHERE date(created_at) BETWEEN ? AND ?', [$from, $to]);
        // Catat bahwa pembersihan terjadi (jejak pembersihan tetap ada).
        audit('HAPUS AUDIT LOG', 'Pengaturan', null,
            ['rentang' => $from . ' s/d ' . $to, 'jumlah' => $stats['count'], 'modul' => array_column($stats['modules'], 'module')],
            ['tersisa_dihapus' => $stats['count']],
            'Pembersihan audit log oleh Super Admin. ' . $reason);
        flash(num($stats['count']) . ' catatan audit pada ' . tgl($from) . ' — ' . tgl($to) . ' telah dihapus. '
            . 'Tindakan pembersihan ini tetap tercatat di audit log.', 'warning');
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: ' . ($act === 'purge' ? 'audit_log.php' : 'audit_log.php'));
    exit;
}

/* Pratinjau rentang yang dipilih (GET) — dihitung server, tanpa menghapus apa pun. */
$preview = null;
if (is_owner_level() && gp('pv_from') !== '' && gp('pv_to') !== '' && gp('pv') !== '') {
    $pvFrom = gp('pv_from'); $pvTo = gp('pv_to');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pvFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $pvTo)) {
        $preview = ['from' => $pvFrom, 'to' => $pvTo] + audit_range_stats($pvFrom, $pvTo);
    }
}

$where = ['1=1'];
$params = [];
if ($scope !== null) { $where[] = '(a.branch_id = ? OR a.branch_id IS NULL)'; $params[] = $scope; }
if (gp('module') !== '') { $where[] = 'a.module = ?'; $params[] = gp('module'); }
if (gp('action') !== '') { $where[] = 'a.action LIKE ?'; $params[] = '%' . gp('action') . '%'; }
if (gp('user') !== '')   { $where[] = 'a.user_id = ?'; $params[] = (int)gp('user'); }
if (gp('from') !== '')   { $where[] = 'date(a.created_at) >= ?'; $params[] = gp('from'); }
if (gp('to') !== '')     { $where[] = 'date(a.created_at) <= ?'; $params[] = gp('to'); }
if (gp('q') !== '') {
    $where[] = '(a.user_name LIKE ? OR a.action LIKE ? OR a.record_id LIKE ? OR a.reason LIKE ?)';
    $t = '%' . gp('q') . '%';
    array_push($params, $t, $t, $t, $t);
}
$w = implode(' AND ', $where);
$page = page_no(); $pp = per_page();
$total = (int)scalar("SELECT COUNT(*) FROM audit_logs a WHERE {$w}", $params);
$rows = all("SELECT a.*, b.name AS branch_name FROM audit_logs a LEFT JOIN branches b ON b.id = a.branch_id
             WHERE {$w} ORDER BY a.id DESC LIMIT {$pp} OFFSET " . (($page - 1) * $pp), $params);
$modules = all('SELECT DISTINCT module FROM audit_logs ORDER BY module');
$users = all('SELECT DISTINCT u.id, u.name FROM audit_logs a JOIN users u ON u.id = a.user_id ORDER BY u.name');

/* Panduan aktivitas yang tercatat */
$tracked = ['Login', 'Logout', 'Tambah Pasien', 'Edit Pasien', 'Tambah Rekam Medis', 'Edit Rekam Medis', 'Kunci Rekam Medis',
    'Amendment Rekam Medis', 'Tambah Transaksi', 'Void Transaksi', 'Refund Transaksi', 'Perubahan Stok Skincare',
    'Perubahan Stok Bahan', 'Ubah Status Reservasi', 'Tambah Produk Skincare', 'Edit Produk Skincare', 'Nonaktifkan Produk',
    'Tambah Treatment', 'Edit Treatment', 'Atur Permission User', 'Backup Database', 'Export PDF', 'Kirim Laporan Email'];

page_head('Audit Log', 'audit');
?>
<div class="page-head">
  <div><h2>Audit Log</h2><p class="muted">Jejak aktivitas sistem: siapa, kapan, di cabang mana, dan apa yang diubah. Bersifat read-only.</p></div>
  <div class="page-actions">
    <?php if (has_perm('export.data')): ?>
      <a class="btn" href="export.php?type=transaksi&format=csv&<?= e(qs([], ['page', 'per_page'])) ?>"><?= icon('download') ?> Export Transaksi</a>
    <?php endif; ?>
    <span class="pill"><?= num($total) ?> catatan</span>
  </div>
</div>

<div class="card tight">
  <form class="filter-bar" method="get">
    <div class="field searchbox"><label>Cari</label><span><?= icon('search') ?></span>
      <input class="input input-sm" name="q" value="<?= e(gp('q')) ?>" placeholder="User / aktivitas / record / alasan"></div>
    <div class="field"><label>Modul</label>
      <select class="input input-sm" name="module"><option value="">Semua modul</option>
        <?php foreach ($modules as $m): ?><option value="<?= e($m['module']) ?>"<?= gp('module') === $m['module'] ? ' selected' : '' ?>><?= e($m['module']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Aktivitas</label><input class="input input-sm" name="action" value="<?= e(gp('action')) ?>" placeholder="mis. Void / Tambah"></div>
    <div class="field"><label>User</label>
      <select class="input input-sm" name="user"><option value="">Semua user</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= gp('user') === (string)$u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Dari</label><input class="input input-sm" type="date" name="from" value="<?= e(gp('from')) ?>"></div>
    <div class="field"><label>Sampai</label><input class="input input-sm" type="date" name="to" value="<?= e(gp('to')) ?>"></div>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    <a class="btn btn-sm" href="audit_log.php">Reset</a>
    <?= per_page_inline() ?>
  </form>
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state('Belum ada catatan audit yang cocok dengan filter.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Waktu</th><th>User</th><th>Role</th><th>Cabang</th><th>Modul</th><th>Aktivitas</th><th>Record</th><th>Perubahan</th><th>Alasan</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="small nowrap"><?= e(tgl($r['created_at'], true)) ?></td>
          <td class="small"><?= e($r['user_name'] ?: 'system') ?></td>
          <td class="small"><?= e($r['user_role'] ?: '-') ?></td>
          <td class="small"><?= e($r['branch_name'] ?: '-') ?></td>
          <td class="small"><?= e($r['module']) ?></td>
          <td><?= badge($r['action'], strpos($r['action'], 'Void') !== false || strpos($r['action'], 'Hapus') !== false || strpos($r['action'], 'Gagal') !== false ? 'red' : (strpos($r['action'], 'Login') !== false ? 'blue' : 'green')) ?></td>
          <td class="small"><?= e($r['record_id'] ?: '-') ?></td>
          <td class="small" style="max-width:320px">
            <?php if ($r['old_value'] || $r['new_value']): ?>
              <details><summary class="muted">lihat detail</summary>
                <div style="word-break:break-word"><strong>Sebelum:</strong> <code><?= e(item_short((string)$r['old_value'], 500)) ?></code><br>
                <strong>Sesudah:</strong> <code><?= e(item_short((string)$r['new_value'], 500)) ?></code></div>
              </details>
            <?php else: ?><span class="muted">-</span><?php endif; ?>
          </td>
          <td class="small"><?= e($r['reason'] ?: '-') ?></td>
          <td class="small"><?= e($r['ip_address'] ?: '-') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?= pagination($total, $pp, $page) ?>
</div>

<?php if (is_super()): ?>
<div class="card" id="purge">
  <div class="card-head">
    <h3>Hapus Audit Log Per Periode</h3>
    <span><?= badge('Khusus Super Admin', 'pink') ?></span>
  </div>
  <div class="card-body">
    <div class="alert alert-warning">
      <strong>Perhatian:</strong> Audit Log adalah jejak pertanggungjawaban (siapa mengubah/menghapus apa).
      Menghapusnya berarti riwayat tersebut <strong>hilang permanen</strong> dan tidak dapat dipulihkan.
      Gunakan hanya untuk mengurangi ukuran database pada periode lama yang sudah tidak diperlukan —
      mis. arsipkan dulu lewat <em>Export</em> sebelum dibersihkan.
    </div>
    <form method="get" class="filter-bar" style="background:#FFFBFD;border:1px solid var(--line);border-radius:12px">
      <input type="hidden" name="pv" value="1">
      <div class="field"><label>Tanggal Awal <span class="req">*</span></label>
        <input class="input input-sm" type="date" name="pv_from" value="<?= e(gp('pv_from')) ?>" required></div>
      <div class="field"><label>Tanggal Akhir <span class="req">*</span></label>
        <input class="input input-sm" type="date" name="pv_to" value="<?= e(gp('pv_to')) ?>" required></div>
      <button class="btn btn-sm btn-primary" type="submit">Hitung Dulu (pratinjau)</button>
      <span class="muted">Tidak ada data yang dihapus pada langkah ini.</span>
    </form>

    <?php if ($preview): ?>
      <?php if ($preview['count'] === 0): ?>
        <div class="alert alert-info mt-2">Tidak ada catatan audit pada rentang <strong><?= e(tgl($preview['from'])) ?> — <?= e(tgl($preview['to'])) ?></strong>.</div>
      <?php else: ?>
        <div class="mt-2">
          <div class="grid g3">
            <div class="stat"><span class="lbl">Catatan pada Rentang Ini</span><span class="val"><?= num($preview['count']) ?></span>
              <span class="sub"><?= e(tgl($preview['from'])) ?> — <?= e(tgl($preview['to'])) ?></span></div>
            <div class="stat"><span class="lbl">Catatan Tertua</span><span class="val" style="font-size:.95rem"><?= e($preview['first'] ? tgl($preview['first'], true) : '-') ?></span><span class="sub">pada rentang ini</span></div>
            <div class="stat"><span class="lbl">Catatan Terbaru</span><span class="val" style="font-size:.95rem"><?= e($preview['last'] ? tgl($preview['last'], true) : '-') ?></span><span class="sub">pada rentang ini</span></div>
          </div>
          <div class="table-wrap mt-2">
            <table class="tbl">
              <thead><tr><th>Modul</th><th class="num">Jumlah catatan</th></tr></thead>
              <tbody>
              <?php foreach ($preview['modules'] as $mod): ?>
                <tr><td><?= e($mod['module']) ?></td><td class="num"><?= num($mod['n']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <form method="post" class="mt-3" id="purge"
                data-heavy-confirm="HAPUS AUDIT LOG"
                data-heavy-warning="Menghapus <strong><?= num($preview['count']) ?> catatan audit</strong> pada rentang
                  <strong><?= e(tgl($preview['from'])) ?> — <?= e(tgl($preview['to'])) ?></strong>.<br>
                  &bull; Riwayat perubahan/hapus data pada periode ini hilang permanen<br>
                  &bull; Tindakan pembersihan ini sendiri tetap akan dicatat (siapa, kapan, rentang, alasan)<br><br>
                  Disarankan mengunduh <em>Export</em> terlebih dahulu sebagai arsip."
                data-heavy-confirm2="PERINGATAN KEDUA: <?= num($preview['count']) ?> catatan audit (<?= e(tgl($preview['from'])) ?> — <?= e(tgl($preview['to'])) ?>) akan dihapus PERMANEN dari database. Lanjutkan?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="purge">
            <input type="hidden" name="from" value="<?= e($preview['from']) ?>">
            <input type="hidden" name="to" value="<?= e($preview['to']) ?>">
            <div class="grid g2">
              <div class="field"><label>Alasan penghapusan <span class="req">*</span></label>
                <input class="input" name="reason" required placeholder="mis. pembersihan log tahun lalu, sudah diarsipkan"></div>
              <div class="field"><label>&nbsp;</label>
                <button class="btn btn-danger" type="submit">Hapus <?= num($preview['count']) ?> Catatan Audit</button></div>
            </div>
          </form>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3>Kebijakan Audit Log</h3></div>
  <div class="card-body">
    <div class="grid g2">
      <div>
        <p class="muted">Aktivitas berikut otomatis tercatat lengkap dengan data sebelum &amp; sesudah, user, cabang, dan waktu:</p>
        <ul class="list-clean">
          <?php foreach (array_slice($tracked, 0, 12) as $t): ?>
            <li><span><?= e($t) ?></span><span class="small muted">tercatat</span></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <ul class="list-clean">
          <?php foreach (array_slice($tracked, 12) as $t): ?>
            <li><span><?= e($t) ?></span><span class="small muted">tercatat</span></li>
          <?php endforeach; ?>
        </ul>
        <div class="notice mt-2">Kasir dan Admin/Dokter <strong>tidak dapat menghapus</strong> Audit Log maupun Inventory Movement.
          Penghapusan audit log dapat dilakukan <strong>hanya oleh Super Admin</strong>, per rentang tanggal, dengan konfirmasi dua tahap,
          dan tindakan pembersihannya sendiri tetap tercatat.</div>
      </div>
    </div>
  </div>
</div>
<?php page_foot(); ?>
