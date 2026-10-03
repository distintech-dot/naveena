<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/chart_js.php';
/* backup_schedule_label() — untuk menampilkan hasil backup otomatis. */
require_once __DIR__ . '/includes/backup_lib.php';
require_perm('dashboard.view');

$user   = current_user();
$today  = date('Y-m-d');
$month  = date('Y-m');
$period = gp('period', 'month');
[$ps, $pe] = resolve_period($period, gp('start'), gp('end'));
$scope  = scope_branch();
$scopeLbl = $scope === null ? 'Semua Cabang' : (string)scalar('SELECT name FROM branches WHERE id = ?', [$scope], '-');

$bo = bscope('o.branch_id');
$ba = bscope('a.branch_id');

/* ---------------- KPI ---------------- */
$revToday = (float)scalar("SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.status='paid' AND date(o.created_at)=? {$bo[0]}", array_merge([$today], $bo[1]));
$revMonth = (float)scalar("SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.status='paid' AND strftime('%Y-%m',o.created_at)=? {$bo[0]}", array_merge([$month], $bo[1]));
$trxToday = (int)scalar("SELECT COUNT(*) FROM orders o WHERE o.status='paid' AND date(o.created_at)=? {$bo[0]}", array_merge([$today], $bo[1]));
$trxMonth = (int)scalar("SELECT COUNT(*) FROM orders o WHERE o.status='paid' AND strftime('%Y-%m',o.created_at)=? {$bo[0]}", array_merge([$month], $bo[1]));
$visitToday = (int)scalar("SELECT COUNT(DISTINCT o.patient_id) FROM orders o WHERE o.status='paid' AND date(o.created_at)=? {$bo[0]}", array_merge([$today], $bo[1]));
$resToday = (int)scalar("SELECT COUNT(*) FROM appointments a WHERE a.date=? {$ba[0]}", array_merge([$today], $ba[1]));

