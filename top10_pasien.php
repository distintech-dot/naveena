<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('report.view');
$scope = scope_branch();

$period = gp('period');
if (!in_array($period, ['month', '3m', 'custom', 'today', '7d', 'year'], true)) $period = 'month';
[$ps, $pe] = resolve_period($period, gp('start'), gp('end'));
if (gp('from') !== '') { $ps = gp('from'); $period = 'custom'; }
if (gp('to') !== '')   { $pe = gp('to'); $period = 'custom'; }

$bs = $scope === null ? '' : ' AND o.branch_id = ?';
$params = [$ps, $pe];
if ($scope !== null) $params[] = $scope;

$rows = all("SELECT p.id, p.name, p.patient_number, p.member_number, p.phone, p.patient_type, b.name branch_name,
                    COUNT(DISTINCT o.id) trx,
                    COUNT(DISTINCT date(o.created_at)) visits,
                    COALESCE(SUM(o.total),0) total,
                    MAX(o.created_at) last_visit
             FROM orders o JOIN patients p ON p.id=o.patient_id JOIN branches b ON b.id=p.branch_id
             WHERE o.status='paid' AND date(o.created_at) BETWEEN ? AND ? {$bs}
             GROUP BY p.id ORDER BY trx DESC, total DESC LIMIT 10", $params);
$grand = array_sum(array_column($rows, 'total')) ?: 1;

page_head('Top 10 Pasien', 'top10');
?>
<div class="page-head">
  <div><h2>Top 10 Pasien</h2><p class="muted">Pasien dengan transaksi terbanyak pada periode terpilih.</p></div>
  <div class="page-actions">
    <?php if (has_perm('export.data')): ?>
      <a class="btn" href="export.php?type=pasien&format=csv"><?= icon('download') ?> Data Pasien CSV</a>
      <a class="btn btn-primary" href="export.php?type=pasien&format=excel">Excel</a>
    <?php endif; ?>
  </div>
</div>

<div class="card tight">
  <form class="filter-bar" method="get">
    <div class="field"><label>Periode</label>
      <select class="input input-sm" name="period" data-period data-autosubmit>
        <?php foreach (['month' => '1 bulan terakhir', '3m' => '3 bulan terakhir', 'today' => 'Hari ini', 'year' => 'Tahun ini', 'custom' => 'Custom tanggal'] as $k => $v): ?>
          <option value="<?= $k ?>"<?= $period === $k ? ' selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field" data-period-custom style="display:none"><label>Dari</label><input class="input input-sm" type="date" name="from" value="<?= e($ps) ?>"></div>
    <div class="field" data-period-custom style="display:none"><label>Sampai</label><input class="input input-sm" type="date" name="to" value="<?= e($pe) ?>"></div>
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
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state('Belum ada transaksi pasien pada periode ini.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Ranking</th><th>Nama Pasien</th><th>No. Member</th><th class="num">Kunjungan</th><th class="num">Jumlah Transaksi</th><th class="num">Total Transaksi</th><th>Cabang</th><th class="num">Kontribusi</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td><span class="rank r<?= $i + 1 ?>"><?= $i + 1 ?></span></td>
          <td><a href="pasien_detail.php?id=<?= (int)$r['id'] ?>"><strong><?= e($r['name']) ?></strong></a>
            <div class="small muted"><?= e($r['patient_number']) ?> · <?= e($r['phone'] ?: '-') ?> · <?= e($r['patient_type']) ?></div></td>
          <td class="small"><?= e($r['member_number'] ?: '-') ?></td>
          <td class="num"><?= num($r['visits']) ?></td>
          <td class="num"><?= num($r['trx']) ?></td>
          <td class="num"><strong><?= money($r['total']) ?></strong></td>
          <td class="small"><?= e($r['branch_name']) ?></td>
          <td class="num"><?= num($r['total'] / $grand * 100, 1) ?>%</td>
          <td><a class="btn btn-sm" href="pasien_detail.php?id=<?= (int)$r['id'] ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th colspan="5">TOTAL 10 PASIEN TERATAS</th><th class="num"><?= money(array_sum(array_column($rows, 'total'))) ?></th><th colspan="3"></th></tr></tfoot>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php page_foot(); ?>
