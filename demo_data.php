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
        'Transaksi' => '3–5 transaksi per hari dari awal bulan ' . DEMO_MONTHS . ' bulan lalu SAMPAI HARI INI, tiap cabang — sebagian berupa <strong>penjualan paket</strong>',
        'Riwayat stok' => 'Pergerakan stok dari penjualan & pemakaian bahan (termasuk restok)',
        'Audit log' => 'Jejak tindakan pengisian data demo',
    ];
}

/** Rentang tanggal transaksi demo yang akan diisi (relatif HARI INI). */
function demo_range_text(): string
{
    $mulai = (new DateTimeImmutable('first day of this month'))
        ->modify('-' . (DEMO_MONTHS - 1) . ' months');
    return tglIndo($mulai->format('Y-m-d')) . ' s.d. ' . tglIndo(date('Y-m-d')) . ' (hari ini)';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
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
                'Data demo diisi (2 cabang, 30 pasien, 1 bulan transaksi)');
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
      <strong>Cakupan transaksi: <?= e(demo_range_text()) ?>.</strong> Data diisi <strong>sampai hari ini</strong>,
      jadi bulan berjalan selalu ada isinya. Tombol ini bersifat <strong>melengkapi (top up)</strong>: hari yang
      sudah punya transaksi di cabang itu dilewati, sehingga menekannya lagi bulan depan akan mengisi bulan baru
      yang masih kosong <strong>tanpa menggandakan</strong> data lama.
    </div>
  </div>
</div>

<div class="card" style="border-color:#F5C9C6">
  <div class="card-head"><h3>Konfirmasi &amp; Jalankan</h3>
    <span><?= badge('Wajib password', 'yellow') ?></span></div>
  <form method="post"
        data-heavy-confirm="ISI DATA DEMO"
        data-heavy-warning="Sistem akan membuat <strong>2 cabang (bila belum ada), 30 pasien, rekam medis, reservasi, dan 1 bulan transaksi tiap cabang</strong> beserta master treatment/skincare/bahan.<br><br>
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
<?php page_foot(); ?>
