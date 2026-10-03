<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('reservation.view');
$user = current_user();


/* HAPUS DATA PER PERIODE (khusus Super Admin) — ditangani lebih dulu karena
   memakai jalur verifikasi sendiri (password + konfirmasi 2 tahap). */
retention_handle_post('reservasi');

/* ---------------- Actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'save') {
            require_perm('reservation.manage');
            $id      = (int)($_POST['id'] ?? 0);
            $patient = (int)($_POST['patient_id'] ?? 0);
            $date    = (string)$_POST['date'];
            $time    = (string)($_POST['time'] ?? '');
            $doctor  = (int)($_POST['doctor_id'] ?? 0);
            $therapist = (int)($_POST['therapist_id'] ?? 0);
            /* Treatment boleh LEBIH DARI SATU. Kolom lama treatment_id tetap diisi
               dengan treatment PERTAMA supaya pesan WA, ekspor, dan detail pasien
               (yang membaca kolom itu) tetap benar. */
            $treatIds = [];
            foreach ((array)($_POST['treatments'] ?? []) as $t) {
                $t = (int)$t;
                if ($t > 0 && !in_array($t, $treatIds, true)) $treatIds[] = $t;
            }
            $treatment = $treatIds ? (int)$treatIds[0] : (int)($_POST['treatment_id'] ?? 0);
            $status  = in_array((string)$_POST['status'], RES_STATUSES, true) ? (string)$_POST['status'] : 'Menunggu';
            $notes   = trim((string)($_POST['notes'] ?? ''));
            $branch  = is_owner_level() ? resolve_branch_input($_POST['branch_id'] ?? 0) : (int)$user['branch_id'];
            if (!$patient) throw new RuntimeException('Pasien wajib dipilih.');
            if (!$date) throw new RuntimeException('Tanggal reservasi wajib diisi.');
            $pat = one('SELECT * FROM patients WHERE id = ?', [$patient]);
            if (!$pat) throw new RuntimeException('Pasien tidak ditemukan.');
            assert_branch((int)$pat['branch_id']);
            assert_branch($branch);
            if ($id > 0) {
                $old = one('SELECT * FROM appointments WHERE id = ?', [$id]);
                if (!$old) throw new RuntimeException('Reservasi tidak ditemukan.');
                assert_branch((int)$old['branch_id']);
                q('UPDATE appointments SET patient_id=?, doctor_id=?, therapist_id=?, treatment_id=?, branch_id=?, date=?, time=?, status=?, notes=?, updated_at=datetime("now","localtime") WHERE id=?',
                    [$patient, $doctor ?: null, $therapist ?: null, $treatment ?: null, $branch, $date, $time, $status, $notes, $id]);
                res_save_treatments($id, $treatIds ?: [$treatment]);
                audit('Edit Reservasi', 'Reservasi', $id, $old,
                    ['date' => $date, 'status' => $status, 'treatment' => count($treatIds) ?: ($treatment ? 1 : 0)],
                    'Perubahan data reservasi');
                flash('Reservasi berhasil diperbarui.');
            } else {
                $num = next_appointment_number($branch);
                q('INSERT INTO appointments (appointment_number, patient_id, doctor_id, therapist_id, treatment_id, branch_id, date, time, status, notes, created_by, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime("now","localtime"))',
                    [$num, $patient, $doctor ?: null, $therapist ?: null, $treatment ?: null, $branch, $date, $time, $status, $notes, $user['id']]);
                $newId = (int)db()->lastInsertId();
                res_save_treatments($newId, $treatIds ?: [$treatment]);
                audit('Tambah Reservasi', 'Reservasi', $newId, null,
                    ['number' => $num, 'date' => $date, 'patient' => $pat['name'], 'treatment' => count($treatIds) ?: ($treatment ? 1 : 0)],
                    'Reservasi baru');
                flash('Reservasi ' . $num . ' berhasil dibuat.');
            }
            header('Location: reservasi.php');
            exit;
        }

        if ($act === 'status') {
            require_perm('reservation.manage');
            $id = (int)$_POST['id'];
            $st = (string)$_POST['status'];
            if (!in_array($st, RES_STATUSES, true)) throw new RuntimeException('Status tidak valid.');
            $a = one('SELECT * FROM appointments WHERE id = ?', [$id]);
            if (!$a) throw new RuntimeException('Reservasi tidak ditemukan.');
            assert_branch((int)$a['branch_id']);
            q('UPDATE appointments SET status=?, updated_at=datetime("now","localtime") WHERE id=?', [$st, $id]);
            audit('Ubah Status Reservasi', 'Reservasi', $id, ['status' => $a['status']], ['status' => $st], 'Perubahan status reservasi');
            flash('Status reservasi diperbarui menjadi ' . $st . '.');
            header('Location: ' . ($_POST['back'] ?? 'reservasi.php'));
            exit;
        }

        if ($act === 'wa_doctor_send') {
            require_perm('reservation.manage');
            $a = res_full((int)$_POST['id']);
            if (!$a) throw new RuntimeException('Reservasi tidak ditemukan.');
            assert_branch((int)$a['branch_id']);
            if (empty($a['doctor_id'])) throw new RuntimeException('Reservasi ini belum memiliki dokter.');
            $doc = one('SELECT * FROM doctors WHERE id = ?', [(int)$a['doctor_id']]);
            if (!$doc) throw new RuntimeException('Data dokter tidak ditemukan.');
            $phone = trim((string)($doc['phone'] ?? ''));
            if ($phone === '') throw new RuntimeException('Nomor WhatsApp dokter ' . $doc['name'] . ' belum diisi. Lengkapi di menu Dokter & Terapis.');
            $msg = wa_doctor_message($a + ['doctor_name' => $doc['name']]);
            $err = '';
            $ok = wa_api_configured() ? wa_api_send($phone, $msg, $err) : false;
            audit($ok ? 'Kirim WhatsApp Dokter' : 'Siapkan WhatsApp Dokter', 'Reservasi', (int)$a['id'], null,
                ['dokter' => $doc['name'], 'tujuan' => $phone, 'terkirim' => $ok],
                $ok ? 'Pengingat jadwal dikirim ke dokter via API' : 'Pengingat disiapkan untuk dikirim dari WhatsApp');
            flash($ok ? 'Pengingat terkirim ke dokter ' . $doc['name'] . '.' : $err, $ok ? 'success' : 'warning');
            header('Location: reservasi.php');
            exit;
        }

        if ($act === 'wa_send') {
            require_perm('reservation.manage');
            $a = res_full((int)$_POST['id']);
            if (!$a) throw new RuntimeException('Reservasi tidak ditemukan.');
            assert_branch((int)$a['branch_id']);
            $msg = wa_reservation_message($a);
            $err = '';
            $ok = wa_api_send((string)$a['patient_phone'], $msg, $err);
            audit($ok ? 'Kirim WhatsApp Reservasi' : 'Gagal Kirim WhatsApp', 'Reservasi', (int)$a['id'], null, ['to' => $a['patient_phone']], $ok ? 'Pesan terkirim via API' : (string)$err);
            flash($ok ? 'Pesan WhatsApp terkirim ke ' . $a['patient_phone'] . '.' : (string)$err, $ok ? 'success' : 'warning');
            header('Location: reservasi.php');
            exit;
        }

        if ($act === 'email') {
            require_perm('reservation.manage');
            $id = (int)$_POST['id'];
            $to = trim((string)$_POST['to']);
            $a  = res_full($id);
            assert_branch((int)$a['branch_id']);
            /* Pengaman kirim ulang (ronde 39): konfirmasi lebih dulu bila email
               konfirmasi reservasi ini sudah pernah dikirim ke alamat yang sama. */
            $emKey = 'Reservasi ' . (string)$a['appointment_number'] . ' ' . strtolower($to);
            $emRiwayat = email_resend_notice($emKey, 'Email konfirmasi reservasi ini');
            if ($emRiwayat['blocked'] && (string)($_POST['confirm_resend'] ?? '') !== '1') {
                flash($emRiwayat['block_msg'], 'warning');
                header('Location: reservasi.php');
                exit;
            }
            if ($emRiwayat['needs'] && (string)($_POST['confirm_resend'] ?? '') !== '1') {
                flash($emRiwayat['notice'] . ' Tekan lagi tombol Kirim Email bila memang ingin mengirim ulang.', 'warning');
                header('Location: reservasi.php');
                exit;
            }
            $html = '<h3>Konfirmasi Reservasi ' . e($a['appointment_number']) . '</h3>'
                . '<p>Halo ' . e($a['patient_name']) . ',</p>'
                . '<p>Berikut detail reservasi Anda di ' . e($a['branch_name']) . ':</p><ul>'
                . '<li>Tanggal: ' . e(tgl($a['date'])) . '</li><li>Jam: ' . e($a['time']) . '</li>'
                . '<li>Treatment: ' . e($a['treatments_all'] ?: '-') . '</li>'
                . '<li>Dokter/Terapis: ' . e(staff_both_text($a['doctor_name'] ?? '', $a['therapist_name'] ?? '') ?: '-') . '</li></ul>'
                . '<p>Mohon konfirmasi kehadiran Anda. Terima kasih.</p>';
            $err = '';
            $ok = send_email($to, 'Reservasi ' . $a['appointment_number'] . ' — ' . clinic_name(), $html, $err);
            q('INSERT INTO email_report_logs (recipient, period, status, message) VALUES (?,?,?,?)',
              [$to, 'Reservasi ' . $a['appointment_number'] . ' ' . strtolower($to), $ok ? 'sent' : 'failed', $ok ? 'Terkirim' : (string)$err]);
            audit($ok ? 'Kirim Email Reservasi' : 'Gagal Kirim Email Reservasi', 'Reservasi', $id, null, ['to' => $to], (string)$err);
            flash($ok ? 'Email konfirmasi reservasi terkirim ke ' . $to . '.' : (string)$err, $ok ? 'success' : 'warning');
            header('Location: reservasi.php');
            exit;
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
        header('Location: reservasi.php');
        exit;
    }
}

