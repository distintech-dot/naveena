<?php
/**
 * Halaman PEMBAYARAN (jalur otomatis).
 *
 * Dipakai saat kasir memilih "QRIS/Transfer otomatis": tagihan sudah dibuat di
 * payment gateway, transaksi BELUM disimpan. Halaman ini menampilkan QR/VA +
 * nominal (termasuk kode unik) dan menyelesaikan transaksi setelah pembayaran
 * dinyatakan LUNAS — baik oleh gateway (tombol "Cek Status" / notifikasi
 * webhook) maupun oleh kasir yang memeriksa mutasi (tombol konfirmasi manual).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('order.manage');
$user = current_user();

$ref = (string)gp('ref');
$p = $ref !== '' ? pay_pending_by_ref($ref) : [];
if (!$p) {
    flash('Permintaan pembayaran tidak ditemukan.', 'error');
    header('Location: order.php');
    exit;
}
assert_branch((int)$p['branch_id']);
$payload = json_decode((string)$p['order_payload'], true) ?: [];
$patient = (int)($payload['patient_id'] ?? 0) > 0
    ? one('SELECT name, patient_number FROM patients WHERE id = ?', [(int)$payload['patient_id']])
    : null;
$amount = (float)$p['amount'];
$code = (int)($payload['unique_code'] ?? 0);

/* ---- Aksi: selesai / cek status / batalkan ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'paid_manual') {
            $res = pay_pending_mark_paid($ref, 'konfirmasi kasir');
            if (!$res['ok']) throw new RuntimeException($res['error']);
            flash('Pembayaran diterima. Transaksi ' . $res['invoice'] . ' tersimpan.');
            header('Location: order_detail.php?id=' . (int)$res['order_id'] . '&new=1');
            exit;
        }
        if ($act === 'check') {
            $st = pay_gateway_status($ref, (string)$p['gateway_ref']);
            if (!$st['ok']) throw new RuntimeException($st['error']);
            q('UPDATE pay_pending SET raw = ? WHERE id = ?',
                [json_encode(['status_check' => $st], JSON_UNESCAPED_UNICODE), (int)$p['id']]);
            if ($st['paid']) {
                $res = pay_pending_mark_paid($ref, 'gateway ' . $st['status']);
                if (!$res['ok']) throw new RuntimeException($res['error']);
                flash('Pembayaran dinyatakan LUNAS oleh gateway. Transaksi ' . $res['invoice'] . ' tersimpan.');
                header('Location: order_detail.php?id=' . (int)$res['order_id'] . '&new=1');
                exit;
            }
            flash('Gateway menyatakan status: ' . ($st['status'] !== '' ? $st['status'] : 'belum lunas')
                . '. Transaksi belum disimpan — selesaikan pembayaran lebih dulu.', 'warning');
        }
        if ($act === 'cancel') {
            q('UPDATE pay_pending SET status = "cancelled" WHERE id = ?', [(int)$p['id']]);
            flash('Permintaan pembayaran dibatalkan. Tidak ada transaksi yang dibuat.', 'warning');
            header('Location: order_baru.php?branch_id=' . (int)$p['branch_id']);
            exit;
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: bayar.php?ref=' . urlencode($ref));
    exit;
}

/* Sudah lunas sebelumnya → langsung ke transaksi. */
if ((string)$p['status'] === 'paid' && (int)$p['order_id'] > 0) {
    header('Location: order_detail.php?id=' . (int)$p['order_id']);
    exit;
}

$clinic = pay_clinic_info();
$total = $amount - $code;      // total tagihan sebelum kode unik
page_head('Pembayaran — ' . (string)$p['ref'], 'order');
?>
<div class="page-head">
  <div>
    <h2>Pembayaran <?= e((string)$p['ref']) ?></h2>
    <p class="muted">Metode <strong><?= e((string)$p['method']) ?></strong> · gateway <?= e(pay_gateway_name()) ?>
      · dibuat <?= e(tgl((string)$p['created_at'], true)) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="order_baru.php?branch_id=<?= (int)$p['branch_id'] ?>">Kembali ke Order Baru</a>
  </div>
</div>

