<?php
/**
 * AI DEVELOPER — bantu revisi / perbaiki / tambah fitur pada aplikasi ini.
 * =====================================================================
 *
 * Alur (semuanya dapat dilihat & dikendalikan Super Admin):
 *
 *   1. Tulis permintaan (mis. "tombol simpan di halaman pasien tidak menyimpan email").
 *   2. AI membaca kode yang relevan → MENYUSUN USULAN (patch) — tidak menulis apa pun.
 *   3. PRATINJAU: rencana, daftar berkas, diff, dan hasil pemeriksaan sintaks.
 *   4. JALANKAN UJI di folder STAGING (salinan) — data produksi tidak tersentuh.
 *   5. Anda SETUJUI (kata kunci + kata sandi) → diterapkan ke aplikasi (dengan salinan pengaman).
 *   6. Bila ada masalah: tombol BATALKAN mengembalikan berkas seperti semula.
 *
 * Halaman ini juga punya titik JSON `?ajax=status` untuk memantau pekerjaan latar
 * belakang (panggilan AI & suite uji) tanpa memuat ulang seluruh halaman.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
if (!is_super()) deny('AI Developer hanya dapat dibuka oleh Super Admin.');
$user = current_user();

/* ------------------------------------------------------------------ *
 * Titik JSON untuk memantau pekerjaan berjalan
 * ------------------------------------------------------------------ */
