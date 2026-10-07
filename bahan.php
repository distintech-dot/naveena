<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('material.view');
$user = current_user();
$scope = scope_branch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        require_perm('material.manage');
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $code = trim((string)$_POST['code']);
            $name = trim((string)$_POST['name']);
            $cat  = trim((string)($_POST['category'] ?? ''));
            $price = (float)($_POST['price'] ?? 0);
            $min  = (float)($_POST['minimum_stock'] ?? 0);
            $unit = trim((string)($_POST['unit'] ?? 'pcs'));
            $sup  = supplier_input('supplier_name');
            $branch = is_owner_level() ? resolve_branch_input($_POST['branch_id'] ?? 0) : (int)$user['branch_id'];
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            if ($code === '' || $name === '') throw new RuntimeException('Kode dan nama bahan wajib diisi.');
            if ($price < 0 || $min < 0) throw new RuntimeException('Harga/minimum stok tidak boleh negatif.');
            assert_branch($branch);
            $dupe = one('SELECT id FROM treatment_materials WHERE code = ? AND branch_id = ? AND id <> ?', [$code, $branch, $id]);
            if ($dupe) throw new RuntimeException('Kode bahan ' . $code . ' sudah dipakai di cabang ini.');
            if ($id > 0) {
                $old = one('SELECT * FROM treatment_materials WHERE id = ?', [$id]);
                if (!$old) throw new RuntimeException('Bahan tidak ditemukan.');
                assert_branch((int)$old['branch_id']);
                assert_branch_unchanged('treatment_materials', $id, (int)$branch);
                q('UPDATE treatment_materials SET code=?, name=?, category=?, price=?, minimum_stock=?, unit=?, supplier_name=?, branch_id=?, status=?, updated_at=datetime("now","localtime") WHERE id=?',
                    [$code, $name, $cat, $price, $min, $unit, $sup, $branch, $status, $id]);
                q('UPDATE inventory SET minimum_stock=?, status=?, branch_id=? WHERE item_type="material" AND item_id=?', [$min, $status, $branch, $id]);
                audit('Edit Bahan Treatment', 'Master Data', $id, $old, ['code' => $code, 'name' => $name, 'price' => $price, 'status' => $status], 'Perubahan bahan treatment');
                flash('Bahan treatment berhasil diperbarui.');
            } else {
                $openStock = qty_parse($_POST['opening_stock'] ?? 0);
                if ($openStock < 0) throw new RuntimeException('Stok awal tidak boleh negatif.');
                $pdo = db();
                $pdo->exec('BEGIN IMMEDIATE');
                try {
                    q('INSERT INTO treatment_materials (code, name, category, stock, minimum_stock, unit, price, supplier_name, branch_id, status, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,datetime("now","localtime"))', [$code, $name, $cat, 0, $min, $unit, $price, $sup, $branch, $status]);
                    $newId = (int)$pdo->lastInsertId();
                    if ($openStock > 0) inv_apply('material', $newId, $openStock, 'Stok Awal', 'Stok awal bahan baru');
                    else q('INSERT INTO inventory (item_type, item_id, branch_id, stock, minimum_stock, status) VALUES ("material",?,?,0,?,?)', [$newId, $branch, $min, $status]);
                    $pdo->exec('COMMIT');
                } catch (Throwable $ex) {
                    $pdo->exec('ROLLBACK');
                    throw $ex;
                }
                audit('Tambah Bahan Treatment', 'Master Data', $newId, null, ['code' => $code, 'name' => $name, 'branch_id' => $branch], 'Bahan treatment baru');
                flash('Bahan treatment ditambahkan.');
            }
        }

        if ($act === 'stock') {
            $id = (int)$_POST['id'];
            $m = one('SELECT * FROM treatment_materials WHERE id = ?', [$id]);
            if (!$m) throw new RuntimeException('Bahan tidak ditemukan.');
            assert_branch((int)$m['branch_id']);
            $dir = ($_POST['direction'] ?? 'in') === 'out' ? 'out' : 'in';
            $type = (string)($_POST['type'] ?? 'Penambahan');
            if (!in_array($type, MOVEMENT_TYPES, true) || $type === 'Penjualan') throw new RuntimeException('Jenis pergerakan stok tidak valid.');
            $qty = abs(qty_parse($_POST['qty'] ?? 0));
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($qty <= 0) throw new RuntimeException('Jumlah stok harus lebih dari 0.');
            if ($dir === 'out' && $reason === '') throw new RuntimeException('Alasan pengurangan stok wajib diisi.');
            $signed = $dir === 'in' ? $qty : -1 * $qty;
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                inv_apply('material', $id, $signed, $dir === 'in' ? ($type === 'Pengurangan' ? 'Penambahan' : $type) : $type, $reason);
                $pdo->exec('COMMIT');
            } catch (Throwable $ex) {
                $pdo->exec('ROLLBACK');
                throw $ex;
            }
            audit('Perubahan Stok Bahan', 'Inventory', $id, ['stock' => $m['stock']], ['perubahan' => $signed, 'jenis' => $type], $reason ?: 'Penambahan stok');
            flash('Stok ' . $m['name'] . ' diperbarui sebesar ' . ($signed > 0 ? '+' : '') . qty_unit($signed, $m['unit']) . '.');
        }

        if ($act === 'toggle') {
            $id = (int)$_POST['id'];
            $m = one('SELECT * FROM treatment_materials WHERE id = ?', [$id]);
            if (!$m) throw new RuntimeException('Bahan tidak ditemukan.');
            assert_branch((int)$m['branch_id']);
            $status = $m['status'] === 'active' ? 'inactive' : 'active';
            q('UPDATE treatment_materials SET status=?, updated_at=datetime("now","localtime") WHERE id=?', [$status, $id]);
            q('UPDATE inventory SET status=? WHERE item_type="material" AND item_id=?', [$status, $id]);
            audit($status === 'inactive' ? 'Nonaktifkan Bahan' : 'Aktifkan Bahan', 'Master Data', $id, ['status' => $m['status']], ['status' => $status], 'Perubahan status bahan');
            flash('Status bahan diubah menjadi ' . $status . '.');
        }

        if ($act === 'delete') {
            $id = (int)$_POST['id'];
            $m = one('SELECT * FROM treatment_materials WHERE id = ?', [$id]);
            if (!$m) throw new RuntimeException('Bahan tidak ditemukan.');
            assert_branch((int)$m['branch_id']);
            $moved = (int)scalar('SELECT COUNT(*) FROM inventory_movements WHERE item_type="material" AND item_id = ? AND type <> "Stok Awal"', [$id]);
            if ($moved > 0) throw new RuntimeException('Bahan "' . $m['name'] . '" sudah memiliki riwayat pergerakan stok dan tidak dapat dihapus. Silakan nonaktifkan bahan ini.');
            /* Bahan yang pernah dipakai pada transaksi tidak boleh hilang: histori
               pemakaian pada order/detail pasien harus tetap bisa ditelusuri. */
            $usedInOrder = (int)scalar('SELECT COUNT(*) FROM order_items WHERE material_id = ?', [$id]);
            if ($usedInOrder > 0) throw new RuntimeException('Bahan "' . $m['name'] . '" sudah dipakai pada ' . $usedInOrder
                . ' baris transaksi dan tidak dapat dihapus. Silakan nonaktifkan bahan ini agar tidak muncul lagi di Order Baru.');
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                q('DELETE FROM inventory_movements WHERE item_type="material" AND item_id=?', [$id]);
                q('DELETE FROM inventory WHERE item_type="material" AND item_id=?', [$id]);
                q('DELETE FROM treatment_materials WHERE id=?', [$id]);
                $pdo->exec('COMMIT');
            } catch (Throwable $ex) {
                $pdo->exec('ROLLBACK');
                throw $ex;
            }
            audit('Hapus Bahan Treatment', 'Master Data', $id, $m, null, 'Belum ada riwayat pergerakan stok');
            flash('Bahan dihapus.');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: bahan.php');
    exit;
}

