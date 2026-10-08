<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/login_hint.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/login_security.php';
require_once __DIR__ . '/includes/layout.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}
/* "Kembali ke halaman masuk" dari halaman verifikasi 2 langkah: batalkan sesi
   setengah jadi supaya tidak nyangkut di tengah proses. */
if (gp('keluar') === '1') {
    unset($_SESSION['2fa_user_id'], $_SESSION['2fa_pending'], $_SESSION['2fa_fail']);
}
$notice = (string)($_SESSION['logout_notice'] ?? '');
unset($_SESSION['logout_notice']);
/* HALAMAN TUJUAN setelah masuk: dipakai bila pengguna diarahkan ke sini karena
   sesinya berakhir (deny() menambahkan ?next=). Nilai HANYA diterima bila berupa
   path di aplikasi ini (tanpa "://" dan tanpa "//") supaya tidak bisa dipakai
   mengalihkan ke situs lain. */
$next = trim((string)($_GET['next'] ?? $_POST['next'] ?? ''));
/* Path aplikasi memang dimulai "/" (mis. "/ai_developer.php") sehingga karakter itu
   HARUS diterima. Yang ditolak: URL absolut/protokol-relatif ("//situs-lain") dan
   karakter yang bisa menyusupkan HTML. */
if ($next === '' || strpos($next, '//') !== false || strpos($next, '://') !== false
    || strpos($next, ':') !== false || preg_match('~[<>"\']~', $next)) {
    $next = '';
} else {
    $next = ltrim($next, '/');
}

