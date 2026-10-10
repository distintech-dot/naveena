<?php
/**
 * ISI DATA DEMO — khusus Super Admin.
 *
 * Mengisi aplikasi dengan data contoh lengkap (2 cabang, 30 pasien, master
 * treatment/skincare/bahan, rekam medis, reservasi, dan 1 bulan transaksi tiap
 * cabang) supaya semua menu/laporan/grafik langsung terlihat hidup — mis. saat
 * aplikasi ini diduplikasi untuk klinik lain.
 *
 * Pengaman: hanya Super Admin, password dikonfirmasi ulang, snapshot database
 * dibuat otomatis lebih dulu, konfirmasi dua tahap, dan hasilnya dicatat di
 * Audit Log. Data demo dapat dihapus kembali lewat "Hapus Semua Data".
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/backup_lib.php';
require_once __DIR__ . '/includes/demo_data.php';
require_once __DIR__ . '/includes/layout.php';
$user = require_login();
if (!is_super()) deny('Hanya Super Admin yang boleh mengisi data demo.');

/** Rencana data yang akan dibuat (untuk pratinjau). */
function demo_plan(): array
{
    return [
        'Cabang (dibuat bila belum ada)' => '2 cabang',
        'Dokter' => '2 per cabang',
        'Terapis' => '3 per cabang',
        'Supplier' => '3 nama supplier',
        'Master treatment' => '12 treatment per cabang — <strong>termasuk kolom HPP</strong> (perkiraan 35% harga normal)',
        'Master skincare' => '10 produk per cabang + stok awal (kolom <strong>HPP / harga beli</strong> ikut diisi)',
        'Bahan treatment' => '8 bahan per cabang + stok awal',
        'Paket treatment & produk' => '4 paket per cabang (2 paket treatment + 2 paket produk) berisi item di atas, HPP dihitung dari komponennya',
        'Biaya operasional (Keuangan)' => 'Gaji, listrik & air, marketing, aplikasi, pajak, operasional lainnya (bulanan) + sewa bangunan (tahunan) — <strong>nominalnya ikut diisi</strong>',
        'Data pasien' => '30 pasien (15 per cabang)',
        'Kartu member' => '8 pasien (4 per cabang)',
        'Rekam medis' => '1–2 catatan per pasien (kode ICD dari kamus resmi)',
        'Reservasi' => '10 per cabang (sebagian multi-treatment)',
        'Transaksi' => '3–5 transaksi per hari SAMPAI HARI INI (3 bulan terakhir), tiap cabang — sebagian berupa <strong>penjualan paket</strong>',
        'Riwayat stok' => 'Pergerakan stok dari penjualan & pemakaian bahan (termasuk restok)',
        'Audit log' => 'Jejak tindakan pengisian data demo',
    ];
}

