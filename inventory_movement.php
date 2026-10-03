<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('inventory.view');
$scope = scope_branch();

$alertOnly = gp('alert') === '1';
$where = ['1=1'];
$params = [];
if ($scope !== null) { $where[] = 'm.branch_id = ?'; $params[] = $scope; }
if (gp('item_type') !== '') { $where[] = 'm.item_type = ?'; $params[] = gp('item_type'); }
if (gp('type') !== '') { $where[] = 'm.type = ?'; $params[] = gp('type'); }
if (gp('from') !== '') { $where[] = 'date(m.created_at) >= ?'; $params[] = gp('from'); }
if (gp('to') !== '') { $where[] = 'date(m.created_at) <= ?'; $params[] = gp('to'); }
if (gp('q') !== '') { $where[] = '(m.item_name LIKE ? OR m.item_code LIKE ? OR m.user_name LIKE ?)'; $t = '%' . gp('q') . '%'; array_push($params, $t, $t, $t); }
$w = implode(' AND ', $where);
$page = page_no(); $pp = per_page();
$total = (int)scalar("SELECT COUNT(*) FROM inventory_movements m WHERE {$w}", $params);
$rows = all("SELECT m.*, b.name AS branch_name FROM inventory_movements m JOIN branches b ON b.id=m.branch_id
             WHERE {$w} ORDER BY m.id DESC LIMIT {$pp} OFFSET " . (($page - 1) * $pp), $params);
$alerts = stock_alerts(60);

/* Ringkasan stok per cabang */
$bs = $scope === null ? ['', []] : [' AND branch_id = ?', [$scope]];
$sumSk = one("SELECT COUNT(*) n, COALESCE(SUM(stock*purchase_price),0) nilai FROM skincare_products WHERE status='active' {$bs[0]}", $bs[1]);
$sumMt = one("SELECT COUNT(*) n, COALESCE(SUM(stock*price),0) nilai FROM treatment_materials WHERE status='active' {$bs[0]}", $bs[1]);

page_head('Stok & Inventory Movement', 'inventory_movement');
?>
<div class="page-head">
  <div><h2>Stok &amp; Inventory Movement</h2><p class="muted">Seluruh pergerakan stok tercatat permanen dan tidak dapat dihapus.</p></div>
  <div class="page-actions">
    <?php if (has_perm('export.data')): ?>
      <a class="btn" href="export.php?type=movement&format=csv&<?= e(qs([], ['page', 'per_page'])) ?>"><?= icon('download') ?> CSV</a>
      <a class="btn" href="export.php?type=movement&format=excel&<?= e(qs([], ['page', 'per_page'])) ?>">Excel</a>
      <a class="btn" href="export.php?type=movement&format=pdf&<?= e(qs([], ['page', 'per_page'])) ?>" target="_blank">PDF</a>
    <?php endif; ?>
    <a class="btn<?= $alertOnly ? ' btn-primary' : '' ?>" href="?alert=1"><?= icon('bell') ?> Stok Menipis (<?= num(count($alerts)) ?>)</a>
    <?php if (is_super()): ?>
      <a class="btn btn-danger" href="purge.php?menu=inventory_movement"
         title="Khusus Super Admin — hapus seluruh data menu ini">Hapus Semua Movement</a>
    <?php endif; ?>
  </div>
</div>

<div class="grid g3">
  <div class="stat"><span class="lbl">Produk Skincare Aktif</span><span class="val"><?= num($sumSk['n']) ?></span><span class="sub">Nilai stok <?= money($sumSk['nilai']) ?></span></div>
  <div class="stat"><span class="lbl">Bahan Treatment Aktif</span><span class="val"><?= num($sumMt['n']) ?></span><span class="sub">Nilai stok <?= money($sumMt['nilai']) ?></span></div>
  <div class="stat <?= count($alerts) ? '' : 'leaf' ?>"><span class="lbl">Item Stok Menipis/Habis</span><span class="val"><?= num(count($alerts)) ?></span><span class="sub"><?= count($alerts) ? 'Perlu segera ditindaklanjuti' : 'Semua stok aman' ?></span></div>
</div>

<?php if ($alerts): ?>
<div class="card mt-2">
  <div class="card-head"><h3>⚠️ Notifikasi Stok</h3><span class="muted">Stok ≤ minimum stok</span></div>
  <div class="card-body">
    <ul class="alert-list">
      <?php foreach ($alerts as $a): ?>
        <li>
          <div><strong><?= e($a['name']) ?></strong> <span class="small muted"><?= e($a['code']) ?> · <?= e($a['kind'] === 'skincare' ? 'Skincare' : 'Bahan') ?> · <?= e($a['branch_name']) ?></span></div>
          <div class="right nowrap">
            <?= (float)$a['stock'] <= 0 ? badge('STOK HABIS', 'red') : badge('STOK MENIPIS', 'yellow') ?>
            <span class="small muted"><?= qty_text($a['stock']) ?>/<?= qty_text($a['minimum_stock']) ?> <?= e($a['unit']) ?></span>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<div class="card tight">
  <form class="filter-bar" method="get">
    <input type="hidden" name="alert" value="">
    <div class="field searchbox"><label>Cari</label><span><?= icon('search') ?></span>
      <input class="input input-sm" name="q" value="<?= e(gp('q')) ?>" placeholder="Nama item / kode / user"></div>
    <div class="field"><label>Jenis Item</label>
      <select class="input input-sm" name="item_type">
        <option value="">Semua</option>
        <option value="skincare"<?= gp('item_type') === 'skincare' ? ' selected' : '' ?>>Skincare</option>
        <option value="material"<?= gp('item_type') === 'material' ? ' selected' : '' ?>>Bahan Treatment</option>
      </select></div>
    <div class="field"><label>Jenis Pergerakan</label>
      <select class="input input-sm" name="type">
        <option value="">Semua</option>
        <?php foreach (MOVEMENT_TYPES as $t): ?><option value="<?= $t ?>"<?= gp('type') === $t ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Dari</label><input class="input input-sm" type="date" name="from" value="<?= e(gp('from')) ?>"></div>
    <div class="field"><label>Sampai</label><input class="input input-sm" type="date" name="to" value="<?= e(gp('to')) ?>"></div>
    <?php if (is_owner_level()): ?>
    <div class="field"><label>Cabang</label>
      <select class="input input-sm" name="branch">
        <option value="all"<?= $scope === null ? ' selected' : '' ?>>Semua Cabang</option>
        <?php foreach (branches() as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $scope === (int)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    <a class="btn btn-sm" href="inventory_movement.php">Reset</a>
    <?= per_page_inline() ?>
  </form>
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state('Belum ada pergerakan stok yang cocok dengan filter.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>No</th><th>Tanggal</th><th>Jam</th><th>Produk/Bahan</th><th>Jenis</th>
        <th class="num">Stok Sebelum</th><th class="num">Perubahan</th><th class="num">Stok Sesudah</th>
        <th>User</th><th>Cabang</th><th>Keterangan</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $m): ?>
        <tr>
          <td class="small"><?= (int)$m['id'] ?></td>
          <td class="nowrap small"><?= e(date('d/m/Y', strtotime($m['created_at']))) ?></td>
          <td class="nowrap small"><?= e(date('H:i', strtotime($m['created_at']))) ?></td>
          <td><?= e($m['item_name']) ?><div class="small muted"><?= e($m['item_code']) ?> · <?= e($m['item_type'] === 'skincare' ? 'Skincare' : 'Bahan') ?></div></td>
          <td><?= badge($m['type'], in_array($m['type'], ['Pengurangan', 'Barang Rusak'], true) ? 'red' : (in_array($m['type'], ['Penjualan', 'Pemakaian Internal'], true) ? 'blue' : 'green')) ?></td>
          <td class="num"><?= qty_text($m['stock_before']) ?></td>
          <td class="num"><?= ((float)$m['quantity'] > 0 ? '+' : '') . qty_text($m['quantity']) ?></td>
          <td class="num"><?= qty_text($m['stock_after']) ?></td>
          <td class="small"><?= e($m['user_name'] ?: '-') ?></td>
          <td class="small"><?= e($m['branch_name']) ?></td>
          <td class="small"><?= e($m['reason'] ?: '-') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?= pagination($total, $pp, $page) ?>
</div>
<div class="notice">Inventory Movement bersifat permanen: Kasir maupun Admin/Dokter tidak dapat menghapus catatan pergerakan stok.</div>
<?php page_foot(); ?>
