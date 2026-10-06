<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
/* receipt_data()/receipt_pdf() dipakai untuk mengirim struk lewat email. */
require_once __DIR__ . '/includes/receipt.php';
require_perm('order.manage');
$user = current_user();


/* Working branch for this transaction */
$scope = scope_branch();
$allBranches = branches();
$branchId = $scope;
if ($branchId === null) {
    /* Urutan penentu cabang kerja (permintaan pemilik): bila pemilih cabang
       topbar tidak dipakai, cabang diambil dari DATA yang sedang dibuka
       (pasien dulu, lalu reservasi) supaya transaksi tersimpan di cabang yang
       benar — dulu selalu jatuh ke cabang pertama sehingga pasien Cepiring
       tertagih di Kaliwangu. Pemilih `?branch_id=` tetap dihormati bila ada. */
    $try = (int)gp('branch_id');
    $fromPatient = (int)gp('patient_id')
        ? (int)scalar('SELECT branch_id FROM patients WHERE id = ?', [(int)gp('patient_id')], 0) : 0;
    $fromAppt = (int)gp('appointment_id')
        ? (int)scalar('SELECT branch_id FROM appointments WHERE id = ?', [(int)gp('appointment_id')], 0) : 0;
    /* URUTANNYA PENTING: DATA yang sedang dibuka lebih menentukan daripada pilihan
       cabang di layar — pasien dulu, lalu reservasi, baru `?branch_id=`, dan
       terakhir cabang pertama sebagai cadangan. */
    if ($fromPatient > 0) $branchId = $fromPatient;
    elseif ($fromAppt > 0) $branchId = $fromAppt;
    elseif ($try && one('SELECT id FROM branches WHERE id = ?', [$try])) $branchId = $try;
    elseif ($allBranches) $branchId = (int)$allBranches[0]['id'];
}
/* Saat MENYIMPAN: pemakainya bisa memilih cabang lain di pemilih cabang topbar,
   jadi cabang transaksi diambil dari DATA pasien yang dipilih (bukan dari pemilih
   cabang) — order_create() juga memastikan hal yang sama di sisi server. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save'
    && user_branch() === null && (int)($_POST['patient_id'] ?? 0) > 0) {
    $patBranch = (int)scalar('SELECT branch_id FROM patients WHERE id = ?', [(int)$_POST['patient_id']], 0);
    if ($patBranch > 0) $branchId = $patBranch;
}
if (!$branchId) {
    flash('Belum ada cabang yang dapat digunakan.', 'error');
    header('Location: dashboard.php');
    exit;
}
assert_branch($branchId);
$branchRow = one('SELECT * FROM branches WHERE id = ?', [$branchId]);

/* ---------------- Save transaction ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verify_csrf();
    /* Logika pembuatan transaksi ada di includes/order_create.php supaya jalur
       pembayaran otomatis (bayar.php / pay_webhook.php) memakai aturan yang SAMA.
       Di sini pembayaran sudah dikonfirmasi kasir pada langkah "Pembayaran"
       (tunai/transfer/QRIS) — sesuai aturan "wajib bayar dulu". */
    try {
        $in = $_POST;
        $in['paid_confirm'] = ($_POST['paid_confirm'] ?? '') === '1' ? '1' : '0';
        /* Kode unik 3 digit untuk transfer/QRIS. Nilai sah dari halaman dipakai
           (agar nominal yang ditampilkan sama dengan yang tersimpan); bila tidak
           ada/tidak sah, server yang membuat. Tunai selalu tanpa kode unik. */
        $sentCode = (int)($_POST['unique_code'] ?? 0);
        $in['unique_code'] = pay_unique_code_for((string)($in['method'] ?? 'Cash'));
        if ($in['unique_code'] > 0 && $sentCode >= 101 && $sentCode <= 999) {
            $in['unique_code'] = $sentCode;
        }
        $res = order_create($in, $user, (int)$branchId);
        flash('Transaksi berhasil disimpan. Invoice ' . $res['invoice'] . '.'
            . ($res['member']['amount'] > 0 ? ' Diskon member ' . money($res['member']['amount'])
                . ' (' . $res['member']['label'] . ').' : '')
            . ($res['unique_code'] > 0 ? ' Kode unik ' . $res['unique_code'] . ' (total transfer sudah termasuk kode unik).' : '')
            . $res['card_msg'] . $res['email_info'], $res['member_warn'] !== '' ? 'warning' : 'success');
        if ($res['member_warn'] !== '') flash($res['member_warn'], 'warning');
        header('Location: order_detail.php?id=' . $res['order_id'] . '&new=1');
        exit;
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
        header('Location: order_baru.php?branch_id=' . $branchId . '&patient_id=' . (int)($_POST['patient_id'] ?? 0));
        exit;
    }
}

