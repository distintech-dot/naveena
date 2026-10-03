<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('medical.view');
$user = current_user();

/**
 * Kembali ke daftar rekam medis SAMBIL MEMBAWA filter yang sedang dipakai.
 *
 * Tanpa ini, setiap aksi dari dalam daftar (ubah status / hapus) mengarah ke
 * `rekam_medis.php` tanpa parameter sehingga filter ter-reset: tanggal "Dari"
 * dan "Sampai" kembali kosong dan daftar menampilkan SEMUA data — persis gejala
 * yang dilaporkan pemilik klinik pada menu Rekam Medis.
 */
function rm_filter_back(): string
{
    $raw = str_replace(["\r", "\n"], '', (string)($_POST['back'] ?? ''));
    /* Hanya menerima query string sederhana (tanpa skema/domain) — mencegah
       pengalihan ke alamat luar. */
    if ($raw !== '' && strpos($raw, '//') === false && preg_match('/^[A-Za-z0-9_\-=&%.+\[\]]*$/', $raw)) {
        return 'rekam_medis.php?' . $raw;
    }
    return 'rekam_medis.php';
}

/* HAPUS DATA PER PERIODE (khusus Super Admin) — diproses lebih dulu karena
   memakai jalur verifikasi sendiri (password + konfirmasi 2 tahap). */
retention_handle_post('rekam_medis');

/* Ubah status penanganan langsung dari daftar (semua level yang boleh mengelola RM) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    verify_csrf();
    require_perm('medical.manage');
    try {
        $id = (int)$_POST['id'];
        $st = (string)$_POST['status'];
        if (!in_array($st, RECORD_STATUSES, true)) throw new RuntimeException('Status penanganan tidak valid.');
        $m = one('SELECT * FROM medical_records WHERE id = ?', [$id]);
        if (!$m) throw new RuntimeException('Rekam medis tidak ditemukan.');
        assert_branch((int)$m['branch_id']);
        if ($m['locked_at']) throw new RuntimeException('Rekam medis sudah dikunci. Gunakan Amendment untuk mengubah statusnya.');
        q('UPDATE medical_records SET status=?, updated_by=?, updated_at=datetime("now","localtime") WHERE id=?',
            [$st, $user['id'], $id]);
        audit('Ubah Status Rekam Medis', 'Rekam Medis', $id, ['status' => $m['status']], ['status' => $st],
            'Status penanganan diubah menjadi ' . $st);
        flash('Status penanganan ' . $m['record_number'] . ' diubah menjadi "' . $st . '".');
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: ' . rm_filter_back());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['delete', 'delete_hard'], true)) {
    verify_csrf();
    require_perm('medical.manage');
    $delAct = (string)$_POST['action'];
    try {
        $id = (int)$_POST['id'];
        $m = one('SELECT * FROM medical_records WHERE id = ?', [$id]);
        if (!$m) throw new RuntimeException('Rekam medis tidak ditemukan.');
        assert_branch((int)$m['branch_id']);

        if ($delAct !== 'delete_hard') {
            throw new RuntimeException('Rekam medis tidak dapat dihapus oleh peran Anda. Gunakan Amendment untuk memperbaiki data '
                . '(histori klinis tetap tersimpan); penghapusan permanen hanya dapat dilakukan Super Admin.');
        }
        if (!is_super()) deny('Hanya Super Admin yang boleh menghapus rekam medis secara permanen.');
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '') throw new RuntimeException('Alasan penghapusan wajib diisi.');
        $photos = (int)scalar('SELECT COUNT(*) FROM medical_record_photos WHERE medical_record_id = ?', [$id]);
        $amend = (int)scalar('SELECT COUNT(*) FROM medical_records WHERE amended_from = ?', [$id]);
        $pdo = db();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            q('DELETE FROM medical_record_photos WHERE medical_record_id = ?', [$id]);
            q('UPDATE medical_records SET amended_from = NULL WHERE amended_from = ?', [$id]);
            q('DELETE FROM medical_records WHERE id = ?', [$id]);
            $pdo->exec('COMMIT');
        } catch (Throwable $ex) {
            $pdo->exec('ROLLBACK');
            throw new RuntimeException('Gagal menghapus: ' . $ex->getMessage());
        }
        audit('HAPUS PERMANEN Rekam Medis', 'Rekam Medis', $id, $m,
            ['foto' => $photos, 'amendment_terkait' => $amend], $reason);
        flash('Rekam medis ' . $m['record_number'] . ' (' . $photos . ' foto) telah dihapus permanen.', 'warning');
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: ' . rm_filter_back());
    exit;
}

$q      = gp('q');
$page   = page_no();
$pp     = per_page();
$scope  = scope_branch();
$where  = ['1=1'];
$params = [];
if ($scope !== null) { $where[] = 'm.branch_id = ?'; $params[] = $scope; }
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR m.record_number LIKE ? OR m.diagnosis LIKE ? OR m.icd10 LIKE ?)';
    $t = '%' . $q . '%';
    array_push($params, $t, $t, $t, $t);
}
if (gp('from') !== '') { $where[] = 'm.date >= ?'; $params[] = gp('from'); }
if (gp('to') !== '') { $where[] = 'm.date <= ?'; $params[] = gp('to'); }
/* Filter DOKTER & TERAPIS memakai kolom yang sudah ada di medical_records
   (doctor_id / therapist_id). Parameternya dibedakan (doctor / therapist)
   karena tabel doctors & therapists punya id yang bisa sama. */
