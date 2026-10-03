<?php
/**
 * MODE PEMELIHARAAN (maintenance).
 *
 * Saat diaktifkan Super Admin dari Pengaturan Sistem:
 *   - Super Admin tetap bebas (perlu untuk memperbaiki sistem),
 *   - semua level lain (Admin/Dokter, Kasir) HANYA DAPAT MELIHAT data:
 *     setiap aksi tulis (tambah/edit/hapus/void/refund/kunci/purge/import)
 *     dan setiap ekspor/impor/unduh backup ditolak di sisi server,
 *   - penolakan menampilkan halaman pemeliharaan yang menjelaskan keadaan
 *     (bukan error mentah), dengan perkiraan selesai & kontak bila diisi.
 *
 * Penegakan dilakukan di satu tempat (maintenance_gate() dari config.php)
 * sehingga tidak ada halaman yang bisa "lolos" hanya karena lupa diperiksa.
 */

/** Apakah mode pemeliharaan sedang aktif? */
function maintenance_on(): bool
{
    return setting('maintenance_mode') === '1';
}

/** Isi pengumuman pemeliharaan (judul, pesan, perkiraan selesai, kontak). */
function maintenance_info(): array
{
    return [
        'title'   => setting('maintenance_title', '') !== '' ? setting('maintenance_title') : 'Sistem Sedang Dalam Pemeliharaan',
        'message' => setting('maintenance_message'),
        'until'   => setting('maintenance_until'),
        'started' => setting('maintenance_started_at'),
        'note'    => setting('maintenance_note'),
        'contact_name'  => setting('maintenance_contact_name'),
        'contact_phone' => setting('maintenance_contact_phone'),
    ];
}

/**
 * Apakah pengguna saat ini dibatasi (hanya lihat) karena pemeliharaan?
 * Super Admin selalu bebas; pengunjung yang belum login dibiarkan (halaman
 * login tetap perlu jalan).
 */
function maintenance_readonly(?array $u = null): bool
{
    if (!maintenance_on()) return false;
    $u = $u ?: current_user();
    if ($u && ($u['role_code'] ?? '') === 'super_admin') return false;
    return true;
}

/** Halaman (dan daftar aksi) yang tetap boleh diakses saat pemeliharaan. */
function maintenance_allowed_scripts(): array
{
    return [
        'login.php',        // Super Admin harus tetap bisa masuk
        'logout.php',
        'maintenance.php',  // halaman penjelasan itu sendiri
        'index.php',
        'logo.php',         // menampilkan logo (tanpa login)
        'member_bg.php',    // pratinjau background kartu member (baca-saja, wajib login)
        'photo.php',        // melihat foto pasien/dokter (baca-saja)
        'media.php',        // melihat lampiran rekam medis (baca-saja)
        'qris.php',         // menampilkan gambar QRIS klinik (baca-saja, tanpa login)
        'pay_webhook.php',  // notifikasi pembayaran dari gateway (bukan aksi pengguna)
        'switch_branch.php',// hanya memilih cabang yang sedang dilihat (bukan perubahan data)
    ];
}

/** Halaman yang seluruhnya dinonaktifkan saat pemeliharaan (impor/ekspor/backup/hapus/demo). */
function maintenance_blocked_pages(): array
{
    return ['export.php', 'import.php', 'backup.php', 'purge.php', 'demo_data.php'];
}

/**
 * Penolakan aksi karena pemeliharaan: tampilkan halaman pemeliharaan (HTML)
 * atau pesan JSON untuk permintaan AJAX. Selalu berhenti (exit).
 */
function maintenance_deny(string $reason = ''): void
{
    $info = maintenance_info();
    if (is_ajax()) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'maintenance' => true,
            'error' => $info['title'],
            'detail' => $reason !== '' ? $reason : 'Mode pemeliharaan aktif — sistem hanya dapat dilihat.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    maintenance_page($reason);
}

/**
 * Gerbang pemeliharaan — dipanggil otomatis dari config.php pada setiap request.
 * Bila aksi tidak diizinkan, tampilkan halaman pemeliharaan lalu berhenti.
 */