function res_full(int $id): array
{
    $row = one('SELECT a.*, p.name AS patient_name, p.phone AS patient_phone, b.name AS branch_name,
                       t.name AS treatment_name,
                       COALESCE(d.name, th.name) AS staff_name,
                       d.name AS doctor_name, th.name AS therapist_name
                FROM appointments a
                JOIN patients p ON p.id = a.patient_id
                JOIN branches b ON b.id = a.branch_id
                LEFT JOIN treatments t ON t.id = a.treatment_id
                LEFT JOIN doctors d ON d.id = a.doctor_id
                LEFT JOIN therapists th ON th.id = a.therapist_id
                WHERE a.id = ?', [$id]) ?: [];
    if ($row) {
        /* Semua treatment (utama + tambahan) untuk pesan WA/email reservasi. */
        $names = array_values(array_filter(array_map(fn($t) => (string)($t['name'] ?? ''), res_treatments((int)$id))));
        $row['treatments_all'] = $names ? implode(', ', $names) : (string)($row['treatment_name'] ?? '');
    }
    return $row;
}

/* ---------------- Data for views ---------------- */
$view = gp('view', 'list');
$q = gp('q');
$status = gp('status');
$page = page_no();
$pp = per_page();
$scope = scope_branch();

$where = ['1=1'];
$params = [];
if ($status !== '') { $where[] = 'a.status = ?'; $params[] = $status; }
/* Periode: "Dari" & "Sampai" (rentang). Filter satu tanggal (?date=) tetap
   didukung agar tautan lama tidak rusak. */
$fromDate = gp('from');
$toDate   = gp('to');
if ($fromDate === '' && $toDate === '' && gp('date') !== '') { $fromDate = $toDate = gp('date'); }
if ($fromDate !== '') { $where[] = 'a.date >= ?'; $params[] = $fromDate; }
if ($toDate !== '')   { $where[] = 'a.date <= ?'; $params[] = $toDate; }
/* Dokter & Terapis: kolom yang sudah ada (doctor_id / therapist_id); parameternya
   dibedakan karena id tabel doctors & therapists bisa sama. */
$docFilter = (int)gp('doctor');
if ($docFilter > 0) { $where[] = 'a.doctor_id = ?'; $params[] = $docFilter; }
$therFilter = (int)gp('therapist');
if ($therFilter > 0) { $where[] = 'a.therapist_id = ?'; $params[] = $therFilter; }
if ($scope !== null) { $where[] = 'a.branch_id = ?'; $params[] = $scope; }
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.phone LIKE ? OR a.appointment_number LIKE ? OR p.patient_number LIKE ?)';
    $t = '%' . $q . '%';
    array_push($params, $t, $t, $t, $t);
}
$w = implode(' AND ', $where);
$base = "FROM appointments a JOIN patients p ON p.id=a.patient_id JOIN branches b ON b.id=a.branch_id
         LEFT JOIN treatments t ON t.id=a.treatment_id LEFT JOIN doctors d ON d.id=a.doctor_id
         LEFT JOIN therapists th ON th.id=a.therapist_id WHERE {$w}";
