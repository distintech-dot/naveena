<?php
/**
 * Laporan lengkap: KPI, grafik (garis/batang/lingkaran), perbandingan antar cabang,
 * progres bulanan, top 5, kasir, metode pembayaran, dan tabel rinci.
 * Semua angka berasal dari includes/reports.php supaya identik dengan ekspor.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/reports.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/chart_js.php';
require_perm('report.view');

$user  = current_user();
$B     = report_bundle(true);
$f     = $B['filters'];
$tot   = $B['totals'];
$scope = $f['scope'];
$scopeName = $scope === null ? 'Semua Cabang' : (string)scalar('SELECT name FROM branches WHERE id=?', [$scope], '-');

$perBranch   = $B['branches'];
$perTreatment = $B['treatments'];
$perSkincare  = $B['skincares'];
$perMaterials = $B['materials'] ?? [];   // pemakaian bahan treatment (tidak ditagihkan)
$perMember    = $B['member_usage'] ?? [];   // pemakaian kartu member (diskon otomatis)
$daily       = $B['daily'];
$monthly     = $B['monthly'];
$methods     = $B['methods'];
$cashiersPerf = $B['cashiers'];

/* RINGKASAN KEUANGAN (HPP, laba kotor, biaya operasional, laba bersih) —
   HANYA untuk level owner (Super Admin & Direktur). Level lain tidak melihat
   kartu/grafiknya sama sekali karena laba bersih bersifat internal perusahaan. */
$fin = null;
$finSeries = null;
if (has_perm('finance.view') && is_owner_level()) {
    $fin = finance_summary($f, finance_mode() === 'lengkap', $scope);
    $finSeries = finance_series($f);
}

$trTot = array_sum(array_column($perTreatment, 's')) ?: 1;
$skTot = array_sum(array_column($perSkincare, 's')) ?: 1;
$topTr = array_slice($perTreatment, 0, 5);
$topSk = array_slice($perSkincare, 0, 5);
$branchSum = array_sum(array_column($perBranch, 'total')) ?: 1;

/* Filter dropdown (daftar pilihan) */
$cashiers = all('SELECT DISTINCT u.id, u.name FROM users u JOIN orders o ON o.user_id = u.id WHERE 1=1 '
    . ($scope !== null ? ' AND o.branch_id=' . (int)$scope : '') . ' ORDER BY u.name');
$treatments = all('SELECT id, name FROM treatments WHERE 1=1 ' . ($scope !== null ? ' AND branch_id=' . (int)$scope : '') . ' ORDER BY name');
$skincares  = all('SELECT id, name FROM skincare_products WHERE 1=1 ' . ($scope !== null ? ' AND branch_id=' . (int)$scope : '') . ' ORDER BY name');

page_head('Laporan Lengkap', 'laporan');
?>
<div class="page-head">
  <div>
    <h2>Laporan Lengkap</h2>
    <p class="muted">
      <?= e(tgl($f['ps'])) ?> — <?= e(tgl($f['pe'])) ?> · <?= e($scopeName) ?>
      · status <strong><?= e($f['status']) ?></strong>
      <?php
      $activeFilters = [];
      if (gp('cashier') !== '') { $c = one('SELECT name FROM users WHERE id = ?', [(int)gp('cashier')]); $activeFilters[] = 'kasir ' . ($c['name'] ?? gp('cashier')); }
      if (gp('method') !== '') $activeFilters[] = 'metode ' . gp('method');
      if (gp('treatment') !== '') { $t = one('SELECT name FROM treatments WHERE id = ?', [(int)gp('treatment')]); $activeFilters[] = 'treatment ' . ($t['name'] ?? gp('treatment')); }
      if (gp('skincare') !== '') { $t = one('SELECT name FROM skincare_products WHERE id = ?', [(int)gp('skincare')]); $activeFilters[] = 'skincare ' . ($t['name'] ?? gp('skincare')); }
      if ($activeFilters) echo ' · filter: <strong>' . e(implode(', ', $activeFilters)) . '</strong>';
      ?>
    </p>
  </div>
  <div class="page-actions">
    <button class="btn" type="button" onclick="Naveena.chartsPng('laporan')"><?= icon('download') ?> Unduh Semua Grafik (PNG)</button>
    <?php if (has_perm('export.data')): ?>
      <a class="btn" href="export.php?type=keuangan&format=csv&<?= e(qs([], ['page', 'per_page'])) ?>">CSV</a>
      <a class="btn btn-primary" href="export.php?type=laporan&format=xlsx&<?= e(qs([], ['page', 'per_page'])) ?>"><?= icon('download') ?> Excel Lengkap + Grafik</a>
      <a class="btn btn-primary" href="export.php?type=laporan&format=pdfserver&<?= e(qs([], ['page', 'per_page'])) ?>"><?= icon('download') ?> PDF Lengkap + Grafik</a>
      <?php if (finance_report_include()): ?>
        <?php /* Tombol KHUSUS OWNER: laporan lengkap + KEUANGAN + grafik. */ ?>
        <a class="btn btn-leaf" href="export.php?type=laporan&format=xlsx&keuangan=1&<?= e(qs([], ['page', 'per_page'])) ?>"><?= icon('download') ?> Excel Lengkap + Keuangan + Grafik</a>
        <a class="btn btn-leaf" href="export.php?type=laporan&format=pdfserver&keuangan=1&<?= e(qs([], ['page', 'per_page'])) ?>"><?= icon('download') ?> PDF Lengkap + Keuangan + Grafik</a>
      <?php endif; ?>
      <a class="btn" href="export.php?type=laporan&format=excel&<?= e(qs([], ['page', 'per_page'])) ?>">Excel (data saja)</a>
      <a class="btn" href="export.php?type=laporan&format=pdf&<?= e(qs([], ['page', 'per_page'])) ?>" target="_blank"><?= icon('print') ?> Cetak / Simpan PDF</a>
    <?php endif; ?>
  </div>
</div>

