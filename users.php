<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('user.manage');
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)$_POST['name']);
            $email = trim((string)$_POST['email']);
            $roleId = (int)$_POST['role_id'];
            $branch = (int)($_POST['branch_id'] ?? 0);
            $phone = trim((string)($_POST['phone'] ?? ''));
            $pass = (string)($_POST['password'] ?? '');
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            if ($name === '' || $email === '') throw new RuntimeException('Nama dan email wajib diisi.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Format email tidak valid.');
            $role = one('SELECT * FROM roles WHERE id = ?', [$roleId]);
            if (!$role) throw new RuntimeException('Role tidak valid.');
            /* Direktur/Owner berada DI BAWAH Super Admin: tidak boleh membuat atau
               mengubah akun menjadi Super Admin, dan tidak boleh menyentuh akun
               Super Admin yang sudah ada. */
            if (!is_super()) {
                if ($role['code'] === 'super_admin') {
                    deny('Hanya Super Admin yang boleh memberi role Super Admin.');
                }
                if ($id > 0) {
                    $target = one('SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id = ?', [$id]);
                    if ($target && $target['role_code'] === 'super_admin') {
                        deny('Akun Super Admin hanya dapat dikelola oleh Super Admin.');
                    }
                }
            }
            if ($role['code'] !== 'super_admin' && !$branch) throw new RuntimeException('Admin/Dokter dan Kasir wajib ditugaskan pada satu cabang.');
            if ($branch) assert_branch($branch);
            $dupe = one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id]);
            if ($dupe) throw new RuntimeException('Email sudah dipakai akun lain.');
            if ($id === 0) {
                if (strlen($pass) < 6) throw new RuntimeException('Password minimal 6 karakter.');
                q('INSERT INTO users (name, email, password_hash, role_id, branch_id, phone, status, created_at)
                   VALUES (?,?,?,?,?,?,?,datetime("now","localtime"))',
                    [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $roleId, $branch ?: null, $phone, $status]);
                $newId = (int)db()->lastInsertId();
                audit('Tambah User', 'Pengaturan', $newId, null, ['email' => $email, 'role' => $role['code'], 'branch_id' => $branch], 'Akun baru');
                flash('Akun ' . $email . ' berhasil dibuat.');
                $id = $newId;
            } else {
                $old = one('SELECT * FROM users WHERE id = ?', [$id]);
                if (!$old) throw new RuntimeException('Akun tidak ditemukan.');
                if ($old['role_code'] === 'super_admin' && $old['id'] !== (int)$user['id']) { /* allowed for super admin */ }
                q('UPDATE users SET name=?, email=?, role_id=?, branch_id=?, phone=?, status=?, updated_at=datetime("now","localtime") WHERE id=?',
                    [$name, $email, $roleId, $branch ?: null, $phone, $status, $id]);
                if ($pass !== '') {
                    if (strlen($pass) < 6) throw new RuntimeException('Password minimal 6 karakter.');
                    q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
                    audit('Reset Password', 'Pengaturan', $id, null, ['email' => $email], 'Password diubah oleh administrator');
                }
                audit('Edit User', 'Pengaturan', $id, $old, ['email' => $email, 'role_id' => $roleId, 'branch_id' => $branch, 'status' => $status], 'Perubahan akun');
                flash('Akun berhasil diperbarui.');
            }
            /* extra permissions */
            q('DELETE FROM user_permissions WHERE user_id = ?', [$id]);
            foreach ((array)($_POST['extra_perms'] ?? []) as $pid) {
                q('INSERT OR IGNORE INTO user_permissions (user_id, permission_id) VALUES (?,?)', [$id, (int)$pid]);
            }
            audit('Atur Permission User', 'Pengaturan', $id, null, ['extra' => count((array)($_POST['extra_perms'] ?? []))], 'Permission tambahan diperbarui');
        }

        if ($act === 'toggle') {
            $id = (int)$_POST['id'];
            if ($id === (int)$user['id']) throw new RuntimeException('Anda tidak dapat menonaktifkan akun sendiri.');
            $u = one('SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id = ?', [$id]);
            if (!$u) throw new RuntimeException('Akun tidak ditemukan.');
            if ($u['role_code'] === 'super_admin' && !is_super()) {
                deny('Akun Super Admin hanya dapat dikelola oleh Super Admin.');
            }
            $status = $u['status'] === 'active' ? 'inactive' : 'active';
            q('UPDATE users SET status=?, updated_at=datetime("now","localtime") WHERE id=?', [$status, $id]);
            audit($status === 'inactive' ? 'Nonaktifkan User' : 'Aktifkan User', 'Pengaturan', $id, ['status' => $u['status']], ['status' => $status], 'Perubahan status akun');
            flash('Status akun diubah menjadi ' . $status . '.');
        }

        if ($act === 'delete') {
            $id = (int)$_POST['id'];
            if ($id === (int)$user['id']) throw new RuntimeException('Anda tidak dapat menghapus akun sendiri.');
            $u = one('SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id = ?', [$id]);
            if (!$u) throw new RuntimeException('Akun tidak ditemukan.');
            if ($u['role_code'] === 'super_admin' && !is_super()) {
                deny('Akun Super Admin hanya dapat dikelola oleh Super Admin.');
            }
            $refs = (int)scalar('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$id])
                  + (int)scalar('SELECT COUNT(*) FROM inventory_movements WHERE user_id = ?', [$id]);
            if ($refs > 0) throw new RuntimeException('Akun ini sudah memiliki histori transaksi. Nonaktifkan saja agar audit & histori tetap utuh.');
            q('DELETE FROM user_permissions WHERE user_id = ?', [$id]);
            q('DELETE FROM users WHERE id = ?', [$id]);
            audit('Hapus User', 'Pengaturan', $id, $u, null, 'Akun belum memiliki histori');
            flash('Akun dihapus.');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: users.php');
    exit;
}

/* Urutan level dipakai untuk matriks hak akses, filter, dan pilihan role —
   supaya kolom Direktur/Owner selalu di sebelah Super Admin walau id role-nya
   lebih besar (database lama membuat role itu belakangan). */
$roles = roles_ordered();
/* Direktur/Owner tidak boleh memilih role Super Admin saat membuat/mengubah akun. */
$assignableRoles = is_super() ? $roles : array_values(array_filter($roles, fn($r) => $r['code'] !== 'super_admin'));
$perms = all('SELECT * FROM permissions ORDER BY module, name');
$q = gp('q');
$where = ['1=1']; $params = [];
if ($q !== '') { $where[] = '(u.name LIKE ? OR u.email LIKE ?)'; $t = '%' . $q . '%'; array_push($params, $t, $t); }
if (gp('role') !== '') { $where[] = 'u.role_id = ?'; $params[] = (int)gp('role'); }
if (gp('branch') !== '' && is_owner_level()) { $where[] = 'u.branch_id = ?'; $params[] = (int)gp('branch'); }
$w = implode(' AND ', $where);
$rows = all("SELECT u.*, r.name AS role_name, r.code AS role_code, b.name AS branch_name,
                    (SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id) trx
             FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN branches b ON b.id=u.branch_id
             WHERE {$w} ORDER BY r.id, u.name", $params);
$edit = gp('action') === 'edit' ? one('SELECT * FROM users WHERE id = ?', [(int)gp('id')]) : null;
$editPerms = $edit ? array_column(all('SELECT permission_id FROM user_permissions WHERE user_id = ?', [(int)$edit['id']]), 'permission_id') : [];
$grouped = [];
foreach ($perms as $p) $grouped[$p['module'] ?: 'Umum'][] = $p;

page_head('Manajemen User', 'users');
?>
<div class="page-head">
  <div><h2>Manajemen User</h2>
    <p class="muted">Akun Super Admin, Direktur/Owner, Admin/Dokter, dan Kasir beserta cabang &amp; permission tambahan.<?= is_super() ? '' : ' Akun Super Admin hanya dapat dikelola oleh Super Admin.' ?></p></div>
  <div class="page-actions">
    <a class="btn btn-primary" href="users.php?action=edit"><span class="ico"><?= icon('plus-circle') ?></span> Tambah Akun</a>
  </div>
</div>

<div class="grid g3">
  <?php foreach ($roles as $r):
    $n = (int)scalar('SELECT COUNT(*) FROM users WHERE role_id = ? AND status="active"', [$r['id']]); ?>
    <div class="stat">
      <span class="lbl"><?= e($r['name']) ?></span>
      <span class="val"><?= num($n) ?></span>
      <span class="sub">akun aktif</span>
    </div>
  <?php endforeach; ?>
</div>

<div class="card tight mt-2">
  <form class="filter-bar" method="get">
    <div class="field searchbox"><label>Cari</label><span><?= icon('search') ?></span>
      <input class="input input-sm" name="q" value="<?= e($q) ?>" placeholder="Nama / email"></div>
    <div class="field"><label>Role</label>
      <select class="input input-sm" name="role"><option value="">Semua role</option>
        <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"<?= gp('role') === (string)$r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Cabang</label>
      <select class="input input-sm" name="branch"><option value="">Semua cabang</option>
        <?php foreach (branches() as $b): ?><option value="<?= (int)$b['id'] ?>"<?= gp('branch') === (string)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
      </select></div>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    <a class="btn btn-sm" href="users.php">Reset</a>
  </form>
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state('Belum ada akun.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Nama</th><th>Email</th><th>Role</th><th>Cabang</th><th>Telepon</th><th class="num">Transaksi</th><th>Status</th><th>Login Terakhir</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $u): ?>
        <tr>
          <td><strong><?= e($u['name']) ?></strong></td>
          <td class="small"><?= e($u['email']) ?></td>
          <td><?= badge($u['role_name'], user_role_tone($u['role_code'])) ?></td>
          <td class="small"><?= e($u['branch_name'] ?: 'Semua Cabang') ?></td>
          <td class="small"><?= e($u['phone'] ?: '-') ?></td>
          <td class="num"><?= num($u['trx']) ?></td>
          <td><?= badge($u['status'] === 'active' ? 'Aktif' : 'Nonaktif', $u['status'] === 'active' ? 'green' : 'gray') ?></td>
          <td class="small"><?= e($u['last_login'] ? tgl($u['last_login'], true) : 'belum pernah') ?></td>
          <td class="nowrap"><div class="row-actions">
            <?php if ($u['role_code'] === 'super_admin' && !is_super()): ?>
              <span class="muted small"><?= icon('lock') ?> hanya Super Admin</span>
            <?php else: ?>
              <a class="btn btn-sm" href="users.php?action=edit&id=<?= (int)$u['id'] ?>">Edit</a>
              <form method="post" data-confirm="Ubah status akun ini?">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= $u['status'] === 'active' ? 'Nonaktif' : 'Aktifkan' ?></button></form>
              <form method="post" data-confirm="Hapus akun ini secara permanen?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Hapus</button></form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h3>Matriks Hak Akses Bawaan</h3>
    <span class="muted"><?= num(count($roles)) ?> level · permission per role (dapat ditambah per akun)</span>
  </div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="tbl">
        <?php /* Header dibuat dari daftar role di database, bukan ditulis tetap —
                 supaya role baru (mis. Direktur/Owner) otomatis muncul di sini. */ ?>
        <thead><tr><th>Permission</th><th>Modul</th>
          <?php foreach ($roles as $r): ?>
            <th class="center" title="<?= e($r['name']) ?>"><?= e($r['name']) ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($perms as $p):
          $has = [];
          foreach ($roles as $r) {
            $has[$r['id']] = (int)scalar('SELECT COUNT(*) FROM role_permissions WHERE role_id=? AND permission_id=?', [$r['id'], $p['id']]);
          } ?>
          <tr>
            <td class="small"><code><?= e($p['code']) ?></code><div class="muted"><?= e($p['name']) ?></div></td>
            <td class="small"><?= e($p['module']) ?></td>
            <?php foreach ($roles as $r): ?>
              <td class="center"><?= $has[$r['id']] ? badge('✔', 'green') : '<span class="muted">—</span>' ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal<?= $edit !== null || gp('action') === 'edit' ? ' open' : '' ?>" id="userModal">
  <div class="modal-box wide">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="u_id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="modal-head"><h3><?= $edit ? 'Edit Akun — ' . e($edit['name']) : 'Tambah Akun Baru' ?></h3>
        <button type="button" class="icon-btn" data-modal-close="userModal"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <div class="form-grid g2">
          <div class="field"><label>Nama Lengkap <span class="req">*</span></label><input class="input" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
          <div class="field"><label>Email <span class="req">*</span></label><input class="input" type="email" name="email" value="<?= e($edit['email'] ?? '') ?>" required></div>
          <div class="field"><label>Role <span class="req">*</span></label>
            <select class="input" name="role_id">
              <?php foreach ($assignableRoles as $r): ?><option value="<?= (int)$r['id'] ?>"<?= (int)($edit['role_id'] ?? 0) === (int)$r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label>Cabang <span class="req">*</span></label>
            <select class="input" name="branch_id">
              <option value="">— Semua Cabang (khusus Super Admin) —</option>
              <?php foreach (branches() as $b): ?><option value="<?= (int)$b['id'] ?>"<?= (int)($edit['branch_id'] ?? 0) === (int)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label>Telepon</label><input class="input" name="phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
          <div class="field"><label><?= $edit ? 'Password Baru (opsional)' : 'Password' ?> <?= $edit ? '' : '<span class="req">*</span>' ?></label>
            <input class="input" type="text" name="password" placeholder="minimal 6 karakter">
            <span class="hint"><?= $edit ? 'Biarkan kosong bila tidak ingin mengubah password.' : 'Password disimpan dalam bentuk hash.' ?></span></div>
          <div class="field"><label>Status</label>
            <select class="input" name="status">
              <option value="active"<?= ($edit['status'] ?? '') === 'active' ? ' selected' : '' ?>>Aktif</option>
              <option value="inactive"<?= ($edit['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Nonaktif</option>
            </select></div>
        </div>
        <div class="section-title">Permission Tambahan (opsional)</div>
        <div class="grid g3">
          <?php foreach ($grouped as $mod => $items): ?>
            <fieldset>
              <legend><?= e($mod) ?></legend>
              <?php foreach ($items as $p): ?>
                <label class="check mb-1"><input type="checkbox" name="extra_perms[]" value="<?= (int)$p['id'] ?>"
                  <?= in_array((int)$p['id'], array_map('intval', $editPerms), true) ? 'checked' : '' ?>> <span class="small"><?= e($p['name']) ?></span></label>
              <?php endforeach; ?>
            </fieldset>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-foot"><button type="button" class="btn" data-modal-close="userModal">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan Akun</button></div>
    </form>
  </div>
</div>
<?php page_foot(); ?>
