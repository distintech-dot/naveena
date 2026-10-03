<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('medical.view');
$user = current_user();

function mr_load(int $id): ?array
{
    $m = one('SELECT m.*, p.name AS patient_name, p.patient_number, p.phone AS patient_phone, p.gender, b.name AS branch_name
              FROM medical_records m JOIN patients p ON p.id=m.patient_id JOIN branches b ON b.id=m.branch_id
              WHERE m.id = ?', [$id]);
    if ($m) assert_branch((int)$m['branch_id']);
    return $m;
}
/** Simpan satu foto rekam medis: gambar dikompres otomatis, keterangan disimpan. */
function mr_store_photo(int $mrId, string $name, string $tmp, int $size, string $caption): void
{
    allowed_upload($name, $size);
    $kind = media_kind($name);
    $isImage = $kind === 'image';
    $res = null;
    $dims = null;
    $stored = null;
    $bytes = $size;

    if ($isImage) {
        /* Kompres otomatis (perkecil + mutu JPEG) supaya foto klinis dari HP
           tidak menghabiskan ruang penyimpanan; keterangan & dimensi dicatat. */
        $res = img_process_upload($tmp, $name, photo_dir('medical'), 'mr' . $mrId, 'medical');
        $stored = $res['file'];
        $dims = $res['width'] . '×' . $res['height'];
        $bytes = (int)$res['bytes'];
    } else {
        // dokumen (PDF/dll) tidak dikompres, disimpan apa adanya
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
        $stored = 'mr' . $mrId . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!@rename($tmp, photo_dir('medical') . '/' . $stored) && !@copy($tmp, photo_dir('medical') . '/' . $stored)) {
            throw new RuntimeException('Gagal menyimpan lampiran.');
        }
    }

    /* Cadangan di penyimpanan media platform: hanya untuk dokumen, karena foto
       (data privat pasien) tidak boleh berada di URL publik. */
    $remote = '';
    $storage = 'local';
    if (!$isImage && media_token() !== '') {
        try {
            $remote = media_upload(photo_dir('medical') . '/' . $stored, $name);
            $storage = 'remote';
        } catch (Throwable $ex) { $remote = ''; }
    }
    q('INSERT INTO medical_record_photos (medical_record_id, file_url, storage, local_path, type, caption, file_size, dimensions, uploaded_by)
       VALUES (?,?,?,?,?,?,?,?,?)',
        [$mrId, $remote, $storage, $stored, $kind, $caption !== '' ? $caption : null, $bytes, $dims, current_user()['id'] ?? null]);
    if ($res) {
        q('UPDATE medical_records SET updated_at = datetime("now","localtime") WHERE id = ?', [$mrId]);
    }
}

function mr_upload_photos(int $mrId, array $files, string $caption): int
{
    $n = 0;
    $errs = [];
    $savedTotal = 0;
    for ($i = 0; $i < count($files['name']); $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = (int)($files['error'][$i] ?? -1);
            if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
                $errs[] = (string)$files['name'][$i] . ': ukuran berkas melebihi batas server (' . ini_get('upload_max_filesize') . ').';
            }
            continue;
        }
        $name = (string)$files['name'][$i];
        try {
            $before = (int)($files['size'][$i] ?? 0);
            mr_store_photo($mrId, $name, (string)$files['tmp_name'][$i], $before, $caption);
            $n++;
        } catch (Throwable $ex) {
            $errs[] = $name . ': ' . $ex->getMessage();
        }
    }
    if ($errs) flash('Sebagian foto gagal diunggah — ' . implode(' | ', $errs), 'warning');
    return $n;
}

/**
 * Blok tombol "Simpan Rekam Medis" + Batal beserta penanda "belum disimpan".
 * Dipakai dua tempat: DI DALAM <form> (rekam medis baru) dan DI LUAR <form>
 * yaitu di bawah kolom "Foto & Lampiran Klinis" (permintaan pengguna) — pada
 * kasus kedua tombol memakai atribut form="mrForm" agar tetap mengirim form.
 */
function mr_actions_block(bool $outsideForm): string
{
    ob_start(); ?>
<div class="form-actions" id="mrActions">
  <div class="unsaved-note" id="unsavedNote" role="status" aria-live="polite">
    <span class="ico-wrap"><?= icon('bell') ?></span>
    <span>Ada perubahan yang <strong>belum disimpan</strong>. Klik <strong>Simpan Rekam Medis</strong> agar tersimpan —
      jika Anda keluar tanpa menyimpan, data tetap sama seperti semula.</span>
  </div>
  <div class="flex">
    <button class="btn btn-primary" type="submit"<?= $outsideForm ? ' form="mrForm"' : '' ?> id="mrSaveBtn"><?= icon('file-medical') ?> Simpan Rekam Medis</button>
    <a class="btn" href="rekam_medis.php">Batal</a>
  </div>
</div>
    <?php return (string)ob_get_clean();
}

