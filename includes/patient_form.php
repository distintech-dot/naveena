<?php
/**
 * FORMULIR PASIEN (Tambah / Edit) — SATU SUMBER untuk dua halaman.
 * ==============================================================
 * Dipakai oleh `pasien.php` (daftar pasien) DAN `pasien_detail.php` (detail pasien),
 * supaya kolomnya tidak pernah berbeda antar halaman.
 *
 * Permintaan pemilik yang dijawab berkas ini:
 *   1. Kolom **unggah foto profil** ada langsung di formulir Tambah/Edit pasien
 *      (dulu hanya tersedia lewat tombol "Foto" terpisah di daftar).
 *   2. Edit dari halaman **Detail Pasien** tetap di halaman itu — formulir dikirim ke
 *      `pasien.php` dengan penanda `back` (halaman asal) dan aplikasi kembali ke sana
 *      setelah menyimpan (lihat `patient_form_back_url()`).
 *   3. Foto yang sudah ada dapat DIKLIK untuk diperbesar (`data-zoom`, lihat app.js).
 */

/**
 * Alamat kembali setelah menyimpan data pasien.
 *
 * HANYA menerima alamat INTERNAL yang aman (tanpa skema/domain, tanpa `..`) supaya
 * tidak bisa dipakai untuk mengalihkan pengguna ke situs lain. Kosong = daftar pasien.
 */
function patient_form_back_url(): string
{
    $back = trim((string)($_POST['back'] ?? ''));
    if ($back === '') return 'pasien.php';
    if (preg_match('~^[a-z_]+\.php(\?[A-Za-z0-9_=&%\.\-]*)?$~i', $back) === 0) return 'pasien.php';
    if (strpos($back, '//') !== false || strpos($back, '..') !== false) return 'pasien.php';
    return $back;
}

/**
 * Tampilkan modal formulir pasien.
 *
 * @param array|null $edit data pasien saat mode Edit (null = tambah baru)
 * @param string     $back halaman tujuan setelah menyimpan (mis. detail pasien)
 * @param bool       $open modal langsung terbuka
 */
function patient_form_modal(?array $edit = null, string $back = '', bool $open = false): void
{
    $isEdit = (bool)$edit;
    $photo  = $isEdit ? photo_url('patient', $edit) : '';
    $maxPx  = (int)setting('photo_max_patient', '480');
    ?>
<div class="modal<?= $open ? ' open' : '' ?>" id="patientModal">
  <div class="modal-box">
    <form method="post" action="pasien.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <?php /* Penanda halaman asal: dipakai supaya "Edit" dari Detail Pasien kembali
               ke halaman Detail Pasien, bukan ke daftar pasien. */ ?>
      <input type="hidden" name="back" value="<?= e($back) ?>">
      <input type="hidden" name="id" id="p_id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="modal-head"><h3 id="p_title"><?= $isEdit ? 'Edit Data Pasien' : 'Tambah Pasien Baru' ?></h3>
        <button type="button" class="icon-btn" data-modal-close="patientModal"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <div class="photo-thumb-upload">
          <div class="pv" id="p_photo_wrap" style="<?= $photo !== '' ? '' : 'display:none' ?>">
            <img id="p_photo_prev" src="<?= e($photo) ?>" alt="Foto pasien"
                 <?= $photo !== '' ? 'data-zoom="' . e($photo) . '" tabindex="0" role="button"' : '' ?>>
          </div>
          <div class="grow">
            <div class="field"><label>Foto Profil <span class="muted small">(opsional)</span></label>
              <input class="input" type="file" name="photo" id="p_photo" accept="image/*"
                     data-max-kb="<?= img_source_max_kb('patient') ?>"
                     onchange="nvPatientPhotoPreview(this)">
              <span class="hint">JPG/PNG/WebP, <strong>maksimal <?= num(img_source_max_kb('patient')) ?> KB</strong>
                (berkas yang lebih besar ditolak agar penyimpanan hemat). Foto <strong>otomatis dipotong 1:1</strong>
                (bagian tengah) lalu dikompres menjadi maks <?= num($maxPx) ?>×<?= num($maxPx) ?> px,
                mutu <?= e(setting('photo_quality', '80')) ?>. Foto yang sudah ada akan diganti;
                dapat diklik untuk dilihat lebih besar.</span></div>
            <?php if ($isEdit && $photo !== ''): ?>
              <p class="muted small">Foto saat ini ditampilkan di kiri — klik untuk memperbesar.
                Mengunggah berkas baru akan menggantinya.</p>
            <?php endif; ?>
          </div>
        </div>
        <div class="form-grid g2 mt-2">
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
            <input class="input" type="date" name="birth_date" id="p_birth" value="<?= e($edit['birth_date'] ?? '') ?>">
            <span class="hint" id="p_umur"><?php
              $umurForm = age_text($edit['birth_date'] ?? '');
              echo $umurForm !== '' ? 'Umur: ' . e($umurForm) : 'Umur dihitung otomatis dari tanggal lahir.';
            ?></span></div>
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
<script>
/* Pratinjau foto yang baru dipilih SEBELUM disimpan (pakai FileReader di peramban). */
function nvPatientPhotoPreview(input) {
  var w = document.getElementById('p_photo_wrap'), img = document.getElementById('p_photo_prev');
  if (!w || !img) return;
  if (input.files && input.files[0]) {
    var r = new FileReader();
    r.onload = function (e) {
      img.src = e.target.result;
      img.setAttribute('data-zoom', e.target.result);
      w.style.display = 'block';
    };
    r.readAsDataURL(input.files[0]);
  }
}
/* Kosongkan formulir untuk "Tambah Pasien Baru". */
function resetPatientForm() {
  var f = { p_id: 0, p_name: '', p_nik: '', p_phone: '', p_email: '', p_address: '', p_birth: '' };
  Object.keys(f).forEach(function (k) { var el = document.getElementById(k); if (el) el.value = f[k]; });
  var t = document.getElementById('p_title'); if (t) t.textContent = 'Tambah Pasien Baru';
  var file = document.getElementById('p_photo'); if (file) file.value = '';
  var w = document.getElementById('p_photo_wrap'), img = document.getElementById('p_photo_prev');
  if (w) w.style.display = 'none';
  if (img) { img.src = ''; img.removeAttribute('data-zoom'); }
  pUmurUpdate();
}
/* Umur diperbarui langsung saat Tanggal Lahir diisi/diubah. */
function pUmurUpdate() {
  var el = document.getElementById('p_birth'), out = document.getElementById('p_umur');
  if (!el || !out) return;
  var v = (el.value || '').substring(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) { out.textContent = 'Umur dihitung otomatis dari tanggal lahir.'; return; }
  var lahir = new Date(v + 'T00:00:00');
  var now = new Date();
  if (lahir > now) { out.textContent = 'Tanggal lahir tidak boleh di masa depan.'; return; }
  var th = now.getFullYear() - lahir.getFullYear();
  var bl = now.getMonth() - lahir.getMonth();
  if (now.getDate() < lahir.getDate()) bl--;
  if (bl < 0) { th--; bl += 12; }
  var txt = [];
  if (th > 0) txt.push(th + ' tahun');
  if (bl > 0) txt.push(bl + ' bulan');
  out.textContent = txt.length ? 'Umur: ' + txt.join(' ') : 'Umur: kurang dari 1 bulan';
}
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('p_birth');
  if (el) el.addEventListener('change', pUmurUpdate);
});
</script>
    <?php
}
