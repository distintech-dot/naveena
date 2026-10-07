<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('order.view');
$user = current_user();

/* HAPUS DATA PER PERIODE (khusus Super Admin). */
retention_handle_post('order');
$scope = scope_branch();

$where = ['1=1'];
$params = [];
if ($scope !== null) { $where[] = 'o.branch_id = ?'; $params[] = $scope; }
$q = gp('q');
if ($q !== '') {
    $where[] = '(o.invoice_number LIKE ? OR p.name LIKE ? OR p.patient_number LIKE ?)';
    $t = '%' . $q . '%';
    array_push($params, $t, $t, $t);
}
if (gp('from') !== '') { $where[] = 'date(o.created_at) >= ?'; $params[] = gp('from'); }
if (gp('to') !== '')   { $where[] = 'date(o.created_at) <= ?'; $params[] = gp('to'); }
if (gp('patient') !== '') { $where[] = 'o.patient_id = ?'; $params[] = (int)gp('patient'); }
if (gp('cashier') !== '') { $where[] = 'o.user_id = ?'; $params[] = (int)gp('cashier'); }
if (gp('status') !== '')  { $where[] = 'o.status = ?'; $params[] = gp('status'); }
if (gp('method') !== '')  { $where[] = 'EXISTS (SELECT 1 FROM payments pm WHERE pm.order_id=o.id AND pm.method = ?)'; $params[] = gp('method'); }
if (gp('treatment') !== '') { $where[] = 'EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id AND oi.treatment_id = ?)'; $params[] = (int)gp('treatment'); }
if (gp('skincare') !== '')  { $where[] = 'EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id AND oi.skincare_id = ?)'; $params[] = (int)gp('skincare'); }
/* Filter pemakaian bahan treatment (bahan tidak dijual, tetap bisa ditelusuri). */
if (gp('material') !== '')  { $where[] = 'EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id AND oi.material_id = ?)'; $params[] = (int)gp('material'); }
$w = implode(' AND ', $where);
$base = "FROM orders o JOIN patients p ON p.id=o.patient_id JOIN branches b ON b.id=o.branch_id WHERE {$w}";

$page = page_no();
$pp   = per_page();
$total = (int)scalar("SELECT COUNT(*) {$base}", $params);
$sum   = one("SELECT COALESCE(SUM(o.total),0) total, COALESCE(SUM(o.discount),0) disc,
                     COALESCE(SUM(CASE WHEN o.status='paid' THEN o.total ELSE 0 END),0) bersih {$base}", $params);
$rows = all("SELECT o.*, p.name AS patient_name, p.patient_number, p.email AS patient_email, b.name AS branch_name,
                    (SELECT GROUP_CONCAT(DISTINCT pm.method) FROM payments pm WHERE pm.order_id = o.id) methods,
                    /* Item yang DITAGIHKAN: treatment/skincare/paket (bahan & isi
                       paket berharga 0 tidak dihitung sebagai item jual). */
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id
                      AND oi.item_type IN ('treatment','skincare','package')) items,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id AND oi.item_type = 'material') materials
             {$base} ORDER BY o.created_at DESC, o.id DESC LIMIT {$pp} OFFSET " . (($page - 1) * $pp), $params);

$cashiers = all('SELECT DISTINCT u.id, u.name FROM users u JOIN orders o ON o.user_id = u.id WHERE 1=1 '
    . ($scope !== null ? ' AND o.branch_id = ' . (int)$scope : '') . ' ORDER BY u.name');
$treatments = all('SELECT id, name FROM treatments WHERE 1=1 ' . ($scope !== null ? ' AND branch_id = ' . (int)$scope : '') . ' ORDER BY name');
$skincares  = all('SELECT id, name FROM skincare_products WHERE 1=1 ' . ($scope !== null ? ' AND branch_id = ' . (int)$scope : '') . ' ORDER BY name');
$materials  = all('SELECT id, name FROM treatment_materials WHERE 1=1 ' . ($scope !== null ? ' AND branch_id = ' . (int)$scope : '') . ' ORDER BY name');

page_head('Riwayat Order', 'order');
?>
<div class="page-head">
  <div><h2>Riwayat Order</h2><p class="muted"><?= num($total) ?> transaksi · Total <?= money($sum['total']) ?> · Pendapatan bersih <?= money($sum['bersih']) ?></p></div>
  <div class="page-actions">
    <?php if (has_perm('order.manage')): ?><a class="btn btn-primary" href="order_baru.php"><?= icon('plus-circle') ?> Order Baru</a><?php endif; ?>
    <?php if (has_perm('export.data')): ?>
      <a class="btn" href="export.php?type=transaksi&format=csv&<?= e(qs([], ['page', 'per_page'])) ?>"><?= icon('download') ?> CSV</a>
      <a class="btn" href="export.php?type=transaksi&format=excel&<?= e(qs([], ['page', 'per_page'])) ?>">Excel</a>
      <a class="btn btn-primary" href="export.php?type=transaksi&format=xlsx&<?= e(qs([], ['page', 'per_page'])) ?>"
         title="Excel asli berisi data transaksi + lembar foto/lampiran klinis pasien pada periode ini"><?= icon('download') ?> Excel + Foto Klinis</a>
      <a class="btn" href="export.php?type=transaksi&format=pdf&<?= e(qs([], ['page', 'per_page'])) ?>" target="_blank">PDF</a>
    <?php endif; ?>
    <?= retention_manual_button('order') ?>
    <?php if (is_super()): ?>
      <a class="btn btn-danger" href="purge.php?menu=order"
         title="Khusus Super Admin — hapus seluruh data menu ini">Hapus Semua Riwayat Order</a>
    <?php endif; ?>
  </div>
