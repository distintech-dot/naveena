<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
/* Melihat daftar cabang cukup dengan `branch.view`. Tindakan yang mengubah
   data cabang (tambah/ubah/nonaktifkan/hapus) tetap khusus `branch.manage`
   — yaitu Super Admin. Direktur/Owner hanya dapat melihat. */
require_perm('branch.view');
$canManage = has_perm('branch.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if (!$canManage) {
            deny('Hanya Super Admin yang boleh menambah, mengubah, menonaktifkan, atau menghapus cabang.');
        }
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $code = strtoupper(trim((string)$_POST['code']));
            $name = trim((string)$_POST['name']);
            $address = trim((string)($_POST['address'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $hours = trim((string)($_POST['opening_hours'] ?? ''));
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            if ($code === '' || $name === '') throw new RuntimeException('Kode dan nama cabang wajib diisi.');
            if (!preg_match('/^[A-Z0-9]{2,4}$/', $code)) throw new RuntimeException('Kode cabang 2–4 karakter huruf/angka (dipakai untuk nomor invoice, mis. KW / CP / BT).');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Format email cabang tidak valid.');
            $dupe = one('SELECT id FROM branches WHERE code = ? AND id <> ?', [$code, $id]);
            if ($dupe) throw new RuntimeException('Kode cabang sudah dipakai.');
            if ($id > 0) {
                $old = one('SELECT * FROM branches WHERE id = ?', [$id]);
                if (!$old) throw new RuntimeException('Cabang tidak ditemukan.');
                q('UPDATE branches SET code=?, name=?, address=?, phone=?, email=?, opening_hours=?, status=?, updated_at=datetime("now","localtime") WHERE id=?',
                    [$code, $name, $address, $phone, $email, $hours, $status, $id]);
                audit('Edit Cabang', 'Pengaturan', $id, $old, ['name' => $name, 'code' => $code, 'status' => $status], 'Perubahan data cabang');
                flash('Data cabang diperbarui.');
            } else {
                q('INSERT INTO branches (code, name, address, phone, email, opening_hours, status, created_at) VALUES (?,?,?,?,?,?,?,datetime("now","localtime"))',
                    [$code, $name, $address, $phone, $email, $hours, $status]);
                $newId = (int)db()->lastInsertId();
                audit('Tambah Cabang', 'Pengaturan', $newId, null, ['name' => $name, 'code' => $code], 'Cabang baru');
                /* BASIS DATA CABANG OTOMATIS (ronde 54): saat mode central/branch aktif,
                   setiap cabang baru langsung mendapat basis datanya sendiri — skema +
                   migrasi + PRAGMA + integrity/FK check + registrasi + health check.
                   Pada mode `legacy` (bawaan) langkah ini dilewati sehingga produksi
                   tidak berubah. */
                /* Cabang baru SELALU dibuatkan basis datanya (arsitektur central +
                   satu basis data per cabang berlaku untuk semua pemasangan). */
                if ($newId > 0) {
                    $dbBranch = db_branch_create($newId, $code);
                    flash($dbBranch['ok']
                        ? 'Basis data cabang dibuat & sehat: ' . basename($dbBranch['path'])
                        : 'Cabang tersimpan, tetapi basis datanya perlu diperiksa: ' . $dbBranch['error'],
                        $dbBranch['ok'] ? 'success' : 'warning');
                } else {
                    flash('Cabang baru ditambahkan.');
                }
            }
        }
        if ($act === 'toggle') {
            $id = (int)$_POST['id'];
            $b = one('SELECT * FROM branches WHERE id = ?', [$id]);
            if (!$b) throw new RuntimeException('Cabang tidak ditemukan.');
            $status = $b['status'] === 'active' ? 'inactive' : 'active';
            q('UPDATE branches SET status=?, updated_at=datetime("now","localtime") WHERE id=?', [$status, $id]);
            audit($status === 'inactive' ? 'Nonaktifkan Cabang' : 'Aktifkan Cabang', 'Pengaturan', $id, ['status' => $b['status']], ['status' => $status], 'Perubahan status cabang');
            flash('Status cabang diubah menjadi ' . $status . '.');
        }
        if ($act === 'delete') {
            $id = (int)$_POST['id'];
            $b = one('SELECT * FROM branches WHERE id = ?', [$id]);
            if (!$b) throw new RuntimeException('Cabang tidak ditemukan.');
            $refs = (int)scalar('SELECT COUNT(*) FROM orders WHERE branch_id = ?', [$id])
                  + (int)scalar('SELECT COUNT(*) FROM patients WHERE branch_id = ?', [$id])
                  + (int)scalar('SELECT COUNT(*) FROM users WHERE branch_id = ?', [$id]);
            if ($refs > 0) throw new RuntimeException('Cabang ini masih memiliki data operasional (pasien/transaksi/user). Nonaktifkan saja agar histori tetap utuh.');
            q('DELETE FROM branches WHERE id = ?', [$id]);
            audit('Hapus Cabang', 'Pengaturan', $id, $b, null, 'Cabang belum memiliki data operasional');
            flash('Cabang dihapus.');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: branches.php');
    exit;
}

/* Paginasi (maksimal per halaman mengikuti Pengaturan; bawaan 25). */
$page = page_no(); $pp = per_page();
$total = (int)scalar('SELECT COUNT(*) FROM branches');
$rows = all('SELECT b.*,
                (SELECT COUNT(*) FROM users u WHERE u.branch_id=b.id) users,
                (SELECT COUNT(*) FROM patients p WHERE p.branch_id=b.id) patients,
                (SELECT COUNT(*) FROM orders o WHERE o.branch_id=b.id AND o.status="paid") orders,
                (SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.branch_id=b.id AND o.status="paid") revenue
             FROM branches b ORDER BY b.id LIMIT ' . $pp . ' OFFSET ' . (($page - 1) * $pp));
$edit = gp('action') === 'edit' ? one('SELECT * FROM branches WHERE id = ?', [(int)gp('id')]) : null;

$pageTitle = $canManage ? 'Manajemen Cabang' : 'Data Cabang';
page_head($pageTitle, 'branches');
?>
<div class="page-head">
  <div>
    <h2><?= e($pageTitle) ?></h2>
    <p class="muted">Data cabang <?= e(clinic_name()) ?>. Kode cabang dipakai untuk penomoran invoice, pasien, dan reservasi.</p>
  </div>
  <div class="page-actions">
    <?php if ($canManage): ?>
      <button class="btn btn-primary" data-modal-open="brModal" onclick="resetBrForm()"><?= icon('plus-circle') ?> Tambah Cabang</button>
    <?php else: ?>
      <span class="pill"><?= icon('shield') ?> Hanya lihat — perubahan cabang khusus Super Admin</span>
    <?php endif; ?>
  </div>
</div>

<?php if (!$canManage): ?>
<div class="card"><div class="card-body">
  <div class="notice">
    Anda dapat <strong>melihat</strong> data seluruh cabang, tetapi <strong>tidak dapat menambah, mengubah,
    menonaktifkan, atau menghapus cabang</strong>. Bila ada perubahan data cabang, ajukan ke Super Admin.
  </div>
</div></div>
<?php endif; ?>

<div class="card tight">
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Kode</th><th>Nama Cabang</th><th>Alamat</th><th>WhatsApp</th><th>Email</th><th>Jam Operasional</th>
        <th class="num">User</th><th class="num">Pasien</th><th class="num">Transaksi</th><th class="num">Pendapatan</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['code']) ?></strong></td>
          <td><?= e($r['name']) ?></td>
          <td class="small"><?= e($r['address'] ?: '-') ?></td>
          <td class="small"><?= e($r['phone'] ?: '-') ?></td>
          <td class="small"><?= e($r['email'] ?: '-') ?></td>
          <td class="small"><?= e($r['opening_hours'] ?: '-') ?></td>
          <td class="num"><?= num($r['users']) ?></td>
          <td class="num"><?= num($r['patients']) ?></td>
          <td class="num"><?= num($r['orders']) ?></td>
          <td class="num"><?= money($r['revenue']) ?></td>
          <td><?= badge($r['status'] === 'active' ? 'Aktif' : 'Nonaktif', $r['status'] === 'active' ? 'green' : 'gray') ?></td>
          <td class="nowrap"><div class="row-actions">
            <?php if ($canManage): ?>
              <a class="btn btn-sm" href="branches.php?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
              <form method="post" data-confirm="Ubah status cabang ini?">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= $r['status'] === 'active' ? 'Nonaktif' : 'Aktifkan' ?></button></form>
              <form method="post" data-confirm="Hapus cabang ini? Hanya dapat dihapus bila belum ada data operasional.">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Hapus</button></form>
            <?php else: ?>
              <a class="btn btn-sm" href="dashboard.php?branch=<?= (int)$r['id'] ?>">Lihat Dashboard</a>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $pp, $page) ?>
</div>

<?php if ($canManage): ?>
<div class="modal<?= $edit ? ' open' : '' ?>" id="brModal">
  <div class="modal-box">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="br_id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="modal-head"><h3 id="br_title"><?= $edit ? 'Edit Cabang' : 'Tambah Cabang' ?></h3>
        <button type="button" class="icon-btn" data-modal-close="brModal"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <div class="form-grid g2">
          <div class="field"><label>Kode Cabang <span class="req">*</span></label>
            <input class="input" name="code" id="br_code" value="<?= e($edit['code'] ?? '') ?>" maxlength="4" required placeholder="KW / CP / BT"></div>
          <div class="field"><label>Nama Cabang <span class="req">*</span></label>
            <input class="input" name="name" id="br_name" value="<?= e($edit['name'] ?? '') ?>" required></div>
          <div class="field" style="grid-column:1/-1"><label>Alamat</label><textarea class="input" name="address" id="br_address"><?= e($edit['address'] ?? '') ?></textarea></div>
          <div class="field"><label>WhatsApp / Telepon</label><input class="input" name="phone" id="br_phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
          <div class="field"><label>Email</label><input class="input" type="email" name="email" id="br_email" value="<?= e($edit['email'] ?? '') ?>"></div>
          <div class="field"><label>Jam Operasional</label><input class="input" name="opening_hours" id="br_hours" value="<?= e($edit['opening_hours'] ?? '') ?>" placeholder="Senin–Sabtu 09.00–20.00"></div>
          <div class="field"><label>Status</label>
            <select class="input" name="status" id="br_status">
              <option value="active"<?= ($edit['status'] ?? '') === 'active' ? ' selected' : '' ?>>Aktif</option>
              <option value="inactive"<?= ($edit['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Nonaktif</option>
            </select></div>
        </div>
      </div>
      <div class="modal-foot"><button type="button" class="btn" data-modal-close="brModal">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan Cabang</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php if ($canManage): ?>
<script>
function resetBrForm() {
  document.getElementById('br_id').value = 0;
  document.getElementById('br_title').textContent = 'Tambah Cabang';
  ['br_code','br_name','br_address','br_phone','br_email','br_hours'].forEach(function (id) { document.getElementById(id).value = ''; });
}
</script>
<?php endif; ?>
<?php page_foot(); ?>
