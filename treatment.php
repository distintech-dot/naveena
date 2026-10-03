<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/package_ui.php';
require_perm('treatment.view');
$user = current_user();
$scope = scope_branch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        require_perm('treatment.manage');
        /* Paket treatment (aksi pkg_save / pkg_delete / pkg_toggle). */
        if (package_ui_handle_post('treatment')) {
            header('Location: treatment.php');
            exit;
        }
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $code = trim((string)$_POST['code']);
            $name = trim((string)$_POST['name']);
            $cat  = trim((string)($_POST['category'] ?? ''));
            $np   = (float)$_POST['normal_price'];
            $pp   = (float)($_POST['promo_price'] ?? 0);
            /* HPP = harga pokok per satu kali treatment; dipakai perhitungan
               laba di menu Keuangan (disimpan sebagai snapshot tiap transaksi). */
            $hpp  = max(0.0, qty_parse($_POST['hpp'] ?? 0));
            $dur  = (int)($_POST['duration'] ?? 60);
            $branch = is_owner_level() ? resolve_branch_input($_POST['branch_id'] ?? 0) : (int)$user['branch_id'];
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            if ($code === '' || $name === '') throw new RuntimeException('Kode dan nama treatment wajib diisi.');
            if ($np < 0 || $pp < 0) throw new RuntimeException('Harga tidak boleh negatif.');
            if ($dur < 0) throw new RuntimeException('Durasi tidak boleh negatif.');
            assert_branch($branch);
            $dupe = one('SELECT id FROM treatments WHERE code = ? AND branch_id = ? AND id <> ?', [$code, $branch, $id]);
            if ($dupe) throw new RuntimeException('Kode treatment ' . $code . ' sudah dipakai di cabang ini.');
            if ($id > 0) {
                $old = one('SELECT * FROM treatments WHERE id = ?', [$id]);
                if (!$old) throw new RuntimeException('Treatment tidak ditemukan.');
                assert_branch((int)$old['branch_id']);
                q('UPDATE treatments SET code=?, name=?, category=?, normal_price=?, promo_price=?, hpp=?, duration=?, branch_id=?, status=?, updated_at=datetime("now","localtime") WHERE id=?',
                    [$code, $name, $cat, $np, $pp, $hpp, $dur, $branch, $status, $id]);
                audit('Edit Treatment', 'Master Data', $id, $old, ['code' => $code, 'name' => $name, 'normal_price' => $np, 'promo_price' => $pp, 'status' => $status], 'Perubahan master treatment');
                if ((float)($old['hpp'] ?? 0) !== $hpp) {
                    audit('Ubah HPP Treatment', 'Master Data', $id, ['hpp' => (float)($old['hpp'] ?? 0)], ['hpp' => $hpp],
                        'HPP treatment diubah (transaksi lama tetap memakai HPP saat transaksi terjadi)');
                }
                flash('Master treatment berhasil diperbarui.');
            } else {
                q('INSERT INTO treatments (code, name, category, normal_price, promo_price, hpp, duration, branch_id, status, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,datetime("now","localtime"))', [$code, $name, $cat, $np, $pp, $hpp, $dur, $branch, $status]);
                $newId = (int)db()->lastInsertId();
                audit('Tambah Treatment', 'Master Data', $newId, null, ['code' => $code, 'name' => $name, 'branch_id' => $branch, 'hpp' => $hpp], 'Treatment baru');
                flash('Treatment baru ditambahkan.');
            }
        }

        if ($act === 'delete') {
            $id = (int)$_POST['id'];
            $t = one('SELECT * FROM treatments WHERE id = ?', [$id]);
            if (!$t) throw new RuntimeException('Treatment tidak ditemukan.');
            assert_branch((int)$t['branch_id']);
            $used = (int)scalar('SELECT COUNT(*) FROM order_items WHERE treatment_id = ?', [$id]);
            if ($used > 0) {
                throw new RuntimeException('Treatment "' . $t['name'] . '" sudah memiliki histori transaksi dan tidak dapat dihapus. Silakan nonaktifkan treatment ini?');
            }
            q('DELETE FROM treatments WHERE id = ?', [$id]);
            audit('Hapus Treatment', 'Master Data', $id, $t, null, 'Treatment belum pernah digunakan dalam transaksi');
            flash('Treatment dihapus (belum pernah digunakan dalam transaksi).');
        }

        if ($act === 'deactivate') {
            $id = (int)$_POST['id'];
            $t = one('SELECT * FROM treatments WHERE id = ?', [$id]);
            if (!$t) throw new RuntimeException('Treatment tidak ditemukan.');
            assert_branch((int)$t['branch_id']);
            $status = $t['status'] === 'active' ? 'inactive' : 'active';
            q('UPDATE treatments SET status=?, updated_at=datetime("now","localtime") WHERE id=?', [$status, $id]);
            audit($status === 'inactive' ? 'Nonaktifkan Treatment' : 'Aktifkan Treatment', 'Master Data', $id, ['status' => $t['status']], ['status' => $status], 'Perubahan status treatment');
            flash('Status treatment diubah menjadi ' . $status . '.');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: treatment.php');
    exit;
}

