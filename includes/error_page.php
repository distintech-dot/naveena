<?php
/**
 * HALAMAN TOLAK AKSES (403) — dipanggil `deny()` di includes/config.php.
 * =====================================================================
 *
 * Diperbaiki ronde 48 (permintaan pemilik: "ada tulisan akses ditolak dan ada
 * button kosong — perbaiki pesannya dengan jelas"):
 *
 *   1. TOMBOL KOSONG: halaman ini dulu memuat `assets/css/app.css` TANPA variabel
 *      tema (`theme_css()` hanya disuntikkan `page_head()`). Karena `.btn-primary`
 *      memakai `background:var(--btn-grad)` dan `color:#fff`, warnanya menjadi tidak
 *      valid sehingga tombol tampak putih dengan tulisan putih → terlihat KOSONG.
 *      Sekarang halaman ini membawa gaya SENDIRI (tanpa variabel tema) sehingga
 *      selalu rapi walau pengguna belum/tidak memakai tata letak aplikasi.
 *   2. PESAN: dibedakan antara "sesi berakhir / belum masuk" (diarahkan ke halaman
 *      masuk — ditangani `deny()`) dan "sudah masuk tetapi level tidak berhak".
 *      Halaman ini khusus keadaan kedua, dan menyebutkan dengan jelas: siapa yang
 *      sedang masuk, level apa yang dibutuhkan, serta langkah yang bisa diambil.
 *
 * Variabel yang tersedia dari `deny()`: $msg (pesan), $detail (keterangan tambahan),
 * $code (kode status).
 */
$u = current_user();
$title = 'Akses Ditolak';
$pesan = isset($msg) && trim((string)$msg) !== '' ? (string)$msg
    : 'Anda tidak memiliki hak akses untuk halaman ini.';
$ket = isset($detail) ? trim((string)$detail) : '';
$nama = $u ? (string)$u['name'] : '';
$peran = $u ? (string)($u['role_name'] ?? $u['role_code'] ?? '') : '';
$klinik = function_exists('clinic_name') ? clinic_name() : 'Aplikasi';
/* Jenis penolakan menentukan tombol yang ditampilkan: masalah TOKEN/SESI (bukan
   level) sebaiknya "Muat ulang halaman", sedangkan masalah HAK AKSES butuh
   keterangan level. Dihitung DI ATAS supaya dapat dipakai di seluruh halaman. */
$masalahSesi = (bool)preg_match('/token|sesi|terkirim lengkap|muat ulang/i', $pesan . ' ' . $ket);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Akses Ditolak · <?= htmlspecialchars($klinik, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="assets/css/app.css">
<?php /* Gaya mandiri: warna ditulis LANGSUNG (tanpa var(--...)) supaya tombol tidak
       pernah tampil kosong walau variabel tema belum disuntikkan. */ ?>
<style>
.deny-body{min-height:100vh;margin:0;display:flex;align-items:center;justify-content:center;padding:24px;
  background:linear-gradient(160deg,#F3F0FF 0%,#FDF2F8 55%,#EFF6FF 100%);
  font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1F1B3A}
.deny-box{width:100%;max-width:560px;background:rgba(255,255,255,.72);backdrop-filter:blur(20px) saturate(180%);
  -webkit-backdrop-filter:blur(20px) saturate(180%);border:1px solid rgba(255,255,255,.7);
  border-radius:18px;box-shadow:0 18px 50px rgba(79,70,229,.16);padding:28px 26px;text-align:center}
.deny-ico{width:64px;height:64px;margin:0 auto 14px;border-radius:50%;background:#FDEAEA;color:#C62828;
  display:grid;place-items:center}
.deny-ico svg{width:30px;height:30px}
.deny-box h1{margin:0 0 4px;font-size:1.32rem;letter-spacing:-.01em}
.deny-box .kode{display:inline-block;margin-bottom:10px;padding:2px 10px;border-radius:999px;
  background:#F1EDFF;color:#4F46E5;font-size:.74rem;font-weight:700;letter-spacing:.04em}
.deny-box p{margin:0 0 10px;font-size:.92rem;line-height:1.62;color:#475069}
.deny-info{margin:14px 0 4px;padding:12px 14px;border-radius:12px;background:#FFF8E6;border:1px solid #F4DFA8;
  color:#6B5300;font-size:.85rem;line-height:1.6;text-align:left}
.deny-aksi{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:18px}
.deny-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;
  padding:11px 18px;border-radius:12px;font-size:.88rem;font-weight:700;text-decoration:none;
  border:1px solid #E6E2F5;background:#fff;color:#1F1B3A;cursor:pointer}
.deny-btn:hover{text-decoration:none;border-color:#C9C2F0}
.deny-btn-utama{background:linear-gradient(135deg,#4F46E5,#8B5CF6);border-color:transparent;color:#fff;
  box-shadow:0 8px 20px rgba(79,70,229,.28)}
.deny-btn-utama:hover{color:#fff;filter:brightness(1.05)}
</style>
</head>
<body class="deny-body">
  <div class="deny-box">
    <div class="deny-ico">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
        <rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>
      </svg>
    </div>
    <span class="kode">AKSES DITOLAK · 403</span>
    <h1><?= htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($nama !== ''): ?>
      <?php if (!$masalahSesi): ?>
        <p>Anda sedang masuk sebagai <strong><?= htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') ?></strong><?= $peran !== '' ? ' (' . htmlspecialchars($peran, ENT_QUOTES, 'UTF-8') . ')' : '' ?>.</p>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($ket !== ''): ?>
      <div class="deny-info"><?= nl2br(htmlspecialchars($ket, ENT_QUOTES, 'UTF-8')) ?></div>
    <?php else: ?>
      <div class="deny-info">
        Halaman ini hanya dapat dibuka oleh level pengguna tertentu. Tindakan yang bisa dilakukan:
        <br>• kembali ke Dashboard untuk melanjutkan pekerjaan Anda, atau
        <br>• keluar lalu masuk memakai akun yang berhak (mis. akun Super Admin).
      </div>
    <?php endif; ?>
    <p style="margin-top:12px;font-size:.82rem;color:#6B6880">
      Bila Anda merasa seharusnya berhak, minta Super Admin memeriksa
      <strong>Manajemen User → Hak Akses</strong> untuk level akun Anda.
    </p>
    <div class="deny-aksi">
      <?php if ($masalahSesi): ?>
        <a class="deny-btn deny-btn-utama" href="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? 'dashboard.php'), ENT_QUOTES, 'UTF-8') ?>"
           onclick="location.reload();return false;">Muat Ulang Halaman</a>
        <a class="deny-btn" href="dashboard.php">Kembali ke Dashboard</a>
      <?php else: ?>
        <a class="deny-btn deny-btn-utama" href="dashboard.php">Kembali ke Dashboard</a>
        <a class="deny-btn" href="logout.php">Keluar &amp; masuk akun lain</a>
      <?php endif; ?>
    </div>
    <?php if ($masalahSesi): ?>
      <p style="margin-top:12px;font-size:.82rem;color:#6B6880">
        Bila tombol di atas tidak berhasil, keluar lalu masuk kembali:
        <a href="logout.php">keluar dari aplikasi</a>.
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
