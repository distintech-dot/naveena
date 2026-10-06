<?php
/**
 * PRATINJAU VISUAL AI DEVELOPER (ronde 44).
 * ========================================
 *
 * Permintaan pemilik: "sebelum diterapkan saya tidak bisa melihat perubahannya —
 * seharusnya ada pratinjau dulu". Karena itu halaman AI Developer dapat
 * menampilkan HALAMAN APLIKASI SUNGGUHAN yang sudah memakai usulan perubahan,
 * di dalam kerangka (iframe), SEBELUM apa pun diterapkan.
 *
 * Cara kerjanya (aman):
 *   1. Salinan aplikasi (folder STAGING) dibuat/dipakai, lalu usulan perubahan
 *      dituliskan ke salinan itu — BERKAS APLIKASI ASLI TIDAK DISENTUH.
 *   2. Halaman yang diminta dijalankan oleh proses PHP terpisah (CLI), memakai
 *      SALINAN basis data di dalam folder staging, sehingga data produksi tidak
 *      mungkin berubah.
 *   3. Tautan gambar/CSS/JS di dalam halaman dialihkan ke router pratinjau supaya
 *      seluruh tampilan tetap berasal dari salinan tersebut.
 */
declare(strict_types=1);

/**
 * Folder SALINAN KHUSUS PRATINJAU.
 *
 * PENTING (perbaikan bug ronde 47): pratinjau dan uji dulu memakai folder salinan
 * YANG SAMA. Saat pemilik membuka pratinjau di tengah proses uji, pratinjau
 * menghapus & membuat ulang folder itu → suite uji yang sedang berjalan kehilangan
 * berkasnya ("No such file or directory") dan hasilnya kacau. Sekarang keduanya
 * punya folder masing-masing sehingga tidak pernah saling ganggu.
 */
function ai_preview_dir(int $taskId): string
{
    return ai_tmp_dir() . '/staging/preview-task-' . max(0, $taskId);
}

/**
 * Alamat basis data SALINAN untuk pratinjau (di dalam folder salinan pratinjau).
 *
 * JEBAKAN PENTING (ronde 47): aplikasi memakai SQLite mode **WAL**, sehingga
 * perubahan terbaru bisa masih berada di berkas `-wal` dan BELUM masuk berkas
 * utama. Menyalin `data.sqlite` saja membuat salinan kehilangan data terbaru —
 * akibatnya pratinjau bisa menampilkan setelan/data lama (mis. gambar QRIS yang
 * baru diunggah tidak muncul). Karena itu checkpoint dijalankan lebih dulu, lalu
 * berkas `-wal`/`-shm` juga ikut disalin sebagai pengaman.
 */
function ai_preview_db(int $taskId): ?string
{
    $dir = ai_preview_dir($taskId);
    $dbDir = $dir . '/naveena_data';
    if (!is_dir($dbDir)) @mkdir($dbDir, 0770, true);
    $db = $dbDir . '/data.sqlite';
    if (!is_file($db)) {
        ai_db_checkpoint();
        if (!@copy(DB_PATH, $db)) return null;
        foreach (['-wal', '-shm'] as $akhiran) {
            if (is_file(DB_PATH . $akhiran)) @copy(DB_PATH . $akhiran, $db . $akhiran);
        }
        @chmod($db, 0664);
    }
    return $db;
}

/**
 * Paksa SQLite menulis seluruh isi WAL ke berkas utama sebelum berkas disalin.
 * Aman dipanggil kapan saja (tidak mengubah data).
 */
function ai_db_checkpoint(): void
{
    try {
        db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Throwable $e) {
        /* Bukan masalah fatal: penyalinan -wal/-shm masih menjadi pengaman. */
    }
}

/**
 * Pastikan salinan aplikasi + usulan perubahan sudah siap dipratinjaukan.
 * @return array{ok:bool,dir:string,error:string,dibuat:bool}
 */
