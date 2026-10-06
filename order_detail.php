<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
/* receipt_email_state() dipakai untuk menampilkan keadaan struk EMAIL secara
   terpisah dari struk WhatsApp. */
require_once __DIR__ . '/includes/mailer.php';
require_perm('order.view');
$user = current_user();

$id = (int)gp('id');
$o = one('SELECT o.*, p.name AS patient_name, p.patient_number, p.member_number, p.phone AS patient_phone, p.email AS patient_email,
                 b.name AS branch_name, b.address AS branch_address, b.phone AS branch_phone, b.email AS branch_email,
                 u.name AS cashier_name2
          FROM orders o JOIN patients p ON p.id=o.patient_id JOIN branches b ON b.id=o.branch_id
          LEFT JOIN users u ON u.id=o.user_id WHERE o.id = ?', [$id]);
if (!$o) {
    flash('Transaksi tidak ditemukan.', 'error');
    header('Location: order.php');
    exit;
}
assert_branch((int)$o['branch_id']);

/* ---------------- Void / Refund ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)$_POST['action'];
    try {
        if ($act === 'delete_hard') {
            if (!is_super()) deny('Hanya Super Admin yang boleh menghapus transaksi secara permanen.');
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') throw new RuntimeException('Alasan penghapusan wajib diisi.');
            $items0 = all('SELECT * FROM order_items WHERE order_id = ?', [$id]);
            $skItems = array_values(array_filter($items0, fn($i) => $i['item_type'] === 'skincare' && $i['skincare_id']));
            /* Bahan treatment yang dipakai juga dikembalikan stoknya saat transaksi
               dihapus permanen (kalau tidak, stok bahan akan "hilang" tanpa jejak). */
            $matItems0 = array_values(array_filter($items0, fn($i) => ($i['item_type'] ?? '') === 'material' && !empty($i['material_id'])));
            /* Komponen PAKET (produk/bahan) juga harus dikembalikan stoknya. */
            $pkgItems0 = array_values(array_filter($items0, fn($i) => ($i['item_type'] ?? '') === 'package_item'
                && ($i['skincare_id'] || $i['material_id'])));
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                /* Kembalikan stok produk yang terjual supaya inventory tetap konsisten. */
                foreach ($skItems as $it) {
                    inv_apply('skincare', (int)$it['skincare_id'], (float)$it['quantity'], 'Return',
                        'Hapus permanen transaksi ' . $o['invoice_number'] . ' — ' . $reason,
                        ['ref_type' => 'order', 'ref_id' => $id]);
                }
                foreach ($matItems0 as $it) {
                    inv_apply('material', (int)$it['material_id'], (float)$it['quantity'], 'Return',
                        'Hapus permanen transaksi ' . $o['invoice_number'] . ' — pemakaian bahan dibatalkan (' . $reason . ')',
                        ['ref_type' => 'order', 'ref_id' => $id]);
                }
                foreach ($pkgItems0 as $it) {
                    if ($it['skincare_id']) {
                        inv_apply('skincare', (int)$it['skincare_id'], (float)$it['quantity'], 'Return',
                            'Hapus permanen transaksi ' . $o['invoice_number'] . ' — isi paket dikembalikan (' . $reason . ')',
                            ['ref_type' => 'order', 'ref_id' => $id]);
                    } elseif ($it['material_id']) {
                        inv_apply('material', (int)$it['material_id'], (float)$it['quantity'], 'Return',
                            'Hapus permanen transaksi ' . $o['invoice_number'] . ' — isi paket dikembalikan (' . $reason . ')',
                            ['ref_type' => 'order', 'ref_id' => $id]);
                    }
                }
                q('DELETE FROM payments WHERE order_id = ?', [$id]);
                q('DELETE FROM order_items WHERE order_id = ?', [$id]);
                q('DELETE FROM orders WHERE id = ?', [$id]);
                $pdo->exec('COMMIT');
            } catch (Throwable $ex) {
                $pdo->exec('ROLLBACK');
                throw new RuntimeException('Gagal menghapus: ' . $ex->getMessage());
            }
            audit('HAPUS PERMANEN Transaksi', 'Kasir', $id, $o,
                ['invoice' => $o['invoice_number'], 'total' => $o['total'], 'item' => count($items0),
                 'stok_dikembalikan' => count($skItems), 'bahan_dikembalikan' => count($matItems0),
                 'isi_paket_dikembalikan' => count($pkgItems0)], $reason);
            flash('Transaksi ' . $o['invoice_number'] . ' telah dihapus permanen. Stok produk yang terjual'
                . ($matItems0 ? ' dan bahan treatment yang dipakai' : '') . ' dikembalikan.', 'warning');
            header('Location: order.php');
            exit;
        }

        /* ---- Ubah METODE pembayaran (mis. tidak jadi transfer → ganti QRIS/tunai).
           Transaksi sudah tersimpan, jadi yang berubah hanya metode pada catatan
           pembayarannya + kode unik disesuaikan (bukan membuat transaksi baru). */
        if ($act === 'pay_method') {
            if ($o['status'] !== 'paid') throw new RuntimeException('Metode pembayaran hanya dapat diubah pada transaksi berstatus paid.');
            $newMethod = in_array((string)($_POST['method'] ?? ''), PAY_METHODS, true) ? (string)$_POST['method'] : '';
            if ($newMethod === '') throw new RuntimeException('Metode pembayaran tidak dikenal.');
            $refNo = trim((string)($_POST['ref_no'] ?? ''));
            $code = pay_unique_code_for($newMethod);
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                /* Sesuaikan total: kode unik lama dikembalikan, kode unik baru ditambahkan. */
                $oldCode = (int)($o['unique_code'] ?? 0);
                $total = round((float)$o['total'] - $oldCode + $code, 2);
                q('UPDATE orders SET unique_code = ?, total = ?, payment_status = "paid", updated_at=datetime("now","localtime") WHERE id = ?',
                    [$code, $total, $id]);
                q('UPDATE payments SET method = ?, ref_no = ?, amount = ?, status = "valid" WHERE order_id = ?',
                    [$newMethod, $refNo !== '' ? $refNo : null, $total, $id]);
                $pdo->exec('COMMIT');
            } catch (Throwable $ex) {
                $pdo->exec('ROLLBACK');
                throw $ex;
            }
            $paysOld = all('SELECT method FROM payments WHERE order_id = ?', [$id]);
            audit('Ubah Metode Pembayaran', 'Kasir', $id,
                ['metode' => implode(', ', array_map(fn($x) => (string)$x['method'], $paysOld)), 'total' => (float)$o['total']],
                ['metode' => $newMethod, 'total' => $total, 'kode_unik' => $code],
                'Metode pembayaran diganti menjadi ' . $newMethod);
            flash('Metode pembayaran diubah menjadi ' . $newMethod . '.'
                . ($code > 0 ? ' Kode unik ' . $code . ' ditambahkan ke total.' : ''));
            header('Location: order_detail.php?id=' . $id);
            exit;
        }

        if (!has_perm('order.void')) deny('Hanya Super Admin atau user dengan hak Void/Refund yang dapat melakukan tindakan ini.');
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '') throw new RuntimeException('Alasan void/refund wajib diisi.');
        if ($o['status'] !== 'paid') throw new RuntimeException('Transaksi ini sudah berstatus ' . $o['status'] . '.');
        $target = $act === 'void' ? 'void' : 'refund';
        $pdo = db();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            q('UPDATE orders SET status=?, payment_status=?, void_reason=?, void_by=?, void_at=datetime("now","localtime"), updated_at=datetime("now","localtime") WHERE id=?',
                [$target, $target === 'void' ? 'void' : 'refunded', $reason, $user['id'], $id]);
            q('UPDATE payments SET status = ? WHERE order_id = ?', [$target === 'void' ? 'void' : 'refunded', $id]);
            /* Return skincare stock to inventory */
            $items = all('SELECT * FROM order_items WHERE order_id = ? AND item_type = "skincare" AND skincare_id IS NOT NULL', [$id]);
            foreach ($items as $it) {
                inv_apply('skincare', (int)$it['skincare_id'], (float)$it['quantity'], 'Return',
                    ucfirst($target) . ' invoice ' . $o['invoice_number'] . ' — ' . $reason,
                    ['ref_type' => 'order', 'ref_id' => $id]);
            }
            /* Pemakaian bahan treatment pada transaksi ini juga dikembalikan
               (bahan tidak dijual, jadi pembatalan = pemakaian batal). */
            $mats = all('SELECT * FROM order_items WHERE order_id = ? AND item_type = "material" AND material_id IS NOT NULL', [$id]);
            foreach ($mats as $it) {
                inv_apply('material', (int)$it['material_id'], (float)$it['quantity'], 'Return',
                    ucfirst($target) . ' invoice ' . $o['invoice_number'] . ' — pemakaian bahan dibatalkan (' . $reason . ')',
                    ['ref_type' => 'order', 'ref_id' => $id]);
            }
            /* Isi PAKET pada transaksi ini juga dikembalikan stoknya (produk & bahan). */
            $pkgs = all('SELECT * FROM order_items WHERE order_id = ? AND item_type = "package_item"', [$id]);
            foreach ($pkgs as $it) {
                if (!empty($it['skincare_id'])) {
                    inv_apply('skincare', (int)$it['skincare_id'], (float)$it['quantity'], 'Return',
                        ucfirst($target) . ' invoice ' . $o['invoice_number'] . ' — isi paket dikembalikan (' . $reason . ')',
                        ['ref_type' => 'order', 'ref_id' => $id]);
                } elseif (!empty($it['material_id'])) {
                    inv_apply('material', (int)$it['material_id'], (float)$it['quantity'], 'Return',
                        ucfirst($target) . ' invoice ' . $o['invoice_number'] . ' — isi paket dikembalikan (' . $reason . ')',
                        ['ref_type' => 'order', 'ref_id' => $id]);
                }
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $ex) {
            $pdo->exec('ROLLBACK');
            throw $ex;
        }
        audit($target === 'void' ? 'Void Transaksi' : 'Refund Transaksi', 'Kasir', $id,
            ['status' => 'paid', 'total' => $o['total']], ['status' => $target, 'total' => 0], $reason);
        flash(($target === 'void' ? 'Transaksi di-void.' : 'Transaksi di-refund.') . ' Stok produk dikembalikan dan laporan otomatis disesuaikan.');
        header('Location: order_detail.php?id=' . $id);
        exit;
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
        header('Location: order_detail.php?id=' . $id);
        exit;
    }
}