</div>

<div class="card tight">
  <form class="filter-bar" method="get">
    <div class="field searchbox"><label>Cari</label><span><?= icon('search') ?></span>
      <input class="input input-sm" name="q" value="<?= e($q) ?>" placeholder="Invoice / nama pasien"></div>
    <div class="field"><label>Dari</label><input class="input input-sm" type="date" name="from" value="<?= e(gp('from')) ?>"></div>
    <div class="field"><label>Sampai</label><input class="input input-sm" type="date" name="to" value="<?= e(gp('to')) ?>"></div>
    <div class="field"><label>Status</label>
      <select class="input input-sm" name="status">
        <option value="">Semua</option>
        <?php foreach (['paid' => 'Lunas', 'void' => 'Void', 'refund' => 'Refund'] as $k => $v): ?>
          <option value="<?= $k ?>"<?= gp('status') === $k ? ' selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label>Metode Pembayaran</label>
      <select class="input input-sm" name="method">
        <option value="">Semua</option>
        <?php foreach (PAY_METHODS as $m): ?><option value="<?= $m ?>"<?= gp('method') === $m ? ' selected' : '' ?>><?= $m ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Kasir</label>
      <select class="input input-sm" name="cashier">
        <option value="">Semua</option>
        <?php foreach ($cashiers as $c): ?><option value="<?= (int)$c['id'] ?>"<?= gp('cashier') === (string)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Treatment</label>
      <select class="input input-sm" name="treatment">
        <option value="">Semua</option>
        <?php foreach ($treatments as $t): ?><option value="<?= (int)$t['id'] ?>"<?= gp('treatment') === (string)$t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Skincare</label>
      <select class="input input-sm" name="skincare">
        <option value="">Semua</option>
        <?php foreach ($skincares as $t): ?><option value="<?= (int)$t['id'] ?>"<?= gp('skincare') === (string)$t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Bahan Treatment</label>
      <select class="input input-sm" name="material">
        <option value="">Semua</option>
        <?php foreach ($materials as $t): ?><option value="<?= (int)$t['id'] ?>"<?= gp('material') === (string)$t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
      </select>
      <span class="hint">Menampilkan transaksi yang memakai bahan tersebut (bahan tidak ditagihkan).</span></div>
    <?php if (is_owner_level()): ?>
    <div class="field"><label>Cabang</label>
      <select class="input input-sm" name="branch">
        <option value="all"<?= $scope === null ? ' selected' : '' ?>>Semua Cabang</option>
        <?php foreach (branches() as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $scope === (int)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    <a class="btn btn-sm" href="order.php">Reset</a>
    <?= per_page_inline() ?>
  </form>
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state('Belum ada transaksi yang cocok dengan filter.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr>
        <th>Invoice</th><th>Tanggal</th><th>Pasien</th><th>Cabang</th><th>Kasir</th>
        <th class="num">Item</th><th class="num">Subtotal</th><th class="num">Diskon</th><th class="num">Total</th>
        <th>Bayar</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $o): ?>
        <tr>
          <td class="nowrap"><a href="order_detail.php?id=<?= (int)$o['id'] ?>"><?= e($o['invoice_number']) ?></a></td>
          <td class="nowrap small"><?= e(tgl($o['created_at'], true)) ?></td>
          <td><?= e($o['patient_name']) ?>
            <div class="small muted"><?= e($o['patient_number']) ?></div>
            <?php if (($o['patient_email'] ?? '') !== ''): ?>
              <div class="small muted"><?= e($o['patient_email']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small"><?= e($o['branch_name']) ?></td>
          <td class="small"><?= e($o['cashier_name'] ?: '-') ?></td>
          <td class="num"><?= num($o['items']) ?><?php if ((int)$o['materials'] > 0): ?>
            <div class="small muted">+<?= num($o['materials']) ?> bahan</div><?php endif; ?></td>
          <td class="num"><?= money($o['subtotal']) ?></td>
          <td class="num"><?= money($o['discount']) ?></td>
          <td class="num"><strong><?= money($o['total']) ?></strong></td>
          <td class="small"><?= e($o['methods'] ?: '-') ?></td>
          <td><?= order_status_badge($o['status']) ?></td>
          <td class="nowrap">
            <div class="row-actions">
              <a class="btn btn-sm" href="order_detail.php?id=<?= (int)$o['id'] ?>">Detail</a>
              <a class="btn btn-sm" href="struk.php?id=<?= (int)$o['id'] ?>&print=1" target="_blank">Struk</a>
              <a class="btn btn-sm" href="struk.php?id=<?= (int)$o['id'] ?>&format=pdf" title="Unduh struk PDF">PDF</a>
              <a class="btn btn-sm btn-leaf" href="struk.php?id=<?= (int)$o['id'] ?>&wa=1" title="Kirim struk ke WhatsApp pasien"><?= icon('whatsapp') ?></a>
              <?php if (has_perm('order.manage')): ?>
                <a class="btn btn-sm" href="order_detail.php?id=<?= (int)$o['id'] ?>#email" title="Kirim struk lewat email pasien"><?= icon('bell') ?> Email</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?= pagination($total, $pp, $page) ?>
</div>
<?php page_foot(); ?>
