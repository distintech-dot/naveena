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
if (!is_super()) deny('AI Settings hanya dapat dibuka oleh Super Admin.',
    deny_role_detail('Super Admin')
    . ' Halaman ini mengubah kode aplikasi & menyimpan kunci API, jadi hanya Super Admin yang boleh membukanya.');

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
            /* RONDE 44: batas ukuran lampiran, mode kolaborasi (opsional), dan harga
               per 1 juta token (untuk perkiraan biaya; 0 = tidak dihitung). */
            set_setting('ai_attach_max_mb', (string)max(1, min(40, (int)($_POST['ai_attach_max_mb'] ?? 20))));
            $collab = (string)($_POST['ai_collab_mode'] ?? 'off');
            set_setting('ai_collab_mode', in_array($collab, ['off', 'on'], true) ? $collab : 'off');
            $ap = (string)($_POST['ai_architect_provider'] ?? 'openai');
            if (!array_key_exists($ap, ai_providers())) $ap = 'openai';
            set_setting('ai_architect_provider', $ap);
            $am = trim((string)($_POST['ai_architect_model'] ?? ''));
            if ($am !== '') set_setting('ai_architect_model', preg_replace('/[^A-Za-z0-9._\-]/', '', $am));
            $akey = trim((string)($_POST['ai_architect_key'] ?? ''));
            if ($akey !== '') set_setting('ai_architect_key', $akey);
            elseif (($_POST['ai_architect_key_clear'] ?? '') === '1') set_setting('ai_architect_key', '');
            set_setting('ai_price_in', (string)max(0, (float)($_POST['ai_price_in'] ?? 0)));
            set_setting('ai_price_out', (string)max(0, (float)($_POST['ai_price_out'] ?? 0)));
            /* RONDE 45: CADANGAN OTOMATIS (model kedua pada penyedia lain) supaya
               pekerjaan tidak berhenti ketika satu saldo/kuota habis. */
            set_setting('ai_context_kb', (string)max(20, min(1200, (int)($_POST['ai_context_kb'] ?? 100))));
            $base = trim((string)($_POST['ai_api_base'] ?? ''));
            if ($base !== '' && !preg_match('~^https?://~i', $base)) $base = '';
            set_setting('ai_api_base', $base);
            set_setting('ai_max_rounds', (string)max(1, min(5, (int)($_POST['ai_max_rounds'] ?? 2))));
            /* RONDE 49: uji otomatis di salinan setelah usulan selesai. */
            set_setting('ai_auto_test', (($_POST['ai_auto_test'] ?? '') === '1') ? '1' : '0');
            /* RONDE 50: mode obrolan (AI membedakan bertanya vs minta dikerjakan) dan
               penelusuran dampak (mencari berkas lain yang ikut terpengaruh). */
            set_setting('ai_chat_mode', (($_POST['ai_chat_mode'] ?? '') === '1') ? '1' : '0');
            set_setting('ai_impact_scan', (($_POST['ai_impact_scan'] ?? '') === '1') ? '1' : '0');
            /* RONDE 51: auto error recovery (self-healing) — AI memperbaiki error uji
               sendiri lalu menguji ulang, tanpa jalan pintas. */
            set_setting('ai_heal_on', (($_POST['ai_heal_on'] ?? '') === '1') ? '1' : '0');
            set_setting('ai_heal_rounds', (string)max(1, min(5, (int)($_POST['ai_heal_rounds'] ?? 3))));
            set_setting('ai_fallback_on', (($_POST['ai_fallback_on'] ?? '') === '1') ? '1' : '0');
            $fp = (string)($_POST['ai_fallback_provider'] ?? 'gemini');
            if (!array_key_exists($fp, ai_providers())) $fp = 'gemini';
            set_setting('ai_fallback_provider', $fp);
            $fm = trim((string)($_POST['ai_fallback_model'] ?? ''));
            if ($fm !== '') set_setting('ai_fallback_model', preg_replace('/[^A-Za-z0-9._\-]/', '', $fm));
            $fk = trim((string)($_POST['ai_fallback_key'] ?? ''));
            if ($fk !== '') set_setting('ai_fallback_key', $fk);
            elseif (($_POST['ai_fallback_key_clear'] ?? '') === '1') set_setting('ai_fallback_key', '');
            $now = ai_settings();
            /* Daftar model yang tersimpan berlaku untuk SATU penyedia: bila penyedia
               diganti, daftar lamanya menyesatkan (mis. daftar Gemini ditampilkan saat
               pelaksana sudah OpenAI) → dibuang supaya dimuat ulang. */
            if ($now['provider'] !== (string)setting('ai_models_provider', '')
                || (string)setting('ai_models_cache', '') === '') {
                if ($now['provider'] !== $was['provider']) {
                    set_setting('ai_models_cache', '');
                    set_setting('ai_models_checked_at', '');
                    set_setting('ai_models_provider', $now['provider']);
                }
            }
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
        /* RONDE 43: baca DAFTAR MODEL yang benar-benar tersedia untuk kunci API ini.
           Menjawab pertanyaan pemilik "sudah upgrade Gemini, kenapa di AI Settings
           tidak berubah?" — model adalah setelan manual, dan daftar ini dibuat dari
           penyedia (bukan dari tebakan) supaya pemilik bisa memilih dengan pasti. */
        if ($aksi === 'model_list') {
            $err = '';
            $daftar = ai_provider_models($err);
            if ($daftar) {
                set_setting('ai_models_cache', json_encode($daftar, JSON_UNESCAPED_UNICODE));
                set_setting('ai_models_checked_at', date('Y-m-d H:i:s'));
                set_setting('ai_models_provider', ai_settings()['provider']);
                audit('Muat Daftar Model AI', 'AI Settings', null, null,
                    ['provider' => ai_settings()['provider'], 'jumlah' => count($daftar)],
                    'Daftar model yang tersedia untuk kunci ini dibaca dari penyedia');
                flash('Daftar model berhasil dibaca: ' . num(count($daftar)) . ' model tersedia untuk kunci ini.');
            } else {
                flash('Gagal membaca daftar model: ' . ($err !== '' ? $err : 'tidak diketahui'), 'error');
            }
        }
        /* PRESET KOMBINASI (ronde 45) — satu klik untuk susunan yang disarankan:
           Arsitek = Gemini (membaca konteks & menyusun brief) + Pelaksana = OpenAI
           Luna (menulis patch) + cadangan otomatis Gemini. Pemilik dapat mengubah
           tiap bagian setelahnya. */
        if ($aksi === 'combo_preset') {
            $jenis = (string)($_POST['preset'] ?? 'luna');
            $was = ai_settings();
            if ($jenis === 'luna') {
                set_setting('ai_provider', 'openai');
                set_setting('ai_model', 'gpt-6-luna');
                set_setting('ai_collab_mode', 'on');
                set_setting('ai_architect_provider', 'gemini');
                set_setting('ai_architect_model', 'gemini-3-flash-preview');
                /* Kunci Gemini dipakai sebagai kunci arsitek bila belum diisi. */
                if ((string)setting('ai_architect_key', '') === '' && (string)setting('ai_api_key', '') !== '') {
                    set_setting('ai_architect_key', (string)setting('ai_api_key', ''));
                }
                set_setting('ai_fallback_on', '1');
                set_setting('ai_fallback_provider', 'gemini');
                set_setting('ai_fallback_model', 'gemini-3-flash-preview');
                if ((string)setting('ai_fallback_key', '') === '' && (string)setting('ai_architect_key', '') !== '') {
                    set_setting('ai_fallback_key', (string)setting('ai_architect_key', ''));
                }
                set_setting('ai_collab_preset', 'luna');
            } elseif ($jenis === 'gemini') {
                set_setting('ai_provider', 'gemini');
                set_setting('ai_model', 'gemini-3-flash-preview');
                set_setting('ai_collab_mode', 'off');
                set_setting('ai_fallback_on', '1');
                set_setting('ai_fallback_provider', 'openai');
                set_setting('ai_fallback_model', 'gpt-6-luna');
                set_setting('ai_collab_preset', 'gemini');
            } else {
                set_setting('ai_collab_mode', 'off');
                set_setting('ai_fallback_on', '0');
                set_setting('ai_collab_preset', 'manual');
            }
            $now = ai_settings();
            audit('Terapkan Preset Kombinasi AI', 'AI Settings', null,
                ['pelaksana' => $was['provider'] . '/' . $was['model'], 'kolaborasi' => $was['collab']],
                ['pelaksana' => $now['provider'] . '/' . $now['model'], 'kolaborasi' => $now['collab'],
                 'cadangan' => $now['fallback_provider'] . '/' . $now['fallback_model']],
                'Preset kombinasi AI diterapkan: ' . $jenis);
            flash('Preset kombinasi diterapkan: ' . ai_executor_text()
                . ' · ' . ai_architect_text(), 'success');
        }

        /* Pakai salah satu model dari daftar (satu klik, tanpa mengetik). */
        if ($aksi === 'model_use') {
            $m = trim((string)($_POST['model'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._\-]{2,60}$/', $m)) throw new RuntimeException('Nama model tidak sah.');
            $lama = (string)setting('ai_model', '');
            set_setting('ai_model', $m);
            audit('Ganti Model AI', 'AI Settings', null, ['model' => $lama], ['model' => $m],
                'Model AI diganti dari daftar model yang tersedia');
            flash('Model AI sekarang: ' . $m . '. Klik "Uji Koneksi AI" untuk memastikan model ini menjawab.');
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
  <strong>Panduan aplikasi untuk AI (aktif):</strong> AI Developer dibekali
  <em>panduan lengkap aplikasi ini</em> pada setiap permintaan —
  <strong>peta berkas</strong> (<?= num(count(ai_app_index())) ?> berkas beserta keterangannya, dibaca otomatis
  dari aplikasi yang berjalan), aturan wajib (path relatif, pembatasan cabang, CSRF, satu pintu
  transaksi), daftar helper, resep pengerjaan tugas yang sering diminta, dan jebakan yang sudah
  terbukti. AI juga dapat <strong>meminta membaca berkas lain</strong> sendiri bila informasi di
  konteksnya kurang, lalu melanjutkan menyusun perubahan. Panduan itu tersimpan di
  <code>includes/ai_playbook.php</code> — ikut tercakup sehingga AI dapat memperbaruinya bila Anda minta.
</div>

<div class="alert alert-info">
  <strong>AI Developer memperbaiki errornya sendiri (ronde 51):</strong> bila uji di salinan menemukan
  error (sintaks, runtime PHP, uji gagal, basis data/migrasi, dependensi, API, atau regresi), AI
  <strong>menganalisis akar masalahnya lalu memperbaikinya sendiri</strong> dan menguji ulang — bukan
  menyerahkan perbaikannya ke Anda. Batasnya dijaga: AI <strong>tidak boleh</strong> menghapus atau
  melemahkan pemeriksaan uji (tidak ada PASS palsu), dan bila error-nya butuh kredensial, keputusan
  bisnis, atau penghapusan data, AI berhenti dan <strong>meminta keputusan Anda</strong> (status
  <em>BLOCKED</em>). Status tidak pernah "selesai" selama masih ada error yang dapat diperbaiki.
</div>

<div class="alert alert-info">
  <strong>AI Developer dapat diajak ngobrol (ronde 50):</strong> Anda boleh bertanya, berdiskusi,
  minta saran, minta diperiksa, atau minta rencana — AI menjawab <em>tanpa mengubah kode</em>.
  Begitu Anda minta dikerjakan (atau menulis <code>kerjakan</code> setelah menyetujui usulannya),
  alurnya langsung berubah menjadi pekerjaan nyata: menelusuri berkas, <strong>menelusuri bagian lain
  yang ikut terdampak</strong> (fungsi/tabel/setelan/izin/menu/ekspor/laporan/migrasi), menyusun
  perubahan, <strong>menguji sendiri di salinan</strong>, lalu meminta persetujuan Anda.
  Setiap fase tercatat di <em>AI Developer → Rincian teknis → Jejak kerja AI</em>, termasuk berkas
  yang belum terbaca utuh — kalau ada yang belum terbaca, AI <strong>tidak</strong> menyimpulkan
  &ldquo;tidak ada perubahan&rdquo; (statusnya <em>Audit belum lengkap</em>).
</div>

<div class="alert alert-info">
  <strong>Bagaimana AI Developer bekerja (aman):</strong>
  <ol class="small" style="margin:8px 0 0 18px;padding:0">
    <li><strong>Baca kode</strong> — hanya berkas pada <em>cakupan</em> di bawah (aplikasi + skrip uji).
      Berkas besar dibaca <strong>bertahap</strong> (kepala + bagian yang paling relevan), bukan dibuang.</li>
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
              <option value="<?= e($m) ?>"><?= e($prov) ?><?= $m === ai_model_recommended($prov) ? ' — disarankan' : '' ?></option>
            <?php endforeach; ?>
          </datalist>
          <span class="hint">Model <strong>tidak berubah sendiri</strong> — termasuk ketika paket/kuota
            Gemini Anda di-upgrade. Pilih di sini, atau tekan <strong>"Muat Daftar Model"</strong> pada
            kartu di bawah untuk melihat model apa saja yang benar-benar tersedia untuk kunci Anda lalu
            pakai dengan satu klik. Disarankan: <code><?= e(ai_model_recommended($set['provider'])) ?></code>.</span></div>
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

      <div class="section-title">Lampiran &amp; pemakaian</div>
      <div class="form-grid g3">
        <div class="field"><label>Batas ukuran satu lampiran (MB)</label>
          <input class="input" type="number" name="ai_attach_max_mb" min="1" max="40" value="<?= ai_attach_max_mb() ?>">
          <span class="hint">Excel/PDF/gambar/dokumen boleh diunggah pada setiap permintaan
            (maksimal 8 berkas per permintaan).</span></div>
        <div class="field"><label>Harga masukan (per 1 juta token)</label>
          <input class="input" type="number" name="ai_price_in" min="0" step="0.01" value="<?= e((string)(float)setting('ai_price_in', '0')) ?>">
          <span class="hint">Opsional. Isi 0 bila tidak ingin menghitung perkiraan biaya.</span></div>
        <div class="field"><label>Anggaran konteks (KB) — hemat token</label>
          <input class="input" type="number" name="ai_context_kb" min="20" max="1200" value="<?= (int)ai_settings()['context_kb'] ?>">
          <span class="hint">Batas TOTAL isi berkas yang dikirim ke model pelaksana per panggilan.
            Makin besar = makin paham konteks tetapi makin mahal. Berkas yang tidak kebagian
            anggaran dilaporkan di langkah proses (dan tidak dapat diubah AI).</span></div>
        <div class="field"><label>Maksimal putaran perbaikan</label>
          <input class="input" type="number" name="ai_max_rounds" min="1" max="5" value="<?= (int)ai_settings()['max_rounds'] ?>">
          <span class="hint">Setiap putaran mengirim ulang konteks (menambah biaya). Bila sering berhasil
            pada percobaan pertama, angka 2 sudah cukup dan lebih hemat.</span></div>
        <div class="field"><label>Mode obrolan (membedakan tanya vs kerjakan)</label>
          <label class="flex gap-sm" style="align-items:center">
            <input type="checkbox" name="ai_chat_mode" value="1"<?= ai_settings()['chat_mode'] ? ' checked' : '' ?>>
            <span>Jawab pertanyaan/diskusi tanpa mengubah kode</span>
          </label>
          <span class="hint">Disarankan <strong>aktif</strong>: AI Developer dapat diajak mengobrol —
            pertanyaan, diskusi, permintaan saran, pemeriksaan/audit, dan permintaan rencana dijawab
            tanpa menyentuh berkas. Begitu Anda minta dikerjakan (atau menulis <code>kerjakan</code>),
            alurnya langsung berubah menjadi perubahan kode + uji. Bila dimatikan, setiap pesan
            diperlakukan sebagai perintah pekerjaan (perilaku lama).</span></div>
        <div class="field"><label>Penelusuran dampak otomatis</label>
          <label class="flex gap-sm" style="align-items:center">
            <input type="checkbox" name="ai_impact_scan" value="1"<?= ai_settings()['impact_scan'] ? ' checked' : '' ?>>
            <span>Telusuri berkas lain yang memakai fungsi/tabel/setelan yang sama</span>
          </label>
          <span class="hint">Disarankan <strong>aktif</strong>: penambahan fitur langsung diintegrasikan ke
            bagian yang relevan (menu, hak akses, ekspor, laporan, dashboard, migrasi database) — bukan
            berhenti di satu berkas. Hasilnya dilaporkan di <em>Rincian teknis → Jejak kerja AI</em>.</span></div>
        <div class="field"><label>Auto Error Recovery (perbaiki error sendiri)</label>
          <label class="flex gap-sm" style="align-items:center">
            <input type="checkbox" name="ai_heal_on" value="1"<?= ai_settings()['heal_on'] ? ' checked' : '' ?>>
            <span>Perbaiki sendiri bila uji menemukan error, lalu uji ulang</span>
          </label>
          <span class="hint">Disarankan <strong>aktif</strong>. Alurnya: <em>deteksi error → analisis akar
            masalah → perbaiki → uji ulang → periksa regresi → pemeriksaan akhir</em>. AI
            <strong>tidak boleh</strong> memakai jalan pintas (menghapus/melemahkan pemeriksaan uji) dan
            akan <strong>berhenti meminta keputusan Anda</strong> bila error-nya butuh kredensial,
            keputusan bisnis, atau penghapusan data. Semua langkahnya tercatat di
            <em>Jejak kerja AI</em> dan Audit Log.</span></div>
        <div class="field"><label>Maksimal putaran perbaikan error</label>
          <input class="input" type="number" name="ai_heal_rounds" min="1" max="5" value="<?= (int)ai_settings()['heal_rounds'] ?>">
          <span class="hint">Berapa kali AI boleh mencoba memperbaiki lalu menguji ulang. Bila hasilnya
            tidak berubah dari putaran sebelumnya, AI berhenti lebih awal dan meminta arahan Anda
            (supaya tidak membuang waktu &amp; token).</span></div>
        <div class="field"><label>Uji otomatis di salinan</label>
          <label class="flex gap-sm" style="align-items:center">
            <input type="checkbox" name="ai_auto_test" value="1"<?= ai_settings()['auto_test'] ? ' checked' : '' ?>>
            <span>Jalankan sendiri suite uji setelah usulan selesai</span>
          </label>
          <span class="hint">Disarankan <strong>aktif</strong>: perubahan hanya boleh diterapkan setelah
            uji di salinan lulus, dan dengan pilihan ini Anda tidak perlu menekan tombol uji —
            hasilnya langsung muncul di percakapan AI Developer.</span></div>
        <div class="field"><label>Harga keluaran (per 1 juta token)</label>
          <input class="input" type="number" name="ai_price_out" min="0" step="0.01" value="<?= e((string)(float)setting('ai_price_out', '0')) ?>">
          <span class="hint">Token "berpikir" dihitung sebagai keluaran.</span></div>
      </div>

      <div class="form-grid g2 mt-2">
        <div class="field"><label>Alamat API OpenAI (opsional)</label>
          <input class="input" name="ai_api_base" value="<?= e((string)setting('ai_api_base', '')) ?>"
                 placeholder="https://api.openai.com/v1">
          <span class="hint">Biarkan kosong untuk memakai alamat resmi. Isi hanya bila memakai
            gateway/proxy sendiri.</span></div>
      </div>

      <div class="section-title">Kombinasi model &amp; cadangan otomatis</div>
      <div class="notice small">
        <strong>Susunan yang disarankan</strong> (dan alasannya):
        <ol class="small" style="margin:6px 0 0 18px;padding:0">
          <li><strong>Arsitek = Gemini Flash</strong> — membaca isi berkas &amp; lampiran lalu menyusun
            brief teknis. Pekerjaan ini paling banyak memakai token, jadi lebih hemat dan memakai
            saldo Gemini Anda yang masih ada.</li>
          <li><strong>Pelaksana = OpenAI Luna</strong> — menulis patch kode yang sebenarnya
            (dikenal lebih teliti untuk perubahan web).</li>
          <li><strong>Cadangan otomatis = Gemini</strong> — dipakai bila OpenAI habis kredit/ditolak,
            sehingga pekerjaan tidak pernah berhenti.</li>
        </ol>
        Keadaan sekarang: <strong><?= e(ai_executor_text()) ?></strong><br>
        <?= e(ai_architect_text()) ?>
      </div>
      <form method="post" class="flex gap-sm flex-wrap mt-2" style="align-items:flex-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="combo_preset">
        <div class="field" style="flex:1 1 320px"><label>Terapkan preset kombinasi</label>
          <select class="input" name="preset">
            <option value="luna"<?= setting('ai_collab_preset') === 'luna' ? ' selected' : '' ?>>Disarankan — Arsitek Gemini + Pelaksana OpenAI Luna (cadangan Gemini)</option>
            <option value="gemini"<?= setting('ai_collab_preset') === 'gemini' ? ' selected' : '' ?>>Hemat OpenAI — Pelaksana Gemini + cadangan OpenAI Luna</option>
            <option value="manual"<?= setting('ai_collab_preset') === 'manual' ? ' selected' : '' ?>>Manual — saya atur sendiri (kolaborasi &amp; cadangan dimatikan)</option>
          </select>
          <span class="hint">Preset hanya menuliskan pilihan di bawah — semuanya masih dapat Anda ubah.</span></div>
        <button class="btn btn-primary" type="submit"><?= icon('settings') ?> Terapkan preset</button>
      </form>

      <div class="section-title">Cadangan otomatis (model kedua)</div>
      <div class="notice small">
        Bila pelaksana gagal (kredit habis, kuota, atau penyedia menolak), model cadangan yang
        menyelesaikan permintaan. Pemakaian token kedua model tetap dicatat terpisah sehingga Anda tahu
        berapa yang benar-benar terpakai di masing-masing saldo.
        Cadangan butuh kuncinya sendiri bila berbeda penyedia.
      </div>
      <div class="form-grid g2 mt-2">
        <div class="field"><label>Cadangan otomatis</label>
          <select class="input" name="ai_fallback_on">
            <option value="1"<?= ai_settings()['fallback_on'] ? ' selected' : '' ?>>Aktif</option>
            <option value="0"<?= !ai_settings()['fallback_on'] ? ' selected' : '' ?>>Nonaktif</option>
          </select></div>
        <div class="field"><label>Cadangan — penyedia</label>
          <select class="input" name="ai_fallback_provider">
            <?php foreach (ai_providers() as $k => $pv): if ($k === 'mock') continue; ?>
              <option value="<?= e($k) ?>"<?= ai_settings()['fallback_provider'] === $k ? ' selected' : '' ?>><?= e($pv['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Cadangan — model</label>
          <input class="input" name="ai_fallback_model" value="<?= e(ai_settings()['fallback_model']) ?>"></div>
        <div class="field"><label>Cadangan — kunci API</label>
          <input class="input" type="password" name="ai_fallback_key" autocomplete="off"
                 placeholder="<?= setting('ai_fallback_key') !== '' ? '•••••• (tersimpan)' : 'belum diisi' ?>">
          <?php if (setting('ai_fallback_key') !== ''): ?>
            <label class="check mt-1"><input type="checkbox" name="ai_fallback_key_clear" value="1">
              <span class="small">hapus kunci yang tersimpan</span></label>
          <?php endif; ?></div>
      </div>

      <div class="section-title">Mode kolaborasi (opsional) — dua model bekerja bersama</div>
      <div class="notice small">
        Bila diaktifkan, satu model <strong>Arsitek</strong> lebih dulu menerjemahkan perintah bebas Anda
        menjadi <em>brief teknis</em> (tujuan, langkah, berkas, aturan data, kriteria selesai), lalu model
        utama (<strong>Pelaksana</strong>: <?= e(ai_providers()[ai_settings()['provider']]['name'] ?? '') ?>
        · <?= e(ai_settings()['model']) ?>) mengerjakan patch-nya.
        Berguna bila Anda memakai Gemini untuk pelaksana dan ingin "penerjemah perintah" dari penyedia lain.
        Tanpa kunci kedua, mode ini otomatis dilewati (tidak pernah diklaim aktif).
      </div>
      <div class="form-grid g2 mt-2">
        <div class="field"><label>Mode kolaborasi</label>
          <select class="input" name="ai_collab_mode">
            <option value="off"<?= ai_settings()['collab'] !== 'on' ? ' selected' : '' ?>>Nonaktif (hanya model utama)</option>
            <option value="on"<?= ai_settings()['collab'] === 'on' ? ' selected' : '' ?>>Aktif (Arsitek + Pelaksana)</option>
          </select>
          <span class="hint">Keadaan sekarang: <?= e(ai_architect_text()) ?></span></div>
        <div class="field"><label>Arsitek — penyedia</label>
          <select class="input" name="ai_architect_provider">
            <?php foreach (ai_providers() as $k => $pv): if ($k === 'mock') continue; ?>
              <option value="<?= e($k) ?>"<?= ai_settings()['arch_provider'] === $k ? ' selected' : '' ?>><?= e($pv['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Arsitek — model</label>
          <input class="input" name="ai_architect_model" value="<?= e(ai_settings()['arch_model']) ?>">
          <span class="hint">Contoh: <code>gpt-4.1-mini</code> atau <code>gpt-4o-mini</code>.</span></div>
        <div class="field"><label>Arsitek — kunci API</label>
          <input class="input" type="password" name="ai_architect_key" autocomplete="off"
                 placeholder="<?= setting('ai_architect_key') !== '' ? '•••••• (tersimpan)' : 'belum diisi' ?>">
          <?php if (setting('ai_architect_key') !== ''): ?>
            <label class="check mt-1"><input type="checkbox" name="ai_architect_key_clear" value="1">
              <span class="small">hapus kunci yang tersimpan</span></label>
          <?php endif; ?>
          <span class="hint">Kunci ini juga tidak pernah ditampilkan kembali.</span></div>
      </div>

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

<?php
/* ==========================================================================
 * PEMAKAIAN TOKEN (ronde 44) — permintaan pemilik: "tambahkan fitur sisa
 * token/kredit ... agar saya tahu habis berapa setiap pengerjaan".
 * Yang dapat dilaporkan dengan JUJUR: pemakaian yang benar-benar dicatat dari
 * balasan penyedia. Sisa kuota/langganan TIDAK dapat dibaca lewat API (Google
 * tidak menyediakannya) — karena itu ditulis apa adanya beserta tautan ke dasbor.
 * ======================================================================== */
$pakai30 = ai_usage_summary(30);
$pakaiAll = ai_usage_summary(0);
?>
<div class="card" id="pemakaian">
  <div class="card-head">
    <h3>Pemakaian Token &amp; Perkiraan Biaya</h3>
    <span><?= badge(num((int)$pakai30['total']['total']) . ' token / 30 hari', 'blue') ?></span>
  </div>
  <div class="card-body">
    <div class="grid g4">
      <div class="stat"><span class="lbl">30 hari terakhir</span>
        <span class="val" style="font-size:1.15rem"><?= num((int)$pakai30['total']['total']) ?></span>
        <span class="sub"><?= num((int)$pakai30['calls']) ?> panggilan · <?= num((int)$pakai30['tugas']) ?> permintaan</span></div>
      <div class="stat"><span class="lbl">Token masuk (30 hari)</span>
        <span class="val" style="font-size:1.15rem"><?= num((int)$pakai30['total']['in']) ?></span>
        <span class="sub">isi kode &amp; lampiran yang dikirim</span></div>
      <div class="stat"><span class="lbl">Token keluar (30 hari)</span>
        <span class="val" style="font-size:1.15rem"><?= num((int)$pakai30['total']['out']) ?></span>
        <span class="sub">jawaban AI yang ditulis</span></div>
      <div class="stat"><span class="lbl">Token berpikir (30 hari)</span>
        <span class="val" style="font-size:1.15rem"><?= num((int)$pakai30['total']['think']) ?></span>
        <span class="sub">model Gemini 3 memakai ini</span></div>
    </div>

    <?php if ($pakai30['total']['total'] <= 0): ?>
      <div class="notice small mt-2">Belum ada pemakaian tercatat dalam 30 hari terakhir.
        Angka muncul otomatis setiap kali AI dipakai dari halaman AI Developer.</div>
    <?php endif; ?>

    <?php if ($pakai30['per_model']): ?>
      <div class="section-title">Rincian per model (30 hari terakhir)</div>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Penyedia / model</th><th>Panggilan</th><th>Token masuk</th><th>Token keluar</th>
            <th>Berpikir</th><th>Total</th><th>Perkiraan biaya</th></tr></thead>
          <tbody>
          <?php foreach ($pakai30['per_model'] as $pm): ?>
            <tr>
              <td><?= e($pm['provider']) ?> · <code><?= e($pm['model']) ?></code></td>
              <td><?= num((int)$pm['calls']) ?></td>
              <td><?= num((int)$pm['usage']['in']) ?></td>
              <td><?= num((int)$pm['usage']['out']) ?></td>
              <td><?= num((int)$pm['usage']['think']) ?></td>
              <td><strong><?= num((int)$pm['usage']['total']) ?></strong></td>
              <td><?= $pm['biaya'] > 0 ? money($pm['biaya']) : '<span class="muted">harga belum diisi</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="notice small mt-2">
      <strong>Perkiraan biaya</strong> dihitung dari harga yang Anda isi di kartu Penyedia AI
      (per 1 juta token). Bila dibiarkan 0, kolom biaya tidak dihitung — yang dilaporkan hanya jumlah token.
    </div>
    <div class="alert alert-info mt-2">
      <strong>Sisa kuota / kredit langganan tidak dapat dibaca dari aplikasi ini.</strong>
      Penyedia AI tidak menyediakan API untuk memeriksa sisa kuota (Google khususnya tidak), jadi sisa
      kuota hanya bisa dilihat di dasbor penyedia:
      <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a> ·
      <a href="https://platform.openai.com/usage" target="_blank" rel="noopener">OpenAI Usage</a>.
      Yang dilaporkan di halaman ini adalah <strong>pemakaian nyata</strong> (dikirim penyedia) sehingga
      Anda tahu setiap pengerjaan menghabiskan berapa token.
      <?php if ((int)$pakaiAll['total']['total'] > 0 && (int)$pakai30['total']['total'] > 0): ?>
        <div class="small mt-1">Total seluruh riwayat: <?= num((int)$pakaiAll['total']['total']) ?> token ·
          <?= num((int)$pakaiAll['calls']) ?> panggilan<?= $pakaiAll['biaya'] > 0 ? ' · perkiraan ' . money($pakaiAll['biaya']) : '' ?>.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card" id="daftarmodel">
  <div class="card-head"><h3>Model yang Tersedia untuk Kunci Anda</h3>
    <span>
      <?php if (setting('ai_models_checked_at') !== ''): ?>
        <?= badge(num(count((array)json_decode((string)setting('ai_models_cache'), true))) . ' model', 'blue') ?>
        <span class="muted small">dibaca <?= e(tgl((string)setting('ai_models_checked_at'), true)) ?></span>
      <?php else: ?><?= badge('Belum pernah dibaca', 'gray') ?><?php endif; ?>
    </span></div>
  <div class="card-body">
    <p class="muted">Daftar ini <strong>dibaca langsung dari penyedia AI</strong> memakai kunci API Anda,
      jadi isinya pasti (bukan tebakan). Berguna ketika paket Anda di-upgrade: model baru muncul di sini
      dan dapat dipakai dengan satu klik. Tekan tombolnya bila baru mengubah paket/kunci.</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="model_list">
      <button class="btn btn-primary" type="submit"><?= icon('search') ?> Muat Daftar Model</button>
      <span class="muted small">Memanggil penyedia sekali (tanpa memakai kuota generate).</span>
    </form>

    <?php
    $cache = json_decode((string)setting('ai_models_cache'), true);
    $daftarModel = is_array($cache) ? $cache : [];
    $penyediaCache = (string)setting('ai_models_provider', '');
    $cacheBasi = ($daftarModel && $penyediaCache !== '' && $penyediaCache !== $set['provider']);
    if ($cacheBasi) $daftarModel = [];
    ?>
    <?php if ($cacheBasi): ?>
      <div class="alert alert-warning mt-2">
        Daftar model yang tersimpan berlaku untuk penyedia <strong><?= e(strtoupper($penyediaCache)) ?></strong>,
        sedangkan pelaksana sekarang <strong><?= e(strtoupper($set['provider'])) ?></strong> — jadi daftar itu
        tidak ditampilkan supaya tidak menyesatkan. Tekan <strong>Muat Daftar Model</strong> untuk penyedia ini.
      </div>
    <?php endif; ?>
    <?php if ($daftarModel): ?>
      <div class="table-wrap scroll-y mt-2" style="max-height:420px">
        <table class="tbl">
          <thead><tr><th>Model</th><th style="width:150px">Keadaan</th><th style="width:130px"></th></tr></thead>
          <tbody>
          <?php foreach ($daftarModel as $m): ?>
            <tr>
              <td><code><?= e((string)$m) ?></code>
                <?php if ((string)$m === ai_model_recommended($set['provider'])): ?>
                  <span class="badge badge-green">disarankan</span><?php endif; ?></td>
              <td><?= (string)$m === (string)$set['model']
                    ? badge('Sedang dipakai', 'green') : badge('tersedia', 'gray') ?></td>
              <td>
                <?php if ((string)$m !== (string)$set['model']): ?>
                  <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="model_use">
                    <input type="hidden" name="model" value="<?= e((string)$m) ?>">
                    <button class="btn btn-sm" type="submit">Pakai model ini</button>
                  </form>
                <?php else: ?><span class="muted small">aktif</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="notice small mt-2">Setelah mengganti model, tekan <strong>Uji Koneksi AI</strong> di
        atas untuk memastikan model itu benar-benar menjawab dengan kunci Anda.</div>
    <?php elseif (setting('ai_models_checked_at') !== ''): ?>
      <div class="alert alert-warning mt-2">Daftar terakhir tidak tersimpan (penyedia menolak).
        Periksa kunci API lalu muat ulang.</div>
    <?php else: ?>
      <div class="notice small mt-2">Belum dimuat. Tekan <strong>Muat Daftar Model</strong> untuk melihat
        model yang tersedia bagi kunci API Anda.</div>
    <?php endif; ?>
  </div>
</div>

<?php page_foot(); ?>