$items = all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$id]);
$pays  = all('SELECT * FROM payments WHERE order_id = ? ORDER BY id', [$id]);
/* Bahan treatment dipisahkan: tidak pernah dihitung sebagai item penjualan. */
$matItems = array_values(array_filter($items, fn($i) => ($i['item_type'] ?? '') === 'material'));
$pkgCompItems = array_values(array_filter($items, fn($i) => ($i['item_type'] ?? '') === 'package_item'));
$sellItems = array_values(array_filter($items, fn($i) => !in_array((string)($i['item_type'] ?? ''), ['material', 'package_item'], true)));
$trItems = array_filter($sellItems, fn($i) => $i['item_type'] === 'treatment');
$skItems = array_filter($sellItems, fn($i) => $i['item_type'] === 'skincare');
$pkItems = array_filter($sellItems, fn($i) => $i['item_type'] === 'package');
$movements = all('SELECT * FROM inventory_movements WHERE ref_type = "order" AND ref_id = ? ORDER BY id', [$id]);

page_head('Detail Transaksi ' . $o['invoice_number'], 'order');
?>
<div class="page-head">
  <div>
    <h2><?= e($o['invoice_number']) ?> <?= order_status_badge($o['status']) ?></h2>
    <p class="muted"><?= e(tgl($o['created_at'], true)) ?> · <?= e($o['branch_name']) ?> · Kasir <?= e($o['cashier_name'] ?: $o['cashier_name2'] ?: '-') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="order.php"><?= icon('receipt') ?> Riwayat Order</a>
    <a class="btn" href="struk.php?id=<?= $id ?>&print=1" target="_blank"><?= icon('print') ?> Cetak Struk</a>
    <a class="btn" href="struk.php?id=<?= $id ?>&format=pdf"><?= icon('download') ?> Struk PDF</a>
    <a class="btn btn-leaf" href="struk.php?id=<?= $id ?>&wa=1"><?= icon('whatsapp') ?> Kirim ke WhatsApp</a>
    <?php if (has_perm('order.manage')): ?>
      <button class="btn" type="button" id="emailOpen"
              data-email-to="<?= e((string)($o['patient_email'] ?? '')) ?>"
              data-order="<?= (int)$id ?>"><?= icon('bell') ?> Kirim Email</button>
    <?php endif; ?>
  </div>
