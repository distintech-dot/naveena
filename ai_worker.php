<?php
/**
 * PEKERJA AI (dijalankan dari baris perintah, di latar belakang).
 * =============================================================
 *
 * Halaman AI Developer memanggil berkas ini lewat `exec()` supaya pekerjaan berat
 * tidak menggantung permintaan web (panggilan AI bisa memakan puluhan detik, dan
 * menjalankan suite uji bisa beberapa menit):
 *
 *   php ai_worker.php plan <id_tugas>   → AI menganalisis + menyusun patch + pratinjau
 *   php ai_worker.php test <id_tugas>   → terapkan ke STAGING lalu jalankan suite uji
 *
 * Berkas ini SENGAJA hanya bisa dijalankan dari CLI (bukan dari peramban):
 * pemanggilan lewat web ditolak, sehingga tidak ada pekerjaan latar belakang yang
 * bisa dipicu orang luar tanpa login.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Berkas ini hanya dapat dijalankan dari baris perintah.';
    exit(1);
}

require_once __DIR__ . '/includes/config.php';

$argvv = $argv ?? [];
$mode = (string)($argvv[1] ?? '');
$id = (int)($argvv[2] ?? 0);
if ($id <= 0 || !in_array($mode, ['plan', 'test'], true)) {
    fwrite(STDERR, "Pemakaian: php ai_worker.php plan|test <id_tugas>\n");
    exit(1);
}
$task = ai_task($id);
if (!$task) { fwrite(STDERR, "Tugas #$id tidak ditemukan.\n"); exit(1); }

/** Tulis keterangan langkah berjalan (dibaca halaman untuk progres). */
$setStage = function (string $s) use ($id): void {
    ai_task_update($id, ['stage' => $s, 'error' => '']);
};