if (gp('ajax') === 'status') {
    header('Content-Type: application/json');
    $id = (int)gp('id');
    $t = $id > 0 ? ai_task($id) : null;
    if (!$t) { echo json_encode(['ok' => false, 'error' => 'Tugas tidak ditemukan.']); exit; }
    [$lbl, $tone] = ai_status_label((string)$t['status']);
    $uji = ['jalan' => false, 'selesai' => false, 'pass' => 0, 'fail' => 0, 'ringkas' => ''];
    if (in_array((string)$t['status'], ['testing'], true) || (string)$t['test_status'] !== '') {
        $uji = ai_test_result((string)($t['test_log'] ?? ''));
    }
    echo json_encode([
        'ok' => true, 'id' => (int)$t['id'], 'status' => (string)$t['status'],
        'status_label' => $lbl, 'stage' => (string)($t['stage'] ?? ''),
        'error' => (string)($t['error'] ?? ''), 'test_status' => (string)($t['test_status'] ?? ''),
        'test' => ['jalan' => (bool)$uji['jalan'], 'selesai' => (bool)($uji['selesai'] ?? false),
                   'pass' => (int)$uji['pass'], 'fail' => (int)$uji['fail'],
                   'ringkas' => (string)($uji['ringkas'] ?? '')],
        'berjalan' => in_array((string)$t['status'], ['analyzing', 'testing'], true),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------ *
 * Aksi
 * ------------------------------------------------------------------ */
$aksi = (string)($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if ($aksi === 'minta') {
            if (!ai_ready()) throw new RuntimeException(ai_ready_text());
            $req = trim((string)($_POST['request'] ?? ''));
            if (ai_strlen($req) < 10) throw new RuntimeException('Tulis permintaan yang lebih jelas (minimal 10 huruf).');
            if (ai_strlen($req) > 4000) throw new RuntimeException('Permintaan maksimal 4000 huruf.');
            $suite = (string)($_POST['suite'] ?? '');
            if ($suite !== '' && !in_array($suite, ai_suite_list(), true)) $suite = '';
            q('INSERT INTO ai_tasks (user_id, request, provider, model, status, stage, suite, created_at)
               VALUES (?,?,?,?, "draft", "Menunggu dijalankan AI", ?, datetime("now","localtime"))',
                [(int)$user['id'], $req, ai_settings()['provider'], ai_settings()['model'],
                 $suite !== '' ? $suite : ai_settings()['default_suite']]);
            $id = (int)db()->lastInsertId();
            audit('Minta Perubahan AI', 'AI Developer', $id, null,
                ['provider' => ai_settings()['provider'], 'model' => ai_settings()['model'], 'permintaan' => short_text($req, 200)],
                'Permintaan pengembangan dikirim ke AI');
            ai_spawn_worker('plan', $id);
            flash('Permintaan dikirim. AI sedang menganalisis — halaman ini akan memperbarui hasilnya sendiri.');
            header('Location: ai_developer.php?id=' . $id);
            exit;
        }
        if ($aksi === 'uji') {
            $id = (int)($_POST['id'] ?? 0);
            $t = ai_task($id);
            if (!$t) throw new RuntimeException('Tugas tidak ditemukan.');
            if ((string)$t['status'] === 'applied') throw new RuntimeException('Perubahan sudah diterapkan.');
            if (!$t['ops']) throw new RuntimeException('Belum ada usulan perubahan untuk diuji.');
            $suite = (string)($_POST['suite'] ?? '');
            if ($suite !== '' && in_array($suite, ai_suite_list(), true)) {
                ai_task_update($id, ['suite' => $suite]);
            }
            ai_spawn_worker('test', $id);
            audit('Jalankan Uji AI di Staging', 'AI Developer', $id, null,
                ['suite' => (string)(ai_task($id)['suite'] ?? '')], 'Suite uji dijalankan di folder staging');
            flash('Uji dijalankan di folder STAGING (bukan aplikasi terbit). Hasilnya muncul di halaman ini.');
            header('Location: ai_developer.php?id=' . $id);
            exit;
        }
        if ($aksi === 'tolak') {
            $id = (int)($_POST['id'] ?? 0);
            $t = ai_task($id);
            if (!$t) throw new RuntimeException('Tugas tidak ditemukan.');
            ai_task_update($id, ['status' => 'rejected', 'stage' => 'Ditolak admin']);
            audit('Tolak Usulan AI', 'AI Developer', $id, null, null,
                'Usulan AI ditolak: ' . short_text((string)($_POST['reason'] ?? ''), 150));
            flash('Usulan AI ditolak — tidak ada perubahan yang diterapkan.', 'warning');
            header('Location: ai_developer.php?id=' . $id);
            exit;
        }
        if ($aksi === 'terapkan') {
            /* PERSETUJUAN: hanya Super Admin (halaman ini) + sandi + kata kunci. */
            $id = (int)($_POST['id'] ?? 0);
            $t = ai_task($id);
            if (!$t) throw new RuntimeException('Tugas tidak ditemukan.');
            if ((string)($_POST['confirm_word'] ?? '') !== 'TERAPKAN') {
                throw new RuntimeException('Kata kunci konfirmasi tidak sesuai (tulis TERAPKAN).');
            }
            $pass = (string)($_POST['password'] ?? '');
            if ($pass === '' || !password_verify($pass, (string)$user['password_hash'])) {
                audit('Terapkan AI Ditolak', 'AI Developer', $id, null, null, 'Kata sandi Super Admin salah');
                throw new RuntimeException('Kata sandi tidak sesuai — perubahan TIDAK diterapkan.');
            }
            if (!$t['ops']) throw new RuntimeException('Belum ada usulan perubahan.');
            if ((string)$t['status'] === 'applied') throw new RuntimeException('Perubahan ini sudah diterapkan sebelumnya.');
            if ((string)$t['test_status'] !== 'lulus') {
                throw new RuntimeException('Uji belum LULUS. Jalankan uji di staging lebih dulu '
                    . '(uji wajib lulus sebelum penerapan) — atau tolak usulan ini.');
            }
            $terap = ai_apply_patch_to_content($t['ops']);
            if (!$terap['ok']) throw new RuntimeException('Tidak dapat menerapkan: ' . $terap['error']);
            /* Salinan pengaman berkas yang akan berubah (untuk tombol Batalkan). */
            $snap = ai_snapshot($id, $terap['files']);
            $tulis = ai_apply_to_app($terap['files']);
            if (!$tulis['ok']) {
                ai_rollback($id);
                throw new RuntimeException('Gagal menulis berkas: ' . $tulis['error'] . ' (perubahan dibatalkan otomatis)');
            }
            $lint = ai_lint_contents($terap['files']);
            ai_task_update($id, [
                'status' => 'applied', 'applied_at' => date('Y-m-d H:i:s'),
                'snapshot_dir' => $snap, 'stage' => 'DITERAPKAN ke aplikasi',
                'error' => $lint['ok'] ? '' : 'Perubahan diterapkan tetapi pemeriksaan sintaks menemukan masalah — periksa segera / batalkan.',
            ]);
            audit('Terapkan Perubahan AI', 'AI Developer', $id, null,
                ['berkas' => array_keys($terap['files']), 'sintaks_ok' => $lint['ok']],
                'Perubahan dari AI DITERAPKAN setelah disetujui Super Admin');
            flash('Perubahan DITERAPKAN ke aplikasi (' . count($terap['files']) . ' berkas). '
                . ($lint['ok'] ? 'Pemeriksaan sintaks OK.' : 'PERHATIAN: ada masalah sintaks, batalkan bila perlu.')
                . ' Bila ada masalah, gunakan tombol "Batalkan".', $lint['ok'] ? 'success' : 'warning');
            header('Location: ai_developer.php?id=' . $id);
            exit;
        }
        if ($aksi === 'batalkan') {
            $id = (int)($_POST['id'] ?? 0);
            $t = ai_task($id);
            if (!$t) throw new RuntimeException('Tugas tidak ditemukan.');
            if ((string)$t['status'] !== 'applied') throw new RuntimeException('Perubahan ini belum diterapkan.');
            $pass = (string)($_POST['password'] ?? '');
            if ($pass === '' || !password_verify($pass, (string)$user['password_hash'])) {
                throw new RuntimeException('Kata sandi tidak sesuai — perubahan TIDAK dibatalkan.');
            }
            $res = ai_rollback($id);
            if (!$res['ok']) throw new RuntimeException($res['error']);
            ai_task_update($id, ['status' => 'rolledback', 'rolled_back_at' => date('Y-m-d H:i:s'),
                'stage' => 'Dibatalkan — berkas dikembalikan']);
            audit('Batalkan Perubahan AI', 'AI Developer', $id, null, $res,
                'Perubahan AI dibatalkan, berkas dikembalikan dari salinan pengaman');
            flash('Perubahan DIBATALKAN. ' . (int)$res['dikembalikan'] . ' berkas dikembalikan, '
                . (int)$res['dihapus'] . ' berkas baru dihapus.');
            header('Location: ai_developer.php?id=' . $id);
            exit;
        }
        if ($aksi === 'hapus') {
            $id = (int)($_POST['id'] ?? 0);
            $t = ai_task($id);
            if (!$t) throw new RuntimeException('Tugas tidak ditemukan.');
            if ((string)$t['status'] === 'applied') throw new RuntimeException('Batalkan dulu perubahan yang sudah diterapkan.');
            ai_rmdir(ai_staging_dir($id));
            ai_rmdir(ai_tmp_dir() . '/snapshot/task-' . $id);
            q('DELETE FROM ai_tasks WHERE id = ?', [$id]);
            audit('Hapus Riwayat AI', 'AI Developer', $id, null, null, 'Riwayat permintaan AI dihapus');
            flash('Riwayat permintaan dihapus.', 'warning');
            header('Location: ai_developer.php');
            exit;
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
        $kembali = (int)($_POST['id'] ?? 0);
        header('Location: ai_developer.php' . ($kembali > 0 ? '?id=' . $kembali : ''));
        exit;
    }
}

/* ------------------------------------------------------------------ *
 * Tampilan
 * ------------------------------------------------------------------ */
/* Rapikan folder sementara AI setiap kali halaman dibuka (menyimpan 3 terbaru). */
$prune = ai_prune_temp();
$idLihat = (int)gp('id');
$t = $idLihat > 0 ? ai_task($idLihat) : null;
$daftar = ai_tasks(30);
$suite = ai_suite_list();
page_head('AI Developer', 'ai_developer');
?>
<div class="page-head">
  <div>
    <h2>AI Developer</h2>
    <p class="muted">Minta AI merevisi, memperbaiki, atau menambah fitur — dengan
      <strong>pratinjau</strong>, <strong>uji di staging</strong>, dan <strong>persetujuan Anda</strong>
      sebelum diterapkan.</p>
  </div>
  <div class="page-actions">
    <span class="muted small" style="align-self:center">Folder sementara AI:
      <?= num($prune['mb'], 2) ?> MB<?= ($prune['staging'] + $prune['snapshot'] + $prune['log']) > 0
        ? ' (baru dibersihkan: ' . num($prune['staging'] + $prune['snapshot'] + $prune['log']) . ' berkas lama)' : '' ?></span>
    <a class="btn" href="ai_settings.php"><?= icon('settings') ?> AI Settings</a>
    <a class="btn" href="developer.php#dokumenfungsi"><?= icon('download') ?> Dokumen Fungsi (PDF)</a>
  </div>
</div>

<?php if (!ai_ready()): ?>
  <div class="alert alert-warning">
    <strong>AI belum siap dipakai.</strong> <?= e(ai_ready_text()) ?>
    <a href="ai_settings.php">Buka AI Settings</a> untuk mengisi kunci API / mengaktifkannya.
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3>Permintaan Baru</h3>
    <span class="muted"><?= e(ai_ready_text()) ?></span></div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="minta">
    <div class="card-body">
      <div class="field">
        <label>Permintaan Anda</label>
        <textarea class="input" name="request" rows="5" required maxlength="4000"
          placeholder="Contoh: Pada halaman Data Pasien, tambahkan filter berdasarkan email.&#10;Contoh: Perbaiki: tombol Simpan di Pengaturan Sistem tidak menyimpan kolom WhatsApp."></textarea>
        <span class="hint">Sebutkan <strong>menu/halaman</strong> yang dimaksud dan apa yang diharapkan.
          Semakin spesifik, semakin kecil kemungkinan salah sasaran.</span>
      </div>
      <div class="form-grid g2">
        <div class="field"><label>Suite uji untuk memeriksa hasil (dijalankan di staging)</label>
          <select class="input" name="suite">
            <?php foreach ($suite as $s): ?>
              <option value="<?= e($s) ?>"<?= ai_settings()['default_suite'] === $s ? ' selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Uji ini WAJIB lulus sebelum tombol Terapkan dapat dipakai.</span></div>
        <div class="field"><label>&nbsp;</label>
          <button class="btn btn-primary" type="submit"<?= ai_ready() ? '' : ' disabled' ?>>
            <?= icon('sparkles') ?> Minta AI Menyusun Perubahan</button></div>
      </div>
      <div class="notice small">
        AI <strong>tidak langsung mengubah kode</strong>. Ia menyusun usulan; Anda dapat membaca
        pratinjau (diff) dan menjalankan uji pada salinan aplikasi lebih dulu.
      </div>
    </div>
  </form>
</div>

<?php if ($t): ?>
  <?php
  [$lbl, $tone] = ai_status_label((string)$t['status']);
  $uji = ai_test_result((string)($t['test_log'] ?? ''));
  $berkas = json_decode((string)($t['files'] ?? '[]'), true) ?: [];
  ?>
  <div class="card" id="detail">
    <div class="card-head">
      <h3>Permintaan #<?= (int)$t['id'] ?></h3>
      <span><?= badge($lbl, $tone) ?>
        <span class="muted small"><?= e((string)$t['provider']) ?> · <?= e((string)$t['model']) ?>
          · <?= e(tgl((string)$t['created_at'], true)) ?></span></span>
    </div>
    <div class="card-body">
      <div class="field"><label>Permintaan</label>
        <div class="notice"><?= nl2br(e((string)$t['request'])) ?></div></div>

      <div class="notice small mt-2" id="stageBox">
        <strong>Keadaan:</strong> <span id="stageText"><?= e((string)($t['stage'] ?? '-')) ?></span>
        <span id="stageSpin" class="muted small"></span>
      </div>

      <?php if ((string)$t['error'] !== ''): ?>
        <div class="alert alert-error" id="errorBox"><?= e((string)$t['error']) ?></div>
      <?php else: ?>
        <div class="alert alert-error hide" id="errorBox"></div>
      <?php endif; ?>

      <?php if ((string)($t['plan'] ?? '') !== ''): ?>
        <div class="section-title"><?= (string)$t['status'] === 'noop' ? 'Penjelasan AI' : 'Rencana AI' ?></div>
        <div class="notice"><?= nl2br(e((string)$t['plan'])) ?></div>
      <?php endif; ?>

      <?php if ((string)$t['status'] === 'noop'): ?>
        <div class="alert alert-info mt-2">
          <strong>AI tidak mengusulkan perubahan apa pun</strong> untuk permintaan ini — jadi tidak ada
          berkas yang perlu diubah maupun diuji. Bila memang perlu ada perubahan, tulis permintaan yang
          lebih spesifik (sebut menu/halaman dan perubahannya), atau periksa penjelasan di atas.
        </div>
        <form method="post" data-confirm="Hapus riwayat permintaan ini?">
          <?= csrf_field() ?><input type="hidden" name="action" value="hapus">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="btn" type="submit">Hapus Riwayat</button>
        </form>
      <?php endif; ?>

      <?php if ($berkas): ?>
        <div class="section-title">Berkas yang akan diubah (<?= num(count($berkas)) ?>)</div>
        <div class="flex gap-sm flex-wrap">
          <?php foreach ($berkas as $b): ?><code class="small"><?= e((string)$b) ?></code><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ((string)($t['lint'] ?? '') !== ''): ?>
        <div class="section-title">Pemeriksaan sintaks</div>
        <pre class="ai-pre"><?= e((string)$t['lint']) ?></pre>
      <?php endif; ?>

      <?php if ((string)($t['diff'] ?? '') !== ''): ?>
        <div class="section-title">Pratinjau perubahan (diff)</div>
        <pre class="ai-pre" id="diffPre"><?= e((string)$t['diff']) ?></pre>
        <div class="small muted">Baris <code>-</code> = yang dibuang, <code>+</code> = yang ditambahkan.</div>
      <?php endif; ?>

      <?php if (!in_array((string)$t['status'], ['applied', 'rolledback', 'rejected'], true) && $t['ops']): ?>
        <div class="section-title">Uji di Staging</div>
        <form method="post" class="flex gap-sm flex-wrap" style="align-items:flex-end">
          <?= csrf_field() ?><input type="hidden" name="action" value="uji">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <div class="field" style="min-width:240px"><label>Suite uji</label>
            <select class="input input-sm" name="suite">
              <?php foreach ($suite as $s): ?>
                <option value="<?= e($s) ?>"<?= (string)$t['suite'] === $s ? ' selected' : '' ?>><?= e($s) ?></option>
              <?php endforeach; ?>
            </select></div>
          <button class="btn btn-primary" type="submit">
            <?= icon('shield') ?> Jalankan Uji di Staging (<?= e((string)($t['suite'] ?: ai_settings()['default_suite'])) ?>)</button>
          <span class="muted small">Uji berjalan pada SALINAN aplikasi — data produksi tidak tersentuh.</span>
        </form>
      <?php endif; ?>

      <?php if ((string)($t['test_status'] ?? '') !== ''): ?>
        <div class="notice small mt-2">
          <strong>Hasil uji:</strong> <?= e((string)$t['test_status']) ?>
          <?php if (!empty($uji['pass']) || !empty($uji['fail'])): ?>
            · PASS <?= num((int)$uji['pass']) ?> · FAIL <?= num((int)$uji['fail']) ?>
          <?php endif; ?>
        </div>
        <?php if (!empty($uji['ringkas'])): ?>
          <pre class="ai-pre" id="testPre"><?= e((string)$uji['ringkas']) ?></pre>
        <?php endif; ?>
      <?php endif; ?>

      <?php if (!in_array((string)$t['status'], ['applied', 'rolledback', 'rejected'], true)): ?>
        <div class="section-title">Persetujuan (Super Admin)</div>
        <?php if ((string)$t['test_status'] !== 'lulus'): ?>
          <div class="alert alert-warning">Uji belum LULUS, jadi penerapan masih terkunci.
            Jalankan uji di staging lebih dulu (penerapan hanya boleh setelah uji lulus).</div>
        <?php endif; ?>
        <div class="flex gap-sm flex-wrap">
          <form method="post" class="flex gap-sm flex-wrap" style="align-items:flex-end"
                data-heavy-confirm="TERAPKAN"
                data-heavy-warning="Menerapkan perubahan dari AI akan MENULIS ULANG berkas aplikasi yang sedang dipakai. Salinan pengaman dibuat otomatis dan tombol &quot;Batalkan&quot; tersedia, tetapi sebaiknya Anda sudah membaca pratinjau &amp; hasil uji."
                data-heavy-confirm2="PERINGATAN KEDUA (terakhir): terapkan perubahan ini ke aplikasi?">
            <?= csrf_field() ?><input type="hidden" name="action" value="terapkan">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <input type="hidden" name="confirm_word" value="TERAPKAN">
            <div class="field" style="min-width:200px"><label>Kata sandi Anda</label>
              <input class="input input-sm" type="password" name="password" required></div>
            <button class="btn btn-primary" type="submit"<?= (string)$t['test_status'] === 'lulus' ? '' : ' disabled' ?>>
              <?= icon('save') ?> Setujui &amp; Terapkan</button>
          </form>
          <form method="post" data-confirm="Tolak usulan AI ini? Tidak ada perubahan yang diterapkan.">
            <?= csrf_field() ?><input type="hidden" name="action" value="tolak">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="btn btn-danger" type="submit">Tolak Usulan</button>
          </form>
          <form method="post" data-confirm="Hapus riwayat permintaan ini?">
            <?= csrf_field() ?><input type="hidden" name="action" value="hapus">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="btn" type="submit">Hapus Riwayat</button>
          </form>
        </div>
      <?php elseif ((string)$t['status'] === 'applied'): ?>
        <div class="alert alert-success">
          <strong>Perubahan sudah DITERAPKAN</strong> pada <?= e(tgl((string)$t['applied_at'], true)) ?>.
          Periksa halaman terkait; bila ada masalah, tekan <em>Batalkan</em> untuk mengembalikan berkas
          seperti semula.
        </div>
        <form method="post" class="flex gap-sm flex-wrap" style="align-items:flex-end"
              data-confirm="Kembalikan berkas ke keadaan sebelum perubahan AI ini?">
          <?= csrf_field() ?><input type="hidden" name="action" value="batalkan">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <div class="field" style="min-width:200px"><label>Kata sandi Anda</label>
            <input class="input input-sm" type="password" name="password" required></div>
          <button class="btn btn-danger" type="submit"><?= icon('refresh') ?> Batalkan (kembalikan berkas)</button>
        </form>
      <?php else: ?>
        <div class="notice small mt-2">
          <?= (string)$t['status'] === 'rolledback' ? 'Perubahan ini sudah DIBATALKAN — berkas dikembalikan seperti semula.'
              : 'Usulan ini ditolak. Tidak ada perubahan yang diterapkan.' ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
  /* ==========================================================================
   * Pemantauan pekerjaan latar belakang (panggilan AI & suite uji).
   * Halaman menyegarkan keadaan setiap 4 detik SELAMA pekerjaan berjalan, lalu
   * berhenti sendiri — supaya tidak membebani server.
   * ======================================================================== */
  (function () {
    var ID = <?= (int)$t['id'] ?>;
    var stage = document.getElementById('stageText');
    var spin = document.getElementById('stageSpin');
    var errBox = document.getElementById('errorBox');
    var testPre = document.getElementById('testPre');
    var jalan = false;
    var timer = null;

    function tanya() {
      fetch('ai_developer.php?ajax=status&id=' + ID, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.ok) return;
          if (stage) stage.textContent = d.stage || d.status_label || '';
          if (spin) spin.textContent = d.berjalan ? ' (sedang berjalan…)' : '';
          if (errBox) {
            errBox.textContent = d.error || '';
            errBox.classList.toggle('hide', !d.error);
          }
          if (testPre && d.test && d.test.ringkas) testPre.textContent = d.test.ringkas;
          var aktif = d.berjalan || (d.test && d.test.jalan);
          if (!aktif) {
            clearInterval(timer); timer = null;
            /* Muat ulang sekali supaya diff/pratinjau & tombol persetujuan muncul. */
            if (jalan) location.reload();
            return;
          }
          jalan = true;
        }).catch(function () { /* jaringan sementara: coba lagi pada putaran berikut */ });
    }
    var perlu = <?= in_array((string)$t['status'], ['analyzing', 'testing'], true) ? 'true' : 'false' ?>;
    tanya();
    if (perlu) { jalan = true; timer = setInterval(tanya, 4000); }
  })();
  </script>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3>Riwayat Permintaan</h3>
    <span class="muted"><?= num(count($daftar)) ?> terakhir</span></div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>#</th><th>Permintaan</th><th>Penyedia</th><th>Uji</th><th>Status</th><th>Waktu</th><th></th></tr></thead>
    <tbody>
    <?php if (!$daftar): ?>
      <tr><td colspan="7" class="center muted">Belum ada permintaan. Tulis permintaan pertama di atas.</td></tr>
    <?php endif; ?>
    <?php foreach ($daftar as $d): [$dlbl, $dtone] = ai_status_label((string)$d['status']); ?>
      <tr>
        <td><?= (int)$d['id'] ?></td>
        <td><?= e(short_text((string)$d['request'], 90)) ?></td>
        <td class="small"><?= e((string)$d['provider']) ?><div class="muted"><?= e((string)$d['model']) ?></div></td>
        <td class="small"><?= e((string)($d['suite'] ?: '-')) ?>
          <?php if ((string)$d['test_status'] !== ''): ?><div class="muted"><?= e((string)$d['test_status']) ?></div><?php endif; ?></td>
        <td><?= badge($dlbl, $dtone) ?></td>
        <td class="small muted"><?= e(tgl((string)$d['created_at'], true)) ?></td>
        <td><a class="btn btn-sm" href="ai_developer.php?id=<?= (int)$d['id'] ?>">Buka</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php page_foot(); ?>