$docFilter = (int)gp('doctor');
if ($docFilter > 0) { $where[] = 'm.doctor_id = ?'; $params[] = $docFilter; }
$therFilter = (int)gp('therapist');
if ($therFilter > 0) { $where[] = 'm.therapist_id = ?'; $params[] = $therFilter; }
/* Status: "Amendment" bukan nilai kolom status, melainkan rekam medis yang
   lahir dari perbaikan (amended_from terisi). Sebelumnya pilihan ini selalu
   menghasilkan 0 baris karena dicocokkan ke kolom status. */
$statusFilter = (string)gp('status');
if ($statusFilter === 'amendment') {
    $where[] = 'm.amended_from IS NOT NULL';
} elseif ($statusFilter !== '') {
    $where[] = 'm.status = ?';
    $params[] = $statusFilter;
}
/* Daftar pilihan dokter/terapis untuk filter (mengikuti cabang yang berlaku,
   sama seperti daftar di form rekam medis). */
$filterDoctors = all('SELECT id, name FROM doctors WHERE status="active"'
    . ($scope !== null ? ' AND branch_id = ' . (int)$scope : '') . ' ORDER BY name');
$filterTherapists = all('SELECT id, name FROM therapists WHERE status="active"'
    . ($scope !== null ? ' AND branch_id = ' . (int)$scope : '') . ' ORDER BY name');
$w = implode(' AND ', $where);
/* Dokter & terapis di-join terpisah supaya KEDUANYA bisa ditampilkan
   (dulu hanya salah satu karena memakai staff_name yang berisi satu nama). */
$base = "FROM medical_records m JOIN patients p ON p.id=m.patient_id JOIN branches b ON b.id=m.branch_id
         LEFT JOIN doctors d ON d.id=m.doctor_id LEFT JOIN therapists th ON th.id=m.therapist_id
         WHERE {$w}";
