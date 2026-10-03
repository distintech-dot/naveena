<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('staff.manage');
$user = current_user();
$scope = scope_branch();
$kind = gp('kind', 'dokter') === 'terapis' ? 'terapis' : 'dokter';
$table = $kind === 'dokter' ? 'doctors' : 'therapists';
$label = $kind === 'dokter' ? 'Dokter' : 'Terapis';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    $tbl = ($_POST['kind'] ?? 'dokter') === 'terapis' ? 'therapists' : 'doctors';
    try {
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)$_POST['name']);
            $phone = trim((string)($_POST['phone'] ?? ''));
            $spec = trim((string)($_POST['specialization'] ?? ''));
            $sched = trim((string)($_POST['schedule'] ?? ''));
            $branch = is_owner_level() ? (int)($_POST['branch_id'] ?? 0) : (int)$user['branch_id'];
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            if ($name === '') throw new RuntimeException('Nama wajib diisi.');
            if (!$branch) throw new RuntimeException('Cabang wajib dipilih.');
            assert_branch($branch);
            if ($id > 0) {
                $old = one("SELECT * FROM {$tbl} WHERE id = ?", [$id]);
                if (!$old) throw new RuntimeException('Data tidak ditemukan.');
                assert_branch((int)$old['branch_id']);
                q("UPDATE {$tbl} SET name=?, phone=?, specialization=?, schedule=?, branch_id=?, status=?, updated_at=datetime('now','localtime') WHERE id=?",
                    [$name, $phone, $spec, $sched, $branch, $status, $id]);
                audit('Edit ' . ($tbl === 'doctors' ? 'Dokter' : 'Terapis'), 'Pengaturan', $id, $old, ['name' => $name, 'status' => $status], 'Perubahan data tenaga medis');
                flash('Data ' . strtolower($tbl === 'doctors' ? 'Dokter' : 'Terapis') . ' diperbarui.');
            } else {
                q("INSERT INTO {$tbl} (name, phone, specialization, schedule, branch_id, status, created_at) VALUES (?,?,?,?,?,?,datetime('now','localtime'))",
                    [$name, $phone, $spec, $sched, $branch, $status]);
                $newId = (int)db()->lastInsertId();
                audit('Tambah ' . ($tbl === 'doctors' ? 'Dokter' : 'Terapis'), 'Pengaturan', $newId, null, ['name' => $name, 'branch_id' => $branch], 'Tenaga medis baru');
                flash('Data berhasil ditambahkan.');
            }
        }
        if ($act === 'photo') {
            require_perm('staff.manage');
            $id = (int)$_POST['id'];
            $kind = ($_POST['kind'] ?? 'dokter') === 'terapis' ? 'therapist' : 'doctor';
            $res = photo_save_person($kind, $id, $_FILES['photo'] ?? []);
            flash('Foto ' . strtolower($label) . ' tersimpan — ' . img_result_text($res) . '.');
            header('Location: staff.php?kind=' . ($kind === 'therapist' ? 'terapis' : 'dokter'));
            exit;
        }
        if ($act === 'toggle') {
            $id = (int)$_POST['id'];
            $r = one("SELECT * FROM {$tbl} WHERE id = ?", [$id]);
            if (!$r) throw new RuntimeException('Data tidak ditemukan.');
            assert_branch((int)$r['branch_id']);
            $status = $r['status'] === 'active' ? 'inactive' : 'active';
            q("UPDATE {$tbl} SET status=?, updated_at=datetime('now','localtime') WHERE id=?", [$status, $id]);
            audit('Ubah Status Tenaga Medis', 'Pengaturan', $id, ['status' => $r['status']], ['status' => $status], 'Perubahan status');
            flash('Status diubah menjadi ' . $status . '.');
        }
        if ($act === 'delete') {
            $id = (int)$_POST['id'];
            $r = one("SELECT * FROM {$tbl} WHERE id = ?", [$id]);
            if (!$r) throw new RuntimeException('Data tidak ditemukan.');
            assert_branch((int)$r['branch_id']);
            $col = $tbl === 'doctors' ? 'doctor_id' : 'therapist_id';
            $refs = (int)scalar("SELECT COUNT(*) FROM appointments WHERE {$col} = ?", [$id])
                  + (int)scalar("SELECT COUNT(*) FROM medical_records WHERE {$col} = ?", [$id]);
            if ($refs > 0) throw new RuntimeException('Data ini sudah dipakai pada reservasi/rekam medis. Nonaktifkan saja agar histori tetap utuh.');
            q("DELETE FROM {$tbl} WHERE id = ?", [$id]);
            audit('Hapus Tenaga Medis', 'Pengaturan', $id, $r, null, 'Belum dipakai pada reservasi/rekam medis');
            flash('Data dihapus.');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: staff.php?kind=' . ($tbl === 'doctors' ? 'dokter' : 'terapis'));
    exit;
}