<div class="grid g2">
  <div class="card">
    <div class="card-head"><h3>Nominal &amp; Kode Unik</h3></div>
    <div class="card-body">
      <dl class="kv">
        <dt>Pasien</dt><dd><strong><?= e((string)($patient['name'] ?? '-')) ?></strong>
          <?= $patient ? ' <span class="small muted">' . e((string)$patient['patient_number']) . '</span>' : '' ?></dd>
        <dt>Total Tagihan</dt><dd><?= money($total) ?></dd>
        <dt>Kode Unik</dt><dd><strong><?= $code > 0 ? e((string)$code) : '—' ?></strong></dd>
        <dt>Jumlah Dibayar</dt><dd class="stat" style="padding:8px 12px"><span class="val"><?= money($amount) ?></span></dd>
      </dl>
      <div class="notice mt-2">Transaksi <strong>belum tersimpan</strong>. Sistem menyimpannya otomatis setelah
        pembayaran dinyatakan lunas — sesuai aturan "wajib bayar dulu".</div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Pindai / Bayar</h3>
      <span class="muted">Status: <?= e((string)$p['status']) ?></span></div>
    <div class="card-body">
      <?php if (trim((string)$p['qr_string']) !== ''): ?>
        <div class="notice" style="background:#fff">
          <div class="small muted mb-1">Kode QRIS dari <?= e(pay_gateway_name()) ?>:</div>
          <code style="word-break:break-all;font-size:.78rem"><?= e((string)$p['qr_string']) ?></code>
        </div>
        <?php
        /* Midtrans mengirim URL gambar QR; Xendit hanya qr_string (teks) — teks
           itu ditampilkan apa adanya, tanpa gambar QR buatan sendiri supaya tidak
           ada QR yang salah tampil. */
        $qrUrl = '';
        $rawJson = json_decode((string)$p['raw'], true);
        if (is_array($rawJson)) $qrUrl = (string)($rawJson['qr_url'] ?? '');
        ?>
        <?php if ($qrUrl !== ''): ?>
          <div class="mt-2" style="text-align:center">
            <img src="<?= e($qrUrl) ?>" alt="QRIS" style="max-width:260px;border:1px solid var(--line);border-radius:10px;background:#fff;padding:6px">
          </div>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($clinic['bank_name'] !== '' && $clinic['bank_account'] !== ''): ?>
        <div class="notice mt-2">
          <strong>Transfer ke rekening klinik</strong>
          <div class="small mt-1">Bank <strong><?= e($clinic['bank_name']) ?></strong> ·
            No. Rek <strong><?= e($clinic['bank_account']) ?></strong><?= $clinic['bank_holder'] !== '' ? ' · a.n. ' . e($clinic['bank_holder']) : '' ?>
            <br>Nominal tepat: <strong><?= money($amount) ?></strong><?= $code > 0 ? ' (termasuk kode unik ' . e((string)$code) . ')' : '' ?></div>
        </div>
      <?php endif; ?>
      <?php if ($clinic['qris_file'] !== ''): ?>
        <div class="notice mt-2" style="background:#fff">
          <strong>QRIS klinik (statis)</strong>
          <div class="mt-1"><img src="qris.php?v=<?= e(substr(md5($clinic['qris_file']), 0, 6)) ?>" alt="QRIS klinik"
            style="max-width:230px;border:1px solid var(--line);border-radius:10px;background:#fff;padding:6px"></div>
        </div>
      <?php endif; ?>
      <?php if ($clinic['note'] !== ''): ?><p class="small muted mt-2"><?= e($clinic['note']) ?></p><?php endif; ?>

      <div class="flex flex-wrap gap-sm mt-3">
        <?php if (pay_gateway_configured()): ?>
          <form method="post" class="inline-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="check">
            <button class="btn btn-primary" type="submit"><?= icon('refresh') ?> Cek Status ke <?= e(pay_gateway_name()) ?></button>
          </form>
        <?php endif; ?>
        <form method="post" class="inline-form" data-confirm="Tandai pembayaran ini LUNAS dan simpan transaksinya?">
          <?= csrf_field() ?><input type="hidden" name="action" value="paid_manual">
          <button class="btn btn-leaf" type="submit"><?= icon('check') ?> Pembayaran diterima (konfirmasi kasir)</button>
        </form>
        <form method="post" class="inline-form" data-confirm="Batalkan permintaan pembayaran ini? Tidak ada transaksi yang dibuat.">
          <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
          <button class="btn btn-danger" type="submit">Batalkan</button>
        </form>
      </div>
      <p class="small muted mt-2">Bila pasien membayar lewat QRIS/transfer otomatis, notifikasi gateway masuk ke
        <code><?= e(pay_webhook_url()) ?></code> dan transaksi langsung tersimpan tanpa klik apa pun.</p>
    </div>
  </div>
</div>
<?php page_foot(); ?>
