<?php
/**
 * GANTI KATA SANDI dari tautan email (token sekali pakai, berlaku 60 menit).
 *
 * Setelah kata sandi diganti: semua sesi "ingat saya" milik akun itu dihapus
 * (perangkat lama tidak boleh tetap masuk) dan pemakaian token dicatat di audit.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/login_security.php';
/* layout.php menyediakan brand_block() (kop merek di halaman publik). */
require_once __DIR__ . '/includes/layout.php';

if (current_user()) { header('Location: dashboard.php'); exit; }

$token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$row = $token !== '' ? password_reset_find($token) : null;
$user = $row ? one('SELECT * FROM users WHERE id = ?', [(int)$row['user_id']]) : null;
$err = '';
$pesan = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$row || !$user) {
        $err = 'Tautan tidak berlaku lagi (kedaluwarsa atau sudah dipakai). Minta tautan baru.';
    } else {
        $sandi = (string)($_POST['password'] ?? '');
        $ulang = (string)($_POST['password2'] ?? '');
        if (strlen($sandi) < 8) {
            $err = 'Kata sandi minimal 8 karakter.';
        } elseif (!preg_match('/[A-Za-z]/', $sandi) || !preg_match('/\d/', $sandi)) {
            $err = 'Kata sandi harus memuat huruf dan angka.';
        } elseif ($sandi !== $ulang) {
            $err = 'Ulangi kata sandi tidak sama.';
        } else {
            q('UPDATE users SET password_hash = ?, updated_at = datetime("now","localtime") WHERE id = ?',
                [password_hash($sandi, PASSWORD_DEFAULT), (int)$user['id']]);
            password_reset_consume((int)$row['id']);
            /* Perangkat yang pernah "ingat saya" tidak boleh tetap masuk. */
            q('DELETE FROM login_remember WHERE user_id = ?', [(int)$user['id']]);
            audit('Ganti Kata Sandi via Tautan', 'Auth', (int)$user['id'], null, null,
                'Kata sandi diganti lewat tautan email; semua sesi "ingat saya" dihapus');
            $pesan = 'Kata sandi berhasil diganti. Silakan masuk dengan kata sandi baru.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, user-scalable=yes, viewport-fit=cover">
<title>Ganti Kata Sandi · <?= e(clinic_name()) ?></title>
<?= function_exists('favicon_link_tag') ? favicon_link_tag() : '' ?>
<link rel="stylesheet" href="assets/css/app.css">
<style id="themeVars"><?= theme_css() ?></style>
<?= function_exists('wallpaper_style_tag') ? wallpaper_style_tag(true) : '' ?>
</head>
<body class="auth-body">
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-visual">
      <?= brand_block() ?>
      <div>
        <h2>Kata Sandi Baru</h2>
        <p>Buat kata sandi baru untuk akun <strong><?= e((string)($user['email'] ?? '-')) ?></strong>.</p>
      </div>
    </div>
    <div class="auth-form">
      <h2>Ganti Kata Sandi</h2>
      <?php if ($pesan !== ''): ?>
        <div class="alert alert-success"><?= e($pesan) ?></div>
        <a class="btn btn-primary" href="login.php" style="width:100%">Masuk Sekarang</a>
      <?php elseif (!$row || !$user): ?>
        <div class="alert alert-error"><strong>Tautan tidak berlaku.</strong>
          Tautan ganti kata sandi hanya berlaku 60 menit dan dapat dipakai satu kali.</div>
        <a class="btn btn-primary" href="lupa_password.php" style="width:100%">Minta Tautan Baru</a>
      <?php else: ?>
        <?php if ($err !== ''): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
        <form method="post" class="mt-2">
          <?= csrf_field() ?>
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <div class="field"><label>Kata sandi baru</label>
            <input class="input" type="password" name="password" required autocomplete="new-password"
                   minlength="8" placeholder="minimal 8 karakter (huruf &amp; angka)"></div>
          <div class="field"><label>Ulangi kata sandi baru</label>
            <input class="input" type="password" name="password2" required autocomplete="new-password"
                   minlength="8"></div>
          <button class="btn btn-primary" type="submit" style="width:100%">Simpan Kata Sandi Baru</button>
        </form>
        <div class="auth-hint mt-3">Setelah tersimpan, semua perangkat yang "ingat saya" akan
          diminta masuk ulang — ini disengaja demi keamanan.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
