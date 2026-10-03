<?php
/**
 * Tautan struk PUBLIK (bertoken) — dipakai pasien yang tidak login.
 *
 * Tautan ini disertakan pada email struk (variabel {link}) dan boleh juga
 * dipakai pada pesan WhatsApp. Tokennya ditandatangani (HMAC) dan punya masa
 * berlaku, jadi tautan hanya berlaku untuk SATU transaksi dan kedaluwarsa
 * sendiri — halaman ini tidak pernah bisa dipakai untuk membuka daftar data.
 *
 * Mode:
 *   ?t=<token>              → tampilan struk (HTML, ramah HP) + tombol unduh
 *   ?t=<token>&format=pdf   → unduh PDF struk asli
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/receipt.php';

$token = (string)gp('t');
$orderId = receipt_link_verify($token);
$o = $orderId ? receipt_data($orderId) : null;

if (!$o) {
    http_response_code(410);
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Tautan struk tidak berlaku</title>'
        . '<link rel="stylesheet" href="assets/css/app.css"></head><body>'
        . '<main class="content"><div class="card" style="max-width:560px;margin:40px auto">'
        . '<div class="card-body">'
        . '<h2>Tautan struk tidak berlaku lagi</h2>'
        . '<p class="muted">Tautan struk memiliki masa berlaku dan hanya bisa dipakai untuk satu transaksi. '
        . 'Silakan minta staf klinik mengirimkan struk Anda kembali.</p>'
        . '<p class="muted small">Bila Anda penerima email, tautan mungkin sudah melewati masa berlaku '
        . '(&#177;' . num(receipt_link_days()) . ' hari sejak dikirim).</p>'
        . '</div></div></main></body></html>';
    exit;
}

/* ---- Unduh PDF ---- */
if (gp('format') === 'pdf') {
    $pdf = receipt_pdf($o, gp('copy') === '1');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdf['filename'] . '"');
    header('Content-Length: ' . strlen($pdf['bytes']));
    echo $pdf['bytes'];
    exit;
}

/* ---- Tampilan struk (tanpa login) ---- */
$items = array_values(array_filter($o['items'] ?? [], fn($it) => $it['item_type'] !== 'material'));
$methods = implode(', ', array_map(fn($p) => (string)$p['method'], $o['payments'] ?? []));
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Struk <?= e($o['invoice_number']) ?> — <?= e(clinic_name()) ?></title>
<link rel="stylesheet" href="assets/css/app.css">
<style id="themeVars"><?= theme_css() ?></style>
<style>
  body{background:var(--bg-2);padding:22px}
  .receipt{max-width:520px;margin:0 auto;background:#fff;border:1px solid var(--line);
    border-radius:var(--radius);padding:22px}
  .receipt h1{font-size:1.05rem;margin:0 0 2px;color:var(--ink)}
  .receipt .sub{color:var(--ink-2);font-size:.8rem;margin-bottom:14px}
  .receipt table{width:100%;border-collapse:collapse;font-size:.86rem}
  .receipt td{padding:5px 0;vertical-align:top}
  .receipt .num{text-align:right;white-space:nowrap}
  .receipt tfoot td{border-top:1px solid var(--line);font-weight:700}
  .rc-actions{max-width:520px;margin:0 auto 14px;display:flex;gap:10px;flex-wrap:wrap}
  @media (max-width:520px){ body{padding:12px} .receipt{padding:16px} }
</style>
</head>
<body>
<div class="rc-actions">
  <a class="btn btn-primary" href="struk_link.php?t=<?= e(urlencode($token)) ?>&amp;format=pdf"><?= icon('download') ?> Unduh Struk PDF</a>
  <button class="btn" type="button" onclick="window.print()"><?= icon('print') ?> Cetak</button>
</div>
<div class="receipt">
  <h1><?= e(clinic_name()) ?></h1>
  <div class="sub"><?= e($o['branch_name']) ?><?= $o['branch_address'] ? ' · ' . e($o['branch_address']) : '' ?>
    <?= $o['branch_phone'] ? ' · ' . e($o['branch_phone']) : '' ?><br>
    Invoice <strong><?= e($o['invoice_number']) ?></strong> · <?= e(tgl($o['created_at'], true)) ?></div>
  <table>
    <tbody>
      <tr><td>Pasien</td><td class="num"><?= e($o['patient_name']) ?><?= $o['patient_number'] ? ' (' . e($o['patient_number']) . ')' : '' ?></td></tr>
      <tr><td>Kasir</td><td class="num"><?= e($o['cashier_name'] ?: ($o['cashier_user'] ?? '-')) ?></td></tr>
      <tr><td>Status</td><td class="num"><?= e(ucfirst((string)$o['status'])) ?></td></tr>
    </tbody>
  </table>
  <table style="margin-top:12px">
    <thead><tr><th style="text-align:left">Item</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Subtotal</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><?= e($it['item_name']) ?><div class="sub" style="margin:0"><?= e($it['item_code']) ?>
          · <?= $it['item_type'] === 'treatment' ? 'Treatment' : 'Skincare' ?></div></td>
        <td class="num"><?= e(qty_text($it['quantity'])) ?></td>
        <td class="num"><?= e(money($it['price'])) ?></td>
        <td class="num"><?= e(money($it['subtotal'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="4" class="sub">Belum ada item.</td></tr><?php endif; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="3">Subtotal</td><td class="num"><?= e(money($o['subtotal'])) ?></td></tr>
      <?php if ((float)$o['discount'] > 0): ?>
        <tr><td colspan="3">Diskon</td><td class="num">- <?= e(money($o['discount'])) ?></td></tr>
      <?php endif; ?>
      <?php if ((int)($o['unique_code'] ?? 0) > 0): ?>
        <tr><td colspan="3">Kode Unik</td><td class="num"><?= e(num((int)$o['unique_code'])) ?></td></tr>
      <?php endif; ?>
      <?php if ((float)($o['member_discount'] ?? 0) > 0): ?>
        <tr><td colspan="3">Diskon Member<?= !empty($o['member_tier']) ? ' (' . e($o['member_tier']) . ')' : '' ?>
          <?php if (!empty($o['member_scope'])): ?><div class="sub" style="margin:0"><?= e(member_scope_text((string)$o['member_scope'])) ?></div><?php endif; ?>
        </td><td class="num">- <?= e(money($o['member_discount'])) ?></td></tr>
      <?php endif; ?>
      <tr><td colspan="3">TOTAL</td><td class="num"><?= e(money($o['total'])) ?></td></tr>
      <?php if ($methods !== ''): ?>
        <tr><td colspan="3">Pembayaran</td><td class="num"><?= e($methods) ?></td></tr>
      <?php endif; ?>
    </tfoot>
  </table>
  <p class="sub" style="margin-top:14px"><?= e(setting('receipt_footer', '')) ?></p>
</div>
</body>
</html>