function ai_preview_ensure(int $taskId): array
{
    $task = ai_task($taskId);
    if (!$task) return ['ok' => false, 'dir' => '', 'error' => 'Tugas tidak ditemukan.', 'dibuat' => false];
    $ops = $task['ops'] ?? [];
    if (!$ops) return ['ok' => false, 'dir' => '', 'error' => 'Belum ada usulan perubahan untuk dipratinjaukan.', 'dibuat' => false];

    $dir = ai_preview_dir($taskId);
    $app = $dir . '/naveena';
    /* CAP JARI usulan: salinan dipakai ulang HANYA bila isinya memang usulan yang
       sedang dilihat. Tanpa cap ini, salinan sisa tugas lain (atau usulan lama yang
       sudah dijalankan ulang) bisa ditampilkan seolah-olah itu perubahan sekarang. */
    $cap = md5(json_encode($ops) . '|' . (string)($task['updated_at'] ?? ''));
    $capFile = $dir . '/.preview-stamp';
    $capCocok = is_file($capFile) && trim((string)@file_get_contents($capFile)) === $cap;
    if (!$capCocok && is_dir($dir)) {
        ai_rmdir($dir);
        @mkdir($dir, 0770, true);
    }
    $berkasAda = $capCocok && is_dir($app) && is_file($app . '/dashboard.php');
    /* Salinan dibuat ulang bila belum ada — mis. tugas baru disusun dan uji belum
       pernah dijalankan. Sesudah ada, salinan itu yang dipakai (lebih cepat). */
    if (!$berkasAda) {
        /* Salinan dibuat KHUSUS untuk pratinjau (folder sendiri). Tidak memakai
           `ai_staging_prepare()` karena fungsi itu menulis ke folder UJI — dua folder
           itu sengaja dipisah supaya pratinjau tidak menghapus salinan uji yang
           sedang dipakai suite uji. */
        $root = ai_root();
        ai_copy_dir(APP_DIR, $app);
        if (is_dir($root . '/naveena_dev')) ai_copy_dir($root . '/naveena_dev', $dir . '/naveena_dev');
        $berkasAda = is_dir($app) && is_file($app . '/dashboard.php');
        if (!$berkasAda) {
            return ['ok' => false, 'dir' => $dir, 'error' => 'Gagal menyiapkan salinan aplikasi untuk pratinjau.', 'dibuat' => false];
        }
        $terap = ai_apply_patch_to_content($ops);
        if (!$terap['ok']) {
            return ['ok' => false, 'dir' => $dir, 'error' => 'Usulan tidak dapat diterapkan ke salinan: ' . $terap['error'], 'dibuat' => false];
        }
        /* Usulan ditulis LANGSUNG ke folder salinan pratinjau. JANGAN memakai
           `ai_staging_apply()` — fungsi itu menulis ke folder UJI (ai_staging_dir),
           bukan folder pratinjau, sehingga berkas perubahan tidak masuk ke salinan
           yang dipratinjaukan (pratinjau jadi menampilkan versi lama). */
        foreach ($terap['files'] as $rel => $isiBaru) {
            $abs = $dir . '/' . str_replace('\\', '/', (string)$rel);
            $sub = dirname($abs);
            if (!is_dir($sub)) @mkdir($sub, 0770, true);
            if (@file_put_contents($abs, (string)$isiBaru) === false) {
                return ['ok' => false, 'dir' => $dir,
                    'error' => 'Gagal menulis berkas usulan ke salinan pratinjau: ' . $rel, 'dibuat' => false];
            }
        }
        ai_preview_uploads_copy($dir);
        ai_preview_db($taskId);
        @file_put_contents($capFile, $cap);
        return ['ok' => true, 'dir' => $dir, 'error' => '', 'dibuat' => true];
    }
    if (!is_dir($dir . '/naveena_uploads')) ai_preview_uploads_copy($dir);
    ai_preview_db($taskId);
    @file_put_contents($capFile, $cap);
    return ['ok' => true, 'dir' => $dir, 'error' => '', 'dibuat' => false];
}

/**
 * Salin HANYA berkas branding (logo & latar kartu member) ke folder unggahan
 * salinan. Foto pasien/dokter TIDAK disalin: pratinjau cukup untuk menilai
 * tampilan/kode, dan tidak boleh menyentuh atau menyiarkan berkas pribadi.
 */
function ai_preview_uploads_copy(string $dir): void
{
    $tujuan = $dir . '/naveena_uploads';
    if (!is_dir($tujuan)) @mkdir($tujuan, 0770, true);
    $sumber = local_upload_dir();
    if (!is_dir($sumber)) return;
    /* Berkas BRANDING ikut disalin supaya tampilan pratinjau tidak "rusak":
       logo klinik, latar kartu member, dan GAMBAR QRIS (dipakai di langkah
       pembayaran Order Baru). Sebelumnya QRIS tidak disalin sehingga di pratinjau
       gambarnya hilang dan muncul sebagai "path rusak" — persis keluhan pemilik. */
    foreach (scandir($sumber) ?: [] as $f) {
        if (!preg_match('~^(logo|membercard|qris)~i', $f)) continue;
        if (!is_file($sumber . '/' . $f)) continue;
        @copy($sumber . '/' . $f, $tujuan . '/' . $f);
    }
}

