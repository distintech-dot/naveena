<?php
/**
 * Kamus ICD — penelusuran kamus resmi ICD-10 (diagnosis) dan ICD-9-CM (tindakan).
 * Sumber data sama dengan yang dipakai form Rekam Medis, sehingga kode yang
 * dipilih di sini pasti konsisten.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
if (!has_perm('medical.view') && !has_perm('medical.manage')) {
    deny('Anda tidak memiliki hak akses untuk kamus ICD.');
}
require_login();

$kind  = gp('kind') === 'icd9cm' ? 'icd9cm' : 'icd10';
$q     = gp('q');
$page  = page_no();
$pp    = per_page();
$where = ['kind = ?'];
$params = [$kind];
if ($q !== '') {
    $norm = icd_norm($q);
    $where[] = '(code LIKE ? OR code_norm LIKE ? OR name_id LIKE ? OR name_en LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $norm . '%', '%' . $q . '%', '%' . $q . '%');
}
$w = implode(' AND ', $where);
$total = (int)scalar("SELECT COUNT(*) FROM icd_codes WHERE {$w}", $params);
$rows = all("SELECT * FROM icd_codes WHERE {$w} ORDER BY LENGTH(code), code LIMIT {$pp} OFFSET " . (($page - 1) * $pp), $params);

/* Kode yang paling sering dipakai di rekam medis cabang yang boleh diakses */
$b = branch_sql('m.branch_id');
$pop10 = all("SELECT m.icd10 code, m.icd10_desc descr, COUNT(*) n
              FROM medical_records m WHERE m.icd10 IS NOT NULL AND m.icd10 <> '' {$b[0]}
              GROUP BY m.icd10 ORDER BY n DESC LIMIT 6", $b[1]);
$b2 = branch_sql('m.branch_id');
$pop9 = all("SELECT m.icd9 code, m.icd9_desc descr, COUNT(*) n
             FROM medical_records m WHERE m.icd9 IS NOT NULL AND m.icd9 <> '' {$b2[0]}
             GROUP BY m.icd9 ORDER BY n DESC LIMIT 6", $b2[1]);

page_head('Kamus ICD', 'icd');
?>
<div class="page-head">
  <div>
    <h2>Kamus ICD-10 &amp; ICD-9-CM</h2>
    <p class="muted">Referensi kode yang dipakai pada Rekam Medis. <?= e(setting('icd_source_note')) ?>.</p>
  </div>
  <div class="page-actions">
    <?php if (has_perm('export.data')): ?>
      <a class="btn" href="export.php?type=icd&format=csv&kind=<?= e($kind) ?>&<?= e(qs([], ['page', 'per_page'])) ?>"><?= icon('download') ?> CSV</a>
      <a class="btn" href="export.php?type=icd&format=excel&kind=<?= e($kind) ?>&<?= e(qs([], ['page', 'per_page'])) ?>">Excel</a>
    <?php endif; ?>
    <a class="btn" href="rekam_medis.php"><?= icon('file-medical') ?> Rekam Medis</a>
  </div>
</div>

<div class="grid g3">
  <div class="stat accent">
    <span class="lbl">Kamus ICD-10</span>
    <span class="val"><?= num(icd_count('icd10')) ?></span>
    <span class="sub"><?= num(icd_translated_count()) ?> berbahasa Indonesia · sisanya judul resmi Inggris</span>
  </div>
  <div class="stat leaf">
    <span class="lbl">Kamus ICD-9-CM</span>
    <span class="val"><?= num(icd_count('icd9cm')) ?></span>
    <span class="sub">kode tindakan/prosedur (CMS Volume 3)</span>
  </div>
  <div class="stat <?= satu_sehat_configured() ? 'leaf' : '' ?>">
    <span class="lbl">Integrasi Satu Sehat</span>
    <span class="val" style="font-size:1.05rem"><?= satu_sehat_configured() && setting('satu_sehat_active') === '1' ? 'Aktif' : 'Belum Aktif' ?></span>
    <span class="sub"><?php if (is_super()): ?>
        <a href="developer.php#satusehat">Pengaturan Satu Sehat →</a>
      <?php else: ?>
        Pengaturan integrasi hanya dapat dibuka Super Admin
      <?php endif; ?></span>
  </div>
</div>

<div class="tabs mt-2">
  <a class="tab<?= $kind === 'icd10' ? ' active' : '' ?>" href="icd.php?kind=icd10">ICD-10 — Diagnosis</a>
  <a class="tab<?= $kind === 'icd9cm' ? ' active' : '' ?>" href="icd.php?kind=icd9cm">ICD-9-CM — Tindakan</a>
</div>

<?php if ($pop10 && $kind === 'icd10'): ?>
<div class="card">
  <div class="card-head"><h3>Kode ICD-10 yang Paling Sering Dipakai</h3><span class="muted">dari rekam medis yang tercatat</span></div>
  <div class="card-body"><div class="chips">
    <?php foreach ($pop10 as $p): ?>
      <a class="pill" href="?kind=icd10&q=<?= urlencode($p['code']) ?>"><?= e($p['code']) ?> <span class="small muted"><?= e(item_short((string)$p['descr'], 34)) ?></span> · <?= num($p['n']) ?>x</a>
    <?php endforeach; ?>
  </div></div>
</div>
<?php endif; ?>
<?php if ($pop9 && $kind === 'icd9cm'): ?>
<div class="card">
  <div class="card-head"><h3>Kode ICD-9-CM yang Paling Sering Dipakai</h3><span class="muted">dari rekam medis yang tercatat</span></div>
  <div class="card-body"><div class="chips">
    <?php foreach ($pop9 as $p): ?>
      <a class="pill" href="?kind=icd9cm&q=<?= urlencode($p['code']) ?>"><?= e($p['code']) ?> <span class="small muted"><?= e(item_short((string)$p['descr'], 34)) ?></span> · <?= num($p['n']) ?>x</a>
    <?php endforeach; ?>
  </div></div>
</div>
<?php endif; ?>

<div class="card tight">
  <form class="filter-bar" method="get">
    <input type="hidden" name="kind" value="<?= e($kind) ?>">
    <div class="field searchbox"><label>Cari Kode / Nama</label><span><?= icon('search') ?></span>
      <input class="input input-sm" name="q" value="<?= e($q) ?>" autofocus
             placeholder="<?= $kind === 'icd10' ? 'mis. L70.0, jerawat, acne, L81' : 'mis. 99.83, phototherapy, 86.' ?>"></div>
    <button class="btn btn-sm btn-primary" type="submit">Cari</button>
    <a class="btn btn-sm" href="icd.php?kind=<?= e($kind) ?>">Reset</a>
    <span class="muted"><?= num($total) ?> kode ditemukan</span>
    <?= per_page_inline() ?>
  </form>
  <div class="table-wrap">
    <?php if (!$rows): ?>
      <?= empty_state('Tidak ada kode yang cocok. Coba kata kunci lain, mis. bagian awal kode.') ?>
    <?php else: ?>
    <?php
    /* Hitung pemakaian kode untuk halaman ini dalam satu query (hindari N+1),
       tetap dibatasi cabang yang boleh diakses. */
    $codes = array_column($rows, 'code');
    $in = implode(',', array_fill(0, count($codes), '?'));
    $col = $kind === 'icd10' ? 'icd10' : 'icd9';
    [$bs, $bp] = branch_sql('branch_id');
    $usedMap = [];
    foreach (all("SELECT {$col} code, COUNT(*) n FROM medical_records
                  WHERE {$col} IN ({$in}) {$bs} GROUP BY {$col}", array_merge($codes, $bp)) as $u) {
        $usedMap[$u['code']] = (int)$u['n'];
    }
    ?>
    <table class="tbl">
      <thead><tr><th>Kode</th><th><?= $kind === 'icd10' ? 'Nama Diagnosis (Indonesia)' : 'Nama Tindakan' ?></th><?= $kind === 'icd10' ? '<th>Nama Inggris</th>' : '' ?><th>Dipakai</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $used = $usedMap[$r['code']] ?? 0; ?>
        <tr>
          <td><code><?= e($r['code']) ?></code></td>
          <td><?= icd_name_html($r) ?></td>
          <?php if ($kind === 'icd10'): ?><td class="small muted"><?= e($r['name_en']) ?></td><?php endif; ?>
          <td class="num"><?= $used > 0 ? num($used) . 'x' : '<span class="muted">—</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?= pagination($total, $pp, $page) ?>
</div>

<div class="notice">
  Kamus ini tersimpan di server dan dipakai untuk saran otomatis pada form Rekam Medis, sehingga kode yang dipilih selalu
  konsisten dengan standar ICD-10 (WHO) dan ICD-9-CM Volume 3 yang juga menjadi referensi Satu Sehat/INA-CBG.
  Pengiriman data ke API Satu Sehat memerlukan kredensial resmi — statusnya<?= is_super()
      ? ' dapat dilihat di <a href="developer.php#satusehat">Developer Settings</a>' : ' hanya dapat dilihat/diatur oleh <strong>Super Admin</strong>' ?>.
</div>
<?php page_foot(); ?>