$total = (int)scalar("SELECT COUNT(*) {$base}", $params);
$rows = all("SELECT m.*, p.name AS patient_name, p.patient_number, b.name AS branch_name,
                    d.name AS doctor_name, th.name AS therapist_name,
                    (SELECT COUNT(*) FROM medical_record_photos ph WHERE ph.medical_record_id = m.id) photos
             {$base} ORDER BY m.date DESC, m.id DESC LIMIT {$pp} OFFSET " . (($page - 1) * $pp), $params);

/* Judul ini dipakai di TOPBAR (`render_topbar`) sekaligus judul <h2> di bawah,
   jadi kedua tempat itu ikut berubah bersama (permintaan pemilik: hanya 3 tempat
   — menu sidebar, topbar, dan judul halaman ini). Teks tombol seperti
   "Rekam Medis Baru" / "Isi Rekam Medis" sengaja TIDAK diubah. */
page_head('Rekam Medis Elektronik', 'rekam_medis');
?>
<div class="page-head">
  <div><h2>Rekam Medis Elektronik</h2><p class="muted">Catatan klinis pasien. Data bersifat privat dan hanya dapat diakses sesuai cabang &amp; hak akses.</p></div>
  <div class="page-actions">
    <?php if (has_perm('medical.manage')): ?>
      <a class="btn btn-primary" href="rekam_medis_form.php"><?= icon('plus-circle') ?> Rekam Medis Baru</a>
    <?php endif; ?>
    <?php if (has_perm('medical.manage')): ?><a class="btn" href="import.php?type=rekam_medis"><?= icon('upload') ?> Import</a><?php endif; ?>
    <?php if (has_perm('export.data') && has_perm('medical.view')): ?>
      <a class="btn" href="export.php?type=rekam_medis&format=excel&<?= e(qs([], ['page', 'per_page'])) ?>"><?= icon('download') ?> Excel</a>
      <a class="btn btn-primary" href="export.php?type=rekam_medis&format=xlsx&<?= e(qs([], ['page', 'per_page'])) ?>"
         title="Excel asli berisi data rekam medis + lembar foto/lampiran klinis"><?= icon('download') ?> Excel + Foto</a>
      <a class="btn" href="export.php?type=rekam_medis&format=csv&<?= e(qs([], ['page', 'per_page'])) ?>">CSV</a>
      <a class="btn" href="export.php?type=rekam_medis&format=pdf&<?= e(qs([], ['page', 'per_page'])) ?>" target="_blank">PDF</a>
    <?php endif; ?>
    <?php if (is_super()): ?>
      <a class="btn btn-danger" href="purge.php?menu=rekam_medis"
         title="Khusus Super Admin — hapus seluruh data menu ini">Hapus Semua Rekam Medis</a>
    <?php endif; ?>
    <?php /* Tombol "Hapus Data Per Periode" diletakkan PALING KANAN supaya semua
             tombol hapus (periode & semua data) berjejer di ujung. */ ?>
    <?= retention_manual_button('rekam_medis') ?>
  </div>
</div>

<div class="card tight">
  <form class="filter-bar" method="get">
    <div class="field searchbox"><label>Cari</label><span><?= icon('search') ?></span>
      <input class="input input-sm" name="q" value="<?= e($q) ?>" placeholder="Nama pasien / nomor RM / diagnosis"></div>
    <div class="field"><label>Dari</label><input class="input input-sm" type="date" name="from" value="<?= e(gp('from')) ?>"></div>
    <div class="field"><label>Sampai</label><input class="input input-sm" type="date" name="to" value="<?= e(gp('to')) ?>"></div>
    <div class="field"><label>Dokter</label>
      <select class="input input-sm" name="doctor">
        <option value="">Semua Dokter</option>
        <?php foreach ($filterDoctors as $d): ?>
          <option value="<?= (int)$d['id'] ?>"<?= $docFilter === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label>Terapis</label>
      <select class="input input-sm" name="therapist">
        <option value="">Semua Terapis</option>
        <?php foreach ($filterTherapists as $t): ?>
          <option value="<?= (int)$t['id'] ?>"<?= $therFilter === (int)$t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label>Status Penanganan</label>
      <select class="input input-sm" name="status">
        <option value="">Semua</option>
        <?php foreach (RECORD_STATUSES as $st): ?>
          <option value="<?= e($st) ?>"<?= gp('status') === $st ? ' selected' : '' ?>><?= e($st) ?></option>
        <?php endforeach; ?>
        <option value="amendment"<?= gp('status') === 'amendment' ? ' selected' : '' ?>>Amendment</option>
      </select></div>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    <a class="btn btn-sm" href="rekam_medis.php" title="Reset filter — menampilkan kembali seluruh data rekam medis">Reset</a>
    <?php /* Pemilih "per halaman" dibuat LANGSUNG di dalam form filter ini.
       Sebelumnya memakai per_page_select() yang membungkus pilihannya dengan
       <form> sendiri sehingga menjadi form BERSARANG — HTML tidak mengizinkan
       itu: browser mengabaikan <form> dalam dan menggabungkan input hidden-nya
       (berisi filter LAMA dari URL) ke form filter. Akibatnya saat menekan
       Filter, parameter terkirim dua kali dan nilai LAMA menang — rentang
       tanggal yang baru tidak pernah dipakai, sehingga daftar seperti tidak
       terfilter dan tanggal "Dari/Sampai" tampak kembali ke nilai sebelumnya. */ ?>
    <select name="per_page" class="input input-sm" onchange="this.form.submit()"
            title="Jumlah data per halaman">
      <?php foreach ([10, 25, 50, 100] as $n): ?>
        <option value="<?= $n ?>"<?= $pp === $n ? ' selected' : '' ?>><?= $n ?> / halaman</option>
      <?php endforeach; ?>
    </select>
  </form>
  <div class="table-wrap">
    <?php if (!$rows): ?><?= empty_state(
        (gp('from') !== '' || gp('to') !== '' || $docFilter > 0 || $therFilter > 0 || $q !== '' || $statusFilter !== '')
          ? 'Tidak ada rekam medis yang cocok dengan filter (0 data). Ubah rentang tanggal/dokter/terapis atau klik Reset.'
          : 'Belum ada rekam medis.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Nomor</th><th>Tanggal</th><th>Pasien</th><th>Dokter/Terapis</th><th>Assessment / Diagnosa</th><th>ICD-10</th><th>Foto</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $m): ?>
        <tr>
          <td class="small nowrap"><?= e($m['record_number']) ?></td>
          <td class="nowrap"><?= e(tgl($m['date'])) ?></td>
          <td><a href="pasien_detail.php?id=<?= (int)$m['patient_id'] ?>"><?= e($m['patient_name']) ?></a>
            <div class="small muted"><?= e($m['patient_number']) ?> · <?= e($m['branch_name']) ?></div></td>
          <td class="small"><?php
            $staff = staff_both_text($m['doctor_name'] ?? '', $m['therapist_name'] ?? '');
            echo $staff !== '' ? e($staff) : e($m['staff_name'] ?: '-');
          ?></td>
          <td><?= e(item_short((string)$m['diagnosis'], 40) ?: '-') ?></td>
          <td class="small"><?= e($m['icd10'] ?: '-') ?></td>
          <td class="num"><?= num($m['photos']) ?></td>
          <td><?= record_status_badge($m['status']) ?><?= $m['locked_at'] ? ' ' . badge('Locked', 'gray') : '' ?></td>
          <td class="nowrap"><div class="row-actions">
            <a class="btn btn-sm" href="rekam_medis_form.php?id=<?= (int)$m['id'] ?>">Lihat</a>
            <?php if (has_perm('medical.manage')): ?>
              <?php if ($m['locked_at']): ?>
                <a class="btn btn-sm" href="rekam_medis_form.php?action=amend&id=<?= (int)$m['id'] ?>"
                   title="Rekam medis terkunci — perbaikan lewat Amendment">Amendment</a>
              <?php else: ?>
                <a class="btn btn-sm btn-primary" href="rekam_medis_form.php?action=edit&id=<?= (int)$m['id'] ?>">Edit</a>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (has_perm('medical.manage')): ?>
            <form method="post" class="inline-form" data-confirm="Ubah status penanganan rekam medis ini?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <input type="hidden" name="back" value="<?= e(qs()) ?>">
              <select class="input input-sm" name="status" data-autosubmit title="Status penanganan">
                <?php foreach (RECORD_STATUSES as $st): ?>
                  <option value="<?= e($st) ?>"<?= ($m['status'] ?? '') === $st ? ' selected' : '' ?>><?= e($st) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <?php endif; ?>
            <?php if (is_owner_level()): ?>
            <form method="post"
                  data-heavy-confirm="HAPUS"
                  data-heavy-warning="Menghapus <strong>permanen</strong> rekam medis <strong><?= e($m['record_number']) ?></strong> milik <strong><?= e($m['patient_name']) ?></strong> tanggal <?= e(tgl($m['date'])) ?><br>&bull; <?= num($m['photos']) ?> foto/lampiran klinis ikut terhapus<br>&bull; Catatan SOAP (Subjektif, Objektif, Assessment, Planning) dan kode ICD hilang dari histori pasien<br><br>Untuk memperbaiki data tanpa menghapus, gunakan <em>Amendment</em>."
                  data-heavy-confirm2="PERINGATAN KEDUA: rekam medis <?= e($m['record_number']) ?> (<?= e($m['patient_name']) ?>) akan dihapus PERMANEN dari database. Lanjutkan?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_hard">
              <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <input type="hidden" name="reason" value="Dihapus permanen oleh Super Admin">
              <input type="hidden" name="back" value="<?= e(qs()) ?>">
              <button class="btn btn-sm btn-danger" type="submit" title="Hapus permanen (Super Admin)">Hapus</button>
            </form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?= pagination($total, $pp, $page) ?>
</div>
<?php page_foot(); ?>