/** Daftar halaman yang dapat dipratinjaukan (isi folder aplikasi salinan). */
function ai_preview_pages(int $taskId): array
{
    $app = ai_preview_dir($taskId) . '/naveena';
    $out = [];
    if (!is_dir($app)) return $out;
    foreach (scandir($app) ?: [] as $f) {
        if (substr($f, -4) !== '.php') continue;
        if (strpos($f, '_') === 0) continue;                       // berkas internal
        /* Halaman internal/berkas bantu tidak perlu dipilih pemilik. */
        if (in_array($f, ['api.php', 'export.php', 'media.php', 'photo.php', 'logo.php', 'qris.php',
                          'ai_worker.php', 'ai_preview.php', 'ai_preview_run.php', 'index.php',
                          'logout.php', 'login.php', 'maintenance.php', 'two_factor.php',
                          'lupa_password.php', 'reset_password.php', 'pay_webhook.php'], true)) continue;
        $out[] = $f;
    }
    sort($out);
    return $out;
}

/**
 * Jalankan satu halaman dari SALINAN lewat proses PHP terpisah.
 *
 * @return array{ok:bool,html:string,error:string,redirect:string}
 */
function ai_preview_render(int $taskId, string $page, string $query = '', int $userId = 1, array $post = []): array
{
    $gagal = ['ok' => false, 'html' => '', 'error' => '', 'redirect' => ''];
    $page = (string)$page;
    if (!preg_match('~^[A-Za-z0-9_\-]+\.php$~', $page)) {
        return array_merge($gagal, ['error' => 'Nama halaman tidak sah.']);
    }
    $dir = ai_preview_dir($taskId);
    $app = $dir . '/naveena';
    if (!is_file($app . '/' . $page)) {
        return array_merge($gagal, ['error' => 'Halaman ' . $page . ' tidak ada di salinan pratinjau.']);
    }
    $db = ai_preview_db($taskId);
    if ($db === null) return array_merge($gagal, ['error' => 'Tidak dapat menyiapkan basis data salinan.']);

    $runner = __DIR__ . '/../ai_preview_run.php';
    if (!is_file($runner)) return array_merge($gagal, ['error' => 'Penjalan pratinjau tidak ditemukan.']);

    /* Variabel $_POST dikirim lewat berkas sementara (bukan argumen perintah). */
    $kirim = '';
    if ($post) {
        $kirim = ai_tmp_dir() . '/tmp/post-' . $taskId . '-' . bin2hex(random_bytes(4)) . '.json';
        @file_put_contents($kirim, json_encode($post, JSON_UNESCAPED_UNICODE));
    }
    $log = ai_tmp_dir() . '/log/preview-' . $taskId . '.log';
    $cmd = escapeshellarg(ai_php_cli()) . ' -d extension=pdo -d extension=pdo_sqlite -d display_errors=0 '
        . escapeshellarg($runner)
        . ' ' . escapeshellarg($app)
        . ' ' . escapeshellarg($page)
        . ' ' . escapeshellarg((string)$userId)
        . ' ' . escapeshellarg((string)$taskId)
        . ' ' . escapeshellarg((string)$query)
        . ' ' . escapeshellarg($kirim !== '' ? $kirim : '-')
        /* Unggahan dibaca dari folder PRODUKSI (hanya dibaca) supaya logo/foto tetap
           tampil; data tetap dari salinan basis data. */
        . ' 2>' . escapeshellarg($log);
    $out = @shell_exec($cmd);
    if ($kirim !== '') @unlink($kirim);
    $html = (string)$out;

    /* Penjalan menandai pengalihan lewat komentar khusus (CLI tidak mengirim header). */
    if (preg_match('~<!--AIPREVIEW-REDIRECT:([^>]*)-->~', $html, $m)) {
        $target = trim($m[1]);
        return ['ok' => true, 'html' => '', 'error' => '', 'redirect' => $target];
    }
    $html = preg_replace('~<!--AIPREVIEW-REDIRECT:[^>]*-->~', '', $html) ?? $html;
    if (trim($html) === '') {
        $logIsi = is_file($log) ? trim((string)file_get_contents($log)) : '';
        return array_merge($gagal, ['error' => 'Halaman tidak menghasilkan tampilan.'
            . ($logIsi !== '' ? ' Catatan server: ' . short_text($logIsi, 300) : '')]);
    }
    return ['ok' => true, 'html' => $html, 'error' => '', 'redirect' => ''];
}

