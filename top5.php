<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('report.view');
$scope = scope_branch();
$period = gp('period', 'month');
[$ps, $pe] = resolve_period($period, gp('start'), gp('end'));

function top_rows(string $type, string $ps, string $pe, ?int $scope, int $limit = 5): array
{
    $cat = $type === 'skincare' ? 's.category' : 't.category';
    $join = $type === 'skincare' ? 'LEFT JOIN skincare_products s ON s.id = oi.skincare_id' : 'LEFT JOIN treatments t ON t.id = oi.treatment_id';
    $bs = $scope === null ? '' : ' AND o.branch_id = ?';
    $params = [$type, $ps, $pe];
    if ($scope !== null) $params[] = $scope;
    return all("SELECT oi.item_name name, oi.item_code code, COALESCE({$cat},'-') category,
                       COALESCE(SUM(oi.quantity),0) qty, COALESCE(SUM(oi.subtotal),0) total,
                       COUNT(DISTINCT o.id) trx
                FROM order_items oi JOIN orders o ON o.id = oi.order_id {$join}
                WHERE o.status='paid' AND oi.item_type = ? AND date(o.created_at) BETWEEN ? AND ? {$bs}
                GROUP BY oi.item_name ORDER BY total DESC LIMIT " . (int)$limit, $params);
}
$topSk = top_rows('skincare', $ps, $pe, $scope);
$topTr = top_rows('treatment', $ps, $pe, $scope);
$skTot = array_sum(array_column($topSk, 'total')) ?: 1;
$trTot = array_sum(array_column($topTr, 'total')) ?: 1;
$focus = gp('type', 'both');

/* Top 5 per cabang (super admin) */
$byBranchTop = [];
if (is_owner_level()) {
    foreach (branches() as $b) {
        $byBranchTop[] = [
            'branch' => $b['name'],
            'skincare' => top_rows('skincare', $ps, $pe, (int)$b['id'], 5),
            'treatment' => top_rows('treatment', $ps, $pe, (int)$b['id'], 5),
        ];
    }
}

page_head('Top 5 Penjualan', 'top5');
?>
<div class="page-head">
  <div><h2>Top 5 Penjualan</h2><p class="muted">Peringkat berdasarkan penjualan bersih (transaksi void/refund tidak dihitung).</p></div>
  <div class="page-actions">
    <?php if (has_perm('export.data')): ?>
      <a class="btn" href="export.php?type=laporan_treatment&format=csv&<?= e(qs([], ['page', 'per_page'])) ?>"><?= icon('download') ?> CSV Treatment</a>
      <a class="btn" href="export.php?type=laporan_skincare&format=csv&<?= e(qs([], ['page', 'per_page'])) ?>">CSV Skincare</a>
      <a class="btn btn-primary" href="laporan.php?<?= e(qs()) ?>">Laporan Lengkap</a>
    <?php endif; ?>
  </div>
</div>

<div class="card tight">
  <form class="filter-bar" method="get">
    <div class="field"><label>Periode</label>
      <select class="input input-sm" name="period" data-period data-autosubmit>
        <?php foreach (['today' => 'Hari ini', '7d' => '7 hari', 'month' => 'Bulan ini', '3m' => '3 bulan', 'year' => 'Tahun ini', 'custom' => 'Custom tanggal'] as $k => $v): ?>
          <option value="<?= $k ?>"<?= $period === $k ? ' selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field" data-period-custom style="display:none"><label>Dari</label><input class="input input-sm" type="date" name="start" value="<?= e($ps) ?>"></div>
    <div class="field" data-period-custom style="display:none"><label>Sampai</label><input class="input input-sm" type="date" name="end" value="<?= e($pe) ?>"></div>
    <?php if (is_owner_level()): ?>
    <div class="field"><label>Cabang</label>
      <select class="input input-sm" name="branch">
        <option value="all"<?= $scope === null ? ' selected' : '' ?>>Semua Cabang</option>
        <?php foreach (branches() as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $scope === (int)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <button class="btn btn-sm btn-primary" type="submit">Terapkan</button>
    <span class="muted"><?= e(tgl($ps)) ?> — <?= e(tgl($pe)) ?></span>
  </form>
</div>

<div class="grid g2">
  <div class="card">
    <div class="card-head"><h3>Top 5 Penjualan Skincare</h3><span class="muted"><?= money(array_sum(array_column($topSk, 'total'))) ?></span></div>
    <div class="card-body">
      <?php if (!$topSk): ?><?= empty_state('Belum ada penjualan skincare pada periode ini.') ?><?php else: ?>
        <div class="chart-box sm"><canvas id="pieSk"></canvas></div>
        <div class="table-wrap mt-2">
          <div class="table-wrap"><table class="tbl">
            <thead><tr><th>Rank</th><th>Nama Produk</th><th>Kategori</th><th class="num">Terjual</th><th class="num">Total Penjualan</th><th class="num">%</th></tr></thead>
            <tbody>
            <?php foreach ($topSk as $i => $r): ?>
              <tr>
                <td><span class="rank r<?= $i + 1 ?>"><?= $i + 1 ?></span></td>
                <td><?= e($r['name']) ?><div class="small muted"><?= e($r['code'] ?: '-') ?> · <?= num($r['trx']) ?> transaksi</div></td>
                <td class="small"><?= e($r['category']) ?></td>
                <td class="num"><?= qty_text($r['qty']) ?></td>
                <td class="num"><?= money($r['total']) ?></td>
                <td class="num"><?= num($r['total'] / $skTot * 100, 1) ?>%</td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Top 5 Penjualan Treatment</h3><span class="muted"><?= money(array_sum(array_column($topTr, 'total'))) ?></span></div>
    <div class="card-body">
      <?php if (!$topTr): ?><?= empty_state('Belum ada penjualan treatment pada periode ini.') ?><?php else: ?>
        <div class="chart-box sm"><canvas id="pieTr"></canvas></div>
        <div class="table-wrap mt-2">
          <div class="table-wrap"><table class="tbl">
            <thead><tr><th>Rank</th><th>Nama Treatment</th><th>Kategori</th><th class="num">Terjual</th><th class="num">Total Penjualan</th><th class="num">%</th></tr></thead>
            <tbody>
            <?php foreach ($topTr as $i => $r): ?>
              <tr>
                <td><span class="rank r<?= $i + 1 ?>"><?= $i + 1 ?></span></td>
                <td><?= e($r['name']) ?><div class="small muted"><?= e($r['code'] ?: '-') ?> · <?= num($r['trx']) ?> transaksi</div></td>
                <td class="small"><?= e($r['category']) ?></td>
                <td class="num"><?= qty_text($r['qty']) ?></td>
                <td class="num"><?= money($r['total']) ?></td>
                <td class="num"><?= num($r['total'] / $trTot * 100, 1) ?>%</td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (is_owner_level()): ?>
  <div class="section-title">Top 5 Per Cabang</div>
  <?php foreach ($byBranchTop as $bt): ?>
    <div class="card">
      <div class="card-head"><h3><?= e($bt['branch']) ?></h3></div>
      <div class="card-body">
        <div class="grid g2">
          <div>
            <h4 class="mb-1">Skincare</h4>
            <?php if (!$bt['skincare']): ?><p class="muted">Belum ada data.</p><?php else: ?>
              <div class="table-wrap"><table class="tbl">
                <thead><tr><th>#</th><th>Produk</th><th class="num">Terjual</th><th class="num">Total</th></tr></thead>
                <tbody><?php foreach ($bt['skincare'] as $i => $r): ?>
                  <tr><td><span class="rank r<?= $i + 1 ?>"><?= $i + 1 ?></span></td><td><?= e($r['name']) ?></td>
                    <td class="num"><?= qty_text($r['qty']) ?></td><td class="num"><?= money($r['total']) ?></td></tr>
                <?php endforeach; ?></tbody>
              </table></div>
            <?php endif; ?>
          </div>
          <div>
            <h4 class="mb-1">Treatment</h4>
            <?php if (!$bt['treatment']): ?><p class="muted">Belum ada data.</p><?php else: ?>
              <div class="table-wrap"><table class="tbl">
                <thead><tr><th>#</th><th>Treatment</th><th class="num">Terjual</th><th class="num">Total</th></tr></thead>
                <tbody><?php foreach ($bt['treatment'] as $i => $r): ?>
                  <tr><td><span class="rank r<?= $i + 1 ?>"><?= $i + 1 ?></span></td><td><?= e($r['name']) ?></td>
                    <td class="num"><?= qty_text($r['qty']) ?></td><td class="num"><?= money($r['total']) ?></td></tr>
                <?php endforeach; ?></tbody>
              </table></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php
$PAGE_SCRIPTS = [];
ob_start();
?>
<script src="assets/vendor/chart.umd.min.js"></script>
<script>
function pie(id, rows) {
  if (!rows.length) return;
  Naveena.chart(id, {
    type: 'pie',
    data: { labels: rows.map(r => r.name),
      datasets: [{ data: rows.map(r => Number(r.total)), backgroundColor: Naveena.paletteFor(rows.length),
        borderWidth: 2, borderColor: '#fff' }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } }
  });
}
pie('pieSk', <?= js_json(array_map(fn($r) => ['name' => $r['name'], 'total' => (float)$r['total']], $topSk)) ?>);
pie('pieTr', <?= js_json(array_map(fn($r) => ['name' => $r['name'], 'total' => (float)$r['total']], $topTr)) ?>);
</script>
<?php
$PAGE_SCRIPTS[] = ob_get_clean();
page_foot();
