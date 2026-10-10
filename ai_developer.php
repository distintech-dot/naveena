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
if (!is_super()) deny('AI Developer hanya dapat dibuka oleh Super Admin.',
    deny_role_detail('Super Admin')
    . ' Halaman ini mengubah kode aplikasi & menyimpan kunci API, jadi hanya Super Admin yang boleh membukanya.');
/* $user dipakai di banyak tempat (penyimpanan permintaan, verifikasi kata sandi saat
   penerapan, dsb). JANGAN hilangkan baris ini — tanpa $user, verifikasi kata sandi
   saat menerapkan SELALU gagal ("Kata sandi tidak sesuai") sehingga perubahan tidak
   pernah bisa diterapkan. */
$user = current_user();

/**
 * Ambil berkas-berkas dari satu kolom unggahan (<input type="file" name="files[]" multiple>).
 *
 * Menerima FORMAT APA PUN (Excel, PDF, gambar, dokumen) sesuai permintaan pemilik —
 * isinya dibaca sebisa mungkin oleh `ai_attach_extract()` dan disertakan pada
 * permintaan ke AI. Berkasnya disimpan DI LUAR folder aplikasi yang disajikan publik.
 */
function ai_uploaded_files(string $field): array
{
    if (empty($_FILES[$field])) return [];
    /* RONDE 50: terima juga unggahan SATU berkas (tanpa tanda [] pada nama kolom).
       Sebelumnya bentuk ini DIAM-DIAM diabaikan (lampiran tidak tersimpan walau
       permintaannya berhasil) — ditemukan saat menguji lewat API, dan bisa terjadi
       juga pada klien/peramban yang mengirim satu berkas saja. */
    if (!is_array($_FILES[$field]['name'] ?? null)) {
        $f = $_FILES[$field];
        if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [];
        return [[
            'name' => (string)($f['name'] ?? ''),
            'type' => (string)($f['type'] ?? ''),
            'tmp_name' => (string)($f['tmp_name'] ?? ''),
            'error' => (int)($f['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($f['size'] ?? 0),
        ]];
    }
    $out = [];
    $n = count($_FILES[$field]['name']);
    for ($i = 0; $i < $n; $i++) {
        $err = (int)($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $out[] = [
            'name' => (string)($_FILES[$field]['name'][$i] ?? ''),
            'type' => (string)($_FILES[$field]['type'][$i] ?? ''),
            'tmp_name' => (string)($_FILES[$field]['tmp_name'][$i] ?? ''),
            'error' => $err,
            'size' => (int)($_FILES[$field]['size'][$i] ?? 0),
        ];
        if (count($out) >= 8) break;   // batas praktis per permintaan
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 * Titik JSON untuk memantau pekerjaan berjalan
 * ------------------------------------------------------------------ */
/* Keluaran uji LENGKAP (dibuka di tab baru) — supaya alasan kegagalan dapat ditelusuri
   sampai ke baris pemeriksaan yang gagal. */
if (gp('log') === '1') {
    $t = ai_task((int)gp('id'));
    if (!$t || (string)$t['test_log'] === '') {
        http_response_code(404);
        exit('Belum ada uji yang dijalankan untuk permintaan ini.');
    }
    if (!is_file((string)$t['test_log'])) {
        http_response_code(404);
        exit("Keluaran uji lengkap sudah dibersihkan oleh pembersih folder sementara.\n\n"
            . 'Ringkasan yang tersimpan pada permintaan ini:\n\n'
            . (string)($t['test_ringkas'] ?? '(tidak ada)'));
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo (string)file_get_contents((string)$t['test_log']);
    exit;
}

/* ------------------------------------------------------------------ *
 * Titik JSON untuk memantau pekerjaan berjalan
 * ------------------------------------------------------------------ */
if (gp('ajax') === 'job') {
    /* ==========================================================================
     * TITIK AJAX JOB (ronde 53) — RINGAN & TANPA MEMUAT ULANG HALAMAN.
     * Mengembalikan status job, progres nyata, checklist langkah, heartbeat,
     * retry/cancel, dan ringkasan aktivitas. Sekaligus memulihkan worker yang
     * berhenti merespons (stalled) bila masih aman — jadi refresh browser TIDAK
     * menghilangkan pekerjaan.
     * ======================================================================== */
    header('Content-Type: application/json');
    $id = (int)gp('id');
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'id kosong']); exit; }
    $pulih = ai_job_recover_stalled($id);
    $v = ai_job_view($id);
    $t = ai_task($id);
    $view = $t ? ai_task_status_view($t) : null;
    echo json_encode([
        'ok' => true, 'job' => $v,
        'task_status' => $t ? (string)$t['status'] : '',
        'workflow' => $view ? $view['workflow'] : '',
        'audit_status' => $t ? (string)($t['audit_status'] ?? '') : '',
        'pulih' => $pulih,
        'berjalan' => (bool)$v['berjalan'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (gp('ajax') === 'status') {    header('Content-Type: application/json');
    $id = (int)gp('id');
    $t = $id > 0 ? ai_task($id) : null;
    if (!$t) { echo json_encode(['ok' => false, 'error' => 'Tugas tidak ditemukan.']); exit; }
    /* RONDE 52: label & workflow dari SATU SUMBER (ai_task_status_view) supaya UI tidak
       pernah menampilkan NO_CHANGE ketika audit sebenarnya belum lengkap. */
    $view = ai_task_status_view($t);
    $lbl = $view['label'];
    $tone = $view['tone'];
    $uji = ['jalan' => false, 'selesai' => false, 'pass' => 0, 'fail' => 0, 'ringkas' => ''];
    if (in_array((string)$t['status'], ['testing'], true) || (string)$t['test_status'] !== '') {
        /* Angka PASS/FAIL diambil dari log, atau dari ringkasan tersimpan bila berkas
           log sudah dibersihkan (supaya tidak pernah berbunyi "LULUS (0 pemeriksaan)"). */
        $uji = ai_test_counts($t);
    }
    $langkah = [];
    foreach (ai_steps((int)$t['id']) as $st) {
        $langkah[] = ['id' => (int)$st['id'], 'kind' => (string)$st['kind'],
                      'text' => (string)$st['text'], 'detail' => (string)($st['detail'] ?? ''),
                      'jam' => substr((string)$st['created_at'], 11, 5),
                      'waktu' => (string)$st['created_at']];
    }
    $pesanChat = [];
    foreach (ai_messages((int)$t['id']) as $m) {
        $pesanChat[] = ['id' => (int)$m['id'], 'role' => (string)$m['role'],
                        'text' => (string)$m['text'], 'jam' => substr((string)$m['created_at'], 11, 5),
                        'waktu' => (string)$m['created_at']];
    }
    echo json_encode([
        'ok' => true, 'id' => (int)$t['id'], 'status' => (string)$t['status'],
        'status_label' => $lbl, 'workflow_label' => $view['workflow'],
        'workflow_konsisten' => (bool)$view['konsisten'],
        'audit_status' => (string)($t['audit_status'] ?? ''),
        'audit_alasan' => (string)($t['files_skipped'] ?? ''),
        'stage' => (string)($t['stage'] ?? ''),
        'error' => (string)($t['error'] ?? ''), 'test_status' => (string)($t['test_status'] ?? ''),
        'test' => ['jalan' => (bool)$uji['jalan'], 'selesai' => (bool)($uji['selesai'] ?? false),
                   'pass' => (int)$uji['pass'], 'fail' => (int)$uji['fail'],
                   'ringkas' => (string)($uji['ringkas'] ?? '')],
        'steps' => $langkah,
        'pesan' => $pesanChat,
        'punya_ops' => (bool)$t['ops'],
        'preview_page' => (string)($t['preview_page'] ?? ''),
        'berjalan' => in_array((string)$t['status'], ['analyzing', 'testing'], true),
        /* Uji otomatis: usulan sudah siap tetapi pekerja uji belum mulai. Halaman
           tetap memantau supaya kotak hasil/pratinjau muncul sendiri (tanpa perlu
           ada tombol "Uji di Staging" yang harus diklik). */
        'menunggu_uji' => (bool)$t['ops'] && in_array((string)$t['status'], ['proposed'], true)
            && in_array((string)($t['test_status'] ?? ''), ['menunggu', 'menyiapkan staging', 'berjalan'], true),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------ *
 * Aksi
 * ------------------------------------------------------------------ */
$aksi = (string)($_POST['action'] ?? '');
/* JEBAKAN PHP: bila TOTAL unggahan melebihi `post_max_size`, PHP MEMBUANG seluruh
   isi POST/FILES tanpa pesan — halaman terlihat "tidak terjadi apa-apa". Dideteksi
   di sini dan dijelaskan apa adanya beserta batas yang berlaku. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('Unggahan DIBATALKAN oleh server karena melebihi batas: ' . ai_upload_limit_text()
        . ' Kurangi jumlah/ukuran berkas lalu kirim ulang.', 'error');
    header('Location: ai_developer.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        /* ==========================================================================
         * SATU KOTAK CHAT (ronde 49, permintaan pemilik).
         * Satu kolom yang sama dipakai untuk: permintaan BARU, perbaikan lanjutan,
         * dan perintah "lanjutkan dan terapkan". Tidak ada lagi tombol uji staging
         * maupun tombol terapkan yang harus dicari-cari.
         * ========================================================================== */
        if ($aksi === 'stop') {
            $idStop = (int)($_POST['id'] ?? 0);
            $t = $idStop > 0 ? ai_task($idStop) : null;
            if (!$t) throw new RuntimeException('Tugas tidak ditemukan.');
            $ok = ai_job_request_cancel($idStop);
            audit('Hentikan Pekerjaan AI', 'AI Developer', $idStop, null,
                ['job' => ai_job_summary_line(ai_job_view($idStop))], 'Pemilik menghentikan pekerjaan AI');
            flash($ok ? 'Permintaan BERHENTI dikirim. Worker menutup pekerjaan ini dalam beberapa detik — '
                . 'statusnya berubah menjadi "Dihentikan oleh Anda".'
                : 'Tidak ada pekerjaan yang sedang berjalan untuk permintaan ini.', $ok ? 'warning' : 'error');
            header('Location: ai_developer.php?id=' . $idStop);
            exit;
        }
        if ($aksi === 'pesan') {
            $idPesan = (int)($_POST['id'] ?? 0);
            $teksPesan = trim((string)($_POST['request'] ?? ''));
            if ($idPesan <= 0) {
                $aksi = 'minta';           // permintaan baru — jalur yang sama seperti sebelumnya
            } elseif ($teksPesan !== '' && ai_apply_command($teksPesan)) {
                /* Kalimat ajaib: "lanjutkan dan terapkan". Diteruskan ke jalur penerapan
                   yang SUDAH ada (kata sandi + uji wajib lulus + salinan pengaman +
                   konfirmasi), jadi tidak ada pengaman yang dilonggarkan. */
                ai_msg($idPesan, 'user', $teksPesan);
                ai_step($idPesan, 'info', 'Perintah dari kotak chat: terapkan perubahan', short_text($teksPesan, 120));
                $_POST['confirm_word'] = 'TERAPKAN';
                $aksi = 'terapkan';
            } else {
                $aksi = 'lanjut';          // perbaikan/lanjutan percakapan
            }
        }
        if ($aksi === 'minta') {
            if (!ai_ready()) throw new RuntimeException(ai_ready_text());
            $req = trim((string)($_POST['request'] ?? ''));
            if (ai_strlen($req) < 10) throw new RuntimeException('Tulis permintaan yang lebih jelas (minimal 10 huruf).');
            if (ai_strlen($req) > 8000) throw new RuntimeException('Permintaan maksimal 8000 huruf.');
            $suite = (string)($_POST['suite'] ?? '');
            if ($suite !== '' && $suite !== 'auto' && !in_array($suite, ai_suite_list(), true)) $suite = '';
            /* RONDE 50: mode yang diminta pemilik (bawaan "auto" = AI menebak sendiri). */
            $modus = (string)($_POST['modus'] ?? 'auto');
            if (!array_key_exists($modus, ai_intent_modes())) $modus = 'auto';
            q('INSERT INTO ai_tasks (user_id, request, provider, model, status, stage, suite, intent_mode, created_at)
               VALUES (?,?,?,?, "draft", "Menunggu dijalankan AI", ?, ?, datetime("now","localtime"))',
                [(int)$user['id'], $req, ai_settings()['provider'], ai_settings()['model'],
                 $suite === '' || $suite === 'auto' ? 'auto' : $suite, $modus]);
            $id = (int)db()->lastInsertId();
            /* LAMPIRAN (ronde 44): berkas apa pun (Excel/PDF/gambar/dokumen) — isinya
               dibaca dan disertakan pada permintaan ke AI. */
            $jmlLampiran = 0; $tolak = [];
            foreach (ai_uploaded_files('files') as $f) {
                $err = '';
                $simpan = ai_attachment_save($id, $f, $err);
                if ($simpan) $jmlLampiran++; else $tolak[] = $err;
            }
            audit('Minta Perubahan AI', 'AI Developer', $id, null,
                ['provider' => ai_settings()['provider'], 'model' => ai_settings()['model'],
                 'permintaan' => short_text($req, 200), 'lampiran' => $jmlLampiran],
                'Permintaan pengembangan dikirim ke AI');
            ai_msg($id, 'user', $req);
            ai_task_update($id, ['title' => short_text($req, 70)]);
            ai_spawn_worker('plan', $id);
            flash('Permintaan dikirim' . ($jmlLampiran > 0 ? ' bersama ' . num($jmlLampiran) . ' lampiran' : '')
                . '. AI sedang menelusuri berkas yang terkait — prosesnya dapat Anda lihat di bawah.'
                . ($tolak ? ' Lampiran yang ditolak: ' . implode(' ', $tolak) : ''));
            header('Location: ai_developer.php?id=' . $id);
            exit;
        }
        /* LANJUTKAN PERCAKAPAN (ronde 45) — permintaan pemilik: "jika saya kurang
           puas bisa melanjutkan perintah di chat tsb". Pesan baru DISIMPAN sebagai
           bagian percakapan, lalu AI menjalankan ulang dengan riwayat itu sebagai
           konteks, sehingga revisi dipahami sebagai lanjutan pekerjaan yang sama. */
        if ($aksi === 'lanjut' || $aksi === 'ulang') {
            if (!ai_ready()) throw new RuntimeException(ai_ready_text());
            $id = (int)($_POST['id'] ?? 0);
            $t = ai_task($id);
            if (!$t) throw new RuntimeException('Tugas tidak ditemukan.');
            if ((string)$t['status'] === 'applied') throw new RuntimeException('Perubahan sudah diterapkan — batalkan dulu bila ingin menyusun ulang.');
            if (in_array((string)$t['status'], ['analyzing', 'testing'], true)) {
                throw new RuntimeException('AI masih bekerja pada permintaan ini — tunggu sampai selesai dulu.');
            }
            $req = trim((string)($_POST['request'] ?? $_POST['pesan'] ?? ''));
            $ubah = ['status' => 'draft', 'stage' => 'Menunggu dijalankan AI', 'error' => '',
                     'raw_reply' => '', 'brief' => '', 'preview_page' => '', 'suggestions' => ''];
            /* Mode boleh diganti pada pesan lanjutan (mis. dari "jawab" ke "kerjakan"). */
            if (isset($_POST['modus'])) {
                $mm = (string)$_POST['modus'];
                if (array_key_exists($mm, ai_intent_modes())) $ubah['intent_mode'] = $mm;
            }
            if ($req !== '') {
                /* Permintaan pada tugas diperbarui menjadi pesan terbaru, sedangkan
                   riwayat lengkapnya sudah tersimpan di ai_messages. */
                $ubah['request'] = $req;
                ai_msg($id, 'user', $req);
            }
            if (isset($_POST['suite'])) {
                $s = (string)$_POST['suite'];
                if ($s === 'auto' || $s === '' || in_array($s, ai_suite_list(), true)) $ubah['suite'] = $s === '' ? 'auto' : $s;
            }
            ai_task_update($id, $ubah);
            $jml = 0; $tolak = [];
            foreach (ai_uploaded_files('files') as $f) {
                $err = '';
                if (ai_attachment_save($id, $f, $err)) $jml++; else $tolak[] = $err;
            }
            ai_step($id, 'info', 'Permintaan lanjutan dari pemilik', short_text($req, 300));
            ai_spawn_worker('plan', $id);
            flash('Perintah lanjutan dikirim ke AI.'
                . ($jml > 0 ? ' ' . num($jml) . ' lampiran baru ditambahkan.' : '')
                . ($tolak ? ' Lampiran yang ditolak: ' . implode(' ', $tolak) : ''));
            header('Location: ai_developer.php?id=' . $id);
            exit;
        }
        if ($aksi === 'hapus_lampiran') {
            $id = (int)($_POST['id'] ?? 0);
            $fid = (int)($_POST['file_id'] ?? 0);
            ai_attachment_delete($fid);
            audit('Hapus Lampiran AI', 'AI Developer', $id, null, ['lampiran' => $fid],
                'Lampiran permintaan AI dihapus');
            flash('Lampiran dihapus.', 'warning');
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
                'workflow' => ai_workflow_status('applied')['kode'],
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
                'workflow' => ai_workflow_status('rolledback')['kode'],
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
            ai_attachment_delete_task($id);
            q('DELETE FROM ai_usage_log WHERE task_id = ?', [$id]);
            /* RONDE 50: langkah, pesan percakapan, dan JEJAK KERJA ikut dibuang supaya
               tabel-tabel itu tidak menumpuk seiring riwayat yang dihapus pemilik. */
            q('DELETE FROM ai_steps WHERE task_id = ?', [$id]);
            q('DELETE FROM ai_messages WHERE task_id = ?', [$id]);
            q('DELETE FROM ai_traces WHERE task_id = ?', [$id]);
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
    <a class="btn" href="ai_settings.php#daftarmodel"><?= icon('settings') ?> AI Settings &amp; Model</a>
    <a class="btn" href="developer.php#dokumenfungsi"><?= icon('download') ?> Dokumen Fungsi (PDF)</a>
  </div>
</div>

<?php if (!ai_ready()): ?>
  <div class="alert alert-warning">
    <strong>AI belum siap dipakai.</strong> <?= e(ai_ready_text()) ?>
    <a href="ai_settings.php">Buka AI Settings</a> untuk mengisi kunci API / mengaktifkannya.
  </div>
<?php endif; ?>

<?php
/* ==========================================================================
 * KARTU PANDUAN (ronde 43, permintaan pemilik):
 * "di kartu permintaan ada banyak pilihan tapi saya tidak tahu pilihan itu untuk
 *  apa — buatkan tabel keterangannya supaya saya paham sebelum menulis permintaan."
 * Isi: arti setiap kolom/tombol di kartu permintaan + keterangan SEMUA suite uji
 * (dibaca dari run_all.sh sehingga otomatis ikut bertambah bila ada suite baru).
 * ======================================================================== */
$panduanSuite = ai_suite_info();
$jumlahRingan = 0; $jumlahSedang = 0; $jumlahBerat = 0;
foreach ($panduanSuite as $s) {
    if ($s['berat'] === 'ringan') $jumlahRingan++;
    elseif ($s['berat'] === 'berat') $jumlahBerat++;
    else $jumlahSedang++;
}
?>
<?php
/* ==========================================================================
 * SATU KARTU CHAT (ronde 49 — permintaan pemilik)
 * ==========================================================================
 * Permintaan pemilik: "kenapa tidak dibikin satu kartu saja seperti ini — ketika
 * saya chat di kartu AI Developer, langsung diproses di situ tanpa membuka kartu
 * baru; Percakapan & Proses dijadikan satu; ketika AI sudah oke, di chat itu ada
 * pesan dengan tautan untuk cek pratinjau; kalau oke saya ketik 'lanjutkan dan
 * terapkan'; kalau belum oke bisa lanjut lagi di chat yang sama. Uji sendiri di
 * chat AI-nya, tidak usah saya klik tombol uji staging — kelamaan dan jadi bug."
 *
 * Karena itu SEMUA hal ini berada di SATU kartu:
 *   • percakapan + langkah proses (mengalir otomatis tiap 2 detik),
 *   • pesan hasil + tombol/tautan PRATINJAU (di dalam percakapan),
 *   • kotak tulis: permintaan baru, perbaikan lanjutan, ATAU "lanjutkan dan terapkan",
 *   • rincian teknis (diff, sintaks, token, lampiran) yang bisa dibuka-tutup.
 * ======================================================================== */
$riwayat = ai_tasks(8);
$ops = $t ? (array)$t['ops'] : [];
$berkas = $t ? (json_decode((string)($t['files'] ?? '[]'), true) ?: []) : [];
$uji = $t ? ai_test_counts($t)
          : ['pass' => 0, 'fail' => 0, 'ringkas' => '', 'selesai' => false, 'ada_total' => false, 'gagal_baris' => []];
[$lbl, $tone] = $t ? ai_status_label((string)$t['status']) : ['Belum ada permintaan', 'gray'];
$bolehLanjut = $t ? !in_array((string)$t['status'], ['applied', 'rolledback', 'rejected'], true) : true;
$statusT = $t ? (string)$t['status'] : '';
$statusUji = $t ? (string)($t['test_status'] ?? '') : '';
$lulus = ($statusUji === 'lulus');
$sedangUji = ($statusT === 'testing') || ($statusUji === 'menunggu') || ($statusUji === 'menyiapkan staging')
    || ((string)($uji['jalan'] ?? '') === '1');
$halamanPratinjau = '';
$penanda = '';
if ($ops && $t) {
    $halamanPratinjau = trim((string)($t['preview_page'] ?? ''));
    if ($halamanPratinjau === '') $halamanPratinjau = ai_preview_page($berkas, (string)$t['request']);
    /* Teks penanda dari patch dipakai untuk MENGGULIR & MENYOROT bagian yang diubah
       di dalam pratinjau (tag HTML & entitas dibuang supaya cocok dengan teks tampil). */
    foreach ($ops as $op) {
        $kandidat = trim((string)($op['replace'] ?? ''));
        if ($kandidat === '') $kandidat = trim((string)($op['search'] ?? ''));
        $kandidat = (string)preg_replace('~<[^>]*>~', ' ', $kandidat);
        $kandidat = html_entity_decode($kandidat, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $kandidat = trim((string)preg_replace('/\s+/', ' ', $kandidat));
        if (ai_strlen($kandidat) >= 12) { $penanda = mb_substr_ai_fallback($kandidat, 0, 60); break; }
    }
}
$langkahChat = $t ? ai_steps((int)$t['id']) : [];
$pesanChat = $t ? ai_messages((int)$t['id']) : [];
$jalanChat = $t && in_array($statusT, ['draft', 'analyzing', 'testing'], true);
$urut = [];
foreach ($pesanChat as $m) {
    $urut[] = ['jam' => (string)$m['created_at'], 'kunci' => 1, 'id' => (int)$m['id'], 'tipe' => 'pesan', 'd' => $m];
}
foreach ($langkahChat as $st) {
    $urut[] = ['jam' => (string)$st['created_at'], 'kunci' => 0, 'id' => (int)$st['id'], 'tipe' => 'langkah', 'd' => $st];
}
usort($urut, fn($a, $b) => [$a['jam'], $a['kunci'], $a['id']] <=> [$b['jam'], $b['kunci'], $b['id']]);
$ikonLangkah = ['ok' => '✓', 'error' => '✕', 'warn' => '!', 'work' => '›', 'info' => '·', 'test' => '⚙'];
$contoh = [
    'Ubah icon menu AI Developer dan AI Settings jadi lebih jelas dan mudah dikenali.',
    'Di halaman Data Pasien, tambahkan kolom email pada tabel.',
    'Dari lampiran ini, ambil hanya nama, jenis kelamin, tanggal lahir, NIK, WA, email, dan alamat — masukkan ke cabang Kaliwungu. Nomor pasien dan nomor member dibuat otomatis.',
    'Buat halaman baru untuk mencatat keluhan pasien beserta tindak lanjutnya.',
];
?>
<div class="card" id="chat">
  <div class="card-head">
    <h3>Chat dengan AI Developer<?= $t ? ' — Permintaan #' . (int)$t['id'] : '' ?></h3>
    <span class="flex gap-sm flex-wrap" style="align-items:center">
      <?= badge($lbl, $tone) ?>
      <?php if ($t): ?>
        <?php /* RONDE 50/52: status workflow RESMI (satu sumber dengan mesin) + jenis
                 permintaan, supaya layar tidak pernah berbeda dengan keadaan sesungguhnya. */ ?>
        <?php $view = ai_task_status_view($t); ?>
        <?= badge($view['workflow'], $view['tone']) ?>
        <?php if (!$view['konsisten']): ?>
          <?= badge('status tidak konsisten', 'red') ?>
        <?php endif; ?>
        <?php if (trim((string)($t['intent'] ?? '')) !== ''): ?>
          <?= badge('Jenis: ' . (string)$t['intent'], 'gray') ?>
        <?php endif; ?>
        <?php if ((string)($t['audit_status'] ?? '') === 'belum_lengkap'): ?>
          <?= badge('Audit belum lengkap', 'yellow') ?>
        <?php endif; ?>
        <span class="muted small"><?= e((string)($t['engine'] ?? '')) !== ''
            ? e((string)$t['engine']) : e((string)$t['provider'] . ' · ' . (string)$t['model']) ?>
          · <?= e(tgl((string)$t['created_at'], true)) ?></span>
        <a class="btn btn-sm" href="ai_developer.php"><?= icon('plus-circle') ?> Permintaan baru</a>
      <?php else: ?>
        <span class="muted small"><?= e(ai_ready_text()) ?></span>
      <?php endif; ?>
    </span>
  </div>
  <div class="card-body">
    <?php if (!$t): ?>
      <div class="notice small">
        <strong>Bicara saja seperti mengobrol.</strong> Anda boleh bertanya, berdiskusi, minta saran,
        minta diperiksa, atau minta rencana — AI akan <strong>menjawab</strong> tanpa mengubah kode.
        Ketika Anda meminta dikerjakan (atau menulis <strong>kerjakan</strong> setelah menyetujui usulannya),
        AI menelusuri berkas yang terkait, menyusun perubahan, <strong>mengujinya sendiri di salinan
        aplikasi</strong>, lalu memberi tahu Anda di kolom percakapan ini — lengkap dengan tautan
        pratinjau. Aplikasi asli baru berubah setelah Anda mengetik
        <strong>lanjutkan dan terapkan</strong>.
        <?php if (!ai_ready()): ?>
          <br><strong>AI belum siap:</strong> <?= e(ai_ready_text()) ?>
          <a href="ai_settings.php">Buka AI Settings</a>.
        <?php endif; ?>
      </div>
    <?php else: ?>
      <?php
      /* ==========================================================================
       * PANEL JOB (ronde 53) — proses kerja terlihat LANGSUNG di kartu chat:
       * job_id, status, progres nyata, checklist langkah, heartbeat, retry, dan
       * tombol BERHENTI. Seluruhnya diperbarui lewat AJAX ringan (tanpa memuat
       * ulang halaman) sehingga pemilik tetap dapat menggulir & membaca percakapan.
       * ======================================================================== */
      $jobView = ai_job_view((int)$t['id']);
      ?>
      <div class="job-panel" id="jobPanel" data-task="<?= (int)$t['id'] ?>">
        <div class="job-head">
          <span class="job-id"><?= e($jobView['job_id'] !== '' ? 'Job ' . $jobView['job_id'] : 'Job belum dimulai') ?></span>
          <span class="badge badge-<?= e($jobView['tone']) ?>" id="jobStatus"><?= e($jobView['status']) ?></span>
          <span class="muted small" id="jobHeart"><?= $jobView['berjalan']
            ? 'heartbeat ' . (int)$jobView['umur'] . 's lalu' : '' ?></span>
          <?php if ($jobView['berjalan']): ?>
            <form method="post" class="inline-form" style="margin-left:auto"
                  data-confirm="Hentikan pekerjaan ini? Worker akan menutup pekerjaan dengan rapi.">
              <?= csrf_field() ?><input type="hidden" name="action" value="stop">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit" id="jobStop"><?= icon('x') ?> Berhenti</button>
            </form>
          <?php endif; ?>
        </div>
        <div class="job-bar"><i id="jobBar" style="width:<?= (int)$jobView['progress'] ?>%"></i></div>
        <div class="job-meta muted small" id="jobMeta">
          <?= (int)$jobView['progress'] ?>% · <span id="jobStep"><?= e($jobView['current_step_label'] !== ''
            ? $jobView['current_step_label'] : (string)($t['stage'] ?? '')) ?></span>
          <?= $jobView['retry'] > 0 ? ' · percobaan ulang ' . (int)$jobView['retry'] : '' ?>
          <?= $jobView['note'] !== '' ? ' · ' . e($jobView['note']) : '' ?>
        </div>
        <ul class="job-steps" id="jobSteps">
          <?php foreach ($jobView['langkah'] as $l): ?>
            <li class="job-step is-<?= e($l['status']) ?>"><span class="job-ico"><?= e($l['ikon']) ?></span>
              <?= e($l['nama']) ?></li>
          <?php endforeach; ?>
        </ul>
        <div class="notice small hide" id="jobError"></div>
      </div>

      <div class="notice small" id="stageBox">
        <strong>Sedang dikerjakan:</strong> <span id="stageText"><?= e((string)($t['stage'] ?? '-')) ?></span>
        <span id="stageSpin" class="muted small"></span>
      </div>

      <?php $lampiranChat = ai_attachment_list((int)$t['id']); ?>
      <?php if ($lampiranChat): ?>
        <?php /* LAMPIRAN TAMPIL DI PERCAKAPAN (spesifikasi PDF bagian 1): pemilik melihat
                 berkas yang dikirim beserta status pembacaannya — apakah isinya benar-benar
                 masuk ke konteks AI (huruf terbaca) atau tidak. */ ?>
        <div class="mt-2" id="chatAttach">
          <?php foreach ($lampiranChat as $lf): ?>
            <div class="notice small" style="margin:6px 0">
              <?= icon('upload') ?> <strong><?= e((string)$lf['name']) ?></strong>
              <span class="muted"> · <?= badge(ai_attach_kind((string)$lf['ext']), 'blue') ?>
                · <?= num(round((int)$lf['size'] / 1024, 1), 1) ?> KB</span>
              <?php if ((int)$lf['chars'] > 0): ?>
                <span class="badge badge-green">isi terbaca <?= num((int)$lf['chars']) ?> huruf → masuk konteks AI</span>
              <?php else: ?>
                <span class="badge badge-yellow">isi belum terbaca</span>
                <span class="muted small"><?= e(short_text((string)($lf['note'] ?? ''), 120)) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div id="chatThread" class="mt-2 ai-scrollbox" style="max-height:56vh">
        <?php foreach ($urut as $it): ?>
          <?php if ($it['tipe'] === 'pesan'): ?>
            <?php if ($it['d']['role'] === 'user'): ?>
              <div class="flex gap-sm mt-2" style="align-items:flex-start;flex-direction:row-reverse">
                <div class="avatar" style="flex:0 0 32px">A</div>
                <div style="max-width:74%">
                  <div class="notice" style="margin:0;background:var(--brand-tint)">
                    <div class="small muted">Anda · <?= e(substr((string)$it['d']['created_at'], 11, 5)) ?></div>
                    <?= nl2br(e((string)$it['d']['text'])) ?>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <div class="flex gap-sm mt-2" style="align-items:flex-start">
                <div class="avatar" style="flex:0 0 32px">AI</div>
                <div style="max-width:86%">
                  <div class="notice" style="margin:0">
                    <div class="small muted">AI Developer · <?= e(substr((string)$it['d']['created_at'], 11, 5)) ?></div>
                    <div class="chat-ai"><?= chat_md((string)$it['d']['text']) ?></div>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="chat-step chat-step-<?= e((string)$it['d']['kind']) ?>" style="margin-left:44px">
              <span class="chat-ico"><?= e($ikonLangkah[(string)$it['d']['kind']] ?? '·') ?></span>
              <span><?= e((string)$it['d']['text']) ?></span>
              <span class="muted small"><?= e(substr((string)$it['d']['created_at'], 11, 5)) ?></span>
              <?php if ((string)($it['d']['detail'] ?? '') !== ''): ?>
                <div class="chat-det muted small"><?= e((string)$it['d']['detail']) ?></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$urut): ?>
          <p class="muted">Belum ada langkah tercatat untuk permintaan ini.</p>
        <?php endif; ?>
      </div>

      <?php if ($jalanChat): ?>
        <div class="notice small mt-2" id="chatJalan">
          <strong>AI sedang bekerja…</strong> percakapan &amp; proses di atas diperbarui sendiri setiap
          2 detik — halaman ini tidak perlu dimuat ulang.
        </div>
      <?php endif; ?>

      <?php /* ================= KOTAK HASIL + PRATINJAU =================
         Inilah "pesan di chat" yang diminta pemilik: begitu AI selesai, di kolom
         percakapan muncul kotak berisi hasil uji, tautan cek pratinjau, dan
         perintah yang perlu diketik (lanjutkan dan terapkan / perbaikan). */ ?>
      <?php if ($ops && $bolehLanjut): ?>
        <div class="card-actions-bar mt-2" id="aksiBubble">
          <?php if ($lulus): ?>
            <div class="alert alert-success" style="margin:0 0 10px">
              <strong>✅ Selesai — perubahan siap Anda periksa.</strong>
              Uji di salinan aplikasi <strong>LULUS</strong>
              (<?= num((int)($uji['pass'] ?? 0)) ?> pemeriksaan).
            </div>
          <?php elseif ($sedangUji): ?>
            <div class="notice" style="margin:0 0 10px" id="bubbleUji">
              <strong>⏳ Sebentar — saya sedang menguji sendiri perubahan ini</strong> di salinan aplikasi
              (aplikasi asli belum berubah). Bila muncul error, saya <strong>perbaiki sendiri</strong> lalu
              menguji ulang. Hasilnya muncul di sini begitu selesai.
            </div>
          <?php else: ?>
            <div class="alert alert-warning" style="margin:0 0 10px">
              <strong>⚠ Uji di salinan belum lulus</strong>
              (<?= num((int)($uji['fail'] ?? 0)) ?> pemeriksaan gagal). Penerapan masih terkunci.
              Alasan lengkapnya ada di bagian <em>Rincian teknis → Hasil uji</em> di bawah —
              tulis saja di kotak chat apa yang perlu disesuaikan.
            </div>
          <?php endif; ?>

          <div class="flex gap-sm flex-wrap" style="align-items:center">
            <button class="btn btn-primary" type="button" id="pvTampil">
              <?= icon('search') ?> Cek pratinjau di sini</button>
            <a class="btn" href="ai_preview.php?task=<?= (int)$t['id'] ?>"
               target="_blank" rel="noopener">
              <?= icon('download') ?> Buka pratinjau di tab baru</a>
            <span class="muted small">Halaman <code><?= e($halamanPratinjau) ?></code><?= $penanda !== ''
              ? ' · otomatis melompat ke bagian yang direvisi' : '' ?></span>
          </div>
          <div id="pvBox" style="display:none;margin-top:10px">
            <iframe id="pvFrame" style="width:100%;height:min(70vh,780px);border:1px solid var(--line);border-radius:12px;background:#fff"
                    title="Pratinjau perubahan"
                    data-src="ai_preview.php?task=<?= (int)$t['id'] ?>&p=<?= e(rawurlencode($halamanPratinjau)) ?><?= $penanda !== '' ? '&cari=' . e(rawurlencode($penanda)) : '' ?>"></iframe>
          </div>
          <div class="small mt-2" style="line-height:1.6">
            <strong>Kalau sudah cocok:</strong> ketik <code>lanjutkan dan terapkan</code> di kotak chat di
            bawah (jangan lupa isi kata sandi Anda) — perubahan langsung diterapkan ke aplikasi.
            <br><strong>Kalau belum cocok:</strong> tulis saja perbaikannya di kotak chat yang sama
            (mis. "icon-nya masih tertukar", "kolomnya kurang lebar") — saya perbaiki dan uji lagi.
          </div>
        </div>
      <?php elseif ($ops && $statusT === 'applied'): ?>
        <div class="alert alert-success mt-2" id="aksiBubble">
          <strong>✅ Perubahan sudah DITERAPKAN</strong> pada <?= e(tgl((string)$t['applied_at'], true)) ?>.
          Periksa halaman terkait; bila ada masalah, tekan <em>Batalkan</em> di kotak chat bawah untuk
          mengembalikan berkas seperti semula.
        </div>
      <?php elseif ($statusT === 'noop' && (string)($t['audit_status'] ?? '') === 'lengkap'): ?>
        <div class="alert alert-info mt-2" id="aksiBubble">
          <strong>AI tidak mengusulkan perubahan apa pun</strong> untuk permintaan ini — semua berkas
          sasaran sudah terbaca utuh, seluruh dependency penting sudah diperiksa, jadi auditnya
          <strong>LENGKAP</strong>. Bila memang perlu ada perubahan, tulis permintaan yang lebih
          spesifik di kotak chat bawah (sebut bagian/kartunya).
        </div>
      <?php elseif (in_array($statusT, ['answered', 'analyzed', 'planned'], true)): ?>
        <?php /* RONDE 50: AI baru MENJAWAB/MEMERIKSA/MENYUSUN RENCANA — berkas belum diubah.
                 Pemilik dapat langsung menulis "kerjakan" di kotak chat yang sama. */ ?>
        <div class="alert alert-info mt-2" id="aksiBubble">
          <strong><?= $statusT === 'analyzed' ? 'Pemeriksaan selesai — belum ada berkas yang diubah.'
              : ($statusT === 'planned' ? 'Rencana sudah disusun — belum ada berkas yang diubah.'
              : 'AI sudah menjawab — belum ada berkas yang diubah.') ?></strong>
          Jawabannya ada di percakapan di atas, lengkap dengan langkah lanjutan yang disarankan.
          <div class="flex gap-sm flex-wrap mt-2" style="align-items:center">
            <button class="btn btn-primary btn-sm" type="button" data-isi="kerjakan">
              <?= icon('sparkles') ?> kerjakan</button>
            <span class="muted small">Tekan tombol itu (atau tulis <code>kerjakan</code> + sebutkan bagian
              yang disetujui) supaya AI menyusun perubahannya, menguji di salinan, lalu menampilkan pratinjau.</span>
          </div>
        </div>
      <?php elseif ($statusT === 'blocked' && $ops): ?>
        <div class="alert alert-warning mt-2" id="aksiBubble">
          <strong>Perbaikan otomatis berhenti — saya butuh keputusan Anda.</strong>
          Error yang tersisa tidak dapat saya perbaiki sendiri dengan aman (butuh kredensial, keputusan
          bisnis, atau menyentuh data). Rinciannya ada di percakapan di atas dan di
          <em>Rincian teknis → Jejak kerja AI</em>. Tulis keputusan/arahan Anda di kotak chat bawah,
          lalu saya lanjutkan dari sana. Penerapan tetap terkunci sampai uji lulus.
        </div>
      <?php elseif ($statusT === 'audit_incomplete'): ?>
        <div class="alert alert-warning mt-2" id="aksiBubble">
          <strong>AUDIT_INCOMPLETE — kesimpulan “tidak ada perubahan” DITAHAN.</strong>
          Auditnya belum lengkap (ada berkas gagal dibaca / dependency belum diperiksa / lampiran
          belum terbaca / area wajib belum tuntas / AI sendiri menyatakan belum cukup), jadi AI
          <em>tidak boleh</em> menyimpulkan apa pun dulu. Daftar berkas &amp; alasannya ada di
          <em>Rincian teknis</em> di bawah (lengkap dengan nama berkas dan penyebabnya).
          <div class="flex gap-sm flex-wrap mt-2" style="align-items:center">
            <button class="btn btn-primary btn-sm" type="button" data-isi="Lanjutkan pemeriksaan: baca berkas yang belum terbaca lalu susun patch bila sudah cukup.">
              <?= icon('search') ?> Lanjutkan pemeriksaan</button>
            <span class="muted small">Atau sebutkan bagian/berkas terpenting di kotak chat supaya
              pembacaan AI lebih sempit &amp; tuntas, dan naikkan “Anggaran konteks / Ukuran maksimal
              berkas” di <a href="ai_settings.php">AI Settings</a> bila perlu.</span>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php /* ================= KOTAK CHAT (selalu di bawah) ================= */ ?>
    <?php if (!$t): ?>
      <form method="post" enctype="multipart/form-data" class="mt-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="minta">
        <?php require __DIR__ . '/ai_chat_form.php'; ?>
      </form>
    <?php elseif ($bolehLanjut): ?>
      <form method="post" enctype="multipart/form-data" class="mt-3" id="formChat">
        <?= csrf_field() ?><input type="hidden" name="action" value="pesan">
        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <?php require __DIR__ . '/ai_chat_form.php'; ?>
      </form>
    <?php else: ?>
      <div class="notice small mt-3">
        Percakapan ini sudah <strong><?= $statusT === 'applied' ? 'diterapkan'
          : ($statusT === 'rolledback' ? 'dibatalkan' : 'ditolak') ?></strong>, jadi tidak dapat dilanjutkan.
        <?php /* Pembatalan perubahan yang sudah diterapkan tetap boleh (dengan kata sandi). */ ?>
        <?php if ($statusT === 'applied'): ?>
          <form method="post" class="flex gap-sm flex-wrap mt-2" style="align-items:flex-end"
                data-confirm="Kembalikan berkas ke keadaan sebelum perubahan AI ini?">
            <?= csrf_field() ?><input type="hidden" name="action" value="batalkan">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <div class="field" style="min-width:200px"><label>Kata sandi Anda</label>
              <input class="input input-sm" type="password" name="password" required></div>
            <button class="btn btn-danger" type="submit"><?= icon('refresh') ?> Batalkan (kembalikan berkas)</button>
          </form>
        <?php endif; ?>
        <div class="mt-2"><a class="btn" href="ai_developer.php"><?= icon('plus-circle') ?> Permintaan baru</a></div>
      </div>
    <?php endif; ?>

    <?php if ($t): ?>
      <?php
      /* ---------- RINCIAN TEKNIS (bisa dibuka-tutup, tetap di kartu yang sama) ---------- */
      $lampiran = ai_attachment_list((int)$t['id']);
      /* JEJAK KERJA AI (ronde 50). Permintaan pemilik (V2.3 bagian 7): simpan jejak yang
         memungkinkan pemilik & pengembang mengetahui apa yang dilakukan AI — maksud yang
         dibaca, berkas yang dibaca/dilewati beserta alasannya, bagian yang terdampak,
         keputusan, perubahan, dan hasil uji — supaya kegagalan dapat ditelusuri. */
      $jejak = ai_traces((int)$t['id'], 40);
      $pakai = ai_usage_task((int)$t['id']);
      $brief = trim((string)($t['brief'] ?? ''));
      $gagalBaris = (array)($uji['gagal_baris'] ?? []);
      $ujiRingkasTersimpan = trim((string)($t['test_ringkas'] ?? ''));
      if (!$gagalBaris && $ujiRingkasTersimpan !== '' && !empty($uji['selesai'])) {
          $gagalBaris = array_values(array_filter(
              array_map('trim', explode("\n", $ujiRingkasTersimpan)),
              fn($l) => stripos($l, 'FAIL') !== false || stripos($l, '- ') === 0));
      }
      ?>
      <div class="section-title">Rincian teknis</div>

      <?php /* RONDE 50: bagaimana AI membaca permintaan ini + berkas yang dibaca/tertahan. */ ?>
      <?php if ((string)($t['intent_note'] ?? '') !== '' || (string)($t['audit_status'] ?? '') !== ''): ?>
        <div class="notice small">
          <strong>Bagaimana AI membaca permintaan ini:</strong> <?= e((string)($t['intent_note'] ?? '-')) ?>
          <?php if ((string)($t['intent'] ?? '') !== ''): ?> · jenis <code><?= e((string)$t['intent']) ?></code><?php endif; ?>
          · langkah workflow <code><?= e(ai_workflow_status((string)$t['status'])['kode']) ?></code>
          <?php if ((string)($t['audit_status'] ?? '') !== ''): ?>
            · audit berkas sasaran:
            <strong><?= (string)$t['audit_status'] === 'lengkap' ? 'LENGKAP (semua terbaca utuh)' : 'BELUM LENGKAP' ?></strong>
          <?php endif; ?>
          <?php $fr = json_decode((string)($t['files_read'] ?? '[]'), true) ?: []; ?>
          <?php if ($fr): ?>
            <div class="mt-1">Berkas yang dibaca:
              <?php foreach ($fr as $rb => $st): ?><code class="small"><?= e((string)$rb) ?><?= $st === 'utuh' ? '' : ' (sebagian)' ?></code> <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php
      /* RONDE 52: bila audit belum lengkap, daftar berkas/area yang BELUM selesai
         diperiksa ditampilkan lengkap dengan alasannya (permintaan pemilik). */
      $auditAlasan = json_decode((string)($t['files_skipped'] ?? '[]'), true);
      if (!is_array($auditAlasan)) $auditAlasan = array_filter(array_map('trim',
          explode("\n", (string)($t['files_skipped'] ?? ''))));
      ?>
      <?php if ((string)($t['audit_status'] ?? '') !== 'lengkap'
                && in_array($statusT, ['audit_incomplete', 'blocked'], true)): ?>
        <div class="alert alert-warning mt-1">
          <strong>AUDIT_INCOMPLETE — berkas/area yang belum selesai diperiksa:</strong>
          <?php if (!empty($auditAlasan)): ?>
            <ul class="small" style="margin:6px 0 0 18px;padding:0">
              <?php foreach (array_slice($auditAlasan, 0, 10) as $al): ?>
                <li><code><?= e(short_text((string)$al, 220)) ?></code></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <?php /* Alasan selalu ditampilkan — bila daftar rincinya kosong (permintaan lama),
                     keadaan sebenarnya dinyatakan apa adanya, bukan dikosongkan. */ ?>
            <div class="small mt-1">AI menyatakan pemeriksaannya <strong>belum cukup</strong> untuk
              menyimpulkan perubahan. Daftar rinci berkasnya tidak tersimpan pada permintaan ini
              (dibuat sebelum fitur ini aktif) — lihat <em>Rincian teknis → Jejak kerja AI</em>
              dan kolom &ldquo;Rencana AI&rdquo; untuk penjelasan AI apa adanya.</div>
          <?php endif; ?>
          <div class="small mt-1">Selama daftar ini belum kosong, AI <strong>tidak boleh</strong>
            menyimpulkan &ldquo;tidak ada perubahan&rdquo;. Bila berkasnya memang perlu diperiksa,
            tulis bagian yang paling penting di kotak chat (supaya pembacaannya lebih sempit dan
            tuntas) atau naikkan &ldquo;Anggaran konteks / Ukuran maksimal berkas&rdquo; di
            <a href="ai_settings.php">AI Settings</a>.</div>
        </div>
      <?php endif; ?>

      <?php if ($jejak): ?>
        <details class="mt-1">
          <summary style="cursor:pointer">Jejak kerja AI (<?= num(count($jejak)) ?> fase) — untuk penelusuran</summary>
          <div class="table-wrap mt-1">
            <table class="tbl">
              <thead><tr><th style="width:140px">Fase</th><th style="width:80px">Jam</th><th>Data</th></tr></thead>
              <tbody>
              <?php foreach ($jejak as $jj): ?>
                <tr>
                  <td><code><?= e((string)$jj['phase']) ?></code></td>
                  <td class="small nowrap"><?= e(substr((string)$jj['created_at'], 11, 8)) ?></td>
                  <td class="small"><pre class="ai-pre" style="margin:0;max-height:200px;overflow:auto"><?= e((string)$jj['data']) ?></pre></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endif; ?>

      <?php if ((string)$t['error'] !== ''): ?>
        <div class="alert alert-error" id="errorBox"><?= e((string)$t['error']) ?></div>
      <?php else: ?>
        <div class="alert alert-error hide" id="errorBox"></div>
      <?php endif; ?>

      <?php if ((string)($t['plan'] ?? '') !== ''): ?>
        <details class="mt-1"<?= $statusT === 'noop' ? ' open' : '' ?>>
          <summary style="cursor:pointer"><?= $statusT === 'noop' ? 'Penjelasan AI' : 'Rencana AI' ?></summary>
          <div class="notice mt-1"><?= nl2br(e((string)$t['plan'])) ?></div>
        </details>
      <?php endif; ?>

      <?php if ($berkas): ?>
        <details class="mt-1">
          <summary style="cursor:pointer">Berkas yang diubah (<?= num(count($berkas)) ?>)</summary>
          <div class="flex gap-sm flex-wrap mt-1">
            <?php foreach ($berkas as $b): ?><code class="small"><?= e((string)$b) ?></code><?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>

      <?php if ((string)($t['lint'] ?? '') !== ''): ?>
        <details class="mt-1">
          <summary style="cursor:pointer">Pemeriksaan sintaks</summary>
          <pre class="ai-pre"><?= e((string)$t['lint']) ?></pre>
        </details>
      <?php endif; ?>

      <?php if ((string)($t['diff'] ?? '') !== ''): ?>
        <details class="mt-1">
          <summary style="cursor:pointer">Perbedaan kode (diff) — <code>-</code> dibuang, <code>+</code> ditambahkan</summary>
          <pre class="ai-pre" id="diffPre" style="max-height:44vh;overflow:auto"><?= e((string)$t['diff']) ?></pre>
        </details>
      <?php endif; ?>

      <?php if ($statusUji !== '' || $ujiRingkasTersimpan !== ''): ?>
        <details class="mt-1"<?= ($lulus || $gagalBaris) ? ' open' : '' ?>>
          <summary style="cursor:pointer">Hasil uji otomatis di salinan — <strong><?= e($statusUji ?: 'belum ada') ?></strong>
            <?php if (!empty($uji['pass']) || !empty($uji['fail'])): ?>
              · PASS <?= num((int)$uji['pass']) ?> · FAIL <?= num((int)$uji['fail']) ?>
            <?php endif; ?>
            <?php if (empty($uji['ada_total']) && $statusUji !== '' && !$sedangUji): ?>
              · <span class="badge badge-red">tidak ada pemeriksaan yang selesai</span>
            <?php endif; ?>
          </summary>
          <?php if ($gagalBaris): ?>
            <div class="alert alert-warning mt-1">
              <strong>Pemeriksaan yang GAGAL (<?= num(count($gagalBaris)) ?>):</strong>
              <ul class="small" style="margin:6px 0 0 18px;padding:0">
                <?php foreach (array_slice($gagalBaris, 0, 8) as $gb): ?>
                  <li><code><?= e(short_text(trim((string)$gb), 180)) ?></code></li>
                <?php endforeach; ?>
              </ul>
              <div class="small mt-1">Bila kegagalan ini TIDAK berkaitan dengan berkas yang diubah AI,
                tulis di kotak chat agar saya jalankan uji dengan suite lain (mis. <code>sintaks-js</code>
                untuk perubahan tampilan).</div>
            </div>
          <?php endif; ?>
          <?php
          $ringkasTampil = trim((string)($uji['ringkas'] ?? ''));
          if ($ringkasTampil === '') $ringkasTampil = $ujiRingkasTersimpan;
          ?>
          <?php if ($ringkasTampil !== ''): ?>
            <pre class="ai-pre mt-1" id="testPre"><?= e($ringkasTampil) ?></pre>
            <?php if ((string)($t['test_log'] ?? '') !== '' && is_file((string)$t['test_log'])): ?>
              <div class="flex gap-sm flex-wrap mt-1">
                <a class="btn btn-sm" href="ai_developer.php?id=<?= (int)$t['id'] ?>&log=1" target="_blank">
                  <?= icon('download') ?> Buka keluaran uji lengkap</a>
              </div>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($bolehLanjut && $ops && !$sedangUji): ?>
            <form method="post" class="flex gap-sm flex-wrap mt-2" style="align-items:flex-end">
              <?= csrf_field() ?><input type="hidden" name="action" value="uji">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <div class="field" style="min-width:220px"><label>Jalankan ulang uji dengan suite</label>
                <select class="input input-sm" name="suite">
                  <?php foreach ($suite as $s): ?>
                    <option value="<?= e($s) ?>"<?= (string)$t['suite'] === $s ? ' selected' : '' ?>><?= e($s) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <button class="btn btn-sm" type="submit"><?= icon('shield') ?> Uji ulang</button>
              <span class="muted small">Uji berjalan pada salinan aplikasi — data &amp; aplikasi asli tidak tersentuh.</span>
            </form>
          <?php endif; ?>
        </details>
      <?php endif; ?>

      <?php if ((string)($t['raw_reply'] ?? '') !== '' && (string)$t['error'] !== ''): ?>
        <details class="mt-1">
          <summary style="cursor:pointer">Jawaban mentah AI (untuk penelusuran)
            <?php if ((string)($t['finish_reason'] ?? '') !== ''): ?>
              — alasan berhenti: <code><?= e((string)$t['finish_reason']) ?></code>
            <?php endif; ?>
          </summary>
          <pre class="ai-pre"><?= e((string)$t['raw_reply']) ?></pre>
        </details>
      <?php endif; ?>

      <?php if ($brief !== ''): ?>
        <details class="mt-1">
          <summary style="cursor:pointer">Brief dari tahap Arsitek
            <span class="muted small"><?= e((string)($t['engine'] ?? '')) ?></span></summary>
          <div class="notice small mt-1"><?= nl2br(e($brief)) ?></div>
        </details>
      <?php endif; ?>

      <?php if ((int)$pakai['total'] > 0 || (int)$pakai['calls'] > 0): ?>
        <details class="mt-1">
          <summary style="cursor:pointer">Pemakaian token permintaan ini</summary>
          <div class="notice small mt-1">
            <strong><?= e(ai_usage_text($pakai, (int)$pakai['calls'])) ?></strong>
            <?php if ($pakai['log']): ?>
              <div class="mt-1">
                <table class="tbl">
                  <thead><tr><th>Kegiatan</th><th>Penyedia / model</th><th>Token</th></tr></thead>
                  <tbody>
                  <?php foreach ($pakai['log'] as $lg): ?>
                    <tr><td class="small"><?= e((string)$lg['purpose']) ?></td>
                      <td class="small"><?= e((string)$lg['provider']) ?> · <?= e((string)$lg['model']) ?></td>
                      <td class="small nowrap"><?= num((int)$lg['tokens_total']) ?>
                        <span class="muted">(<?= num((int)$lg['tokens_in']) ?> masuk /
                          <?= num((int)$lg['tokens_out']) ?> keluar<?php if ((int)$lg['tokens_think'] > 0): ?> /
                          <?= num((int)$lg['tokens_think']) ?> berpikir<?php endif; ?>)</span></td></tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
            <div class="muted small">Angka ini dilaporkan penyedia AI, bukan perkiraan. Sisa kuota
              hanya dapat dilihat di dasbor penyedia (Google AI Studio / OpenAI).</div>
          </div>
        </details>
      <?php endif; ?>

      <?php if ($lampiran): ?>
        <details class="mt-1">
          <summary style="cursor:pointer">Lampiran (<?= num(count($lampiran)) ?>)</summary>
          <div class="table-wrap mt-1">
            <table class="tbl">
              <thead><tr><th>Berkas</th><th>Jenis</th><th>Ukuran</th><th>Isi terbaca</th><th>Catatan</th>
                <?php if ($bolehLanjut): ?><th></th><?php endif; ?></tr></thead>
              <tbody>
              <?php foreach ($lampiran as $lf): ?>
                <tr>
                  <td><?= e((string)$lf['name']) ?><div class="muted small"><?= e((string)$lf['ext']) ?></div></td>
                  <td><?= badge(ai_attach_kind((string)$lf['ext']), 'blue') ?></td>
                  <td class="small nowrap"><?= num(round((int)$lf['size'] / 1024, 1), 1) ?> KB</td>
                  <td class="small nowrap"><?= (int)$lf['chars'] > 0 ? num((int)$lf['chars']) . ' huruf'
                      : '<span class="muted">—</span>' ?></td>
                  <td class="small muted"><?= e(short_text((string)($lf['note'] ?? ''), 120)) ?></td>
                  <?php if ($bolehLanjut): ?>
                    <td>
                      <form method="post" data-confirm="Hapus lampiran ini?">
                        <?= csrf_field() ?><input type="hidden" name="action" value="hapus_lampiran">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <input type="hidden" name="file_id" value="<?= (int)$lf['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endif; ?>

      <?php if ($bolehLanjut): ?>
        <details class="mt-1">
          <summary style="cursor:pointer">Tolak / hapus permintaan ini</summary>
          <div class="flex gap-sm flex-wrap mt-2">
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
        </details>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  /* Contoh perintah: klik mengisi kotak chat (tidak langsung mengirim). */
  var ta = document.querySelector('textarea[name=request]');
  document.querySelectorAll('[data-contoh]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!ta) return;
      ta.value = b.getAttribute('data-contoh');
      ta.focus();
      ta.scrollIntoView({ block: 'center' });
    });
  });
  /* Tombol pintas perintah penerapan: mengisi kotak chat dengan kalimat yang
     diminta pemilik ("lanjutkan dan terapkan"). */
  document.querySelectorAll('[data-isi]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!ta) return;
      ta.value = b.getAttribute('data-isi');
      ta.focus();
      ta.scrollIntoView({ block: 'center' });
    });
  });
})();

/* Kotak perintah TUMBUH mengikuti isi: kecil saat pendek, membesar saat panjang. */
(function () {
  function pasang(t) {
    if (!t || t.dataset.autoGrow === '1') return;
    t.dataset.autoGrow = '1';
    var gaya = getComputedStyle(t);
    var maks = parseFloat(gaya.maxHeight);
    if (!isFinite(maks) || maks <= 0) maks = window.innerHeight * 0.56;
    function ukur() {
      t.style.height = 'auto';
      var perlu = Math.min(t.scrollHeight + 2, maks);
      t.style.height = Math.max(perlu, parseFloat(gaya.minHeight) || 90) + 'px';
    }
    t.addEventListener('input', ukur);
    t.addEventListener('focus', ukur);
    ukur();
    window.addEventListener('resize', ukur);
  }
  document.querySelectorAll('textarea.auto-grow').forEach(pasang);
})();
</script>

<?php if ($t): ?>
<script>
/* ==========================================================================
 * Pemantauan percakapan (menggantikan tombol-tombol yang harus diklik):
 * halaman menyegarkan percakapan tiap 2 detik SELAMA AI bekerja (analisis
 * maupun uji otomatis), lalu memuat ulang sekali supaya kotak hasil/pratinjau
 * muncul. Berhenti sendiri agar tidak membebani server.
 * ======================================================================== */
(function () {
  var ID = <?= (int)$t['id'] ?>;
  var stage = document.getElementById('stageText');
  var spin = document.getElementById('stageSpin');
  var errBox = document.getElementById('errorBox');
  var testPre = document.getElementById('testPre');
  var jalan = false;
  var timer = null;
  var n = 0;

  function perbaruiJob() {
    fetch('ai_developer.php?ajax=job&id=' + ID, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok || !d.job) return;
        var j = d.job;
        var st = document.getElementById('jobStatus');
        if (st) { st.textContent = j.status; st.className = 'badge badge-' + j.tone; }
        var bar = document.getElementById('jobBar');
        if (bar) bar.style.width = (j.progress || 0) + '%';
        var meta = document.getElementById('jobMeta');
        if (meta) {
          meta.innerHTML = (j.progress || 0) + '% · ' + aman(j.current_step_label || '')
            + (j.retry > 0 ? ' · percobaan ulang ' + j.retry + '/' + j.max_retry : '')
            + (j.note ? ' · ' + aman(j.note) : '');
        }
        var hb = document.getElementById('jobHeart');
        if (hb) hb.textContent = j.berjalan ? ('heartbeat ' + j.umur + 's lalu') : '';
        var ul = document.getElementById('jobSteps');
        if (ul) {
          var h = '';
          (j.langkah || []).forEach(function (l) {
            h += '<li class="job-step is-' + aman(l.status) + '"><span class="job-ico">'
              + aman(l.ikon) + '</span>' + aman(l.nama) + '</li>';
          });
          ul.innerHTML = h;
        }
        var eb = document.getElementById('jobError');
        if (eb) {
          eb.textContent = j.error || '';
          eb.classList.toggle('hide', !j.error);
          if (j.error) eb.className = 'notice small alert-error';
        }
        /* Tombol Berhenti: tampil hanya saat pekerjaan berjalan. */
        var sp = document.getElementById('jobStop');
        if (sp) sp.closest('form').style.display = j.berjalan ? '' : 'none';
      }).catch(function () { /* jaringan sementara */ });
  }

  function tanya() {
    n++;
    perbaruiJob();
    fetch('ai_developer.php?ajax=status&id=' + ID, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        if (stage) stage.textContent = d.stage || d.status_label || '';
        if (spin) spin.textContent = d.berjalan ? ' (sedang berjalan…)' : '';
        if (errBox) { errBox.textContent = d.error || ''; errBox.classList.toggle('hide', !d.error); }
        if (testPre && d.test && d.test.ringkas) testPre.textContent = d.test.ringkas;
        gambarPercakapan(d);
        var aktif = d.berjalan || (d.test && d.test.jalan) || d.menunggu_uji || (d.job && d.job.berjalan);
        if (!aktif) {
          clearInterval(timer); timer = null;
          /* TANPA MUAT ULANG HALAMAN (permintaan pemilik): cukup tarik ulang bagian
             percakapan + kotak hasil, lalu ganti isinya di tempat. Pemilik tetap
             dapat menggulir & membaca; posisi gulir tidak hilang. */
          if (jalan) {
            fetch(location.pathname + '?ajax=status&id=' + ID, { credentials: 'same-origin' })
              .then(function () { return fetch(location.pathname + '?id=' + ID, { credentials: 'same-origin' }); })
              .then(function (r) { return r.text(); })
              .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                ['aksiBubble', 'chatThread', 'stageText', 'jobPanel'].forEach(function (id) {
                  var baru = doc.getElementById(id), lama = document.getElementById(id);
                  if (baru && lama) lama.outerHTML = baru.outerHTML;
                });
              }).catch(function () { /* biarkan tampilan lama */ });
          }
          return;
        }
        jalan = true;
        /* Batas aman: berhenti memantau setelah ±10 menit supaya halaman tidak
           memanggil server tanpa henti bila pekerja latar belakang mati. */
        if (n > 300) { clearInterval(timer); timer = null; }
      }).catch(function () { /* jaringan sementara: coba lagi pada putaran berikut */ });
  }

  var IKON = { ok: '✓', error: '✕', warn: '!', work: '›', info: '·', test: '⚙' };
  function jam(t) { return (t || '').substr(11, 5); }
  function aman(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
  function tebal(t) { return aman(t).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>'); }
  function gambarPercakapan(d) {
    var kotak = document.getElementById('chatThread');
    if (!kotak || (!d.steps && !d.pesan)) return;
    var item = [];
    (d.pesan || []).forEach(function (m) { item.push({ jam: m.waktu || '', kunci: 1, id: m.id, tipe: 'pesan', d: m }); });
    (d.steps || []).forEach(function (x) { item.push({ jam: x.waktu || '', kunci: 0, id: x.id, tipe: 'langkah', d: x }); });
    item.sort(function (a, b) {
      if (a.jam === b.jam) return a.kunci - b.kunci || a.id - b.id;
      return a.jam < b.jam ? -1 : 1;
    });
    var html = '';
    item.forEach(function (it) {
      if (it.tipe === 'pesan') {
        if (it.d.role === 'user') {
          html += '<div style="display:flex;gap:8px;margin-top:8px;flex-direction:row-reverse">'
            + '<div class="avatar" style="flex:0 0 32px">A</div><div style="max-width:74%">'
            + '<div class="notice" style="margin:0;background:var(--brand-tint)">'
            + '<div class="small muted">Anda · ' + aman(jam(it.d.waktu)) + '</div>'
            + aman(it.d.text).replace(/\n/g, '<br>') + '</div></div></div>';
        } else {
          html += '<div style="display:flex;gap:8px;margin-top:8px">'
            + '<div class="avatar" style="flex:0 0 32px">AI</div><div style="max-width:86%">'
            + '<div class="notice" style="margin:0">'
            + '<div class="small muted">AI Developer · ' + aman(jam(it.d.waktu)) + '</div>'
            + '<div class="chat-ai">' + tebal(it.d.text).replace(/\n/g, '<br>') + '</div>'
            + '</div></div></div>';
        }
      } else {
        var k = it.d.kind || 'info';
        html += '<div class="chat-step chat-step-' + aman(k) + '" style="margin-left:44px">'
          + '<span class="chat-ico">' + aman(IKON[k] || '·') + '</span>'
          + '<span>' + aman(it.d.text) + '</span>'
          + '<span class="muted small">' + aman(jam(it.d.waktu || it.d.created_at)) + '</span>'
          + (it.d.detail ? '<div class="chat-det muted small">' + aman(it.d.detail) + '</div>' : '')
          + '</div>';
      }
    });
    kotak.innerHTML = html !== '' ? html
      : '<p class="muted">Belum ada langkah tercatat untuk permintaan ini.</p>';
  }

  var perlu = <?= ($jalanChat || $sedangUji) ? 'true' : 'false' ?>;
  tanya();
  if (perlu) { jalan = true; timer = setInterval(tanya, 2000); }
})();