$q = gp('q');
$where = ['1=1'];
$params = [];
if ($scope !== null) { $where[] = 'm.branch_id = ?'; $params[] = $scope; }
if ($q !== '') { $where[] = '(m.name LIKE ? OR m.code LIKE ? OR m.category LIKE ?)'; $t = '%' . $q . '%'; array_push($params, $t, $t, $t); }
if (gp('status') !== '') { $where[] = 'm.status = ?'; $params[] = gp('status'); }
if (gp('alert') === '1') { $where[] = 'm.stock <= m.minimum_stock'; }
$w = implode(' AND ', $where);
$page = page_no(); $pp = per_page();
$total = (int)scalar("SELECT COUNT(*) FROM treatment_materials m WHERE {$w}", $params);
$rows = all("SELECT m.*, b.name AS branch_name,
                    (SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id
                      WHERE oi.material_id = m.id AND o.status='paid') used,
                    (SELECT COUNT(DISTINCT oi.order_id) FROM order_items oi JOIN orders o ON o.id=oi.order_id
                      WHERE oi.material_id = m.id AND o.status='paid') used_trx
             FROM treatment_materials m JOIN branches b ON b.id=m.branch_id
             WHERE {$w} ORDER BY (m.stock <= m.minimum_stock) DESC, m.name LIMIT {$pp} OFFSET " . (($page - 1) * $pp), $params);
/* PEMBATASAN CABANG (audit isolasi ronde 56): pilihan supplier mengikuti cabang akun. */
[$supSql, $supParams] = bscope('branch_id');
$suppliers = all('SELECT DISTINCT name FROM suppliers WHERE status="active"' . $supSql . ' ORDER BY name', $supParams);
$edit = gp('action') === 'edit' ? one('SELECT * FROM treatment_materials WHERE id = ?', [(int)gp('id')]) : null;
if ($edit) assert_branch((int)$edit['branch_id']);
$stockItem = gp('action') === 'stock' ? one('SELECT * FROM treatment_materials WHERE id = ?', [(int)gp('id')]) : null;
if ($stockItem) assert_branch((int)$stockItem['branch_id']);
$openModal = ($edit || gp('action') === 'new') ? 'bhModal' : ($stockItem ? 'bhStockModal' : '');

page_head('Bahan Treatment', 'bahan');
?>
<div class="page-head">
  <div><h2>Bahan Treatment</h2><p class="muted">Bahan habis pakai untuk tindakan treatment beserta stok dan suppliernya.</p></div>
  <?php /* Urutan tombol (permintaan pemilik): Tambah Bahan paling kiri. */ ?>
  <div class="page-actions">
    <?php if (has_perm('material.manage')): ?>
      <button class="btn btn-primary" data-modal-open="bhModal" onclick="resetBhForm()"><?= icon('plus-circle') ?> Tambah Bahan</button>
    <?php endif; ?>
    <?php if (has_perm('export.data')): ?>
      <a class="btn" href="export.php?type=inventory&format=csv"><?= icon('download') ?> CSV</a>
      <a class="btn" href="export.php?type=inventory&format=excel">Excel</a>
    <?php endif; ?>
    <?php if (has_perm('material.manage')): ?><a class="btn" href="import.php?type=bahan"><?= icon('upload') ?> Import</a><?php endif; ?>
    <?php if (is_super()): ?>
      <a class="btn btn-danger" href="purge.php?menu=bahan"
         title="Khusus Super Admin — hapus seluruh data menu ini">Hapus Semua Bahan Treatment</a>
    <?php endif; ?>
  </div>
</div>

<div class="card tight">
  <form class="filter-bar" method="get">
    <div class="field searchbox"><label>Cari</label><span><?= icon('search') ?></span>
      <input class="input input-sm" name="q" value="<?= e($q) ?>" placeholder="Nama / kode / kategori"></div>
    <div class="field"><label>Status</label>
      <select class="input input-sm" name="status">
        <option value="">Semua</option>
        <option value="active"<?= gp('status') === 'active' ? ' selected' : '' ?>>Aktif</option>
        <option value="inactive"<?= gp('status') === 'inactive' ? ' selected' : '' ?>>Nonaktif</option>
      </select></div>
    <div class="field"><label>Stok</label>
      <select class="input input-sm" name="alert">
        <option value="">Semua</option>
        <option value="1"<?= gp('alert') === '1' ? ' selected' : '' ?>>Hanya stok menipis/habis</option>
      </select></div>
    <?= branch_filter_field() ?>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    <a class="btn btn-sm" href="bahan.php">Reset</a>
    <?= per_page_inline() ?>
  </form>
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state('Belum ada data bahan treatment.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Kode</th><th>Bahan</th><th>Kategori</th><th class="num">Stok</th><th class="num">Min</th><th>Satuan</th><th class="num">Harga</th><th class="num">Dipakai</th><th>Supplier</th><th>Cabang</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $low = (float)$r['stock'] <= (float)$r['minimum_stock']; ?>
        <tr>
          <td class="small nowrap"><?= e($r['code']) ?></td>
          <td><strong><?= e($r['name']) ?></strong></td>
          <td class="small"><?= e($r['category'] ?: '-') ?></td>
          <td class="num"><strong><?= qty_text($r['stock']) ?></strong>
            <?= (float)$r['stock'] <= 0 ? ' ' . badge('HABIS', 'red') : ($low ? ' ' . badge('MENIPIS', 'yellow') : '') ?></td>
          <td class="num"><?= qty_text($r['minimum_stock']) ?></td>
          <td class="small"><?= e($r['unit']) ?></td>
          <td class="num"><?= money($r['price']) ?></td>
          <td class="num">
            <?php if ((float)$r['used'] > 0): ?>
              <a href="order.php?material=<?= (int)$r['id'] ?>" title="Lihat transaksi yang memakai bahan ini"><?= qty_unit($r['used'], $r['unit']) ?></a>
              <div class="small muted"><?= num($r['used_trx']) ?> transaksi</div>
            <?php else: ?>
              <span class="muted">belum dipakai</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= e($r['supplier_name'] ?: '-') ?></td>
          <td class="small"><?= e($r['branch_name']) ?></td>
          <td><?= badge($r['status'] === 'active' ? 'Aktif' : 'Nonaktif', $r['status'] === 'active' ? 'green' : 'gray') ?></td>
          <td class="nowrap">
            <?php if (has_perm('material.manage')): ?>
            <div class="row-actions">
              <a class="btn btn-sm btn-primary" href="bahan.php?action=stock&id=<?= (int)$r['id'] ?>">Stok</a>
              <a class="btn btn-sm" href="bahan.php?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
              <form method="post" data-confirm="Ubah status bahan ini?">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= $r['status'] === 'active' ? 'Nonaktif' : 'Aktifkan' ?></button>
              </form>
              <form method="post" data-confirm="<?= (float)$r['used'] > 0 ? 'Bahan ini sudah dipakai pada transaksi (lihat kolom Dipakai) sehingga TIDAK dapat dihapus — sistem akan menolak. Gunakan tombol Nonaktifkan agar tidak muncul lagi di pilihan Order Baru. Tetap coba hapus?' : 'Hapus bahan ini?' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
              </form>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?= pagination($total, $pp, $page) ?>
</div>

<div class="modal<?= $openModal === 'bhModal' ? ' open' : '' ?>" id="bhModal">
  <div class="modal-box wide">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="bh_id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="modal-head"><h3 id="bh_title"><?= $edit ? 'Edit Bahan' : 'Tambah Bahan Treatment' ?></h3>
        <button type="button" class="icon-btn" data-modal-close="bhModal"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <div class="form-grid g3">
          <div class="field"><label>Kode Bahan <span class="req">*</span></label><input class="input" name="code" id="bh_code" value="<?= e($edit['code'] ?? '') ?>" required></div>
          <div class="field" style="grid-column:1/-1"><label>Nama Bahan <span class="req">*</span></label><input class="input" name="name" id="bh_name" value="<?= e($edit['name'] ?? '') ?>" required></div>
          <div class="field"><label>Kategori</label><input class="input" name="category" id="bh_cat" value="<?= e($edit['category'] ?? '') ?>"></div>
          <div class="field"><label>Satuan</label><input class="input" name="unit" id="bh_unit" value="<?= e($edit['unit'] ?? 'pcs') ?>"></div>
          <div class="field"><label>Harga</label><input class="input" type="number" min="0" step="500" name="price" id="bh_price" value="<?= (float)($edit['price'] ?? 0) ?>"></div>
          <?php if (!$edit): ?>
            <div class="field"><label>Stok Awal</label><input class="input" type="number" min="0" step="0.01" name="opening_stock" id="bh_open" inputmode="decimal" value="0"></div>
          <?php endif; ?>
          <div class="field"><label>Minimum Stok</label><input class="input" type="number" min="0" step="0.01" name="minimum_stock" id="bh_min" value="<?= (float)($edit['minimum_stock'] ?? 0) ?>"></div>
          <div class="field"><label>Supplier</label>
            <?= opt_supplier('supplier_name', (string)($edit['supplier_name'] ?? ''), 'bh') ?>
            <span class="hint">Daftar diambil dari menu <a href="suppliers.php">Supplier</a>. Bila belum ada, pilih "Isi manual".</span></div>
          <?php if (is_owner_level()): ?>
          <div class="field"><label>Cabang <span class="req">*</span></label><select class="input" name="branch_id" id="bh_branch"><?= opt_branches($edit['branch_id'] ?? ($scope ?: null)) ?></select></div>
          <?php endif; ?>
          <div class="field"><label>Status</label>
            <select class="input" name="status" id="bh_status">
              <option value="active"<?= ($edit['status'] ?? '') === 'active' ? ' selected' : '' ?>>Aktif</option>
              <option value="inactive"<?= ($edit['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Nonaktif</option>
            </select></div>
        </div>
      </div>
      <div class="modal-foot"><button type="button" class="btn" data-modal-close="bhModal">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan Bahan</button></div>
    </form>
  </div>
</div>

<div class="modal<?= $stockItem ? ' open' : '' ?>" id="bhStockModal">
  <div class="modal-box sheet">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="stock"><input type="hidden" name="id" value="<?= (int)($stockItem['id'] ?? 0) ?>">
      <div class="modal-head"><h3>Kelola Stok — <?= e($stockItem['name'] ?? '') ?></h3>
        <button type="button" class="icon-btn" data-modal-close="bhStockModal"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <?php if ($stockItem): ?>
          <div class="notice mb-2">Stok saat ini: <strong><?= qty_unit($stockItem['stock'], $stockItem['unit']) ?></strong> · Minimum <?= qty_unit($stockItem['minimum_stock'], $stockItem['unit']) ?></div>
          <div class="form-grid g2">
            <div class="field"><label>Arah Pergerakan</label>
              <select class="input" name="direction">
                <option value="in">Stok Masuk (tambah)</option>
                <option value="out">Stok Keluar (kurang)</option>
              </select></div>
            <div class="field"><label>Jenis</label>
              <select class="input" name="type">
                <?php foreach (MOVEMENT_TYPES as $t): if ($t === 'Penjualan' || $t === 'Stok Awal') continue; ?>
                  <option value="<?= $t ?>"><?= $t ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="field"><label>Jumlah <span class="req">*</span></label><input class="input" type="text" inputmode="decimal" name="qty" required placeholder="mis. 0,5"><span class="hint">Satuan mengikuti data barang. Boleh pecahan (mis. 0,5 liter).</span></div>
            <div class="field"><label>Alasan / Keterangan</label><input class="input" name="reason" placeholder="wajib untuk pengurangan"></div>
          </div>
        <?php endif; ?>
      </div>
      <div class="modal-foot"><button type="button" class="btn" data-modal-close="bhStockModal">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan Perubahan Stok</button></div>
    </form>
  </div>
</div>
<script>
function resetBhForm() {
  document.getElementById('bh_id').value = 0;
  document.getElementById('bh_title').textContent = 'Tambah Bahan Treatment';
  ['bh_code','bh_name','bh_cat','bh_sup'].forEach(function (id) { var el = document.getElementById(id); if (el) el.value = ''; });
  ['bh_price','bh_min'].forEach(function (id) { document.getElementById(id).value = 0; });
  var open = document.getElementById('bh_open'); if (open) open.value = 0;
  document.getElementById('bh_unit').value = 'pcs';
}
</script>
<?php page_foot(); ?>