<div class="card tight">
  <form class="filter-bar" method="get">
    <div class="field"><label>Periode</label>
      <select class="input input-sm" name="period" data-period data-autosubmit>
        <?php foreach (['today' => 'Hari ini', '7d' => '7 hari', 'month' => 'Bulan ini', '3m' => '3 bulan', 'year' => 'Tahun ini', 'custom' => 'Custom tanggal'] as $k => $v): ?>
          <option value="<?= $k ?>"<?= $f['period'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field" data-period-custom style="display:none"><label>Dari</label><input class="input input-sm" type="date" name="start" value="<?= e($f['ps']) ?>"></div>
    <div class="field" data-period-custom style="display:none"><label>Sampai</label><input class="input input-sm" type="date" name="end" value="<?= e($f['pe']) ?>"></div>
    <?php if (is_owner_level()): ?>
    <div class="field"><label>Cabang</label>
      <select class="input input-sm" name="branch">
        <option value="all"<?= $scope === null ? ' selected' : '' ?>>Semua Cabang</option>
        <?php foreach (branches() as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $scope === (int)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <div class="field"><label>Kasir</label>
      <select class="input input-sm" name="cashier"><option value="">Semua</option>
        <?php foreach ($cashiers as $c): ?><option value="<?= (int)$c['id'] ?>"<?= gp('cashier') === (string)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Metode Bayar</label>
      <select class="input input-sm" name="method"><option value="">Semua</option>
        <?php foreach (PAY_METHODS as $m): ?><option value="<?= $m ?>"<?= gp('method') === $m ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Treatment</label>
      <select class="input input-sm" name="treatment"><option value="">Semua</option>
        <?php foreach ($treatments as $t): ?><option value="<?= (int)$t['id'] ?>"<?= gp('treatment') === (string)$t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Skincare</label>
      <select class="input input-sm" name="skincare"><option value="">Semua</option>
        <?php foreach ($skincares as $t): ?><option value="<?= (int)$t['id'] ?>"<?= gp('skincare') === (string)$t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Status</label>
      <select class="input input-sm" name="status">
        <option value="paid"<?= $f['status'] === 'paid' ? ' selected' : '' ?>>Lunas (paid)</option>
        <option value="void"<?= $f['status'] === 'void' ? ' selected' : '' ?>>Void</option>
        <option value="refund"<?= $f['status'] === 'refund' ? ' selected' : '' ?>>Refund</option>
      </select></div>
    <button class="btn btn-sm btn-primary" type="submit">Terapkan</button>
    <a class="btn btn-sm" href="laporan.php">Reset</a>
  </form>
</div>

<!-- ============ KPI ============ -->
<div class="grid g3">
  <div class="stat accent"><span class="lbl">Total Pendapatan</span><span class="val"><?= money($tot['total']) ?></span>
    <span class="sub"><?= num($tot['trx']) ?> transaksi · rata-rata <?= money($tot['avg']) ?></span></div>
  <div class="stat"><span class="lbl">Pendapatan Treatment</span><span class="val"><?= money($tot['tr']) ?></span>
    <span class="sub"><?= num($tot['tr_q']) ?> treatment · <?= num($tot['total'] > 0 ? $tot['tr'] / $tot['total'] * 100 : 0, 1) ?>% dari total</span></div>
  <div class="stat leaf"><span class="lbl">Penjualan Skincare</span><span class="val"><?= money($tot['sk']) ?></span>
    <span class="sub"><?= num($tot['sk_q']) ?> produk · <?= num($tot['total'] > 0 ? $tot['sk'] / $tot['total'] * 100 : 0, 1) ?>% dari total</span></div>
  <?php if ((float)($tot['pkg'] ?? 0) > 0): ?>
  <div class="stat"><span class="lbl">Pendapatan Paket</span><span class="val"><?= money($tot['pkg']) ?></span>
    <span class="sub"><?= num($tot['pkg_q'] ?? 0) ?> paket terjual</span></div>
  <?php endif; ?>
  <div class="stat"><span class="lbl">Jumlah Transaksi</span><span class="val"><?= num($tot['trx']) ?></span>
    <span class="sub"><?= money($tot['avg']) ?> rata-rata per transaksi</span></div>
  <div class="stat"><span class="lbl">Total Pembayaran Valid</span><span class="val"><?= money($tot['pay_total']) ?></span>
    <span class="sub"><?= num($tot['pay_n']) ?> pembayaran tercatat</span></div>
  <div class="stat"><span class="lbl">Total Diskon</span><span class="val"><?= money($tot['disc']) ?></span>
    <span class="sub">Subtotal <?= money($tot['subtotal']) ?></span></div>
  <div class="stat"><span class="lbl">Diskon Member</span><span class="val"><?= money($tot['member_disc'] ?? 0) ?></span>
    <span class="sub"><?= num($tot['member_trx'] ?? 0) ?> transaksi memakai kartu member</span></div>
</div>

<?php if ($fin): ?>
<?php /* ============ KPI KEUANGAN (khusus owner) ============ */ ?>
<div class="card mt-2">
  <div class="card-head">
    <h3>Keuangan (HPP &amp; Laba) <span class="badge badge-yellow">Internal — khusus Direktur/Owner &amp; Super Admin</span></h3>
    <span class="muted"><?= e(finance_mode_label($fin['mode'])) ?> ·
      <a href="keuangan.php?period=<?= e($f['period']) ?><?= $scope !== null ? '&amp;branch=' . (int)$scope : '' ?>">Buka menu Keuangan →</a></span>
  </div>
  <div class="card-body">
    <div class="grid g4">
      <div class="stat"><span class="lbl">Total HPP</span><span class="val"><?= money($fin['hpp_total']) ?></span>
        <span class="sub">Treatment <?= money($fin['hpp_treatment']) ?> · Produk <?= money($fin['hpp_produk']) ?></span></div>
      <?php if ($fin['mode'] === 'lengkap'): ?>
        <div class="stat"><span class="lbl">Laba Kotor</span><span class="val"><?= money($fin['laba_kotor']) ?></span>
          <span class="sub">Omzet − HPP</span></div>
        <div class="stat"><span class="lbl">Biaya Operasional</span><span class="val"><?= money($fin['biaya_total']) ?></span>
          <span class="sub"><?= num(count($fin['biaya_rows'])) ?> pos biaya</span></div>
      <?php else: ?>
        <div class="stat"><span class="lbl">Omzet (dasar keuangan)</span><span class="val"><?= money($fin['omzet']) ?></span>
          <span class="sub">barang − diskon − diskon member</span></div>
        <div class="stat"><span class="lbl">Mode Laporan</span><span class="val" style="font-size:1.05rem">Dasar</span>
          <span class="sub"><a href="keuangan.php?period=<?= e($f['period']) ?>#modepilihan">aktifkan "Lengkap" di menu Keuangan →</a></span></div>
      <?php endif; ?>
      <div class="stat leaf"><span class="lbl">LABA BERSIH</span><span class="val"><?= money($fin['laba_bersih']) ?></span>
        <span class="sub"><?= $fin['margin'] !== null ? 'margin ' . num($fin['margin'], 1) . '% dari omzet' : 'belum ada omzet' ?></span></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ============ PROGRES BULANAN ============ -->
<div class="card mt-2">
  <div class="card-head">
    <h3>Progres Pendapatan <?= $monthly['granularity'] === 'harian' ? 'Harian' : 'Bulanan' ?>
      (<?= num($monthly['buckets']) ?> <?= e($monthly['label_suffix']) ?>)</h3>
    <div class="flex gap-sm">
      <?php if ($monthly['growth'] !== null): ?>
        <span class="pill <?= $monthly['growth'] >= 0 ? 'active' : '' ?>">
          <?= $monthly['growth'] >= 0 ? '▲' : '▼' ?> <?= num(abs($monthly['growth']), 1) ?>% vs bulan sebelumnya
        </span>
      <?php endif; ?>
      <button class="btn btn-sm" type="button" onclick="Naveena.chartPng('chartMonthly','progres-treatment-skincare')"><?= icon('download') ?> PNG Treatment &amp; Skincare</button>
      <button class="btn btn-sm" type="button" onclick="Naveena.chartPng('chartMonthlyTotal','progres-total')"><?= icon('download') ?> PNG Total</button>
    </div>
  </div>
  <div class="card-body">
    <div class="grid g3 mb-3">
      <div class="stat"><span class="lbl"><?= $monthly['granularity'] === 'harian' ? 'Hari' : 'Bulan' ?> Terakhir
          (<?= e($monthly['labels'][count($monthly['labels']) - 1] ?? '-') ?>)</span>
        <span class="val"><?= money($monthly['last']) ?></span>
        <span class="sub"><?= $monthly['prev'] > 0 ? 'sebelumnya ' . money($monthly['prev']) : 'belum ada pembanding' ?></span></div>
      <div class="stat"><span class="lbl">Total <?= num($monthly['buckets']) ?> <?= e($monthly['label_suffix']) ?></span>
        <span class="val"><?= money($monthly['sum']) ?></span>
        <span class="sub">rata-rata <?= money($monthly['buckets'] ? $monthly['sum'] / $monthly['buckets'] : 0) ?>
          / <?= e(strtolower($monthly['label_suffix'])) ?></span></div>
      <div class="stat"><span class="lbl">Periode Diterapkan</span>
        <span class="val" style="font-size:1rem"><?= e(tgl($monthly['start'])) ?> — <?= e(tgl($monthly['end'])) ?></span>
        <span class="sub"><?= e($scopeName) ?> · status <?= e($f['status']) ?></span></div>
    </div>
    <?php /* Ditumpuk (lebar penuh) supaya label tanggal tidak berdesakan pada
       periode 3 bulan/tahun — sejalan dengan grafik di dashboard. */ ?>
    <div>
      <h4 class="chart-cap">Treatment vs Skincare (batang berjajaran)</h4>
      <div class="chart-box lg"><canvas id="chartMonthly" data-chart="Progres Treatment &amp; Skincare"></canvas></div>
    </div>
    <div class="mt-2">
      <h4 class="chart-cap">Total Pendapatan</h4>
      <div class="chart-box"><canvas id="chartMonthlyTotal" data-chart="Progres Total"></canvas></div>
    </div>
    <p class="muted mt-1">Grafik ini mengikuti <strong>seluruh filter penerapan</strong> di atas (tanggal, cabang,
      kasir, metode bayar, treatment/skincare, dan status transaksi). Rentang ≤ 62 hari ditampilkan harian, lebih dari itu diringkas per bulan.
      Treatment dan Skincare ditampilkan <strong>berjajaran</strong> (bukan ditumpuk) supaya nilai masing-masing terbaca,
      sedangkan <strong>total pendapatan</strong> punya grafiknya sendiri.</p>
  </div>
</div>

<?php if (is_owner_level() && $monthly['branches']): ?>
<div class="card">
  <div class="card-head">
    <h3>Perbandingan Progres Antar Cabang (<?= $monthly['granularity'] === 'harian' ? 'harian' : 'bulanan' ?>)</h3>
    <button class="btn btn-sm" type="button" onclick="Naveena.chartPng('chartMonthlyBranch','progres-per-cabang')"><?= icon('download') ?> PNG</button>
  </div>
  <div class="card-body"><div class="chart-box lg"><canvas id="chartMonthlyBranch" data-chart="Progres Per Cabang"></canvas></div></div>
</div>
<?php endif; ?>

<!-- ============ KOMPOSISI & PERBANDINGAN ============ -->
<div class="grid g2">
  <div class="card">
    <div class="card-head">
      <h3>Komposisi Pendapatan</h3>
      <button class="btn btn-sm" type="button" onclick="Naveena.chartPng('chartComposition','komposisi-pendapatan')"><?= icon('download') ?> PNG</button>
    </div>
    <div class="card-body">
      <div class="chart-box"><canvas id="chartComposition" data-chart="Komposisi Pendapatan"></canvas></div>
      <ul class="list-clean mt-2">
        <li><span>Treatment</span><span><strong><?= money($tot['tr']) ?></strong> · <?= num($tot['total'] > 0 ? $tot['tr'] / $tot['total'] * 100 : 0, 1) ?>%</span></li>
        <li><span>Skincare</span><span><strong><?= money($tot['sk']) ?></strong> · <?= num($tot['total'] > 0 ? $tot['sk'] / $tot['total'] * 100 : 0, 1) ?>%</span></li>
        <li><span>Potongan diskon</span><span><strong><?= money($tot['disc']) ?></strong></span></li>
        <li><span>Total dibayar</span><span><strong><?= money($tot['total']) ?></strong></span></li>
      </ul>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <h3>Metode Pembayaran</h3>
      <button class="btn btn-sm" type="button" onclick="Naveena.chartPng('chartMethod','metode-pembayaran')"><?= icon('download') ?> PNG</button>
    </div>
    <div class="card-body">
      <?php if (!$methods): ?><?= empty_state('Belum ada data pembayaran pada periode ini.') ?><?php else: ?>
        <div class="chart-box"><canvas id="chartMethod" data-chart="Metode Pembayaran"></canvas></div>
        <ul class="list-clean mt-2">
          <?php foreach ($methods as $m): ?>
            <li><span><?= e($m['method']) ?></span>
              <span><strong><?= money($m['total']) ?></strong> · <?= num($m['n']) ?>x</span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============ PER CABANG ============ -->
<div class="card tight">
  <div class="card-head">
    <h3>Perbandingan Cabang</h3>
    <div class="flex gap-sm">
      <button class="btn btn-sm" type="button" onclick="Naveena.chartPng('chartBranchBar','perbandingan-cabang')"><?= icon('download') ?> PNG Batang</button>
      <button class="btn btn-sm" type="button" onclick="Naveena.chartPng('chartBranchPie','kontribusi-cabang')"><?= icon('download') ?> PNG Lingkaran</button>
    </div>
  </div>
  <div class="card-body">
    <div class="grid g2">
      <div><div class="chart-box"><canvas id="chartBranchBar" data-chart="Perbandingan Cabang"></canvas></div></div>
      <div><div class="chart-box"><canvas id="chartBranchPie" data-chart="Kontribusi Cabang"></canvas></div></div>
    </div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Cabang</th><th class="num">Transaksi</th><th class="num">Pendapatan</th><th class="num">Treatment</th><th class="num">Skincare</th><th class="num">Diskon</th><th class="num">Kontribusi</th></tr></thead>
      <tbody>
      <?php if (!$perBranch): ?><tr><td colspan="7" class="muted center">Belum ada data.</td></tr><?php endif; ?>
      <?php foreach ($perBranch as $b): ?>
        <tr>
          <td><strong><?= e($b['name']) ?></strong></td>
          <td class="num"><?= num($b['trx']) ?></td>
          <td class="num"><?= money($b['total']) ?></td>
          <td class="num"><?= money($b['tr']) ?></td>
          <td class="num"><?= money($b['sk']) ?></td>
          <td class="num"><?= money($b['disc']) ?></td>
          <td class="num"><?= num($b['total'] / $branchSum * 100, 1) ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($perBranch): ?>
      <tfoot><tr>
        <th>TOTAL</th>
        <th class="num"><?= num(array_sum(array_column($perBranch, 'trx'))) ?></th>
        <th class="num"><?= money(array_sum(array_column($perBranch, 'total'))) ?></th>
        <th class="num"><?= money(array_sum(array_column($perBranch, 'tr'))) ?></th>
        <th class="num"><?= money(array_sum(array_column($perBranch, 'sk'))) ?></th>
        <th class="num"><?= money(array_sum(array_column($perBranch, 'disc'))) ?></th>
        <th class="num">100%</th>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<!-- ============ TREN HARIAN ============ -->
<div class="card">
  <div class="card-head">
    <h3>Pergerakan Pendapatan <?= $daily['by_month'] ? 'Per Bulan' : 'Harian' ?></h3>
    <div class="flex gap-sm">
      <span class="muted"><?= e(tgl($f['ps'])) ?> — <?= e(tgl($f['pe'])) ?></span>
      <button class="btn btn-sm" type="button" onclick="Naveena.chartPng('chartDaily','tren-treatment-skincare')"><?= icon('download') ?> PNG Treatment &amp; Skincare</button>
      <button class="btn btn-sm" type="button" onclick="Naveena.chartPng('chartDailyTotal','tren-total')"><?= icon('download') ?> PNG Total</button>
    </div>
  </div>
  <div class="card-body">
    <div>
      <h4 class="chart-cap">Treatment vs Skincare (batang berjajaran)</h4>
      <div class="chart-box lg"><canvas id="chartDaily" data-chart="Pergerakan Treatment &amp; Skincare"></canvas></div>
    </div>
    <div class="mt-2">
      <h4 class="chart-cap">Total Pendapatan</h4>
      <div class="chart-box"><canvas id="chartDailyTotal" data-chart="Pergerakan Total"></canvas></div>
      <?php if ($finSeries): ?>
      <div class="mt-2">
        <h4 class="chart-cap">Laba Bersih <span class="muted small">(internal — khusus owner)</span></h4>
        <div class="chart-box"><canvas id="chartLaba" data-chart="Laba Bersih"></canvas></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============ TOP 5 ============ -->
<div class="grid g2">
  <div class="card">
    <div class="card-head">
      <h3>Top 5 Treatment</h3>
      <div class="flex gap-sm">
        <button class="btn btn-sm" type="button" onclick="Naveena.chartPng('chartTopTr','top5-treatment')"><?= icon('download') ?> PNG</button>
        <a class="btn btn-sm" href="top5.php?<?= e(qs()) ?>">Detail</a>
      </div>
    </div>
    <div class="card-body">
      <?php if (!$topTr): ?><?= empty_state('Belum ada penjualan treatment pada periode ini.') ?><?php else: ?>
        <div class="grid g2">
          <div><div class="chart-box sm"><canvas id="pieTopTr" data-chart="Top 5 Treatment (lingkaran)"></canvas></div></div>
          <div><div class="chart-box sm"><canvas id="chartTopTr" data-chart="Top 5 Treatment"></canvas></div></div>
        </div>
        <div class="table-wrap mt-2">
          <table class="tbl">
            <thead><tr><th>#</th><th>Treatment</th><th>Kategori</th><th class="num">Terjual</th><th class="num">Pendapatan</th><th class="num">%</th></tr></thead>
            <tbody>
            <?php foreach ($topTr as $i => $r): ?>
              <tr>
                <td><span class="rank r<?= $i + 1 ?>"><?= $i + 1 ?></span></td>
                <td><?= e($r['nama']) ?><div class="small muted"><?= e($r['kode'] ?: '-') ?> · <?= num($r['trx']) ?> transaksi</div></td>
                <td class="small"><?= e($r['kategori']) ?></td>
                <td class="num"><?= qty_text($r['q']) ?></td>
                <td class="num"><?= money($r['s']) ?></td>
                <td class="num"><?= num($r['s'] / $trTot * 100, 1) ?>%</td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <h3>Top 5 Skincare</h3>
      <div class="flex gap-sm">
        <button class="btn btn-sm" type="button" onclick="Naveena.chartPng('chartTopSk','top5-skincare')"><?= icon('download') ?> PNG</button>
        <a class="btn btn-sm" href="top5.php?type=skincare&<?= e(qs()) ?>">Detail</a>
      </div>
    </div>
    <div class="card-body">
      <?php if (!$topSk): ?><?= empty_state('Belum ada penjualan skincare pada periode ini.') ?><?php else: ?>
        <div class="grid g2">
          <div><div class="chart-box sm"><canvas id="pieTopSk" data-chart="Top 5 Skincare (lingkaran)"></canvas></div></div>
          <div><div class="chart-box sm"><canvas id="chartTopSk" data-chart="Top 5 Skincare"></canvas></div></div>
        </div>
        <div class="table-wrap mt-2">
          <table class="tbl">
            <thead><tr><th>#</th><th>Produk</th><th>Kategori</th><th class="num">Terjual</th><th class="num">Penjualan</th><th class="num">%</th></tr></thead>
            <tbody>
            <?php foreach ($topSk as $i => $r): ?>
              <tr>
                <td><span class="rank r<?= $i + 1 ?>"><?= $i + 1 ?></span></td>
                <td><?= e($r['nama']) ?><div class="small muted"><?= e($r['kode'] ?: '-') ?> · <?= num($r['trx']) ?> transaksi</div></td>
                <td class="small"><?= e($r['kategori']) ?></td>
                <td class="num"><?= qty_text($r['q']) ?></td>
                <td class="num"><?= money($r['s']) ?></td>
                <td class="num"><?= num($r['s'] / $skTot * 100, 1) ?>%</td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============ KINERJA KASIR ============ -->
<div class="card">
  <div class="card-head">
    <h3>Perbandingan Kinerja Kasir</h3>
    <button class="btn btn-sm" type="button" onclick="Naveena.chartPng('chartCashier','kinerja-kasir')"><?= icon('download') ?> PNG</button>
  </div>
  <div class="card-body">
    <?php if (!$cashiersPerf): ?><?= empty_state('Belum ada transaksi pada periode ini.') ?><?php else: ?>
      <div class="chart-box"><canvas id="chartCashier" data-chart="Kinerja Kasir"></canvas></div>
      <div class="table-wrap mt-2">
        <table class="tbl">
          <thead><tr><th>Kasir</th><th class="num">Transaksi</th><th class="num">Pendapatan</th><th class="num">Treatment</th><th class="num">Skincare</th><th class="num">Rata-rata/Transaksi</th></tr></thead>
          <tbody>
          <?php foreach ($cashiersPerf as $c): ?>
            <tr>
              <td><?= e($c['nama']) ?></td>
              <td class="num"><?= num($c['trx']) ?></td>
              <td class="num"><?= money($c['total']) ?></td>
              <td class="num"><?= money($c['tr']) ?></td>
              <td class="num"><?= money($c['sk']) ?></td>
              <td class="num"><?= money($c['trx'] > 0 ? $c['total'] / $c['trx'] : 0) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============ TABEL RINCI ============ -->
<div class="grid g2">
  <div class="card tight">
    <div class="card-head"><h3>Rincian Treatment</h3>
      <div class="flex gap-sm">
        <span class="muted"><?= num(count($perTreatment)) ?> item</span>
        <?php if (has_perm('export.data')): ?><a class="btn btn-sm" href="export.php?type=laporan_treatment&format=excel&<?= e(qs([], ['page','per_page'])) ?>">Excel</a><?php endif; ?>
      </div>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Treatment</th><th>Kategori</th><th class="num">Terjual</th><th class="num">Pendapatan</th><th class="num">%</th></tr></thead>
        <tbody>
        <?php if (!$perTreatment): ?><tr><td colspan="5" class="muted center">Belum ada penjualan treatment.</td></tr><?php endif; ?>
        <?php foreach ($perTreatment as $r): ?>
          <tr><td><?= e($r['nama']) ?><div class="small muted"><?= e($r['kode']) ?></div></td>
            <td class="small"><?= e($r['kategori']) ?></td>
            <td class="num"><?= qty_text($r['q']) ?></td>
            <td class="num"><?= money($r['s']) ?></td>
            <td class="num"><?= num($r['s'] / $trTot * 100, 1) ?>%</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card tight">
    <div class="card-head"><h3>Rincian Skincare</h3>
      <div class="flex gap-sm">
        <span class="muted"><?= num(count($perSkincare)) ?> item</span>
        <?php if (has_perm('export.data')): ?><a class="btn btn-sm" href="export.php?type=laporan_skincare&format=excel&<?= e(qs([], ['page','per_page'])) ?>">Excel</a><?php endif; ?>
      </div>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Produk</th><th>Kategori</th><th class="num">Terjual</th><th class="num">Penjualan</th><th class="num">%</th></tr></thead>
        <tbody>
        <?php if (!$perSkincare): ?><tr><td colspan="5" class="muted center">Belum ada penjualan skincare.</td></tr><?php endif; ?>
        <?php foreach ($perSkincare as $r): ?>
          <tr><td><?= e($r['nama']) ?><div class="small muted"><?= e($r['kode']) ?></div></td>
            <td class="small"><?= e($r['kategori']) ?></td>
            <td class="num"><?= qty_text($r['q']) ?></td>
            <td class="num"><?= money($r['s']) ?></td>
            <td class="num"><?= num($r['s'] / $skTot * 100, 1) ?>%</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card tight mt-2">
  <div class="card-head">
    <h3>Pemakaian Kartu Member</h3>
    <div class="flex gap-sm">
      <span class="muted"><?= num(count($perMember)) ?> tingkat</span>
      <?php if (has_perm('export.data')): ?>
        <a class="btn btn-sm" href="export.php?type=laporan_member&format=excel&<?= e(qs([], ['page','per_page'])) ?>">Excel</a>
        <a class="btn btn-sm" href="export.php?type=laporan_member&format=csv&<?= e(qs([], ['page','per_page'])) ?>">CSV</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body" style="border-bottom:1px solid var(--line)">
    <div class="notice">
      Diskon member dihitung <strong>otomatis</strong> dari nilai transaksi saat petugas memilih "Ada kartu member" di Order Baru.
      Aturannya: <?= e(member_rules_text()) ?>.
      <?php if (member_activate_amount() > 0): ?>
        Transaksi ≥ <strong><?= money(member_activate_amount()) ?></strong> otomatis mengaktifkan kartu member
        (level awal) <em>dan</em> potongannya langsung berlaku pada transaksi tersebut.
      <?php endif; ?>
      Akumulasi <strong><?= e(member_period_label()) ?></strong> ≥ <strong><?= money(member_base_level()['min_year']) ?></strong> juga membuka kartu member.
      Cakupan diskon: <strong><?= e(member_scope_text()) ?></strong>, minimal transaksi
      <strong><?= money(member_min_transaction()) ?></strong>.
    </div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Level Member</th><th>Cakupan Diskon</th><th class="num">Transaksi</th><th class="num">Nilai Transaksi</th><th class="num">Total Diskon</th><th class="num">Rata-rata Diskon</th></tr></thead>
      <tbody>
      <?php if (!$perMember): ?><tr><td colspan="6" class="muted center">Belum ada transaksi yang memakai kartu member pada periode ini.</td></tr><?php endif; ?>
      <?php foreach ($perMember as $r): ?>
        <tr>
          <td><?= e($r['tier']) ?></td>
          <td class="small"><?= e(member_scope_text((string)($r['scope'] ?? 'both'))) ?></td>
          <td class="num"><?= num($r['trx']) ?></td>
          <td class="num"><?= money($r['subtotal']) ?></td>
          <td class="num"><strong><?= money($r['disc']) ?></strong></td>
          <td class="num"><?= money((int)$r['trx'] > 0 ? (float)$r['disc'] / (int)$r['trx'] : 0) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th>TOTAL DISKON MEMBER</th><th></th>
        <th class="num"><?= num(array_sum(array_column($perMember, 'trx'))) ?></th>
        <th class="num"><?= money(array_sum(array_column($perMember, 'subtotal'))) ?></th>
        <th class="num"><?= money(array_sum(array_column($perMember, 'disc'))) ?></th><th></th></tr></tfoot>
    </table>
  </div>
</div>

<div class="card tight mt-2">
  <div class="card-head">
    <h3>Bahan Treatment Terpakai (tidak ditagihkan)</h3>
    <div class="flex gap-sm">
      <span class="muted"><?= num(count($perMaterials)) ?> jenis bahan</span>
      <?php if (has_perm('export.data')): ?>
        <a class="btn btn-sm" href="export.php?type=pemakaian_bahan&format=excel&<?= e(qs([], ['page','per_page'])) ?>">Excel</a>
        <a class="btn btn-sm" href="export.php?type=pemakaian_bahan&format=csv&<?= e(qs([], ['page','per_page'])) ?>">CSV</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body" style="border-bottom:1px solid var(--line)">
    <div class="notice">
      Bahan treatment dipakai sebagai <strong>pelengkap proses treatment</strong> dan <strong>tidak dijual</strong>
      ke pasien — karena itu pemakaiannya <strong>tidak menambah pendapatan</strong> pada laporan ini, hanya mengurangi
      <a href="inventory_movement.php?item_type=material">stok inventory</a>. Kolom nilai bahan hanya informasi biaya.
    </div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Bahan</th><th>Kode</th><th class="num">Jumlah Terpakai</th><th class="num">Transaksi</th><th class="num">Nilai Bahan</th><th></th></tr></thead>
      <tbody>
      <?php if (!$perMaterials): ?><tr><td colspan="6" class="muted center">Belum ada pemakaian bahan treatment pada periode ini.</td></tr><?php endif; ?>
      <?php foreach ($perMaterials as $r):
        $hrg = $r['material_id'] ? (float)scalar('SELECT price FROM treatment_materials WHERE id=?', [(int)$r['material_id']], 0) : 0; ?>
        <tr>
          <td><?= e($r['nama']) ?></td>
          <td class="small"><?= e($r['kode'] ?: '-') ?></td>
          <td class="num"><?= qty_text($r['q']) ?></td>
          <td class="num"><?= num($r['trx']) ?></td>
          <td class="num"><?= money($hrg * (float)$r['q']) ?></td>
          <td><?php if ($r['material_id']): ?><a class="btn btn-sm" href="bahan.php?action=stock&id=<?= (int)$r['material_id'] ?>">Stok</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($perMaterials): ?>
      <tfoot><tr><th colspan="2">TOTAL PEMAKAIAN (tidak ditagihkan)</th>
        <th class="num"><?= num(array_sum(array_column($perMaterials, 'q'))) ?></th>
        <th class="num"><?= num(array_sum(array_column($perMaterials, 'trx'))) ?></th><th colspan="2"></th></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="notice">
  Semua grafik dapat disimpan: tekan <strong>PNG</strong> pada tiap grafik, atau
  <strong>Unduh Semua Grafik (PNG)</strong> untuk mengunduh semuanya sekaligus.
  Untuk dokumen lengkap dalam satu berkas, gunakan
  <strong>Excel Lengkap + Grafik</strong> (.xlsx dengan 10 sheet data + 10 grafik, siap diedit di Excel)
  atau <strong>PDF Lengkap + Grafik</strong> (PDF asli berisi tabel &amp; grafik — sama dengan yang dikirim lewat email).
  <?php if (finance_report_include()): ?>
    <br>Khusus <strong>Direktur/Owner &amp; Super Admin</strong> tersedia juga
    <strong>Excel/PDF Lengkap + Keuangan + Grafik</strong> yang memuat HPP, laba kotor, biaya operasional,
    dan laba bersih beserta grafik laba bersih.<br>
    <?php endif; ?>
  Keduanya mengikuti filter penerapan yang sama dengan tampilan layar.
</div>

<?php
$PAGE_SCRIPTS = [];
ob_start();
?>
<script src="assets/vendor/chart.umd.min.js"></script>
<script><?= income_charts_js() ?></script>
<script>
(function () {
  var money = function (v) { return 'Rp ' + Number(v || 0).toLocaleString('id-ID'); };
  var yMoney = { beginAtZero: true, ticks: { callback: function (v) { return 'Rp ' + Number(v).toLocaleString('id-ID'); } } };
  var legendBottom = { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } };
  /* Warna seri: palet kategorikal kontras tinggi (Naveena.paletteFor(n)) untuk
     seri yang banyak — mis. garis perbandingan antar cabang — dan warna seri
     bermakna (Treatment/Skincare/Total) yang diatur di Pengaturan → Warna Grafik. */
  var S = { tr: Naveena.series('treatment'), sk: Naveena.series('skincare'), tot: Naveena.series('total') };

  /* --- progres bulanan: DUA grafik (batang berjajaran + total terpisah) ---
     Susunannya sama dengan dashboard & dokumen cetak (includes/chart_js.php). */
  NaveenaIncome({
    bars: 'chartMonthly', total: 'chartMonthlyTotal',
    labels: <?= js_json($monthly['labels']) ?>,
    tr: <?= js_json($monthly['tr']) ?>,
    sk: <?= js_json($monthly['sk']) ?>,
    totalData: <?= js_json($monthly['total']) ?>,
    trColor: S.tr, skColor: S.sk,
    totalColor: S.tot, totalFill: Naveena.rgba(S.tot, .14),
    money: money, short: Naveena.rupiahShort
  });

  <?php if (is_owner_level() && $monthly['branches']): ?>
  /* --- perbandingan progres antar cabang ---
     PENTING: warna diambil dengan Naveena.paletteFor(jumlah cabang) sehingga
     SETIAP cabang mendapat warna berbeda yang kontras (dulu `palette[i % 10]`
     dengan palet satu keluarga warna → garis ke-1..ke-3 tampak sama, dan
     cabang ke-11 mengulang warna cabang ke-1). Titik diberi garis tepi putih
     supaya tetap terlihat saat dua garis berpotongan. */
  (function () {
    var dat = <?= js_json(array_map(function ($name, $series) {
        return ['label' => branch_short_label((string)$name), 'data' => $series, 'tension' => .3,
                'borderWidth' => 2.4, 'pointRadius' => 3, 'pointBorderWidth' => 1.2, 'pointBorderColor' => '#fff',
                'pointBackgroundColor' => ''];
    }, array_keys($monthly['branches']), array_values($monthly['branches']))) ?>;
    var cols = Naveena.paletteFor(dat.length);
    dat.forEach(function (d, i) {
      d.borderColor = cols[i]; d.pointBackgroundColor = cols[i]; d.backgroundColor = 'transparent';
      d.pointHoverRadius = 5; d.borderDash = [];
      /* Bila cabangnya sangat banyak (>8), garis ganjil diberi pola putus-putus
         supaya dua garis yang berdekatan tetap dapat dibedakan walau warnanya
         (pada layar tertentu) tampak mirip. */
      if (dat.length > 8 && i % 2 === 1) d.borderDash = [6, 4];
    });
    Naveena.chart('chartMonthlyBranch', {
      type: 'line',
      data: { labels: <?= js_json($monthly['labels']) ?>, datasets: dat },
      options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: legendBottom, scales: { y: yMoney } }
    });
  })();
  <?php endif; ?>

  <?php if ($finSeries): ?>
  /* --- LABA BERSIH (khusus owner): omzet − HPP [- biaya operasional] per bucket --- */
  Naveena.chart('chartLaba', {
    type: 'bar',
    data: {
      labels: <?= js_json($finSeries['labels']) ?>,
      datasets: [{ label: 'Laba Bersih', data: <?= js_json($finSeries['laba']) ?>,
        backgroundColor: <?= js_json($finSeries['laba']) ?>.map(function (v) {
          return v >= 0 ? Naveena.series('positif') : Naveena.series('negatif'); }),
        borderRadius: 5, maxBarThickness: 30 }]
    },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false },
        tooltip: { callbacks: { label: function (c) { return 'Laba bersih: ' + money(c.parsed.y); } } } },
      scales: { x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0, font: { size: 10 } } },
        y: { ticks: { callback: function (v) { return Naveena.rupiahShort(v); }, font: { size: 10 } }, grid: { drawTicks: false } } } }
  });
  <?php endif; ?>

  /* --- komposisi --- */
  Naveena.chart('chartComposition', {
    type: 'doughnut',
    data: {
      labels: ['Treatment', 'Skincare'],
      datasets: [{ data: [<?= (float)$tot['tr'] ?>, <?= (float)$tot['sk'] ?>], backgroundColor: [S.tr, S.sk], borderWidth: 2, borderColor: '#fff' }]
    },
    options: { responsive: true, maintainAspectRatio: false, cutout: '58%',
      plugins: { legend: { position: 'bottom' },
        tooltip: { callbacks: { label: function (c) {
          var t = c.dataset.data.reduce(function (a, b) { return a + b; }, 0) || 1;
          return c.label + ': ' + money(c.parsed) + ' (' + (c.parsed / t * 100).toFixed(1) + '%)';
        } } } } }
  });

  /* --- metode pembayaran --- */
  <?php if ($methods): ?>
  Naveena.chart('chartMethod', {
    type: 'pie',
    data: {
      labels: <?= js_json(array_column($methods, 'method')) ?>,
      datasets: [{ data: <?= js_json(array_map(fn($m) => (float)$m['total'], $methods)) ?>,
        backgroundColor: Naveena.paletteFor(<?= count($methods) ?>), borderWidth: 2, borderColor: '#fff' }]
    },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
        tooltip: { callbacks: { label: function (c) { return c.label + ': ' + money(c.parsed); } } } } }
  });
  <?php endif; ?>

  /* --- per cabang: batang & lingkaran --- */
  var brLabels = <?= js_json(array_map(fn($b) => branch_short_label((string)$b['name']), $perBranch)) ?>;
  Naveena.chart('chartBranchBar', {
    type: 'bar',
    data: {
      labels: brLabels,
      datasets: [
        { label: 'Treatment', data: <?= js_json(array_map(fn($b) => (float)$b['tr'], $perBranch)) ?>, backgroundColor: S.tr, borderRadius: 5 },
        { label: 'Skincare', data: <?= js_json(array_map(fn($b) => (float)$b['sk'], $perBranch)) ?>, backgroundColor: S.sk, borderRadius: 5 }
      ]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: legendBottom, scales: { y: yMoney } }
  });
  Naveena.chart('chartBranchPie', {
    type: 'doughnut',
    data: {
      labels: brLabels,
      datasets: [{ data: <?= js_json(array_map(fn($b) => (float)$b['total'], $perBranch)) ?>,
        backgroundColor: Naveena.paletteFor(brLabels.length), borderWidth: 2, borderColor: '#fff' }]
    },
    options: { responsive: true, maintainAspectRatio: false, cutout: '52%',
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
        tooltip: { callbacks: { label: function (c) {
          var t = c.dataset.data.reduce(function (a, b) { return a + b; }, 0) || 1;
          return c.label + ': ' + money(c.parsed) + ' (' + (c.parsed / t * 100).toFixed(1) + '%)';
        } } } } }
  });

  /* --- tren harian/bulanan: DUA grafik (batang berjajaran + total terpisah) ---
     Dulu Treatment & Skincare ditumpuk ke atas dan garis Total ikut tertumpuk
     sehingga nilainya berlipat dan garisnya keluar dari area grafik (tidak terlihat). */
  NaveenaIncome({
    bars: 'chartDaily', total: 'chartDailyTotal',
    labels: <?= js_json($daily['labels']) ?>,
    tr: <?= js_json($daily['tr']) ?>,
    sk: <?= js_json($daily['sk']) ?>,
    totalData: <?= js_json($daily['total']) ?>,
    trColor: S.tr, skColor: S.sk,
    totalColor: S.tot, totalFill: Naveena.rgba(S.tot, .14),
    money: money, short: Naveena.rupiahShort
  });

  /* --- top 5: batang + lingkaran --- */
  function topCharts(barId, pieId, rows) {
    if (!rows.length) return;
    Naveena.chart(barId, {
      type: 'bar',
      data: { labels: rows.map(function (r) { return r.nama; }),
        datasets: [{ label: 'Nilai', data: rows.map(function (r) { return Number(r.s); }),
          backgroundColor: Naveena.paletteFor(rows.length), borderRadius: 5 }] },
      options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: Object.assign({ legend: { display: false },
          tooltip: { callbacks: { label: function (c) { return money(c.parsed.x); } } } }, {}),
        scales: { x: { beginAtZero: true, ticks: { callback: function (v) { return 'Rp ' + Number(v).toLocaleString('id-ID'); } } } } }
    });
    Naveena.chart(pieId, {
      type: 'pie',
      data: { labels: rows.map(function (r) { return r.nama; }),
        datasets: [{ data: rows.map(function (r) { return Number(r.s); }), backgroundColor: Naveena.paletteFor(rows.length), borderWidth: 2, borderColor: '#fff' }] },
      options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
          tooltip: { callbacks: { label: function (c) {
            var t = c.dataset.data.reduce(function (a, b) { return a + b; }, 0) || 1;
            return c.label + ': ' + money(c.parsed) + ' (' + (c.parsed / t * 100).toFixed(1) + '%)';
          } } } } }
    });
  }
  topCharts('chartTopTr', 'pieTopTr', <?= js_json(array_map(fn($r) => ['nama' => $r['nama'], 's' => (float)$r['s']], $topTr)) ?>);
  topCharts('chartTopSk', 'pieTopSk', <?= js_json(array_map(fn($r) => ['nama' => $r['nama'], 's' => (float)$r['s']], $topSk)) ?>);

  /* --- kasir --- */
  <?php if ($cashiersPerf): ?>
  Naveena.chart('chartCashier', {
    type: 'bar',
    data: {
      labels: <?= js_json(array_map(fn($c) => $c['nama'], $cashiersPerf)) ?>,
      datasets: [
        { label: 'Pendapatan', data: <?= js_json(array_map(fn($c) => (float)$c['total'], $cashiersPerf)) ?>, backgroundColor: S.tr, borderRadius: 5 },
        { label: 'Jumlah Transaksi', data: <?= js_json(array_map(fn($c) => (int)$c['trx'], $cashiersPerf)) ?>, type: 'line',
          borderColor: S.sk, backgroundColor: 'transparent', tension: .3, yAxisID: 'y1', pointRadius: 3 }
      ]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: legendBottom,
      scales: { y: yMoney, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } } } }
  });
  <?php endif; ?>
})();
</script>
<?php
$PAGE_SCRIPTS[] = ob_get_clean();
page_foot();