function maintenance_gate(): void
{
    if (!maintenance_on()) return;
    $u = current_user();
    if ($u && ($u['role_code'] ?? '') === 'super_admin') return;   // Super Admin bebas

    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, maintenance_allowed_scripts(), true)) return;

    $isPost = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    if ($isPost) {
        if ($u) {
            try {
                audit('Aksi Diblokir (Pemeliharaan)', 'Pemeliharaan', null,
                    null, ['halaman' => $script], 'Aksi tulis ditolak karena mode pemeliharaan aktif');
            } catch (Throwable $ex) { /* audit tidak boleh menggagalkan penolakan */ }
        }
        maintenance_deny('Sistem sedang dipelihara, sehingga penambahan/perubahan data untuk sementara dinonaktifkan.');
    }
    if (in_array($script, maintenance_blocked_pages(), true)) {
        $what = [
            'export.php' => 'Ekspor/unduh data',
            'import.php' => 'Impor data',
            'backup.php' => 'Backup & unduh database',
            'purge.php'  => 'Hapus data',
            'demo_data.php' => 'Pengisian data demo',
        ][$script] ?? 'Aksi ini';
        maintenance_deny($what . ' dinonaktifkan sementara selama pemeliharaan.');
    }
}

/**
 * Halaman pemeliharaan yang rapi: identitas klinik, penjelasan, perkiraan
 * selesai, kontak, daftar hal yang dinonaktifkan, dan tombol lanjut melihat data.
 */
function maintenance_page(string $reason = '', int $code = 503): void
{
    /* layout.php dipastikan tersedia: halaman ini bisa dipanggil dari skrip
       yang belum memuat layout (mis. export.php/api.php) — tanpa ini, pemanggilan
       icon()/brand_logo_src() akan gagal. */
    require_once __DIR__ . '/layout.php';
    http_response_code($code);
    $info = maintenance_info();
    $u = current_user();
    $company = clinic_name();
    $logo = function_exists('brand_logo_src') ? brand_logo_src() : '';
    $wa = preg_replace('/[^0-9]/', '', (string)$info['contact_phone']);
    if ($wa !== '' && $wa[0] === '0') $wa = '62' . substr($wa, 1);
    $until = $info['until'] !== '' ? strtotime((string)$info['until']) : false;
    $left = $until ? ($until - time()) : 0;
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($info['title']) ?> · <?= e($company) ?></title>
<link rel="stylesheet" href="assets/css/app.css">
<?php if (function_exists('theme_css')): ?><style id="themeVars"><?= theme_css() ?></style><?php endif; ?>
<?php if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$u): /* pengunjung umum: periksa ulang otomatis */
      ?><meta http-equiv="refresh" content="120"><?php endif; ?>
