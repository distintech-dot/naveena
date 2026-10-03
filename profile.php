<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/twofa_ui.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'password') {
            $cur = (string)($_POST['current'] ?? '');
            $new = (string)($_POST['new'] ?? '');
            $conf = (string)($_POST['confirm'] ?? '');
            if (!password_verify($cur, $user['password_hash'])) throw new RuntimeException('Password saat ini tidak sesuai.');
            if (strlen($new) < 6) throw new RuntimeException('Password baru minimal 6 karakter.');
            if ($new !== $conf) throw new RuntimeException('Konfirmasi password baru tidak sama.');
            q('UPDATE users SET password_hash = ?, updated_at = datetime("now","localtime") WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            audit('Ubah Password Sendiri', 'Pengaturan', $user['id'], null, ['email' => $user['email']], 'User mengubah password sendiri');
            flash('Password berhasil diubah.');
        }
        /* Penyiapan verifikasi 2 langkah (semua level boleh menyiapkan akunnya). */
        if (strpos($act, 'twofa_') === 0) {
            twofa_handle_post($user, $act);
            header('Location: profile.php#keamanan2fa');
            exit;
        }
        if ($act === 'profile') {
            $name = trim((string)$_POST['name']);
            $phone = trim((string)($_POST['phone'] ?? ''));
            if ($name === '') throw new RuntimeException('Nama tidak boleh kosong.');
            q('UPDATE users SET name=?, phone=?, updated_at=datetime("now","localtime") WHERE id=?', [$name, $phone, $user['id']]);
            audit('Ubah Profil Sendiri', 'Pengaturan', $user['id'], null, ['name' => $name], 'User memperbarui profil');
            flash('Profil diperbarui.');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: profile.php');
    exit;
}

$perms = has_perm('user.manage') ? all('SELECT p.code, p.name, p.module FROM permissions p') : [];
$myPerms = user_perms();
$loginCount = (int)scalar('SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND action = "Login"', [$user['id']]);
$myTrx = (int)scalar('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$user['id']]);
$myActs = all('SELECT * FROM audit_logs WHERE user_id = ? ORDER BY id DESC LIMIT 12', [$user['id']]);

page_head('Akun Saya', '');
?>
<div class="page-head">
  <div><h2>Akun Saya</h2><p class="muted"><?= e($user['name']) ?> · <?= e($user['role_name']) ?> · <?= e($user['branch_name'] ?: 'Semua Cabang') ?></p></div>
</div>

<div class="grid g4">
  <div class="stat accent"><span class="lbl">Role</span><span class="val" style="font-size:1.05rem"><?= e($user['role_name']) ?></span><span class="sub"><?= num(count($myPerms)) ?> permission aktif</span></div>
  <div class="stat"><span class="lbl">Cabang</span><span class="val" style="font-size:1.05rem"><?= e($user['branch_name'] ?: 'Semua Cabang') ?></span><span class="sub"><?= is_super() ? 'Akses penuh sistem' : (is_owner_level() ? 'Cakupan semua cabang (tanpa maintenance/backup)' : 'Terbatas pada cabang ini') ?></span></div>
  <div class="stat"><span class="lbl">Transaksi Dibuat</span><span class="val"><?= num($myTrx) ?></span><span class="sub">sejak akun dibuat</span></div>
  <div class="stat"><span class="lbl">Total Login</span><span class="val"><?= num($loginCount) ?></span><span class="sub">terakhir <?= e($user['last_login'] ? tgl($user['last_login'], true) : '-') ?></span></div>
</div>

<div class="grid g2 mt-2">
  <div class="card">
    <div class="card-head"><h3>Data Akun</h3></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <div class="card-body">
        <div class="form-grid g2">
          <div class="field"><label>Nama Lengkap</label><input class="input" name="name" value="<?= e($user['name']) ?>" required></div>
          <div class="field"><label>Telepon</label><input class="input" name="phone" value="<?= e($user['phone'] ?? '') ?>"></div>
          <div class="field"><label>Email</label><input class="input" value="<?= e($user['email']) ?>" disabled>
            <span class="hint">Perubahan email dilakukan oleh Super Admin melalui Manajemen User.</span></div>
          <div class="field"><label>Status Akun</label><input class="input" value="<?= e($user['status']) ?>" disabled></div>
        </div>
      </div>
      <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)"><button class="btn btn-primary" type="submit">Simpan Profil</button></div>
    </form>
  </div>

  <div class="card">
    <div class="card-head"><h3>Ubah Password</h3></div>
    <form method="post" data-confirm="Ubah password akun Anda sekarang?">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <div class="card-body">
        <div class="form-grid">
          <div class="field"><label>Password Saat Ini <span class="req">*</span></label><input class="input" type="password" name="current" required></div>
          <div class="field"><label>Password Baru <span class="req">*</span></label><input class="input" type="password" name="new" required minlength="6"></div>
          <div class="field"><label>Konfirmasi Password Baru <span class="req">*</span></label><input class="input" type="password" name="confirm" required minlength="6"></div>
        </div>
        <div class="notice mt-2">Password disimpan dalam bentuk hash (<code>password_hash</code>) dan tidak dapat dibaca kembali.</div>
      </div>
      <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)"><button class="btn btn-primary" type="submit">Ubah Password</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Permission yang Dimiliki</h3><span class="muted"><?= num(count($myPerms)) ?> permission</span></div>
  <div class="card-body">
    <div class="chips">
      <?php foreach ($myPerms as $code): ?><span class="pill"><?= e($code) ?></span><?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card tight">
  <div class="card-head"><h3>Aktivitas Saya Terbaru</h3></div>
  <div class="table-wrap">
    <?php if (!$myActs): ?><?= empty_state('Belum ada aktivitas tercatat.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Waktu</th><th>Modul</th><th>Aktivitas</th><th>Keterangan</th></tr></thead>
      <tbody>
      <?php foreach ($myActs as $a): ?>
        <tr><td class="small nowrap"><?= e(tgl($a['created_at'], true)) ?></td>
          <td class="small"><?= e($a['module']) ?></td>
          <td><?= badge($a['action'], 'blue') ?></td>
          <td class="small"><?= e($a['reason'] ?: '-') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php
/* Kartu penyiapan verifikasi 2 langkah (komponen bersama dengan Developer Settings). */
twofa_render_card($user, 'profile.php', 'keamanan2fa');
page_foot(); ?>
