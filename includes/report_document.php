<?php
/**
 * Dokumen laporan lengkap siap cetak/simpan PDF: ringkasan angka, grafik
 * (garis, batang, lingkaran), dan tabel rinci dalam satu berkas.
 *
 * Grafik dirender Chart.js dengan animation:false lalu dokumen otomatis
 * membuka dialog cetak (pilih "Save as PDF"). Diuji bahwa canvas ikut tercetak.
 */
declare(strict_types=1);

// brand_block()/icon() ada di layout.php — tanpa ini dokumen terpotong
// (fatal error) dan halaman terlihat "hanya header".
require_once __DIR__ . '/layout.php';
// helper grafik tren (income_charts_js) — dipakai juga oleh dashboard & laporan
require_once __DIR__ . '/chart_js.php';

function render_report_document(array $B, array $user): void
{
    $f   = $B['filters'];
    $tot = $B['totals'];
    $scopeName = $f['scope'] === null ? 'Semua Cabang' : (string)scalar('SELECT name FROM branches WHERE id=?', [$f['scope']], '-');
    $perBranch = $B['branches'];
    $perTr = $B['treatments'];
    $perSk = $B['skincares'];
    $perMat = $B['materials'] ?? [];   // pemakaian bahan treatment (tidak dijual)
    $daily = $B['daily'];
    $monthly = $B['monthly'];
    $methods = $B['methods'];
    $cashiers = $B['cashiers'];
    $topTr = array_slice($perTr, 0, 5);
    $topSk = array_slice($perSk, 0, 5);
    $trTot = array_sum(array_column($perTr, 's')) ?: 1;
    $skTot = array_sum(array_column($perSk, 's')) ?: 1;
    $branchSum = array_sum(array_column($perBranch, 'total')) ?: 1;

    $topPatients = [];
    if (has_perm('report.view')) {
        $bs = $f['scope'] === null ? '' : ' AND o.branch_id = ?';
        $bp = $f['scope'] === null ? [] : [$f['scope']];
        $topPatients = all("SELECT p.name, p.patient_number, p.member_number, b.name AS branch_name,
                                   COUNT(DISTINCT o.id) trx, COALESCE(SUM(o.total),0) total
                            FROM orders o JOIN patients p ON p.id=o.patient_id JOIN branches b ON b.id=o.branch_id
                            WHERE o.status='paid' AND date(o.created_at) BETWEEN ? AND ? {$bs}
                            GROUP BY p.id ORDER BY trx DESC, total DESC LIMIT 10",
            array_merge([$f['ps'], $f['pe']], $bp));
    }
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laporan Lengkap <?= e(clinic_name()) ?> — <?= e(tgl($f['ps'])) ?> s.d. <?= e(tgl($f['pe'])) ?></title>
<link rel="stylesheet" href="assets/css/app.css">
<style id="themeVars"><?= theme_css() ?></style>
<style>
  body{background:#fff;padding:26px;color:#2B1B27}
  .doc-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;
    border-bottom:3px solid #C2185B;padding-bottom:14px;margin-bottom:20px}
  .doc-head h1{margin:6px 0 4px;font-size:1.35rem;color:#8E0E42}
  .doc-meta{font-size:.8rem;color:#635F82;text-align:right;line-height:1.5}
  .doc-section{margin-top:26px;page-break-inside:avoid}
  .doc-section > h2{font-size:1rem;color:#8E0E42;margin:0 0 10px;padding-bottom:6px;border-bottom:1px solid #F0DDE7}
  .kpi{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
  .kpi .box{border:1px solid #F0DDE7;border-radius:10px;padding:10px 12px;background:#FFFAFC}
  .kpi .lbl{font-size:.66rem;text-transform:uppercase;letter-spacing:.07em;color:#635F82;font-weight:700}
  .kpi .val{font-size:1.12rem;font-weight:750;margin-top:2px}
  .kpi .sub{font-size:.7rem;color:#635F82}
  .chart-grid{display:grid;gap:16px}
  .chart-grid.two{grid-template-columns:1fr 1fr}
  .chart-grid.three{grid-template-columns:repeat(3,1fr)}
  .chart-card{border:1px solid #F0DDE7;border-radius:12px;padding:12px;background:#fff;page-break-inside:avoid}
  .chart-card h3{font-size:.82rem;margin:0 0 8px;color:#8E0E42}
  .cbox{position:relative;height:230px}
  .cbox.tall{height:280px}
  .cbox.small{height:200px}
  table.tbl th{background:#FDF2F7!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  tfoot th{background:#FDF2F7}
  .no-print{margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  /* Dokumen ini juga sering dibuka langsung di HP (tombol "Cetak / Simpan PDF").
     Tanpa penyesuaian berikut, dokumen selebar A4 tampil berdesakan di layar HP. */
  @media (max-width:900px){
    body{padding:14px}
    .doc-head{flex-direction:column;gap:10px}
    .doc-meta{text-align:left}
    .kpi{grid-template-columns:1fr}
    .chart-grid.two,.chart-grid.three{grid-template-columns:1fr}
    .cbox{height:210px}
    .cbox.tall{height:240px}
  }
  @media print{
    body{padding:0}
    .no-print{display:none!important}
    @page{size:A4 landscape;margin:10mm}
    .chart-card,.doc-section,.kpi .box{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  }
</style>
</head>
<body>
<div class="no-print">
  <button class="btn btn-primary" onclick="window.print()">Cetak / Simpan sebagai PDF</button>
  <a class="btn" href="laporan.php?<?= e(qs()) ?>">Kembali ke Laporan</a>
  <span class="muted">Pada dialog cetak pilih tujuan <strong>Save as PDF</strong> agar grafik ikut tersimpan.</span>
</div>

<div class="doc-head">
  <div>
    <?= brand_block() ?>
    <h1>Laporan Lengkap Klinik</h1>
    <div class="muted" style="font-size:.82rem">
      Periode <strong><?= e(tgl($f['ps'])) ?></strong> — <strong><?= e(tgl($f['pe'])) ?></strong>
      · <?= e($scopeName) ?> · status transaksi <strong><?= e($f['status']) ?></strong>
    </div>
  </div>
  <div class="doc-meta">
    Dicetak: <?= e(tglIndo(date('Y-m-d'))) ?> <?= date('H:i') ?><br>
    Oleh: <?= e($user['name']) ?> (<?= e($user['role_name']) ?>)<br>
    <?= e(clinic_name()) ?><br>
    <?= e(setting('company_address')) ?>
  </div>
</div>

<!-- ================= RINGKASAN ================= -->
<div class="doc-section">
  <h2>1. Ringkasan Periode</h2>
  <div class="kpi">
    <div class="box"><div class="lbl">Total Pendapatan</div><div class="val"><?= money($tot['total']) ?></div>
      <div class="sub"><?= num($tot['trx']) ?> transaksi · rata-rata <?= money($tot['avg']) ?></div></div>
    <div class="box"><div class="lbl">Pendapatan Treatment</div><div class="val"><?= money($tot['tr']) ?></div>
      <div class="sub"><?= num($tot['tr_q']) ?> treatment · <?= num($tot['total'] > 0 ? $tot['tr'] / $tot['total'] * 100 : 0, 1) ?>%</div></div>
    <div class="box"><div class="lbl">Penjualan Skincare</div><div class="val"><?= money($tot['sk']) ?></div>
      <div class="sub"><?= num($tot['sk_q']) ?> produk · <?= num($tot['total'] > 0 ? $tot['sk'] / $tot['total'] * 100 : 0, 1) ?>%</div></div>
    <div class="box"><div class="lbl">Total Subtotal</div><div class="val"><?= money($tot['subtotal']) ?></div>
      <div class="sub">sebelum diskon</div></div>
    <div class="box"><div class="lbl">Total Diskon</div><div class="val"><?= money($tot['disc']) ?></div>
      <div class="sub"><?= num($tot['subtotal'] > 0 ? $tot['disc'] / $tot['subtotal'] * 100 : 0, 1) ?>% dari subtotal</div></div>
    <div class="box"><div class="lbl">Pembayaran Valid</div><div class="val"><?= money($tot['pay_total']) ?></div>
      <div class="sub"><?= num($tot['pay_n']) ?> pembayaran</div></div>
  </div>
</div>

<!-- ================= PROGRES ================= -->
<div class="doc-section">
  <h2>2. Progres <?= $monthly['granularity'] === 'harian' ? 'Harian' : 'Bulanan' ?> (<?= num($monthly['buckets']) ?> <?= e($monthly['label_suffix']) ?>)
    <?php if ($monthly['growth'] !== null): ?>
      <span class="badge badge-<?= $monthly['growth'] >= 0 ? 'green' : 'red' ?>">
        <?= $monthly['growth'] >= 0 ? 'naik' : 'turun' ?> <?= num(abs($monthly['growth']), 1) ?>% vs bulan sebelumnya
      </span>
    <?php endif; ?>
  </h2>
  <?php /* Satu grafik per baris (lebar penuh) — sama seperti di layar, supaya
     label periode tidak berdesakan pada laporan 3 bulan/tahun. */ ?>
  <div class="chart-grid">
    <div class="chart-card"><h3>Pendapatan per Bulan — Treatment vs Skincare</h3>
      <div class="cbox tall"><canvas id="cMonthly" data-chart="Progres Treatment &amp; Skincare"></canvas></div>
      <h3 style="margin-top:10px">Total Pendapatan per Bulan</h3>
      <div class="cbox"><canvas id="cMonthlyTotal" data-chart="Progres Total"></canvas></div></div>
    <div class="chart-card"><h3>Pertumbuhan Dibanding Bulan Sebelumnya (%)</h3>
      <div class="cbox"><canvas id="cGrowth" data-chart="Pertumbuhan Bulanan"></canvas></div></div>
  </div>
  <div class="table-wrap mt-2">
    <table class="tbl">
      <thead><tr><th><?= $monthly['granularity'] === 'harian' ? 'Tanggal' : 'Bulan' ?></th><th class="num">Transaksi</th><th class="num">Treatment</th><th class="num">Skincare</th><th class="num">Total</th><th class="num">Perubahan</th></tr></thead>
      <tbody>
      <?php foreach ($monthly['labels_full'] as $i => $lbl):
        $now = $monthly['total'][$i]; $prev = $i > 0 ? $monthly['total'][$i - 1] : 0;
        $chg = $prev > 0 ? (($now - $prev) / $prev) * 100 : null; ?>
        <tr>
          <td><?= e($lbl) ?></td>
          <td class="num"><?= num($monthly['trx'][$i]) ?></td>
          <td class="num"><?= money($monthly['tr'][$i]) ?></td>
          <td class="num"><?= money($monthly['sk'][$i]) ?></td>
          <td class="num"><strong><?= money($now) ?></strong></td>
          <td class="num"><?= $chg === null ? '<span class="muted">—</span>' : ($chg >= 0 ? '+' : '') . num($chg, 1) . '%' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th>TOTAL</th>
        <th class="num"><?= num(array_sum($monthly['trx'])) ?></th>
        <th class="num"><?= money(array_sum($monthly['tr'])) ?></th>
        <th class="num"><?= money(array_sum($monthly['sk'])) ?></th>
        <th class="num"><?= money($monthly['sum']) ?></th><th></th></tr></tfoot>
    </table>
  </div>
</div>

<?php if ($f['scope'] === null && $monthly['branches']): ?>
<div class="doc-section">
  <h2>3. Perbandingan Progres Antar Cabang</h2>
  <div class="chart-card"><h3>Tren Pendapatan Bulanan per Cabang</h3>
    <div class="cbox tall" style="height:300px"><canvas id="cMonthlyBranch" data-chart="Progres Per Cabang"></canvas></div></div>
</div>
<?php endif; ?>

<!-- ================= PERBANDINGAN ================= -->
<div class="doc-section">
  <h2><?= $f['scope'] === null && $monthly['branches'] ? '4' : '3' ?>. Perbandingan &amp; Komposisi</h2>
  <div class="chart-grid three">
    <div class="chart-card"><h3>Komposisi Pendapatan</h3><div class="cbox small"><canvas id="cComposition" data-chart="Komposisi Pendapatan"></canvas></div></div>
    <?php if ($methods): ?>
    <div class="chart-card"><h3>Metode Pembayaran</h3><div class="cbox small"><canvas id="cMethod" data-chart="Metode Pembayaran"></canvas></div></div>
    <?php endif; ?>
    <div class="chart-card"><h3>Kontribusi Cabang</h3><div class="cbox small"><canvas id="cBranchPie" data-chart="Kontribusi Cabang"></canvas></div></div>
  </div>
  <div class="chart-grid mt-2">
    <div class="chart-card"><h3>Treatment vs Skincare per Cabang</h3><div class="cbox"><canvas id="cBranchBar" data-chart="Perbandingan Cabang"></canvas></div></div>
    <div class="chart-card"><h3><?= $daily['by_month'] ? 'Pendapatan Bulanan' : 'Pendapatan Harian' ?> — Treatment vs Skincare</h3>
      <div class="cbox tall"><canvas id="cDaily" data-chart="Pergerakan Treatment &amp; Skincare"></canvas></div>
      <h3 style="margin-top:10px">Total Pendapatan pada Periode Ini</h3>
      <div class="cbox"><canvas id="cDailyTotal" data-chart="Pergerakan Total"></canvas></div></div>
  </div>
  <div class="table-wrap mt-2">
    <table class="tbl">
      <thead><tr><th>Cabang</th><th class="num">Transaksi</th><th class="num">Treatment</th><th class="num">Skincare</th><th class="num">Diskon</th><th class="num">Pendapatan</th><th class="num">Kontribusi</th></tr></thead>
      <tbody>
      <?php if (!$perBranch): ?><tr><td colspan="7" class="center muted">Belum ada data.</td></tr><?php endif; ?>
      <?php foreach ($perBranch as $b): ?>
        <tr><td><?= e($b['name']) ?></td><td class="num"><?= num($b['trx']) ?></td>
          <td class="num"><?= money($b['tr']) ?></td><td class="num"><?= money($b['sk']) ?></td>
          <td class="num"><?= money($b['disc']) ?></td><td class="num"><strong><?= money($b['total']) ?></strong></td>
          <td class="num"><?= num($b['total'] / $branchSum * 100, 1) ?>%</td></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($perBranch): ?>
      <tfoot><tr><th>TOTAL</th>
        <th class="num"><?= num(array_sum(array_column($perBranch, 'trx'))) ?></th>
        <th class="num"><?= money(array_sum(array_column($perBranch, 'tr'))) ?></th>
        <th class="num"><?= money(array_sum(array_column($perBranch, 'sk'))) ?></th>
        <th class="num"><?= money(array_sum(array_column($perBranch, 'disc'))) ?></th>
        <th class="num"><?= money(array_sum(array_column($perBranch, 'total'))) ?></th>
        <th class="num">100%</th></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<!-- ================= TOP 5 ================= -->
<div class="doc-section">
  <h2><?= $f['scope'] === null && $monthly['branches'] ? '5' : '4' ?>. Top 5 Penjualan</h2>
  <div class="chart-grid two">
    <div class="chart-card"><h3>Top 5 Treatment (nilai &amp; porsi)</h3><div class="cbox"><canvas id="cTopTr" data-chart="Top 5 Treatment"></canvas></div></div>
    <div class="chart-card"><h3>Top 5 Skincare (nilai &amp; porsi)</h3><div class="cbox"><canvas id="cTopSk" data-chart="Top 5 Skincare"></canvas></div></div>
  </div>
  <div class="chart-grid two mt-2">
    <div>
      <table class="tbl">
        <thead><tr><th>#</th><th>Treatment</th><th class="num">Terjual</th><th class="num">Pendapatan</th><th class="num">%</th></tr></thead>
        <tbody>
        <?php if (!$topTr): ?><tr><td colspan="5" class="center muted">Belum ada data.</td></tr><?php endif; ?>
        <?php foreach ($topTr as $i => $r): ?>
          <tr><td><?= $i + 1 ?></td><td><?= e($r['nama']) ?><div class="muted" style="font-size:.7rem"><?= e($r['kategori']) ?></div></td>
            <td class="num"><?= qty_text($r['q']) ?></td><td class="num"><?= money($r['s']) ?></td>
            <td class="num"><?= num($r['s'] / $trTot * 100, 1) ?>%</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div>
      <table class="tbl">
        <thead><tr><th>#</th><th>Produk Skincare</th><th class="num">Terjual</th><th class="num">Penjualan</th><th class="num">%</th></tr></thead>
        <tbody>
        <?php if (!$topSk): ?><tr><td colspan="5" class="center muted">Belum ada data.</td></tr><?php endif; ?>
        <?php foreach ($topSk as $i => $r): ?>
          <tr><td><?= $i + 1 ?></td><td><?= e($r['nama']) ?><div class="muted" style="font-size:.7rem"><?= e($r['kategori']) ?></div></td>
            <td class="num"><?= qty_text($r['q']) ?></td><td class="num"><?= money($r['s']) ?></td>
            <td class="num"><?= num($r['s'] / $skTot * 100, 1) ?>%</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ================= KASIR ================= -->
<?php if ($cashiers): ?>
<div class="doc-section">
  <h2><?= $f['scope'] === null && $monthly['branches'] ? '6' : '5' ?>. Kinerja Kasir</h2>
  <div class="chart-card"><h3>Pendapatan &amp; Jumlah Transaksi per Kasir</h3>
    <div class="cbox"><canvas id="cCashier" data-chart="Kinerja Kasir"></canvas></div></div>
  <div class="table-wrap mt-2">
    <table class="tbl">
      <thead><tr><th>Kasir</th><th class="num">Transaksi</th><th class="num">Treatment</th><th class="num">Skincare</th><th class="num">Pendapatan</th><th class="num">Rata-rata/Transaksi</th></tr></thead>
      <tbody>
      <?php foreach ($cashiers as $c): ?>
        <tr><td><?= e($c['nama']) ?></td><td class="num"><?= num($c['trx']) ?></td>
          <td class="num"><?= money($c['tr']) ?></td><td class="num"><?= money($c['sk']) ?></td>
          <td class="num"><strong><?= money($c['total']) ?></strong></td>
          <td class="num"><?= money($c['trx'] > 0 ? $c['total'] / $c['trx'] : 0) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ================= TOP PASIEN ================= -->
<?php if ($topPatients): ?>
<div class="doc-section">
  <h2><?= $f['scope'] === null && $monthly['branches'] ? '7' : '6' ?>. Top 10 Pasien (Periode Ini)</h2>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>#</th><th>Nama Pasien</th><th>No. Member</th><th class="num">Kunjungan</th><th class="num">Jumlah Transaksi</th><th class="num">Total Transaksi</th><th>Cabang</th></tr></thead>
      <tbody>
      <?php foreach ($topPatients as $i => $r): ?>
        <tr><td><?= $i + 1 ?></td>
          <td><?= e($r['name']) ?><div class="muted" style="font-size:.7rem"><?= e($r['patient_number']) ?></div></td>
          <td><?= e($r['member_number'] ?: '-') ?></td>
          <td class="num"><?= num($r['trx']) ?></td>
          <td class="num"><?= num($r['trx']) ?></td>
          <td class="num"><strong><?= money($r['total']) ?></strong></td>
          <td><?= e($r['branch_name']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ================= RINCIAN ================= -->
<div class="doc-section">
  <h2><?= $f['scope'] === null && $monthly['branches'] ? '8' : '7' ?>. Rincian Penjualan per Item</h2>
  <div class="chart-grid two">
    <div>
      <h3 style="font-size:.82rem;color:#8E0E42">Treatment (<?= num(count($perTr)) ?> item)</h3>
      <div class="table-wrap"><table class="tbl">
        <thead><tr><th>Treatment</th><th>Kategori</th><th class="num">Terjual</th><th class="num">Pendapatan</th><th class="num">%</th></tr></thead>
        <tbody>
        <?php if (!$perTr): ?><tr><td colspan="5" class="center muted">Belum ada data.</td></tr><?php endif; ?>
        <?php foreach ($perTr as $r): ?>
          <tr><td><?= e($r['nama']) ?></td><td class="muted" style="font-size:.72rem"><?= e($r['kategori']) ?></td>
            <td class="num"><?= qty_text($r['q']) ?></td><td class="num"><?= money($r['s']) ?></td>
            <td class="num"><?= num($r['s'] / $trTot * 100, 1) ?>%</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <div>
      <h3 style="font-size:.82rem;color:#8E0E42">Skincare (<?= num(count($perSk)) ?> item)</h3>
      <div class="table-wrap"><table class="tbl">
        <thead><tr><th>Produk</th><th>Kategori</th><th class="num">Terjual</th><th class="num">Penjualan</th><th class="num">%</th></tr></thead>
        <tbody>
        <?php if (!$perSk): ?><tr><td colspan="5" class="center muted">Belum ada data.</td></tr><?php endif; ?>
        <?php foreach ($perSk as $r): ?>
          <tr><td><?= e($r['nama']) ?></td><td class="muted" style="font-size:.72rem"><?= e($r['kategori']) ?></td>
            <td class="num"><?= qty_text($r['q']) ?></td><td class="num"><?= money($r['s']) ?></td>
            <td class="num"><?= num($r['s'] / $skTot * 100, 1) ?>%</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <?php if ($methods): ?>
  <div class="table-wrap mt-2">
    <table class="tbl">
      <thead><tr><th>Metode Pembayaran</th><th class="num">Jumlah Pembayaran</th><th class="num">Total</th><th class="num">Porsi</th></tr></thead>
      <tbody>
      <?php foreach ($methods as $m): $pt = $tot['pay_total'] > 0 ? $m['total'] / $tot['pay_total'] * 100 : 0; ?>
        <tr><td><?= e($m['method']) ?></td><td class="num"><?= num($m['n']) ?></td>
          <td class="num"><?= money($m['total']) ?></td><td class="num"><?= num($pt, 1) ?>%</td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ================= KEUANGAN (khusus owner) =================
   HPP, laba kotor, biaya operasional, dan laba bersih bersifat INTERNAL:
   blok ini hanya dirender untuk level owner (Super Admin & Direktur). */
$docFin = null;
$docFinSeries = null;
if (finance_report_include()) {
    $docFin = finance_summary($f, finance_mode() === 'lengkap', $f['scope']);
    $docFinSeries = finance_series($f);
}
?>
<?php if ($docFin): ?>
<div class="doc-section">
  <h2><?= $f['scope'] === null && $monthly['branches'] ? '9' : '8' ?>. Keuangan — HPP, Laba Kotor &amp; Laba Bersih
    <span class="badge badge-yellow">Internal</span></h2>
  <p class="muted" style="font-size:.78rem;margin:0 0 8px">
    Mode <?= e(finance_mode_label($docFin['mode'])) ?>. Omzet dihitung dari nilai barang (treatment + skincare)
    dikurangi diskon transaksi &amp; diskon member; kode unik transfer/QRIS tidak dihitung.
    <?= $docFin['mode'] === 'lengkap'
        ? 'Biaya operasional diprorata sesuai periode pembayarannya (' . num((int)$docFin['biaya_days']) . ' hari pada rentang ini).'
        : 'Biaya operasional belum dihitung pada mode ini.' ?>
  </p>
  <div class="kpi">
    <div class="box"><span class="lbl">Total Pendapatan (Omzet)</span><span class="val"><?= money($docFin['omzet']) ?></span></div>
    <div class="box"><span class="lbl">Total HPP</span><span class="val"><?= money($docFin['hpp_total']) ?></span></div>
    <?php if ($docFin['mode'] === 'lengkap'): ?>
      <div class="box"><span class="lbl">Laba Kotor</span><span class="val"><?= money($docFin['laba_kotor']) ?></span></div>
      <div class="box"><span class="lbl">Total Biaya Operasional</span><span class="val"><?= money($docFin['biaya_total']) ?></span></div>
    <?php endif; ?>
    <div class="box"><span class="lbl">LABA BERSIH</span><span class="val"><?= money($docFin['laba_bersih']) ?></span></div>
  </div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Komponen</th><th class="num">Nilai</th></tr></thead>
    <tbody>
      <tr><td>Pendapatan Treatment</td><td class="num"><?= money($docFin['pendapatan_treatment']) ?></td></tr>
      <tr><td>Pendapatan Skincare</td><td class="num"><?= money($docFin['pendapatan_skincare']) ?></td></tr>
      <tr><td>Pendapatan Paket</td><td class="num"><?= money($docFin['pendapatan_paket']) ?></td></tr>
      <tr><td>Pengurangan Diskon Transaksi</td><td class="num">− <?= money($docFin['diskon']) ?></td></tr>
      <tr><td>Pengurangan Diskon Member</td><td class="num">− <?= money($docFin['diskon_member']) ?></td></tr>
      <tr><th>Total Pendapatan (Omzet)</th><th class="num"><?= money($docFin['omzet']) ?></th></tr>
      <tr><td>HPP Treatment</td><td class="num">− <?= money($docFin['hpp_treatment']) ?></td></tr>
      <tr><td>HPP Produk</td><td class="num">− <?= money($docFin['hpp_produk']) ?></td></tr>
      <tr><td>HPP Paket</td><td class="num">− <?= money($docFin['hpp_paket']) ?></td></tr>
      <?php if ($docFin['mode'] === 'lengkap'): ?>
        <tr><th>Laba Kotor</th><th class="num"><?= money($docFin['laba_kotor']) ?></th></tr>
        <?php foreach ($docFin['biaya_rows'] as $c): ?>
          <tr><td><?= e($c['name']) ?> <span class="muted" style="font-size:.72rem">(<?= e($c['period_label']) ?>
            · <?= e($c['scope_label'] ?? 'Semua cabang') ?> · <?= money($c['amount']) ?>)</span>
            <?php if (!empty($c['breakdown']) && count($c['parts'] ?? []) > 1): ?>
              <div class="muted" style="font-size:.7rem">= <?= e($c['breakdown']) ?></div>
            <?php endif; ?></td>
            <td class="num">− <?= money($c['share']) ?></td></tr>
        <?php endforeach; ?>
        <tr><th>Total Biaya Operasional</th><th class="num">− <?= money($docFin['biaya_total']) ?></th></tr>
      <?php endif; ?>
      <tr><th>LABA BERSIH</th><th class="num"><?= money($docFin['laba_bersih']) ?></th></tr>
    </tbody>
  </table></div>
  <?php if ($docFinSeries && $docFinSeries['branches']): ?>
    <div class="table-wrap mt-2"><table class="tbl">
      <thead><tr><th>Cabang</th><th class="num">Omzet</th><th class="num">HPP Treatment</th>
        <th class="num">HPP Produk</th><?php if ($docFin['mode'] === 'lengkap'): ?><th class="num">Biaya Operasional</th><?php endif; ?>
        <th class="num">Laba Bersih</th></tr></thead>
      <tbody>
      <?php foreach (finance_per_branch($f) as $b): ?>
        <tr><td><?= e(branch_short_label($b['branch'])) ?></td>
          <td class="num"><?= money($b['omzet']) ?></td>
          <td class="num"><?= money($b['hpp_treatment']) ?></td>
          <td class="num"><?= money($b['hpp_produk']) ?></td>
          <?php if ($docFin['mode'] === 'lengkap'): ?><td class="num">− <?= money($b['biaya_total']) ?></td><?php endif; ?>
          <td class="num"><strong><?= money($b['laba_bersih']) ?></strong></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php /* ================= KARTU MEMBER ================= */
$perMemberDoc = $B['member_usage'] ?? []; ?>
<?php if (!empty($perMemberDoc)): ?>
<div class="doc-section">
  <h2><?= $f['scope'] === null && $monthly['branches'] ? '9' : '8' ?>. Pemakaian Kartu Member (diskon otomatis)</h2>
  <p class="muted" style="font-size:.78rem;margin:0 0 8px">
    Diskon member dihitung otomatis dari nilai transaksi saat petugas memilih "Ada kartu member".
    Aturan berlaku: <?= e(member_rules_text()) ?>.
  </p>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Level Member</th><th>Cakupan Diskon</th><th class="num">Transaksi</th><th class="num">Nilai Transaksi</th><th class="num">Total Diskon</th></tr></thead>
    <tbody>
    <?php foreach ($perMemberDoc as $r): ?>
      <tr><td><?= e($r['tier']) ?></td>
        <td class="small"><?= e(member_scope_text((string)($r['scope'] ?? 'both'))) ?></td>
        <td class="num"><?= num($r['trx']) ?></td>
        <td class="num"><?= money($r['subtotal']) ?></td><td class="num"><?= money($r['disc']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th>TOTAL DISKON MEMBER</th><th></th>
      <th class="num"><?= num(array_sum(array_column($perMemberDoc, 'trx'))) ?></th>
      <th class="num"><?= money(array_sum(array_column($perMemberDoc, 'subtotal'))) ?></th>
      <th class="num"><?= money(array_sum(array_column($perMemberDoc, 'disc'))) ?></th></tr></tfoot>
  </table></div>
</div>
<?php endif; ?>

<?php /* ================= PEMAKAIAN BAHAN TREATMENT =================
   Bahan treatment tidak dijual ke pasien sehingga tidak masuk pendapatan;
   bagian ini hanya mencatat pemakaian + nilai biaya bahannya. */ ?>
<?php if (!empty($perMat)): ?>
<div class="doc-section">
  <h2><?= $f['scope'] === null && $monthly['branches'] ? '9' : '8' ?>. Pemakaian Bahan Treatment (tidak ditagihkan)</h2>
  <div class="card-body" style="padding:0 0 10px">
    <p class="muted" style="font-size:.78rem;margin:0">
      Bahan treatment dipakai sebagai pelengkap proses treatment dan <strong>tidak dijual</strong> ke pasien —
      tidak muncul di struk dan <strong>tidak menambah pendapatan</strong> pada laporan ini. Kolom nilai di bawah
      hanya informasi biaya (harga master bahan × jumlah pakai).
    </p>
  </div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Bahan</th><th>Kode</th><th class="num">Jumlah Terpakai</th><th class="num">Transaksi</th><th class="num">Nilai Bahan</th></tr></thead>
    <tbody>
    <?php foreach ($perMat as $r): $hrg = $r['material_id'] ? (float)scalar('SELECT price FROM treatment_materials WHERE id=?', [(int)$r['material_id']], 0) : 0; ?>
      <tr><td><?= e($r['nama']) ?></td><td class="muted" style="font-size:.72rem"><?= e($r['kode'] ?: '-') ?></td>
        <td class="num"><?= qty_text($r['q']) ?></td><td class="num"><?= num($r['trx']) ?></td>
        <td class="num"><?= money($hrg * (float)$r['q']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="2">TOTAL (tidak ditagihkan)</th>
      <th class="num"><?= qty_text(array_sum(array_column($perMat, 'q'))) ?></th>
      <th class="num"><?= num(array_sum(array_column($perMat, 'trx'))) ?></th><th></th></tr></tfoot>
  </table></div>
</div>
<?php endif; ?>

<p class="muted mt-3" style="font-size:.72rem">
  Dokumen ini dihasilkan otomatis oleh <?= e(clinic_name()) ?> Management System pada
  <?= e(tglIndo(date('Y-m-d'))) ?> <?= date('H:i') ?>. Seluruh angka dihitung dari database pada saat dokumen dibuat.
</p>

<script src="assets/vendor/chart.umd.min.js"></script>
<script><?= income_charts_js() ?></script>
<script>
(function () {
  var money = function (v) { return 'Rp ' + Number(v || 0).toLocaleString('id-ID'); };
  var yMoney = { beginAtZero: true, ticks: { callback: function (v) { return 'Rp ' + Number(v).toLocaleString('id-ID'); } } };
  var legendBottom = { legend: { position: 'bottom', labels: { boxWidth: 11, font: { size: 10 } } } };
  var noAnim = { animation: false, responsive: true, maintainAspectRatio: false };
  /* Palet & warna seri dikirim dari PHP (dokumen ini HANYA memuat Chart.js,
     tanpa app.js, jadi tidak boleh memanggil Naveena.* — pernah membuat skrip
     berhenti di tengah dan grafik berikutnya kosong).
     Paletnya SAMA dengan halaman layar (chart_colors()) sehingga dokumen cetak,
     Excel, dan PDF memakai warna yang konsisten — dulu dokumen ini punya
     daftar warnanya sendiri yang 3 warna pertamanya satu keluarga (magenta). */
  var PAL = <?= js_json(chart_colors(48)) ?>;
  var SER = <?= js_json(chart_series_colors()) ?>;
  var pal = PAL;
  function palFor(n) { return PAL.slice(0, Math.max(1, Math.min(n, PAL.length))); }
  function rgba(hex, a) {
    var h = String(hex).replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    var v = parseInt(h, 16);
    return 'rgba(' + ((v >> 16) & 255) + ',' + ((v >> 8) & 255) + ',' + (v & 255) + ',' + a + ')';
  }

  function mk(id, cfg) { var c = document.getElementById(id); if (c) new Chart(c.getContext('2d'), cfg); }
  function base(extra) { return Object.assign({}, noAnim, extra); }

  /* progres bulanan: dua grafik — batang berjajaran + total terpisah
     (helper bersama includes/chart_js.php, sama dengan dashboard & laporan) */
  NaveenaIncome({
    bars: 'cMonthly', total: 'cMonthlyTotal',
    labels: <?= js_json($monthly['labels']) ?>,
    tr: <?= js_json($monthly['tr']) ?>,
    sk: <?= js_json($monthly['sk']) ?>,
    totalData: <?= js_json($monthly['total']) ?>,
    trColor: SER.treatment, skColor: SER.skincare, totalColor: SER.total, totalFill: rgba(SER.total, .14),
    money: money, animation: false
  });

  /* pertumbuhan bulanan (%) */
  var gLabels = <?= js_json($monthly['labels']) ?>;
  var gData = <?= js_json((function () use ($monthly) {
      $g = [];
      foreach ($monthly['total'] as $i => $v) {
          $prev = $i > 0 ? $monthly['total'][$i - 1] : 0;
          $g[] = $prev > 0 ? round((($v - $prev) / $prev) * 100, 1) : 0;
      }
      return $g;
  })()) ?>;
  mk('cGrowth', base({
    type: 'bar',
    data: { labels: gLabels, datasets: [{ label: 'Perubahan (%)', data: gData,
      backgroundColor: gData.map(function (v) { return v >= 0 ? SER.positif : SER.negatif; }), borderRadius: 4 }] },
    options: base({ plugins: { legend: { display: false },
      tooltip: { callbacks: { label: function (c) { return (c.parsed.y >= 0 ? '+' : '') + c.parsed.y + '%'; } } } },
      scales: { y: { ticks: { callback: function (v) { return v + '%'; } } } } })
  }));

  <?php if ($f['scope'] === null && $monthly['branches']): ?>
  (function () {
    var dat = <?= js_json(array_map(function ($name, $series) {
        return ['label' => branch_short_label((string)$name), 'data' => $series, 'tension' => .3,
                'borderWidth' => 2.2, 'pointRadius' => 2, 'pointBackgroundColor' => '', 'pointBorderColor' => '#fff',
                'pointBorderWidth' => 1.2];
    }, array_keys($monthly['branches']), array_values($monthly['branches']))) ?>;
    var cols = palFor(dat.length);
    dat.forEach(function (d, i) {
      d.borderColor = cols[i]; d.pointBackgroundColor = cols[i]; d.backgroundColor = 'transparent';
      if (dat.length > 8 && i % 2 === 1) d.borderDash = [6, 4];
    });
    mk('cMonthlyBranch', base({
      type: 'line',
      data: { labels: <?= js_json($monthly['labels']) ?>, datasets: dat },
      options: base({ plugins: legendBottom, scales: { y: yMoney } })
    }));
  })();
  <?php endif; ?>

  /* komposisi */
  mk('cComposition', base({
    type: 'doughnut',
    data: { labels: ['Treatment', 'Skincare'],
      datasets: [{ data: [<?= (float)$tot['tr'] ?>, <?= (float)$tot['sk'] ?>],
        backgroundColor: [SER.treatment, SER.skincare], borderWidth: 2, borderColor: '#fff' }] },
    options: base({ cutout: '55%', plugins: { legend: { position: 'bottom' },
      tooltip: { callbacks: { label: function (c) {
        var t = c.dataset.data.reduce(function (a, b) { return a + b; }, 0) || 1;
        return c.label + ': ' + money(c.parsed) + ' (' + (c.parsed / t * 100).toFixed(1) + '%)'; } } } } })
  }));

  <?php if ($methods): ?>
  mk('cMethod', base({
    type: 'pie',
    data: { labels: <?= js_json(array_column($methods, 'method')) ?>,
      datasets: [{ data: <?= js_json(array_map(fn($m) => (float)$m['total'], $methods)) ?>,
        backgroundColor: palFor(<?= count($methods) ?>), borderWidth: 2, borderColor: '#fff' }] },
    options: base({ plugins: legendBottom })
  }));
  <?php endif; ?>

  var brLabels = <?= js_json(array_map(fn($b) => branch_short_label((string)$b['name']), $perBranch)) ?>;
  mk('cBranchPie', base({
    type: 'doughnut',
    data: { labels: brLabels, datasets: [{ data: <?= js_json(array_map(fn($b) => (float)$b['total'], $perBranch)) ?>,
      backgroundColor: palFor(brLabels.length), borderWidth: 2, borderColor: '#fff' }] },
    options: base({ cutout: '50%', plugins: legendBottom })
  }));
  mk('cBranchBar', base({
    type: 'bar',
    data: { labels: brLabels, datasets: [
      { label: 'Treatment', data: <?= js_json(array_map(fn($b) => (float)$b['tr'], $perBranch)) ?>, backgroundColor: SER.treatment },
      { label: 'Skincare', data: <?= js_json(array_map(fn($b) => (float)$b['sk'], $perBranch)) ?>, backgroundColor: SER.skincare }] },
    options: base({ plugins: legendBottom, scales: { y: yMoney } })
  }));
  /* tren harian/bulanan: dua grafik — batang berjajaran + total terpisah.
     Dulu keduanya ditumpuk dan garis Total ikut tertumpuk sehingga nilainya
     berlipat dan garisnya keluar dari area grafik (tidak terlihat). */
  NaveenaIncome({
    bars: 'cDaily', total: 'cDailyTotal',
    labels: <?= js_json($daily['labels']) ?>,
    tr: <?= js_json($daily['tr']) ?>,
    sk: <?= js_json($daily['sk']) ?>,
    totalData: <?= js_json($daily['total']) ?>,
    trColor: SER.treatment, skColor: SER.skincare, totalColor: SER.total, totalFill: rgba(SER.total, .14),
    money: money, animation: false
  });

  /* top 5: batang nilai + porsi */
  function topChart(id, rows, label) {
    if (!rows.length) return;
    mk(id, base({
      type: 'bar',
      data: { labels: rows.map(function (r) { return r.nama; }),
        datasets: [
          { label: 'Nilai', data: rows.map(function (r) { return Number(r.s); }), backgroundColor: palFor(rows.length), borderRadius: 4, yAxisID: 'y' },
          { label: 'Porsi (%)', data: rows.map(function (r) {
              var t = rows.reduce(function (a, b) { return a + Number(b.s); }, 0) || 1;
              return Number(((Number(r.s) / t) * 100).toFixed(1));
            }), type: 'line', borderColor: SER.total, backgroundColor: 'transparent', tension: .3, pointRadius: 3, yAxisID: 'y1' }
        ] },
      options: base({ plugins: legendBottom,
        scales: { y: yMoney, y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false },
          ticks: { callback: function (v) { return v + '%'; } } } } })
    }));
  }
  topChart('cTopTr', <?= js_json(array_map(fn($r) => ['nama' => $r['nama'], 's' => (float)$r['s']], $topTr)) ?>, 'Treatment');
  topChart('cTopSk', <?= js_json(array_map(fn($r) => ['nama' => $r['nama'], 's' => (float)$r['s']], $topSk)) ?>, 'Skincare');

  <?php if ($cashiers): ?>
  mk('cCashier', base({
    type: 'bar',
    data: { labels: <?= js_json(array_map(fn($c) => $c['nama'], $cashiers)) ?>, datasets: [
      { label: 'Pendapatan', data: <?= js_json(array_map(fn($c) => (float)$c['total'], $cashiers)) ?>, backgroundColor: SER.treatment, yAxisID: 'y' },
      { label: 'Transaksi', data: <?= js_json(array_map(fn($c) => (int)$c['trx'], $cashiers)) ?>, type: 'line',
        borderColor: SER.skincare, backgroundColor: 'transparent', tension: .3, pointRadius: 3, yAxisID: 'y1' }] },
    options: base({ plugins: legendBottom,
      scales: { y: yMoney, y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } } } })
  }));
  <?php endif; ?>
})();

/* Buka dialog cetak setelah grafik selesai digambar, supaya grafik ikut
   tersimpan pada PDF. Grafik dibuat sinkron oleh skrip di atas, jadi cukup
   menunggu satu putaran render. Dijaga agar hanya tercetak sekali. */
(function () {
  var printed = false;
  function go() {
    if (printed) return;
    var canvases = document.querySelectorAll('canvas[data-chart]');
    var drawn = 0;
    Array.prototype.forEach.call(canvases, function (c) { if (c.width > 0) drawn++; });
    if (drawn >= canvases.length && canvases.length > 0 || performance.now() > 4000) {
      printed = true;
      setTimeout(function () { window.print(); }, 300);
    } else {
      setTimeout(go, 150);
    }
  }
  if (document.readyState === 'complete') setTimeout(go, 400);
  else window.addEventListener('load', function () { setTimeout(go, 400); });
})();
</script>
</body>
</html>
<?php
}
