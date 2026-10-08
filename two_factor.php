<?php
/**
 * VERIFIKASI 2 LANGKAH SAAT MASUK.
 *
 * Halaman ini muncul HANYA ketika: email & kata sandi sudah benar, level pengguna
 * diwajibkan 2FA, dan perangkat authenticator-nya sudah terpasang & terverifikasi
 * (`user_2fa_pending()`). Selama perangkat belum disiapkan, halaman ini tidak
 * pernah dipakai — jadi 2FA tidak bisa mengunci pengguna dari akunnya sendiri.
 *
 * Tiga cara masuk yang tersedia:
 *   1. 6 angka dari aplikasi Google Authenticator (utama),
 *   2. kode pemulihan sekali-pakai (bila HP hilang — tidak butuh email),
 *   3. kode cadangan lewat email (hanya bila layanan email sudah dikonfigurasi;
 *      ada tombol "Kirim kode ke email" karena email tidak dikirim diam-diam).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/login_hint.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/login_security.php';
/* layout.php menyediakan brand_block() (kop merek di halaman publik). */
require_once __DIR__ . '/includes/layout.php';

/* Sudah login penuh? Tidak perlu halaman ini. */
$viaPending = (int)($_SESSION['2fa_user_id'] ?? 0);
if (current_user() && empty($_SESSION['2fa_pending'])) {
    header('Location: dashboard.php');
    exit;
}
if ($viaPending <= 0) {
    header('Location: login.php');
    exit;
}
$u = one('SELECT u.*, r.code AS role_code, r.name AS role_name FROM users u
          JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$viaPending]);
if (!$u || (string)$u['status'] !== 'active') {
    unset($_SESSION['2fa_user_id'], $_SESSION['2fa_pending']);
    header('Location: login.php');
    exit;
}

$metode = 'app';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $aksi = (string)($_POST['aksi'] ?? '');
    if ($aksi === 'email_kirim') {
        $e = '';
        $ok = twofa_email_code_send($u, $e);
        if ($ok) { flash('Kode dikirim ke ' . $u['email'] . '. Kode berlaku 10 menit.'); }
        else { $err = $e; }
    } else {
        $kode = (string)($_POST['kode'] ?? '');
        $metode = (string)($_POST['metode'] ?? 'app');
        $ok = false;
        if ($metode === 'recovery') {
            $ok = twofa_check_recovery($u, $kode);
        } elseif ($metode === 'email') {
            $ok = twofa_check_email($u, $kode);
        } else {
            $ok = twofa_check_totp($u, $kode);
        }
        if ($ok) {
            /* Kode benar → sesi penuh dijalankan. */
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$u['id'];
            $_SESSION['last_seen'] = time();
            unset($_SESSION['2fa_user_id'], $_SESSION['2fa_pending'], $_SESSION['login_fail'], $_SESSION['login_lock']);
            q("UPDATE users SET last_login = datetime('now','localtime') WHERE id = ?", [(int)$u['id']]);
            audit('Login 2FA', 'Auth', (int)$u['id'], null, ['metode' => $metode],
                'Verifikasi 2 langkah berhasil (' . $metode . ')');
            /* Halaman tujuan (bila pengguna diarahkan ke halaman masuk karena
               sesinya berakhir saat membuka halaman tertentu). */
            $tujuan = trim((string)($_SESSION['login_next'] ?? ''));
            unset($_SESSION['login_next']);
            if ($tujuan === '' || strpos($tujuan, '//') !== false || strpos($tujuan, '://') !== false
                || strpos($tujuan, ':') !== false || preg_match('~[<>"\']~', $tujuan)) {
                $tujuan = 'dashboard.php';
            } else {
                $tujuan = ltrim($tujuan, '/');
            }
            header('Location: ' . $tujuan);
            exit;
        }
        $_SESSION['2fa_fail'] = (int)($_SESSION['2fa_fail'] ?? 0) + 1;
        audit('Verifikasi 2FA Gagal', 'Auth', (int)$u['id'], null, ['metode' => $metode],
            'Kode verifikasi salah saat masuk');
        if ($_SESSION['2fa_fail'] >= 5) {
            unset($_SESSION['2fa_user_id'], $_SESSION['2fa_pending']);
            audit('Verifikasi 2FA Diblokir', 'Auth', (int)$u['id'], null, null,
                'Terlalu banyak kode salah — kembali ke halaman masuk');
            flash('Terlalu banyak kode yang salah. Silakan masuk ulang.', 'error');
            header('Location: login.php');
            exit;
        }
        $err = $metode === 'recovery'
            ? 'Kode pemulihan tidak cocok atau sudah dipakai.'
            : 'Kode tidak cocok. Pastikan jam di HP akurat (setel waktu otomatis) lalu coba lagi.';
    }
}
$notice = (string)($_SESSION['logout_notice'] ?? '');
unset($_SESSION['logout_notice']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verifikasi 2 Langkah · <?= e(clinic_name()) ?></title>
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
        <h2>Verifikasi 2 Langkah</h2>
        <p>Satu langkah lagi untuk masuk sebagai <strong><?= e($u['name']) ?></strong>
          (<?= e($u['role_name']) ?>).</p>
      </div>
    </div>
    <div class="auth-form">
      <h2>Masukkan Kode</h2>
      <p class="muted">Buka <strong>Google Authenticator</strong> di HP Anda dan masukkan 6 angka
        yang tampil untuk akun ini.</p>

      <?php if ($notice !== ''): ?><div class="alert alert-warning"><?= e($notice) ?></div><?php endif; ?>
      <?php foreach (take_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
      <?php if ($err !== ''): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

      <form method="post" class="mt-2">
        <?= csrf_field() ?>
        <input type="hidden" name="metode" value="app">
        <div class="field">
          <label>6 angka dari aplikasi authenticator</label>
          <input class="input" name="kode" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
                 autocomplete="one-time-code" autofocus required placeholder="123456"
                 style="font-size:1.5rem;letter-spacing:8px;text-align:center">
        </div>
        <button class="btn btn-primary" type="submit" style="width:100%">Masuk</button>
      </form>

      <details class="mt-3">
        <summary class="small muted" style="cursor:pointer">HP hilang / tidak bisa membuka aplikasi?</summary>
        <div class="mt-2">
          <form method="post" class="mb-2">
            <?= csrf_field() ?><input type="hidden" name="metode" value="recovery">
            <div class="field"><label>Kode pemulihan (sekali pakai)</label>
              <input class="input" name="kode" placeholder="XXXX-XXXX" autocomplete="off"></div>
            <button class="btn" type="submit" style="width:100%">Pakai Kode Pemulihan</button>
          </form>
          <?php if (mail_configured()): ?>
            <form method="post" class="mb-2">
              <?= csrf_field() ?><input type="hidden" name="aksi" value="email_kirim">
              <button class="btn btn-sm" type="submit" style="width:100%">Kirim Kode ke Email (<?= e($u['email']) ?>)</button>
            </form>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="metode" value="email">
              <div class="field"><label>Kode dari email</label>
                <input class="input" name="kode" inputmode="numeric" maxlength="6" placeholder="123456"></div>
              <button class="btn btn-sm" type="submit" style="width:100%">Pakai Kode Email</button>
            </form>
          <?php else: ?>
            <div class="notice small">Kode lewat email belum tersedia karena layanan email belum
              dikonfigurasi. Gunakan kode pemulihan atau aplikasi authenticator.</div>
          <?php endif; ?>
        </div>
      </details>

      <div class="auth-hint mt-3">
        Bukan Anda? <a href="login.php?keluar=1"><strong>Kembali ke halaman masuk</strong></a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
