<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
/* WAJIB: halaman ini memakai satu sumber angka bersama (report_top_patients()),
   yang hidup di includes/reports.php. Tanpa baris ini halaman mati dengan
   "Call to undefined function" (tertangkap uji pemeliharaan sebagai HTTP 500). */
require_once __DIR__ . '/includes/reports.php';
require_perm('report.view');
$scope = scope_branch();

$period = gp('period');
if (!in_array($period, ['month', '3m', 'custom', 'today', '7d', 'year'], true)) $period = 'month';
/* Rentang dihitung dari PILIHAN PERIODE. Kolom "Dari/Sampai" hanya dipakai bila
   periodenya memang `custom` — sebelumnya nilai `from`/`to` yang selalu ikut
   terkirim memaksa periodenya menjadi custom dengan tanggal lama.
   `from`/`to` tetap diterima sebagai ALIAS `start`/`end` supaya tautan/bookmark
   lama yang memakai nama itu tidak rusak. */
$startArg = gp('start') !== '' ? (string)gp('start') : (string)gp('from');
$endArg   = gp('end')   !== '' ? (string)gp('end')   : (string)gp('to');
[$ps, $pe] = resolve_period($period, $startArg, $endArg);

/* Satu sumber angka dengan lampiran email laporan bulanan (report_top_patients())
   supaya daftar di halaman ini dan di email tidak pernah berbeda. */
$rows = report_top_patients(report_filters_manual($ps, $pe, $scope, 'paid'), 10);
$grand = array_sum(array_column($rows, 'total')) ?: 1;

page_head('Top 10 Pasien', 'top10');
?>
<div class="page-head">
  <div><h2>Top 10 Pasien</h2><p class="muted">Pasien dengan <strong>total transaksi (Rp) terbesar</strong>
    pada periode terpilih — peringkat 1 = nilai terbesar; jumlah transaksi menjadi penentu berikutnya
    bila nilainya sama.<br>
    <strong>Jumlah Transaksi</strong> = banyaknya transaksi pada Riwayat Order (periode ini) ·
    <strong>Kunjungan</strong> = banyaknya <em>hari</em> pasien datang pada periode ini
    (transaksi atau rekam medis); 3 transaksi dalam sehari tetap terhitung 1 kunjungan.</p></div>
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
    <?php /* NAMA KOLOM `start`/`end` (bukan `from`/`to`) — ini perbaikan bug:
       kolom tanggal tersembunyi SELALU ikut terkirim saat formulir dikirim, sehingga
       memilih "3 bulan terakhir"/"Tahun ini" tetap dianggap "Custom tanggal" dengan
       tanggal LAMA (pilihan periode tampak tidak bekerja). `resolve_period()` hanya
       membaca `start`/`end` ketika periodinya memang `custom`, jadi penamaannya
       disamakan dengan halaman Laporan & Top 5. */ ?>
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
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state('Belum ada transaksi pasien pada periode ini.') ?><?php else: ?>
    <table class="tbl">
      <?php /* Kolom memakai daftar bersama (top_patients_columns()) supaya halaman,
         dokumen cetak, dan Excel menyebut kolom yang SAMA untuk data yang sama. */ ?>
      <thead><tr><?php foreach (top_patients_columns() as $ci => $th): ?><th<?= $ci >= 3 && $ci <= 5 ? ' class="num"' : '' ?>><?= e($ci === 0 ? 'Ranking' : $th) ?></th><?php endforeach; ?><th class="num">Kontribusi</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td><span class="rank r<?= $i + 1 ?>"><?= $i + 1 ?></span></td>
          <td><a href="pasien_detail.php?id=<?= (int)$r['id'] ?>"><strong><?= e($r['name']) ?></strong></a>
            <div class="small muted"><?= e($r['patient_number']) ?> · <?= e($r['phone'] ?: '-') ?> · <?= e($r['patient_type']) ?></div></td>
          <td class="small"><?= e($r['member_number'] ?: '-') ?></td>
          <?php /* Urutan sel MENGIKUTI top_patients_columns(): Total Transaksi
             (penentu peringkat) sebelum Jumlah Transaksi. */ ?>
          <td class="num"><?= num($r['visits']) ?></td>
          <td class="num"><strong><?= money($r['total']) ?></strong></td>
          <td class="num"><?= num($r['trx']) ?></td>
          <td class="small"><?= e($r['branch_name']) ?></td>
          <td class="num"><?= num($r['total'] / $grand * 100, 1) ?>%</td>
          <td><a class="btn btn-sm" href="pasien_detail.php?id=<?= (int)$r['id'] ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php /* Selang-selang baris TOTAL disesuaikan dengan urutan kolom BARU: kolom
         ke-5 = "Total Transaksi" — jadi angka totalnya harus mendarat di sana
         (colspan 4 + 1 + 4 = 9 kolom). */ ?>
      <tfoot><tr><th colspan="4">TOTAL 10 PASIEN TERATAS</th><th class="num"><?= money(array_sum(array_column($rows, 'total'))) ?></th><th colspan="4"></th></tr></tfoot>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php page_foot(); ?>