$q = gp('q');
$where = ['1=1']; $params = [];
if ($scope !== null) { $where[] = 'd.branch_id = ?'; $params[] = $scope; }
if ($q !== '') { $where[] = '(d.name LIKE ? OR d.specialization LIKE ?)'; $t = '%' . $q . '%'; array_push($params, $t, $t); }
$w = implode(' AND ', $where);
$rows = all("SELECT d.*, b.name AS branch_name,
                    (SELECT COUNT(*) FROM appointments a WHERE a." . ($kind === 'dokter' ? 'doctor_id' : 'therapist_id') . " = d.id) appointments
             FROM {$table} d JOIN branches b ON b.id=d.branch_id WHERE {$w} ORDER BY d.name", $params);
$edit = gp('action') === 'edit' ? one("SELECT * FROM {$table} WHERE id = ?", [(int)gp('id')]) : null;
if ($edit) assert_branch((int)$edit['branch_id']);

page_head('Dokter & Terapis', 'staff');
?>
<div class="page-head">
  <div><h2>Dokter &amp; Terapis</h2><p class="muted">Daftar tenaga medis per cabang yang dapat dipilih pada reservasi dan rekam medis.</p></div>
  <div class="page-actions">
    <button class="btn btn-primary" data-modal-open="stModal" onclick="resetStForm()"><?= icon('plus-circle') ?> Tambah <?= e($label) ?></button>
  </div>
</div>

<div class="tabs">
  <a class="tab<?= $kind === 'dokter' ? ' active' : '' ?>" href="staff.php?kind=dokter">Dokter</a>
  <a class="tab<?= $kind === 'terapis' ? ' active' : '' ?>" href="staff.php?kind=terapis">Terapis</a>
</div>

<div class="card tight">
  <form class="filter-bar" method="get">
    <input type="hidden" name="kind" value="<?= $kind ?>">
    <div class="field searchbox"><label>Cari</label><span><?= icon('search') ?></span>
      <input class="input input-sm" name="q" value="<?= e($q) ?>" placeholder="Nama / spesialisasi"></div>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    <a class="btn btn-sm" href="staff.php?kind=<?= $kind ?>">Reset</a>
  </form>
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state('Belum ada data ' . strtolower($label) . '.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Nama</th><th>Telepon</th><th>Spesialisasi</th><th>Jadwal</th><th>Cabang</th><th class="num">Reservasi</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><div class="person-cell"><?= person_avatar($kind === 'dokter' ? 'doctor' : 'therapist', $r, 34) ?>
            <span class="pc-name"><strong><?= e($r['name']) ?></strong></span></div></td>
          <td class="small"><?= e($r['phone'] ?: '-') ?></td>
          <td class="small"><?= e($r['specialization'] ?: '-') ?></td>
          <td class="small"><?= e($r['schedule'] ?: '-') ?></td>
          <td class="small"><?= e($r['branch_name']) ?></td>
          <td class="num"><?= num($r['appointments']) ?></td>
          <td><?= badge($r['status'] === 'active' ? 'Aktif' : 'Nonaktif', $r['status'] === 'active' ? 'green' : 'gray') ?></td>
          <td class="nowrap"><div class="row-actions">
            <button class="btn btn-sm" type="button" data-modal-open="photoModal"
              onclick="photoTarget(<?= (int)$r['id'] ?>, '<?= e(addslashes($r['name'])) ?>', '<?= e(photo_url($kind === 'dokter' ? 'doctor' : 'therapist', $r)) ?>')">Foto</button>
            <a class="btn btn-sm" href="staff.php?kind=<?= $kind ?>&action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
            <form method="post" data-confirm="Ubah status data ini?">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="kind" value="<?= $kind ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm" type="submit"><?= $r['status'] === 'active' ? 'Nonaktif' : 'Aktifkan' ?></button></form>
            <form method="post" data-confirm="Hapus data ini?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="kind" value="<?= $kind ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit">Hapus</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="modal<?= $edit ? ' open' : '' ?>" id="stModal">
  <div class="modal-box">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="kind" value="<?= $kind ?>">
      <input type="hidden" name="id" id="st_id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="modal-head"><h3 id="st_title"><?= $edit ? 'Edit ' : 'Tambah ' ?>Data <?= e($label) ?></h3>
        <button type="button" class="icon-btn" data-modal-close="stModal"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <div class="form-grid g2">
          <div class="field"><label>Nama <span class="req">*</span></label><input class="input" name="name" id="st_name" value="<?= e($edit['name'] ?? '') ?>" required></div>
          <div class="field"><label>Nomor Telepon</label><input class="input" name="phone" id="st_phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
          <div class="field"><label>Spesialisasi</label><input class="input" name="specialization" id="st_spec" value="<?= e($edit['specialization'] ?? '') ?>" placeholder="mis. Kulit & Estetika"></div>
          <div class="field"><label>Jadwal Praktik</label><input class="input" name="schedule" id="st_sched" value="<?= e($edit['schedule'] ?? '') ?>" placeholder="mis. Senin–Jumat 10.00–17.00"></div>
          <?php if (is_owner_level()): ?>
          <div class="field"><label>Cabang <span class="req">*</span></label><select class="input" name="branch_id" id="st_branch"><?= opt_branches($edit['branch_id'] ?? ($scope ?: null)) ?></select></div>
          <?php endif; ?>
          <div class="field"><label>Status</label>
            <select class="input" name="status" id="st_status">
              <option value="active"<?= ($edit['status'] ?? '') === 'active' ? ' selected' : '' ?>>Aktif</option>
              <option value="inactive"<?= ($edit['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Nonaktif</option>
            </select></div>
        </div>
      </div>
      <div class="modal-foot"><button type="button" class="btn" data-modal-close="stModal">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan</button></div>
    </form>
  </div>
</div>
<div class="modal" id="photoModal">
  <div class="modal-box sheet">
    <div class="modal-head"><h3>Foto <?= e($label) ?> — <span id="photoName"></span></h3>
      <button type="button" class="icon-btn" data-modal-close="photoModal"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="photo-thumb-upload">
        <div class="pv" id="photoPreviewWrap" style="display:none"><img id="photoPreview" src="" alt="Foto"></div>
        <div class="grow">
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="photo">
            <input type="hidden" name="kind" value="<?= $kind ?>">
            <input type="hidden" name="id" id="photoId" value="0">
            <div class="field"><label>Unggah Foto</label>
              <input class="input" type="file" name="photo" accept="image/*" required>
              <span class="hint">Otomatis diperkecil &amp; dikompres (maks <?= num((int)setting('photo_max_staff', '480')) ?> px,
                mutu <?= e(setting('photo_quality', '80')) ?>).</span></div>
            <button class="btn btn-primary btn-sm" type="submit"><?= icon('upload') ?> Simpan Foto</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
function photoTarget(id, name, url) {
  document.getElementById('photoId').value = id;
  document.getElementById('photoName').textContent = name;
  var w = document.getElementById('photoPreviewWrap'), img = document.getElementById('photoPreview');
  if (url) { img.src = url; w.style.display = 'block'; } else { img.src = ''; w.style.display = 'none'; }
}
function resetStForm() {
  document.getElementById('st_id').value = 0;
  document.getElementById('st_title').textContent = 'Tambah Data <?= e($label) ?>';
  ['st_name','st_phone','st_spec','st_sched'].forEach(function (id) { document.getElementById(id).value = ''; });
}
</script>
<?php page_foot(); ?>
