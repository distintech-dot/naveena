<?php
/**
 * ROUTER PRATINJAU VISUAL AI DEVELOPER (ronde 44).
 * ================================================
 *
 * Dipakai oleh halaman AI Developer untuk menampilkan HALAMAN APLIKASI yang sudah
 * memakai usulan perubahan — SEBELUM diterapkan. Semuanya dijalankan dari salinan
 * (folder staging) dengan basis data salinan, jadi aplikasi & data asli tidak
 * pernah tersentuh.
 *
 * Bentuk permintaan:
 *   ai_preview.php?task=5                       → kerangka pratinjau (pemilih halaman)
 *   ai_preview.php?task=5&p=dashboard.php       → halaman salinan (dipakai iframe)
 *   ai_preview.php?task=5&a=assets/css/app.css  → berkas aset dari salinan
 *
 * Hanya Super Admin (sama dengan AI Developer itu sendiri).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ai.php';
require_once __DIR__ . '/includes/ai_preview.php';
if (!is_super()) deny('Pratinjau AI Developer hanya dapat dibuka oleh Super Admin.');
$user = current_user();

$taskId = (int)gp('task');
$page = (string)gp('p');
$asset = (string)gp('a');
$t = $taskId > 0 ? ai_task($taskId) : null;
if (!$t) { http_response_code(404); exit('Tugas tidak ditemukan.'); }

/* ---------- Aset dari salinan ---------- */
if ($asset !== '') {
    $a = ai_preview_asset($taskId, $asset);
    if (!$a['ok']) { http_response_code(404); exit($a['error']); }
    header('Content-Type: ' . $a['mime'] . '; charset=utf-8');
    header('Cache-Control: no-store');
    echo $a['body'];
    exit;
}

/* ---------- Halaman salinan (di dalam iframe) ---------- */
if ($page !== '') {
    $siap = ai_preview_ensure($taskId);
    if (!$siap['ok']) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><body style="font:14px system-ui;padding:24px">'
            . '<h3>Pratinjau tidak dapat disiapkan</h3><p>' . e($siap['error']) . '</p></body>';
        exit;
    }
    $post = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
    /* Parameter milik ROUTER (task/p/cari) dibuang sebelum diteruskan ke halaman
       salinan — halaman aplikasi tidak boleh menerima parameter asing. */
    $q = $_GET;
    unset($q['task'], $q['p'], $q['cari'], $q['a']);
    $r = ai_preview_render($taskId, $page, http_build_query($q), (int)$user['id'], $post);
    if ($r['redirect'] !== '') {
        $target = $r['redirect'];
        if (preg_match('~\.php~', $target) && strpos($target, 'ai_preview.php') === false) {
            $target = 'ai_preview.php?task=' . $taskId . '&p=' . rawurlencode(ltrim($target, '/'));
        }
        header('Location: ' . $target);
        exit;
    }
    if (!$r['ok']) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><body style="font:14px system-ui;padding:24px">'
            . '<h3>Halaman pratinjau tidak dapat ditampilkan</h3><p>' . e($r['error']) . '</p></body>';
        exit;
    }
    $html = ai_preview_rewrite((string)$r['html'], $taskId, $page);
    /* Penanda teks dari patch (parameter `cari`) dipakai untuk melompat & menyorot
       bagian yang diubah di dalam pratinjau. */
    $cari = trim((string)gp('cari'));
    $html = ai_preview_banner($html, $taskId, $page, $cari);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $html;
    exit;
}

/* ---------- Kerangka pratinjau ---------- */
$siap = ai_preview_ensure($taskId);
$berkas = json_decode((string)($t['files'] ?? '[]'), true) ?: [];
$pilih = trim((string)($t['preview_page'] ?? ''));
if ($pilih === '' || !preg_match('~^[A-Za-z0-9_\-]+\.php$~', $pilih)) $pilih = ai_preview_page($berkas, (string)$t['request']);
$halaman = ai_preview_pages($taskId);
if ($halaman && !in_array($pilih, $halaman, true)) array_unshift($halaman, $pilih);
$siapOk = $siap['ok'];
page_head('Pratinjau AI', 'ai_developer');
?>
<div class="page-head">
  <div>
    <h2>Pratinjau: Permintaan #<?= (int)$t['id'] ?></h2>
    <p class="muted">Tampilan aplikasi <strong>setelah</strong> usulan AI diterapkan — dijalankan dari
      <strong>salinan</strong> (berkas &amp; basis data salinan), jadi aplikasi dan data asli tidak tersentuh
      sampai Anda menekan <em>Setujui &amp; Terapkan</em>.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="ai_developer.php?id=<?= (int)$t['id'] ?>"><?= icon('edit') ?> Kembali ke AI Developer</a>
    <a class="btn btn-primary" href="ai_preview.php?task=<?= (int)$t['id'] ?>&p=<?= e(rawurlencode($pilih)) ?>"
       target="_blank" rel="noopener"><?= icon('search') ?> Buka di tab baru</a>
  </div>
</div>

<?php if (!$siapOk): ?>
  <div class="alert alert-warning">
    <strong>Pratinjau belum bisa disiapkan:</strong> <?= e($siap['error']) ?>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <h3>Pilih halaman yang dilihat</h3>
      <span class="muted small"><?= $siap['dibuat']
        ? 'salinan pratinjau baru dibuat & usulan sudah diterapkan di dalamnya'
        : 'memakai salinan yang sudah ada' ?></span>
    </div>
    <div class="card-body">
      <form method="get" class="flex gap-sm flex-wrap" style="align-items:flex-end">
        <input type="hidden" name="task" value="<?= (int)$t['id'] ?>">
        <div class="field" style="flex:1 1 260px"><label>Halaman</label>
          <select class="input" name="p" onchange="this.form.submit()">
            <?php foreach ($halaman as $h): ?>
              <option value="<?= e($h) ?>"<?= $h === $pilih ? ' selected' : '' ?>><?= e($h) ?></option>
            <?php endforeach; ?>
          </select></div>
        <button class="btn btn-primary" type="submit">Tampilkan</button>
      </form>
      <?php if ($berkas): ?>
        <div class="notice small mt-2">Berkas yang diubah pada usulan ini:
          <?php foreach ($berkas as $b): ?><code class="small"><?= e((string)$b) ?></code> <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Pratinjau halaman <code><?= e($pilih) ?></code></h3>
      <span class="muted small">klik di dalam pratinjau dapat berpindah halaman (tetap di salinan)</span></div>
    <div class="card-body" style="padding:0">
      <iframe src="ai_preview.php?task=<?= (int)$t['id'] ?>&p=<?= e(rawurlencode($pilih)) ?>"
              style="width:100%;height:min(80vh,900px);border:1px solid var(--line);border-radius:0 0 14px 14px;background:#fff"
              title="Pratinjau perubahan"></iframe>
    </div>
  </div>
<?php endif; ?>
<?php page_foot(); ?>