$email = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $_SESSION['login_fail'] = (int)($_SESSION['login_fail'] ?? 0);
    if ($_SESSION['login_fail'] >= 8 && (time() - (int)($_SESSION['login_lock'] ?? 0)) < 120) {
        $err = 'Terlalu banyak percobaan login. Silakan coba lagi 2 menit lagi.';
    } elseif ($email === '' || $pass === '') {
        $err = 'Email dan password wajib diisi.';
    } else {
        $u = one('SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id
                  WHERE u.email = ?', [$email]);
        if ($u && $u['status'] === 'active' && password_verify($pass, $u['password_hash'])) {
            $ingat = (string)($_POST['remember'] ?? '') === '1';
            /* VERIFIKASI 2 LANGKAH: bila level pengguna diwajibkan 2FA DAN
               perangkat authenticator-nya sudah terpasang, minta kode dulu.
               Sesi penuh (user_id) BELUM diisi pada tahap ini. */
            if (user_2fa_pending($u)) {
                $_SESSION['2fa_user_id'] = (int)$u['id'];
                $_SESSION['2fa_pending'] = true;
                $_SESSION['2fa_fail'] = 0;
                $_SESSION['2fa_remember'] = $ingat;      // diteruskan setelah kode benar
                $_SESSION['login_next'] = $next;         // halaman tujuan setelah 2FA berhasil
                audit('Login Tahap 1 (perlu 2FA)', 'Auth', (int)$u['id'], null, ['email' => $u['email']],
                    'Email & kata sandi benar — menunggu kode verifikasi 2 langkah');
                header('Location: two_factor.php');
                exit;
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$u['id'];
            /* "INGAT SAYA": dicentang → sesi boleh bertahan tanpa batas tidak aktif
               dan token cookie diperpanjang otomatis; tidak dicentang → sesi berakhir
               setelah batas tidak aktif (diatur di Developer Settings). */
            $_SESSION['remember_me'] = $ingat;
            $_SESSION['last_seen'] = time();
            if ($ingat) remember_cookie_set(remember_token_create((int)$u['id']), login_security()['remember_days']);
            else remember_token_clear();
            unset($_SESSION['login_fail'], $_SESSION['login_lock'], $_SESSION['active_branch'],
                  $_SESSION['2fa_user_id'], $_SESSION['2fa_pending'], $_SESSION['2fa_fail'], $_SESSION['2fa_remember']);
            q("UPDATE users SET last_login = datetime('now','localtime') WHERE id = ?", [$u['id']]);
            audit('Login', 'Auth', $u['id'], null, ['email' => $u['email']], 'Login berhasil');
            /* Pengiriman laporan otomatis tanpa cron: diperiksa saat petugas login. */
            try { if (function_exists('email_auto_run')) { $ar = email_auto_run(); if ($ar) $_SESSION['email_auto_result'] = $ar; } } catch (Throwable $e) { /* jangan blokir login */ }
            /* BACKUP OTOMATIS tanpa cron (platform tidak menyediakan cron):
               diperiksa saat ada yang login, maksimal sekali per periode jadwal
               (harian/mingguan/bulanan) — dijaga setelan backup_last_period. */
            try {
                require_once __DIR__ . '/includes/backup_lib.php';
                $bk = backup_auto_run((int)$u['id']);
                if ($bk) $_SESSION['backup_auto_result'] = $bk;
            } catch (Throwable $e) { /* jangan blokir login */ }
            /* RETENSI DATA OTOMATIS (hapus data lama) tanpa cron: diperiksa saat
               ada yang login, maksimal sekali sehari, hanya menu yang diaktifkan
               Super Admin di Developer Settings.
               PENTING: berlaku untuk SEMUA LEVEL — siapa pun yang login lebih dulu
               pada hari itu akan menjalankannya, sehingga pembersihan tetap jalan
               walau Super Admin sendiri jarang login. */
            try {
                $rt = retention_auto_run((int)$u['id']);
                if ($rt) $_SESSION['retention_auto_result'] = $rt;
            } catch (Throwable $e) { /* jangan blokir login */ }
            /* PEMBERSIHAN AKTIVITAS AKUN ("Aktivitas Saya Terbaru"): hanya aktivitas
               milik pengguna yang sedang login. Juga berlaku untuk semua level. */
            try {
                $ak = activity_auto_run((int)$u['id']);
                if ($ak) $_SESSION['activity_auto_result'] = $ak;
            } catch (Throwable $e) { /* jangan blokir login */ }
            /* RESET PERIODE KARTU MEMBER (tanpa cron): bila periode akumulasi
               (1/3/5 tahun) sudah lewat, level semua member turun sesuai setelan
               dan akumulasi mulai dari nol. Dijaga penanda `member_period_applied`
               sehingga hanya berjalan SEKALI per periode — berlaku untuk semua
               level, siapa pun yang login lebih dulu akan menjalankannya. */
            try {
                $mr = member_rollover_run((int)$u['id']);
                if ($mr['ran']) $_SESSION['member_rollover_result'] = $mr;
            } catch (Throwable $e) { /* jangan blokir login */ }
            header('Location: ' . ($next !== '' ? $next : 'dashboard.php'));
            exit;
        }
        $_SESSION['login_fail']++;
        $_SESSION['login_lock'] = time();
        if ($u) audit('Login Gagal', 'Auth', $u['id'], null, null, 'Password salah untuk ' . $email);
        $err = 'Email atau password salah.';
    }
}
if ($notice === '') $notice = trim((string)($_SESSION['deny_notice'] ?? ''));
unset($_SESSION['deny_notice']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk · <?= e(clinic_name()) ?> Management System</title>
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
        <h2>Website Management System</h2>
        <?php
        /* Nama klinik & daftar cabang diambil dari data aktif supaya halaman
           login ikut berubah saat nama klinik diganti (dulu ditulis tetap). */
        $loginBranches = array_map(fn($x) => branch_short_label((string)$x['name']), branches());
        $loginBranchTxt = implode(' · ', array_slice($loginBranches, 0, 4));
        if (count($loginBranches) > 4) $loginBranchTxt .= ' · +' . (count($loginBranches) - 4) . ' lainnya';
        ?>
        <p>Klinik Kecantikan <strong><?= e(clinic_name()) ?></strong><?= $loginBranchTxt !== '' ? ' — ' . e($loginBranchTxt) : '' ?></p>
        <ul>
          <li>Multi-cabang &amp; hak akses berbasis role</li>
          <li>Reservasi, rekam medis, dan order terhubung</li>
          <li>Inventory &amp; stok otomatis per cabang</li>
          <li>Laporan, dashboard, dan audit log</li>
        </ul>
      </div>
      <p class="small" style="color:rgba(255,255,255,.75)">© <?= date('Y') ?> <?= e(clinic_name()) ?></p>
    </div>
    <div class="auth-form">
      <?= brand_block() ?>
      <?php if (maintenance_on()): ?>
        <div class="maint-banner mt-3" style="margin-bottom:14px">
          <span class="mb-ico"><?= icon('settings') ?></span>
          <div><strong><?= e(maintenance_info()['title']) ?></strong>
            <div class="mb-sub">Kasir &amp; Admin/Dokter hanya dapat <strong>melihat</strong> data sampai pemeliharaan selesai.
              Super Admin tetap dapat masuk untuk mengelola sistem.</div></div>
        </div>
      <?php endif; ?>
      <h2 class="mt-3">Masuk ke Sistem</h2>
      <p class="muted mb-3">Gunakan email dan password akun klinik Anda.</p>

      <?php if ($err !== ''): ?>
        <div class="alert alert-error"><?= e($err) ?></div>
      <?php endif; ?>
      <?php if ($notice !== ''): ?>
        <div class="alert alert-warning"><?= e($notice) ?></div>
      <?php endif; ?>
      <?php foreach (take_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>

      <form method="post" data-loading="1">
        <?= csrf_field() ?>
        <?php if ($next !== ""): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
        <div class="form-grid">
          <div class="field">
            <label for="email">Email</label>
            <input class="input" type="email" id="email" name="email" value="<?= e($email) ?>" placeholder="<?= e(setting('company_email') !== '' ? setting('company_email') : 'nama@emailklinik.id') ?>" required autofocus autocomplete="username">
          </div>
          <div class="field">
            <label for="password">Password</label>
            <input class="input" type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
          </div>
          <?php
          /* "INGAT SAYA" + batas tidak aktif (ronde 38) — durasinya diatur
             Super Admin di Developer Settings → Pengaturan Umum/Keamanan Login. */
          $sec = login_security();
          ?>
          <div class="flex flex-wrap gap-sm" style="justify-content:space-between;align-items:center">
            <label class="check"><input type="checkbox" name="remember" value="1">
              <span>Ingat saya <span class="small muted">(<?= num($sec['remember_days']) ?> hari)</span></span></label>
            <a class="small" href="lupa_password.php">Lupa kata sandi?</a>
          </div>
          <button class="btn btn-primary btn-block" type="submit">Masuk</button>
          <div class="small muted">Tanpa centang "Ingat saya", sesi berakhir otomatis setelah
            <?= num($sec['idle_hours']) ?> jam tanpa aktivitas.</div>
        </div>
      </form>

      <?php
      /* PETUNJUK AKUN BAWAAN (ronde 37): ditampilkan per PERAN yang dipilih pemilik
         di Developer Settings → Pengaturan Umum. Hanya akun yang sandinya MASIH
         sandi bawaan yang muncul, jadi begitu sandinya diganti akun itu otomatis
         hilang dari sini (tidak pernah membocorkan sandi yang benar-benar dipakai). */
      $hintGroups = login_hint_grouped(3);
      if (login_hint_enabled()):
      ?>
      <div class="auth-hint">
        <strong>Akun bawaan sistem</strong>
        <span class="small">(kata sandi awal — akun akan hilang dari daftar ini setelah sandinya Anda ganti)</span>
        <?php if ($hintGroups): ?>
          <?php foreach ($hintGroups as $g): ?>
            <div class="mt-1"><strong><?= e((string)$g['label']) ?></strong>
              <?php foreach ($g['items'] as $i => $a): ?>
                <br><?= e($a['name']) ?><?= $a['branch'] !== '' ? ' (' . e($a['branch']) . ')' : '' ?> —
                <code><?= e($a['email']) ?></code> / <code><?= e($a['password']) ?></code>
              <?php endforeach; ?>
              <?php if ((int)$g['total'] > count($g['items'])): ?>
                <br><span class="small">+<?= num((int)$g['total'] - count($g['items'])) ?> akun lain pada peran ini</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="small mt-1">Semua kata sandi bawaan sudah diganti — daftar akun tidak ditampilkan.
            Bila lupa sandi, hubungi Super Admin untuk menyetel ulang dari menu Manajemen User.</div>
        <?php endif; ?>
        <div class="small mt-1">Daftar akun ini diatur di <strong>Developer Settings → Pengaturan Umum</strong>
          (pilih peran yang ingin ditampilkan).</div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
