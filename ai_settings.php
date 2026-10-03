<?php
/**
 * AI SETTINGS — pengaturan penyedia AI (Gemini / OpenAI / tiruan).
 *
 * Menu ini berada di sidebar tepat DI BAWAH "Backup Database", disusul menu
 * "AI Developer". Khusus Super Admin (ditegakkan di server), karena kunci API
 * bersifat rahasia dan menu berikutnya dapat mengubah kode aplikasi.
 *
 * Alur pemakaiannya dijelaskan panjang di halaman supaya pemilik tahu batasannya
 * (khususnya: AI TIDAK pernah menulis kode sendiri — selalu ada pratinjau,
 * uji, dan persetujuan).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
if (!is_super()) deny('AI Settings hanya dapat dibuka oleh Super Admin.');
$user = current_user();

$aksi = (string)($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if ($aksi === 'simpan') {
            $was = ai_settings();
            set_setting('ai_enabled', ($_POST['ai_enabled'] ?? '') === '1' ? '1' : '0');
            $prov = (string)($_POST['ai_provider'] ?? 'gemini');
            if (!array_key_exists($prov, ai_providers())) $prov = 'gemini';
            set_setting('ai_provider', $prov);
            $model = trim((string)($_POST['ai_model'] ?? ''));
            if ($model === '') $model = (ai_model_options()[$prov][0] ?? '');
            set_setting('ai_model', $model);
            /* Kunci API: hanya ditimpa bila kolomnya diisi (agar tidak terhapus
               tanpa sengaja saat menyimpan pengaturan lain). */
            $keyBaru = trim((string)($_POST['ai_api_key'] ?? ''));
            if ($keyBaru !== '') {
                set_setting('ai_api_key', $keyBaru);
            } elseif (($_POST['ai_api_key_clear'] ?? '') === '1') {
                set_setting('ai_api_key', '');
            }
            /* Cakupan folder: aplikasi + skrip uji (bawaan). */
            $scope = [];
            foreach ((array)($_POST['ai_scope'] ?? []) as $sc) {
                $sc = trim((string)$sc, '/');
                if (in_array($sc, ['naveena', 'naveena_dev/test'], true)) $scope[] = $sc;
            }
            set_setting('ai_scope', implode(',', $scope));
            set_setting('ai_max_files', (string)max(1, min(40, (int)($_POST['ai_max_files'] ?? 12))));
            set_setting('ai_max_file_kb', (string)max(8, min(1024, (int)($_POST['ai_max_file_kb'] ?? 120))));
            $suite = (string)($_POST['ai_default_suite'] ?? '');
            if ($suite !== '' && in_array($suite, ai_suite_list(), true)) set_setting('ai_default_suite', $suite);
            $now = ai_settings();
            audit('Ubah Pengaturan AI', 'AI Settings', null,
                ['provider' => $was['provider'], 'model' => $was['model'], 'aktif' => $was['enabled'], 'cakupan' => $was['scope']],
                ['provider' => $now['provider'], 'model' => $now['model'], 'aktif' => $now['enabled'], 'cakupan' => $now['scope']],
                'Pengaturan AI Developer diperbarui' . ($keyBaru !== '' ? ' (kunci API diperbarui)' : ''));
            flash('Pengaturan AI disimpan. ' . ai_ready_text());
        }
        if ($aksi === 'uji') {
            $err = '';
            $ok = ai_provider_test($err);
            set_setting('ai_last_test_at', date('Y-m-d H:i:s'));
            set_setting('ai_last_test_ok', $ok ? '1' : '0');
            set_setting('ai_last_test_msg', short_text($ok ? 'Koneksi berhasil — AI menjawab.' : (string)$err, 300));
            audit($ok ? 'Uji Koneksi AI Berhasil' : 'Uji Koneksi AI Gagal', 'AI Settings', null, null,
                ['provider' => ai_settings()['provider'], 'model' => ai_settings()['model'], 'ok' => $ok],
                $ok ? 'Koneksi ke penyedia AI berhasil' : ('Gagal: ' . (string)$err));
            flash($ok ? 'Koneksi ke penyedia AI BERHASIL (AI menjawab).' : ('Koneksi GAGAL: ' . $err), $ok ? 'success' : 'error');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: ai_settings.php');
    exit;
}