if ($mode === 'plan') {
    /* ---------- 1. Pilih berkas relevan ---------- */
    ai_task_update($id, ['status' => 'analyzing', 'error' => '']);
    $setStage('Membaca daftar berkas');
    $daftar = ai_scope_files();
    if (!$daftar) {
        ai_task_update($id, ['status' => 'failed', 'error' => 'Tidak ada berkas pada cakupan AI Settings.', 'stage' => '']);
        exit(1);
    }

    $setStage('AI memilih berkas yang relevan');
    $pick = ai_call_resilient([['role' => 'user', 'text' => ai_pick_prompt((string)$task['request'], $daftar)]], 0.0, 1024);
    $pilih = [];
    if ($pick['ok']) {
        $j = ai_extract_json($pick['text']);
        foreach ((array)($j['files'] ?? []) as $f) {
            $f = trim((string)$f);
            if ($f !== '' && ai_path($f) !== null) $pilih[] = $f;
        }
    }
    /* Cadangan: bila AI tidak memberi daftar berkas yang sah, cari kata kunci
       penting dari permintaan (nama berkas/menu) supaya pekerjaan tetap jalan. */
    if (!$pilih) {
        $setStage('Mencari berkas lewat kata kunci (AI tidak memberi daftar berkas)');
        $kata = [];
        if (preg_match_all('/[A-Za-z_]{4,}/', (string)$task['request'], $m)) {
            foreach (array_slice(array_unique($m[0]), 0, 6) as $w) $kata[] = $w;
        }
        foreach ($kata as $w) {
            foreach (ai_search($w, 6) as $hit) {
                if (!in_array($hit['rel'], $pilih, true)) $pilih[] = $hit['rel'];
            }
            if (count($pilih) >= ai_settings()['max_files']) break;
        }
        /* Berkas inti selalu ikut bila belum terpilih supaya AI punya konteks. */
        foreach (['naveena/includes/config.php', 'naveena/includes/layout.php'] as $inti) {
            if (count($pilih) < ai_settings()['max_files'] && ai_path($inti) !== null && !in_array($inti, $pilih, true)) {
                $pilih[] = $inti;
            }
        }
    }
    $pilih = array_slice(array_values(array_unique($pilih)), 0, ai_settings()['max_files']);
    if (!$pilih) {
        ai_task_update($id, ['status' => 'failed', 'stage' => '',
            'error' => 'Tidak dapat menentukan berkas yang relevan. Tulis permintaan lebih spesifik (sebutkan menu atau nama berkas).']);
        exit(1);
    }

    /* ---------- 2. Baca isi berkas ---------- */
    $setStage('Membaca isi ' . count($pilih) . ' berkas');
    $isi = [];
    $lewat = [];
    foreach ($pilih as $rel) {
        $b = ai_read($rel);
        if (!$b['ok']) { $lewat[] = $rel . ' (' . $b['error'] . ')'; continue; }
        if (!empty($b['truncated'])) { $lewat[] = $rel . ' (terlalu besar, dilewati)'; continue; }
        $isi[$rel] = $b['text'];
    }
    if (!$isi) {
        ai_task_update($id, ['status' => 'failed', 'stage' => '',
            'error' => 'Berkas yang relevan tidak dapat dibaca: ' . implode(', ', $lewat)]);
        exit(1);
    }

    /* ---------- 3. Minta patch ---------- */
    $setStage('AI menyusun usulan perubahan');
    $jawab = ai_call_resilient([
        ['role' => 'user', 'text' => ai_system_prompt()],
        ['role' => 'model', 'text' => 'Baik, saya akan menjawab hanya dengan JSON sesuai aturan.'],
        ['role' => 'user', 'text' => ai_patch_prompt((string)$task['request'], $isi)],
    ], 0.15, 8192);
    if (!$jawab['ok']) {
        ai_task_update($id, ['status' => 'failed', 'stage' => '', 'error' => $jawab['error']]);
        exit(1);
    }
    $parse = ai_patch_parse($jawab['text']);
    if (!$parse['ok']) {
        ai_task_update($id, ['status' => 'failed', 'stage' => '',
            'error' => 'Usulan AI tidak dapat dibaca: ' . $parse['error']]);
        exit(1);
    }

    /* ---------- 3b. AI menyimpulkan TIDAK ADA yang perlu diubah ---------- */
    if (!empty($parse['noop'])) {
        $penjelasan = trim((string)$parse['plan'] . "\n\n" . (string)($parse['note'] ?? ''));
        ai_task_update($id, [
            'status' => 'noop', 'stage' => 'AI menyimpulkan tidak ada perubahan yang diperlukan',
            'plan' => $penjelasan !== '' ? $penjelasan : 'AI tidak memberi penjelasan.',
            'patch' => '', 'diff' => '', 'lint' => '', 'files' => '[]', 'error' => '',
        ]);
        audit('AI Tidak Mengusulkan Perubahan', 'AI Developer', $id, null, null,
            'AI menyimpulkan tidak ada perubahan yang perlu dilakukan untuk permintaan ini');
        exit(0);
    }

    /* ---------- 4. Pratinjau: patch + diff + pemeriksaan sintaks ---------- */
    $setStage('Menyiapkan pratinjau & memeriksa sintaks');
    $terap = ai_apply_patch_to_content($parse['ops']);
    if (!$terap['ok']) {
        ai_task_update($id, ['status' => 'failed', 'stage' => '',
            'error' => 'Usulan AI tidak dapat diterapkan: ' . $terap['error']]);
        exit(1);
    }
    $diff = [];
    foreach ($terap['files'] as $rel => $baru) {
        $lama = in_array($rel, $terap['created'], true) ? '' : (string)(ai_read($rel)['text'] ?? '');
        $diff[] = ai_diff_text($rel, $lama, $baru);
    }
    $lint = ai_lint_contents($terap['files']);
    $petaLint = [];
    foreach ($lint['hasil'] as $h) $petaLint[] = ($h['ok'] ? 'OK  ' : 'GAGAL  ') . $h['file'] . ' — ' . $h['msg'];

    ai_task_update($id, [
        'status' => 'proposed', 'stage' => 'Selesai — menunggu pratinjau & persetujuan',
        'plan' => $parse['plan'], 'patch' => json_encode($parse['ops'], JSON_UNESCAPED_UNICODE),
        'diff' => implode("\n\n", $diff),
        'lint' => implode("\n", $petaLint),
        'files' => json_encode(array_keys($terap['files']), JSON_UNESCAPED_UNICODE),
        'error' => '',
    ]);
    audit('AI Usulkan Perubahan', 'AI Developer', $id, null,
        ['berkas' => array_keys($terap['files']), 'sintaks_ok' => $lint['ok']],
        'AI menyusun usulan perubahan untuk: ' . short_text((string)$task['request'], 120));
    exit(0);
}