/* ---------------- Actions ---------------- */if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'save' || $act === 'amend') {
            require_perm('medical.manage');
            $id      = (int)($_POST['id'] ?? 0);
            $patient = (int)($_POST['patient_id'] ?? 0);
            $date    = (string)$_POST['date'];
            $doctor  = (int)($_POST['doctor_id'] ?? 0);
            $ther    = (int)($_POST['therapist_id'] ?? 0);
            $staff   = ($doctor ? (string)scalar('SELECT name FROM doctors WHERE id=?', [$doctor], '') : ($ther ? (string)scalar('SELECT name FROM therapists WHERE id=?', [$ther], '') : ''));
            $branch  = is_owner_level() ? resolve_branch_input($_POST['branch_id'] ?? 0) : (int)$user['branch_id'];
            $data = [
                'subjective' => trim((string)($_POST['subjective'] ?? '')),
                'objective'  => trim((string)($_POST['objective'] ?? '')),
                'diagnosis'  => trim((string)($_POST['diagnosis'] ?? '')),
                'icd10'      => trim((string)($_POST['icd10'] ?? '')),
                'action'     => trim((string)($_POST['action_med'] ?? '')),
                'icd9'       => trim((string)($_POST['icd9'] ?? '')),
                'solution'   => trim((string)($_POST['solution'] ?? '')),
            ];
            /* Deskripsi ICD selalu diambil ulang dari kamus resmi supaya yang
               tersimpan konsisten dengan kode (tidak bergantung input klien). */
            $icd10row = icd_lookup('icd10', $data['icd10']);
            $icd9row  = icd_lookup('icd9cm', $data['icd9']);
            if ($data['icd10'] !== '' && !$icd10row) {
                throw new RuntimeException('Kode ICD-10 "' . $data['icd10'] . '" tidak ada di kamus resmi. Pilih kode dari daftar saran yang muncul saat mengetik.');
            }
            if ($data['icd9'] !== '' && !$icd9row) {
                throw new RuntimeException('Kode ICD-9-CM "' . $data['icd9'] . '" tidak ada di kamus resmi. Pilih kode dari daftar saran yang muncul saat mengetik.');
            }
            $icd10desc = $icd10row ? (string)($icd10row['name_id'] ?: $icd10row['name_en']) : null;
            $icd9desc  = $icd9row  ? (string)($icd9row['name_id'] ?: $icd9row['name_en']) : null;
            /* Status penanganan: Proses / Selesai / Terjadwal */
            $statusIn = (string)($_POST['record_status'] ?? 'Proses');
            if (!in_array($statusIn, RECORD_STATUSES, true)) $statusIn = 'Proses';
            if (!$patient) throw new RuntimeException('Pasien wajib dipilih.');
            if (!$date) throw new RuntimeException('Tanggal wajib diisi.');
            $pat = one('SELECT * FROM patients WHERE id = ?', [$patient]);
            if (!$pat) throw new RuntimeException('Pasien tidak ditemukan.');
            assert_branch((int)$pat['branch_id']);
            assert_branch($branch);
            /* CABANG REKAM MEDIS = CABANG PASIEN (perbaikan ronde 37).
               Pemakai lintas cabang (Super Admin/Direktur) dulu bisa menyimpan
               rekam medis Kaliwungu untuk pasien Cepiring karena kolom Cabang
               dipilih manual. Rekam medis selalu milik cabang pasien (termasuk
               penentu cabang pada nomor RM dan cakupan laporan). */
            if (user_branch() === null) $branch = (int)$pat['branch_id'];

            if ($act === 'amend') {
                $src = mr_load($id);
                if (!$src) throw new RuntimeException('Rekam medis asal tidak ditemukan.');
                $num = next_medical_number((int)$src['branch_id']);
                q('INSERT INTO medical_records (record_number, patient_id, doctor_id, therapist_id, staff_name, branch_id, date,
                      subjective, objective, diagnosis, icd10, icd10_desc, action, icd9, icd9_desc, solution, status, amended_from, created_by, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime("now","localtime"))',
                    [$num, $patient, $doctor ?: null, $ther ?: null, $staff, $branch, $date, $data['subjective'], $data['objective'],
                     $data['diagnosis'], $data['icd10'], $icd10desc, $data['action'], $data['icd9'], $icd9desc, $data['solution'], 'amendment', (int)$src['id'], $user['id']]);
                $newId = (int)db()->lastInsertId();
                if (!empty($_FILES['photos']['name'][0])) mr_upload_photos($newId, $_FILES['photos'], 'Amendment of ' . $src['record_number']);
                audit('Amendment Rekam Medis', 'Rekam Medis', $newId, ['from' => $src['record_number']], ['number' => $num], 'Perbaikan rekam medis terkunci');
                try { patient_sync_type($patient, (int)$user['id']); } catch (Throwable $e) { /* status pasien tidak boleh menghalangi simpan */ }
                flash('Amendment rekam medis ' . $num . ' dibuat. Rekam medis asal tetap tersimpan.');
                header('Location: rekam_medis_form.php?id=' . $newId);
                exit;
            }

            if ($id > 0) {
                $old = mr_load($id);
                if (!$old) throw new RuntimeException('Rekam medis tidak ditemukan.');
                if ($old['locked_at']) throw new RuntimeException('Rekam medis sudah dikunci. Gunakan Amendment untuk memperbaiki data.');
                $new = array_merge($data, ['date' => $date, 'doctor_id' => $doctor ?: null, 'therapist_id' => $ther ?: null,
                    'staff_name' => $staff, 'icd10_desc' => $icd10desc, 'icd9_desc' => $icd9desc, 'status' => $statusIn]);
                q('UPDATE medical_records SET date=?, doctor_id=?, therapist_id=?, staff_name=?, subjective=?, objective=?, diagnosis=?, icd10=?, icd10_desc=?, action=?, icd9=?, icd9_desc=?, solution=?,
                   status=?, branch_id=?, updated_by=?, updated_at=datetime("now","localtime") WHERE id=?',
                    [$date, $doctor ?: null, $ther ?: null, $staff, $data['subjective'], $data['objective'], $data['diagnosis'],
                     $data['icd10'], $icd10desc, $data['action'], $data['icd9'], $icd9desc, $data['solution'], $statusIn, $branch, $user['id'], $id]);
                audit('Edit Rekam Medis', 'Rekam Medis', $id, $old, $new, 'Perubahan rekam medis');
                if (!empty($_FILES['photos']['name'][0])) mr_upload_photos($id, $_FILES['photos'], '');
                flash('Rekam medis berhasil diperbarui.');
                header('Location: rekam_medis_form.php?id=' . $id);
                exit;
            }
            $num = next_medical_number($branch);
            q('INSERT INTO medical_records (record_number, patient_id, doctor_id, therapist_id, staff_name, branch_id, date,
                  subjective, objective, diagnosis, icd10, icd10_desc, action, icd9, icd9_desc, solution, status, created_by, created_at)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime("now","localtime"))',
                [$num, $patient, $doctor ?: null, $ther ?: null, $staff, $branch, $date, $data['subjective'], $data['objective'],
                 $data['diagnosis'], $data['icd10'], $icd10desc, $data['action'], $data['icd9'], $icd9desc, $data['solution'], $statusIn, $user['id']]);
            $newId = (int)db()->lastInsertId();
            if (!empty($_FILES['photos']['name'][0])) mr_upload_photos($newId, $_FILES['photos'], '');
            audit('Tambah Rekam Medis', 'Rekam Medis', $newId, null, ['number' => $num, 'patient' => $pat['name'], 'diagnosis' => $data['diagnosis']], 'Rekam medis baru');
            try { patient_sync_type($patient, (int)$user['id']); } catch (Throwable $e) { /* status pasien tidak boleh menghalangi simpan */ }
            flash('Rekam medis ' . $num . ' berhasil disimpan.');
            header('Location: rekam_medis_form.php?id=' . $newId);
            exit;
        }

        if ($act === 'lock') {
            require_perm('medical.manage');
            $m = mr_load((int)$_POST['id']);
            if (!$m) throw new RuntimeException('Rekam medis tidak ditemukan.');
            q('UPDATE medical_records SET locked_at=datetime("now","localtime"), status="final", updated_by=?, updated_at=datetime("now","localtime") WHERE id=?', [$user['id'], $m['id']]);
            audit('Kunci Rekam Medis', 'Rekam Medis', $m['id'], ['locked' => false], ['locked' => true], 'Rekam medis difinalkan dan dikunci');
            flash('Rekam medis dikunci. Perubahan selanjutnya harus melalui Amendment.');
            header('Location: rekam_medis_form.php?id=' . $m['id']);
            exit;
        }

        if ($act === 'photo_upload') {
            require_perm('medical.manage');
            $m = mr_load((int)$_POST['id']);
            if (!$m) throw new RuntimeException('Rekam medis tidak ditemukan.');
            if (empty($_FILES['photos']['name'][0])) throw new RuntimeException('Pilih file foto terlebih dahulu.');
            $n = mr_upload_photos((int)$m['id'], $_FILES['photos'], trim((string)($_POST['caption'] ?? '')));
            audit('Upload Foto Rekam Medis', 'Rekam Medis', $m['id'], null, ['jumlah' => $n], 'Upload foto klinis');
            if ($n) flash($n . ' foto berhasil diunggah.');
            header('Location: rekam_medis_form.php?id=' . $m['id']);
            exit;
        }

        if ($act === 'photo_delete') {
            require_perm('medical.manage');
            $p = one('SELECT ph.*, m.branch_id, m.locked_at FROM medical_record_photos ph JOIN medical_records m ON m.id=ph.medical_record_id WHERE ph.id = ?', [(int)$_POST['pid']]);
            if (!$p) throw new RuntimeException('Foto tidak ditemukan.');
            assert_branch((int)$p['branch_id']);
            if ($p['locked_at']) throw new RuntimeException('Rekam medis terkunci — foto tidak dapat diubah.');
            if ($p['storage'] === 'local' && $p['local_path']) @unlink(local_upload_dir() . '/' . $p['local_path']);
            q('DELETE FROM medical_record_photos WHERE id = ?', [$p['id']]);
            audit('Hapus Foto Rekam Medis', 'Rekam Medis', (int)$p['medical_record_id'], ['file' => $p['file_url'] ?: $p['local_path']], null, 'Hapus foto klinis');
            flash('Foto dihapus.');
            header('Location: rekam_medis_form.php?id=' . (int)$p['medical_record_id']);
            exit;
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
        header('Location: ' . (($_POST['id'] ?? 0) ? 'rekam_medis_form.php?id=' . (int)$_POST['id'] : 'rekam_medis_form.php'));
        exit;
    }
}

