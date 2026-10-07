<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/package_ui.php';
require_perm('skincare.view');
$user = current_user();
$scope = scope_branch();
const OUT_TYPES = ['Pengurangan', 'Barang Rusak', 'Pemakaian Internal', 'Koreksi'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        require_perm('skincare.manage');
        /* Paket produk (aksi pkg_save / pkg_delete / pkg_toggle). */
        if (package_ui_handle_post('product')) {
            header('Location: skincare.php');
            exit;
        }
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $code = trim((string)$_POST['code']);
            $name = trim((string)$_POST['name']);
            $cat  = trim((string)($_POST['category'] ?? ''));
            /* HPP produk = HARGA BELI (kolom `purchase_price`). Dipakai perhitungan
               laba di menu Keuangan; disimpan sebagai snapshot tiap transaksi. */
            $buy  = max(0.0, qty_parse($_POST['purchase_price'] ?? 0));
            $sell = (float)($_POST['selling_price'] ?? 0);
            $min  = (float)($_POST['minimum_stock'] ?? 0);
            $unit = trim((string)($_POST['unit'] ?? 'pcs'));
            $sup  = supplier_input('supplier_name');
            $branch = is_owner_level() ? resolve_branch_input($_POST['branch_id'] ?? 0) : (int)$user['branch_id'];
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            if ($code === '' || $name === '') throw new RuntimeException('Kode dan nama produk wajib diisi.');
            if ($buy < 0 || $sell < 0) throw new RuntimeException('Harga tidak boleh negatif.');
            if ($min < 0) throw new RuntimeException('Minimum stok tidak boleh negatif.');
            assert_branch($branch);
            $dupe = one('SELECT id FROM skincare_products WHERE code = ? AND branch_id = ? AND id <> ?', [$code, $branch, $id]);
            if ($dupe) throw new RuntimeException('Kode produk ' . $code . ' sudah dipakai di cabang ini.');

            if ($id > 0) {
                $old = one('SELECT * FROM skincare_products WHERE id = ?', [$id]);
                if (!$old) throw new RuntimeException('Produk tidak ditemukan.');
                assert_branch((int)$old['branch_id']);
                assert_branch_unchanged('skincare_products', $id, (int)$branch);
                q('UPDATE skincare_products SET code=?, name=?, category=?, purchase_price=?, selling_price=?, minimum_stock=?, unit=?, supplier_name=?, branch_id=?, status=?, updated_at=datetime("now","localtime") WHERE id=?',
                    [$code, $name, $cat, $buy, $sell, $min, $unit, $sup, $branch, $status, $id]);
                q('UPDATE inventory SET minimum_stock=?, status=?, branch_id=? WHERE item_type="skincare" AND item_id=?', [$min, $status, $branch, $id]);
                audit('Edit Produk Skincare', 'Master Data', $id, $old,
                    ['code' => $code, 'name' => $name, 'selling_price' => $sell, 'minimum_stock' => $min, 'status' => $status],
                    'Perubahan master skincare');
                flash('Produk skincare berhasil diperbarui.');
            } else {
                $openStock = qty_parse($_POST['opening_stock'] ?? 0);
                if ($openStock < 0) throw new RuntimeException('Stok awal tidak boleh negatif.');
                $pdo = db();
                $pdo->exec('BEGIN IMMEDIATE');
                try {
                    q('INSERT INTO skincare_products (code, name, category, purchase_price, selling_price, stock, minimum_stock, unit, supplier_name, branch_id, status, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime("now","localtime"))',
                        [$code, $name, $cat, $buy, $sell, 0, $min, $unit, $sup, $branch, $status]);
                    $newId = (int)$pdo->lastInsertId();
                    if ($openStock > 0) {
                        inv_apply('skincare', $newId, $openStock, 'Stok Awal', 'Stok awal produk baru');
                    } else {
                        q('INSERT INTO inventory (item_type, item_id, branch_id, stock, minimum_stock, status) VALUES ("skincare",?,?,0,?,?)', [$newId, $branch, $min, $status]);
                    }
                    $pdo->exec('COMMIT');
                } catch (Throwable $ex) {
                    $pdo->exec('ROLLBACK');
                    throw $ex;
                }
                audit('Tambah Produk Skincare', 'Master Data', $newId, null,
                    ['code' => $code, 'name' => $name, 'branch_id' => $branch, 'stok_awal' => $openStock], 'Produk skincare baru');
                flash('Produk skincare ditambahkan.' . ($openStock > 0 ? ' Stok awal ' . num($openStock) . ' ' . $unit . ' tercatat pada Inventory Movement.' : ''));
            }
        }

        if ($act === 'stock') {
            $id = (int)$_POST['id'];
            $p = one('SELECT * FROM skincare_products WHERE id = ?', [$id]);
            if (!$p) throw new RuntimeException('Produk tidak ditemukan.');
            assert_branch((int)$p['branch_id']);
            $dir  = ($_POST['direction'] ?? 'in') === 'out' ? 'out' : 'in';
            $type = (string)($_POST['type'] ?? 'Penambahan');
            if (!in_array($type, MOVEMENT_TYPES, true) || $type === 'Penjualan') throw new RuntimeException('Jenis pergerakan stok tidak valid.');
            $qty  = abs(qty_parse($_POST['qty'] ?? 0));
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($qty <= 0) throw new RuntimeException('Jumlah stok harus lebih dari 0.');
            if ($dir === 'out' && $reason === '') throw new RuntimeException('Alasan pengurangan stok wajib diisi (rusak / expired / hilang / koreksi / pemakaian internal / salah input).');
            $signed = $dir === 'in' ? $qty : -1 * $qty;
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                inv_apply('skincare', $id, $signed, $dir === 'in' ? ($type === 'Pengurangan' ? 'Penambahan' : $type) : $type, $reason, ['allow_negative' => false]);
                $pdo->exec('COMMIT');
            } catch (Throwable $ex) {
                $pdo->exec('ROLLBACK');
                throw $ex;
            }
            audit('Perubahan Stok Skincare', 'Inventory', $id,
                ['stock' => $p['stock']], ['perubahan' => $signed, 'jenis' => $type], $reason ?: 'Penambahan stok');
            flash('Stok ' . $p['name'] . ' diperbarui sebesar ' . ($signed > 0 ? '+' : '') . qty_unit($signed, $p['unit']) . '.');
        }

        if ($act === 'toggle') {
            $id = (int)$_POST['id'];
            $p = one('SELECT * FROM skincare_products WHERE id = ?', [$id]);
            if (!$p) throw new RuntimeException('Produk tidak ditemukan.');
            assert_branch((int)$p['branch_id']);
            $status = $p['status'] === 'active' ? 'inactive' : 'active';
            q('UPDATE skincare_products SET status=?, updated_at=datetime("now","localtime") WHERE id=?', [$status, $id]);
            q('UPDATE inventory SET status=? WHERE item_type="skincare" AND item_id=?', [$status, $id]);
            audit($status === 'inactive' ? 'Nonaktifkan Produk' : 'Aktifkan Produk', 'Master Data', $id, ['status' => $p['status']], ['status' => $status], 'Perubahan status produk');
            flash('Status produk diubah menjadi ' . $status . '.');
        }

        if ($act === 'delete') {
            $id = (int)$_POST['id'];
            $p = one('SELECT * FROM skincare_products WHERE id = ?', [$id]);
            if (!$p) throw new RuntimeException('Produk tidak ditemukan.');
            assert_branch((int)$p['branch_id']);
            $used = (int)scalar('SELECT COUNT(*) FROM order_items WHERE skincare_id = ?', [$id]);
            $moved = (int)scalar('SELECT COUNT(*) FROM inventory_movements WHERE item_type="skincare" AND item_id = ? AND type <> "Stok Awal"', [$id]);
            if ($used > 0 || $moved > 0) {
                throw new RuntimeException('Produk "' . $p['name'] . '" sudah memiliki histori transaksi dan tidak dapat dihapus. Silakan nonaktifkan produk ini (nonaktif tetap muncul di histori).');
            }
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                q('DELETE FROM inventory_movements WHERE item_type="skincare" AND item_id=?', [$id]);
                q('DELETE FROM inventory WHERE item_type="skincare" AND item_id=?', [$id]);
                q('DELETE FROM skincare_products WHERE id=?', [$id]);
                $pdo->exec('COMMIT');
            } catch (Throwable $ex) {
                $pdo->exec('ROLLBACK');
                throw $ex;
            }
            audit('Hapus Produk Skincare', 'Master Data', $id, $p, null, 'Produk belum pernah digunakan dalam transaksi');
            flash('Produk dihapus karena belum pernah digunakan dalam transaksi.');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: skincare.php');
    exit;
}