$kpiTreatment = one("SELECT COALESCE(SUM(oi.subtotal),0) s, COALESCE(SUM(oi.quantity),0) q
    FROM order_items oi JOIN orders o ON o.id=oi.order_id
    WHERE o.status='paid' AND oi.item_type='treatment' AND date(o.created_at)=? {$bo[0]}", array_merge([$today], $bo[1]));
$kpiTreatmentM = one("SELECT COALESCE(SUM(oi.subtotal),0) s, COALESCE(SUM(oi.quantity),0) q
    FROM order_items oi JOIN orders o ON o.id=oi.order_id
    WHERE o.status='paid' AND oi.item_type='treatment' AND strftime('%Y-%m',o.created_at)=? {$bo[0]}", array_merge([$month], $bo[1]));
$kpiSkincare = one("SELECT COALESCE(SUM(oi.subtotal),0) s, COALESCE(SUM(oi.quantity),0) q
    FROM order_items oi JOIN orders o ON o.id=oi.order_id
    WHERE o.status='paid' AND oi.item_type='skincare' AND date(o.created_at)=? {$bo[0]}", array_merge([$today], $bo[1]));
$kpiSkincareM = one("SELECT COALESCE(SUM(oi.subtotal),0) s, COALESCE(SUM(oi.quantity),0) q
    FROM order_items oi JOIN orders o ON o.id=oi.order_id
    WHERE o.status='paid' AND oi.item_type='skincare' AND strftime('%Y-%m',o.created_at)=? {$bo[0]}", array_merge([$month], $bo[1]));

/* ---------------- Per-branch comparison (Super Admin) ---------------- */
$perBranch = [];
if (is_owner_level()) {
    foreach (branches() as $b) {
        $perBranch[] = [
            'name' => $b['name'],
            'code' => $b['code'],
            'today' => (float)scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid' AND branch_id=? AND date(created_at)=?", [$b['id'], $today]),
            'month' => (float)scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid' AND branch_id=? AND strftime('%Y-%m',created_at)=?", [$b['id'], $month]),
            'trx'   => (int)scalar("SELECT COUNT(*) FROM orders WHERE status='paid' AND branch_id=? AND strftime('%Y-%m',created_at)=?", [$b['id'], $month]),
            'tr'    => (float)scalar("SELECT COALESCE(SUM(oi.subtotal),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status='paid' AND oi.item_type='treatment' AND o.branch_id=? AND strftime('%Y-%m',o.created_at)=?", [$b['id'], $month]),
            'sk'    => (float)scalar("SELECT COALESCE(SUM(oi.subtotal),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status='paid' AND oi.item_type='skincare' AND o.branch_id=? AND strftime('%Y-%m',o.created_at)=?", [$b['id'], $month]),
        ];
    }
}

/* ---------------- Trend series for selected period ---------------- */
$days = (int)floor((strtotime($pe) - strtotime($ps)) / 86400) + 1;
$byMonth = $days > 92;
$fmt = $byMonth ? "strftime('%Y-%m',o.created_at)" : "date(o.created_at)";

$rowsA = all("SELECT {$fmt} AS d, COUNT(*) c, COALESCE(SUM(o.total),0) t FROM orders o
              WHERE o.status='paid' AND date(o.created_at) BETWEEN ? AND ? {$bo[0]}
              GROUP BY d ORDER BY d", array_merge([$ps, $pe], $bo[1]));
$rowsB = all("SELECT {$fmt} AS d, oi.item_type, COALESCE(SUM(oi.subtotal),0) s, COALESCE(SUM(oi.quantity),0) q
              FROM order_items oi JOIN orders o ON o.id=oi.order_id
              WHERE o.status='paid' AND date(o.created_at) BETWEEN ? AND ? {$bo[0]}
              GROUP BY d, oi.item_type ORDER BY d", array_merge([$ps, $pe], $bo[1]));

$agg = [];
foreach ($rowsA as $r) { $agg[$r['d']] = ['tr' => 0, 'sk' => 0, 'total' => (float)$r['t'], 'trx' => (int)$r['c']]; }
foreach ($rowsB as $r) {
    if (!isset($agg[$r['d']])) $agg[$r['d']] = ['tr' => 0, 'sk' => 0, 'total' => 0, 'trx' => 0];
    if ($r['item_type'] === 'treatment') $agg[$r['d']]['tr'] = (float)$r['s'];
    if ($r['item_type'] === 'skincare')  $agg[$r['d']]['sk'] = (float)$r['s'];
}
/* Fill empty buckets so the chart has a continuous axis */
$cursor = strtotime($byMonth ? date('Y-m-01', strtotime($ps)) : $ps);
$labels = []; $sTr = []; $sSk = []; $sTot = [];
$guard = 0;
while ($cursor <= strtotime($pe) && $guard++ < 400) {
    $k = $byMonth ? date('Y-m', $cursor) : date('Y-m-d', $cursor);
    $labels[] = $byMonth ? tgl($k . '-01') : date('d/m', $cursor);
    $sTr[]  = $agg[$k]['tr'] ?? 0;
    $sSk[]  = $agg[$k]['sk'] ?? 0;
    $sTot[] = $agg[$k]['total'] ?? 0;
    $cursor = strtotime($byMonth ? '+1 month' : '+1 day', $cursor);
}
$periodTotals = [
    'total' => array_sum($sTot),
    'tr'    => array_sum($sTr),
    'sk'    => array_sum($sSk),
    'trx'   => array_sum(array_column($agg, 'trx')),
];

/* ---------------- RINGKASAN KEUANGAN (HPP & laba) ----------------
   HANYA untuk level owner (Super Admin & Direktur): HPP dan laba bersih
   bersifat internal perusahaan. Level lain TIDAK melihat kartunya sama sekali
   (dan nilainya tidak dihitung) — lihat also includes/finance.php. */
$fin = null;
$finSeries = null;
if (has_perm('finance.view') && is_owner_level()) {
    $finFilter = [
        'scope' => $scope, 'period' => $period, 'ps' => $ps, 'pe' => $pe,
        'status' => 'paid', 'params' => [],
    ];
    $fw = ['o.status = ?', 'date(o.created_at) BETWEEN ? AND ?'];
    $fp = ['paid', $ps, $pe];
    if ($scope !== null) { $fw[] = 'o.branch_id = ?'; $fp[] = $scope; }
    $finFilter['sql'] = implode(' AND ', $fw);
    $finFilter['params'] = $fp;
    $fin = finance_summary($finFilter, finance_mode() === 'lengkap', $scope);
    $finSeries = finance_series($finFilter);
}

/* ---------------- Top 5 in period ---------------- */
function top_items(string $type, string $ps, string $pe, array $b): array
{
    $cat = $type === 'skincare' ? 'p.category' : 't.category';
    $join = $type === 'skincare' ? 'LEFT JOIN skincare_products p ON p.id = oi.skincare_id' : 'LEFT JOIN treatments t ON t.id = oi.treatment_id';
    return all("SELECT oi.item_name AS name, COALESCE({$cat},'-') AS category,
                       COALESCE(SUM(oi.quantity),0) q, COALESCE(SUM(oi.subtotal),0) s
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                {$join}
                WHERE o.status='paid' AND oi.item_type = ? AND date(o.created_at) BETWEEN ? AND ? {$b[0]}
                GROUP BY oi.item_name ORDER BY s DESC LIMIT 5",
        array_merge([$type, $ps, $pe], $b[1]));
}
$topSk = top_items('skincare', $ps, $pe, $bo);
$topTr = top_items('treatment', $ps, $pe, $bo);

/* ---------------- Recent & alerts ---------------- */
$recent = all("SELECT o.*, p.name AS patient_name, b.name AS branch_name
               FROM orders o JOIN patients p ON p.id=o.patient_id JOIN branches b ON b.id=o.branch_id
               WHERE 1=1 {$bo[0]} ORDER BY o.id DESC LIMIT 8", $bo[1]);
$alerts = stock_alerts(6);
$alertCount = stock_alert_count();

page_head('Dashboard', 'dashboard');

/* Hasil pengiriman otomatis laporan bulanan (bila baru terjadi saat login) */
/* Hasil BACKUP OTOMATIS (dijalankan saat login bila jadwalnya sudah berganti). */
if (!empty($_SESSION['backup_auto_result'])) {
    $bk = $_SESSION['backup_auto_result'];
    unset($_SESSION['backup_auto_result']);
    $isSuper = is_super();
    echo '<div class="alert alert-' . (!empty($bk['ok']) ? 'success' : 'warning') . '" data-autohide="1">';
    echo '<strong>Backup otomatis:</strong> ';
    if (!empty($bk['ok'])) {
        echo 'berkas <strong>' . e($bk['file']) . '</strong> (' . num(round(((int)$bk['size']) / 1024, 1), 1) . ' KB) dibuat'
            . ' sesuai jadwal <strong>' . e(backup_schedule_label((string)($bk['schedule'] ?? 'harian'))) . '</strong>'
            . (!empty($bk['removed']) ? ' · ' . num((int)$bk['removed']) . ' backup lama dibuang otomatis' : '') . '.';
        if ($isSuper) echo ' <a href="backup.php">Kelola backup</a>';
    } else {
        echo 'gagal — ' . e((string)($bk['error'] ?? 'tidak diketahui'));
        if ($isSuper) echo ' <a href="backup.php">Lihat halaman Backup</a>';
    }
    echo '</div>';
}

if (!empty($_SESSION['email_auto_result'])) {
    $ar = $_SESSION['email_auto_result'];
    unset($_SESSION['email_auto_result']);
    echo '<div class="alert alert-' . (!empty($ar['ok']) ? 'success' : 'warning') . '">';
    echo '<strong>Laporan bulanan otomatis:</strong> ';
    echo !empty($ar['ok'])
        ? 'periode ' . e($ar['month']) . ' terkirim ke ' . e($ar['to'])
          . (!empty($ar['attachments']) ? ' beserta ' . num(count($ar['attachments'])) . ' lampiran (' . e(implode(' + ', $ar['attachments'])) . ').' : '.')
        : 'gagal dikirim — ' . e((string)$ar['error']);
    echo ' <a href="settings.php">Pengaturan email</a></div>';
}

/* Hasil penghapusan OTOMATIS data lama (retensi) — diatur khusus oleh Super Admin
   di Developer Settings, TETAPI dijalankan saat SIAPA PUN login (semua level),
   supaya pembersihan tetap berjalan walau Super Admin jarang masuk. Karena itu
   pemberitahuannya ditampilkan kepada siapa pun yang sedang login — perubahan
   data tidak boleh terjadi tanpa sepengetahuan petugas. */
if (!empty($_SESSION['retention_auto_result'])) {
    $rt = $_SESSION['retention_auto_result'];
    unset($_SESSION['retention_auto_result']);
    echo '<div class="alert alert-warning" data-autohide="1">';
    echo '<strong>Pembersihan data lama otomatis:</strong> ' . num((int)$rt['total']) . ' baris dihapus — ';
    echo e(implode(', ', array_map(fn($x) => $x['label'] . ' ' . num((int)$x['jumlah']), $rt['rincian'])));
    echo '. ' . (is_super() ? '<a href="developer.php#retensi">Atur penyimpanan data</a>' : 'Hubungi Super Admin untuk pengaturan ini.');
    echo '</div>';
}

/* Hasil pembersihan AKTIVITAS AKUN sendiri ("Aktivitas Saya Terbaru"). */
if (!empty($_SESSION['activity_auto_result'])) {
    $ak = $_SESSION['activity_auto_result'];
    unset($_SESSION['activity_auto_result']);
    echo '<div class="alert alert-info" data-autohide="1">';
    echo '<strong>Aktivitas akun lama dibersihkan otomatis:</strong> ' . num((int)$ak['deleted'])
        . ' catatan aktivitas Anda yang lebih lama dari ' . e((string)$ak['label']) . ' dihapus.';
    echo ' <a href="profile.php">Lihat aktivitas saya</a>';
    echo '</div>';
}

/* RESET PERIODE KARTU MEMBER — perubahan level member tidak boleh terjadi tanpa
   sepengetahuan petugas, jadi diberitahukan kepada siapa pun yang sedang login. */
if (!empty($_SESSION['member_rollover_result'])) {
    $mr = $_SESSION['member_rollover_result'];
    unset($_SESSION['member_rollover_result']);
    echo '<div class="alert alert-warning" data-autohide="1">';
    echo '<strong>Periode akumulasi kartu member berganti:</strong> periode baru mulai 1 Januari '
        . num((int)$mr['to']) . '. ';
    echo (int)$mr['steps'] > 0
        ? num((int)$mr['berubah']) . ' dari ' . num((int)$mr['anggota']) . ' member <strong>turun '
          . num((int)$mr['steps']) . ' tingkat</strong>'
        : 'Level member <strong>tidak</strong> diturunkan';
    echo ' dan akumulasi mulai dari nol (nomor member &amp; kartu tidak berubah).';
    echo is_super() ? ' <a href="settings.php#member">Lihat pengaturan kartu member</a>' : '';
    echo '</div>';
}
?>
<div class="page-head">
  <div>
    <h2><?= $scope === null ? 'Dashboard Pusat — ' . e(clinic_name()) : 'Dashboard ' . e($scopeLbl) ?></h2>
    <p class="muted">Ringkasan operasional per <?= e(tglIndo($today)) ?> · Cakupan: <?= e($scopeLbl) ?></p>
  </div>
  <div class="page-actions">
    <?php if (has_perm('order.manage')): ?><a class="btn btn-primary" href="order_baru.php"><?= icon('plus-circle') ?> Order Baru</a><?php endif; ?>
    <?php if (has_perm('reservation.manage')): ?><a class="btn" href="reservasi.php?action=new"><?= icon('calendar') ?> Reservasi Baru</a><?php endif; ?>
  </div>
</div>

<div class="grid g4">
  <div class="stat accent">
    <span class="lbl">Pendapatan Hari Ini</span>
    <span class="val"><?= money($revToday) ?></span>
    <span class="sub"><?= num($trxToday) ?> transaksi · <?= num($visitToday) ?> pasien berkunjung</span>
  </div>
  <div class="stat">
    <span class="lbl">Pendapatan Bulan Ini</span>
    <span class="val"><?= money($revMonth) ?></span>
    <span class="sub"><?= num($trxMonth) ?> transaksi tercatat</span>
  </div>
  <div class="stat">
    <span class="lbl">Reservasi Hari Ini</span>
    <span class="val"><?= num($resToday) ?></span>
    <span class="sub"><a href="reservasi.php">Lihat jadwal reservasi →</a></span>
  </div>
  <?php if (member_card_enabled()): ?>
  <div class="stat">
    <span class="lbl">Member Aktif</span>
    <span class="val"><?= num(member_count()) ?></span>
    <span class="sub"><a href="pasien.php">Kartu member diterbitkan →</a></span>
  </div>
  <?php endif; ?>
  <div class="stat">
    <span class="lbl">Stok Menipis</span>
    <span class="val"><?= num($alertCount) ?></span>
    <span class="sub"><a href="inventory_movement.php?alert=1">Periksa inventory →</a></span>
  </div>
</div>

<?php if ($fin): ?>
<?php /* Kartu KEUANGAN (HPP & laba) — hanya level owner. */ ?>
<div class="grid g4 mt-2">
  <div class="stat">
    <span class="lbl">Total HPP <span class="muted small">(internal)</span></span>
    <span class="val"><?= money($fin['hpp_total']) ?></span>
    <span class="sub">Treatment <?= money($fin['hpp_treatment']) ?> · Produk <?= money($fin['hpp_produk']) ?></span>
  </div>
  <?php if ($fin['mode'] === 'lengkap'): ?>
    <div class="stat">
      <span class="lbl">Laba Kotor</span>
      <span class="val"><?= money($fin['laba_kotor']) ?></span>
      <span class="sub">Omzet − HPP</span>
    </div>
    <div class="stat">
      <span class="lbl">Biaya Operasional</span>
      <span class="val"><?= money($fin['biaya_total']) ?></span>
      <span class="sub"><?= num(count($fin['biaya_rows'])) ?> pos biaya</span>
    </div>
  <?php endif; ?>
  <div class="stat leaf">
    <span class="lbl">LABA BERSIH <span class="muted small">(internal)</span></span>
    <span class="val"><?= money($fin['laba_bersih']) ?></span>
    <span class="sub"><?= $fin['margin'] !== null ? 'margin ' . num($fin['margin'], 1) . '% dari omzet' : 'belum ada omzet' ?>
      · <a href="keuangan.php?period=<?= e($period) ?>">Laporan keuangan →</a></span>
  </div>
</div>
<?php endif; ?>

<div class="grid g2 mt-2">
  <div class="stat">
    <span class="lbl">Penjualan Treatment — Hari Ini</span>
    <span class="val"><?= money((float)$kpiTreatment['s']) ?></span>
    <span class="sub"><?= num((float)$kpiTreatment['q']) ?> treatment terjual</span>
    <div class="notice mt-1">Bulan ini: <strong><?= money((float)$kpiTreatmentM['s']) ?></strong> · <?= num((float)$kpiTreatmentM['q']) ?> treatment terjual</div>
  </div>
  <div class="stat leaf">
    <span class="lbl">Penjualan Skincare — Hari Ini</span>
    <span class="val"><?= money((float)$kpiSkincare['s']) ?></span>
    <span class="sub"><?= num((float)$kpiSkincare['q']) ?> produk terjual</span>
    <div class="notice mt-1" style="background:rgba(255,255,255,.22);border-color:rgba(255,255,255,.5);color:#fff">Bulan ini: <strong><?= money((float)$kpiSkincareM['s']) ?></strong> · <?= num((float)$kpiSkincareM['q']) ?> produk terjual</div>
  </div>
</div>

<?php if (is_owner_level()): ?>
<div class="section-title">Pendapatan Per Cabang</div>
<div class="grid grid-fit">
  <div class="card tight" style="grid-column:1/-1">
    <div class="card-head"><h3>Rekap Cabang</h3><span class="muted">Periode <?= e(date('F Y')) ?></span></div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Cabang</th><th class="num">Pendapatan Hari Ini</th><th class="num">Pendapatan Bulan Ini</th><th class="num">Transaksi</th><th class="num">Treatment</th><th class="num">Skincare</th></tr></thead>
        <tbody>
        <?php foreach ($perBranch as $pb): ?>
          <tr>
            <td><strong><?= e($pb['name']) ?></strong></td>
            <td class="num"><?= money($pb['today']) ?></td>
            <td class="num"><?= money($pb['month']) ?></td>
            <td class="num"><?= num($pb['trx']) ?></td>
            <td class="num"><?= money($pb['tr']) ?></td>
            <td class="num"><?= money($pb['sk']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th>TOTAL</th>
            <th class="num"><?= money(array_sum(array_column($perBranch, 'today'))) ?></th>
            <th class="num"><?= money(array_sum(array_column($perBranch, 'month'))) ?></th>
            <th class="num"><?= num(array_sum(array_column($perBranch, 'trx'))) ?></th>
            <th class="num"><?= money(array_sum(array_column($perBranch, 'tr'))) ?></th>
            <th class="num"><?= money(array_sum(array_column($perBranch, 'sk'))) ?></th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<?php
/* KARTU PERBANDINGAN LEBAR PENUH (ronde 37, permintaan pemilik).
   Dulu kartu ini berada di dalam grid bersama tabel "Rekap Cabang" sehingga
   hanya selebar sisa kolom. Sekarang dipisah: lebar penuh, dan TINGGINYA
   MENYESUAIKAN JUMLAH CABANG (2–3 cabang tetap lega; makin banyak cabang makin
   tinggi supaya batang & label tidak berdesakan). Bila cabangnya banyak (> 8),
   grafik otomatis berubah menjadi batang HORIZONTAL (nama cabang di kiri) agar
   tetap terbaca. */
$branchCount = count($perBranch);
$chartBranchH = $branchCount > 8 ? min(900, 190 + ($branchCount * 34)) : 320;
?>
<div class="card" id="perbandingan">
  <div class="card-head">
    <h3>Perbandingan</h3>
    <span class="muted">Bulan ini vs hari ini · <?= num($branchCount) ?> cabang</span>
  </div>
  <div class="card-body">
    <?php /* Tinggi kotak grafik mengikuti jumlah cabang (maksimal 900px). */ ?>
    <div class="chart-box" style="height:<?= (int)$chartBranchH ?>px"><canvas id="chartBranch"></canvas></div>
    <div class="small muted mt-1">Perbandingan pendapatan <strong>bulan ini</strong> dengan
      <strong>hari ini</strong> untuk setiap cabang.</div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h3>Ringkasan Transaksi Berdasarkan Periode</h3>
    <span class="muted"><?= e(tgl($ps)) ?> — <?= e(tgl($pe)) ?></span>
  </div>
  <form class="filter-bar" method="get">
    <div class="field">
      <label>Periode</label>
      <select class="input input-sm" name="period" data-period data-autosubmit>
        <?php foreach (['today' => 'Hari ini', '7d' => '7 hari', 'month' => 'Bulan ini', '3m' => '3 bulan', 'year' => 'Tahun ini', 'custom' => 'Custom tanggal'] as $k => $v): ?>
          <option value="<?= $k ?>"<?= $period === $k ? ' selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" data-period-custom style="display:none">
      <label>Dari</label>
      <input type="date" class="input input-sm" name="start" value="<?= e($ps) ?>">
    </div>
    <div class="field" data-period-custom style="display:none">
      <label>Sampai</label>
      <input type="date" class="input input-sm" name="end" value="<?= e($pe) ?>">
    </div>
    <?php if (is_owner_level()): ?>
    <div class="field">
      <label>Cabang</label>
      <select class="input input-sm" name="branch" data-autosubmit>
        <option value="all"<?= $scope === null ? ' selected' : '' ?>>Semua Cabang</option>
        <?php foreach (branches() as $b): ?>
          <option value="<?= (int)$b['id'] ?>"<?= (string)$scope === (string)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <button class="btn btn-sm btn-primary" type="submit">Terapkan</button>
  </form>
  <div class="card-body">
    <div class="grid g4 mb-3">
      <div class="stat"><span class="lbl">Total Pendapatan</span><span class="val"><?= money($periodTotals['total']) ?></span></div>
      <div class="stat"><span class="lbl">Pendapatan Treatment</span><span class="val"><?= money($periodTotals['tr']) ?></span></div>
      <div class="stat"><span class="lbl">Penjualan Skincare</span><span class="val"><?= money($periodTotals['sk']) ?></span></div>
      <div class="stat"><span class="lbl">Jumlah Transaksi</span><span class="val"><?= num($periodTotals['trx']) ?></span></div>
    </div>
    <?php /* Dua grafik ditumpuk (bukan kiri-kanan) supaya masing-masing memakai
       lebar penuh kartu — pada periode panjang (3 bulan/tahun) label sumbu
       tanggal tidak berdesakan seperti saat grafiknya hanya separuh lebar. */ ?>
    <div>
      <h4 class="chart-cap">Treatment vs Skincare (batang berjajaran)</h4>
      <div class="chart-box lg"><canvas id="chartTrend" data-chart="Ringkasan Treatment &amp; Skincare"></canvas></div>
    </div>
    <div class="mt-2">
      <h4 class="chart-cap">Total Pendapatan</h4>
      <div class="chart-box"><canvas id="chartTrendTotal" data-chart="Ringkasan Total"></canvas></div>
    </div>
    <?php if ($finSeries): ?>
    <?php /* Grafik LABA BERSIH — hanya level owner (data internal). */ ?>
    <div class="mt-2">
      <h4 class="chart-cap">Laba Bersih <span class="muted small">(internal)</span></h4>
      <div class="chart-box"><canvas id="chartLaba" data-chart="Laba Bersih"></canvas></div>
      <div class="small muted mt-1">
        Omzet − HPP <?= $fin['mode'] === 'lengkap' ? '− biaya operasional ' : '' ?>per
        <?= $finSeries['granularity'] === 'harian' ? 'hari' : 'bulan' ?> ·
        total <?= money($finSeries['total']) ?> pada periode ini ·
        <a href="keuangan.php?period=<?= e($period) ?>">rincian di menu Keuangan →</a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="grid g2">
  <div class="card">
    <div class="card-head"><h3>Top 5 Penjualan Skincare</h3><a class="btn btn-sm" href="top5.php?type=skincare">Detail</a></div>
    <div class="card-body">
      <?php if (!$topSk): ?><?= empty_state('Belum ada penjualan skincare pada periode ini.') ?><?php else: ?>
        <div class="chart-box sm"><canvas id="chartTopSk"></canvas></div>
        <div class="table-wrap mt-2"><table class="tbl">
          <thead><tr><th>#</th><th>Produk</th><th class="num">Terjual</th><th class="num">Total</th></tr></thead>
          <tbody>
          <?php $totSk = array_sum(array_column($topSk, 's')) ?: 1; foreach ($topSk as $i => $r): ?>
            <tr><td><span class="rank r<?= $i + 1 ?>"><?= $i + 1 ?></span></td>
              <td><?= e($r['name']) ?><div class="small muted"><?= e($r['category']) ?></div></td>
              <td class="num"><?= qty_text($r['q']) ?></td>
              <td class="num"><?= money($r['s']) ?><div class="small muted"><?= num($r['s'] / $totSk * 100, 1) ?>%</div></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Top 5 Penjualan Treatment</h3><a class="btn btn-sm" href="top5.php?type=treatment">Detail</a></div>
    <div class="card-body">
      <?php if (!$topTr): ?><?= empty_state('Belum ada penjualan treatment pada periode ini.') ?><?php else: ?>
        <div class="chart-box sm"><canvas id="chartTopTr"></canvas></div>
        <div class="table-wrap mt-2"><table class="tbl">
          <thead><tr><th>#</th><th>Treatment</th><th class="num">Terjual</th><th class="num">Total</th></tr></thead>
          <tbody>
          <?php $totTr = array_sum(array_column($topTr, 's')) ?: 1; foreach ($topTr as $i => $r): ?>
            <tr><td><span class="rank r<?= $i + 1 ?>"><?= $i + 1 ?></span></td>
              <td><?= e($r['name']) ?><div class="small muted"><?= e($r['category']) ?></div></td>
              <td class="num"><?= qty_text($r['q']) ?></td>
              <td class="num"><?= money($r['s']) ?><div class="small muted"><?= num($r['s'] / $totTr * 100, 1) ?>%</div></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid g2">
  <div class="card tight">
    <div class="card-head"><h3>Transaksi Terbaru</h3><a class="btn btn-sm" href="order.php">Semua</a></div>
    <div class="table-wrap">
      <?php if (!$recent): ?><?= empty_state('Belum ada transaksi.') ?><?php else: ?>
      <table class="tbl">
        <thead><tr><th>Invoice</th><th>Pasien</th><th>Cabang</th><th class="num">Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td><a href="order_detail.php?id=<?= (int)$r['id'] ?>"><?= e($r['invoice_number']) ?></a><div class="small muted"><?= e(tgl($r['created_at'], true)) ?></div></td>
            <td><?= e($r['patient_name']) ?></td>
            <td class="small"><?= e($r['branch_name']) ?></td>
            <td class="num"><?= money($r['total']) ?></td>
            <td><?= order_status_badge($r['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Stok Menipis / Habis</h3><a class="btn btn-sm" href="inventory_movement.php?alert=1">Kelola</a></div>
    <div class="card-body">
      <?php if (!$alerts): ?>
        <p class="muted">Semua stok dalam kondisi aman.</p>
      <?php else: ?>
        <ul class="alert-list">
        <?php foreach ($alerts as $a): ?>
          <li>
            <div>
              <strong><?= e($a['name']) ?></strong>
              <div class="small muted"><?= e($a['code']) ?> · <?= e($a['branch_name']) ?></div>
            </div>
            <div class="right">
              <?= (float)$a['stock'] <= 0 ? badge('STOK HABIS', 'red') : badge('STOK MENIPIS', 'yellow') ?>
              <div class="small muted"><?= qty_unit($a['stock'], $a['unit']) ?> / min <?= qty_unit($a['minimum_stock'], $a['unit']) ?></div>
            </div>
          </li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$PAGE_SCRIPTS = [];
ob_start();
?>
<script src="assets/vendor/chart.umd.min.js"></script>
<script><?= income_charts_js() ?></script>
<script>
(function () {
  /* Warna seri bermakna (Treatment/Skincare/Total) & palet kontras tinggi —
     lihat Pengaturan → Warna Grafik. Dulu grafik perbandingan antar cabang
     memakai magenta vs hijau muda sehingga perbedaannya tipis. */
  var S = { tr: Naveena.series('treatment'), sk: Naveena.series('skincare'), tot: Naveena.series('total') };
  <?php if (is_owner_level() && $perBranch): ?>
  /* GRAFIK PERBANDINGAN ANTAR CABANG — menyesuaikan JUMLAH CABANG (ronde 37).
     2–8 cabang: batang tegak (nama cabang di bawah). Lebih dari 8 cabang:
     batang HORIZONTAL supaya nama cabang panjang & batangnya tetap terbaca,
     dengan tinggi kotak yang sudah dihitung dari jumlah cabang. */
  (function () {
    var labels = <?= js_json(array_map(fn($x) => branch_short_label((string)$x['name']), $perBranch)) ?>;
    var bulan = <?= js_json(array_map(fn($x) => (float)$x['month'], $perBranch)) ?>;
    var hari = <?= js_json(array_map(fn($x) => (float)$x['today'], $perBranch)) ?>;
    var byk = labels.length > 8;
    var nilaiSumbu = function (v) {
      var a = typeof Naveena.rupiahShort === 'function' ? Naveena.rupiahShort(v) : String(v);
      return a;
    };
    Naveena.chart('chartBranch', {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          /* Dua seri: bulan ini vs hari ini — memakai warna yang paling kontras
             (palet kategorikal: merah vs cyan) supaya batangnya jelas berbeda. */
          { label: 'Bulan ini', data: bulan, backgroundColor: Naveena.paletteFor(2)[0],
            borderRadius: 6, maxBarThickness: byk ? 22 : 44 },
          { label: 'Hari ini', data: hari, backgroundColor: Naveena.paletteFor(2)[1],
            borderRadius: 6, maxBarThickness: byk ? 22 : 44 }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        indexAxis: byk ? 'y' : 'x',
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } },
          tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + Naveena.rupiah(c.parsed.raw) } } },
        scales: byk
          /* Banyak cabang: nilai ada di sumbu X (dibuat ringkas), nama cabang di Y. */
          ? { x: { beginAtZero: true, ticks: { callback: nilaiSumbu, font: { size: 10 }, maxRotation: 0 },
                   grid: { drawTicks: false } },
              y: { ticks: { font: { size: 10 }, autoSkip: false }, grid: { display: false } } }
          : { y: { beginAtZero: true, ticks: { callback: nilaiSumbu, font: { size: 10 }, maxRotation: 0 },
                   grid: { drawTicks: false } },
              x: { ticks: { font: { size: 10 }, autoSkip: false, maxRotation: labels.length > 5 ? 40 : 0 },
                   grid: { display: false } } }
      }
    });
  })();
  <?php endif; ?>
  /* Ringkasan transaksi per periode: DUA grafik — Treatment vs Skincare
     berjajaran, lalu Total Pendapatan dengan sumbu sendiri. Dulu keduanya
     ditumpuk sehingga garis Total menempel di puncak tumpukan (tampak hilang). */
  NaveenaIncome({
    bars: 'chartTrend', total: 'chartTrendTotal',
    labels: <?= js_json($labels) ?>,
    tr: <?= js_json($sTr) ?>,
    sk: <?= js_json($sSk) ?>,
    totalData: <?= js_json($sTot) ?>,
    trColor: S.tr, skColor: S.sk,
    totalColor: S.tot, totalFill: Naveena.rgba(S.tot, .14),
    money: Naveena.rupiah, short: Naveena.rupiahShort
  });
  <?php if ($finSeries): ?>
  /* Grafik LABA BERSIH (hanya level owner). Warna mengikuti seri "positif/negatif"
     yang diatur di Pengaturan → Warna Grafik. */
  Naveena.chart('chartLaba', {
    type: 'bar',
    data: {
      labels: <?= js_json($finSeries['labels']) ?>,
      datasets: [{ label: 'Laba Bersih', data: <?= js_json($finSeries['laba']) ?>,
        backgroundColor: <?= js_json($finSeries['laba']) ?>.map(function (v) {
          return v >= 0 ? Naveena.series('positif') : Naveena.series('negatif'); }),
        borderRadius: 5, maxBarThickness: 32 }]
    },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false },
        tooltip: { callbacks: { label: function (c) { return 'Laba bersih: ' + Naveena.rupiah(c.parsed.y); } } } },
      scales: { x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0, font: { size: 10 } } },
        y: { ticks: { callback: function (v) { return Naveena.rupiahShort(v); }, font: { size: 10 } }, grid: { drawTicks: false } } } }
  });
  <?php endif; ?>
  function doughnut(id, rows) {
    if (!rows || !rows.length) return;
    Naveena.chart(id, {
      type: 'doughnut',
      data: { labels: rows.map(r => r.name),
        datasets: [{ data: rows.map(r => Number(r.s)), backgroundColor: Naveena.paletteFor(rows.length),
          borderWidth: 2, borderColor: '#fff' }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } } } }
    });
  }
  doughnut('chartTopSk', <?= js_json(array_map(fn($r) => ["name" => $r['name'], 's' => (float)$r['s']], $topSk)) ?>);
  doughnut('chartTopTr', <?= js_json(array_map(fn($r) => ["name" => $r['name'], 's' => (float)$r['s']], $topTr)) ?>);
})();
</script>
<?php
$PAGE_SCRIPTS[] = ob_get_clean();
page_foot();
