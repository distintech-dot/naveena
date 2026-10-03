<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('supplier.manage');
$user = current_user();
$scope = scope_branch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)$_POST['name']);
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $branch = is_owner_level() ? (int)($_POST['branch_id'] ?? 0) : (int)$user['branch_id'];
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            if ($name === '') throw new RuntimeException('Nama supplier wajib diisi.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Format email supplier tidak valid.');
            if ($branch) assert_branch($branch);
            if ($id > 0) {
                $old = one('SELECT * FROM suppliers WHERE id = ?', [$id]);
                if (!$old) throw new RuntimeException('Supplier tidak ditemukan.');
                if ($old['branch_id']) assert_branch((int)$old['branch_id']);
                q('UPDATE suppliers SET name=?, phone=?, email=?, address=?, branch_id=?, status=?, updated_at=datetime("now","localtime") WHERE id=?',
                    [$name, $phone, $email, $address, $branch ?: null, $status, $id]);
                audit('Edit Supplier', 'Master Data', $id, $old, ['name' => $name], 'Perubahan supplier');
                flash('Data supplier diperbarui.');
            } else {
                q('INSERT INTO suppliers (name, phone, email, address, branch_id, status, created_at) VALUES (?,?,?,?,?,?,datetime("now","localtime"))',
                    [$name, $phone, $email, $address, $branch ?: null, $status]);
                $newId = (int)db()->lastInsertId();
                audit('Tambah Supplier', 'Master Data', $newId, null, ['name' => $name], 'Supplier baru');
                flash('Supplier baru ditambahkan.');
            }
        }
        if ($act === 'delete') {
            $id = (int)$_POST['id'];
            $s = one('SELECT * FROM suppliers WHERE id = ?', [$id]);
            if (!$s) throw new RuntimeException('Supplier tidak ditemukan.');
            if ($s['branch_id']) assert_branch((int)$s['branch_id']);
            $used = (int)scalar('SELECT COUNT(*) FROM skincare_products WHERE supplier_name = ?', [$s['name']])
                  + (int)scalar('SELECT COUNT(*) FROM treatment_materials WHERE supplier_name = ?', [$s['name']]);
            if ($used > 0) throw new RuntimeException('Supplier ini masih dipakai pada ' . $used . ' produk/bahan. Nonaktifkan saja agar histori tetap utuh.');
            q('DELETE FROM suppliers WHERE id = ?', [$id]);
            audit('Hapus Supplier', 'Master Data', $id, $s, null, 'Supplier belum dipakai');
            flash('Supplier dihapus.');
        }
        if ($act === 'toggle') {
            $id = (int)$_POST['id'];
            $s = one('SELECT * FROM suppliers WHERE id = ?', [$id]);
            if (!$s) throw new RuntimeException('Supplier tidak ditemukan.');
            $status = $s['status'] === 'active' ? 'inactive' : 'active';
            q('UPDATE suppliers SET status=?, updated_at=datetime("now","localtime") WHERE id=?', [$status, $id]);
            audit('Ubah Status Supplier', 'Master Data', $id, ['status' => $s['status']], ['status' => $status], 'Perubahan status supplier');
            flash('Status supplier diubah menjadi ' . $status . '.');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: suppliers.php');
    exit;
}

