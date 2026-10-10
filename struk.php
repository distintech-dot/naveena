<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';   // untuk brand_block() & icon()
require_once __DIR__ . '/includes/receipt.php';
require_perm('order.view');

$id = (int)gp('id');
$o = receipt_data($id);
if (!$o) {
    http_response_code(404);
    echo 'Transaksi tidak ditemukan.';
    exit;
}
assert_branch((int)$o['branch_id']);

/* ---- Unduh PDF asli ---- */
if (gp('format') === 'pdf') {
    $pdf = receipt_pdf($o, gp('copy') === '1');
    audit('Unduh Struk PDF', 'Kasir', $id, null, ['invoice' => $o['invoice_number']], 'Struk PDF diunduh');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdf['filename'] . '"');
    header('Content-Length: ' . strlen($pdf['bytes']));
    header('Cache-Control: private, no-store');
    echo $pdf['bytes'];
    exit;
}

$openWa       = gp('wa') === '1';
$waConfigured = wa_api_configured();
$sender       = wa_sender_number();
$defaultLink  = setting('wa_receipt_link') === '1';
$items        = $o['items'];
$paid         = (float)$o['paid_total'];
$template     = str_replace('{LINK}', '(tautan struk PDF)', wa_receipt_template($o));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Struk <?= e($o['invoice_number']) ?></title>
<?= function_exists('favicon_link_tag') ? favicon_link_tag() : '' ?>
<link rel="stylesheet" href="assets/css/app.css">
<style id="themeVars"><?= theme_css() ?></style>
<style>
  body{background:#F6F1F4;padding:22px 14px}
  .toolbar{max-width:520px;margin:0 auto 16px;display:flex;gap:8px;flex-wrap:wrap;justify-content:center}
  .sent-note{max-width:520px;margin:0 auto 14px}
  @media print{ body{background:#fff;padding:0} .toolbar,.sent-note,.no-print{display:none!important} }
</style>
</head>
<body>
<div class="toolbar no-print">
  <button class="btn btn-primary" onclick="window.print()"><?= icon('print') ?> Cetak Struk</button>
  <a class="btn" href="struk.php?id=<?= $id ?>&format=pdf"><?= icon('download') ?> Unduh PDF</a>
  <a class="btn btn-leaf" href="#" id="btnWa"><?= icon('whatsapp') ?> Kirim ke WhatsApp</a>
  <a class="btn" href="order_detail.php?id=<?= $id ?>">Kembali ke Transaksi</a>
</div>

<?php if ($o['receipt_sent_at'] && $o['receipt_status'] === 'sent'): ?>
<div class="sent-note no-print">
  <div class="alert alert-success" style="margin:0">
    Struk <strong>terkirim</strong> ke WhatsApp <strong><?= e($o['receipt_sent_to']) ?></strong>
    pada <?= e(tgl($o['receipt_sent_at'], true)) ?> via <?= e($o['receipt_sent_via'] ?: 'WhatsApp API') ?>.
    <a href="#" id="btnWa2">Kirim ulang</a>
  </div>
</div>
<?php elseif ($o['receipt_sent_at']): ?>
<div class="sent-note no-print">
  <div class="alert alert-warning" style="margin:0">
    Pesan struk <strong>disiapkan</strong> untuk WhatsApp <strong><?= e($o['receipt_sent_to']) ?></strong>
    pada <?= e(tgl($o['receipt_sent_at'], true)) ?> — server belum mengirim sendiri (WhatsApp API belum aktif),
    jadi pastikan pesan benar-benar terkirim dari WhatsApp perangkat petugas.
    <a href="#" id="btnWa2">Kirim lagi</a>
  </div>
</div>
<?php endif; ?>

<div class="receipt" id="struk">
  <div class="rhead">
    <?= brand_block(true) ?>
    <div style="margin-top:8px"><strong><?= e($o['branch_name']) ?></strong><br>
      <span class="small"><?= e($o['branch_address']) ?><br><?= e($o['branch_phone']) ?></span>
    </div>
  </div>
  <table>
    <tr><td>No. Invoice</td><td class="right"><strong><?= e($o['invoice_number']) ?></strong></td></tr>
    <tr><td>Tanggal</td><td class="right"><?= e(tgl($o['created_at'], true)) ?></td></tr>
    <tr><td>Pasien</td><td class="right"><?= e($o['patient_name']) ?></td></tr>
    <tr><td>No. Pasien</td><td class="right"><?= e($o['patient_number']) ?></td></tr>
    <?php if ($o['member_number']): ?><tr><td>No. Member</td><td class="right"><?= e($o['member_number']) ?></td></tr><?php endif; ?>
    <tr><td>Kasir</td><td class="right"><?= e($o['cashier_user'] ?: $o['cashier_name'] ?: '-') ?></td></tr>
  </table>

  <div style="border-top:1px dashed var(--line);margin:12px 0"></div>

  <table>
    <?php foreach ($items as $it): ?>
      <?php
        /* Bahan treatment & IS PAKET (berharga 0) tidak ditagihkan → tidak di struk. */
        if (in_array((string)($it['item_type'] ?? ''), ['material', 'package_item'], true)) continue;
        $itKind = (string)$it['item_type'];
      ?>
      <tr>
        <td colspan="2"><strong><?= e($it['item_name']) ?></strong><br>
          <?php
            /* HARGA NORMAL DICORET bila sedang promo (permintaan pemilik). */
            $hn = (float)($it['price_normal'] ?? 0);
            $promo = $hn > 0 && abs($hn - (float)$it['price']) > 0.5;
            $hargaTxt = $promo
              ? '<span class="o-price-old">' . money($hn) . '</span> <span class="o-arrow">&rarr;</span> '
                . '<strong>' . money($it['price']) . '</strong> <span class="badge badge-pink">PROMO</span>'
              : money($it['price']);
          ?>
          <span class="small"><?= e($itKind === 'treatment' ? 'Treatment' : ($itKind === 'package' ? 'Paket' : 'Skincare')) ?> · <?= qty_text($it['quantity']) ?> x <?= $hargaTxt ?></span></td>
      </tr>
      <tr><td></td><td class="right"><?= money($it['subtotal']) ?></td></tr>
    <?php endforeach; ?>
  </table>

  <div class="tot">
    <table>
      <tr><td>Subtotal</td><td class="right"><?= money($o['subtotal']) ?></td></tr>
      <tr><td>Diskon</td><td class="right">− <?= money($o['discount']) ?></td></tr>
      <?php if ((int)($o['unique_code'] ?? 0) > 0): ?>
        <tr><td>Kode Unik</td><td class="right"><?= num((int)$o['unique_code']) ?></td></tr>
      <?php endif; ?>
      <?php if ((float)($o['member_discount'] ?? 0) > 0): ?>
        <tr><td>Diskon Member<?= !empty($o['member_tier']) ? ' (' . e($o['member_tier']) . ')' : '' ?>
            <?php if (!empty($o['member_scope'])): ?><div class="small muted"><?= e(member_scope_text((string)$o['member_scope'])) ?></div><?php endif; ?></td>
          <td class="right">− <?= money($o['member_discount']) ?></td></tr>
      <?php endif; ?>
      <tr><td><strong>TOTAL</strong></td><td class="right"><strong><?= money($o['total']) ?></strong></td></tr>
      <?php foreach ($o['payments'] as $p): ?>
        <tr><td>Bayar (<?= e($p['method']) ?>)<?= $p['status'] !== 'valid' ? ' [' . e($p['status']) . ']' : '' ?></td><td class="right"><?= money($p['amount']) ?></td></tr>
      <?php endforeach; ?>
      <tr><td>Kembali</td><td class="right"><?= money(max(0, $paid - (float)$o['total'])) ?></td></tr>
    </table>
  </div>

  <?php if ($o['status'] !== 'paid'): ?>
    <div style="text-align:center;margin-top:10px;font-weight:700;color:#B3261E">*** <?= strtoupper(e($o['status'])) ?> ***</div>
    <?php if ($o['void_reason']): ?><div style="text-align:center" class="small"><?= e($o['void_reason']) ?></div><?php endif; ?>
  <?php endif; ?>

  <div style="text-align:center;margin-top:14px" class="small">
    <?= nl2br(e(setting('receipt_footer'))) ?>
  </div>
</div>

<!-- ================= Modal Kirim WhatsApp ================= -->
<div class="modal<?= $openWa ? ' open' : '' ?>" id="waModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Kirim Struk ke WhatsApp</h3>
      <button type="button" class="icon-btn" onclick="closeWa()"><?= icon('x') ?></button>
    </div>
    <div class="modal-body">
      <div class="alert alert-<?= $waConfigured ? 'info' : 'warning' ?>">
        <?= e(wa_receipt_status_text()) ?>
        <?php if (!$waConfigured): ?>
          <br>Pengirim yang dipakai: <strong><?= e($sender !== '' ? $sender : 'belum diatur (isi di Pengaturan Sistem)') ?></strong>
          <?php if ($sender === ''): ?>
            — <a href="settings.php#wa">atur nomor pengirim di Pengaturan → WhatsApp</a> agar pesan dibuka dari nomor yang benar.
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="form-grid g2">
        <div class="field">
          <label>Nomor WhatsApp Pasien <span class="req">*</span></label>
          <input class="input" id="waPhone" value="<?= e($o['patient_phone'] ?: '') ?>" placeholder="08xxxxxxxxxx">
          <span class="hint">Nama pasien: <?= e($o['patient_name']) ?><?= $o['patient_phone'] ? '' : ' — nomor belum terdaftar, isi manual atau lengkapi data pasien.' ?></span>
        </div>
        <div class="field">
          <label>Struk PDF</label>
          <label class="check"><input type="checkbox" id="waAttach" <?= $defaultLink ? 'checked' : '' ?>>
            <span>Sertakan tautan PDF pada pesan</span></label>
          <span class="hint">Bila dicentang, WhatsApp menerima tautan struk yang bisa dibuka langsung.
          Bila tidak, pesan dikirim tanpa tautan dan PDF cukup diunduh untuk dilampirkan manual.</span>
        </div>
      </div>

      <div class="field mt-2">
        <label>Isi Pesan (bisa diedit)</label>
        <textarea class="input" id="waMsg" rows="9"><?= e($template) ?></textarea>
      </div>

      <div id="waStrukInfo" class="notice mt-2">
        Struk PDF (<?= e($o['invoice_number']) ?>) akan dibuat otomatis saat Anda menekan tombol kirim.
      </div>
      <div id="waResult"></div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn" onclick="closeWa()">Batal</button>
      <a class="btn" href="struk.php?id=<?= $id ?>&format=pdf" id="waDl"><?= icon('download') ?> Unduh PDF</a>
      <button type="button" class="btn btn-leaf" id="waSend"><?= icon('whatsapp') ?>
        <?= $waConfigured ? 'Kirim via WhatsApp API' : 'Buka WhatsApp &amp; Siapkan PDF' ?></button>
    </div>
  </div>
</div>

<script>
var WA_ID = <?= $id ?>, WA_API = <?= $waConfigured ? 'true' : 'false' ?>, WA_SENDER = <?= js_json($sender) ?>;
function openWa() { document.getElementById('waModal').classList.add('open'); }
function closeWa() { document.getElementById('waModal').classList.remove('open'); }
document.getElementById('btnWa').addEventListener('click', function (e) { e.preventDefault(); openWa(); });
var b2 = document.getElementById('btnWa2');
if (b2) b2.addEventListener('click', function (e) { e.preventDefault(); openWa(); });
/* Modal ini juga TIDAK menutup saat diklik di luar kartunya (ronde 39) — sama
   dengan modal lain, supaya isian (nomor telepon/pesan) tidak hilang karena klik
   tak sengaja. Menutup hanya lewat tombol × atau Batal. */
document.querySelectorAll('.modal').forEach(function (m) {
  m.addEventListener('click', function (e) {
    if (e.target !== m) return;
    /* Default seluruh aplikasi: tahan. Modal yang memang ingin bisa ditutup lewat
       klik luar harus memakai atribut data-modal-backdrop-close. */
    if (m.getAttribute('data-modal-backdrop-close') === null) return;
    m.classList.remove('open');
  });
});

document.getElementById('waSend').addEventListener('click', async function () {
  var btn = this;
  var phone = document.getElementById('waPhone').value.trim();
  var msg = document.getElementById('waMsg').value;
  var link = document.getElementById('waAttach').checked ? '1' : '0';
  var out = document.getElementById('waResult');
  if (phone.replace(/\D/g, '').length < 8) {
    out.innerHTML = '<div class="alert alert-error">Nomor WhatsApp pasien tidak valid.</div>';
    return;
  }
  btn.disabled = true;
  var label = btn.innerHTML;
  btn.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px"></span> Memproses…';
  out.innerHTML = '';
  try {
    var res = await fetch('wa_struk.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ id: WA_ID, phone: phone, message: msg, link: link, _csrf: <?= js_json(csrf_token()) ?> })
    });
    var data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Gagal memproses.');

    var html = '';
    if (data.sent) {
      html += '<div class="alert alert-success">Pesan WhatsApp berhasil dikirim ke <strong>' + data.phone + '</strong> via API.</div>';
    } else {
      /* Penting: jangan pakai window.open() setelah await fetch — popup blocker
         browser akan memblokirnya karena sudah kehilangan "user gesture".
         Sediakan tombol yang diklik langsung oleh petugas. */
      html += '<div class="alert alert-success">Pesan siap dikirim ke <strong>' + data.phone + '</strong>.<br>'
            + 'Tekan tombol di bawah untuk membuka WhatsApp (pastikan login dengan nomor '
            + '<strong>' + (data.sender || <?= js_json(clinic_name()) ?>) + '</strong>), lalu tekan tombol kirim di WhatsApp.</div>'
            + '<div class="flex gap-sm mb-2">'
            + '<a class="btn btn-leaf" href="' + data.wa_link + '" target="_blank" rel="noopener" id="waOpenLink">'
            + 'Buka WhatsApp Sekarang</a>'
            + '<a class="btn" href="' + data.wa_link + '" target="_blank" rel="noopener" id="waOpenLink2">Buka di WhatsApp Web</a>'
            + '</div>'
            + '<div class="notice">Bila WhatsApp tidak menerima lampiran otomatis: WhatsApp memang hanya menerima '
            + 'berkas yang dilampirkan dari aplikasi. Struk PDF di bawah dapat dilampirkan manual, atau tautannya '
            + 'sudah disertakan pada pesan.</div>';
    }
    if (data.pdf_link) {
      html += '<div class="alert alert-info">Tautan struk PDF: <a href="' + data.pdf_link + '" target="_blank" rel="noopener">' + data.pdf_link + '</a></div>';
    } else if (data.pdf_error) {
      html += '<div class="alert alert-warning">Tautan PDF tidak dibuat: ' + data.pdf_error + '</div>';
    }
    if (!data.sent) {
      html += '<div class="notice mb-2"><a href="struk.php?id=' + WA_ID + '&format=pdf"><strong>Unduh struk PDF</strong></a> '
            + 'untuk dilampirkan sebagai dokumen di WhatsApp bila diperlukan.</div>';
    }
    html += '<div class="notice">Status: ' + data.status_text + '</div>';
    out.innerHTML = html;
  } catch (e) {
    out.innerHTML = '<div class="alert alert-error">' + e.message + '</div>';
  }
  btn.disabled = false;
  btn.innerHTML = label;
});

<?php if ($openWa): ?>
document.addEventListener('DOMContentLoaded', openWa);
<?php endif; ?>
</script>
<?php if (gp('print') === '1'): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
