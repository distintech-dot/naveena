<?php
/**
 * Tautan kartu member publik untuk MEMBERSHIP UPGRADE — dipakai pesan WhatsApp.
 *
 * WhatsApp hanya dapat mengirim TEKS (tidak bisa melampirkan berkas), sehingga
 * kartu member PDF 2 halaman dikirim sebagai TAUTAN UNDUH bertoken. Halaman ini
 * tidak memerlukan login:
 *
 *   ?t=<token>              → halaman ringkas: identitas pasien + tombol unduh
 *   ?t=<token>&format=pdf   → unduh PDF kartu (2 halaman A4) yang sama seperti
 *                             yang dilampirkan pada email
 *
 * Token ditandatangani HMAC dan punya masa berlaku (bawaan 3 HARI,
 * dapat diatur Super Admin di Pengaturan Sistem → Kartu Member). Token hanya
 * berlaku untuk SATU riwayat kenaikan level/pasien, jadi halaman ini tidak
 * pernah dapat dipakai membuka daftar data pasien.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/member_upgrade.php';

$token = (string)gp('t');
$id = member_card_link_verify($token);
$r = $id > 0 ? member_upgrade_row($id) : null;

if (!$r) {
    http_response_code(410);
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Tautan kartu member tidak berlaku</title>'
        . favicon_link_tag()
        . '<link rel="stylesheet" href="assets/css/app.css"></head><body>'
        . '<main class="content"><div class="card" style="max-width:560px;margin:40px auto">'
        . '<div class="card-body">'
        . '<h2>Tautan kartu member tidak berlaku lagi</h2>'
        . '<p class="muted">Tautan kartu member memiliki masa berlaku yang terbatas dan hanya bisa dipakai '
        . 'untuk satu kartu. Silakan minta staf ' . e(clinic_name()) . ' mengirimkan tautannya kembali.</p>'
        . '<p class="muted small">Tautan yang dikirim lewat WhatsApp berlaku &#177;' . num(member_card_link_days())
        . ' hari sejak dibuat.</p>'
        . '</div></div></main></body></html>';
    exit;
}

$p = one('SELECT * FROM patients WHERE id = ?', [(int)$r['patient_id']]);
if (!$p) {
    http_response_code(410);
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Data tidak ditemukan</title>'
        . '<link rel="stylesheet" href="assets/css/app.css"></head><body><main class="content">'
        . '<div class="card" style="max-width:560px;margin:40px auto"><div class="card-body">'
        . '<h2>Data pasien tidak ditemukan</h2><p class="muted">Silakan hubungi staf ' . e(clinic_name()) . '.</p>'
        . '</div></div></main></body></html>';
    exit;
}

/* ---- Unduh PDF kartu (2 halaman A4) — identik dengan lampiran email ---- */
if (gp('format') === 'pdf') {
    try {
        $level = member_level_by_key((string)$r['new_level']);
        $bytes = member_upgrade_pdf_bytes($p, $level);
    } catch (Throwable $e) {
        $bytes = '';
    }
    if ($bytes === '' || substr($bytes, 0, 5) !== '%PDF-') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Kartu member tidak dapat dibuat saat ini. Silakan hubungi staf klinik.';
        exit;
    }
    $nama = member_upgrade_pdf_name($p);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nama . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}

/* Pengunjung halaman ini datang dari tautan WhatsApp — dicatat pada audit supaya
   staf tahu tautannya benar-benar dibuka (sekali per kunjungan halaman). */
try {
    audit('Buka Tautan Kartu Member', 'Membership', (int)$r['patient_id'],
        ['level' => (string)$r['new_level']], ['tautan' => 'whatsapp'],
        'Tautan kartu member (PDF) dibuka tanpa login — ' . (string)$r['name']);
} catch (Throwable $e) {
    /* Kegagalan pencatatan audit tidak boleh menggagalkan halaman publik. */
}

$ctx = member_upgrade_ctx((int)$id);
$levelLbl = (string)($r['new_label'] ?? '');
$berlaku = member_card_link_expiry_text($token);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Kartu Member — <?= e(clinic_name()) ?></title>
<?= favicon_link_tag() ?>
<link rel="stylesheet" href="assets/css/app.css">
<style id="themeVars"><?= theme_css() ?></style>
<?= function_exists('wallpaper_style_tag') ? wallpaper_style_tag(true) : '' ?>
</head>
<body>
<main class="content kartu-link-page">
  <div class="card kartu-link-card">
    <div class="card-head">
      <h3>Kartu Member <?= e((string)$r['name']) ?></h3>
      <span class="badge badge-green"><?= e($levelLbl !== '' ? $levelLbl : 'Member') ?></span>
    </div>
    <div class="card-body">
      <?= brand_block(false) ?>
      <p class="muted mt-2">Selamat! Level kartu member Anda di <strong><?= e(clinic_name()) ?></strong>
        <?php if ((string)($r['branch_name'] ?? '') !== ''): ?> cabang <strong><?= e((string)$r['branch_name']) ?></strong><?php endif; ?>
        naik menjadi <strong><?= e($levelLbl) ?></strong>.</p>
      <dl class="kv mt-2">
        <dt>Nama</dt><dd><?= e((string)$r['name']) ?></dd>
        <dt>Nomor Member</dt><dd><?= e((string)$r['member_number']) ?></dd>
        <dt>Level</dt><dd><strong><?= e($levelLbl) ?></strong> ·
          <?= e(member_upgrade_discount_text((string)$r['new_level'])) ?></dd>
        <dt>Tanggal Naik</dt><dd><?= e((string)($ctx['tanggal'] ?? '')) ?></dd>
        <dt>Akumulasi <?= e(member_period_label()) ?></dt><dd><?= e((string)($ctx['akumulasi'] ?? '-')) ?></dd>
      </dl>
      <div class="notice mt-3">
        Kartu member lengkap Anda tersedia sebagai <strong>PDF 2 halaman</strong> (halaman 1 pratinjau kedua
        sisi kartu, halaman 2 status member &amp; aturan diskon yang berlaku).
      </div>
      <a class="btn btn-primary mt-2" href="kartu_link.php?t=<?= e(urlencode($token)) ?>&amp;format=pdf">
        <?= icon('download') ?> Unduh Kartu Member (PDF)</a>
      <p class="small muted mt-2">Tautan ini berlaku sampai <strong><?= e($berlaku) ?></strong>.
        Simpan berkas PDF-nya agar tetap dapat dibuka setelah tautan kedaluwarsa.</p>
    </div>
  </div>
</main>
</body>
</html>