</head>
<body class="auth-body maint-body">
<div class="maint-wrap">
  <div class="maint-card">
    <div class="maint-top">
      <?php if ($logo !== ''): ?>
        <div class="brand brand-img"><img class="logo-img" src="<?= e($logo) ?>" alt="<?= e($company) ?>"></div>
      <?php else: ?>
        <div class="brand-text"><span class="brand-name"><?= e($company) ?></span></div>
      <?php endif; ?>
      <span class="maint-pill"><?= icon('settings') ?> Mode Pemeliharaan Aktif</span>
    </div>

    <div class="maint-ico"><?= icon('settings') ?></div>
    <h1 class="maint-title"><?= e($info['title']) ?></h1>
    <?php if (trim((string)$info['message']) !== ''): ?>
      <div class="maint-msg"><?= nl2br(e($info['message'])) ?></div>
    <?php else: ?>
      <div class="maint-msg">Kami sedang melakukan pemeliharaan sistem agar layanan kembali berjalan lebih baik.
        Selama proses ini, <strong>penambahan dan perubahan data dinonaktifkan sementara</strong> —
        Anda tetap dapat membuka menu dan melihat data yang sudah ada.</div>
    <?php endif; ?>

    <?php if ($reason !== ''): ?>
      <div class="alert alert-warning"><?= e($reason) ?></div>
    <?php endif; ?>

    <div class="maint-grid">
      <div class="maint-box">
        <div class="maint-box-h">Dimulai</div>
        <div class="maint-box-v"><?= e($info['started'] !== '' ? tgl($info['started'], true) : '-') ?></div>
      </div>
      <div class="maint-box">
        <div class="maint-box-h">Perkiraan selesai</div>
        <div class="maint-box-v"><?= e($until ? tgl($info['until'], true) : 'Belum ditentukan') ?></div>
        <?php if ($until && $left > 0): ?><div class="maint-box-s">± <?= e(maintenance_duration_text($left)) ?> lagi</div><?php endif; ?>
      </div>
      <?php if ($info['contact_name'] !== '' || $wa !== ''): ?>
      <div class="maint-box">
        <div class="maint-box-h">Butuh bantuan?</div>
        <div class="maint-box-v"><?= e($info['contact_name'] !== '' ? $info['contact_name'] : $company) ?></div>
        <?php if ($wa !== ''): ?>
          <a class="btn btn-sm btn-leaf mt-1" target="_blank" rel="noopener"
             href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Halo, saya ingin bertanya tentang pemeliharaan sistem ' . $company . '.') ?>">
            <?= icon('whatsapp') ?> WhatsApp
          </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="maint-lists">
      <div class="maint-list maint-off">
        <div class="maint-list-h">Sementara dinonaktifkan</div>
        <ul>
          <li>Tambah / ubah data (pasien, order, rekam medis, stok, user)</li>
          <li>Hapus data &amp; void / refund transaksi</li>
          <li>Impor data (Excel/CSV) dan ekspor / unduh laporan</li>
          <li>Kirim struk WhatsApp &amp; kirim laporan email</li>
        </ul>
      </div>
      <div class="maint-list maint-on">
        <div class="maint-list-h">Masih dapat dilakukan</div>
        <ul>
          <li>Membuka semua menu dan <strong>melihat data</strong> (baca-saja)</li>
          <li>Menelusuri riwayat order, rekam medis, stok, dan laporan di layar</li>
          <li>Mencetak / membuka struk transaksi yang sudah ada</li>
          <li><?= $u ? 'Masuk sebagai Super Admin untuk mengelola sistem' : 'Masuk sebagai Super Admin untuk mengakhiri pemeliharaan' ?></li>
        </ul>
      </div>
    </div>

    <?php if (trim((string)$info['note']) !== ''): ?>
      <div class="notice mt-2"><strong>Catatan dari admin:</strong> <?= nl2br(e($info['note'])) ?></div>
    <?php endif; ?>

    <div class="maint-actions">
      <?php if ($u): ?>
        <a class="btn btn-primary" href="dashboard.php"><?= icon('grid') ?> Lanjut Melihat Data</a>
        <a class="btn" href="logout.php"><?= icon('logout') ?> Keluar</a>
        <button class="btn" type="button" id="maintReload"><?= icon('activity') ?> Cek Status Terbaru</button>
      <?php else: ?>
        <a class="btn btn-primary" href="login.php"><?= icon('lock') ?> Masuk Sistem</a>
        <button class="btn" type="button" id="maintReload"><?= icon('activity') ?> Muat Ulang</button>
      <?php endif; ?>
    </div>
    <p class="maint-foot"><?= e($company) ?> · Halaman ini muncul karena sistem sedang dalam mode pemeliharaan.</p>
  </div>
</div>
<script>
(function () {
  var b = document.getElementById('maintReload');
  if (b) b.addEventListener('click', function () { location.reload(); });
  /* Periksa status tiap 60 detik: begitu pemeliharaan selesai, pengguna
     otomatis diarahkan ke dashboard (hanya untuk permintaan GET). */
  <?php if ($_SERVER['REQUEST_METHOD'] === 'GET'): ?>
  var target = <?= $u ? "'dashboard.php'" : "'login.php'" ?>;
  setInterval(function () {
    fetch('api.php?a=maintenance_status', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.maintenance === false) location.href = target; })
      .catch(function () {});
  }, 60000);
  <?php endif; ?>
})();
</script>
</body>
</html>
    <?php
    exit;
}

/** "2 jam 15 menit" dari sejumlah detik (untuk perkiraan selesai). */
function maintenance_duration_text(int $seconds): string
{
    $m = (int)round($seconds / 60);
    if ($m < 60) return $m . ' menit';
    $h = intdiv($m, 60);
    $sisa = $m % 60;
    if ($h < 24) return $h . ' jam' . ($sisa ? ' ' . $sisa . ' menit' : '');
    return intdiv($h, 24) . ' hari' . ($h % 24 ? ' ' . ($h % 24) . ' jam' : '');
}