</div>

<?php if (gp('new') === '1'): ?>
  <div class="alert alert-success">
    Transaksi berhasil disimpan.
    <div class="flex flex-wrap gap-sm" style="margin-top:8px">
      <a class="btn btn-sm" href="struk.php?id=<?= $id ?>&print=1" target="_blank"><?= icon('print') ?> Cetak Struk</a>
      <a class="btn btn-sm" href="struk.php?id=<?= $id ?>&format=pdf"><?= icon('download') ?> Struk PDF</a>
      <?php if (has_perm('order.manage')): ?>
        <button class="btn btn-sm" type="button" id="emailOpen2"
                data-email-to="<?= e((string)($o['patient_email'] ?? '')) ?>"><?= icon('bell') ?> Kirim Struk ke Email</button>
      <?php endif; ?>
      <a class="btn btn-sm btn-leaf" href="struk.php?id=<?= $id ?>&wa=1"><?= icon('whatsapp') ?> Kirim ke WhatsApp</a>
      <?php if (($o['patient_email'] ?? '') === ''): ?>
        <span class="small muted" style="align-self:center">Belum ada email pasien — tombol email akan meminta alamatnya, dan otomatis tersimpan.</span>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php if ($o['status'] !== 'paid'): ?>
  <div class="alert alert-warning">Transaksi ini berstatus <strong><?= e($o['status']) ?></strong>.
    <?= $o['void_reason'] ? ' Alasan: ' . e($o['void_reason']) . '.' : '' ?>
    <?= $o['void_at'] ? ' Diproses ' . e(tgl($o['void_at'], true)) . '.' : '' ?>
    Transaksi ini tidak dihitung dalam laporan dan Top 5.</div>
<?php endif; ?>