/* -------- list -------- */
$q = gp('q');
$where = ['1=1'];
$params = [];
if ($scope !== null) { $where[] = 's.branch_id = ?'; $params[] = $scope; }
if ($q !== '') { $where[] = '(s.name LIKE ? OR s.code LIKE ? OR s.category LIKE ?)'; $t = '%' . $q . '%'; array_push($params, $t, $t, $t); }
if (gp('status') !== '') { $where[] = 's.status = ?'; $params[] = gp('status'); }
if (gp('alert') === '1') { $where[] = 's.stock <= s.minimum_stock'; }
$w = implode(' AND ', $where);
$page = page_no(); $pp = per_page();
$total = (int)scalar("SELECT COUNT(*) FROM skincare_products s WHERE {$w}", $params);
$rows = all("SELECT s.*, b.name AS branch_name,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.skincare_id=s.id) sold
             FROM skincare_products s JOIN branches b ON b.id=s.branch_id WHERE {$w}
             ORDER BY (s.stock <= s.minimum_stock) DESC, s.name LIMIT {$pp} OFFSET " . (($page - 1) * $pp), $params);
/* PEMBATASAN CABANG (audit isolasi ronde 56): pilihan supplier mengikuti cabang akun —
   akun yang dipin satu cabang tidak melihat supplier cabang lain. */
[$supSql, $supParams] = bscope('branch_id');
$suppliers = all('SELECT DISTINCT name FROM suppliers WHERE status="active"' . $supSql . ' ORDER BY name', $supParams);
$edit = gp('action') === 'edit' ? one('SELECT * FROM skincare_products WHERE id = ?', [(int)gp('id')]) : null;
if ($edit) assert_branch((int)$edit['branch_id']);
$stockItem = gp('action') === 'stock' ? one('SELECT * FROM skincare_products WHERE id = ?', [(int)gp('id')]) : null;
if ($stockItem) assert_branch((int)$stockItem['branch_id']);
$openModal = ($edit || gp('action') === 'new') ? 'skModal' : ($stockItem ? 'stockModal' : '');

page_head('Master Skincare', 'skincare');
?>
<div class="page-head">
  <div><h2>Master Skincare</h2><p class="muted">Produk, harga, stok, dan supplier. Kasir berhak mengelola penuh modul ini untuk cabangnya.</p></div>
  <?php /* URUTAN TOMBOL (permintaan pemilik): Tambah Produk paling kiri, disusul
           Paket Produk, baru tombol lain (impor/ekspor/hapus). */ ?>
  <div class="page-actions">
    <?php if (has_perm('skincare.manage')): ?>
      <button class="btn btn-primary" data-modal-open="skModal" onclick="resetSkForm()"><?= icon('plus-circle') ?> Tambah Produk</button>
      <?= package_ui_button('product') ?>
    <?php endif; ?>
    <?php if (has_perm('export.data')): ?>
      <a class="btn" href="export.php?type=skincare&format=csv"><?= icon('download') ?> CSV</a>
      <a class="btn" href="export.php?type=skincare&format=excel">Excel</a>
      <a class="btn" href="export.php?type=skincare&format=pdf" target="_blank">PDF</a>
    <?php endif; ?>
    <?php if (has_perm('skincare.manage')): ?><a class="btn" href="import.php?type=skincare"><?= icon('upload') ?> Import</a><?php endif; ?>
    <?php if (is_super()): ?>
      <a class="btn btn-danger" href="purge.php?menu=skincare"
         title="Khusus Super Admin — hapus seluruh data menu ini">Hapus Semua Master Skincare</a>
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
    <a class="btn btn-sm" href="skincare.php">Reset</a>
    <?= per_page_inline() ?>
  </form>
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state('Belum ada produk skincare.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Kode</th><th>Produk</th><th>Kategori</th><th class="num">HPP <span class="muted small">(harga beli)</span></th><th class="num">Harga Jual</th>
        <th class="num">Stok</th><th class="num">Min</th><th>Satuan</th><th>Supplier</th><th>Cabang</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $low = (float)$r['stock'] <= (float)$r['minimum_stock']; ?>
        <tr>
          <td class="small nowrap"><?= e($r['code']) ?></td>
          <td><strong><?= e($r['name']) ?></strong><div class="small muted">terjual <?= num($r['sold']) ?></div></td>
          <td class="small"><?= e($r['category'] ?: '-') ?></td>
          <td class="num"><?= money($r['purchase_price']) ?></td>
          <td class="num"><?= money($r['selling_price']) ?></td>
          <td class="num"><strong><?= qty_text($r['stock']) ?></strong>
            <?= (float)$r['stock'] <= 0 ? ' ' . badge('HABIS', 'red') : ($low ? ' ' . badge('MENIPIS', 'yellow') : '') ?></td>
          <td class="num"><?= qty_text($r['minimum_stock']) ?></td>
          <td class="small"><?= e($r['unit']) ?></td>
          <td class="small"><?= e($r['supplier_name'] ?: '-') ?></td>
          <td class="small"><?= e($r['branch_name']) ?></td>
          <td><?= badge($r['status'] === 'active' ? 'Aktif' : 'Nonaktif', $r['status'] === 'active' ? 'green' : 'gray') ?></td>
          <td class="nowrap">
            <?php if (has_perm('skincare.manage')): ?>
            <div class="row-actions">
              <a class="btn btn-sm btn-primary" href="skincare.php?action=stock&id=<?= (int)$r['id'] ?>">Stok</a>
              <a class="btn btn-sm" href="skincare.php?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
              <form method="post" data-confirm="Ubah status produk ini?">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= $r['status'] === 'active' ? 'Nonaktif' : 'Aktifkan' ?></button>
              </form>
              <form method="post" data-confirm="Hapus produk ini? Hanya dapat dihapus bila belum pernah dijual/dipakai.">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
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

<?php package_ui_card('product'); ?>

<div class="modal<?= $openModal === 'skModal' ? ' open' : '' ?>" id="skModal">
  <div class="modal-box wide">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="sk_id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="modal-head"><h3 id="sk_title"><?= $edit ? 'Edit Produk' : 'Tambah Produk Skincare' ?></h3>
        <button type="button" class="icon-btn" data-modal-close="skModal"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <div class="form-grid g3">
          <div class="field"><label>Kode Produk <span class="req">*</span></label><input class="input" name="code" id="sk_code" value="<?= e($edit['code'] ?? '') ?>" required></div>
          <div class="field" style="grid-column:1/-1"><label>Nama Produk <span class="req">*</span></label><input class="input" name="name" id="sk_name" value="<?= e($edit['name'] ?? '') ?>" required></div>
          <div class="field"><label>Kategori</label><input class="input" name="category" id="sk_cat" value="<?= e($edit['category'] ?? '') ?>"></div>
          <div class="field"><label>HPP — Harga Pokok (Harga Beli)</label>
            <input class="input" type="text" inputmode="decimal" name="purchase_price" id="sk_buy" value="<?= e(qty_text((float)($edit['purchase_price'] ?? 0))) ?>">
            <span class="hint">Harga beli produk dari supplier = <strong>HPP produk</strong>, dipakai menghitung laba di menu Keuangan.</span></div>
          <div class="field"><label>Harga Jual <span class="req">*</span></label><input class="input" type="number" min="0" step="500" name="selling_price" id="sk_sell" value="<?= (float)($edit['selling_price'] ?? 0) ?>" required></div>
          <?php if (!$edit): ?>
            <div class="field"><label>Stok Awal</label><input class="input" type="number" min="0" step="0.01" name="opening_stock" id="sk_open" inputmode="decimal" value="0">
              <span class="hint">Tercatat sebagai "Stok Awal" pada Inventory Movement.</span></div>
          <?php endif; ?>
          <div class="field"><label>Minimum Stok</label><input class="input" type="number" min="0" step="0.01" name="minimum_stock" id="sk_min" value="<?= (float)($edit['minimum_stock'] ?? 0) ?>"></div>
          <div class="field"><label>Satuan</label><input class="input" name="unit" id="sk_unit" value="<?= e($edit['unit'] ?? 'pcs') ?>"></div>
          <div class="field"><label>Supplier</label>
            <?= opt_supplier('supplier_name', (string)($edit['supplier_name'] ?? ''), 'sk') ?>
            <span class="hint">Daftar diambil dari menu <a href="suppliers.php">Supplier</a>.</span></div>
          <?php if (is_owner_level()): ?>
          <div class="field"><label>Cabang <span class="req">*</span></label><select class="input" name="branch_id" id="sk_branch"><?= opt_branches($edit['branch_id'] ?? ($scope ?: null)) ?></select></div>
          <?php endif; ?>
          <div class="field"><label>Status</label>
            <select class="input" name="status" id="sk_status">
              <option value="active"<?= ($edit['status'] ?? '') === 'active' ? ' selected' : '' ?>>Aktif</option>
              <option value="inactive"<?= ($edit['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Nonaktif</option>
            </select></div>
        </div>
        <div class="notice mt-2">Produk nonaktif tidak muncul di Order Baru, tetapi tetap tampil pada histori transaksi.</div>
      </div>
      <div class="modal-foot"><button type="button" class="btn" data-modal-close="skModal">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan Produk</button></div>
    </form>
  </div>
</div>

<div class="modal<?= $stockItem ? ' open' : '' ?>" id="stockModal">
  <div class="modal-box sheet">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="stock"><input type="hidden" name="id" value="<?= (int)($stockItem['id'] ?? 0) ?>">
      <div class="modal-head"><h3>Kelola Stok — <?= e($stockItem['name'] ?? '') ?></h3>
        <button type="button" class="icon-btn" data-modal-close="stockModal"><?= icon('x') ?></button></div>
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
            <div class="field"><label>Alasan / Keterangan</label><input class="input" name="reason" placeholder="wajib untuk pengurangan (rusak/expired/koreksi)"></div>
          </div>
        <?php endif; ?>
      </div>
      <div class="modal-foot"><button type="button" class="btn" data-modal-close="stockModal">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan Perubahan Stok</button></div>
    </form>
  </div>
</div>
<script>
function resetSkForm() {
  document.getElementById('sk_id').value = 0;
  document.getElementById('sk_title').textContent = 'Tambah Produk Skincare';
  ['sk_code','sk_name','sk_cat','sk_sup'].forEach(function (id) { var el = document.getElementById(id); if (el) el.value = ''; });
  ['sk_buy','sk_sell','sk_min'].forEach(function (id) { document.getElementById(id).value = 0; });
  var open = document.getElementById('sk_open'); if (open) open.value = 0;
  document.getElementById('sk_unit').value = 'pcs';
}
</script>
<?php page_foot(); ?>