/** Alihkan tautan/aset di dalam HTML pratinjau supaya tetap berada di salinan. */
function ai_preview_rewrite(string $html, int $taskId, string $page): string
{
    $base = 'ai_preview.php?task=' . $taskId;
    $lewati = '~^(?:[a-z][a-z0-9+.\-]*:|//|#)~i';
    $html = preg_replace_callback(
        '~(\s(?:href|src|action)\s*=\s*")([^"]*)(")~i',
        function ($m) use ($base, $lewati) {
            $u = trim($m[2]);
            if ($u === '' || preg_match($lewati, $u)) return $m[0];
            if (strpos($u, 'ai_preview.php') !== false) return $m[0];
            $u2 = preg_replace('~^\./~', '', $u);
            return $m[1] . ($u2 === '' ? $u : $base . (strpos($u2, '.php') !== false
                ? '&p=' . rawurlencode($u2) : '&a=' . rawurlencode($u2))) . $m[3];
        }, $html);
    return $html === null ? '' : $html;
}

/** Sisipkan spanduk "PRATINJAU" + penangkap klik/form ke dalam halaman salinan. */
function ai_preview_banner(string $html, int $taskId, string $page, string $cari = ''): string
{
    $spanduk = '<div id="aiPreviewBar" style="position:fixed;top:0;left:0;right:0;z-index:99999;'
        . 'background:#4F46E5;color:#fff;font:600 12.5px/1.5 system-ui,sans-serif;padding:6px 12px;'
        . 'display:flex;gap:12px;align-items:center;flex-wrap:wrap">'
        . '<span>PRATINJAU — perubahan BELUM diterapkan ke aplikasi. Data berasal dari salinan (aman).</span>'
        . '<span style="opacity:.85;font-weight:500">' . e($page) . '</span>'
        . ($cari !== '' ? '<button type="button" id="aiPreviewJump" '
            . 'style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.45);color:#fff;'
            . 'border-radius:8px;padding:3px 10px;font:600 12px system-ui;cursor:pointer">'
            . '⬇ Lompat ke bagian yang diubah</button>' : '')
        . '<a href="ai_preview.php?task=' . $taskId . '&close=1" target="_top" '
        . 'style="margin-left:auto;color:#fff;text-decoration:underline">tutup pratinjau</a></div>'
        . '<div style="height:30px"></div>';
    $pelompat = '';
    if ($cari !== '') {
        /* PEMANDU KE BAGIAN YANG DIUBAH (ronde 48).
           Permintaan pemilik: "tampilan pratinjau tidak langsung mengarah ke bagian
           halaman revisi yang saya minta". Skrip ini mencari elemen yang memuat
           potongan TEKS dari patch (yang dikirim sebagai parameter `cari`), lalu
           menggulir ke elemen itu dan memberinya sorotan. Pencarian dilakukan dari
           elemen TERKECIL yang memuat teks agar yang disorot tepat bagiannya. */
        $cariJs = json_encode($cari, JSON_UNESCAPED_UNICODE);
        $pelompat = '<script>
(function () {
  var CARI = ' . $cariJs . ';
  function norm(t) { return (t || "").replace(/\s+/g, " ").trim(); }
  function cariElemen() {
    var target = norm(CARI);
    if (target.length < 12) return null;
    var pendek = target.slice(0, 60);
    var semua = document.body.querySelectorAll("*");
    var kandidat = null;
    var panjangKandidat = Infinity;
    /* Pilih elemen TERKECIL yang memuat teks itu (paling tepat bagiannya).
       Tanpa perbandingan panjang ini, wadah besar seperti `.shell` (yang memuat
       seluruh halaman) ikut cocok dan seluruh halaman jadi tersorot. */
    for (var i = 0; i < semua.length; i++) {
      var el = semua[i];
      var t = norm(el.textContent);
      if (t.indexOf(pendek) === -1) continue;
      if (t.length < panjangKandidat) { kandidat = el; panjangKandidat = t.length; }
    }
    return kandidat;
  }
  /* Apakah elemen benar-benar terlihat pengguna? (mis. isi modal yang belum dibuka
     ada di DOM tetapi tersembunyi). */
  function terlihat(el) {
    return !!(el && el.offsetParent !== null && el.getBoundingClientRect().height > 0);
  }
  function catatan(teks, jenis) {
    var b = document.getElementById("aiPreviewJump");
    if (!b) return;
    b.textContent = teks;
    b.style.background = jenis === "warn" ? "rgba(255,214,102,.28)" : "rgba(255,255,255,.2)";
  }
  function tandai(el) {
    if (!el) return false;
    if (!terlihat(el)) {
      catatan("⚠ Bagian yang diubah ada di dalam bagian yang TERTUTUP (mis. jendela/modal) — buka bagian itu di halaman untuk melihat perubahannya", "warn");
      var induk = el;
      for (var k = 0; k < 8 && induk; k++) {
        if (terlihat(induk)) break;
        /* Cari wadah terdekat yang terlihat lalu gulir ke sana supaya pengguna tahu
           letaknya. */
        induk = induk.parentElement;
      }
      if (terlihat(induk)) { induk.scrollIntoView({ block: "center" }); return true; }
      return false;
    }
    el.style.transition = "box-shadow .3s, background .3s";
    el.style.boxShadow = "0 0 0 3px #8B5CF6";
    el.style.background = "#F5F3FF";
    el.scrollIntoView({ block: "center", inline: "nearest" });
    var info = document.createElement("div");
    info.textContent = "▲ Bagian yang diubah AI";
    info.style.cssText = "position:absolute;z-index:99999;background:#4F46E5;color:#fff;font:600 11px system-ui;"
      + "padding:3px 8px;border-radius:6px;transform:translateY(-120%)";
    try { el.style.position = el.style.position || "relative"; el.appendChild(info); } catch (e) {}
    setTimeout(function () { el.style.boxShadow = ""; if (info.parentNode) info.parentNode.removeChild(info); }, 6000);
    return true;
  }
  function lompat() {
    if (!tandai(cariElemen())) {
      /* Bila teksnya tidak ditemukan (bagian berada di dalam modal/konten dinamis),
         gulir ke bawah halaman saja supaya pengguna tetap melihat isinya. */
      window.scrollTo({ top: Math.min(document.body.scrollHeight * 0.35, 1200), behavior: "smooth" });
    }
  }
  setTimeout(lompat, 400);
  var b = document.getElementById("aiPreviewJump");
  if (b) b.addEventListener("click", lompat);
})();
</script>';
    }
    $script = $pelompat . '<script>(function(){var B="ai_preview.php?task=' . $taskId . '&";'
        . 'document.addEventListener("click",function(e){var a=e.target.closest?e.target.closest("a[href]"):null;'
        . 'if(!a)return;var h=a.getAttribute("href")||"";if(!/\.php/.test(h)||/[a-z]+:/i.test(h)||h.indexOf("ai_preview")>=0)return;'
        . 'e.preventDefault();location.href=B+"p="+encodeURIComponent(h);},true);'
        . 'document.addEventListener("submit",function(e){var f=e.target;if(!f||!f.getAttribute)return;'
        . 'var a=f.getAttribute("action")||"";if(!/\.php/.test(a)||a.indexOf("ai_preview")>=0)return;'
        . 'f.setAttribute("action",B+"p="+encodeURIComponent(a));},true);})();</script>';
    $html = preg_replace('~(<body[^>]*>)~i', '$1' . $spanduk, $html, 1) ?? $html;
    if (stripos($html, '</body>') !== false) {
        $html = preg_replace('~</body>~i', $script . '</body>', $html, 1) ?? $html;
    } else {
        $html .= $script;
    }
    return $html;
}

