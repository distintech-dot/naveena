<?php
/**
 * LUPA KATA SANDI — kirim tautan ganti sandi ke email akun terdaftar.
 *
 * Keamanan: jawaban SELALU sama ("bila email terdaftar, tautan dikirim") supaya
 * halaman ini tidak bisa dipakai menebak alamat email mana yang terdaftar.
 * Bila layanan email belum dikonfigurasi, pengguna diberi tahu apa adanya beserta
 * jalan lain (minta Super Admin / pakai kode pemulihan) — bukan pesan palsu.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/login_security.php';
/* layout.php menyediakan brand_block() (kop merek di halaman publik). */
require_once __DIR__ . '/includes/layout.php';

if (current_user()) { header('Location: dashboard.php'); exit; }

$email = '';
$pesan = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    /* Batasi percobaan supaya tidak dipakai mengirim email berulang-ulang. */
    $_SESSION['lupa_jml'] = (int)($_SESSION['lupa_jml'] ?? 0);
    $terakhir = (int)($_SESSION['lupa_terakhir'] ?? 0);
    if ($_SESSION['lupa_jml'] >= 5 && (time() - $terakhir) < 900) {
        $err = 'Terlalu banyak permintaan. Coba lagi 15 menit lagi.';
    } elseif ((time() - $terakhir) < 60) {
        $err = 'Mohon tunggu satu menit sebelum meminta tautan lagi.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $_SESSION['lupa_jml']++;
        $_SESSION['lupa_terakhir'] = time();
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Tulis alamat email yang sah.';
        } else {
            /* ALAMAT YANG TIDAK TERDAFTAR: email TIDAK dikirim dan pengguna diberi
               tahu apa adanya (permintaan pemilik: "jangan langsung kirim — kalau
               email tidak terdaftar, beri pesan bahwa email itu tidak terdaftar").
               Ini aplikasi internal klinik dengan jumlah akun terbatas, jadi
               memberi tahu lebih menolong daripada menyembunyikannya. */
            $u = one('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id
                      WHERE LOWER(u.email) = LOWER(?)', [$email]);
            if (!$u) {
                audit('Lupa Password — email tidak terdaftar', 'Auth', null, null,
                    ['email' => $email], 'Permintaan ganti sandi untuk email yang tidak terdaftar (tidak ada email dikirim)');
                $err = 'Email ' . $email . ' TIDAK TERDAFTAR pada sistem ini — email tidak dikirim. '
                    . 'Periksa penulisannya, atau hubungi Super Admin untuk memastikan alamat email akun Anda.';
            } elseif ((string)$u['status'] !== 'active') {
                audit('Lupa Password — akun nonaktif', 'Auth', (int)$u['id'], null, ['email' => $email],
                    'Akun tidak aktif — email tidak dikirim');
                $err = 'Akun dengan email ' . $email . ' sedang TIDAK AKTIF, jadi tautan ganti sandi tidak '
                    . 'dikirim. Hubungi Super Admin untuk mengaktifkan kembali akun Anda.';
            } else {
                $e = '';
                $ok = password_reset_send($u, $e);
                if ($ok) {
                    $pesan = 'Tautan ganti kata sandi sudah dikirim ke <strong>' . e($email) . '</strong>'
                        . ($u['name'] !== '' ? ' (akun: ' . e((string)$u['name']) . ')' : '')
                        . '. Tautan berlaku 60 menit dan hanya bisa dipakai sekali. '
                        . 'Periksa juga folder <em>Spam/Promosi</em>.';
                } else {
                    /* Akun ada, tetapi email tidak dapat dikirim — jelaskan apa adanya. */
                    $err = $e !== '' ? $e : 'Email tidak dapat dikirim.';
                }
            }
        }
    }
}
$status = mail_status_text();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lupa Kata Sandi · <?= e(clinic_name()) ?></title>
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
        <h2>Lupa Kata Sandi</h2>
        <p>Tulis email akun Anda — kami kirim tautan untuk membuat kata sandi baru.
          Bila emailnya tidak terdaftar, sistem akan memberitahu dan tidak mengirim email.</p>
      </div>
    </div>
    <div class="auth-form">
      <h2>Ganti Kata Sandi</h2>
      <?php if (!mail_configured()): ?>
        <div class="alert alert-warning">
          <strong>Layanan email belum dikonfigurasi</strong> — tautan tidak dapat dikirim otomatis.
          <?= e($status) ?><br>
          Pilihan Anda: <strong>minta Super Admin</strong> menyetel ulang kata sandi dari menu
          Manajemen User, atau gunakan <strong>kode pemulihan</strong> bila fitur verifikasi 2 langkah
          sudah Anda aktifkan.
        </div>
      <?php endif; ?>
      <?php if ($pesan !== ''): ?><div class="alert alert-success"><?= $pesan ?></div><?php endif; ?>
      <?php if ($err !== ''): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

      <form method="post" class="mt-2">
        <?= csrf_field() ?>
        <div class="field"><label>Email akun</label>
          <input class="input" type="email" name="email" value="<?= e($email) ?>" required
                 autocomplete="username" placeholder="nama@email.com"></div>
        <button class="btn btn-primary" type="submit" style="width:100%">Kirim Tautan Ganti Sandi</button>
      </form>
      <div class="auth-hint mt-3">
        Sudah ingat? <a href="login.php"><strong>Kembali ke halaman masuk</strong></a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