$id    = (int)gp('id');
$isNew = $id === 0;
$rec   = $isNew ? null : mr_load($id);
if (!$isNew && !$rec) {
    flash('Rekam medis tidak ditemukan.', 'error');
    header('Location: rekam_medis.php');
    exit;
}
$mode = gp('action', 'view');
if ($isNew) $mode = 'edit';
$locked = $rec && $rec['locked_at'];
$readonly = ($mode === 'view' || $locked || !has_perm('medical.manage'));
$photos = $rec ? all('SELECT * FROM medical_record_photos WHERE medical_record_id = ? ORDER BY id', [$id]) : [];
$scope = scope_branch();
/* Daftar dokter/terapis dihitung SETELAH cabang pasien diketahui — lihat blok
   "CABANG FORMULIR" di bawah (ronde 37: pilihan mengikuti cabang yang dipilih). */
$amendOf = $rec && $rec['amended_from'] ? one('SELECT record_number FROM medical_records WHERE id=?', [$rec['amended_from']]) : null;

/* ---- Pra-isi dari RESERVASI ("Isi Rekam Medis" pada daftar reservasi) ----
   Nilai awal saja: tanggal, dokter, dan terapis diambil dari reservasi supaya
   petugas tidak mengetik ulang. Semuanya TETAP BISA DIUBAH pada form ini
   (bukan data terkunci), dan hanya dipakai saat membuat rekam medis BARU agar
   data rekam medis yang sudah tersimpan tidak pernah tertimpa. */