$total = (int)scalar("SELECT COUNT(*) {$base}", $params);
$rows = all("SELECT a.*, p.name AS patient_name, p.phone AS patient_phone, b.name AS branch_name,
                    t.name AS treatment_name, COALESCE(d.name, th.name) AS staff_name,
                    d.name AS doctor_name, d.phone AS doctor_phone,
                    th.name AS therapist_name,
                    /* Semua treatment reservasi (treatment utama + tambahan). */
                    (SELECT GROUP_CONCAT(COALESCE(t2.name, ''), ', ')
                       FROM appointment_treatments at2
                       LEFT JOIN treatments t2 ON t2.id = at2.treatment_id
                      WHERE at2.appointment_id = a.id) AS treatments_all
             {$base} ORDER BY a.date DESC, a.time DESC LIMIT {$pp} OFFSET " . (($page - 1) * $pp), $params);

/* Calendar month */
$calMonth = gp('m', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $calMonth)) $calMonth = date('Y-m');
$calStart = $calMonth . '-01';
$calEnd   = date('Y-m-t', strtotime($calStart));
$calWhere = ['a.date BETWEEN ? AND ?'];
$calParams = [$calStart, $calEnd];
if ($scope !== null) { $calWhere[] = 'a.branch_id = ?'; $calParams[] = $scope; }
$calRows = all('SELECT a.*, p.name AS patient_name, t.name AS treatment_name
                FROM appointments a JOIN patients p ON p.id=a.patient_id
                LEFT JOIN treatments t ON t.id=a.treatment_id
                WHERE ' . implode(' AND ', $calWhere) . ' ORDER BY a.time', $calParams);
$calByDay = [];
foreach ($calRows as $r) $calByDay[$r['date']][] = $r;

/* ============================================================================
 * DAFTAR ACUAN FORMULIR RESERVASI MENGIKUTI **CABANG YANG DIPILIH**
 * (permintaan pemilik, ronde 36).
 *
 * Sebelumnya daftar dokter/terapis/treatment mengikuti CAKUPAN FILTER halaman
 * (mis. "Semua Cabang"), sehingga saat pemilik memilih cabang Kaliwungu di kolom
 * Cabang, pilihan treatment masih memuat milik cabang lain — dan untuk menandai
 * asalnya dulu ditambahkan teks "— Kaliwungu" pada tiap pilihan. Cara itu
 * dihapus: sekarang cukup pilih cabangnya, daftarnya IKUT cabang itu (tidak ada
 * lagi teks tambahan). Bila pemilik memindahkan kolom Cabang, pilihannya
 * dimuat ulang lewat `api.php?a=res_form` (lihat skrip di bawah).
 * ========================================================================== */
$edit = null;
if (gp('action') === 'edit') {
    $edit = res_full((int)gp('id'));
    if ($edit) assert_branch((int)$edit['branch_id']);
}
/* Cabang kerja formulir: cabang data yang sedang diedit, atau cabang yang
   dipilih pada pemilih cabang topbar; kalau cakupannya "semua cabang" pakai
   cabang pertama sebagai titik awal (kolom Cabang di formulir menentukan). */
$allBranchRows = selectable_branches();
$formBranchId = (int)($edit['branch_id'] ?? 0);
if ($formBranchId <= 0) {
    $formBranchId = (int)($scope ?? 0);
    if ($formBranchId <= 0 && $allBranchRows) $formBranchId = (int)$allBranchRows[0]['id'];
}
$branchCond = function (string $col) use ($formBranchId, $scope): array {
    if ($formBranchId > 0) return [' AND ' . $col . ' = ' . $formBranchId, []];
    if ($scope !== null) return [' AND ' . $col . ' = ' . (int)$scope, []];
    return ['', []];
};
/* Daftar FILTER (bar di atas daftar reservasi) memakai cakupan HALAMAN — supaya
   pemilik tetap dapat menyaring memakai dokter/terapis cabang mana pun. */
$scopeCond = function (string $col) use ($scope): string {
    return $scope !== null ? ' AND ' . $col . ' = ' . (int)$scope : '';
};
$doctorList = all('SELECT id, name, branch_id FROM doctors WHERE status="active"'
    . $scopeCond('branch_id') . ' ORDER BY name');
$therapistList = all('SELECT id, name, branch_id FROM therapists WHERE status="active"'
    . $scopeCond('branch_id') . ' ORDER BY name');
/* Daftar FORMULIR (modal reservasi) memakai CABANG YANG DIPILIH di kolom Cabang. */
$formDoctorList = all('SELECT id, name, branch_id FROM doctors WHERE status="active"'
    . $branchCond('branch_id')[0] . ' ORDER BY name');
$formTherapistList = all('SELECT id, name, branch_id FROM therapists WHERE status="active"'
    . $branchCond('branch_id')[0] . ' ORDER BY name');
$treatmentList = all('SELECT id, name, branch_id, normal_price, promo_price FROM treatments WHERE status="active"'
    . $branchCond('branch_id')[0] . ' ORDER BY name');
/* Nama cabang yang sedang dipakai formulir (dipakai pada keterangan kecil). */
$formBranchName = $formBranchId > 0
    ? (string)scalar('SELECT name FROM branches WHERE id = ?', [$formBranchId], '')
    : 'semua cabang';

$openModal = (gp('action') === 'new' || $edit) ? 'resModal' : '';
$editJson = $edit ? js_json($edit) : "null";

page_head('Reservasi', 'reservasi');
?>
<div class="page-head">
  <div><h2>Reservasi</h2><p class="muted">Jadwal dan status reservasi pasien.</p></div>
  <div class="page-actions">
    <a class="btn<?= $view === 'list' ? ' btn-primary' : '' ?>" href="?view=list">Daftar</a>
    <a class="btn<?= $view === 'cal' ? ' btn-primary' : '' ?>" href="?view=cal&m=<?= e($calMonth) ?>">Kalender</a>
    <?php if (has_perm('reservation.manage')): ?>
      <button class="btn btn-primary" data-modal-open="resModal" onclick="resetResForm()"><?= icon('plus-circle') ?> Reservasi Baru</button>
    <?php endif; ?>
    <?= retention_manual_button('reservasi') ?>
  </div>
</div>

<?php if ($view === 'cal'): ?>
<div class="card">
  <div class="card-head">
    <h3><?= e(tgl($calStart)) ?> — <?= e(tgl($calEnd)) ?></h3>
    <div class="flex gap-sm">
      <a class="btn btn-sm" href="?view=cal&m=<?= e(date('Y-m', strtotime($calStart . ' -1 month'))) ?>">‹ Bulan lalu</a>
      <a class="btn btn-sm" href="?view=cal&m=<?= e(date('Y-m')) ?>">Bulan ini</a>
      <a class="btn btn-sm" href="?view=cal&m=<?= e(date('Y-m', strtotime($calEnd . ' +1 day'))) ?>">Bulan depan ›</a>
    </div>
  </div>
  <div class="card-body">
    <?php if (!$calRows): ?><p class="muted">Tidak ada reservasi pada bulan ini.</p><?php endif; ?>
    <div class="cal">
      <?php foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $d): ?><div class="dow"><?= $d ?></div><?php endforeach; ?>
      <?php
      $first = strtotime($calStart);
      $dow = (int)date('N', $first); // 1=Mon
      $daysIn = (int)date('t', $first);
      for ($i = 1; $i < $dow; $i++) echo '<div class="day other"></div>';
      for ($d = 1; $d <= $daysIn; $d++) {
          $dk = date('Y-m-', $first) . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
          $cls = 'day' . ($dk === date('Y-m-d') ? ' today' : '');
          echo '<div class="' . $cls . '"><span class="dnum">' . $d . '</span>';
          foreach ($calByDay[$dk] ?? [] as $ev) {
              $st = strtolower($ev['status']);
              $cls2 = 'ev' . (in_array($ev['status'], ['Selesai', 'Hadir'], true) ? ' done' : '') . (in_array($ev['status'], ['Cancel', 'No Show'], true) ? ' cancel' : '');
              echo '<a class="' . $cls2 . '" title="' . e($ev['appointment_number'] . ' · ' . $ev['patient_name'] . ' · ' . $ev['status']) . '" href="reservasi.php?view=list&q=' . urlencode($ev['appointment_number']) . '">' . e(substr($ev['time'], 0, 5) . ' ' . $ev['patient_name']) . '</a>';
          }
          echo '</div>';
      }
      ?>
    </div>
  </div>