/** Rentang tanggal transaksi demo yang akan diisi (relatif HARI INI). */
function demo_range_text(): string
{
    $r = demo_date_range();
    return tglIndo($r['mulai']) . ' s.d. ' . tglIndo($r['akhir']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    /* HAPUS PER BATCH DEMO (ronde 54): pratinjau dulu, snapshot pengaman, lalu hapus
       dengan urutan dependency yang aman + pemeriksaan FK. */
    if ($act === 'hapus_batch') {
        if (!is_super()) deny('Hanya Super Admin.');
        $batch = trim((string)($_POST['batch_id'] ?? ''));
        if ($batch === '') throw new RuntimeException('Batch demo tidak disebutkan.');
        $pratinjau = demo_batch_preview($batch);
        if (!$pratinjau) throw new RuntimeException('Batch itu tidak memiliki data demo lagi.');
        $snap = backup_create('Snapshot sebelum hapus data demo ' . $batch, (int)$user['id'],
            ['prefix' => 'sebelum-hapus-demo-', 'enforce' => false]);
        if (!$snap['ok']) throw new RuntimeException('Snapshot pengaman gagal dibuat: ' . $snap['error']);
        $res = demo_batch_delete($batch);
        if ($res['fk'] > 0) {
            throw new RuntimeException('Penghapusan menyisakan ' . $res['fk']
                . ' pelanggaran relasi — periksa data. Snapshot pengaman: ' . $snap['file']);
        }
        audit('Hapus Data Demo (batch)', 'Data Demo', null, null,
            ['batch' => $batch, 'terhapus' => $res['total'], 'snapshot' => $snap['file']],
            'Data demo dihapus per batch beserta pemeriksaan relasi');
        flash('Data demo batch ' . $batch . ' dihapus: ' . num((int)$res['total']) . ' record. '
            . 'Snapshot pengaman: ' . $snap['file'] . '.', 'success');
        header('Location: demo_data.php');
        exit;
    }
    try {
        if ($act === 'seed') {
            $pass = (string)($_POST['password'] ?? '');
            if (!password_verify($pass, (string)$user['password_hash'])) {
                throw new RuntimeException('Password Super Admin tidak sesuai. Data demo tidak dibuat.');
            }
            $snap = backup_create('Snapshot otomatis sebelum Isi Data Demo', $user['id'],
                ['prefix' => 'sebelum-demo-', 'enforce' => false]);
            if (!$snap['ok']) {
                throw new RuntimeException('Gagal membuat snapshot pengaman sebelum mengisi data demo ('
                    . $snap['error'] . '). Pengisian dibatalkan.');
            }
            $before = demo_operational_counts();
            $res = demo_seed((int)$user['id']);
            audit('Isi Data Demo', 'Pengaturan', null, $before,
                ['hasil' => $res, 'snapshot' => $snap['file']],
                'Data demo diisi (2 cabang, 30 pasien, transaksi ' . demo_range_text() . ')');
            /* Akhiri dengan pergi ke dashboard supaya pengguna langsung melihat
               aplikasi yang sudah berisi data. */
            $rinci = [];
            if ($res['pasien'] > 0) $rinci[] = num($res['pasien']) . ' pasien baru';
            if ($res['rekam_medis'] > 0) $rinci[] = num($res['rekam_medis']) . ' rekam medis baru';
            if ($res['reservasi'] > 0) $rinci[] = num($res['reservasi']) . ' reservasi baru';
            if ($res['transaksi'] > 0) $rinci[] = num($res['transaksi']) . ' transaksi baru ('
                . num((int)$res['hari_terisi']) . ' hari terisi)';
            if ($res['treatment'] > 0) $rinci[] = num($res['treatment']) . ' master treatment';
            if ($res['skincare'] > 0) $rinci[] = num($res['skincare']) . ' produk skincare';
            if ($res['bahan'] > 0) $rinci[] = num($res['bahan']) . ' bahan treatment';
            if ((int)$res['restok'] > 0) $rinci[] = num((int)$res['restok']) . ' restok stok';
            /* Fitur baru (menu Keuangan & paket) juga dilaporkan apa adanya. */
            if ((int)($res['hpp_treatment'] ?? 0) > 0) $rinci[] = num((int)$res['hpp_treatment']) . ' treatment diberi HPP';
            if ((int)($res['hpp_produk'] ?? 0) > 0) $rinci[] = num((int)$res['hpp_produk']) . ' produk diberi HPP';
            if ((int)($res['paket'] ?? 0) > 0) $rinci[] = num((int)$res['paket']) . ' paket treatment/produk';
            if ((int)($res['biaya_operasional'] ?? 0) > 0) $rinci[] = num((int)$res['biaya_operasional']) . ' pos biaya operasional';
            flash('Data demo selesai diproses: ' . ($rinci ? implode(', ', $rinci) : 'tidak ada yang perlu ditambah — semua data demo sudah lengkap')
                . '. Cakupan transaksi: ' . demo_range_text() . '. '
                . ((int)($res['hari_dilewati'] ?? 0) > 0
                    ? num((int)$res['hari_dilewati']) . ' hari dilewati karena sudah ada transaksi (tidak digandakan). ' : '')
                . 'Snapshot pengaman sebelum pengisian: ' . $snap['file'] . '.');
            header('Location: dashboard.php');
            exit;
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
        header('Location: demo_data.php');
        exit;
    }
}

$counts = demo_operational_counts();
$hasData = array_sum($counts) > 0;
$plan = demo_plan();

page_head('Isi Data Demo', '');
?>
<?php
/* ==========================================================================
 * BATCH DATA DEMO (ronde 54) — pelacakan & penghapusan per batch.
 * Setiap pengisian data demo menghasilkan satu `demo_batch_id`; seluruh record
 * (pasien, rekam medis, reservasi, transaksi, item, pembayaran, pergerakan stok)
 * ditandai batch itu sehingga dapat diringkas dan dihapus per batch dengan urutan
 * dependency yang aman, snapshot pengaman, dan pemeriksaan relasi.
 * ======================================================================== */
$daftarBatch = function_exists('demo_batches_list') ? demo_batches_list() : [];
?>
<div class="page-head">
  <div>
    <h2><?= icon('database') ?> Isi Data Demo</h2>
    <p class="muted">Khusus Super Admin · mengisi aplikasi dengan data contoh yang siap dihapus kembali.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="developer.php#datademo"><?= icon('settings') ?> Pengaturan Sistem</a>
    <a class="btn" href="purge.php?menu=semua"><?= icon('trash') ?> Hapus Semua Data</a>
  </div>
</div>

<?php if ($hasData): ?>
<div class="alert alert-warning">
  <strong>Database sudah berisi data.</strong> Data demo akan <strong>DITAMBAHKAN</strong> di atas data yang ada
  (tidak menghapus apa pun) — nomor pasien/invoice akan melanjutkan nomor terakhir.
  Bila tujuan Anda adalah memulai dari kondisi bersih, jalankan
  <a href="purge.php?menu=semua"><strong>Hapus Semua Data</strong></a> lebih dulu.
  <div class="small mt-1">Saat ini: <?= num($counts['patients']) ?> pasien ·
    <?= num($counts['medical_records']) ?> rekam medis · <?= num($counts['orders']) ?> transaksi ·
    <?= num((int)scalar('SELECT COUNT(*) FROM packages')) ?> paket ·
    <?= num((int)scalar('SELECT COUNT(*) FROM finance_cost_amounts WHERE amount > 0')) ?> pos biaya operasional terisi ·
    <?= num($counts['treatments']) ?> treatment · <?= num($counts['skincare']) ?> skincare ·
    <?= num($counts['materials']) ?> bahan.</div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3>Rencana Data Demo</h3>
    <span><?= badge('Data contoh', 'pink') ?></span></div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Bagian</th><th>Yang akan dibuat</th></tr></thead>
        <tbody>
          <?php foreach ($plan as $k => $v): ?>
            <tr><td><?= e($k) ?></td><td class="small"><?= e($v) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="notice mt-2">
      Transaksi demo dibuat melalui <strong>jalur yang sama dengan kasir</strong> (harga, stok, diskon member,
      dan nomor invoice dihitung server), jadi laporan, Top 5, dan grafik yang muncul adalah hasil perhitungan
      sungguhan dari data tersebut. Data demo juga <strong>mengurangi stok</strong> seperti transaksi asli dan
      tercatat di pergerakan stok.
      <br><br>
      <strong>Cakupan transaksi: <?= e(demo_range_text()) ?> (SAMPAI HARI INI).</strong> Rentangnya
      dihitung dari tanggal hari ini, jadi menekan tombol ini lagi bulan depan akan mengisi bulan
      baru yang masih kosong.
      Tombol ini bersifat <strong>melengkapi (top up)</strong>: hari yang sudah punya transaksi di cabang itu
      dilewati, sehingga pengisian ulang hanya melengkapi hari yang masih kosong <strong>tanpa menggandakan</strong>
      data lama.
    </div>
  </div>
</div>

<div class="card" style="border-color:#F5C9C6">
  <div class="card-head"><h3>Konfirmasi &amp; Jalankan</h3>
    <span><?= badge('Wajib password', 'yellow') ?></span></div>
  <form method="post"
        data-heavy-confirm="ISI DATA DEMO"
        data-heavy-warning="Sistem akan membuat <strong>2 cabang (bila belum ada), 30 pasien, rekam medis, reservasi, dan transaksi tiap cabang
        <span class="nowrap">3 bulan terakhir SAMPAI HARI INI (<?= e(demo_range_text()) ?>)</span></strong> beserta master treatment/skincare/bahan.<br><br>
          Data contoh ini <strong>ditambahkan</strong> ke database (data lama tidak dihapus) dan dapat dibersihkan kembali lewat tombol Hapus Semua Data. Snapshot pengaman dibuat otomatis lebih dulu."
        data-heavy-confirm2="PERINGATAN KEDUA (terakhir): data demo akan dibuat sekarang. Proses ini dapat berjalan beberapa detik. Lanjutkan?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="seed">
    <div class="card-body">
      <div class="form-grid g2">
        <div class="field"><label>Email Super Admin</label>
          <input class="input" value="<?= e($user['email']) ?>" disabled></div>
        <div class="field"><label>Password Super Admin <span class="req">*</span></label>
          <input class="input" type="password" name="password" required autocomplete="current-password">
          <span class="hint">Dipakai untuk memastikan hanya Super Admin yang menjalankan pengisian data.</span></div>
      </div>
      <div class="notice mt-2">Setelah selesai Anda diarahkan ke Dashboard. Untuk mengosongkan kembali,
        gunakan <a href="purge.php?menu=semua">Hapus Semua Data</a> (manajemen user &amp; Pengaturan tetap utuh).</div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <a class="btn" href="developer.php#datademo">Batalkan</a>
      <button class="btn btn-primary" type="submit">Isi Data Demo Sekarang (2x konfirmasi)</button>
    </div>
  </form>
</div>
<div class="card" id="batchdemo">
  <div class="card-head">
    <h3>Batch Data Demo</h3>
    <span class="muted"><?= num(count($daftarBatch)) ?> batch terakhir</span>
  </div>
  <div class="card-body">
    <div class="notice small">
      Data demo ditandai <code>demo_batch_id</code> sehingga <strong>dapat dilacak</strong> dan
      <strong>dihapus per batch</strong> — hanya data demo yang terhapus, data klinik yang asli
      tidak tersentuh. Sebelum menghapus, sistem membuat <strong>snapshot pengaman</strong> dan
      setelahnya memeriksa relasi (FK) agar tidak ada data menggantung.
    </div>
    <?php if (!$daftarBatch): ?>
      <p class="muted small mt-2">Belum ada batch data demo. Batch dibuat otomatis saat Anda
        menekan <em>Isi Data Demo</em>.</p>
    <?php endif; ?>
    <?php foreach ($daftarBatch as $b): ?>
      <div class="notice small mt-2">
        <div class="flex gap-sm flex-wrap" style="align-items:center">
          <strong><code><?= e((string)$b['batch_id']) ?></code></strong>
          <?= badge((string)$b['status'] === 'ACTIVE' ? 'ada' : 'sudah dihapus',
              (string)$b['status'] === 'ACTIVE' ? 'green' : 'gray') ?>
          <span class="muted">dibuat <?= e(tgl((string)$b['created_at'], true)) ?>
            · <?= num((int)$b['total']) ?> record</span>
        </div>
        <?php if (!$b['rincian']): ?>
          <?php /* Batch ada tetapi recordnya sudah tidak ada (mis. sudah dihapus lewat
                   "Hapus Semua Data" atau tombol hapus batch sebelumnya) — dinyatakan
                   apa adanya supaya pemilik tidak mengira tombolnya hilang. */ ?>
          <div class="small muted mt-1">Tidak ada record demo tersisa pada batch ini
            (sudah terhapus). Tidak ada yang perlu dibersihkan.</div>
        <?php endif; ?>
        <?php if ($b['rincian']): ?>
          <div class="mt-1 small muted">
            <?php foreach ($b['rincian'] as $x): ?>
              <span class="nowrap"><?= e((string)$x['label']) ?> <strong><?= num((int)$x['jumlah']) ?></strong></span>
              &nbsp;·&nbsp;
            <?php endforeach; ?>
          </div>
          <form method="post" class="mt-2" data-heavy-confirm="HAPUS DATA DEMO"
                data-heavy-warning="Sistem akan membuat snapshot pengaman lalu MENGHAPUS seluruh record batch ini (<?= num((int)$b['total']) ?> record) beserta relasinya. Data klinik yang asli tidak terhapus."
                data-heavy-confirm2="PERINGATAN KEDUA (terakhir): hapus batch data demo ini?">
            <?= csrf_field() ?><input type="hidden" name="action" value="hapus_batch">
            <input type="hidden" name="batch_id" value="<?= e((string)$b['batch_id']) ?>">
            <div class="field" style="max-width:320px"><label>Kata sandi Super Admin</label>
              <input class="input input-sm" type="password" name="password" required></div>
            <button class="btn btn-danger btn-sm mt-1" type="submit">
              <?= icon('x') ?> Hapus Batch Ini (<?= num((int)$b['total']) ?> record)</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php

?>
<div class="page-head">
  <div>

<?php page_foot(); ?>