$set = ai_settings();
$ujiTerakhir = (string)setting('ai_last_test_at', '');
$suite = ai_suite_list();
page_head('AI Settings', 'ai_settings');
?>
<div class="page-head">
  <div>
    <h2>AI Settings</h2>
    <p class="muted">Penyedia AI untuk menu <a href="ai_developer.php">AI Developer</a> ·
      <?= badge(ai_ready() ? 'Siap dipakai' : 'Belum siap', ai_ready() ? 'green' : 'yellow') ?>
      <?= badge('Khusus Super Admin', 'pink') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="ai_developer.php"><?= icon('sparkles') ?> Buka AI Developer</a>
  </div>
</div>

<div class="alert alert-info">
  <strong>Bagaimana AI Developer bekerja (aman):</strong>
  <ol class="small" style="margin:8px 0 0 18px;padding:0">
    <li><strong>Baca kode</strong> — hanya berkas pada <em>cakupan</em> di bawah (aplikasi + skrip uji).</li>
    <li><strong>Analisis permintaan</strong> &amp; <strong>usulkan perubahan</strong> dalam bentuk patch
      (potongan teks lama → baru). AI <strong>tidak pernah</strong> menulis berkas sendiri.</li>
    <li><strong>Pratinjau</strong> (diff + pemeriksaan sintaks PHP/JS/CSS) — dapat Anda baca sebelum apa pun berubah.</li>
    <li><strong>Uji di folder STAGING</strong> (salinan aplikasi) — jadi menjalankan uji tidak menyentuh data produksi.</li>
    <li><strong>Super Admin menyetujui</strong> (kata kunci + kata sandi) → baru <strong>DITERAPKAN</strong>,
      selalu dengan salinan pengaman berkas dan tombol <em>Batalkan</em>.</li>
  </ol>
</div>