</div>
<?php else: ?>
<div class="card tight">
  <form class="filter-bar" method="get">
    <input type="hidden" name="view" value="list">
    <div class="field searchbox"><label>Cari</label><span><?= icon('search') ?></span>
      <input class="input input-sm" name="q" value="<?= e($q) ?>" placeholder="Nama pasien / No. reservasi"></div>
    <div class="field"><label>Status</label>
      <select class="input input-sm" name="status">
        <option value="">Semua status</option>
        <?php foreach (RES_STATUSES as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Dari</label><input class="input input-sm" type="date" name="from" value="<?= e($fromDate) ?>"></div>
    <div class="field"><label>Sampai</label><input class="input input-sm" type="date" name="to" value="<?= e($toDate) ?>"></div>
    <div class="field"><label>Dokter</label>
      <select class="input input-sm" name="doctor">
        <option value="">Semua Dokter</option>
        <?php foreach ($doctorList as $d): ?>
          <option value="<?= (int)$d['id'] ?>"<?= $docFilter === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label>Terapis</label>
      <select class="input input-sm" name="therapist">
        <option value="">Semua Terapis</option>
        <?php foreach ($therapistList as $t): ?>
          <option value="<?= (int)$t['id'] ?>"<?= $therFilter === (int)$t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    <a class="btn btn-sm" href="reservasi.php">Reset</a>
    <?= per_page_inline() ?>
  </form>
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state('Belum ada reservasi.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>No. Reservasi</th><th>Jadwal</th><th>Pasien</th><th>Treatment</th><th>Dokter/Terapis</th><th>Cabang</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap small"><?= e($r['appointment_number']) ?></td>
          <td class="nowrap"><?= e(tgl($r['date'])) ?><div class="small muted"><?= e(substr((string)$r['time'], 0, 5)) ?></div></td>
          <td><div class="person-cell">
            <?php $pr = one('SELECT * FROM patients WHERE id = ?', [(int)$r['patient_id']]); ?>
            <?= person_avatar('patient', $pr ?: [], 32) ?>
            <span class="pc-name"><a href="pasien_detail.php?id=<?= (int)$r['patient_id'] ?>"><?= e($r['patient_name']) ?></a>
              <div class="small muted"><?= e($r['patient_phone'] ?: '-') ?></div></span></div></td>
          <td><?php
            $tAll = trim((string)($r['treatments_all'] ?? ''));
            $tList = $tAll !== '' ? explode(', ', $tAll) : ($r['treatment_name'] ? [(string)$r['treatment_name']] : []);
            if (!$tList) { echo '<span class="muted">-</span>'; }
            else {
                echo e($tList[0]);
                if (count($tList) > 1) {
                    echo ' <span class="badge badge-gray" title="' . e(implode(', ', array_slice($tList, 1))) . '">+'
                        . num(count($tList) - 1) . ' lainnya</span>';
                }
            }
          ?></td>
          <td class="small"><?php
            $staff = staff_both_text($r['doctor_name'] ?? '', $r['therapist_name'] ?? '');
            echo $staff !== '' ? e($staff) : '-';
          ?></td>
          <td class="small"><?= e($r['branch_name']) ?></td>
          <td><?= appointment_status_badge($r['status']) ?></td>
          <td>
            <div class="row-actions">
              <?php if (has_perm('reservation.manage')): ?>
                <form method="post" class="inline-form">
                  <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <select class="input input-sm" name="status" data-autosubmit>
                    <?php foreach (RES_STATUSES as $s): ?><option value="<?= $s ?>"<?= $r['status'] === $s ? ' selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
                  </select>
                </form>
                <a class="btn btn-sm" href="reservasi.php?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                <?php if (has_perm('medical.manage') && !in_array($r['status'], ['Cancel'], true)): ?>
                  <a class="btn btn-sm" href="rekam_medis_form.php?patient_id=<?= (int)$r['patient_id'] ?>&amp;reservation_id=<?= (int)$r['id'] ?>"
                     title="Buat rekam medis dengan pasien, tanggal, dan dokter/terapis terisi dari reservasi ini (masih bisa diubah)"><?= icon('file-medical') ?> Isi Rekam Medis</a>
                <?php endif; ?>
                <?php if ($r['patient_phone']): ?>
                  <?php if (wa_api_configured()): ?>
                    <form method="post" class="inline-form" data-confirm="Kirim pesan WhatsApp via API ke pasien <?= e($r['patient_name']) ?>?">
                      <?= csrf_field() ?><input type="hidden" name="action" value="wa_send"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-leaf" type="submit" title="Kirim pengingat ke pasien"><?= icon('whatsapp') ?> WA Pasien</button>
                    </form>
                  <?php else: ?>
                    <a class="btn btn-sm btn-leaf" target="_blank" rel="noopener" title="Pengingat untuk pasien (dibuka di WhatsApp)" href="<?= e(wa_link($r['patient_phone'], wa_reservation_message($r))) ?>"><?= icon('whatsapp') ?> WA Pasien</a>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($r['doctor_id']) && setting('wa_doctor_active', '1') === '1'): ?>
                  <?php $docPhone = trim((string)($r['doctor_phone'] ?? '')); ?>
                  <?php if (wa_api_configured() && $docPhone !== ''): ?>
                    <form method="post" class="inline-form" data-confirm="Kirim pengingat jadwal ke dokter <?= e($r['doctor_name']) ?>?">
                      <?= csrf_field() ?><input type="hidden" name="action" value="wa_doctor_send"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm" type="submit" title="Kirim pengingat jadwal ke dokter"><?= icon('whatsapp') ?> WA Dokter</button>
                    </form>
                  <?php elseif ($docPhone !== ''): ?>
                    <a class="btn btn-sm" target="_blank" rel="noopener" title="Pengingat jadwal untuk dokter (dibuka di WhatsApp)"
                       href="<?= e(wa_link($docPhone, wa_doctor_message($r + ['doctor_name' => $r['doctor_name']]))) ?>"><?= icon('whatsapp') ?> WA Dokter</a>
                  <?php else: ?>
                    <span class="badge badge-yellow" title="Nomor WhatsApp dokter belum diisi di menu Dokter &amp; Terapis">WA Dokter: no. kosong</span>
                  <?php endif; ?>
                <?php endif; ?>
                <button class="btn btn-sm" data-modal-open="emailModal" onclick="setEmailTarget(<?= (int)$r['id'] ?>, '<?= e($r['appointment_number']) ?>')">Email</button>
                <?php if (has_perm('order.manage') && !in_array($r['status'], ['Cancel'], true)): ?>
                  <?php /* branch_id ikut dikirim supaya Order Baru memakai cabang data
                           reservasi/pasien ini (bukan cabang pertama). */ ?>
                  <a class="btn btn-sm btn-primary" href="order_baru.php?branch_id=<?= (int)$r['branch_id'] ?>&patient_id=<?= (int)$r['patient_id'] ?>&appointment_id=<?= (int)$r['id'] ?>">→ Transaksi</a>
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
<?php endif; ?>

<div class="modal<?= $openModal ? ' open' : '' ?>" id="resModal">
  <div class="modal-box wide">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="r_id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <input type="hidden" name="patient_id" id="r_patient_id" value="<?= (int)($edit['patient_id'] ?? 0) ?>">
      <div class="modal-head"><h3 id="r_title"><?= $edit ? 'Edit Reservasi' : 'Reservasi Baru' ?></h3>
        <button type="button" class="icon-btn" data-modal-close="resModal"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <div class="form-grid g2">
          <div class="field"><label>Pasien <span class="req">*</span></label>
            <div class="searchbox"><span><?= icon('search') ?></span>
              <input class="input" id="r_patient_search" placeholder="Ketik nama / NIK / telepon pasien" autocomplete="off" value="<?= e($edit['patient_name'] ?? '') ?>">
              <div class="suggest" id="r_patient_suggest"></div>
            </div>
            <span class="hint">Pencarian pasien mengikuti <strong>cabang yang dipilih</strong> pada kolom Cabang
              (di bawah). Tidak ketemu? <a href="<?= e(is_owner_level() ? 'pasien.php?action=new' : 'pasien.php?action=new') ?>">Daftarkan pasien baru</a> terlebih dahulu.</span>
          </div>
          <div class="field"><label>Tanggal <span class="req">*</span></label>
            <input class="input" type="date" name="date" id="r_date" value="<?= e($edit['date'] ?? date('Y-m-d')) ?>" required></div>
          <div class="field"><label>Jam</label>
            <input class="input" type="time" name="time" id="r_time" value="<?= e(substr((string)($edit['time'] ?? '09:00'), 0, 5)) ?>"></div>
          <div class="field"><label>Status</label>
            <select class="input" name="status" id="r_status">
              <?php foreach (RES_STATUSES as $s): ?><option value="<?= $s ?>"<?= ($edit['status'] ?? '') === $s ? ' selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label>Dokter</label>
            <select class="input" name="doctor_id" id="r_doctor">
              <option value="">- tidak ada -</option>
              <?php foreach ($formDoctorList as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)($edit['doctor_id'] ?? 0) === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label>Terapis</label>
            <select class="input" name="therapist_id" id="r_therapist">
              <option value="">- tidak ada -</option>
              <?php foreach ($formTherapistList as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)($edit['therapist_id'] ?? 0) === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field" style="grid-column:1/-1">
            <label>Treatment <span class="muted small">(boleh lebih dari satu)</span></label>
            <?php
            /* Baris treatment awal: untuk Edit diambil dari daftar treatment
               reservasi (tabel appointment_treatments); untuk reservasi baru
               dimulai dengan satu baris kosong. */
            $resTreat = $edit ? res_treatments((int)$edit['id']) : [];
            $resTreatIds = array_map(fn($t) => (int)$t['treatment_id'], $resTreat);
            $rowCount = max(1, count($resTreatIds));
            ?>
            <div id="r_treat_rows">
              <?php for ($i = 0; $i < $rowCount; $i++): $cur = $resTreatIds[$i] ?? 0; ?>
                <div class="flex gap-sm r-treat-row" style="align-items:center;margin-bottom:6px">
                  <select class="input" name="treatments[]" aria-label="Treatment">
                    <option value="">- belum ditentukan -</option>
                    <?php /* Pilihan treatment = treatment cabang yang dipilih pada kolom Cabang
                             (tanpa teks tambahan nama cabang). */ ?>
                    <?php foreach ($treatmentList as $t): ?>
                      <option value="<?= (int)$t['id'] ?>"<?= $cur === (int)$t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-danger r-treat-del" type="button" title="Hapus baris ini">Hapus</button>
                </div>
              <?php endfor; ?>
            </div>
            <button class="btn btn-sm" type="button" id="r_treat_add"><?= icon('plus-circle') ?> Tambah treatment</button>
            <span class="hint">Treatment pertama juga menjadi treatment utama (dipakai pada pesan WhatsApp &amp; tombol Lanjut Transaksi).
              <span id="r_treat_branch_hint">Daftar treatment, dokter, dan terapis mengikuti
                <strong>cabang <?= e($formBranchName) ?></strong><?= is_owner_level() ? ' — ubah kolom Cabang di bawah untuk memilih cabang lain' : '' ?>.</span></span>
          </div>
          <?php if (is_owner_level()): ?>
          <div class="field"><label>Cabang <span class="req">*</span></label>
            <select class="input" name="branch_id" id="r_branch" data-res-branch><?= opt_branches($formBranchId) ?></select>
            <span class="hint">Pilihan <strong>dokter, terapis, dan treatment</strong> di atas langsung mengikuti
              cabang yang dipilih di sini (hanya memuat data cabang tersebut).</span></div>
          <?php endif; ?>
        </div>
        <div class="field mt-2"><label>Catatan</label><textarea class="input" name="notes" id="r_notes"><?= e($edit['notes'] ?? '') ?></textarea></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" data-modal-close="resModal">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan Reservasi</button>
      </div>
    </form>
  </div>
</div>

<div class="modal" id="emailModal">
  <div class="modal-box sheet">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="email">
      <input type="hidden" name="id" id="em_id" value="0">
      <div class="modal-head"><h3>Kirim Email Reservasi</h3>
        <button type="button" class="icon-btn" data-modal-close="emailModal"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <div class="field"><label>Email Tujuan</label><input class="input" type="email" name="to" id="em_to" required placeholder="nama@email.com"></div>
        <div class="notice mt-2">Status email service: <strong><?= e(mail_status_text()) ?></strong></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" data-modal-close="emailModal">Batal</button>
        <button class="btn btn-primary" type="submit">Kirim Email</button>
      </div>
    </form>
  </div>
</div>

<script>
var RES_EDIT = <?= $editJson ?>;
/* Daftar treatment (dipakai saat menambah baris treatment baru di form). */
var RES_FORM_BRANCH = <?= (int)$formBranchId ?>;
/* Parameter pencarian pasien: mengikuti CABANG FORMULIR (perbaikan ronde 37).
   Objek ini sengaja dipakai langsung oleh Naveena.suggest (dibaca saat mengetik),
   sehingga cukup diubah nilainya saat kolom Cabang dipindah. */
var RES_PATIENT_PARAMS = { branch: RES_FORM_BRANCH };
var RES_TREATMENTS = <?= js_json(array_map(fn($t) => [
    'id' => (int)$t['id'], 'name' => $t['name'],
], $treatmentList)) ?>;
function setEmailTarget(id, num) { document.getElementById('em_id').value = id; }
/* Tambah satu baris pilihan treatment pada form reservasi. */
function resAddTreatRow(selectedId) {
  var rows = document.getElementById('r_treat_rows');
  if (!rows) return;
  var wrap = document.createElement('div');
  wrap.className = 'flex gap-sm r-treat-row';
  wrap.style.alignItems = 'center';
  wrap.style.marginBottom = '6px';
  var opts = '<option value="">- belum ditentukan -</option>';
  RES_TREATMENTS.forEach(function (t) {
    opts += '<option value="' + t.id + '"' + (Number(selectedId) === t.id ? ' selected' : '') + '>'
      + (t.name || t.label || '') + '</option>';
  });
  wrap.innerHTML = '<select class="input" name="treatments[]" aria-label="Treatment">' + opts + '</select>'
    + '<button class="btn btn-sm btn-danger r-treat-del" type="button" title="Hapus baris ini">Hapus</button>';
  rows.appendChild(wrap);
}
/* Satu baris kosong saja (dipakai tombol "Hapus" supaya tidak habis semua). */
function resCleanTreatRows() {
  var rows = document.getElementById('r_treat_rows');
  if (!rows) return;
  if (!rows.querySelector('.r-treat-row')) resAddTreatRow(0);
}
function resetResForm() {
  document.getElementById('r_id').value = 0;
  document.getElementById('r_patient_id').value = '';
  document.getElementById('r_patient_search').value = '';
  document.getElementById('r_title').textContent = 'Reservasi Baru';
  var rows = document.getElementById('r_treat_rows');
  if (rows) { rows.innerHTML = ''; resAddTreatRow(0); }   // reservasi baru: satu baris kosong
}
/* ============================================================================
 * KOLOM CABANG MENENTUKAN ISI PILIHAN — (permintaan pemilik, ronde 36)
 *
 * Saat pemilik memindahkan kolom "Cabang" pada modal Reservasi, daftar
 * dokter/terapis/treatment dimuat ulang dari `api.php?a=res_form` supaya hanya
 * memuat data cabang tersebut — jadi tidak perlu lagi memberi teks "— Kaliwungu"
 * pada tiap pilihan treatment. Pilihan yang sudah dibuat dibuang karena pasti
 * bukan milik cabang yang baru (diberitahukan lewat keterangan kecil).
 * ========================================================================== */
function resLoadBranchLists(bid) {
  var hint = document.getElementById('r_treat_branch_hint');
  var rows = document.getElementById('r_treat_rows');
  if (!bid) return;
  fetch('api.php?a=res_form&ajax=1&branch=' + encodeURIComponent(bid), { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d || !d.ok) return;
      RES_FORM_BRANCH = Number(d.branch || bid);
      RES_PATIENT_PARAMS.branch = RES_FORM_BRANCH;
      /* Pilihan pasien dibuang: pasien sebelumnya bisa jadi milik cabang lain. */
      var pid = document.getElementById('r_patient_id');
      var psearch = document.getElementById('r_patient_search');
      if (pid && Number(pid.value) > 0) { pid.value = ''; if (psearch) psearch.value = ''; }
      RES_TREATMENTS = (d.treatments || []).map(function (t) { return { id: t.id, name: t.name }; });
      var dok = document.getElementById('r_doctor');
      var ter = document.getElementById('r_therapist');
      var isi = function (sel, list, kosong) {
        if (!sel) return;
        var lama = sel.value;
        sel.innerHTML = '<option value="">' + kosong + '</option>'
          + list.map(function (x) { return '<option value="' + x.id + '">' + x.name + '</option>'; }).join('');
        if (lama && list.some(function (x) { return String(x.id) === String(lama); })) sel.value = lama;
      };
      isi(dok, d.doctors || [], '- tidak ada -');
      isi(ter, d.therapists || [], '- tidak ada -');
      if (rows) { rows.innerHTML = ''; resAddTreatRow(0); }    // pilihan lama tidak berlaku lagi
      if (hint) {
        hint.innerHTML = 'Daftar treatment, dokter, dan terapis mengikuti <strong>cabang '
          + (d.branch_name || bid) + '</strong>' + (d.owner_level ? ' — ubah kolom Cabang di bawah untuk memilih cabang lain' : '') + '.';
      }
    })
    .catch(function () { /* jaringan bermasalah: biarkan pilihan lama */ });
}