if ($mode === 'test') {
    /* ---------- Uji di STAGING (bukan di aplikasi terbit) ---------- */
    $patch = json_decode((string)$task['patch'], true) ?: [];
    if (!$patch) {
        ai_task_update($id, ['status' => 'failed', 'error' => 'Belum ada usulan perubahan untuk diuji.', 'stage' => '']);
        exit(1);
    }
    ai_task_update($id, ['status' => 'testing', 'test_status' => 'menyiapkan staging', 'stage' => 'Menyiapkan folder staging']);
    $staging = ai_staging_prepare($id);
    ai_task_update($id, ['staging_dir' => $staging['dir']]);

    ai_task_update($id, ['stage' => 'Menerapkan usulan ke staging']);
    $terap = ai_apply_patch_to_content($patch);
    if (!$terap['ok']) {
        ai_task_update($id, ['status' => 'failed', 'test_status' => 'gagal', 'stage' => '',
            'error' => 'Gagal menerapkan ke staging: ' . $terap['error']]);
        exit(1);
    }
    $tulis = ai_staging_apply($id, $terap['files']);
    if (!$tulis['ok']) {
        ai_task_update($id, ['status' => 'failed', 'test_status' => 'gagal', 'stage' => '', 'error' => $tulis['error']]);
        exit(1);
    }

    /* Periksa sintaks juga di staging (berkas sudah benar-benar ada di sana). */
    $lint = ai_lint_contents($terap['files']);
    $petaLint = [];
    foreach ($lint['hasil'] as $h) $petaLint[] = ($h['ok'] ? 'OK  ' : 'GAGAL  ') . $h['file'] . ' — ' . $h['msg'];

    $suite = trim((string)($task['suite'] ?? ''));
    if ($suite === '') $suite = ai_settings()['default_suite'];

    ai_task_update($id, ['stage' => 'Menjalankan suite uji "' . $suite . '" di staging', 'test_status' => 'berjalan']);
    $err = '';
    $log = ai_run_suite($id, $suite, $err);
    if ($log === null) {
        ai_task_update($id, ['status' => 'failed', 'test_status' => 'gagal', 'stage' => '', 'error' => $err]);
        exit(1);
    }
    ai_task_update($id, ['test_log' => $log]);

    /* Tunggu sampai suite selesai (worker ini memang berjalan di latar belakang). */
    $mulai = time();
    $hasil = ['selesai' => false, 'pass' => 0, 'fail' => 0, 'ringkas' => ''];
    while ((time() - $mulai) < 1800) {
        $hasil = ai_test_result($log);
        if (!empty($hasil['selesai'])) break;
        sleep(3);
    }
    $ringkas = trim(implode("\n", $petaLint)) . "\n\n" . (string)($hasil['ringkas'] ?? '');
    $lulus = !empty($hasil['selesai']) && (int)($hasil['fail'] ?? 0) === 0 && $lint['ok'];
    ai_task_update($id, [
        'status' => $lulus ? 'tested' : 'failed',
        'test_status' => $lulus ? 'lulus' : 'perlu diperiksa',
        'lint' => implode("\n", $petaLint),
        'stage' => $lulus ? 'Uji selesai: LULUS — menunggu persetujuan' : 'Uji selesai: ADA KEGAGALAN',
        'error' => $lulus ? '' : ('Uji/sintaks belum lulus. ' . (string)($hasil['fail'] ?? 0) . ' pemeriksaan gagal.'),
    ]);
    /* Bersihkan folder sementara agar staging tidak menumpuk di disk. */
    ai_prune_temp();
    audit('AI Uji di Staging', 'AI Developer', $id, null,
        ['suite' => $suite, 'pass' => (int)($hasil['pass'] ?? 0), 'fail' => (int)($hasil['fail'] ?? 0), 'lulus' => $lulus],
        'Hasil uji staging: ' . ($lulus ? 'LULUS' : 'ADA KEGAGALAN'));
    exit(0);
}

exit(0);