$resv = null;
$resvId = (int)gp('reservation_id');
if ($isNew && $resvId > 0) {
    $resv = one('SELECT a.*, p.name AS patient_name, p.patient_number FROM appointments a
                 JOIN patients p ON p.id = a.patient_id WHERE a.id = ?', [$resvId]);
    if ($resv) {
        assert_branch((int)$resv['branch_id']);
        if ((int)($resv['patient_id']) !== (int)($rec['patient_id'] ?? 0)) {
            /* Pasien mengikuti reservasi bila belum ditentukan di URL. */
            if ((int)gp('patient_id') === 0) $_GET['patient_id'] = (int)$resv['patient_id'];
        }
    } else {
        $resv = null;
    }
}
/* Nilai awal field (reservasi dipakai lebih dulu, lalu data rekam medis).
   Termasuk NAMA PASIEN supaya kolom pencarian pasien tidak kosong saat masuk
   dari reservasi / tombol "Rekam Medis Baru" di detail pasien — dulu kolomnya
   kosong sehingga nama harus dicari ulang (keluhan pemilik klinik). */
$prefDate  = (string)($resv['date'] ?? ($rec['date'] ?? date('Y-m-d')));
$prefDoc   = (int)($resv['doctor_id'] ?? ($rec['doctor_id'] ?? 0));
$prefTher  = (int)($resv['therapist_id'] ?? ($rec['therapist_id'] ?? 0));
$prefPatientId  = (int)($rec['patient_id'] ?? $resv['patient_id'] ?? gp('patient_id'));
$prefPatientTxt = (string)($rec['patient_name'] ?? $resv['patient_name'] ?? '');
if ($prefPatientTxt === '' && $prefPatientId > 0) {
    $pp = one('SELECT name, patient_number FROM patients WHERE id = ?', [$prefPatientId]);
    if ($pp) $prefPatientTxt = $pp['name'] . ' (' . $pp['patient_number'] . ')';
} elseif ($prefPatientTxt !== '' && $prefPatientId > 0 && strpos($prefPatientTxt, '(') === false) {
    $pp = one('SELECT patient_number FROM patients WHERE id = ?', [$prefPatientId]);
    if ($pp) $prefPatientTxt .= ' (' . $pp['patient_number'] . ')';
}

/* ============================================================================
 * CABANG FORMULIR REKAM MEDIS + DAFTAR DOKTER/TERAPIS (ronde 37)
 *
 * Urutan penentu cabang (data lebih menentukan daripada pilihan di layar):
 *   1. cabang rekam medis yang sedang dibuka/diedit,
 *   2. cabang PASIEN (dari tombol "Rekam Medis Baru" di detail pasien maupun
 *      dari reservasi),
 *   3. cakupan filter halaman,
 *   4. cabang pertama (cadangan).
 * Pilihan dokter & terapis MENGIKUTI cabang itu, sehingga tidak mungkin lagi
 * memilih dokter cabang lain saat mengisi rekam medis pasien Kaliwungu.
 * ========================================================================== */
$formBranchId = 0;
if ($rec) $formBranchId = (int)$rec['branch_id'];
if ($formBranchId <= 0 && $prefPatientId > 0) {
    $formBranchId = (int)scalar('SELECT branch_id FROM patients WHERE id = ?', [$prefPatientId], 0);
}
if ($formBranchId <= 0 && $resv) $formBranchId = (int)$resv['branch_id'];
if ($formBranchId <= 0 && $scope !== null) $formBranchId = (int)$scope;
if ($formBranchId <= 0) {
    $fb = selectable_branches();
    if ($fb) $formBranchId = (int)$fb[0]['id'];
}
$branchFilter = $formBranchId > 0
    ? ' AND branch_id = ' . $formBranchId
    : ($scope !== null ? ' AND branch_id = ' . (int)$scope : '');
$doctorList    = all('SELECT id,name FROM doctors WHERE status="active"' . $branchFilter . ' ORDER BY name');
$therapistList = all('SELECT id,name FROM therapists WHERE status="active"' . $branchFilter . ' ORDER BY name');
$formBranchName = $formBranchId > 0
    ? (string)scalar('SELECT name FROM branches WHERE id = ?', [$formBranchId], '') : 'semua cabang';

page_head($isNew ? 'Rekam Medis Baru' : 'Rekam Medis ' . ($rec['record_number'] ?? ''), 'rekam_medis');
?>
<?php if ($resv): ?>
  <div class="alert alert-success" data-autohide="1">
    Formulir diisi otomatis dari <strong>Reservasi <?= e($resv['appointment_number']) ?></strong>
    (pasien <strong><?= e($resv['patient_name']) ?></strong>, tanggal <?= e(tgl($resv['date'])) ?>) —
    semua kolom <strong>masih bisa diubah</strong> sebelum disimpan.
  </div>
<?php endif; ?>
<div class="page-head">
  <div>
    <h2><?= $isNew ? 'Rekam Medis Baru' : 'Rekam Medis ' . e($rec['record_number']) ?></h2>
    <p class="muted">
      <?php if ($rec): ?>
        <?= e($rec['patient_name']) ?> · <?= e($rec['patient_number']) ?> · <?= e($rec['branch_name']) ?>
        <?= $locked ? ' · ' . badge('LOCKED', 'gray') : '' ?>
        <?= $amendOf ? ' · Amendment dari ' . e($amendOf['record_number']) : '' ?>
      <?php else: ?>
        Catat hasil pemeriksaan dan tindakan pasien.
      <?php endif; ?>
    </p>
  </div>
  <div class="page-actions">
    <a class="btn" href="rekam_medis.php"><?= icon('file-medical') ?> Daftar Rekam Medis</a>
    <?php
    /* Tombol "Lanjutkan ke Transaksi": muncul HANYA bila status penanganan belum
       "Selesai" (permintaan pemilik klinik). Transaksinya langsung terisi pasien
       yang sama; rekam medis yang sudah Selesai disembunyikan tombolnya. */
    if (!$isNew && has_perm('order.manage') && ($rec['status'] ?? 'Proses') !== 'Selesai'): ?>
      <a class="btn btn-primary" href="order_baru.php?branch_id=<?= (int)$rec['branch_id'] ?>&patient_id=<?= (int)$rec['patient_id'] ?>"
         title="Buat transaksi untuk pasien ini (terisi otomatis) — tersedia selama status belum Selesai">
        <?= icon('receipt') ?> Lanjutkan ke Transaksi</a>
    <?php endif; ?>
    <?php if (!$isNew): ?><button class="btn" data-print><?= icon('print') ?> Print</button><?php endif; ?>
    <?php if (!$readonly && !$isNew): ?><a class="btn btn-primary" href="rekam_medis_form.php?action=edit&id=<?= $id ?>"><?= icon('edit') ?> Edit</a><?php endif; ?>
    <?php if (!$isNew && !$locked && has_perm('medical.manage')): ?>
      <form method="post" class="inline-form" data-confirm="Kunci rekam medis ini? Setelah dikunci, perubahan hanya bisa melalui Amendment.">
        <?= csrf_field() ?><input type="hidden" name="action" value="lock"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn btn-leaf" type="submit"><?= icon('lock') ?> Kunci Rekam Medis</button>
      </form>
    <?php endif; ?>
    <?php if ($locked && has_perm('medical.manage')): ?>
      <a class="btn" href="rekam_medis_form.php?action=amend&id=<?= $id ?>">Buat Amendment</a>
    <?php endif; ?>
    <?php if (!$isNew && is_owner_level()): ?>
      <form method="post" action="rekam_medis.php"
            data-heavy-confirm="HAPUS"
            data-heavy-warning="Menghapus <strong>permanen</strong> rekam medis <strong><?= e($rec['record_number']) ?></strong> milik <?= e($rec['patient_name']) ?> beserta <?= num(count($photos)) ?> foto/lampiran. Data ini hilang dari histori pasien dan tidak dapat dikembalikan."
            data-heavy-confirm2="PERINGATAN KEDUA: rekam medis <?= e($rec['record_number']) ?> akan dihapus PERMANEN. Lanjutkan?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_hard">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="reason" value="Dihapus permanen oleh Super Admin dari halaman rekam medis">
        <button class="btn btn-danger" type="submit">Hapus Permanen</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<form method="post" enctype="multipart/form-data" id="mrForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="<?= $mode === 'amend' ? 'amend' : 'save' ?>">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="patient_id" id="mr_patient_id" value="<?= $prefPatientId ?>">

  <div class="card">
    <div class="card-head"><h3>Identitas Pemeriksaan</h3></div>
    <div class="card-body">
      <div class="form-grid g4">
        <div class="field"><label>Pasien <span class="req">*</span></label>
          <?php if ($readonly && $rec): ?>
            <div class="person-cell mb-1">
              <?php $prow = one('SELECT * FROM patients WHERE id = ?', [(int)$rec['patient_id']]); ?>
              <?= person_avatar('patient', $prow ?: [], 40) ?>
              <input class="input" value="<?= e($rec['patient_name']) ?>" disabled>
            </div>
          <?php else: ?>
            <div class="searchbox"><span><?= icon('search') ?></span>
              <input class="input" id="mr_patient_search" placeholder="Cari pasien" autocomplete="off"
                     value="<?= e($prefPatientTxt) ?>" <?= $readonly ? 'disabled' : '' ?>>
              <div class="suggest" id="mr_patient_suggest"></div>
            </div>
          <?php endif; ?>
        </div>
        <div class="field"><label>Tanggal <span class="req">*</span></label>
          <input class="input" type="date" name="date" value="<?= e($prefDate) ?>" <?= $readonly ? 'disabled' : 'required' ?>></div>
        <div class="field"><label>Dokter</label>
          <select class="input" name="doctor_id" <?= $readonly ? 'disabled' : '' ?>>
            <option value="">- tidak ada -</option>
            <?php foreach ($doctorList as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $prefDoc === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label>Terapis</label>
          <select class="input" name="therapist_id" <?= $readonly ? 'disabled' : '' ?>>
            <option value="">- tidak ada -</option>
            <?php foreach ($therapistList as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $prefTher === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
          </select></div>
        <?php if (is_owner_level()): ?>
        <div class="field"><label>Cabang</label>
          <select class="input" name="branch_id" id="mr_branch" data-mr-branch <?= $readonly ? 'disabled' : '' ?>><?= opt_branches($formBranchId) ?></select>
          <span class="hint">Pilihan <strong>pasien, dokter, dan terapis</strong> mengikuti cabang ini.
            Rekam medis selalu tersimpan pada cabang pasien.</span></div>
        <?php endif; ?>
        <div class="field" style="grid-column:1/-1">
          <label>Status Penanganan <span class="req">*</span></label>
          <?php
          $curStatus = in_array((string)($rec['status'] ?? ''), RECORD_STATUSES, true) ? $rec['status'] : 'Proses';
          $statusHint = [
              'Proses' => 'Treatment sedang dalam proses (1x treatment).',
              'Selesai' => 'Seluruh treatment yang direncanakan sudah selesai.',
              'Terjadwal' => 'Sudah ada jadwal treatment berikutnya.',
          ];
          ?>
          <div class="status-picker">
            <?php foreach (RECORD_STATUSES as $st): ?>
              <label class="status-opt<?= $curStatus === $st ? ' active' : '' ?>">
                <input type="radio" name="record_status" value="<?= e($st) ?>"<?= $curStatus === $st ? ' checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                <span class="st-name"><?= e($st) ?></span>
                <span class="st-desc"><?= e($statusHint[$st]) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <?php if ($rec && $rec['locked_at']): ?>
            <span class="hint">Rekam medis terkunci — status ikut terkunci. Gunakan Amendment untuk mengubahnya.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Catatan Klinis (SOAP)</h3><span class="muted">Subjektif · Objektif · Assessment · Planning</span></div>
    <div class="card-body">
      <div class="form-grid g2">
        <div class="field"><label>Subjektif (S)</label><textarea class="input" name="subjective" <?= $readonly ? 'disabled' : '' ?>><?= e($rec['subjective'] ?? '') ?></textarea></div>
        <div class="field"><label>Objektif (O)</label><textarea class="input" name="objective" <?= $readonly ? 'disabled' : '' ?>><?= e($rec['objective'] ?? '') ?></textarea></div>
        <div class="field"><label>Assessment / Diagnosa (A)</label><textarea class="input" name="diagnosis" <?= $readonly ? 'disabled' : '' ?>><?= e($rec['diagnosis'] ?? '') ?></textarea></div>
        <div class="field">
          <label>ICD-10 (penunjang Assessment) <span class="hint-inline">— ketik kode atau nama, saran muncul otomatis</span></label>
          <?php if (!$readonly): ?>
            <div class="searchbox"><span><?= icon('search') ?></span>
              <input class="input" id="icd10_input" name="icd10" autocomplete="off"
                     placeholder="mis. L70.0 atau jerawat" value="<?= e($rec['icd10'] ?? '') ?>">
              <div class="suggest" id="icd10_suggest"></div>
            </div>
          <?php else: ?>
            <input class="input" value="<?= e($rec['icd10'] ?? '') ?>" disabled>
          <?php endif; ?>
          <?php $hint10 = 'Kode mengikuti kamus resmi ICD-10 (WHO) — sama dengan referensi Satu Sehat. ' . num(icd_count('icd10')) . ' kode tersedia.'; ?>
          <span class="hint" id="icd10_status"><?= $rec && $rec['icd10'] ? e($rec['icd10_desc'] ?: '') : e($hint10) ?></span>
          <input type="hidden" name="icd10_desc" id="icd10_desc" value="<?= e($rec['icd10_desc'] ?? '') ?>">
        </div>
        <div class="field"><label>Tindakan</label><textarea class="input" name="action_med" <?= $readonly ? 'disabled' : '' ?>><?= e($rec['action'] ?? '') ?></textarea></div>
        <div class="field">
          <label>ICD-9-CM (Tindakan) <span class="hint-inline">— ketik kode atau nama, saran muncul otomatis</span></label>
          <?php if (!$readonly): ?>
            <div class="searchbox"><span><?= icon('search') ?></span>
              <input class="input" id="icd9_input" name="icd9" autocomplete="off"
                     placeholder="mis. 99.83 atau phototherapy" value="<?= e($rec['icd9'] ?? '') ?>">
              <div class="suggest" id="icd9_suggest"></div>
            </div>
          <?php else: ?>
            <input class="input" value="<?= e($rec['icd9'] ?? '') ?>" disabled>
          <?php endif; ?>
          <?php $hint9 = 'Kode mengikuti kamus resmi ICD-9-CM Volume 3 (prosedur). ' . num(icd_count('icd9cm')) . ' kode tersedia.'; ?>
          <span class="hint" id="icd9_status"><?= $rec && $rec['icd9'] ? e($rec['icd9_desc'] ?: '') : e($hint9) ?></span>
          <input type="hidden" name="icd9_desc" id="icd9_desc" value="<?= e($rec['icd9_desc'] ?? '') ?>">
        </div>
        <div class="field" style="grid-column:1/-1"><label>Planning (P)</label><textarea class="input" name="solution" <?= $readonly ? 'disabled' : '' ?>><?= e($rec['solution'] ?? '') ?></textarea></div>
      </div>
    </div>
    <?php if (!$readonly): ?>
    <div class="card-body" style="border-top:1px solid var(--line)">
      <div class="form-grid g2">
        <div class="field"><label>Foto / Lampiran</label><input class="input" type="file" name="photos[]" multiple accept="image/*,application/pdf"></div>
        <div class="notice">Format: JPG/PNG/WebP/PDF. Foto klinis hanya dapat diakses oleh user yang berhak melalui sistem.</div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php /* Rekam medis BARU: belum ada kartu foto, jadi tombol simpan tetap di dalam form. */ ?>
  <?php if (!$readonly && !$rec): ?><?= mr_actions_block(false) ?><?php endif; ?>
</form>

<?php if ($rec): ?>
<?php
/* Tombol kelola foto HANYA di mode edit: di mode "Lihat" rekam medis bersifat
   baca-saja (tidak ada unggah/hapus foto), sesuai permintaan pengguna. */
$canPhotoManage = ($mode !== 'view') && !$locked && has_perm('medical.manage');
?>
<div class="card">
  <div class="card-head">
    <h3>Foto &amp; Lampiran Klinis</h3>
    <span class="muted"><?= num(count($photos)) ?> file<?= $canPhotoManage ? ' · hapus foto langsung tersimpan' : ' · mode lihat (baca-saja)' ?></span>
  </div>
  <div class="card-body">
    <?php if (!$photos): ?>
      <?= empty_state('Belum ada foto atau lampiran.') ?>
    <?php else: ?>
      <div class="photo-grid">
        <?php foreach ($photos as $ph): ?>
          <figure>
            <?php if ($ph['type'] === 'image'): ?>
              <a href="media.php?id=<?= (int)$ph['id'] ?>" target="_blank"><img src="media.php?id=<?= (int)$ph['id'] ?>" alt="<?= e($ph['caption'] ?: 'Foto klinis') ?>"></a>
            <?php else: ?>
              <div class="ph-doc"><?= icon('file-medical') ?><div class="small">Dokumen</div></div>
            <?php endif; ?>
            <figcaption>
              <span class="cap<?= $ph['caption'] ? '' : ' none' ?>"><?= e($ph['caption'] ?: 'Tanpa keterangan') ?></span>
              <span class="cap-meta">
                <a href="media.php?id=<?= (int)$ph['id'] ?>" target="_blank">Buka</a>
                <span class="muted small"><?= e(tgl($ph['created_at'])) ?></span>
              </span>
              <?php if (!empty($ph['dimensions']) || !empty($ph['file_size'])): ?>
                <span class="cap-meta small muted">
                  <?= e($ph['dimensions'] ?: '') ?><?= $ph['dimensions'] && $ph['file_size'] ? ' · ' : '' ?><?= $ph['file_size'] ? num(round(((int)$ph['file_size']) / 1024, 1), 1) . ' KB' : '' ?>
                </span>
              <?php endif; ?>
              <?php if ($canPhotoManage): ?>
                <form method="post" class="mt-1" data-confirm="Hapus foto/lampiran ini beserta keterangannya? Foto langsung hilang dari rekam medis dan tidak dapat dikembalikan.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="photo_delete">
                  <input type="hidden" name="pid" value="<?= (int)$ph['id'] ?>">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash') ?> Hapus Foto</button>
                </form>
              <?php endif; ?>
            </figcaption>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($canPhotoManage): ?>
      <form method="post" enctype="multipart/form-data" class="flex flex-wrap gap-lg mt-2">
        <?= csrf_field() ?><input type="hidden" name="action" value="photo_upload"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="field"><label>Tambah Foto</label><input class="input" type="file" name="photos[]" multiple required accept="image/*"></div>
        <div class="field"><label>Keterangan</label><input class="input" name="caption" placeholder="mis. kondisi sebelum treatment">
          <span class="hint">Keterangan ini akan tampil di bawah fotonya dan ikut tersimpan di rekam medis.</span></div>
        <button class="btn btn-primary" type="submit">Upload</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$readonly): ?>
<?php /* Tombol simpan diletakkan di bawah kolom "Foto & Lampiran Klinis".
        Tombol memakai atribut form="mrForm" karena berada di luar <form> utama
        (kartu foto berisi form unggah/hapus miliknya sendiri). */ ?>
<?= mr_actions_block(true) ?>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3>Riwayat Rekam Medis Pasien</h3><a class="btn btn-sm" href="pasien_detail.php?id=<?= (int)$rec['patient_id'] ?>">Profil Pasien</a></div>
  <div class="table-wrap">
    <?php
    $hist = all('SELECT id, record_number, date, diagnosis, status, locked_at, amended_from FROM medical_records
                 WHERE patient_id = ? ORDER BY date DESC, id DESC LIMIT 20', [$rec['patient_id']]);
    ?>
    <?php if (!$hist): ?><?= empty_state('Belum ada riwayat.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Nomor</th><th>Tanggal</th><th>Assessment / Diagnosa</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($hist as $h): ?>
        <tr<?= (int)$h['id'] === $id ? ' style="background:#FFF3F8"' : '' ?>>
          <td class="small"><?= e($h['record_number']) ?><?= $h['amended_from'] ? ' <span class="badge badge-yellow">amendment</span>' : '' ?></td>
          <td><?= e(tgl($h['date'])) ?></td>
          <td><?= e(item_short((string)$h['diagnosis'], 60) ?: '-') ?></td>
          <td><?= record_status_badge($h['status']) ?></td>
          <td><a class="btn btn-sm" href="rekam_medis_form.php?id=<?= (int)$h['id'] ?>">Lihat</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script>
var MR_FORM_BRANCH = <?= (int)$formBranchId ?>;
var MR_PARAMS = { branch: MR_FORM_BRANCH };
/* Muat ulang dokter & terapis sesuai cabang (memakai api.php?a=res_form yang
   mengembalikan ketiganya — treatment tidak dipakai di halaman ini). */
function mrLoadBranchLists(bid) {
  if (!bid) return;
  fetch('api.php?a=res_form&ajax=1&branch=' + encodeURIComponent(bid), { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d || !d.ok) return;
      MR_FORM_BRANCH = Number(d.branch || bid);
      MR_PARAMS.branch = MR_FORM_BRANCH;
      var isi = function (sel, list) {
        if (!sel) return;
        var lama = sel.value;
        sel.innerHTML = '<option value="">- tidak ada -</option>'
          + list.map(function (x) { return '<option value="' + x.id + '">' + x.name + '</option>'; }).join('');
        if (lama && list.some(function (x) { return String(x.id) === String(lama); })) sel.value = lama;
      };
      isi(document.querySelector('select[name=doctor_id]'), d.doctors || []);
      isi(document.querySelector('select[name=therapist_id]'), d.therapists || []);
    })
    .catch(function () { /* jaringan bermasalah: biarkan pilihan lama */ });
}
document.addEventListener('DOMContentLoaded', function () {
  <?php if (!$readonly && $isNew): ?>
  var mrBranchSel = document.getElementById('mr_branch');
  if (mrBranchSel) mrBranchSel.addEventListener('change', function () { mrLoadBranchLists(mrBranchSel.value); });
  /* ===== PASIEN, DOKTER, TERAPIS MENGIKUTI CABANG (ronde 37) =====
     • pencarian pasien dibatasi ke cabang yang dipilih (objek MR_PARAMS dipakai
       langsung oleh Naveena.suggest, jadi cukup diubah nilainya),
     • memilih pasien dari cabang lain → kolom Cabang ikut berpindah ke cabang
       pasien & daftar dokter/terapis dimuat ulang (rekam medis memang milik
       cabang pasien),
     • memindahkan kolom Cabang → daftar dokter/terapis dimuat ulang juga. */
  Naveena.suggest({ input: '#mr_patient_search', box: '#mr_patient_suggest', action: 'patient',
    params: MR_PARAMS,
    onPick: function (it) {
      document.getElementById('mr_patient_id').value = it.id;
      document.getElementById('mr_patient_search').value = it.name + ' (' + it.number + ')';
      var sel = document.getElementById('mr_branch');
      if (sel && it.branch_id && Number(it.branch_id) !== Number(sel.value)) {
        sel.value = it.branch_id;
        mrLoadBranchLists(it.branch_id);
      }
    } });
  <?php endif; ?>

  /* Status Penanganan: radio aslinya disembunyikan oleh CSS
     (.status-opt input {opacity:0; pointer-events:none}) sehingga satu-satunya
     penanda pilihan adalah kelas `.active` pada kartunya. Tanpa penanganan ini
     kelas tersebut tidak pernah berpindah saat pilihan diklik, jadi status
     tampak "tidak bisa dipilih" walau radio-nya sebenarnya berubah. Di sini
     penanda itu disinkronkan dengan pilihan yang benar-benar aktif. */
  (function () {
    var pick = document.querySelector('.status-picker');
    if (!pick) return;
    var radios = pick.querySelectorAll('input[name="record_status"]');
    if (!radios.length) return;
    function syncStatus() {
      Array.prototype.forEach.call(pick.querySelectorAll('.status-opt'), function (lab) {
        var r = lab.querySelector('input');
        lab.classList.toggle('active', !!(r && r.checked));
      });
    }
    Array.prototype.forEach.call(radios, function (r) {
      r.addEventListener('change', syncStatus);
      /* Klik pada kartu juga menandai pilihan (mis. saat label diklik
         programatik atau lewat keyboard). */
      var lab = r.closest('.status-opt');
      if (lab) lab.addEventListener('click', function () { setTimeout(syncStatus, 0); });
    });
    syncStatus();
  })();

  <?php if (!$readonly): ?>
  /* Penjaga PERUBAHAN BELUM DISIMPAN pada form rekam medis:
     - data hanya tersimpan bila tombol "Simpan Rekam Medis" benar-benar ditekan,
     - setiap akan menyimpan, perubahan dikonfirmasi lebih dulu,
     - bila keluar/muat ulang tanpa menyimpan, pengguna diperingatkan lebih dulu
       sehingga data di sistem tetap sama seperti sebelumnya. */
  (function () {
    var f = document.getElementById('mrForm');
    if (!f || !Naveena.dirtyGuard) return;
    Naveena.dirtyGuard(f, {
      note: '#unsavedNote',
      mark: '#mrSaveBtn',
      confirmSave: 'Simpan perubahan pada rekam medis ini?',
      leaveMessage: 'Perubahan pada rekam medis BELUM disimpan.\n\nKlik OK untuk tetap meninggalkan halaman (perubahan tidak tersimpan), atau Cancel untuk kembali dan menekan tombol Simpan.'
    });
  })();
  <?php endif; ?>

  /* Kamus ICD-10 & ICD-9-CM: ketik -> saran muncul, deskripsi ikut tersimpan. */
  Naveena.suggest({ input: '#icd10_input', box: '#icd10_suggest', action: 'icd10', min: 1,
    onPick: function (it) {
      document.getElementById('icd10_input').value = it.code;
      document.getElementById('icd10_desc').value = it.name;
      document.getElementById('icd10_status').textContent = it.name;
      document.getElementById('icd10_status').className = 'hint icd-ok';
    } });
  Naveena.suggest({ input: '#icd9_input', box: '#icd9_suggest', action: 'icd9cm', min: 1,
    onPick: function (it) {
      document.getElementById('icd9_input').value = it.code;
      document.getElementById('icd9_desc').value = it.name;
      document.getElementById('icd9_status').textContent = it.name;
      document.getElementById('icd9_status').className = 'hint icd-ok';
    } });

  /* Verifikasi terhadap kamus resmi: kode di luar kamus ditandai sebelum disimpan. */
  function verify(inputId, descId, statusId, kind) {
    var inp = document.getElementById(inputId);
    if (!inp) return;
    inp.addEventListener('blur', async function () {
      var v = inp.value.trim();
      if (v === '') { document.getElementById(descId).value = ''; return; }
      try {
        var d = await Naveena.get('api.php', { a: 'icd_check', kind: kind, code: v });
        var st = document.getElementById(statusId);
        if (d.found) {
          document.getElementById(descId).value = d.item.name;
          st.textContent = d.item.name;
          st.className = 'hint icd-ok';
        } else {
          document.getElementById(descId).value = '';
          st.textContent = 'Kode "' + v + '" tidak ditemukan di kamus resmi ' + (kind === 'icd10' ? 'ICD-10' : 'ICD-9-CM') + '. Pilih dari daftar saran.';
          st.className = 'hint icd-warn';
        }
      } catch (e) { /* biarkan, verifikasi server tetap berlaku saat simpan */ }
    });
  }
  verify('icd10_input', 'icd10_desc', 'icd10_status', 'icd10');
  verify('icd9_input', 'icd9_desc', 'icd9_status', 'icd9cm');
});
</script>
<?php page_foot(); ?>