$q = gp('q');
$where = ['1=1'];
$params = [];
if ($scope !== null) { $where[] = '(s.branch_id = ? OR s.branch_id IS NULL)'; $params[] = $scope; }
if ($q !== '') { $where[] = '(s.name LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)'; $t = '%' . $q . '%'; array_push($params, $t, $t, $t); }
$w = implode(' AND ', $where);
$rows = all("SELECT s.*, b.name AS branch_name,
                    (SELECT COUNT(*) FROM skincare_products p WHERE p.supplier_name = s.name) produk,
                    (SELECT COUNT(*) FROM treatment_materials m WHERE m.supplier_name = s.name) bahan
             FROM suppliers s LEFT JOIN branches b ON b.id = s.branch_id WHERE {$w} ORDER BY s.name", $params);
$edit = gp('action') === 'edit' ? one('SELECT * FROM suppliers WHERE id = ?', [(int)gp('id')]) : null;

page_head('Supplier', 'suppliers');
?>
<div class="page-head">
  <div><h2>Supplier</h2><p class="muted">Daftar pemasok produk skincare dan bahan treatment.</p></div>
  <div class="page-actions">
    <button class="btn btn-primary" data-modal-open="supModal" onclick="resetSupForm()"><?= icon('plus-circle') ?> Tambah Supplier</button>
    <?php if (has_perm('export.data') && has_perm('supplier.manage')): ?>
      <a class="btn" href="export.php?type=suppliers&format=excel&<?= e(qs([], ['page', 'per_page'])) ?>"><?= icon('download') ?> Excel</a>
      <a class="btn" href="export.php?type=suppliers&format=csv&<?= e(qs([], ['page', 'per_page'])) ?>">CSV</a>
      <a class="btn" href="export.php?type=suppliers&format=pdf&<?= e(qs([], ['page', 'per_page'])) ?>" target="_blank">PDF</a>
    <?php endif; ?>
    <?php if (is_super()): ?>
      <a class="btn btn-danger" href="purge.php?menu=suppliers"
         title="Khusus Super Admin — hapus seluruh data menu ini">Hapus Semua Supplier</a>
    <?php endif; ?>
  </div>
</div>

<div class="card tight">
  <form class="filter-bar" method="get">
    <div class="field searchbox"><label>Cari</label><span><?= icon('search') ?></span>
      <input class="input input-sm" name="q" value="<?= e($q) ?>" placeholder="Nama / telepon / email supplier"></div>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    <a class="btn btn-sm" href="suppliers.php">Reset</a>
  </form>
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state('Belum ada data supplier.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Nama Supplier</th><th>Telepon</th><th>Email</th><th>Alamat</th><th>Cabang</th><th class="num">Produk</th><th class="num">Bahan</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong></td>
          <td class="small"><?= e($r['phone'] ?: '-') ?></td>
          <td class="small"><?= e($r['email'] ?: '-') ?></td>
          <td class="small"><?= e($r['address'] ?: '-') ?></td>
          <td class="small"><?= e($r['branch_name'] ?: 'Semua Cabang') ?></td>
          <td class="num"><?= num($r['produk']) ?></td>
          <td class="num"><?= num($r['bahan']) ?></td>
          <td><?= badge($r['status'] === 'active' ? 'Aktif' : 'Nonaktif', $r['status'] === 'active' ? 'green' : 'gray') ?></td>
          <td class="nowrap"><div class="row-actions">
            <a class="btn btn-sm" href="suppliers.php?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
            <form method="post" data-confirm="Ubah status supplier ini?">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm" type="submit"><?= $r['status'] === 'active' ? 'Nonaktif' : 'Aktifkan' ?></button></form>
            <form method="post" data-confirm="Hapus supplier ini?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit">Hapus</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="modal<?= $edit ? ' open' : '' ?>" id="supModal">
  <div class="modal-box">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="sup_id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="modal-head"><h3 id="sup_title"><?= $edit ? 'Edit Supplier' : 'Tambah Supplier' ?></h3>
        <button type="button" class="icon-btn" data-modal-close="supModal"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <div class="form-grid g2">
          <div class="field" style="grid-column:1/-1"><label>Nama Supplier <span class="req">*</span></label><input class="input" name="name" id="sup_name" value="<?= e($edit['name'] ?? '') ?>" required></div>
          <div class="field"><label>Telepon</label><input class="input" name="phone" id="sup_phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
          <div class="field"><label>Email</label><input class="input" type="email" name="email" id="sup_email" value="<?= e($edit['email'] ?? '') ?>"></div>
          <div class="field" style="grid-column:1/-1"><label>Alamat</label><textarea class="input" name="address" id="sup_address"><?= e($edit['address'] ?? '') ?></textarea></div>
          <?php if (is_owner_level()): ?>
          <div class="field"><label>Cabang</label><select class="input" name="branch_id" id="sup_branch">
            <option value="">Semua Cabang</option>
            <?= opt_branches($edit['branch_id'] ?? null) ?></select></div>
          <?php endif; ?>
          <div class="field"><label>Status</label>
            <select class="input" name="status" id="sup_status">
              <option value="active"<?= ($edit['status'] ?? '') === 'active' ? ' selected' : '' ?>>Aktif</option>
              <option value="inactive"<?= ($edit['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Nonaktif</option>
            </select></div>
        </div>
      </div>
      <div class="modal-foot"><button type="button" class="btn" data-modal-close="supModal">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan Supplier</button></div>
    </form>
  </div>
</div>
<script>
function resetSupForm() {
  document.getElementById('sup_id').value = 0;
  document.getElementById('sup_title').textContent = 'Tambah Supplier';
  ['sup_name','sup_phone','sup_email','sup_address'].forEach(function (id) { document.getElementById(id).value = ''; });
}
</script>
<?php page_foot(); ?>