document.addEventListener('DOMContentLoaded', function () {
  var branchSel = document.getElementById('r_branch');
  if (branchSel) {
    branchSel.addEventListener('change', function () { resLoadBranchLists(branchSel.value); });
  }
  Naveena.suggest({ input: '#r_patient_search', box: '#r_patient_suggest', action: 'patient',
    params: RES_PATIENT_PARAMS,
    onPick: function (it) {
      document.getElementById('r_patient_id').value = it.id;
      document.getElementById('r_patient_search').value = it.name + ' (' + it.number + ')';
    } });
  if (RES_EDIT) {
    var s = document.getElementById('r_patient_search');
    if (s && RES_EDIT.patient_name) s.value = RES_EDIT.patient_name;
  }
  /* Tombol "+ Tambah treatment" dan "Hapus" pada baris treatment. */
  var addBtn = document.getElementById('r_treat_add');
  if (addBtn) addBtn.addEventListener('click', function () { resAddTreatRow(0); });
  var rowsBox = document.getElementById('r_treat_rows');
  if (rowsBox) rowsBox.addEventListener('click', function (ev) {
    if (!ev.target.classList.contains('r-treat-del')) return;
    var row = ev.target.closest('.r-treat-row');
    if (row) row.remove();
    resCleanTreatRows();
  });
});
</script>
<?php page_foot(); ?>
