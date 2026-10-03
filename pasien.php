<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('patient.view');
$user = current_user();

/* ---------------- Actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'save') {
            require_perm('patient.manage');
            $id      = (int)($_POST['id'] ?? 0);
            $name    = trim((string)$_POST['name']);
            $gender  = (string)$_POST['gender'];
            $nik     = preg_replace('/\s+/', '', (string)$_POST['nik']);
            $phone   = trim((string)$_POST['phone']);
            $email   = trim((string)($_POST['email'] ?? ''));
            $address = trim((string)$_POST['address']);
            $type    = (string)$_POST['patient_type'];
            $birth   = (string)($_POST['birth_date'] ?? '');
            $branch  = is_owner_level() ? resolve_branch_input($_POST['branch_id'] ?? 0) : (int)$user['branch_id'];
            if ($name === '') throw new RuntimeException('Nama lengkap wajib diisi.');
            if ($nik !== '' && !preg_match('/^\d{8,20}$/', $nik)) throw new RuntimeException('NIK harus berupa angka (8–20 digit).');
            if ($phone !== '' && !preg_match('/^[0-9\+\-\s]{8,20}$/', $phone)) throw new RuntimeException('Nomor telepon tidak valid.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Format email tidak valid (mis. nama@email.com).');
            if (!in_array($gender, ['Laki-laki', 'Perempuan'], true)) throw new RuntimeException('Jenis kelamin tidak valid.');
            if (!in_array($type, ['Baru', 'Lama'], true)) $type = 'Baru';
            if ($id > 0) {
                assert_branch((int)$branch);
            } else {
                assert_branch($branch);
            }
            // duplicate detection
            if ($id === 0) {
                $dup = [];
                if ($nik !== '') $dup = all('SELECT * FROM patients WHERE nik = ? AND status = "active"', [$nik]);
                if (!$dup && $phone !== '') $dup = all('SELECT * FROM patients WHERE phone = ? AND status = "active"', [$phone]);
                if ($dup) {
                    $list = implode(', ', array_map(fn($d) => $d['name'] . ' (' . $d['patient_number'] . ')', $dup));
                    throw new RuntimeException('Potensi duplikasi data: ' . $list . '. Periksa kembali NIK/nomor telepon, atau ubah data pasien yang sudah ada.');
                }
            }
            $db = db();
            if ($id > 0) {
                $old = one('SELECT * FROM patients WHERE id = ?', [$id]);
                if (!$old) throw new RuntimeException('Data pasien tidak ditemukan.');
                assert_branch((int)$old['branch_id']);
                q('UPDATE patients SET name=?, gender=?, nik=?, address=?, phone=?, email=?, patient_type=?, birth_date=?, branch_id=?, updated_at=datetime("now","localtime") WHERE id=?',
                    [$name, $gender, $nik, $address, $phone, $email, $type, $birth ?: null, $branch, $id]);
                audit('Edit Pasien', 'Pasien', $id, $old, ['name' => $name, 'phone' => $phone, 'nik' => $nik], 'Perubahan data pasien');
                flash('Data pasien berhasil diperbarui.');
            } else {
                $pnum = next_patient_number($branch);
                $mnum = next_member_number($branch);
                q('INSERT INTO patients (patient_number, member_number, name, gender, nik, address, phone, email, patient_type, birth_date, branch_id, created_by, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,datetime("now","localtime"))',
                    [$pnum, $mnum, $name, $gender, $nik, $address, $phone, $email, $type, $birth ?: null, $branch, $user['id']]);
                $newId = (int)$db->lastInsertId();
                audit('Tambah Pasien', 'Pasien', $newId, null, ['name' => $name, 'patient_number' => $pnum, 'member' => $mnum], 'Registrasi pasien baru');
                flash('Pasien baru terdaftar dengan nomor ' . $pnum . '.');
            }
            header('Location: pasien.php');
            exit;
        }

        if ($act === 'photo') {
            require_perm('patient.manage');
            $id = (int)$_POST['id'];
            $res = photo_save_person('patient', $id, $_FILES['photo'] ?? []);
            flash('Foto pasien tersimpan — ' . img_result_text($res) . '.');
            header('Location: pasien.php');
            exit;
        }
        if ($act === 'member_grant' || $act === 'member_revoke') {
            require_perm('patient.manage');
            $id = (int)$_POST['id'];
            $row = one('SELECT * FROM patients WHERE id = ?', [$id]);
            if (!$row) throw new RuntimeException('Pasien tidak ditemukan.');
            assert_branch((int)$row['branch_id']);
            if ($act === 'member_grant') {
                $ok = grant_member_card($id, 'manual');
                audit('Aktifkan Kartu Member', 'Pasien', $id, ['member' => 0], ['member' => 1, 'sumber' => 'manual'],
                    'Kartu member diaktifkan petugas');
                flash($ok ? 'Kartu member ' . $row['name'] . ' diaktifkan. Kartu digital dapat diunduh di halaman pasien.'
                    : 'Pasien ini sudah memiliki kartu member.');
            } else {
                revoke_member_card($id);
                audit('Cabut Kartu Member', 'Pasien', $id, ['member' => 1], ['member' => 0], 'Status member dicabut petugas');
                flash('Status member ' . $row['name'] . ' dicabut (nomor member tetap tersimpan).', 'warning');
            }
            header('Location: pasien.php');
            exit;
        }
        if ($act === 'photo_delete') {
            require_perm('patient.manage');
            $id = (int)$_POST['id'];
            $row = one('SELECT * FROM patients WHERE id = ?', [$id]);
            if (!$row) throw new RuntimeException('Pasien tidak ditemukan.');
            assert_branch((int)$row['branch_id']);
            if (!empty($row['photo_file'])) {
                $f = photo_dir('patient') . '/' . basename((string)$row['photo_file']);
                if (is_file($f)) @unlink($f);
            }
            q('UPDATE patients SET photo_file = NULL, photo_updated_at = datetime("now","localtime") WHERE id = ?', [$id]);
            audit('Hapus Foto Pasien', 'Pasien', $id, null, null, 'Foto pasien dihapus');
            flash('Foto pasien dihapus.');
            header('Location: pasien.php');
            exit;
        }
        if ($act === 'delete_hard') {
            // Hanya Super Admin — menghapus pasien beserta seluruh data terkaitnya.
            if (!is_super()) deny('Hanya Super Admin yang boleh menghapus data pasien secara permanen.');
            $id = (int)$_POST['id'];
            $p = one('SELECT * FROM patients WHERE id = ?', [$id]);
            if (!$p) throw new RuntimeException('Data pasien tidak ditemukan.');
            $counts = [
                'orders' => (int)scalar('SELECT COUNT(*) FROM orders WHERE patient_id = ?', [$id]),
                'medical' => (int)scalar('SELECT COUNT(*) FROM medical_records WHERE patient_id = ?', [$id]),
                'appointments' => (int)scalar('SELECT COUNT(*) FROM appointments WHERE patient_id = ?', [$id]),
            ];
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') throw new RuntimeException('Alasan penghapusan wajib diisi.');
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                q('DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE patient_id = ?)', [$id]);
                q('DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE patient_id = ?)', [$id]);
                q('DELETE FROM orders WHERE patient_id = ?', [$id]);
                q('DELETE FROM medical_record_photos WHERE medical_record_id IN (SELECT id FROM medical_records WHERE patient_id = ?)', [$id]);
                q('DELETE FROM medical_records WHERE patient_id = ?', [$id]);
                /* Daftar treatment reservasi harus dihapus sebelum reservasinya
                   (foreign_keys=ON). */
                q('DELETE FROM appointment_treatments WHERE appointment_id IN (SELECT id FROM appointments WHERE patient_id = ?)', [$id]);
                q('DELETE FROM appointments WHERE patient_id = ?', [$id]);
                q('DELETE FROM patients WHERE id = ?', [$id]);
                $pdo->exec('COMMIT');
            } catch (Throwable $ex) {
                $pdo->exec('ROLLBACK');
                throw new RuntimeException('Gagal menghapus: ' . $ex->getMessage());
            }
            audit('HAPUS PERMANEN Pasien', 'Pasien', $id, $p, $counts, $reason);
            flash('Data pasien "' . $p['name'] . '" beserta ' . $counts['orders'] . ' transaksi, '
                . $counts['medical'] . ' rekam medis, dan ' . $counts['appointments'] . ' reservasi telah dihapus permanen.', 'warning');
            header('Location: pasien.php');
            exit;
        }

        if ($act === 'delete') {
            require_perm('patient.manage');
            $id = (int)$_POST['id'];
            $p = one('SELECT * FROM patients WHERE id = ?', [$id]);
            if (!$p) throw new RuntimeException('Data pasien tidak ditemukan.');
            assert_branch((int)$p['branch_id']);
            q('UPDATE patients SET status = "inactive", updated_at = datetime("now","localtime") WHERE id = ?', [$id]);
            audit('Nonaktifkan Pasien', 'Pasien', $id, $p, ['status' => 'inactive'], trim((string)($_POST['reason'] ?? 'Dihapus oleh user')));
            flash('Data pasien dinonaktifkan. Riwayat transaksi tetap tersimpan.');
            header('Location: pasien.php');
            exit;
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
        header('Location: pasien.php');
        exit;
    }
}