/* Tombol "Cek pratinjau di sini": menampilkan kerangka pratinjau di dalam kartu. */
(function () {
  var b = document.getElementById('pvTampil');
  var box = document.getElementById('pvBox');
  var fr = document.getElementById('pvFrame');
  if (!b || !box || !fr) return;
  b.addEventListener('click', function () {
    if (!fr.getAttribute('src')) fr.setAttribute('src', fr.getAttribute('data-src'));
    var tampil = box.style.display !== 'none';
    box.style.display = tampil ? 'none' : 'block';
    b.innerHTML = tampil ? '<?= icon('search') ?> Cek pratinjau di sini'
                         : '<?= icon('x') ?> Sembunyikan pratinjau';
    if (!tampil) box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
})();
</script>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3>Riwayat Permintaan</h3>
    <span class="muted"><?= num(count($daftar)) ?> terakhir</span></div>
  <div class="table-wrap ai-scrollbox" style="max-height:52vh"><table class="tbl">
    <thead><tr><th>#</th><th>Permintaan</th><th>Jenis</th><th>Penyedia</th><th>Uji</th><th>Status</th><th>Waktu</th><th></th></tr></thead>
    <tbody>
    <?php if (!$daftar): ?>
      <tr><td colspan="8" class="center muted">Belum ada permintaan. Tulis permintaan pertama di atas.</td></tr>
    <?php endif; ?>
    <?php foreach ($daftar as $d): [$dlbl, $dtone] = ai_status_label((string)$d['status']); ?>
      <tr>
        <td><?= (int)$d['id'] ?></td>
        <td><?= e(short_text((string)$d['request'], 90)) ?></td>
        <td class="small"><?= e((string)($d['intent'] ?: '—')) ?>
          <?php if ((string)($d['audit_status'] ?? '') === 'belum_lengkap'): ?>
            <div class="muted">audit belum lengkap</div><?php endif; ?></td>
        <td class="small"><?= e((string)$d['provider']) ?><div class="muted"><?= e((string)$d['model']) ?></div></td>
        <td class="small"><?= e((string)($d['suite'] ?: '-')) ?>
          <?php if ((string)$d['test_status'] !== ''): ?><div class="muted"><?= e((string)$d['test_status']) ?></div><?php endif; ?></td>
        <td><?= badge($dlbl, $dtone) ?></td>
        <td class="small muted"><?= e(tgl((string)$d['created_at'], true)) ?></td>
        <td class="nowrap">
          <?php /* Tombol "Hapus" di sebelah KANAN tombol "Buka" (permintaan pemilik) supaya
                   riwayat permintaan dapat dirapikan dari daftar ini tanpa harus membuka
                   tiap tugas. Aksi `hapus` sudah ada & memakai pengaman yang sama seperti
                   di halaman rincian: konfirmasi, CSRF, dan PENOLAKAN bila perubahannya
                   sudah diterapkan (harus "Batalkan" lebih dulu) — jadi tombolnya tidak
                   dirender untuk tugas berstatus `applied` agar tidak menyesatkan. */ ?>
          <div class="flex gap-sm" style="align-items:center">
            <a class="btn btn-sm" href="ai_developer.php?id=<?= (int)$d['id'] ?>">Buka</a>
            <?php if ((string)$d['status'] !== 'applied'): ?>
              <form method="post" style="display:inline"
                    data-confirm="Hapus riwayat permintaan #<?= (int)$d['id'] ?>? Riwayat, langkah proses, percakapan, jejak kerja, dan lampirannya ikut dihapus.">
                <?= csrf_field() ?><input type="hidden" name="action" value="hapus">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit" title="Hapus riwayat permintaan ini">Hapus</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card" id="percakapan-terakhir">
  <div class="card-head"><h3>Percakapan Terakhir</h3>
    <span class="muted"><?= num(count($riwayat)) ?> permintaan terbaru</span></div>
  <div class="card-body">
    <?php if (!$riwayat): ?>
      <p class="muted">Belum ada percakapan. Tulis perintah pertama Anda di kotak di atas.</p>
    <?php endif; ?>
    <?php foreach ($riwayat as $r): ?>
      <?php [$rlbl, $rtone] = ai_status_label((string)$r['status']); ?>
      <div class="flex gap-sm mt-2" style="align-items:flex-start">
        <div class="avatar" style="flex:0 0 34px">A</div>
        <div style="flex:1;min-width:0">
          <div class="notice" style="margin:0">
            <div class="small muted">Anda · <?= e(tgl((string)$r['created_at'], true)) ?></div>
            <div><a href="ai_developer.php?id=<?= (int)$r['id'] ?>"><?= e(short_text((string)$r['request'], 160)) ?></a></div>
          </div>
          <div class="flex gap-sm mt-1 flex-wrap" style="align-items:center">
            <?= badge($rlbl, $rtone) ?>
            <?php if ((int)($r['tokens_total'] ?? 0) > 0): ?>
              <span class="muted small"><?= num((int)$r['tokens_total']) ?> token</span>
            <?php endif; ?>
            <?php if ((string)($r['suite'] ?? '') !== '' && (string)$r['suite'] !== 'auto'): ?>
              <span class="muted small">uji: <?= e((string)$r['suite']) ?></span>
            <?php endif; ?>
            <a class="btn btn-sm" href="ai_developer.php?id=<?= (int)$r['id'] ?>">Buka</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card" id="panduan">
  <div class="card-head">
    <h3>Panduan: Arti Pilihan &amp; Tombol di Halaman Ini</h3>
    <span><?= badge(num(count($panduanSuite)) . ' suite uji', 'blue') ?></span>
  </div>
  <div class="card-body">
    <p class="muted">Bacalah bagian ini sekali sebelum menulis permintaan — supaya permintaan Anda
      langsung tepat sasaran. Semua yang dilakukan AI di sini <strong>tidak langsung mengubah
      aplikasi</strong>: AI mengusulkan, menguji sendiri di salinan, Anda <strong>melihat pratinjau</strong>,
      lalu Anda yang menerapkan.</p>

    <div class="alert alert-info">
      <strong>Alurnya satu kartu saja — tidak ada tombol uji/penerapan yang harus Anda cari.</strong>
      Tulis perintah seperti mengobrol → AI menelusuri berkas → usulan disusun →
      <strong>uji di salinan dijalankan sendiri</strong> → muncul pesan berisi hasil uji + tombol
      <em>Cek pratinjau di sini</em>. Bila sudah cocok, ketik <code>lanjutkan dan terapkan</code>;
      bila belum, tulis perbaikannya di kotak chat yang sama. Daftar suite di bagian 3 hanya untuk
      Anda yang ingin memilih pemeriksaan sendiri.
    </div>

    <div class="section-title" style="margin-top:0">1. Bagian-bagian pada kartu "Chat dengan AI Developer"</div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th style="width:190px">Bagian</th><th>Keterangan / yang sebaiknya Anda isi</th></tr></thead>
        <tbody>
          <tr><td><strong>Permintaan Anda</strong></td>
            <td>Kalimat permintaan Anda dengan bahasa sehari-hari. Sebaiknya menyebut
              <strong>menu/halaman</strong>-nya (mis. "Data Pasien", "Order Baru"), <strong>apa yang
              sekarang terjadi</strong>, dan <strong>apa yang Anda inginkan</strong>. Contoh baik:
              <em>"Di halaman Data Pasien, tambahkan filter berdasarkan email"</em>. Boleh juga minta
              perbaikan: <em>"Perbaiki: tombol Simpan di Pengaturan tidak menyimpan kolom WhatsApp"</em>.</td></tr>
          <tr><td><strong>Lampiran (format apa pun)</strong></td>
            <td>Unggah <strong>Excel/CSV</strong>, <strong>PDF</strong>, <strong>gambar</strong> (screenshot/foto),
              atau dokumen <code>.docx/.pptx</code>. Isinya dibaca sebagai teks; <strong>gambar dan PDF juga
              dibaca langsung oleh model AI (Gemini)</strong>. Dipakai bila perintah Anda menyangkut data —
              mis. "ambil dari lampiran ini hanya nama, jenis kelamin, TTL, NIK, WA, email, alamat".</td></tr>
          <tr><td><strong>Periksa hasil dengan (opsional)</strong></td>
            <td>Program pemeriksa otomatis yang dijalankan di <strong>salinan aplikasi</strong> setelah AI
              selesai. Biarkan <strong>"Otomatis"</strong> bila Anda tidak yakin — sistem memilih suite dari
              isi perintah (mis. menyebut "struk" → <code>struk-whatsapp</code>). Daftar lengkap ada di
              bagian 3 di bawah.</td></tr>
          <tr><td><strong>Mode (opsional)</strong></td>
            <td>Biarkan <strong>Otomatis</strong> bila tidak yakin. Dengan mode otomatis, AI
              <strong>membedakan sendiri</strong>: pertanyaan, diskusi, permintaan saran, pemeriksaan/audit,
              dan permintaan rencana <em>dijawab tanpa mengubah kode</em>; permintaan pekerjaan langsung
              dikerjakan (disusun, diuji sendiri di salinan, lalu dimintakan persetujuan). Pilihan lain
              hanya bila Anda ingin memaksa, mis. <em>Kerjakan</em> untuk memastikan perubahannya dibuat,
              atau <em>Jawab saja</em> untuk sekadar penjelasan.</td></tr>
          <tr><td><strong>Ketik <code>kerjakan</code></strong></td>
            <td>Setelah AI menjawab/menyusun rencana, tulis <code>kerjakan</code> di kotak chat yang sama
              (ada tombol pintasnya). AI lalu menyusun perubahannya, menguji sendiri di salinan, dan
              menampilkan pratinjau untuk Anda periksa.</td></tr>
          <tr><td><strong>Jejak kerja AI</strong></td>
            <td>Ada di <em>Rincian teknis</em>: mencatat setiap fase (maksud yang dibaca, berkas yang
              dibaca/dilewati beserta alasannya, bagian lain yang ikut terdampak, perubahan, hasil uji).
              Berguna bila hasilnya kurang sesuai — Anda bisa melihat AI membaca apa saja.</td></tr>
          <tr><td><strong>Kirim ke AI</strong></td>
            <td>Tombol untuk memulai. AI menelusuri kode yang relevan lalu menyusun
              <em>usulan perubahan</em> (belum mengubah apa pun). Bila jawabannya terpotong atau tidak
              pas, sistem <strong>memperbaiki sendiri otomatis</strong> (sampai 3 putaran, lalu mencoba
              per berkas).</td></tr>
        </tbody>
      </table>
    </div>

    <div class="section-title">2. Bagian-bagian pada hasil (setelah AI selesai)</div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th style="width:190px">Bagian</th><th>Keterangan</th></tr></thead>
        <tbody>
          <tr><td><strong>Rencana AI</strong></td>
            <td>Penjelasan singkat dari AI tentang apa yang akan diubah dan mengapa.</td></tr>
          <tr><td><strong>Berkas yang akan diubah</strong></td>
            <td>Daftar berkas yang tersentuh. Berguna untuk menilai luasnya perubahan.</td></tr>
          <tr><td><strong>Pemeriksaan sintaks</strong></td>
            <td>Apakah berkas hasil perubahan masih "sehat" menurut pemeriksa bahasa
              (PHP/JS/CSS). GAGAL di sini = jangan diterapkan.</td></tr>
          <tr><td><strong>Pratinjau visual</strong></td>
            <td><strong>Lihat halaman aplikasi yang sudah memakai perubahan</strong>, sebelum diterapkan:
              tombol "Tampilkan pratinjau" membuka halaman itu di dalam kerangka, dan "Buka pratinjau &amp;
              pilih halaman" membuka di tab baru (bisa berpindah halaman). Dijalankan dari salinan, jadi
              aplikasi &amp; data asli belum berubah.</td></tr>
          <tr><td><strong>Pemakaian token</strong></td>
            <td>Berapa token yang dipakai permintaan itu (masuk/keluar/berpikir), per kegiatan, dilaporkan
              penyedia AI. Angka ini muncul juga di <a href="ai_settings.php#pemakaian">AI Settings</a>
              untuk seluruh pengerjaan.</td></tr>
          <tr><td><strong>Pratinjau perubahan (diff)</strong></td>
            <td>Perbedaan kode sebelum→sesudah. Baris <code>-</code> = dibuang, <code>+</code> = ditambahkan.
              <strong>Baca ini</strong> sebelum menyetujui.</td></tr>
          <tr><td><strong>Perbaikan otomatis (auto recovery)</strong></td>
            <td>Bila uji menemukan error, AI <strong>tidak menyerahkan perbaikannya ke Anda</strong>:
              ia menganalisis akar masalah, memperbaikinya, lalu <strong>menguji ulang</strong> —
              berulang sampai bersih (maksimal sesuai setelan). AI dilarang memakai jalan pintas
              seperti menghapus/melemahkan pemeriksaan uji (<em>tidak ada PASS palsu</em>) dan
              <strong>berhenti meminta keputusan Anda</strong> (status <em>BLOCKED</em>) bila error-nya
              butuh kredensial, keputusan bisnis, atau penghapusan data. Semua langkahnya tercatat di
              <em>Jejak kerja AI</em> + Audit Log.</td></tr>
          <tr><td><strong>Uji otomatis di salinan</strong></td>
            <td>Begitu usulan selesai, sistem <strong>menjalankan sendiri</strong> suite uji pada
              <strong>salinan aplikasi</strong> (data &amp; aplikasi terbit tidak tersentuh) — Anda
              <strong>tidak perlu menekan tombol apa pun</strong>. Hasilnya muncul sebagai pesan di
              percakapan ("LULUS" / "belum lulus" beserta alasannya). Bila ingin mengulang dengan
              suite lain, buka <em>Rincian teknis → Hasil uji</em> lalu tekan <em>Uji ulang</em>.</td></tr>
          <tr><td><strong>Cek pratinjau di sini</strong></td>
            <td>Membuka halaman aplikasi yang <strong>sudah memakai perubahan</strong> di dalam kartu ini
              (atau di tab baru) dan langsung menggulir ke bagian yang diubah. Dijalankan dari salinan,
              jadi aplikasi &amp; data asli belum berubah.</td></tr>
          <tr><td><strong>Kotak chat — "lanjutkan dan terapkan"</strong></td>
            <td>Kalau hasilnya sudah cocok, tulis <code>lanjutkan dan terapkan</code> di kotak chat
              (isi juga kolom <strong>Kata sandi</strong>). Perubahan langsung diterapkan ke aplikasi,
              dengan salinan pengaman berkas + catatan Audit Log. Tombol pintas
              <em>"lanjutkan dan terapkan"</em> di atas kotak mengisikannya untuk Anda.</td></tr>
          <tr><td><strong>Kotak chat — perbaikan lanjutan</strong></td>
            <td>Kalau belum cocok, tulis saja apa yang kurang di kotak chat yang sama
              (mis. "icon-nya masih tertukar"). AI mengingat percakapan ini, memperbaiki, lalu
              <strong>menguji ulang sendiri</strong> dan mengirim pesan baru.</td></tr>
          <tr><td><strong>Rincian teknis</strong></td>
            <td>Bagian yang bisa dibuka-tutup di kartu yang sama: rencana AI, berkas yang diubah,
              pemeriksaan sintaks, perbedaan kode (diff), hasil uji, pemakaian token, lampiran,
              serta tombol <strong>Tolak Usulan</strong> dan <strong>Hapus Riwayat</strong>.</td></tr>
          <tr><td><strong>Batalkan</strong></td>
            <td>Mengembalikan berkas seperti sebelum perubahan diterapkan (bila ternyata ada masalah
              setelah diperiksa di aplikasi).</td></tr>
          <tr><td><strong>Riwayat Permintaan &amp; Percakapan Terakhir</strong></td>
            <td>Permintaan yang sudah selesai tersimpan di sana beserta statusnya — bisa dibuka lagi
              kapan saja.</td></tr>
        </tbody>
      </table>
    </div>

    <div class="section-title">3. Arti setiap pilihan "Suite uji"
      <span class="muted small">— <?= num($jumlahRingan) ?> ringan · <?= num($jumlahSedang) ?> sedang ·
        <?= num($jumlahBerat) ?> berat (semakin "berat", makin lama tapi makin luas yang diperiksa)</span>
    </div>
    <div class="field" style="max-width:420px">
      <label>Cari suite (ketik nama atau kata kunci, mis. "struk", "keuangan", "hp")</label>
      <input class="input" id="suiteCari" type="text" placeholder="mis. struk / keuangan / responsif"
             autocomplete="off">
    </div>
    <div class="table-wrap scroll-y" style="max-height:520px">
      <table class="tbl" id="suiteTabel">
        <thead><tr><th style="width:230px">Nama suite</th><th style="width:110px">Bobot</th>
          <th>Keterangan (apa yang diperiksa)</th></tr></thead>
        <tbody>
        <?php foreach ($panduanSuite as $s): ?>
          <?php
          $tone = $s['berat'] === 'ringan' ? 'green' : ($s['berat'] === 'berat' ? 'red' : 'yellow');
          $teksCari = strtolower($s['nama'] . ' ' . $s['berat'] . ' ' . $s['ket'] . ' ' . $s['berkas']);
          ?>
          <tr data-suite-cari="<?= e($teksCari) ?>">
            <td><code><?= e($s['nama']) ?></code><?php if ($s['berkas'] !== ''): ?>
              <div class="muted small"><?= e($s['berkas']) ?></div><?php endif; ?></td>
            <td><?= badge($s['berat'], $tone) ?></td>
            <td class="small"><?= e($s['ket']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$panduanSuite): ?>
          <tr><td colspan="3" class="center muted">Daftar suite tidak terbaca dari
            <code>naveena_dev/test/run_all.sh</code>.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="muted small" id="suiteHasil"></p>
    <div class="notice small mt-2">
      <strong>Tips menulis permintaan yang berhasil:</strong> sebut nama menu/halaman, tulis apa yang
      diharapkan, dan bila mungkin sebutkan istilah yang muncul di layar (mis. nama kolom atau tombol).
      Bila hasilnya kata AI "tidak ada perubahan", tulis ulang permintaan dengan lebih spesifik — bukan
      berarti aplikasinya rusak.
    </div>
  </div>
</div>
<script>
/* Penyaring daftar suite: menyembunyikan baris yang tidak cocok dengan kata kunci. */
(function () {
  var kotak = document.getElementById('suiteCari');
  var tabel = document.getElementById('suiteTabel');
  var hasil = document.getElementById('suiteHasil');
  if (!kotak || !tabel) return;
  var baris = Array.prototype.slice.call(tabel.querySelectorAll('tbody tr[data-suite-cari]'));
  function saring() {
    var q = (kotak.value || '').toLowerCase().trim();
    var tampil = 0;
    baris.forEach(function (tr) {
      var cocok = q === '' || (tr.getAttribute('data-suite-cari') || '').indexOf(q) !== -1;
      tr.style.display = cocok ? '' : 'none';
      if (cocok) tampil++;
    });
    if (hasil) {
      hasil.textContent = q === '' ? ('Menampilkan seluruh ' + baris.length + ' suite uji.')
        : ('Menampilkan ' + tampil + ' dari ' + baris.length + ' suite untuk kata kunci "' + kotak.value + '".');
    }
  }
  kotak.addEventListener('input', saring);
  saring();
})();
</script>

<?php page_foot(); ?>