<div class="card">
  <div class="card-head"><h3>Penyedia AI</h3>
    <span class="muted"><?= e(ai_ready_text()) ?></span></div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="simpan">
    <div class="card-body">
      <div class="form-grid g2">
        <div class="field"><label>Aktifkan AI Developer</label>
          <select class="input" name="ai_enabled">
            <option value="1"<?= $set['enabled'] ? ' selected' : '' ?>>Aktif</option>
            <option value="0"<?= !$set['enabled'] ? ' selected' : '' ?>>Nonaktif</option>
          </select>
          <span class="hint">Bila nonaktif, menu AI Developer tidak dapat menjalankan AI.</span></div>
        <div class="field"><label>Penyedia</label>
          <select class="input" name="ai_provider" data-autosubmit-in-form>
            <?php foreach (ai_providers() as $k => $p): ?>
              <option value="<?= e($k) ?>"<?= $set['provider'] === $k ? ' selected' : '' ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Gemini &amp; OpenAI memerlukan kunci API. Mode "Tiruan" dipakai uji otomatis.</span></div>
        <div class="field"><label>Model</label>
          <input class="input" name="ai_model" list="modelList" value="<?= e($set['model']) ?>">
          <datalist id="modelList">
            <?php foreach (ai_model_options() as $prov => $daftar) foreach ($daftar as $m): ?>
              <option value="<?= e($m) ?>"><?= e($prov) ?></option>
            <?php endforeach; ?>
          </datalist>
          <span class="hint">Gemini yang tersedia untuk kunci Anda antara lain
            <code>gemini-flash-latest</code>, <code>gemini-3-flash-preview</code>,
            <code>gemini-3.8-flash</code> (model <code>2.5-pro</code> bisa kena batas kuota paket gratis).</span></div>
        <div class="field"><label>Kunci API</label>
          <input class="input" type="password" name="ai_api_key" autocomplete="off"
                 placeholder="<?= setting('ai_api_key') !== '' ? '•••••• (tersimpan)' : 'belum diisi' ?>">
          <?php if (setting('ai_api_key') !== ''): ?>
            <label class="check mt-1"><input type="checkbox" name="ai_api_key_clear" value="1">
              <span class="small">hapus kunci yang tersimpan</span></label>
          <?php endif; ?>
          <span class="hint">Kunci <strong>tidak pernah ditampilkan kembali</strong> dan tidak ditulis ke log.
            Kosongkan kolom ini bila tidak ingin mengubahnya.</span></div>
      </div>

      <div class="section-title">Cakupan berkas yang boleh dibaca/diubah AI</div>
      <div class="flex flex-wrap gap-sm" style="gap:16px">
        <label class="check"><input type="checkbox" name="ai_scope[]" value="naveena"
          <?= in_array('naveena', $set['scope'], true) ? 'checked' : '' ?>> <span>Aplikasi (<code>naveena/</code>)</span></label>
        <label class="check"><input type="checkbox" name="ai_scope[]" value="naveena_dev/test"
          <?= in_array('naveena_dev/test', $set['scope'], true) ? 'checked' : '' ?>> <span>Skrip uji (<code>naveena_dev/test/</code>)</span></label>
      </div>
      <span class="hint">Di luar cakupan ini AI tidak dapat membaca/mengubah apa pun (basis data, unggahan,
        dan berkas rahasia tidak pernah ikut).</span>

      <div class="form-grid g3 mt-2">
        <div class="field"><label>Maksimal berkas per permintaan</label>
          <input class="input" type="number" name="ai_max_files" min="1" max="40" value="<?= (int)$set['max_files'] ?>">
          <span class="hint">Semakin banyak berkas, semakin luas konteksnya tetapi makin lambat.</span></div>
        <div class="field"><label>Ukuran maksimal berkas (KB)</label>
          <input class="input" type="number" name="ai_max_file_kb" min="8" max="1024" value="<?= (int)$set['max_file_kb'] ?>">
          <span class="hint">Berkas yang lebih besar dilewati (dilaporkan di pratinjau).</span></div>
        <div class="field"><label>Suite uji bawaan</label>
          <select class="input" name="ai_default_suite">
            <?php foreach ($suite as $s): ?>
              <option value="<?= e($s) ?>"<?= $set['default_suite'] === $s ? ' selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Suite yang dijalankan di staging sebelum persetujuan (dapat diganti per permintaan).</span></div>
      </div>
      <div class="notice small mt-2">
        <strong>Catatan biaya:</strong> setiap permintaan mengirim sebagian kode ke penyedia AI. Model
        "flash" jauh lebih murah/cepat daripada "pro". Kunci API Anda dipakai langsung dari server ini;
        token tidak pernah dibagikan ke pihak lain.
      </div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit"><?= icon('save') ?> Simpan Pengaturan AI</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-head"><h3>Uji Koneksi</h3>
    <span><?php if ($ujiTerakhir !== ''): ?>
      <?= badge(setting('ai_last_test_ok') === '1' ? 'Terakhir: berhasil' : 'Terakhir: gagal',
                setting('ai_last_test_ok') === '1' ? 'green' : 'red') ?>
      <span class="muted small"><?= e(tgl($ujiTerakhir, true)) ?></span>
    <?php else: ?><?= badge('Belum pernah diuji', 'gray') ?><?php endif; ?></span></div>
  <div class="card-body">
    <p class="muted">Mengirim pertanyaan sangat pendek ke penyedia AI untuk memastikan kunci API dan model
      dapat dipakai. Hasilnya dilaporkan apa adanya.</p>
    <?php if (setting('ai_last_test_msg') !== ''): ?>
      <div class="notice small">Hasil terakhir: <?= e((string)setting('ai_last_test_msg')) ?></div>
    <?php endif; ?>
    <form method="post" class="mt-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="uji">
      <button class="btn btn-primary" type="submit"><?= icon('bell') ?> Uji Koneksi AI</button>
      <span class="muted small">Pastikan kunci API sudah disimpan lebih dulu.</span>
    </form>
  </div>
</div>

<?php page_foot(); ?>