/* ---------------- Filters & list ---------------- */
$q      = gp('q');
$type   = gp('type');
$page   = page_no();
$pp     = per_page();
$where  = ['1=1'];
$params = [];
$sc = scope_branch();
if ($sc !== null) { $where[] = 'p.branch_id = ?'; $params[] = $sc; }
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.nik LIKE ? OR p.phone LIKE ? OR p.email LIKE ? OR p.member_number LIKE ? OR p.patient_number LIKE ?)';
    $t = '%' . $q . '%';
    array_push($params, $t, $t, $t, $t, $t, $t);
}
if ($type !== '') { $where[] = 'p.patient_type = ?'; $params[] = $type; }
if (gp('status') === 'inactive') { $where[] = 'p.status = "inactive"'; } else { $where[] = 'p.status = "active"'; }
$w = implode(' AND ', $where);

$total = (int)scalar("SELECT COUNT(*) FROM patients p WHERE {$w}", $params);
$rows  = all("SELECT p.*, b.name AS branch_name,
                     (SELECT COUNT(*) FROM orders o WHERE o.patient_id = p.id AND o.status='paid') AS visits,
                     (SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.patient_id = p.id AND o.status='paid') AS spent
              FROM patients p JOIN branches b ON b.id = p.branch_id
              WHERE {$w} ORDER BY p.id DESC LIMIT {$pp} OFFSET " . (($page - 1) * $pp), $params);

/* Jumlah data terkait per pasien (untuk peringatan sebelum hapus permanen) */
$linked = [];
if (is_owner_level() && $rows) {
    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (all("SELECT patient_id, COUNT(*) n FROM orders WHERE patient_id IN ({$in}) GROUP BY patient_id", $ids) as $r) {
        $linked[(int)$r['patient_id']]['orders'] = (int)$r['n'];
    }
    foreach (all("SELECT patient_id, COUNT(*) n FROM medical_records WHERE patient_id IN ({$in}) GROUP BY patient_id", $ids) as $r) {
        $linked[(int)$r['patient_id']]['medical'] = (int)$r['n'];
    }
    foreach (all("SELECT patient_id, COUNT(*) n FROM appointments WHERE patient_id IN ({$in}) GROUP BY patient_id", $ids) as $r) {
        $linked[(int)$r['patient_id']]['appointments'] = (int)$r['n'];
    }
}

$edit = null;
if (gp('action') === 'edit') {
    $edit = one('SELECT * FROM patients WHERE id = ?', [(int)gp('id')]);
    if ($edit) assert_branch((int)$edit['branch_id']);
}
$openModal = (gp('action') === 'new' || $edit) ? 'patientModal' : '';

page_head('Data Pasien', 'pasien');
?>
<div class="page-head">
  <div><h2>Data Pasien</h2><p class="muted">Database pasien <?= e(is_owner_level() && scope_branch() === null ? 'seluruh cabang' : 'cabang Anda') ?>.</p></div>
  <div class="page-actions">
    <?php if (has_perm('patient.manage')): ?>
      <button class="btn btn-primary" data-modal-open="patientModal" onclick="resetPatientForm()"><?= icon('plus-circle') ?> Tambah Pasien</button>
    <?php endif; ?>
    <?php if (has_perm('patient.manage')): ?><a class="btn" href="import.php?type=pasien"><?= icon('upload') ?> Import</a><?php endif; ?>
    <?php if (has_perm('export.data') && has_perm('patient.view')): ?>
      <a class="btn" href="export.php?type=pasien&format=excel&<?= e(qs([], ['page', 'per_page'])) ?>"><?= icon('download') ?> Excel</a>
      <a class="btn btn-primary" href="export.php?type=pasien&format=xlsx&<?= e(qs([], ['page', 'per_page'])) ?>"
         title="Excel asli berisi data pasien + lembar foto pasien"><?= icon('download') ?> Excel + Foto</a>
      <a class="btn" href="export.php?type=pasien&format=csv&<?= e(qs([], ['page', 'per_page'])) ?>">CSV</a>
      <a class="btn" href="export.php?type=pasien&format=pdf&<?= e(qs([], ['page', 'per_page'])) ?>" target="_blank">PDF</a>
    <?php endif; ?>
    <?php if (is_super()): ?>
      <a class="btn btn-danger" href="purge.php?menu=pasien"
         title="Khusus Super Admin — hapus seluruh data menu ini">Hapus Semua Data Pasien</a>
    <?php endif; ?>
  </div>
</div>

<div class="card tight">
  <form class="filter-bar" method="get">
    <div class="field searchbox">
      <label>Cari</label>
      <span><?= icon('search') ?></span>
      <input class="input input-sm" type="text" name="q" value="<?= e($q) ?>" placeholder="Nama / NIK / telepon / email / nomor member">
    </div>
    <div class="field">
      <label>Status Pasien</label>
      <select class="input input-sm" name="type">
        <option value="">Semua</option>
        <?php foreach (['Baru', 'Lama'] as $t): ?><option value="<?= $t ?>"<?= $type === $t ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php if (is_owner_level()): ?>
    <div class="field">
      <label>Cabang</label>
      <select class="input input-sm" name="branch">
        <option value="all"<?= scope_branch() === null ? ' selected' : '' ?>>Semua Cabang</option>
        <?php foreach (branches() as $b): ?><option value="<?= (int)$b['id'] ?>"<?= scope_branch() === (int)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    <a class="btn btn-sm" href="pasien.php">Reset</a>
    <?= per_page_inline() ?>
  </form>
  <div class="table-wrap">
    <?php if (!$rows): ?>
      <?= empty_state('Belum ada data pasien yang cocok dengan filter.') ?>
    <?php else: ?>
    <table class="tbl">
      <thead><tr>
        <th>No. Pasien</th><th>Nama</th><th>JK</th><th>NIK</th><th>Telepon</th><th>Email</th><th>Member</th>
        <th>Status</th><th>Cabang</th><th class="num">Kunjungan</th><th class="num">Total Belanja</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= e($r['patient_number']) ?><div class="small muted"><?= e(tgl($r['created_at'])) ?></div></td>
          <td><div class="person-cell"><?= person_avatar('patient', $r, 34) ?>
            <span class="pc-name"><a href="pasien_detail.php?id=<?= (int)$r['id'] ?>"><strong><?= e($r['name']) ?></strong></a></span></div></td>
          <td><?= e($r['gender'] ?: '-') ?></td>
          <td class="nowrap"><?= e($r['nik'] ?: '-') ?></td>
          <td class="nowrap"><?= e($r['phone'] ?: '-') ?></td>
          <td class="small"><?= ($r['email'] ?? '') !== '' ? e($r['email']) : '<span class="muted">-</span>' ?></td>
          <td class="nowrap"><?= e($r['member_number'] ?: '-') ?>
            <?php if ((int)($r['member_card'] ?? 0) === 1): ?>
              <div><span class="badge badge-yellow" title="Pemegang kartu member">KARTU MEMBER</span></div>
            <?php endif; ?>
          </td>
          <td><?= badge($r['patient_type'] ?: 'Baru', $r['patient_type'] === 'Lama' ? 'blue' : 'pink') ?><?= $r['status'] !== 'active' ? ' ' . badge('Nonaktif', 'gray') : '' ?></td>
          <td class="small"><?= e($r['branch_name']) ?></td>
          <td class="num"><?= num($r['visits']) ?></td>
          <td class="num"><?= money($r['spent']) ?></td>
          <td class="nowrap">
            <div class="row-actions">
              <a class="btn btn-sm" href="pasien_detail.php?id=<?= (int)$r['id'] ?>">Detail</a>
              <?php if (member_card_enabled() && (int)($r['member_card'] ?? 0) === 1): ?>
                <a class="btn btn-sm" href="member_card.php?id=<?= (int)$r['id'] ?>" title="Kartu member digital (dapat diunduh)"><?= icon('star') ?> Kartu</a>
              <?php endif; ?>
              <?php if (has_perm('patient.manage')): ?>
                <button class="btn btn-sm" type="button" data-modal-open="photoModal"
                        onclick="photoTarget(<?= (int)$r['id'] ?>, '<?= e(addslashes($r['name'])) ?>', '<?= e(photo_url('patient', $r)) ?>')">Foto</button>
                <a class="btn btn-sm" href="pasien.php?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                <?php if (member_card_enabled() && (int)($r['member_card'] ?? 0) !== 1): ?>
                  <form method="post" data-confirm="Aktifkan kartu member untuk pasien ini?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="member_grant"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-leaf" type="submit" title="Aktifkan kartu member">+ Member</button>
                  </form>
                <?php endif; ?>
                <form method="post" data-confirm="Nonaktifkan pasien ini? Riwayat transaksi tetap tersimpan.">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm" type="submit">Nonaktifkan</button>
                </form>
                <?php if (is_super()): $lk = $linked[(int)$r['id']] ?? []; ?>
                <form method="post"
                      data-heavy-confirm="HAPUS"
                      data-heavy-warning="Menghapus <strong><?= e($r['name']) ?></strong> (<?= e($r['patient_number']) ?>) secara <strong>permanen</strong> beserta:<br>
                        • <?= num($lk['orders'] ?? 0) ?> transaksi &amp; pembayarannya<br>
                        • <?= num($lk['medical'] ?? 0) ?> rekam medis (termasuk foto klinis)<br>
                        • <?= num($lk['appointments'] ?? 0) ?> reservasi<br><br>
                        Riwayat keuangan pasien ini akan hilang dari laporan. Bila hanya ingin menonaktifkan, gunakan tombol <em>Nonaktifkan</em>."
                      data-heavy-confirm2="PERINGATAN KEDUA: seluruh data pasien <?= e($r['name']) ?>, termasuk <?= num($lk['orders'] ?? 0) ?> transaksi dan <?= num($lk['medical'] ?? 0) ?> rekam medis, akan dihapus PERMANEN dari database dan tidak dapat dikembalikan. Lanjutkan?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_hard">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="reason" value="Dihapus permanen oleh Super Admin dari daftar pasien">
                  <button class="btn btn-sm btn-danger" type="submit" title="Hapus permanen (Super Admin)">Hapus Permanen</button>
                </form>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?= pagination($total, $pp, $page) ?>
</div>

<div class="modal<?= $openModal ? ' open' : '' ?>" id="patientModal">
  <div class="modal-box">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="p_id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="modal-head"><h3 id="p_title"><?= $edit ? 'Edit Data Pasien' : 'Tambah Pasien Baru' ?></h3>
        <button type="button" class="icon-btn" data-modal-close="patientModal"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <div class="form-grid g2">
          <div class="field"><label>Nama Lengkap <span class="req">*</span></label>
            <input class="input" name="name" id="p_name" value="<?= e($edit['name'] ?? '') ?>" required></div>
          <div class="field"><label>Jenis Kelamin <span class="req">*</span></label>
            <select class="input" name="gender" id="p_gender">
              <?php foreach (['Perempuan', 'Laki-laki'] as $g): ?>
                <option value="<?= $g ?>"<?= ($edit['gender'] ?? '') === $g ? ' selected' : '' ?>><?= $g ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>NIK</label>
            <input class="input" name="nik" id="p_nik" value="<?= e($edit['nik'] ?? '') ?>" inputmode="numeric" placeholder="16 digit"></div>
          <div class="field"><label>Nomor Telepon / WhatsApp</label>
            <input class="input" name="phone" id="p_phone" value="<?= e($edit['phone'] ?? '') ?>" placeholder="08xxxxxxxxxx"></div>
          <div class="field"><label>Email <span class="muted small">(opsional)</span></label>
            <input class="input" type="email" name="email" id="p_email" value="<?= e($edit['email'] ?? '') ?>"
                   placeholder="nama@email.com" autocomplete="email">
            <span class="hint">Dipakai untuk mengirim struk transaksi &amp; pengingat ke email pasien.</span></div>
          <div class="field"><label>Tanggal Lahir</label>
            <input class="input" type="date" name="birth_date" id="p_birth" value="<?= e($edit['birth_date'] ?? '') ?>"></div>
          <div class="field"><label>Status Pasien</label>
            <select class="input" name="patient_type" id="p_type">
              <?php foreach (['Baru', 'Lama'] as $t): ?>
                <option value="<?= $t ?>"<?= ($edit['patient_type'] ?? '') === $t ? ' selected' : '' ?>><?= $t ?></option>
              <?php endforeach; ?>
            </select>
              <span class="hint"><?= e(patient_type_rule_text()) ?></span></div>
          <?php if (is_owner_level()): ?>
          <div class="field"><label>Cabang <span class="req">*</span></label>
            <select class="input" name="branch_id" id="p_branch"><?= opt_branches($edit['branch_id'] ?? scope_branch()) ?></select></div>
          <?php endif; ?>
        </div>
        <div class="field mt-2"><label>Alamat</label>
          <textarea class="input" name="address" id="p_address"><?= e($edit['address'] ?? '') ?></textarea></div>
        <div class="notice mt-2">Nomor pasien dan nomor member dibuat otomatis oleh sistem saat data disimpan.</div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" data-modal-close="patientModal">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan Data Pasien</button>
      </div>
    </form>
  </div>
</div>
<div class="modal" id="photoModal">
  <div class="modal-box sheet">
    <div class="modal-head"><h3>Foto Pasien — <span id="photoName"></span></h3>
      <button type="button" class="icon-btn" data-modal-close="photoModal"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="photo-thumb-upload">
        <div class="pv" id="photoPreviewWrap" style="display:none"><img id="photoPreview" src="" alt="Foto pasien"></div>
        <div class="grow">
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="photo">
            <input type="hidden" name="id" id="photoId" value="0">
            <div class="field"><label>Unggah Foto</label>
              <input class="input" type="file" name="photo" accept="image/*" required>
              <span class="hint">Foto otomatis diperkecil &amp; dikompres (maks <?= num((int)setting('photo_max_patient', '480')) ?> px,
                mutu <?= e(setting('photo_quality', '80')) ?>) supaya hemat penyimpanan meski dipakai untuk ribuan pasien.</span></div>
            <button class="btn btn-primary btn-sm" type="submit"><?= icon('upload') ?> Simpan Foto</button>
          </form>
          <form method="post" class="mt-1" id="photoDeleteForm" style="display:none"
                data-confirm="Hapus foto pasien ini?">
            <?= csrf_field() ?><input type="hidden" name="action" value="photo_delete">
            <input type="hidden" name="id" id="photoDeleteId" value="0">
            <button class="btn btn-sm btn-danger" type="submit">Hapus Foto</button>
          </form>
          <div class="notice mt-2">Foto pasien bersifat <strong>privat</strong>: disimpan di luar area publik dan hanya
            dapat dilihat oleh user yang sudah masuk serta berhak atas cabangnya.</div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
function photoTarget(id, name, url) {
  document.getElementById('photoId').value = id;
  document.getElementById('photoDeleteId').value = id;
  document.getElementById('photoName').textContent = name;
  var w = document.getElementById('photoPreviewWrap');
  var img = document.getElementById('photoPreview');
  var del = document.getElementById('photoDeleteForm');
  if (url) { img.src = url; w.style.display = 'block'; del.style.display = 'block'; }
  else { img.src = ''; w.style.display = 'none'; del.style.display = 'none'; }
}
function resetPatientForm() {
  var f = { p_id: 0, p_name: '', p_nik: '', p_phone: '', p_email: '', p_address: '', p_birth: '' };
  Object.keys(f).forEach(function (k) { var el = document.getElementById(k); if (el) el.value = f[k]; });
  document.getElementById('p_title').textContent = 'Tambah Pasien Baru';
}
</script>
<?php page_foot(); ?>