$q = gp('q');
$where = ['1=1'];
$params = [];
if ($scope !== null) { $where[] = 't.branch_id = ?'; $params[] = $scope; }
if ($q !== '') { $where[] = '(t.name LIKE ? OR t.code LIKE ? OR t.category LIKE ?)'; $t = '%' . $q . '%'; array_push($params, $t, $t, $t); }
if (gp('status') !== '') { $where[] = 't.status = ?'; $params[] = gp('status'); }
if (gp('category') !== '') { $where[] = 't.category = ?'; $params[] = gp('category'); }
$w = implode(' AND ', $where);
$page = page_no(); $pp = per_page();
$total = (int)scalar("SELECT COUNT(*) FROM treatments t WHERE {$w}", $params);
$rows = all("SELECT t.*, b.name AS branch_name,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.treatment_id=t.id) sold
             FROM treatments t JOIN branches b ON b.id=t.branch_id WHERE {$w}
             ORDER BY t.status DESC, t.name LIMIT {$pp} OFFSET " . (($page - 1) * $pp), $params);
$cats = all('SELECT DISTINCT category FROM treatments WHERE category IS NOT NULL AND category <> "" ORDER BY category');

$edit = gp('action') === 'edit' ? one('SELECT * FROM treatments WHERE id = ?', [(int)gp('id')]) : null;
if ($edit) assert_branch((int)$edit['branch_id']);
$openModal = (gp('action') === 'new' || $edit) ? 'trModal' : '';

page_head('Master Treatment', 'treatment');
?>
<div class="page-head">
  <div><h2>Master Treatment</h2><p class="muted">Daftar layanan treatment beserta harga dan durasi per cabang.</p></div>
  <?php /* URUTAN TOMBOL (permintaan pemilik): Tambah Treatment paling kiri, disusul
           Paket Treatment, baru tombol lain (impor/ekspor/hapus). */ ?>
  <div class="page-actions">
    <?php if (has_perm('treatment.manage')): ?>
      <button class="btn btn-primary" data-modal-open="trModal" onclick="resetTrForm()"><?= icon('plus-circle') ?> Tambah Treatment</button>
      <?= package_ui_button('treatment') ?>
    <?php endif; ?>
    <?php if (has_perm('export.data')): ?>
      <a class="btn" href="export.php?type=treatment&format=csv"><?= icon('download') ?> CSV</a>
      <a class="btn" href="export.php?type=treatment&format=excel">Excel</a>
      <a class="btn" href="export.php?type=treatment&format=pdf" target="_blank">PDF</a>
    <?php endif; ?>
    <?php if (has_perm('treatment.manage')): ?><a class="btn" href="import.php?type=treatment"><?= icon('upload') ?> Import</a><?php endif; ?>
    <?php if (is_super()): ?>
      <a class="btn btn-danger" href="purge.php?menu=treatment"
         title="Khusus Super Admin — hapus seluruh data menu ini">Hapus Semua Master Treatment</a>
    <?php endif; ?>
  </div>
</div>

<div class="card tight">
  <form class="filter-bar" method="get">
    <div class="field searchbox"><label>Cari</label><span><?= icon('search') ?></span>
      <input class="input input-sm" name="q" value="<?= e($q) ?>" placeholder="Nama / kode / kategori"></div>
    <div class="field"><label>Kategori</label>
      <select class="input input-sm" name="category">
        <option value="">Semua</option>
        <?php foreach ($cats as $c): ?><option value="<?= e($c['category']) ?>"<?= gp('category') === $c['category'] ? ' selected' : '' ?>><?= e($c['category']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Status</label>
      <select class="input input-sm" name="status">
        <option value="">Semua</option>
        <option value="active"<?= gp('status') === 'active' ? ' selected' : '' ?>>Aktif</option>
        <option value="inactive"<?= gp('status') === 'inactive' ? ' selected' : '' ?>>Nonaktif</option>
      </select></div>
    <?php if (is_owner_level()): ?>
    <div class="field"><label>Cabang</label>
      <select class="input input-sm" name="branch">
        <option value="all"<?= $scope === null ? ' selected' : '' ?>>Semua Cabang</option>
        <?php foreach (branches() as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $scope === (int)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    <a class="btn btn-sm" href="treatment.php">Reset</a>
    <?= per_page_inline() ?>
  </form>
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state('Belum ada master treatment.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Kode</th><th>Nama Treatment</th><th>Kategori</th><th class="num">Harga Normal</th><th class="num">Harga Promo</th><?php if (has_perm('finance.view')): ?><th class="num">HPP <span class="muted small">(internal)</span></th><?php endif; ?><th class="num">Durasi</th><th>Cabang</th><th class="num">Terjual</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="small nowrap"><?= e($r['code']) ?></td>
          <td><strong><?= e($r['name']) ?></strong></td>
          <td class="small"><?= e($r['category'] ?: '-') ?></td>
          <td class="num"><?= money($r['normal_price']) ?></td>
          <td class="num"><?= (float)$r['promo_price'] > 0 ? money($r['promo_price']) : '-' ?></td>
          <?php if (has_perm('finance.view')): ?>
            <td class="num"><?= money((float)($r['hpp'] ?? 0)) ?></td>
          <?php endif; ?>
          <td class="num"><?= num($r['duration']) ?> mnt</td>
          <td class="small"><?= e($r['branch_name']) ?></td>
          <td class="num"><?= num($r['sold']) ?></td>
          <td><?= badge($r['status'] === 'active' ? 'Aktif' : 'Nonaktif', $r['status'] === 'active' ? 'green' : 'gray') ?></td>
          <td class="nowrap">
            <?php if (has_perm('treatment.manage')): ?>
            <div class="row-actions">
              <a class="btn btn-sm" href="treatment.php?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
              <form method="post" data-confirm="Ubah status treatment ini?">
                <?= csrf_field() ?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= $r['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?></button>
              </form>
              <form method="post" data-confirm="Hapus treatment ini? Hanya bisa dihapus bila belum pernah dipakai bertransaksi.">
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

<?php package_ui_card('treatment'); ?>

<div class="modal<?= $openModal ? ' open' : '' ?>" id="trModal">
  <div class="modal-box">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="tr_id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="modal-head"><h3 id="tr_title"><?= $edit ? 'Edit Treatment' : 'Tambah Treatment' ?></h3>
        <button type="button" class="icon-btn" data-modal-close="trModal"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <div class="form-grid g2">
          <div class="field"><label>Kode Treatment <span class="req">*</span></label><input class="input" name="code" id="tr_code" value="<?= e($edit['code'] ?? '') ?>" required></div>
          <div class="field"><label>Nama Treatment <span class="req">*</span></label><input class="input" name="name" id="tr_name" value="<?= e($edit['name'] ?? '') ?>" required></div>
          <div class="field"><label>Kategori</label><input class="input" name="category" id="tr_cat" value="<?= e($edit['category'] ?? '') ?>" list="tr_cats">
            <datalist id="tr_cats"><?php foreach ($cats as $c): ?><option value="<?= e($c['category']) ?>"><?php endforeach; ?></datalist></div>
          <div class="field"><label>Durasi (menit)</label><input class="input" type="number" min="0" name="duration" id="tr_dur" value="<?= (int)($edit['duration'] ?? 60) ?>"></div>
          <div class="field"><label>Harga Normal <span class="req">*</span></label><input class="input" type="number" min="0" step="1000" name="normal_price" id="tr_np" value="<?= (float)($edit['normal_price'] ?? 0) ?>" required></div>
          <div class="field"><label>Harga Promo</label><input class="input" type="number" min="0" step="1000" name="promo_price" id="tr_pp" value="<?= (float)($edit['promo_price'] ?? 0) ?>">
            <span class="hint">Isi 0 bila tidak ada harga promo.</span></div>
          <div class="field"><label>HPP — Harga Pokok per Treatment</label>
            <input class="input" type="text" inputmode="decimal" name="hpp" id="tr_hpp" value="<?= e(qty_text((float)($edit['hpp'] ?? 0))) ?>">
            <span class="hint">Biaya bahan/pokok untuk <strong>satu kali</strong> treatment ini (mis. total harga bahan yang dipakai).
              Dipakai menghitung <strong>laba</strong> di menu Keuangan — perubahan HPP tidak mengubah laba transaksi yang sudah terjadi.</span></div>
          <?php if (is_owner_level()): ?>
          <div class="field"><label>Cabang <span class="req">*</span></label><select class="input" name="branch_id" id="tr_branch"><?= opt_branches($edit['branch_id'] ?? ($scope ?: null)) ?></select></div>
          <?php endif; ?>
          <div class="field"><label>Status</label>
            <select class="input" name="status" id="tr_status">
              <option value="active"<?= ($edit['status'] ?? '') === 'active' ? ' selected' : '' ?>>Aktif</option>
              <option value="inactive"<?= ($edit['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Nonaktif</option>
            </select></div>
        </div>
      </div>
      <div class="modal-foot"><button type="button" class="btn" data-modal-close="trModal">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan</button></div>
    </form>
  </div>
</div>
<script>
function resetTrForm() {
  document.getElementById('tr_id').value = 0;
  document.getElementById('tr_title').textContent = 'Tambah Treatment';
  ['tr_code','tr_name','tr_cat'].forEach(function (id) { document.getElementById(id).value = ''; });
  document.getElementById('tr_np').value = 0;
  document.getElementById('tr_pp').value = 0;
  var hppEl = document.getElementById('tr_hpp');
  if (hppEl) hppEl.value = '0';
  document.getElementById('tr_dur').value = 60;
}
</script>
<?php page_foot(); ?>