/** Sajikan aset (CSS/JS/gambar/font) dari salinan pratinjau. */
function ai_preview_asset(int $taskId, string $rel): array
{
    $gagal = ['ok' => false, 'body' => '', 'mime' => 'application/octet-stream', 'error' => ''];
    $rel = trim(str_replace('\\', '/', $rel));
    $rel = preg_replace('~\.\.+~', '', $rel) ?? '';
    $rel = ltrim((string)$rel, '/');
    if ($rel === '') return array_merge($gagal, ['error' => 'Nama aset kosong.']);
    $path = ai_preview_dir($taskId) . '/naveena/' . $rel;
    if (!is_file($path)) return array_merge($gagal, ['error' => 'Aset tidak ada di salinan: ' . $rel]);
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $mime = [
        'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
    ][$ext] ?? 'application/octet-stream';
    $body = (string)@file_get_contents($path);
    /* CSS di dalam salinan juga memuat url(...) — alihkan supaya gambarnya ikut
       berasal dari salinan (tampilan tetap konsisten). */
    if ($ext === 'css') {
        $body = preg_replace_callback('~url\((["\']?)([^"\')]+)\1\)~i', function ($m) use ($taskId) {
            $u = trim($m[2]);
            if (preg_match('~^(?:[a-z][a-z0-9+.\-]*:|//|data:|#)~i', $u)) return $m[0];
            return 'url(ai_preview.php?task=' . $taskId . '&a=' . rawurlencode(preg_replace('~^\./~', '', $u)) . ')';
        }, $body) ?? $body;
    }
    return ['ok' => true, 'body' => $body, 'mime' => $mime, 'error' => ''];
}