<div class="grid g2">
  <div class="card">
    <div class="card-head"><h3>Informasi Transaksi</h3></div>
    <div class="card-body">
      <dl class="kv">
        <dt>Invoice</dt><dd><?= e($o['invoice_number']) ?></dd>
        <dt>Tanggal</dt><dd><?= e(tgl($o['created_at'], true)) ?></dd>
        <dt>Pasien</dt><dd><a href="pasien_detail.php?id=<?= (int)$o['patient_id'] ?>"><?= e($o['patient_name']) ?></a> (<?= e($o['patient_number']) ?>)</dd>
        <dt>Nomor Member</dt><dd><?= e($o['member_number'] ?: '-') ?></dd>
        <dt>Telepon</dt><dd><?= e($o['patient_phone'] ?: '-') ?></dd>
        <dt>Cabang</dt><dd><?= e($o['branch_name']) ?></dd>
        <dt>Kasir</dt><dd><?= e($o['cashier_name'] ?: '-') ?></dd>
        <dt>Kartu Member</dt><dd>
          <?php if ((float)($o['member_discount'] ?? 0) > 0): ?>
            <?= badge('DIPAKAI', 'green') ?> <?= e($o['member_tier'] ?: '') ?>
            <?php if (!empty($o['member_scope'])): ?>
              <div class="small muted">Cakupan diskon: <?= e(member_scope_text((string)$o['member_scope'])) ?></div>
            <?php endif; ?>
            <?php if ((float)$o['member_pct'] > 0): ?><span class="small muted">· potongan <?= num((float)$o['member_pct'], (float)$o['member_pct'] == (int)$o['member_pct'] ? 0 : 1) ?>%</span><?php endif; ?>
          <?php elseif ((int)($o['member_card'] ?? 0) === 1): ?>
            <?= badge('Kartu ada, tanpa potongan', 'yellow') ?>
            <div class="small muted">Nilai transaksi belum mencapai tier diskon terendah.</div>
          <?php else: ?>
            <span class="muted">Tidak memakai kartu member.</span>
          <?php endif; ?>
          <?php $mrp = one('SELECT id, member_card, member_number FROM patients WHERE id = ?', [(int)$o['patient_id']]); ?>
          <?php if ($mrp && (int)$mrp['member_card'] === 1): ?>
            <div><a class="small" href="member_card.php?id=<?= (int)$mrp['id'] ?>">lihat kartu member pasien (<?= e($mrp['member_number']) ?>)</a></div>
          <?php endif; ?>
        </dd>
        <dt>Catatan</dt><dd><?= e($o['notes'] ?: '-') ?></dd>
        <?php
        /* STRUK WHATSAPP dan STRUK EMAIL ditampilkan sebagai DUA baris terpisah dengan
           kolom basis data masing-masing. Sebelumnya pengiriman email menulis ke kolom
           WhatsApp, sehingga baris "Struk WhatsApp" menampilkan ALAMAT EMAIL pasien
           (membingungkan — perbaikan ronde 48). Data lama yang masih memakai kolom
           WhatsApp untuk email tetap dibaca sebagai "Email" supaya tidak salah tampil. */
        $waTo = (string)($o['receipt_sent_to'] ?? '');
        $waVia = (string)($o['receipt_sent_via'] ?? '');
        $waSebenarnyaEmail = ($waVia === 'Email' || ($waTo !== '' && filter_var($waTo, FILTER_VALIDATE_EMAIL) !== false
            && in_array((string)($o['receipt_status'] ?? ''), ['sent', 'failed'], true)));
        if ($waSebenarnyaEmail && !isset($o['receipt_email_to'])) { $o['receipt_email_to'] = $waTo; }
        if ($waSebenarnyaEmail && !isset($o['receipt_email_status']) && (string)$o['receipt_status'] === 'sent') {
            $o['receipt_email_status'] = 'sent';
            $o['receipt_email_sent_at'] = (string)($o['receipt_sent_at'] ?? '');
        }
        $emailState = function_exists('receipt_email_state') ? receipt_email_state($o)
            : ['label' => 'Belum dikirim', 'tone' => 'gray', 'to' => '', 'at' => '', 'catatan' => '', 'alamatValid' => false];
        ?>
        <dt>Struk WhatsApp</dt><dd>
          <?php if (!$waSebenarnyaEmail && $o['receipt_sent_at'] && $o['receipt_status'] === 'sent'): ?>
            <?= badge('Terkirim via API', 'green') ?> ke <?= e($waTo) ?>
            <div class="small muted"><?= e(tgl($o['receipt_sent_at'], true)) ?><?= $waVia ? ' · ' . e($waVia) : '' ?></div>
          <?php elseif (!$waSebenarnyaEmail && $o['receipt_sent_at']): ?>
            <?= badge('Disiapkan (belum terkirim)', 'yellow') ?> untuk <?= e($waTo) ?>
            <div class="small muted"><?= e(tgl($o['receipt_sent_at'], true)) ?> · pesan dibuka di WhatsApp perangkat petugas; server tidak mengirim sendiri.</div>
          <?php else: ?>
            <span class="muted">Belum dikirim lewat WhatsApp.</span>
          <?php endif; ?>
          <?php if ($o['receipt_url']): ?><div><a class="small" href="<?= e($o['receipt_url']) ?>" target="_blank">tautan struk PDF</a></div><?php endif; ?>
        </dd>
        <dt>Struk Email</dt><dd>
          <?= badge($emailState['label'], $emailState['tone']) ?>
          <?php if ($emailState['to'] !== ''): ?> ke <strong><?= e($emailState['to']) ?></strong><?php endif; ?>
          <?php if ($emailState['at'] !== ''): ?>
            <div class="small muted"><?= e(tgl($emailState['at'], true)) ?></div>
          <?php endif; ?>
          <div class="small muted"><?= e($emailState['catatan']) ?></div>
          <?php
          $emailPasien = trim((string)($o['patient_email'] ?? ''));
          if ($emailPasien === ''): ?>
            <div class="small">Pasien belum punya email — <a href="pasien.php?edit=<?= (int)$o['patient_id'] ?>">isi di Data Pasien</a>
              atau isi langsung saat menekan tombol Kirim Email.</div>
          <?php elseif ($emailState['status'] !== 'sent' || $emailState['to'] !== $emailPasien): ?>
            <div class="small">Email pasien saat ini: <strong><?= e($emailPasien) ?></strong>
              <?= filter_var($emailPasien, FILTER_VALIDATE_EMAIL) === false ? ' (bentuknya tidak sah — perbaiki di Data Pasien)' : '' ?></div>
          <?php endif; ?>
        </dd>
      </dl>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Pembayaran</h3></div>
    <div class="card-body">
      <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Metode</th><th>Waktu</th><th>Referensi</th><th class="num">Jumlah</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($pays as $p): ?>
          <tr>
            <td><strong><?= e($p['method']) ?></strong></td>
            <td class="small"><?= e(tgl($p['paid_at'], true)) ?></td>
            <td class="small"><?= e($p['ref_no'] ?: '-') ?></td>
            <td class="num"><?= money($p['amount']) ?></td>
            <td><?= payment_status_badge($p['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div class="notice mt-2">
        Subtotal <strong><?= money($o['subtotal']) ?></strong> − Diskon <strong><?= money($o['discount']) ?></strong>
        <?php if ((float)($o['member_discount'] ?? 0) > 0): ?>
          − Diskon Member <strong><?= money($o['member_discount']) ?></strong>
        <?php endif; ?>
        <?php if ((int)($o['unique_code'] ?? 0) > 0): ?>
          + Kode Unik <strong><?= num((int)$o['unique_code']) ?></strong>
        <?php endif; ?>
        = Total <strong><?= money($o['total']) ?></strong>
      </div>
      <?php if ($o['status'] === 'paid' && has_perm('order.manage')): ?>
        <?php /* Pasien kadang berubah pikiran (tidak jadi transfer → QRIS/tunai).
           Metode pembayaran dapat diganti di sini; kode unik otomatis
           disesuaikan (dihapus untuk tunai, ditambahkan untuk transfer/QRIS). */ ?>
        <form method="post" class="flex gap-sm flex-wrap mt-3" style="align-items:flex-end">
          <?= csrf_field() ?><input type="hidden" name="action" value="pay_method">
          <div class="field"><label>Ubah Metode Pembayaran</label>
            <select class="input input-sm" name="method">
              <?php
              $curMethod = '';
              foreach ($pays as $pp) { if (($pp['status'] ?? '') === 'valid') { $curMethod = (string)$pp['method']; break; } }
              foreach (PAY_METHODS as $m): ?>
                <option value="<?= e($m) ?>"<?= $curMethod === $m ? ' selected' : '' ?>><?= e($m) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>No. Referensi</label>
            <input class="input input-sm" name="ref_no" placeholder="opsional"></div>
          <button class="btn btn-sm btn-primary" type="submit"
                  data-confirm="Ubah metode pembayaran transaksi ini? Kode unik &amp; total menyesuaikan metode baru.">
            Simpan Metode</button>
        </form>
        <?php if (!pay_clinic_ready()): ?>
          <div class="small muted mt-1">Data rekening/QRIS klinik belum diisi —
            lengkapi di <a href="settings.php#pembayaran">Pengaturan → Pembayaran</a>.</div>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($o['status'] === 'paid' && has_perm('order.void')): ?>
        <form method="post" class="mt-3" data-confirm="Yakin melanjutkan? Tindakan ini mengubah laporan dan mengembalikan stok produk.">
          <?= csrf_field() ?>
          <div class="field"><label>Alasan Void / Refund <span class="req">*</span></label>
            <input class="input" name="reason" required placeholder="mis. salah input item / pasien membatalkan"></div>
          <div class="flex mt-1">
            <button class="btn btn-danger" name="action" value="void" type="submit">Void Transaksi</button>
            <button class="btn" name="action" value="refund" type="submit">Refund Transaksi</button>
          </div>
        </form>
      <?php elseif ($o['status'] === 'paid'): ?>
        <div class="notice mt-2">Void/Refund hanya dapat dilakukan Super Admin atau user dengan permission <code>order.void</code>.</div>
      <?php endif; ?>

      <?php if (is_owner_level()): ?>
        <div class="notice mt-2" style="border-color:#F5C9C6;background:#FFF6F5">
          <strong style="color:#B3261E">Zona berbahaya — Super Admin</strong><br>
          Menghapus transaksi secara <strong>permanen</strong> (bukan void/refund): transaksi, item, dan pembayarannya hilang dari database
          sehingga <strong>tidak dapat ditelusuri</strong> lagi di laporan/audit. Stok produk skincare yang terjual dikembalikan,
          begitu juga bahan treatment yang dipakai pada transaksi ini.
          Untuk koreksi normal, gunakan <em>Void</em> atau <em>Refund</em> di atas agar histori tetap terjaga.
          <form method="post" class="mt-1"
                data-heavy-confirm="HAPUS"
                data-heavy-warning="Menghapus <strong>permanen</strong> transaksi <strong><?= e($o['invoice_number']) ?></strong> (<?= e($o['patient_name']) ?>, <?= money($o['total']) ?>)<br>
                  &bull; <?= num(count($sellItems)) ?> item terjual<?= $matItems ? ' + ' . num(count($matItems)) . ' bahan treatment' : '' ?> dan <?= num(count($pays)) ?> pembayaran akan hilang<br>
                  &bull; Data ini tidak lagi masuk laporan, dan tidak dapat dipulihkan<br>
                  &bull; Stok produk skincare<?= $matItems ? ' dan bahan treatment' : '' ?> pada transaksi ini akan dikembalikan<br><br>
                  Pertimbangkan <em>Void</em>/<em>Refund</em> bila tujuannya hanya membatalkan transaksi."
                data-heavy-confirm2="PERINGATAN KEDUA: transaksi <?= e($o['invoice_number']) ?> (<?= money($o['total']) ?>) akan dihapus PERMANEN dari database. Lanjutkan?">
            <?= csrf_field() ?>
            <div class="field mt-1"><label>Alasan penghapusan <span class="req">*</span></label>
              <input class="input" name="reason" required placeholder="mis. data uji yang salah input"></div>
            <button class="btn btn-danger" name="action" value="delete_hard" type="submit">Hapus Permanen Transaksi</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card tight">
  <div class="card-head"><h3>Rincian Item</h3>
    <span class="muted"><?= num(count($sellItems)) ?> item · <?= num(count($trItems)) ?> treatment · <?= num(count($skItems)) ?> skincare<?= $matItems ? ' · ' . num(count($matItems)) . ' bahan (tidak ditagihkan)' : '' ?></span></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Jenis</th><th>Kode</th><th>Nama Item</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Subtotal</th></tr></thead>
      <tbody>
      <?php foreach ($sellItems as $it): ?>
        <tr>
          <?php $ik = (string)$it['item_type']; ?>
          <td><?= badge($ik === 'treatment' ? 'Treatment' : ($ik === 'package' ? 'Paket' : 'Skincare'),
                $ik === 'treatment' ? 'pink' : ($ik === 'package' ? 'yellow' : 'green')) ?></td>
          <td class="small"><?= e($it['item_code'] ?: '-') ?></td>
          <td><?= e($it['item_name']) ?></td>
          <td class="num"><?= qty_text($it['quantity']) ?></td>
          <td class="num"><?= money($it['price']) ?></td>
          <td class="num"><?= money($it['subtotal']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="5" class="num">Subtotal</th><th class="num"><?= money($o['subtotal']) ?></th></tr>
        <tr><th colspan="5" class="num">Diskon</th><th class="num">− <?= money($o['discount']) ?></th></tr>
        <?php if ((float)($o['member_discount'] ?? 0) > 0): ?>
          <tr><th colspan="5" class="num">Diskon Member<?= !empty($o['member_tier']) ? ' (' . e($o['member_tier']) . ')' : '' ?>
              <?php if (!empty($o['member_scope'])): ?><div class="small muted"><?= e(member_scope_text((string)$o['member_scope'])) ?></div><?php endif; ?></th>
            <th class="num">− <?= money($o['member_discount']) ?></th></tr>
        <?php endif; ?>
        <tr><th colspan="5" class="num">TOTAL</th><th class="num"><?= money($o['total']) ?></th></tr>
      </tfoot>
    </table>
  </div>
</div>

<?php if ($pkgCompItems): ?>
<div class="card tight">
  <div class="card-head">
    <h3>Isi Paket yang Diserahkan</h3>
    <span class="muted"><?= num(count($pkgCompItems)) ?> komponen · stok otomatis berkurang</span>
  </div>
  <div class="card-body" style="border-bottom:1px solid var(--line)">
    <div class="notice">
      Paket dijual sebagai satu harga (lihat baris <strong>Paket</strong> di rincian item).
      Komponen di bawah ini <strong>tidak ditagihkan terpisah</strong> — nilainya sudah termasuk harga paket —
      tetapi <strong>stoknya ikut berkurang</strong> di inventory saat transaksi disimpan, dan dikembalikan
      bila transaksi di-void/refund/dihapus.
    </div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Jenis</th><th>Nama Komponen</th><th class="num">Jumlah</th><th class="num">Ditagihkan</th></tr></thead>
      <tbody>
      <?php foreach ($pkgCompItems as $it): ?>
        <tr>
          <td><?= badge(!empty($it['skincare_id']) ? 'Produk' : (!empty($it['material_id']) ? 'Bahan' : 'Treatment'),
                !empty($it['skincare_id']) ? 'green' : (!empty($it['material_id']) ? 'yellow' : 'pink')) ?></td>
          <td><?= e($it['item_name']) ?> <span class="muted small"><?= e($it['item_code'] ?: '') ?></span></td>
          <td class="num"><?= qty_text($it['quantity']) ?></td>
          <td class="num muted">Rp 0</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($matItems): ?>
<div class="card tight">
  <div class="card-head">
    <h3>Bahan Treatment yang Digunakan</h3>
    <span class="muted"><?= num(count($matItems)) ?> bahan · tidak ditagihkan ke pasien</span>
  </div>
  <div class="card-body" style="border-bottom:1px solid var(--line)">
    <div class="notice">
      Bahan berikut dipakai sebagai <strong>pelengkap proses treatment</strong> pada transaksi ini.
      Bahan treatment <strong>tidak dijual</strong> ke pasien: tidak muncul di struk, tidak menambah total
      pembayaran, dan hanya mengurangi <a href="inventory_movement.php?item_type=material">stok inventory</a>.
    </div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Jenis</th><th>Kode</th><th>Nama Bahan</th><th class="num">Jumlah Dipakai</th><th class="num">Harga</th><th class="num">Ditagihkan</th></tr></thead>
      <tbody>
      <?php foreach ($matItems as $it): ?>
        <tr>
          <td><?= badge('Bahan', 'yellow') ?></td>
          <td class="small"><?= e($it['item_code'] ?: '-') ?></td>
          <td><?= e($it['item_name']) ?>
            <?php if (!empty($it['material_id'])): ?>
              <div class="small muted"><a href="bahan.php?action=stock&id=<?= (int)$it['material_id'] ?>">lihat stok bahan</a></div>
            <?php endif; ?></td>
          <td class="num"><?= qty_text($it['quantity']) ?></td>
          <td class="num"><?= money($it['price']) ?></td>
          <td class="num"><?= money($it['subtotal']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="5" class="num">Nilai bahan (tidak ditagihkan)</th><th class="num"><?= money(array_sum(array_map(fn($x) => (float)$x['subtotal'], $matItems))) ?></th></tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($movements): ?>
<div class="card tight">
  <div class="card-head"><h3>Pergerakan Stok Terkait Transaksi Ini</h3></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Waktu</th><th>Item</th><th>Jenis</th><th class="num">Sebelum</th><th class="num">Perubahan</th><th class="num">Sesudah</th><th>Keterangan</th></tr></thead>
      <tbody>
      <?php foreach ($movements as $m): ?>
        <tr>
          <td class="small"><?= e(tgl($m['created_at'], true)) ?></td>
          <td><?= e($m['item_name']) ?></td>
          <td><?= badge($m['type'], 'blue') ?></td>
          <td class="num"><?= qty_text($m['stock_before']) ?></td>
          <td class="num"><?= ((float)$m['quantity'] > 0 ? '+' : '') . qty_text($m['quantity']) ?></td>
          <td class="num"><?= qty_text($m['stock_after']) ?></td>
          <td class="small"><?= e($m['reason'] ?: '-') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php if (has_perm('order.manage')): ?>
<div class="modal" id="emailModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Kirim Struk lewat Email</h3>
      <button type="button" class="icon-btn" data-modal-close="emailModal"><?= icon('x') ?></button>
    </div>
    <div class="modal-body">
      <div class="notice mb-2" id="emailStatus">Subjek &amp; isi pesan terisi otomatis dari Pengaturan Sistem dan boleh diubah.</div>
      <div class="field"><label>Email Tujuan <span class="req">*</span></label>
        <input class="input" type="email" id="emailTo" value="<?= e((string)($o['patient_email'] ?? '')) ?>" placeholder="nama@email.com">
        <span class="hint">Belum ada email pasien? Isi di sini — otomatis tersimpan ke data pasien untuk pengiriman berikutnya.</span></div>
      <div class="field mt-2"><label>Subjek</label><input class="input" id="emailSubject"></div>
      <div class="field mt-2"><label>Isi Pesan</label><textarea class="input" id="emailBody" rows="8"></textarea></div>
      <div class="notice mt-2 small">Struk PDF transaksi ini akan <strong>dilampirkan</strong> pada email.
        Bila layanan email belum dikonfigurasi, sistem menyampaikan alasannya apa adanya (tidak mengklaim terkirim).</div>
      <div class="notice mt-2 small" id="mailtoNotice">
        Kirim lewat aplikasi email Anda sendiri (HP/PC)? Aplikasi email tidak dapat membawa lampiran otomatis,
        jadi <strong>tautan unduh struk PDF</strong> disertakan di dalam isi pesan.
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn" data-modal-close="emailModal">Batal</button>
      <button class="btn" type="button" id="emailMailto"><?= icon('bell') ?> Buka di Aplikasi Email (HP/PC)</button>
      <button class="btn btn-primary" type="button" id="emailSend"><?= icon('bell') ?> Kirim Email Sekarang</button>
    </div>
  </div>
</div>
<script>
(function () {
  var btn = document.getElementById('emailOpen') || document.getElementById('emailOpen2');
  if (!btn) return;
  var openBtn = document.getElementById('emailOpen2') || btn;   // dipakai tombol kirim
  var ORDER_ID = <?= (int)$id ?>;
  async function openEmailModal() {
    Naveena.openModal('emailModal');
    try {
      var d = await Naveena.get('email_struk.php', { id: ORDER_ID });
      document.getElementById('emailSubject').value = d.subject || '';
      document.getElementById('emailBody').value = d.body || '';
      if (!document.getElementById('emailTo').value) document.getElementById('emailTo').value = d.email || '';
      var st = document.getElementById('emailStatus');
      st.className = 'notice mb-2';
      st.innerHTML = d.configured
        ? 'Layanan email siap. Subjek &amp; isi otomatis dari Pengaturan Sistem (boleh diubah).'
        : '<strong>Email belum dikonfigurasi.</strong> ' + (d.status || '')
          + ' Anda tetap bisa memakai tombol <strong>Buka di Aplikasi Email</strong>.';
    } catch (e) {
      document.getElementById('emailStatus').innerHTML = 'Gagal mengambil data email: ' + e.message;
    }
  }
  btn.addEventListener('click', openEmailModal);
  var btn2 = document.getElementById('emailOpen2');
  if (btn2 && btn2 !== btn) btn2.addEventListener('click', openEmailModal);
  document.getElementById('emailSend').addEventListener('click', async function () {
    var b = this, st = document.getElementById('emailStatus');
    var to = document.getElementById('emailTo').value.trim();
    if (!to) { st.className = 'alert alert-error mb-2'; st.textContent = 'Email tujuan wajib diisi.'; return; }
    b.disabled = true; st.className = 'notice mb-2'; st.textContent = 'Mengirim...';
    try {
      var fd = new URLSearchParams({
        _csrf: document.querySelector('input[name=_csrf]').value,
        id: ORDER_ID, to: to,
        subject: document.getElementById('emailSubject').value,
        body: document.getElementById('emailBody').value
      });
      var res = await fetch('email_struk.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      var d = await res.json();
      /* PENGAMAN KIRIM ULANG (ronde 39): server meminta konfirmasi karena struk
         ini sudah pernah dikirim. Tampilkan jumlah & waktu kiriman terakhir —
         supaya klik ganda tidak berubah menjadi dua email ke pasien. */
      if (d && d.needs_confirm && !d.ok) {
        b.disabled = false;
        st.className = 'alert alert-warning mb-2';
        st.textContent = (d.notice || 'Email sudah pernah dikirim. Kirim lagi?').split('\n')[0];
        if (!window.confirm(d.notice || 'Email sudah pernah dikirim. Kirim lagi?')) return;
        fd.set('confirm_resend', '1');
        res = await fetch('email_struk.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        d = await res.json();
        b.disabled = true;
      }
      if (d.ok) { st.className = 'alert alert-success mb-2'; st.textContent = 'Struk berhasil dikirim ke ' + d.to + '.'; }
      else { st.className = 'alert alert-error mb-2'; st.textContent = 'Email TIDAK terkirim: ' + d.error; }
    } catch (e) { st.className = 'alert alert-error mb-2'; st.textContent = 'Email TIDAK terkirim: ' + e.message; }
    b.disabled = false;
  });

  /* ---- Kirim lewat APLIKASI EMAIL pengguna (HP/PC) -------------------
     mailto: hanya membawa teks, jadi tautan unduh struk PDF disertakan di
     dalam isi pesan (tautan bertoken & punya masa berlaku). */
  var mailBtn = document.getElementById('emailMailto');
  if (mailBtn) mailBtn.addEventListener('click', function () {
    var st = document.getElementById('emailStatus');
    var to = document.getElementById('emailTo').value.trim();
    var body = document.getElementById('emailBody').value;
    if (body.indexOf('http') < 0) {
      st.className = 'notice mb-2';
      st.textContent = 'Menunggu tautan struk...';
      Naveena.get('email_struk.php', { id: ORDER_ID }).then(function (d) {
        document.getElementById('emailBody').value = (d.body || '');
        document.getElementById('emailSubject').value = d.subject || document.getElementById('emailSubject').value;
        mailBtn.click();
      }).catch(function (e) {
        st.className = 'alert alert-error mb-2';
        st.textContent = 'Tautan struk tidak dapat dibuat: ' + e.message;
      });
      return;
    }
    var url = 'mailto:' + encodeURIComponent(to)
      + '?subject=' + encodeURIComponent(document.getElementById('emailSubject').value)
      + '&body=' + encodeURIComponent(body);
    st.className = 'alert alert-success mb-2';
    st.innerHTML = 'Membuka aplikasi email Anda' + (to ? ' (' + to + ')' : '')
      + ' dengan subjek &amp; isi yang sudah terisi. Tekan <strong>Kirim</strong> di aplikasi tersebut — '
      + 'pengiriman dari aplikasi Anda tidak tercatat di sistem.';
    window.location.href = url;
  });
})();
</script>
<?php endif; ?>

<?php page_foot(); ?>