/* ---------------- Pembayaran OTOMATIS (gateway) ----------------
   Dipakai saat kasir memilih "QRIS/Transfer otomatis": tagihan dibuat di
   gateway, transaksinya BARU dibuat setelah gateway menyatakan lunas
   (aturan "wajib bayar dulu"). Muatan transaksi disimpan di tabel pay_pending
   supaya notifikasi gateway (tanpa sesi) bisa menyelesaikannya. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_auto') {
    verify_csrf();
    header('Content-Type: application/json');
    try {
        if (!pay_gateway_configured()) {
            echo json_encode(['ok' => false, 'error' => 'Pembayaran otomatis belum siap: kunci API '
                . pay_gateway_name() . ' belum diisi di Pengaturan Sistem → Pembayaran. '
                . 'Silakan pakai jalur manual (transfer/QRIS klinik).'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $payload = json_decode((string)($_POST['payload'] ?? ''), true);
        if (!is_array($payload)) throw new RuntimeException('Data transaksi tidak terbaca.');
        $code = pay_unique_code_for((string)($payload['method'] ?? 'QRIS'));
        $payload['unique_code'] = $code;
        /* Hitung total yang harus dibayar (subtotal - diskon + kode unik) sesuai
           aturan yang sama dengan order_create(), agar nominal di gateway tepat. */
        $subtotal = 0.0;
        foreach ((array)($payload['items'] ?? []) as $it) {
            if ((string)($it['type'] ?? '') === 'material') continue;
            $subtotal += qty_parse($it['qty'] ?? 0) * qty_parse($it['price'] ?? 0);
        }
        $disc = qty_parse($payload['discount'] ?? 0);
        $memberDisc = 0.0;   // potongan member dihitung ulang di order_create()
        $amountPreview = max(0, $subtotal - $disc) + $code;
        $p = pay_pending_create($payload + ['pay_amount' => $amountPreview], (int)$branchId, (int)$user['id']);
        $created = pay_gateway_create((string)$p['ref'], $amountPreview,
            'Invoice ' . (string)scalar('SELECT name FROM patients WHERE id = ?', [(int)($payload['patient_id'] ?? 0)], '-'));
        if (!$created['ok']) {
            q('UPDATE pay_pending SET status = "failed", raw = ? WHERE id = ?',
                [json_encode($created, JSON_UNESCAPED_UNICODE), (int)$p['id']]);
            echo json_encode(['ok' => false, 'error' => $created['error'] !== '' ? $created['error']
                : 'Gateway tidak mengembalikan tagihan QRIS.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        pay_pending_set_gateway((int)$p['id'], $created);
        echo json_encode([
            'ok' => true, 'ref' => (string)$p['ref'], 'amount' => $amountPreview,
            'unique_code' => $code, 'gateway' => pay_gateway_name(),
            'qr_string' => $created['qr_string'], 'qr_url' => $created['qr_url'],
            'expires' => $created['expires'], 'pay_url' => 'bayar.php?ref=' . urlencode((string)$p['ref']),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        error_log('pay_auto gagal: ' . $ex->getMessage());
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$patient = null;
if ((int)gp('patient_id')) {
    $patient = one('SELECT * FROM patients WHERE id = ?', [(int)gp('patient_id')]);
    if ($patient) assert_branch((int)$patient['branch_id']);
}
$appt = (int)gp('appointment_id') ? one('SELECT * FROM appointments WHERE id = ?', [(int)gp('appointment_id')]) : null;
if ($appt) assert_branch((int)$appt['branch_id']);

/* ---- Pra-isi item dari RESERVASI (tombol "→ Transaksi") ----
   Treatment yang dipesan pada reservasi dimasukkan lebih dulu ke daftar item
   transaksi supaya kasir tidak memilih ulang. Semuanya masih bisa DIUBAH,
   ditambah, atau dihapus seperti item biasa (bukan data terkunci). */
$prefill = [];
if ($appt) {
    foreach (res_treatments((int)$appt['id']) as $t) {
        $tid = (int)($t['treatment_id'] ?? 0);
        if ($tid <= 0) continue;
        $row = one('SELECT id, name, code, normal_price, promo_price, status, branch_id FROM treatments WHERE id = ?', [$tid]);
        if (!$row || $row['status'] !== 'active') continue;
        $price = (float)$row['promo_price'] > 0 ? (float)$row['promo_price'] : (float)$row['normal_price'];
        $prefill[] = [
            'type' => 'treatment', 'id' => (int)$row['id'], 'name' => (string)$row['name'],
            'code' => (string)$row['code'], 'price' => $price, 'stock' => null, 'unit' => '',
        ];
    }
}

/* Info pembayaran klinik (rekening & QRIS) untuk langkah Pembayaran. */
$payInfoQ = pay_clinic_info();
/* Kode unik disiapkan SEKALI untuk halaman ini supaya nominal yang ditampilkan
   ke kasir/pasien sama dengan yang tersimpan (dikirim sebagai field tersembunyi). */
$payCodeQ = pay_unique_code_for('QRIS');
page_head('Order Baru', 'order_baru');
?>
<div class="page-head">
  <div>
    <h2>Order Baru — <?= e($branchRow['name']) ?></h2>
    <p class="muted">Pilih pasien → tambahkan treatment/skincare → simpan transaksi &amp; cetak struk.</p>
  </div>
  <div class="page-actions">
    <?php if (is_owner_level()): ?>
      <form method="get" class="inline-form">
        <select class="input input-sm" name="branch_id" data-autosubmit>
          <?php foreach ($allBranches as $b): ?>
            <option value="<?= (int)$b['id'] ?>"<?= (int)$b['id'] === $branchId ? ' selected' : '' ?>>Cabang: <?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
    <a class="btn" href="order.php">Riwayat Order</a>
  </div>
</div>

<?php if ($appt): ?>
  <div class="alert alert-info">Melanjutkan reservasi <strong><?= e($appt['appointment_number']) ?></strong> — transaksi ini akan menandai reservasi sebagai <em>Selesai</em>.</div>
<?php endif; ?>

<form method="post" id="orderForm" data-loading="1">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="appointment_id" value="<?= (int)($appt['id'] ?? 0) ?>">
  <input type="hidden" name="patient_id" id="o_patient_id" value="<?= (int)($patient['id'] ?? 0) ?>">

  <div class="card">
    <div class="card-head"><h3>1. Data Pasien &amp; Cabang</h3></div>
    <div class="card-body">
      <div class="form-grid g3">
        <div class="field"><label>Pasien <span class="req">*</span></label>
          <div class="searchbox"><span><?= icon('search') ?></span>
            <input class="input" id="o_patient_search" placeholder="Cari nama / NIK / telepon / nomor member" autocomplete="off"
                   value="<?= e($patient ? $patient['name'] . ' (' . $patient['patient_number'] . ')' : '') ?>">
            <div class="suggest" id="o_patient_suggest"></div>
          </div>
          <span class="hint">Belum terdaftar? <a href="pasien.php?action=new">Tambah pasien baru</a>.</span>
        </div>
        <div class="field"><label>Cabang Transaksi</label>
          <input class="input" value="<?= e($branchRow['name']) ?>" disabled>
        </div>
        <div class="field"><label>Kasir</label><input class="input" value="<?= e($user['name']) ?>" disabled></div>
      </div>
      <div id="o_patient_info" class="notice mt-2<?= $patient ? '' : ' hide' ?>">
        <?php if ($patient): ?>
          Member <strong><?= e($patient['member_number']) ?></strong> · <?= e($patient['phone'] ?: 'tanpa telepon') ?> ·
          <?php if (($patient['email'] ?? '') !== ''): ?>
            Email <strong><?= e($patient['email']) ?></strong>
          <?php else: ?>
            <span class="muted" title="Tambahkan email di data pasien bila ingin mengirim struk lewat email">belum ada email</span>
          <?php endif; ?>
          · Status <?= e($patient['patient_type']) ?>
        <?php endif; ?>
      </div>

      <?php if (member_card_enabled()): ?>
      <?php
      $mLevels = member_levels();
      $pId = (int)($patient['id'] ?? 0);
      $pStatus = $patient ? member_status($patient) : null;
      $pIsMember = $patient && patient_is_member($patient);
      ?>
      <div class="member-box" id="o_member_box">
        <div class="member-box-head">
          <span class="mb-title"><?= icon('star') ?> Kartu Member — diskon berlevel</span>
          <span id="o_member_state" class="badge badge-gray">belum dipilih</span>
        </div>
        <div class="member-box-body">
          <input type="hidden" name="member_activate" id="o_member_activate" value="<?= $pIsMember ? '1' : '0' ?>">
          <div class="status-picker member-picker">
            <label class="status-opt<?= $pIsMember ? ' active' : '' ?>" id="o_mem_yes_opt">
              <input type="radio" name="member_card" value="1" id="o_member_yes"<?= $pIsMember ? ' checked' : '' ?>>
              <span class="st-name">Ya — ada kartu member</span>
              <span class="st-desc">Potongan dihitung otomatis sesuai level member.</span>
            </label>
            <label class="status-opt<?= $pIsMember ? '' : ' active' ?>" id="o_mem_no_opt">
              <input type="radio" name="member_card" value="0" id="o_member_no"<?= $pIsMember ? '' : ' checked' ?>>
              <span class="st-name">Tidak ada kartu</span>
              <span class="st-desc">Tanpa potongan member (kartu tetap dapat terbuka otomatis dari aturan di Pengaturan).</span>
            </label>
            <?php /* Pilihan ketiga — hanya MUNCUL bila pasien memang pemegang kartu:
                       artinya pasien PUNYA kartu tetapi diskon TIDAK dipakai pada
                       transaksi ini. Pilihan "Tidak ada kartu" di tengah dinonaktifkan
                       karena tidak sesuai keadaan (pasien jelas punya kartu). */ ?>
            <label class="status-opt hide" id="o_mem_skip_opt">
              <input type="radio" name="member_card" value="0" id="o_member_skip">
              <span class="st-name">Tanpa Kartu Member</span>
              <span class="st-desc">Pasien pemegang kartu, tetapi <strong>diskon tidak dipakai</strong> pada transaksi ini.</span>
            </label>
          </div>
          <div class="alert alert-warning mt-2 hide" id="o_member_ask">
            <strong>Pasien ini belum terdaftar sebagai pemegang Kartu Member.</strong>
            <div class="small mt-1" id="o_member_ask_sub"></div>
            <div class="small mt-1">Apakah Kartu Member mau diaktifkan pada pasien ini?</div>
            <div class="flex gap-sm mt-2">
              <button class="btn btn-sm btn-primary" type="button" id="o_member_yes_activate">
                <?= icon('star') ?> Ya, aktifkan kartu &amp; beri diskon</button>
              <button class="btn btn-sm" type="button" id="o_member_no_activate">Tidak, tanpa kartu member</button>
            </div>
          </div>
          <div class="field mt-2" id="o_member_scope_field">
            <label>Cakupan Diskon Kartu Member <span class="muted small">(bisa diubah per transaksi)</span></label>
            <div class="status-picker member-picker" id="o_member_scope_picker">
              <?php $scopeNow = member_scope(); foreach (member_scope_options() as $sk => $slbl): ?>
                <label class="status-opt<?= $scopeNow === $sk ? ' active' : '' ?>">
                  <input type="radio" name="member_scope" value="<?= e($sk) ?>"<?= $scopeNow === $sk ? ' checked' : '' ?>>
                  <span class="st-name"><?= e($slbl) ?></span>
                  <span class="st-desc"><?php
                    echo $sk === 'both' ? 'Semua item yang dibeli ikut dihitung.'
                        : ('Hanya nilai ' . strtolower(str_replace(' saja', '', $slbl)) . ' yang dihitung.'); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <span class="hint">Cakupan diskon: <em>Treatment saja</em> = treatment + <strong>paket treatment</strong>,
              <em>Skincare saja</em> = skincare + <strong>paket produk</strong>, <em>keduanya</em> = semuanya.
              Bawaan mengikuti Pengaturan Sistem (saat ini <strong><?= e(member_scope_text()) ?></strong>);
              pilihan di sini hanya berlaku untuk transaksi ini.</span>
          </div>
          <div class="notice mt-2">
            <strong>Aturan aktif:</strong> <?= e(member_rules_text()) ?>.
            <div class="small muted mt-1">Pasien yang <strong>belum terdaftar</strong> sebagai pemegang kartu harus
              dikonfirmasi dulu (<em>Apakah Kartu Member mau diaktifkan?</em>) sebelum diskon berlaku — dengan
              begitu pasien baru tidak mendapat potongan tanpa sengaja.</div>
            <div class="small muted mt-1">
              Berlaku bila nilai transaksi ≥ <strong><?= money(member_min_transaction()) ?></strong>
              (dapat diubah di Pengaturan Sistem → Kartu Member).
            </div>
            <?php if ($pStatus): ?>
              <div class="small mt-1" id="o_member_hint">
                <?php if ($pIsMember): ?>
                  Level saat ini: <strong><?= e($pStatus['level']['label']) ?></strong> ·
                  akumulasi <?= e(member_period_label()) ?> <strong><?= money($pStatus['year_total']) ?></strong>
                  <?php if ($pStatus['next']): ?>
                    · <span class="muted"><?= money(max(0, $pStatus['next']['min_year'] - $pStatus['year_total'])) ?> lagi
                    menuju <?= e($pStatus['next']['label']) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="muted">Pasien ini belum punya kartu. Kartu terbuka bila transaksi ≥ <?= money(member_activate_amount()) ?>
                  atau akumulasi <?= e(member_period_label()) ?> mencapai <?= money($mLevels[0]['min_year']) ?>.</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>2. Item Treatment / Skincare</h3>
      <span class="muted">Item yang dijual ke pasien</span>
    </div>
    <div class="card-body">
      <?php /* Treatment & skincare berdampingan; kolom "Bahan Treatment" diletakkan
              di BARIS SENDIRI di bawahnya supaya tetap rapi di layar sempit. */ ?>
      <div class="form-grid g2">
        <div class="field"><label>Treatment</label>
          <div class="searchbox"><span><?= icon('search') ?></span>
            <input class="input" id="o_treatment_search" placeholder="Cari treatment (nama boleh sebagian)" autocomplete="off">
            <div class="suggest" id="o_treatment_suggest"></div>
          </div>
        </div>
        <div class="field"><label>Produk Skincare</label>
          <div class="searchbox"><span><?= icon('search') ?></span>
            <input class="input" id="o_skincare_search" placeholder="Cari produk skincare" autocomplete="off">
            <div class="suggest" id="o_skincare_suggest"></div>
          </div>
        </div>
        <div class="field" style="grid-column:1/-1"><label>Paket Treatment / Produk</label>
          <div class="searchbox"><span><?= icon('search') ?></span>
            <input class="input" id="o_package_search" placeholder="Cari paket (mis. Paket Glowing)" autocomplete="off">
            <div class="suggest" id="o_package_suggest"></div>
          </div>
          <span class="hint">Paket dihitung sebagai <strong>satu harga paket</strong>; saat transaksi disimpan,
            <strong>stok setiap isi paket ikut berkurang</strong> (dan dikembalikan bila transaksi dibatalkan).
            Paket dibuat di <a href="treatment.php#paket">Master Treatment</a> /
            <a href="skincare.php#paket">Master Skincare</a>.</span>
        </div>
      </div>
      <div class="table-wrap mt-2">
        <table class="tbl" id="o_items">
          <thead><tr><th>Item</th><th style="width:150px">Jumlah</th><th style="width:160px">Harga</th><th class="num" style="width:150px">Subtotal</th><th></th></tr></thead>
          <tbody id="o_items_body">
            <tr id="o_empty_row"><td colspan="5" class="muted center">Belum ada item. Gunakan pencarian di atas untuk menambahkan treatment atau skincare.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card" id="o_material_card">
    <div class="card-head">
      <h3>Bahan Treatment yang Digunakan</h3>
      <span class="muted">Opsional · tidak ditagihkan</span>
    </div>
    <div class="card-body">
      <div class="field">
        <label>Pilih Bahan Treatment</label>
        <div class="searchbox"><span><?= icon('search') ?></span>
          <input class="input" id="o_material_search" placeholder="Cari bahan (mis. masker, serum ampul, minyak)" autocomplete="off">
          <div class="suggest" id="o_material_suggest"></div>
        </div>
        <span class="hint">Bahan treatment <strong>tidak dijual</strong> ke pasien: tidak muncul di struk dan tidak
          menambah harga transaksi. Pencatatan ini hanya mengurangi <strong>stok inventory</strong> sebagai pemakaian.
          Jumlah dapat berupa pecahan sesuai satuan bahan (mis. <strong>0,5 liter</strong>).</span>
      </div>
      <div class="table-wrap mt-2">
        <table class="tbl" id="o_materials">
          <thead><tr>
            <th>Bahan</th><th style="width:190px">Jumlah Dipakai</th>
            <th style="width:150px" class="num">Sisa Stok</th><th style="width:90px"></th>
          </tr></thead>
          <tbody id="o_material_body">
            <tr id="o_mat_empty_row"><td colspan="4" class="muted center">Belum ada bahan yang dipakai. Bahan ini hanya
              pelengkap proses treatment — boleh dikosongkan.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="grid g2">
    <div class="card">
      <div class="card-head"><h3>3. Pembayaran</h3></div>
      <div class="card-body">
        <div class="form-grid g2">
          <div class="field"><label>Metode Pembayaran <span class="req">*</span></label>
            <select class="input" name="method" id="o_method">
              <?php foreach (PAY_METHODS as $m): ?><option value="<?= $m ?>"><?= $m ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label>No. Referensi / Keterangan Bank</label><input class="input" name="ref_no" id="o_ref_no" placeholder="opsional untuk transfer/QRIS"></div>
          <div class="field"><label>Diskon (Rp)</label><input class="input" type="number" name="discount" id="o_discount" value="0" min="0" step="500"></div>
          <div class="field"><label>Jumlah Dibayar (Rp)</label><input class="input" type="number" name="paid" id="o_paid" value="0" min="0" step="500"></div>
          <div class="field" style="grid-column:1/-1"><label>Catatan</label><input class="input" name="notes" placeholder="opsional"></div>
        </div>
        <div class="notice mt-2">Kembalian: <strong id="o_change">Rp 0</strong></div>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3>4. Ringkasan</h3></div>
      <div class="card-body">
        <dl class="kv">
          <dt>Subtotal</dt><dd><strong id="o_subtotal">Rp 0</strong></dd>
          <dt>Diskon</dt><dd><strong id="o_discount_view">Rp 0</strong></dd>
          <dt>Diskon Member <span class="hint-inline" id="o_member_label"></span></dt>
          <dd><strong id="o_member_view">Rp 0</strong></dd>
          <dt>Total</dt><dd class="stat" style="padding:8px 12px"><span class="val" id="o_total">Rp 0</span></dd>
          <dt>Jumlah Item</dt><dd><strong id="o_count">0</strong> item</dd>
          <dt>Bahan Treatment</dt><dd><strong id="o_matcount">0</strong> bahan
            <div class="small muted">Tidak ditagihkan · hanya mengurangi stok</div></dd>
        </dl>
        <?php /* Aturan "wajib bayar dulu": tombol ini membuka langkah Pembayaran
           (konfirmasi tunai / transfer / QRIS) dan transaksi baru dikirim setelah
           pembayaran dikonfirmasi — jadi tidak ada transaksi tersimpan tanpa bayar. */ ?>
        <div class="flex mt-3">
          <button class="btn btn-primary" type="button" id="oPayOpen"><?= icon('receipt') ?> Simpan &amp; Proses Pembayaran</button>
          <a class="btn" href="order_baru.php?branch_id=<?= $branchId ?>">Reset</a>
        </div>
        <div class="notice mt-2 small">Pembayaran dikonfirmasi lebih dulu; transaksi tersimpan setelah
          pembayaran diterima (tunai/transfer/QRIS).</div>
      </div>
    </div>
  </div>

<!-- ===== Langkah Pembayaran (wajib bayar dulu) ===== -->
<input type="hidden" name="unique_code" id="o_unique_code" value="">
<div class="modal" id="payModal">
  <div class="modal-box">
    <div class="modal-head"><h3>Langkah Pembayaran</h3>
      <button type="button" class="icon-btn" data-modal-close="payModal"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <?php /* RINCIAN YANG DIBELI (permintaan pemilik, ronde 48): pada kartu langkah
         pembayaran ditampilkan daftar treatment/skincare yang diambil beserta diskon
         member yang diberikan, supaya kasir & pasien sama-sama melihat rinciannya
         saat memindai QRIS. Isinya diisi skrip (pmRecap()) dari baris item yang
         sedang dipilih — jadi selalu sama dengan yang akan ditagihkan. */ ?>
      <div class="field" style="margin-bottom:12px">
        <label>Rincian yang dibeli</label>
        <div class="pm-recap" id="pmRecap"></div>
      </div>
      <dl class="kv">
        <dt>Subtotal</dt><dd><strong id="pmSubtotal">Rp 0</strong></dd>
        <dt>Diskon manual</dt><dd><span id="pmDiscount">Rp 0</span></dd>
        <dt>Diskon member</dt><dd><span id="pmMemberDisc">Rp 0</span> <span class="small muted" id="pmMemberNote"></span></dd>
        <dt>Total Tagihan</dt><dd><strong id="pmTotal">Rp 0</strong></dd>
        <dt>Kode Unik</dt><dd><strong id="pmCode">-</strong>
          <div class="small muted">Ditambahkan otomatis untuk transfer/QRIS (mudah dicocokkan dengan mutasi).</div></dd>
        <dt>Jumlah Harus Dibayar</dt><dd><strong id="pmPay">Rp 0</strong></dd>
      </dl>

      <div class="field mt-2"><label>Metode Pembayaran</label>
        <select class="input" id="pmMethod">
          <?php foreach (PAY_METHODS as $m): ?><option value="<?= $m ?>"><?= $m ?></option><?php endforeach; ?>
        </select>
        <span class="hint">Boleh diganti di sini — mis. pasien tidak jadi transfer lalu bayar tunai/QRIS.</span></div>

      <div id="pmCash" class="hide">
        <div class="field"><label>Uang Diterima (Rp)</label>
          <input class="input" type="number" id="pmCashIn" min="0" step="500" value="0">
          <span class="hint">Kembalian: <strong id="pmChange">Rp 0</strong></span></div>
      </div>

      <div id="pmInfo" class="hide">
        <?php /* Isi kotak ini rata TENGAH (teks & gambar QRIS) — permintaan pemilik. */ ?>
        <div class="notice pm-clinic" id="pmClinic"></div>
        <div class="field mt-2"><label>Jenis Pembayaran Transfer/QRIS</label>
          <select class="input" id="pmMode">
            <option value="manual">Manual — staf cek mutasi/QRIS lalu konfirmasi</option>
            <option value="auto"<?= pay_gateway_configured() ? '' : ' disabled' ?>>Otomatis lewat <?= e(pay_gateway_name()) ?><?= pay_gateway_configured() ? '' : ' (kunci API belum diisi)' ?></option>
          </select>
          <span class="hint" id="pmModeHint"><?= e(pay_gateway_status_text()) ?></span></div>
        <div class="field mt-2" id="pmRefWrap"><label>No. Referensi / Bukti Transfer</label>
          <input class="input" id="pmRef" placeholder="opsional"></div>
      </div>

      <div class="notice mt-2" id="pmStatus">Pilih metode lalu konfirmasi pembayaran.</div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn" data-modal-close="payModal">Batal</button>
      <button class="btn btn-primary" type="button" id="pmConfirm"><?= icon('check') ?> Ya, pembayaran diterima &amp; simpan</button>
    </div>
  </div>
</div>
</form>

<script>
var O_BRANCH = <?= (int)$branchId ?>;
/* ---- Data pembayaran (dipakai langkah "Pembayaran") ---- */
var O_PAY = {
  code: <?= (int)$payCodeQ ?>,
  uniqueCodeOn: <?= pay_unique_code_enabled() ? 'true' : 'false' ?>,
  gatewayOn: <?= pay_gateway_configured() ? 'true' : 'false' ?>,
  gatewayName: <?= js_json(pay_gateway_name()) ?>,
  clinic: <?= js_json(pay_clinic_info()) ?>,
  qrisReady: <?= $payInfoQ['qris_file'] !== '' ? 'true' : 'false' ?>
};
/* Tier diskon member dikirim dari server (sumber kebenaran tetap server). */
/* Item yang sudah terisi dari reservasi (tombol "→ Transaksi"). */
var O_PREFILL = <?= js_json($prefill) ?>;
var O_MEMBER = {
  enabled: <?= member_card_enabled() ? 'true' : 'false' ?>,
  activate: <?= (float)member_activate_amount() ?>,
  minTransaction: <?= (float)member_min_transaction() ?>,
  scopeDefault: <?= js_json(member_scope()) ?>,
  levels: <?= js_json(array_map(fn($l) => ['label' => $l['label'], 'pct' => (float)$l['pct'], 'min_year' => (float)$l['min_year']], member_levels())) ?>,
  patientYearTotal: <?= (float)($pStatus['year_total'] ?? 0) ?>,
  patientMember: <?= ($patient && patient_is_member($patient)) ? 'true' : 'false' ?>,
  patientName: <?= js_json($patient['name'] ?? '') ?>,
  /* Label pilihan pasien terakhir (dipakai mendeteksi perubahan nama pasien). */
  lastPickedLabel: <?= js_json($patient ? ((string)$patient['name'] . ' (' . (string)$patient['patient_number'] . ')') : '') ?>,
  /* Apakah nilai transaksi (diisi ulang oleh recalc) sudah memenuhi syarat
     kartu otomatis — hanya dipakai untuk menjelaskan di kotak konfirmasi. */
  wouldAuto: false
};
/* Level yang berlaku: akumulasi periode berjalan + (transaksi ini bila mencapai ambang aktivasi). */
function memberLevel(subtotal) {
  var base = O_MEMBER.levels && O_MEMBER.levels.length ? Number(O_MEMBER.levels[0].min_year) : 0;
  var look = Number(O_MEMBER.patientYearTotal || 0);
  if (O_MEMBER.activate > 0 && subtotal + 0.001 >= O_MEMBER.activate) look = Math.max(look, base);
  var hit = null;
  (O_MEMBER.levels || []).forEach(function (l) {
    if (look + 0.001 >= Number(l.min_year)) {
      if (!hit || Number(l.min_year) > Number(hit.min_year)) hit = l;
    }
  });
  return hit || (O_MEMBER.levels && O_MEMBER.levels[0]) || null;
}
/* Cakupan yang SEDANG dipilih petugas (radio di kotak kartu member). */
function memberScope() {
  var el = document.querySelector('input[name="member_scope"]:checked');
  return (el && el.value) ? el.value : (O_MEMBER.scopeDefault || 'both');
}
/* Nilai barang yang termasuk cakupan diskon (treatment / skincare / keduanya). */
function memberBase() {
  var scope = memberScope();
  var base = 0;
  document.querySelectorAll('#o_items_body tr').forEach(function (tr) {
    if (tr.id === 'o_empty_row') return;
    var t = tr.dataset.type || 'treatment';
    /* Aturan cakupan SAMA dengan server (includes/member.php):
       treatment saja  → treatment + paket treatment
       skincare saja   → skincare  + paket produk
       both            → keduanya + semua paket */
    var masuk = false;
    if (t === 'treatment') masuk = (scope === 'both' || scope === 'treatment');
    else if (t === 'skincare') masuk = (scope === 'both' || scope === 'skincare');
    else if (t === 'package') {
      if (scope === 'both') masuk = true;
      else {
        var produk = (tr.dataset.pkgKind === 'product' || tr.dataset.pkgKind === 'skincare');
        masuk = (scope === 'treatment') ? !produk : produk;
      }
    }
    if (!masuk) return;
    var q = numval((tr.querySelector('.o-qty') || {}).value);
    var p = numval((tr.querySelector('.o-price') || {}).value);
    base += q * p;
  });
  return base;
}
/* Kartu dianggap DIPAKAI hanya bila pasien sudah pemegang kartu, atau kasir
   sudah mengonfirmasi "Ya, aktifkan kartu" untuk pasien yang belum terdaftar.
   Tanpa konfirmasi, pilihan kartu TIDAK memberi diskon. */
function memberConfirmed() {
  var el = document.getElementById('o_member_activate');
  return !!(el && el.value === '1');
}
function memberUsing() {
  var el = document.getElementById('o_member_yes');
  if (!el || !el.checked) return false;
  return O_MEMBER.patientMember || memberConfirmed();
}
var O_IDX = 0;
var O_TYPE_LABEL = { treatment: 'Treatment', skincare: 'Skincare', material: 'Bahan Treatment', package: 'Paket' };
function fmt(n) { return Naveena.rupiah(n); }
/* Membaca angka yang diketik pengguna: menerima koma desimal ("0,5" liter). */
function numval(v) {
  var s = String(v == null ? '' : v).replace(/\s+/g, '').replace(/[^0-9.,-]/g, '');
  var dot = s.lastIndexOf('.'), com = s.lastIndexOf(',');
  if (dot >= 0 && com >= 0) {
    if (com > dot) { s = s.replace(/\./g, '').replace(',', '.'); }
    else { s = s.replace(/,/g, ''); }
  } else if (com >= 0) { s = s.replace(',', '.'); }
  var n = parseFloat(s);
  return isNaN(n) ? 0 : n;
}
/* Jumlah barang ditampilkan dengan desimal seperlunya (0,5 liter — bukan 0,50). */
function qtyText(n) {
  var v = Number(n) || 0;
  var s = (Math.abs(v - Math.round(v)) < 0.00001) ? v.toFixed(0) : v.toFixed(2).replace(/0$/, '');
  return s.replace('.', ',');
}
function emptyRow(tbody, id, text, cols) {
  if (document.getElementById(id)) return;
  var tr = document.createElement('tr');
  tr.id = id;
  tr.innerHTML = '<td colspan="' + cols + '" class="muted center">' + text + '</td>';
  tbody.appendChild(tr);
}
/* Kolom "Jumlah": isian + satuan yang SAMA dengan satuan bahan/produk di inventory. */
function qtyCell(i, unit, step) {
  return '<td><div class="qty-cell">' +
    '<input class="input input-sm o-qty" type="text" inputmode="decimal" autocomplete="off" value="1" ' +
      'name="items[' + i + '][qty]" aria-label="Jumlah">' +
    (unit ? '<span class="qty-unit">' + unit + '</span>' : '') +
    '</div>' +
    (step ? '<span class="hint qty-hint">' + step + '</span>' : '') +
    '</td>';
}
/* ---- Item yang dijual (treatment / skincare) ---- */
function addItem(type, id, name, code, price, stock, unit, components, kind) {
  var body = document.getElementById('o_items_body');
  var empty = document.getElementById('o_empty_row');
  if (empty) empty.remove();
  var i = O_IDX++;
  var tr = document.createElement('tr');
  tr.dataset.type = type;
  /* Jenis paket (treatment / produk) dipakai pratinjau diskon member supaya
     sama dengan perhitungan di server (cakupan "treatment saja" juga mencakup
     paket treatment, dst). */
  if (type === 'package') tr.dataset.pkgKind = (kind === undefined || kind === null) ? 'treatment' : kind;
  tr.dataset.stock = stock === undefined || stock === null ? '' : stock;
  tr.dataset.unit = unit || '';
  /* PAKET: tampilkan isi paketnya supaya kasir tahu apa yang diserahkan ke pasien. */
  var compTxt = '';
  if (type === 'package' && components && components.length) {
    compTxt = '<div class="small muted">Isi paket: ' + components.map(function (c) {
      return Naveena.angka(c.qty) + '× ' + c.name + (c.unit ? ' (' + c.unit + ')' : '');
    }).join(', ') + ' — stok ikut berkurang saat disimpan</div>';
  }
  tr.innerHTML =
    '<td><strong class="o-name">' + name + '</strong><div class="small muted">' + code + ' · ' + O_TYPE_LABEL[type] +
      (stock !== undefined && stock !== null ? ' · stok ' + Naveena.angka(stock) + (unit ? ' ' + unit : '') : '') + '</div>' +
      compTxt +
      '<input type="hidden" name="items[' + i + '][type]" value="' + type + '">' +
      '<input type="hidden" name="items[' + i + '][id]" value="' + id + '">' +
      '<input type="hidden" name="items[' + i + '][name]" value="' + name.replace(/"/g, '&quot;') + '">' +
      '<input type="hidden" name="items[' + i + '][code]" value="' + code + '">' +
    '</td>' +
    qtyCell(i, unit || '', '') +
    '<td><input class="input input-sm o-price" type="number" min="0" step="500" value="' + Number(price) + '" name="items[' + i + '][price]"></td>' +
    '<td class="num o-line">Rp 0</td>' +
    '<td><button type="button" class="btn btn-sm btn-danger o-del">Hapus</button></td>';
  body.appendChild(tr);
  tr.querySelector('.o-del').addEventListener('click', function () { tr.remove(); recalc(); });
  tr.querySelectorAll('input').forEach(function (el) { el.addEventListener('input', recalc); });
  recalc();
}
/* ---- Bahan treatment (area terpisah; tidak dijual, tidak menambah harga) ---- */
function addMaterial(id, name, code, stock, unit) {
  var body = document.getElementById('o_material_body');
  if (!body) return;
  var empty = document.getElementById('o_mat_empty_row');
  if (empty) empty.remove();
  var dup = false;
  body.querySelectorAll('tr').forEach(function (tr) {
    if (String(tr.dataset.id) === String(id)) dup = true;
  });
  if (dup) { recalc(); return; }
  var i = O_IDX++;
  var tr = document.createElement('tr');
  tr.dataset.type = 'material';
  tr.dataset.id = id;
  tr.dataset.stock = stock === undefined || stock === null ? '' : stock;
  tr.dataset.unit = unit || '';
  tr.innerHTML =
    '<td><strong>' + name + '</strong><div class="small muted">' + code + ' · Bahan Treatment' +
      ' · <span title="bahan treatment tidak dijual">tidak ditagihkan</span></div>' +
      '<input type="hidden" name="items[' + i + '][type]" value="material">' +
      '<input type="hidden" name="items[' + i + '][id]" value="' + id + '">' +
      '<input type="hidden" name="items[' + i + '][name]" value="' + name.replace(/"/g, '&quot;') + '">' +
      '<input type="hidden" name="items[' + i + '][code]" value="' + code + '">' +
    '</td>' +
    qtyCell(i, unit || '', 'boleh pecahan, mis. 0,5' + (unit ? ' ' + unit : '')) +
    '<td class="num o-stock"></td>' +
    '<td><button type="button" class="btn btn-sm btn-danger o-del">Hapus</button></td>';
  body.appendChild(tr);
  tr.querySelector('.o-del').addEventListener('click', function () { tr.remove(); refreshEmpty(); recalc(); });
  tr.querySelectorAll('input').forEach(function (el) { el.addEventListener('input', recalc); });
  recalc();
}
function refreshEmpty() {
  emptyRow(document.getElementById('o_items_body'), 'o_empty_row',
    'Belum ada item. Gunakan pencarian di atas untuk menambahkan treatment atau skincare.', 5);
  emptyRow(document.getElementById('o_material_body'), 'o_mat_empty_row',
    'Belum ada bahan yang dipakai. Bahan ini hanya pelengkap proses treatment — boleh dikosongkan.', 4);
}
/* ================= KARTU MEMBER: SATU SUMBER KEBENARAN =================
   Fungsi-fungsi ini berada di SKUP ATAS (bukan di dalam DOMContentLoaded)
   karena `recalc()` — yang juga di skup atas — memanggil syncMember() untuk
   menggambar penanda status. Sebelumnya ditempatkan di dalam DOMContentLoaded
   sehingga muncul galat "syncMember is not defined" dan penanda status tidak
   pernah ikut diperbarui saat diskon dihitung. */
  function memberPatientSelected() {
    var el = document.getElementById('o_patient_id');
    return !!(el && Number(el.value) > 0);
  }
  function memberYesChecked() {
    var el = document.getElementById('o_member_yes');
    return !!(el && el.checked);
  }
  /** Pilihan ketiga: pemegang kartu tanpa memakai diskon. */
  function memberSkipChecked() {
    var el = document.getElementById('o_member_skip');
    return !!(el && el.checked);
  }
  /**
   * Atur pilihan kartu: `yes` = pakai kartu, `skip` = pemegang kartu tetapi
   * diskon tidak dipakai (pilihan ketiga). Bila keduanya false → "Tidak ada kartu".
   */
  function memberSetRadio(yes, skip) {
    var y = document.getElementById('o_member_yes');
    var n = document.getElementById('o_member_no');
    var k = document.getElementById('o_member_skip');
    if (!y || !n || !k) return;
    y.checked = !!yes;
    k.checked = !yes && !!skip;
    n.checked = !yes && !skip;
    document.getElementById('o_mem_yes_opt').classList.toggle('active', !!yes);
    document.getElementById('o_mem_skip_opt').classList.toggle('active', !yes && !!skip);
    document.getElementById('o_mem_no_opt').classList.toggle('active', !yes && !skip);
  }
  /** Apakah pasien memakai kartu (Ya) — dipakai juga oleh recalc(). */
  function memberYesOn() {
    var y = document.getElementById('o_member_yes');
    return !!(y && y.checked);
  }
  /** Atur ulang seluruh tampilan kotak member sesuai keadaan saat ini. */
  function syncMember() {
    var box = document.getElementById('o_member_box');
    if (!box) return;
    var picked = memberPatientSelected();
    var isMember = picked && !!O_MEMBER.patientMember;
    var yes = memberYesChecked();
    /* Butuh konfirmasi aktivasi HANYA bila: pasien terpilih, BUKAN pemegang kartu,
       memilih "ya kartu", dan belum dikonfirmasi. */
    var needConfirm = picked && yes && !isMember && !memberConfirmed();

    /* 1. Tanpa pasien terpilih: kotak tidak dapat dipakai (harus ada nama dulu). */
    box.classList.toggle('locked', !picked);
    document.querySelectorAll('input[name="member_card"]').forEach(function (r) { r.disabled = !picked; });

    /* 1b. Pilihan menurut keadaan pasien (permintaan pemilik):
       - PASIEN PEMEGANG KARTU → hanya pilihan pertama dan pilihan ketiga
         (pemegang kartu yang tidak memakai diskon); pilihan tengah dinonaktifkan
         karena tidak sesuai keadaan.
       - PASIEN TANPA KARTU → pilihan tengah aktif, pilihan ketiga disembunyikan.
       CATATAN: teks label TIDAK ditulis di komentar ini — blok skrip selalu ikut
       terkirim walau fitur member dimatikan, dan uji memeriksa label itu tidak
       muncul sama sekali pada keadaan tersebut. */
    var optNo = document.getElementById('o_mem_no_opt');
    var optSkip = document.getElementById('o_mem_skip_opt');
    var skipRadio = document.getElementById('o_member_skip');
    var noRadio = document.getElementById('o_member_no');
    if (optNo && optSkip && skipRadio && noRadio) {
      if (picked && isMember) {
        noRadio.disabled = true;
        optNo.classList.add('disabled');
        optSkip.classList.remove('hide');
        skipRadio.disabled = false;
        /* Bila yang tercentang justru "Tidak ada kartu", pindahkan ke pilihan
           yang benar (Tanpa Kartu Member) supaya keadaan tidak mustahil. */
        if (noRadio.checked) memberSetRadio(false, true);
      } else {
        noRadio.disabled = !picked;
        optNo.classList.remove('disabled');
        if (skipRadio.checked) memberSetRadio(false, false);
        optSkip.classList.add('hide');
        skipRadio.disabled = true;
      }
    }

    /* 2. Kotak konfirmasi aktivasi. */
    var ask = document.getElementById('o_member_ask');
    if (ask) {
      var sub = document.getElementById('o_member_ask_sub');
      if (sub && needConfirm) {
        sub.innerHTML = 'Kartu member belum aktif untuk <strong>' + (O_MEMBER.patientName || 'pasien ini') + '</strong>.'
          + (O_MEMBER.wouldAuto
              ? ' Nilai transaksi ini sudah memenuhi syarat kartu otomatis (' + fmt(O_MEMBER.activate) + ').'
              : ' Kartu dapat diaktifkan sekarang atas persetujuan pasien.');
      }
      ask.classList.toggle('hide', !needConfirm);
    }

    /* 3. Pemilih cakupan diskon: tampil bila kartu DIPAKAI (pemegang kartu atau
       sudah dikonfirmasi). Pilihan radio tetap aktif agar bisa diubah, tetapi
       hanya dihitung bila kartu benar-benar dipakai. */
    var scopeBox = document.getElementById('o_member_scope_field');
    if (scopeBox) {
      var scopeOn = picked && yes && (isMember || memberConfirmed());
      scopeBox.classList.toggle('hide', !scopeOn);
      scopeBox.querySelectorAll('input[type=radio]').forEach(function (r) { r.disabled = !scopeOn; });
    }

    /* 4. Penanda status di kepala kotak — SATU tempat untuk seluruh keadaan. */
    var st = document.getElementById('o_member_state');
    if (st) {
      var ld = O_MEMBER.lastDisc || null;
      if (!picked) {
        st.className = 'badge badge-gray';
        st.textContent = 'pilih pasien dulu';
      } else if (!yes) {
        st.className = 'badge badge-gray';
        st.textContent = 'tanpa kartu member';
      } else if (needConfirm) {
        st.className = 'badge badge-yellow';
        st.textContent = 'menunggu konfirmasi aktivasi';
      } else if (ld && ld.using) {
        if (ld.ok) {
          st.className = 'badge badge-green';
          st.textContent = 'diskon ' + qtyText(ld.pct) + '% aktif' + (ld.level ? ' (' + ld.level + ')' : '');
        } else if (ld.base <= 0) {
          var sl = { both: 'Treatment & Skincare', treatment: 'Treatment', skincare: 'Skincare' }[ld.scope] || 'barang';
          st.className = 'badge badge-yellow';
          st.textContent = 'tidak ada barang ' + sl + ' pada transaksi ini';
        } else {
          st.className = 'badge badge-yellow';
          st.textContent = 'nilai belum mencapai ' + fmt(O_MEMBER.minTransaction);
        }
      } else if (isMember) {
        var lv = memberLevel(0);
        st.className = 'badge badge-blue';
        st.textContent = 'pemegang kartu' + (lv ? ' · ' + lv.label : '') + ' — tambahkan item untuk diskon';
      } else {
        st.className = 'badge badge-blue';
        st.textContent = 'kartu akan diaktifkan pada transaksi ini';
      }
    }

    /* 5. Keterangan akumulasi pasien terpilih (bukan pasien lain). */
    var hint = document.getElementById('o_member_hint');
    if (hint) {
      if (!picked) {
        hint.innerHTML = 'Pilih pasien terlebih dahulu — kotak kartu member akan aktif setelah nama pasien dipilih. '
          + 'Pencarian pasien juga menerima nomor member, NIK, dan nomor telepon.';
      } else if (isMember) {
        var lv2 = memberLevel(0);
        hint.innerHTML = '<strong>' + (O_MEMBER.patientName || 'Pasien ini') + '</strong> adalah pemegang kartu member'
          + (lv2 ? ' (level ' + lv2.label + ')' : '')
          + '. Akumulasi ' + <?= js_json(member_period_label()) ?> + ' <strong>' + fmt(O_MEMBER.patientYearTotal)
          + '</strong> — potongan dihitung otomatis saat item ditambahkan (minimal transaksi '
          + fmt(O_MEMBER.minTransaction) + ').';
      } else {
        hint.innerHTML = '<strong>' + (O_MEMBER.patientName || 'Pasien ini') + '</strong> belum memiliki kartu member. '
          + 'Kartu dapat diaktifkan sekarang (tombol konfirmasi) atau otomatis bila nilai transaksi ≥ '
          + fmt(O_MEMBER.activate) + '.';
      }
    }
    O_MEMBER.wouldAuto = false;
  }


function recalc() {
  var subtotal = 0, count = 0, mats = 0;
  document.querySelectorAll('#o_items_body tr').forEach(function (tr) {
    if (tr.id === 'o_empty_row') return;
    var q = numval((tr.querySelector('.o-qty') || {}).value);
    var p = numval((tr.querySelector('.o-price') || {}).value);
    if (q > 0) count++;
    var line = q * p;
    subtotal += line;
    var cell = tr.querySelector('.o-line');
    if (cell) cell.textContent = fmt(line);
    var stock = tr.dataset.stock;
    tr.classList.toggle('o-over', stock !== '' && stock !== undefined && q - numval(stock) > 0.00001);
    var hint = tr.querySelector('.qty-hint');
    if (hint && stock !== '' && stock !== undefined) {
      hint.textContent = 'Tersedia ' + qtyText(stock) + (tr.dataset.unit ? ' ' + tr.dataset.unit : '');
    }
  });
  document.querySelectorAll('#o_material_body tr').forEach(function (tr) {
    if (tr.id === 'o_mat_empty_row') return;
    var q = numval((tr.querySelector('.o-qty') || {}).value);
    var stock = tr.dataset.stock;
    var unit = tr.dataset.unit || '';
    var over = stock !== '' && stock !== undefined && q - numval(stock) > 0.00001;
    if (q > 0) mats++;
    tr.classList.toggle('o-over', over);
    var sc = tr.querySelector('.o-stock');
    if (sc) {
      sc.innerText = '';
      if (stock !== '' && stock !== undefined) {
        var sisa = numval(stock) - q;
        sc.appendChild(document.createTextNode(qtyText(sisa) + (unit ? ' ' + unit : '')));
        var note = document.createElement('div');
        note.className = 'small ' + (over ? 'o-stock-warn' : 'muted');
        note.textContent = over ? 'melebihi stok ' + qtyText(stock) + (unit ? ' ' + unit : '')
                                : 'dari ' + qtyText(stock) + (unit ? ' ' + unit : '');
        sc.appendChild(note);
      }
    }
    var hint = tr.querySelector('.qty-hint');
    if (hint) {
      hint.textContent = over
        ? 'Melebihi stok ' + qtyText(stock) + (unit ? ' ' + unit : '')
        : 'Tersedia ' + qtyText(stock) + (unit ? ' ' + unit : '');
    }
  });
  var disc = numval(document.getElementById('o_discount').value);
  /* Nilai transaksi terbaru dipakai untuk menjelaskan syarat kartu otomatis.
     syncMember() dipanggil di AKHIR fungsi ini untuk menggambar penanda status
     (aman dari rekursi: syncMember() tidak pernah memanggil recalc()). */
  if (O_MEMBER.enabled) {
    O_MEMBER.wouldAuto = O_MEMBER.activate > 0 && subtotal + 0.001 >= O_MEMBER.activate;
  }
  /* Diskon member: level + cakupan barang + nilai minimum transaksi. */
  var mBase = memberBase();
  var tier = (memberUsing() && O_MEMBER.enabled) ? memberLevel(subtotal) : null;
  var memOk = !!tier && mBase > 0 && (O_MEMBER.minTransaction <= 0 || subtotal + 0.001 >= O_MEMBER.minTransaction);
  var memPct = memOk ? Number(tier.pct) : 0;
  var memDisc = memOk ? Math.round(mBase * memPct) / 100 : 0;
  if (memDisc > subtotal - disc) memDisc = Math.max(0, subtotal - disc);
  var total = Math.max(0, subtotal - disc - memDisc);
  document.getElementById('o_subtotal').textContent = fmt(subtotal);
  document.getElementById('o_discount_view').textContent = fmt(disc);
  var mv = document.getElementById('o_member_view');
  if (mv) mv.textContent = fmt(memDisc);
  var ml = document.getElementById('o_member_label');
  if (ml) {
    ml.textContent = memOk
      ? '(' + (tier.label || 'Member') + ' · ' + qtyText(memPct) + '% dari ' + fmt(mBase) + ')'
      : (memberUsing() && tier ? '(' + (tier.label || 'Member') + ' · ' + qtyText(Number(tier.pct)) + '%)' : '');
  }
  /* Keadaan diskon disimpan supaya penanda status di kepala kotak kartu member
     digambar oleh SATU tempat saja (syncMember) — dulu blok ini dan syncMember
     sama-sama menulis penanda yang sama sehingga saling menimpa. */
  if (O_MEMBER.enabled) {
    O_MEMBER.lastDisc = { using: memberUsing(), ok: memOk, pct: memPct, base: mBase,
      scope: memberScope(), level: tier ? (tier.label || 'Member') : '' };
    syncMember();
  }
  document.getElementById('o_total').textContent = fmt(total);
  document.getElementById('o_count').textContent = count;
  var mc = document.getElementById('o_matcount');
  if (mc) mc.textContent = mats;
  var paid = numval(document.getElementById('o_paid').value);
  document.getElementById('o_change').textContent = fmt(Math.max(0, paid - total));
  if (!document.getElementById('o_paid').dataset.touched) document.getElementById('o_paid').value = Math.round(total);
}
/* ================= Langkah PEMBAYARAN (wajib bayar dulu) =================
   Alur: tombol "Simpan & Proses Pembayaran" → modal konfirmasi pembayaran →
   metode boleh diganti (mis. batal transfer, ganti tunai/QRIS) → setelah kasir
   menekan "Ya, pembayaran diterima" barulah form dikirim (transaksi dibuat).
   Untuk QRIS/Transfer mode OTOMATIS, tagihan dibuat di gateway dan transaksi
   baru tersimpan setelah gateway menyatakan lunas (halaman bayar.php). */
(function () {
  var modal = document.getElementById('payModal');
  if (!modal) return;
  var form = document.getElementById('orderForm');
  var el = function (id) { return document.getElementById(id); };
  var code = 0;

  function subTotal() {
    var t = 0;
    document.querySelectorAll('#o_items_body tr').forEach(function (tr) {
      if (tr.id === 'o_empty_row') return;
      var q = numval((tr.querySelector('.o-qty') || {}).value);
      var p = numval((tr.querySelector('.o-price') || {}).value);
      t += q * p;
    });
    return t;
  }
  /* Total akhir mengikuti perhitungan server (subtotal - diskon - diskon member). */
  function totalNow() {
    var raw = el('o_total').textContent.replace(/[^0-9]/g, '');
    return Number(raw || 0);
  }
  function isCash(m) { return m === 'Cash'; }
  /* RINCIAN ITEM pada kartu pembayaran (ronde 48) — dibaca dari baris item yang
     sedang diisi di halaman, jadi apa yang dilihat pasien = apa yang ditagihkan. */
  function pmRecap() {
    var kotak = el('pmRecap');
    if (!kotak) return;
    var baris = [];
    document.querySelectorAll('#o_items_body tr').forEach(function (tr) {
      if (tr.id === 'o_empty_row') return;
      var q = numval((tr.querySelector('.o-qty') || {}).value);
      var p = numval((tr.querySelector('.o-price') || {}).value);
      var nm = itemName(tr);
      if (q <= 0 || nm.trim() === '') return;
      baris.push({ nama: nm.trim(), qty: q, harga: p, total: q * p, unit: tr.dataset.unit || '' });
    });
    if (!baris.length) {
      kotak.innerHTML = '<span class="muted">Belum ada item yang dipilih.</span>';
      return;
    }
    var html = '<table class="pm-recap-tbl"><tbody>';
    baris.forEach(function (b) {
      html += '<tr><td>' + esc(b.nama) + '<div class="small muted">' + qtyText(b.qty)
        + (b.unit ? ' ' + esc(b.unit) : '') + ' × ' + fmt(b.harga) + '</div></td>'
        + '<td class="num nowrap">' + fmt(b.total) + '</td></tr>';
    });
    html += '</tbody></table>';
    /* Angka diskon diambil dari perhitungan recalc() (sumber yang sama dengan tagihan). */
    var memo = [];
    if (Number(el('o_subtotal') ? el('o_subtotal').textContent.replace(/[^0-9]/g, '') : 0) > 0
        && O_MEMBER.lastDisc && O_MEMBER.lastDisc.ok && O_MEMBER.lastDisc.pct > 0) {
      memo.push('Member ' + esc(O_MEMBER.lastDisc.level || '') + ' · ' + qtyText(O_MEMBER.lastDisc.pct) + '%');
    }
    if (memo.length) html += '<div class="small muted">' + memo.join(' · ') + '</div>';
    kotak.innerHTML = html;
  }
  /* Nama item yang sedang dipilih: dibaca dari elemen `.o-name`, dengan cadangan
     dari kolom tersembunyi `items[i][name]`. Cadangan ini WAJIB ada — perbaikan
     permintaan pemilik (rincian di langkah pembayaran kosong): dulu hanya
     `.o-name` yang dibaca, sedangkan baris item tidak memakai kelas itu sehingga
     daftar selalu dianggap kosong ("Belum ada item yang dipilih"). */
  function itemName(tr) {
    var n = tr.querySelector('.o-name');
    if (n && String(n.textContent || '').trim() !== '') return String(n.textContent).trim();
    var h = tr.querySelector('input[type=hidden][name$="[name]"]');
    return h && h.value ? String(h.value) : '';
  }
  /* Escapce teks dari DOM (nama item) sebelum dimasukkan kembali sebagai HTML. */
  function esc(t) {
    var d = document.createElement('div');
    d.textContent = t == null ? '' : String(t);
    return d.innerHTML;
  }
  function refreshPay() {
    var m = el('pmMethod').value;
    var total = totalNow();
    /* Kode unik berasal dari SERVER (O_PAY.code) agar yang ditampilkan sama
       dengan yang tersimpan; 0 untuk tunai. */
    code = (!isCash(m) && O_PAY.uniqueCodeOn) ? (O_PAY.code || 0) : 0;
    if (el('o_unique_code')) el('o_unique_code').value = code;
    var payAmount = total + code;
    el('pmTotal').textContent = fmt(total);
    el('pmCode').textContent = code > 0 ? code : 'tanpa kode unik';
    el('pmPay').textContent = fmt(payAmount);
    /* Rincian item + diskon pada kartu pembayaran. */
    var sub = numval((el('o_subtotal') || {}).textContent ? el('o_subtotal').textContent.replace(/[^0-9]/g, '') : 0);
    var discManual = numval((el('o_discount') || {}).value);
    var discMember = numval((el('o_member_view') || {}).textContent ? el('o_member_view').textContent.replace(/[^0-9]/g, '') : 0);
    if (el('pmSubtotal')) el('pmSubtotal').textContent = fmt(sub);
    if (el('pmDiscount')) el('pmDiscount').textContent = fmt(discManual);
    if (el('pmMemberDisc')) el('pmMemberDisc').textContent = fmt(discMember);
    if (el('pmMemberNote')) {
      var ket = (el('o_member_label') || {}).textContent || '';
      el('pmMemberNote').textContent = discMember > 0 ? ket : (O_MEMBER.lastDisc && O_MEMBER.lastDisc.using
        ? 'kartu member dipakai, tetapi belum memenuhi syarat diskon' : '');
    }
    pmRecap();
    el('pmCash').classList.toggle('hide', !isCash(m));
    el('pmInfo').classList.toggle('hide', isCash(m));
    el('pmRefWrap').classList.toggle('hide', isCash(m));
    if (isCash(m)) {
      var inEl = el('pmCashIn');
      if (!inEl.dataset.touched) inEl.value = Math.round(payAmount);
      el('pmChange').textContent = fmt(Math.max(0, numval(inEl.value) - payAmount));
    } else {
      /* Jelaskan rekening/QRIS klinik apa adanya.
         PERMINTAAN PEMILIK (ronde 49): keterangan cara membayar HARUS mengikuti
         metode yang benar-benar dipilih — memilih Transfer tidak boleh
         memunculkan gambar QRIS; gambar QRIS hanya muncul saat metode = QRIS.
         Isi kotak pink juga dibuat rata TENGAH (teks maupun gambarnya). */
      var c = O_PAY.clinic || {};
      var isQris = (m === 'QRIS');
      var isTransfer = (m === 'Transfer');
      var html = '<strong>' + (isQris ? 'QRIS' : (isTransfer ? 'Transfer' : esc(m))) + ' ke klinik</strong>'
        + '<div class="small mt-1">';
      if (isTransfer && c.bank_name && c.bank_account) {
        html += 'Bank <strong>' + c.bank_name + '</strong> · No. Rek <strong>' + c.bank_account + '</strong>'
             + (c.bank_holder ? ' · a.n. ' + c.bank_holder : '') + '<br>';
      }
      if (isQris) {
        /* Gambar QRIS ditampilkan LANGSUNG dan HANYA pada metode QRIS supaya
           pasien dapat memindainya saat itu juga. */
        if (O_PAY.qrisReady && c.qris_url) {
          html += '<div class="mt-1"><img src="' + c.qris_url + '" alt="QRIS klinik" '
               + 'style="max-width:220px;border:1px solid var(--line);border-radius:10px;background:#fff;padding:6px">'
               + '<div class="small muted">Pindai QRIS ini untuk membayar (nominal di bawah).</div></div>';
        } else if (O_PAY.qrisReady) {
          /* QRIS sudah diunggah tetapi berkasnya tidak terbaca dari sisi server. */
          html += 'Gambar QRIS sudah diunggah, tetapi berkasnya belum dapat dibaca — '
               + 'unggah ulang di Pengaturan → Pembayaran.';
        } else {
          html += '<em>Gambar QRIS belum diunggah di Pengaturan → Pembayaran.</em><br>';
        }
      } else if (!isTransfer) {
        html += '<em>Pembayaran dengan ' + esc(m) + ' dicatat apa adanya (tanpa kode unik).</em><br>';
      } else if (!c.bank_name || !c.bank_account) {
        html += '<em>Nomor rekening belum diisi di Pengaturan → Pembayaran.</em><br>';
      }
      html += 'Nominal yang harus dibayar <strong>' + fmt(payAmount) + '</strong>'
            + (code > 0 ? ' (termasuk kode unik <strong>' + code + '</strong>)' : '') + '.</div>';
      if (c.note) html += '<div class="small mt-1">' + c.note + '</div>';
      if (isQris && O_PAY.gatewayOn) html += '<div class="small mt-1">Mode otomatis: tagihan QRIS dibuat di '
        + O_PAY.gatewayName + ' dan transaksi tersimpan otomatis setelah dinyatakan lunas.</div>';
      el('pmClinic').innerHTML = html;
    }
  }
  el('oPayOpen').addEventListener('click', function () {
    if (!el('o_patient_id').value) { alert('Pilih pasien terlebih dahulu.'); return; }
    /* Bahan treatment juga dihitung sebagai "ada isi" supaya pesan penolakan
       "bahan saja tidak cukup" tetap datang dari SERVER (bukan diblokir diam-diam
       di layar). Hanya form yang benar-benar kosong yang dicegah di sini. */
    var items = document.querySelectorAll('#o_items_body tr:not(#o_empty_row), #o_material_body tr:not(#o_mat_empty_row)').length;
    if (!items) { alert('Tambahkan minimal satu item treatment/skincare terlebih dahulu.'); return; }
    el('pmMethod').value = el('o_method').value;
    code = 0;
    refreshPay();
    el('pmStatus').className = 'notice mt-2';
    el('pmStatus').textContent = 'Konfirmasi metode pembayaran, lalu tekan tombol di bawah.';
    Naveena.openModal('payModal');
  });
  el('pmMethod').addEventListener('change', refreshPay);
  el('pmMode').addEventListener('change', function () {
    el('pmConfirm').textContent = (this.value === 'auto')
      ? 'Buat QRIS Otomatis & tunggu pelunasan' : 'Ya, pembayaran diterima & simpan';
  });
  el('pmCashIn').addEventListener('input', function () { this.dataset.touched = '1'; refreshPay(); });

  el('pmConfirm').addEventListener('click', async function () {
    var btn = this;
    var m = el('pmMethod').value;
    var mode = isCash(m) ? 'manual' : el('pmMode').value;
    var st = el('pmStatus');
    /* Kembalikan pilihan metode ke form utama supaya server memakai metode final. */
    el('o_method').value = m;
    if (isCash(m)) {
      var jumlah = numval(el('pmCashIn').value);
      if (jumlah + 0.01 < totalNow()) {
        st.className = 'alert alert-error mt-2';
        st.textContent = 'Uang diterima kurang dari total tagihan.';
        return;
      }
      el('o_paid').value = jumlah;
    } else {
      el('o_paid').value = totalNow();   // transfer/QRIS: dibayar penuh sesuai nominal
      if (el('o_ref_no') && el('pmRef')) el('o_ref_no').value = el('pmRef').value;
    }
    if (mode === 'auto') {
      btn.disabled = true;
      st.className = 'notice mt-2';
      st.textContent = 'Membuat tagihan di ' + O_PAY.gatewayName + '...';
      try {
        var fd = new URLSearchParams();
        fd.set('_csrf', form.querySelector('input[name=_csrf]').value);
        fd.set('action', 'pay_auto');
        fd.set('payload', JSON.stringify(payloadNow(m)));
        var res = await fetch('order_baru.php?branch_id=' + O_BRANCH, { method: 'POST', body: fd, credentials: 'same-origin' });
        var d = await res.json();
        if (!d.ok) {
          st.className = 'alert alert-error mt-2';
          st.textContent = 'Gagal membuat tagihan otomatis: ' + (d.error || 'tidak diketahui');
          btn.disabled = false;
          return;
        }
        st.className = 'alert alert-success mt-2';
        st.innerHTML = 'Tagihan dibuat (' + d.gateway + ' · ' + fmt(d.amount) + ', kode unik ' + d.unique_code + '). '
          + 'Membuka halaman pembayaran...';
        window.location.href = d.pay_url;
        return;
      } catch (e) {
        st.className = 'alert alert-error mt-2';
        st.textContent = 'Gagal menghubungi server: ' + e.message;
        btn.disabled = false;
        return;
      }
    }
    /* Tunai / transfer manual: pembayaran sudah diterima kasir → kirim form. */
    var hid = document.getElementById('o_paid_confirm');
    if (!hid) {
      hid = document.createElement('input');
      hid.type = 'hidden'; hid.name = 'paid_confirm'; hid.id = 'o_paid_confirm';
      form.appendChild(hid);
    }
    hid.value = '1';
    st.className = 'alert alert-success mt-2';
    st.textContent = 'Pembayaran dikonfirmasi — menyimpan transaksi...';
    btn.disabled = true;
    form.querySelector('button[type=submit]') ? form.submit() : form.submit();
  });

  /* Muatan transaksi untuk jalur otomatis (sama bentuknya dengan POST form). */
  function payloadNow(method) {
    var items = [];
    document.querySelectorAll('#o_items_body tr').forEach(function (tr) {
      if (tr.id === 'o_empty_row') return;
      var g = function (n) { var el2 = tr.querySelector('[name$="[' + n + ']"]'); return el2 ? el2.value : ''; };
      items.push({
        type: tr.dataset.type, id: g('id'), name: g('name'), code: g('code'),
        qty: (tr.querySelector('.o-qty') || {}).value || 1,
        price: (tr.querySelector('.o-price') || {}).value || 0
      });
    });
    document.querySelectorAll('#o_material_body tr').forEach(function (tr) {
      if (tr.id === 'o_mat_empty_row' || !tr.dataset.mid) return;
      items.push({ type: 'material', id: tr.dataset.mid, name: tr.dataset.mname || '', code: tr.dataset.mcode || '',
        qty: (tr.querySelector('.o-qty') || {}).value || 0, price: 0 });
    });
    return {
      patient_id: el('o_patient_id').value,
      items: items,
      method: method,
      discount: el('o_discount').value,
      member_card: (el('o_member_yes') || {}).checked ? '1' : '0',
      member_activate: el('o_member_activate') ? el('o_member_activate').value : '0',
      member_scope: (document.querySelector('input[name=member_scope]:checked') || {}).value || 'both',
      notes: (document.querySelector('input[name=notes]') || {}).value || '',
      appointment_id: (document.querySelector('input[name=appointment_id]') || {}).value || 0,
      unique_code: code,
      ref_no: el('pmRef') ? el('pmRef').value : ''
    };
  }
})();

document.addEventListener('DOMContentLoaded', function () {
  /* Item dari reservasi dimasukkan sebagai baris item BIASA sehingga bisa
     diubah jumlah/harganya, dihapus, atau ditambah item lain. */
  (O_PREFILL || []).forEach(function (it) { addItem(it.type, it.id, it.name, it.code, it.price, it.stock, it.unit); });
  Naveena.suggest({ input: '#o_patient_search', box: '#o_patient_suggest', action: 'patient', params: { branch: O_BRANCH },
    onPick: function (it) {
      /* ===== PASIEN DARI CABANG LAIN → PINDAH KE ORDER BARU CABANG PASIEN =====
         Super Admin & Direktur/Owner dapat melihat pasien semua cabang. Bila
         pasien yang dipilih terdaftar di cabang lain, halaman ini (yang sedang
         memuat daftar treatment/stok cabang lain) TIDAK boleh menagihnya:
         transaksinya harus di cabang pasien. Karena itu halaman dimuat ulang ke
         cabang pasien — daftar treatment & stoknya ikut menyesuaikan. Bila sudah
         ada item di keranjang, ditanyakan lebih dulu supaya tidak hilang
         begitu saja. */
      if (it.branch_id && Number(it.branch_id) !== Number(O_BRANCH)) {
        var appt = (document.querySelector('input[name=appointment_id]') || {}).value || 0;
        var adaItem = document.querySelectorAll('#o_items_body tr').length > 0;
        var url = 'order_baru.php?branch_id=' + encodeURIComponent(it.branch_id)
          + '&patient_id=' + encodeURIComponent(it.id) + (Number(appt) > 0 ? '&appointment_id=' + appt : '');
        if (!adaItem || window.confirm('Pasien ini terdaftar di cabang ' + (it.branch_name || 'lain')
          + '.\n\nOrder Baru akan dibuka di cabang tersebut supaya transaksinya sesuai cabang pasien.'
          + '\n\nItem yang sudah Anda tambahkan akan dikosongkan. Lanjutkan?')) {
          window.location.href = url;
        }
        return;
      }
      document.getElementById('o_patient_id').value = it.id;
      document.getElementById('o_patient_search').value = it.name + ' (' + it.number + ')';
      var box = document.getElementById('o_patient_info');
      box.classList.remove('hide');
      box.innerHTML = 'Member <strong>' + (it.member || '-') + '</strong> · ' + (it.phone || 'tanpa telepon') + ' · Status ' + it.type
        + (it.is_member ? ' · <strong style="color:var(--ok)">pemegang kartu member</strong>' : '');
      /* ===== Kartu member mengikuti PASIEN YANG BARU DIPILIH =====
         Sebelumnya blok ini hanya memindahkan radio Ya/Tidak tanpa memperbarui
         data pasien di O_MEMBER, sehingga: pemilih cakupan diskon tidak muncul,
         pesan "belum terdaftar" tetap tampil untuk pemegang kartu, dan diskon
         tidak pernah dihitung. Sekarang seluruh keadaan disinkronkan. */
      if (O_MEMBER.enabled) {
        O_MEMBER.patientMember = !!it.is_member;
        O_MEMBER.patientName = it.name || '';
        O_MEMBER.patientYearTotal = Number(it.year_total || 0);
        /* Pemegang kartu: otomatis "Ya" (diskon langsung dihitung, tanpa perlu
           konfirmasi aktivasi). Belum punya kartu: dikembalikan ke "Tidak ada
           kartu" dan konfirmasi aktivasi dibatalkan. */
        document.getElementById('o_member_activate').value = it.is_member ? '1' : '0';
        memberSetRadio(!!it.is_member, false);
        if (it.is_member) O_MEMBER.lastPickedLabel = it.name + ' (' + it.number + ')';
        syncMember();
      }
      recalc();
    } });
  /* Mengubah teks nama pasien setelah memilih → pilihan pasien DIKEMBALIKAN kosong.
     Alasannya: kalau nama diganti, pasien yang tampil di kotak member bukan lagi
     pasien yang akan ditagih — lebih aman mengosongkan hingga pasien dipilih lagi
     (termasuk mengunci kembali kotak kartu member). */
  var patSearch = document.getElementById('o_patient_search');
  if (patSearch) {
    patSearch.addEventListener('input', function () {
      var pid = document.getElementById('o_patient_id');
      if (!pid || Number(pid.value) === 0) return;
      if (patSearch.value === (O_MEMBER.lastPickedLabel || '')) return;
      pid.value = '0';
      O_MEMBER.patientMember = false;
      O_MEMBER.patientName = '';
      O_MEMBER.patientYearTotal = 0;
      document.getElementById('o_member_activate').value = '0';
      if (O_MEMBER.enabled) { memberSetRadio(false, false); syncMember(); }
      var box = document.getElementById('o_patient_info');
      if (box) box.classList.add('hide');
      recalc();
    });
  }
  Naveena.suggest({ input: '#o_treatment_search', box: '#o_treatment_suggest', action: 'treatment', params: { branch: O_BRANCH },
    onPick: function (it) {
      addItem('treatment', it.id, it.name, it.code, it.price);
      document.getElementById('o_treatment_search').value = '';
    } });
  Naveena.suggest({ input: '#o_skincare_search', box: '#o_skincare_suggest', action: 'skincare', params: { branch: O_BRANCH },
    onPick: function (it) {
      addItem('skincare', it.id, it.name, it.code, it.price, it.stock, it.unit);
      document.getElementById('o_skincare_search').value = '';
    } });
  /* PAKET treatment/produk: satu harga, isi paket dipotong stoknya saat disimpan. */
  Naveena.suggest({ input: '#o_package_search', box: '#o_package_suggest', action: 'package', params: { branch: O_BRANCH },
    onPick: function (it) {
      addItem('package', it.id, it.name, it.code, it.price, null, '', it.components || [], it.kind || 'treatment');
      document.getElementById('o_package_search').value = '';
    } });
  /* Bahan treatment: area sendiri, harga selalu 0 (tidak dijual ke pasien). */
  Naveena.suggest({ input: '#o_material_search', box: '#o_material_suggest', action: 'material', params: { branch: O_BRANCH },
    onPick: function (it) {
      addMaterial(it.id, it.name, it.code, it.stock, it.unit);
      document.getElementById('o_material_search').value = '';
    } });
  /* Pemilih CAKUPAN diskon hanya relevan bila petugas memilih memakai kartu
     (catatan: teks komentar ini sengaja TIDAK memuat label pilihan, karena uji
     "fitur member nonaktif" memeriksa label itu tidak muncul di halaman). */
  /* ================= KARTU MEMBER: SATU SUMBER KEBENARAN =================
     Seluruh keadaan kotak kartu member (pilihan Ya/Tidak, kotak konfirmasi
     aktivasi, pemilih cakupan diskon, dan status) diatur oleh SATU fungsi
     `syncMember()`. Sebelumnya tiap penangan hanya memperbarui sebagian keadaan,
     sehingga muncul rangkaian bug yang dilaporkan pemilik klinik:
       • memilih pasien pemegang kartu → pemilih cakupan TIDAK muncul dan diskon
         tidak berjalan (O_MEMBER.patientMember tidak pernah diperbarui);
       • menekan "Tidak, tanpa kartu member" → pilihan kembali ke "tidak ada kartu"
         tetapi pemilih cakupan tetap terlihat;
       • menekan "Ya, aktifkan kartu" → kotak konfirmasi hilang, pemilih cakupan
         tetap tidak muncul sehingga terasa "tidak berfungsi";
       • pesan "belum terdaftar sebagai pemegang Kartu Member" tetap muncul untuk
         pasien yang SUDAH punya kartu;
       • kotak kartu member bisa diklik sebelum ada pasien terpilih.
     */
  /* Tombol "Ya, aktifkan kartu & beri diskon". */
  var oBtnYes = document.getElementById('o_member_yes_activate');
  if (oBtnYes) oBtnYes.addEventListener('click', function () {
    document.getElementById('o_member_activate').value = '1';
    memberSetRadio(true);
    syncMember();
    recalc();
  });
  /* Tombol "Tidak, tanpa kartu member" → kembali ke opsi tanpa kartu DAN
     pemilih cakupan ikut disembunyikan (dulu tetap terlihat). */
  var oBtnNo = document.getElementById('o_member_no_activate');
  if (oBtnNo) oBtnNo.addEventListener('click', function () {
    document.getElementById('o_member_activate').value = '0';
    /* Pemegang kartu yang membatalkan aktivasi tetap PUNYA kartu → pilih
       "Tanpa Kartu Member" (bukan "Tidak ada kartu"). */
    memberSetRadio(false, !!O_MEMBER.patientMember);
    syncMember();
    recalc();
  });
  /* Pilihan kartu member: gaya aktif + hitung ulang diskon + seluruh tampilan. */
  ['o_member_yes', 'o_member_no', 'o_member_skip'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('change', function () {
      /* Memilih "Tidak ada kartu" ATAU "Tanpa Kartu Member" membatalkan konfirmasi
         aktivasi yang mungkin sudah diberikan, supaya tidak ada diskon "tersisa". */
      if (document.getElementById('o_member_no').checked || document.getElementById('o_member_skip').checked) {
        document.getElementById('o_member_activate').value = '0';
      }
      /* Gaya kartu terpilih diselaraskan dari keadaan sebenarnya. */
      var y = document.getElementById('o_member_yes').checked;
      var k = document.getElementById('o_member_skip').checked;
      document.getElementById('o_mem_yes_opt').classList.toggle('active', y);
      document.getElementById('o_mem_skip_opt').classList.toggle('active', k);
      document.getElementById('o_mem_no_opt').classList.toggle('active', !y && !k);
      syncMember();
      recalc();
    });
  });
  /* Ganti cakupan → hitung ulang (diskon mengikuti cakupan yang dipilih). */
  document.querySelectorAll('input[name="member_scope"]').forEach(function (el) {
    el.addEventListener('change', function () {
      document.querySelectorAll('#o_member_scope_picker .status-opt').forEach(function (o) {
        var r = o.querySelector('input');
        o.classList.toggle('active', !!(r && r.checked));
      });
      recalc();
    });
  });
  if (O_MEMBER.enabled) syncMember();
  ['o_discount', 'o_paid'].forEach(function (id) {
    var el = document.getElementById(id);
    el.addEventListener('input', function () { if (id === 'o_paid') el.dataset.touched = '1'; recalc(); });
  });
  document.getElementById('o_method').addEventListener('change', function () {
    if (this.value !== 'Cash') { document.getElementById('o_paid').value = 0; }
    recalc();
  });
  recalc();
});
</script>
<?php page_foot(); ?>
