<?php
/**
 * JOB ENGINE AI DEVELOPER (ronde 53)
 * =================================
 * Permintaan pemilik (PDF Master Upgrade): pekerjaan berat AI harus berjalan sebagai
 * JOB di latar belakang dengan jejak yang dapat dipulihkan — bukan request browser
 * yang menahan halaman.
 *
 * Yang disediakan berkas ini:
 *   • identitas job (job_id), status workflow resmi, current_step, progress NYATA
 *   • heartbeat  → mendeteksi worker mati/stuck (stalled/timeout)
 *   • checkpoint → resume dari langkah terakhir yang selesai, bukan mengulang semua
 *   • retry      → percobaan ulang terbatas pada langkah yang gagal
 *   • cancel     → pemilik dapat menghentikan job & UI melihat worker benar berhenti
 *
 * SATU SUMBER STATUS: seluruh tampilan (UI, titik AJAX) membaca status dari sini,
 * dan status job diturunkan dari status tugas (`ai_tasks.status`) agar tidak mungkin
 * ada kondisi mesin AUDIT_INCOMPLETE sementara layar menampilkan "tidak ada perubahan".
 */
require_once __DIR__ . '/ai.php';

/** Status job resmi (spesifikasi PDF) → label + tone untuk tampilan. */
function ai_job_status_map(): array
{
    return [
        'QUEUED'           => ['Menunggu dijalankan', 'gray'],
        'DISCOVERING'      => ['Discovery repositori', 'yellow'],
        'AUDITING'         => ['Audit konteks & bukti', 'yellow'],
        'AUDIT_INCOMPLETE' => ['Audit belum lengkap', 'yellow'],
        'PLAN_READY'       => ['Rencana siap', 'blue'],
        'IMPLEMENTING'     => ['Menyusun perubahan', 'yellow'],
        'TESTING'          => ['Menguji di salinan', 'yellow'],
        'REGRESSION'       => ['Regresi', 'yellow'],
        'FINAL_AUDIT'      => ['Pemeriksaan akhir', 'yellow'],
        'READY_TO_APPLY'   => ['Siap diterapkan (menunggu TERAPKAN)', 'blue'],
        'APPLYING'         => ['Menerapkan ke aplikasi', 'yellow'],
        'COMPLETED'        => ['Selesai', 'green'],
        'NO_CHANGE'        => ['Tidak ada perubahan diperlukan', 'blue'],
        'BLOCKED'          => ['Terhenti — butuh keputusan Anda', 'yellow'],
        'FAILED'           => ['Gagal', 'red'],
        'CANCELLED'        => ['Dihentikan oleh Anda', 'gray'],
        'STALLED'          => ['Worker berhenti merespons (stalled)', 'red'],
    ];
}

/** Ubah status tugas (ai_tasks) menjadi status JOB resmi — SATU pemetaan. */
function ai_job_status_from_task(string $status, string $workflow = ''): string
{
    $w = strtoupper(trim($workflow));
    $peta = [
        'DRAFT' => 'QUEUED', 'IMPLEMENTING' => 'IMPLEMENTING', 'HEALING' => 'TESTING',
        'TESTING' => 'TESTING', 'TESTED' => 'READY_TO_APPLY', 'WAITING_APPROVAL' => 'READY_TO_APPLY',
        'ANSWERED' => 'COMPLETED', 'ANALYSIS_COMPLETE' => 'COMPLETED', 'PLAN_READY' => 'PLAN_READY',
        'AUDIT_INCOMPLETE' => 'AUDIT_INCOMPLETE', 'NO_CHANGE' => 'NO_CHANGE', 'COMPLETED' => 'COMPLETED',
        'BLOCKED' => 'BLOCKED', 'FAILED' => 'FAILED', 'REJECTED' => 'CANCELLED', 'ROLLEDBACK' => 'CANCELLED',
    ];
    if ($w !== '' && isset($peta[$w])) return $peta[$w];
    $s = strtolower(trim($status));
    $peta2 = [
        'draft' => 'QUEUED', 'analyzing' => 'DISCOVERING', 'proposed' => 'READY_TO_APPLY',
        'testing' => 'TESTING', 'tested' => 'READY_TO_APPLY', 'applied' => 'COMPLETED',
        'noop' => 'NO_CHANGE', 'answered' => 'COMPLETED', 'analyzed' => 'COMPLETED',
        'planned' => 'PLAN_READY', 'audit_incomplete' => 'AUDIT_INCOMPLETE',
        'blocked' => 'BLOCKED', 'failed' => 'FAILED', 'rejected' => 'CANCELLED',
        'rolledback' => 'CANCELLED', 'cancelled' => 'CANCELLED',
    ];
    return $peta2[$s] ?? 'QUEUED';
}

/** Langkah BAKU sebuah pekerjaan (dipakai checklist progres di UI). */
function ai_job_steps_template(): array
{
    return [
        'discovery'   => 'Discovery repositori',
        'audit'       => 'Audit konteks & bukti berkas',
        'dependency'  => 'Analisis dependency & dampak',
        'plan'        => 'Rencana perubahan',
        'implement'   => 'Menyusun perubahan (patch)',
        'test'        => 'Testing di salinan',
        'regression'  => 'Regresi',
        'final_audit' => 'Final audit',
    ];
}

/** Buat job baru untuk sebuah tugas (atau kembalikan job aktif yang ada). */
function ai_job_begin(int $taskId, string $type = 'pipeline', array $opts = []): array
{
    $ada = ai_job_active($taskId);
    if ($ada) return $ada;
    $jobId = 'JOB-' . $taskId . '-' . strtoupper(bin2hex(random_bytes(3)));
    /* INSERT minimal lalu lengkapi lewat ai_job_update(): cara ini menghindari
       kesalahan urutan parameter pada daftar kolom yang panjang (bug yang langsung
       terlihat saat uji: langkah/progres tertukar sehingga progres tampak 50%
       padahal baru 1 dari 8 langkah). */
    q('INSERT INTO ai_jobs (task_id, job_id, type, status, current_step, progress, heartbeat,
            started_at, updated_at) VALUES (?,?,?,?,?,?,datetime("now","localtime"),
            datetime("now","localtime"),datetime("now","localtime"))',
        [$taskId, $jobId, $type, 'QUEUED', '', 0]);
    $rowId = (int)db()->lastInsertId();
    ai_job_update($rowId, [
        'max_retry' => max(1, (int)setting('ai_job_max_retry', '2')),
        'checkpoint' => json_encode(['next_mode' => 'plan'], JSON_UNESCAPED_UNICODE),
        'steps_json' => json_encode(ai_job_steps_template(), JSON_UNESCAPED_UNICODE),
        'worker_pid' => getmypid(),
    ]);
    return ai_job_get($rowId) ?? [];
}

/** Job AKTIF (belum selesai) milik sebuah tugas. */
function ai_job_active(int $taskId): ?array
{
    $j = one('SELECT * FROM ai_jobs WHERE task_id = ? AND status NOT IN
              ("COMPLETED","FAILED","BLOCKED","CANCELLED","NO_CHANGE","AUDIT_INCOMPLETE")
              ORDER BY id DESC LIMIT 1', [$taskId]);
    if (!$j) return null;
    /* WAJIB lewat ai_job_get(): kolom steps_json perlu di-decode lebih dulu, kalau tidak
       ai_job_beat() menghitung progres dari daftar langkah yang kosong (bug yang
       langsung terlihat saat uji: discovery selesai tetapi progres tetap 0%). */
    return ai_job_get((int)$j['id']);
}

/** Ambil satu job berdasarkan id barisnya. */
function ai_job_get(int $id): ?array
{
    $j = one('SELECT * FROM ai_jobs WHERE id = ?', [$id]);
    if (!$j) return null;
    $j['steps'] = json_decode((string)($j['steps_json'] ?? '{}'), true) ?: [];
    return $j;
}

/** Job terbaru milik sebuah tugas (aktif atau tidak) — untuk tampilan. */
function ai_job_latest(int $taskId): ?array
{
    $j = one('SELECT * FROM ai_jobs WHERE task_id = ? ORDER BY id DESC LIMIT 1', [$taskId]);
    if (!$j) return null;
    $j['steps'] = json_decode((string)($j['steps_json'] ?? '{}'), true) ?: [];
    return $j;
}

/**
 * Heartbeat + kemajuan (dipanggil worker di setiap tahap).
 * `$step` memakai kunci dari ai_job_steps_template(); `$state` = running|done|failed|skipped.
 */
function ai_job_beat(int $taskId, string $step = '', string $state = 'running', array $extra = []): void
{
    $j = ai_job_active($taskId);
    if (!$j) return;
    $steps = (array)$j['steps'];
    if ($step !== '') {
        $steps[$step] = $state;
        /* Langkah sesudahnya tetap pending; yang sebelumnya dianggap selesai bila
           state-nya masih running (urutan maju). */
    }
    $selesai = count(array_filter($steps, fn($v) => in_array($v, ['done', 'skipped'], true)));
    $progress = count($steps) > 0 ? (int)round($selesai / count($steps) * 100) : 0;
    $set = [
        'steps_json' => json_encode($steps, JSON_UNESCAPED_UNICODE),
        'heartbeat' => date('Y-m-d H:i:s'),
        'progress' => min(99, $progress),
        'worker_pid' => getmypid(),
    ];
    if ($step !== '') $set['current_step'] = $step;
    if (!empty($extra['status'])) $set['status'] = (string)$extra['status'];
    if (!empty($extra['error'])) $set['error'] = (string)$extra['error'];
    if (isset($extra['files_found'])) $set['files_found'] = (int)$extra['files_found'];
    if (isset($extra['files_read'])) $set['files_read'] = (int)$extra['files_read'];
    if (isset($extra['files_skipped'])) $set['files_skipped'] = (int)$extra['files_skipped'];
    if (isset($extra['note'])) $set['note'] = short_text((string)$extra['note'], 300);
    ai_job_update((int)$j['id'], $set);
}

/** Simpan titik resume (checkpoint) supaya pekerjaan dapat dilanjutkan. */
function ai_job_checkpoint(int $taskId, array $data): void
{
    $j = ai_job_active($taskId);
    if (!$j) return;
    ai_job_update((int)$j['id'], [
        'checkpoint' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'heartbeat' => date('Y-m-d H:i:s'),
    ]);
}

/** Tandai job selesai/gagal beserta hasil akhirnya. */
function ai_job_finish(int $taskId, string $status, string $error = '', array $extra = []): void
{
    $j = ai_job_active($taskId);
    if (!$j) return;
    $set = [
        'status' => $status,
        'heartbeat' => date('Y-m-d H:i:s'),
        'error' => short_text($error, 600),
        'finished_at' => date('Y-m-d H:i:s'),
        'progress' => in_array($status, ['COMPLETED', 'NO_CHANGE', 'READY_TO_APPLY'], true) ? 100 : (int)($j['progress'] ?? 0),
    ];
    if (!empty($extra['steps'])) $set['steps_json'] = json_encode($extra['steps'], JSON_UNESCAPED_UNICODE);
    if (!empty($extra['note'])) $set['note'] = short_text((string)$extra['note'], 300);
    ai_job_update((int)$j['id'], $set);
}

/** Ubah kolom sebuah job (dipakai internal). */
function ai_job_update(int $jobRowId, array $data): void
{
    if (!$data) return;
    $data['updated_at'] = date('Y-m-d H:i:s');
    $set = []; $val = [];
    foreach ($data as $k => $v) { $set[] = $k . ' = ?'; $val[] = $v; }
    $val[] = $jobRowId;
    try { q('UPDATE ai_jobs SET ' . implode(', ', $set) . ' WHERE id = ?', $val); } catch (Throwable $e) { /* abaikan */ }
}

/** Permintaan BERHENTI dari pemilik (tombol Stop). */
function ai_job_request_cancel(int $taskId): bool
{
    $j = ai_job_active($taskId);
    if (!$j) return false;
    ai_job_update((int)$j['id'], ['cancel_requested' => 1, 'note' => 'Permintaan berhenti diterima — worker menutup pekerjaan']);
    return true;
}

/** Apakah pemilik meminta berhenti? (diperiksa worker di dalam loop) */
function ai_job_cancelled(int $taskId): bool
{
    $j = ai_job_active($taskId);
    return $j ? ((int)$j['cancel_requested'] === 1) : false;
}

/**
 * Deteksi job yang STALLED (worker berhenti merespons) dan, bila masih aman,
 * jalankan ulang pekerjaan dari checkpoint (retry terbatas).
 *
 * Dipanggil dari titik AJAX status (tanpa perlu cron): halaman yang sedang dibuka
 * pemilik yang menyalakan pemulihan, dan keadaannya tetap tersimpan di basis data
 * sehingga refresh browser tidak menghilangkannya.
 */
function ai_job_recover_stalled(int $taskId): array
{
    $j = ai_job_latest($taskId);
    if (!$j) return ['aksi' => 'tidak-ada-job'];
    if (in_array((string)$j['status'], ['COMPLETED', 'FAILED', 'BLOCKED', 'CANCELLED', 'NO_CHANGE',
            'AUDIT_INCOMPLETE', 'READY_TO_APPLY'], true)) {
        return ['aksi' => 'selesai'];
    }
    $detik = max(30, (int)setting('ai_job_stall_seconds', '900'));
    $umur = time() - strtotime((string)$j['heartbeat']);
    if ($umur <= $detik) return ['aksi' => 'hidup', 'umur' => $umur];

    /* Worker berhenti merespons → tandai stalled & coba lanjutkan. */
    ai_job_update((int)$j['id'], ['status' => 'STALLED', 'note' => 'Worker tidak mengirim heartbeat selama '
        . round($umur / 60) . ' menit — mencoba melanjutkan dari checkpoint']);
    ai_trace($taskId, 'job-stalled', ['job_id' => (string)$j['job_id'], 'umur_detik' => $umur,
        'checkpoint' => (string)$j['checkpoint']]);
    $retry = (int)$j['retry_count'];
    if ($retry >= (int)$j['max_retry']) {
        ai_task_update($taskId, ['status' => 'failed', 'stage' => '',
            'error' => 'Pekerjaan berhenti merespons dan batas percobaan ulang tercapai. '
                . 'Tekan "Jalankan ulang" atau kirim perintah lagi di kotak chat.']);
        ai_job_update((int)$j['id'], ['status' => 'FAILED']);
        return ['aksi' => 'gagal-final', 'retry' => $retry];
    }
    /* Lanjutkan: pekerjaan dijalankan ulang dari tahap terakhir yang tercatat —
       pekerja akan membaca checkpoint dan MELEWATI tahap yang sudah selesai. */
    ai_job_update((int)$j['id'], ['retry_count' => $retry + 1, 'heartbeat' => date('Y-m-d H:i:s'),
        'note' => 'Melanjutkan dari checkpoint (percobaan ' . ($retry + 1) . '/' . (int)$j['max_retry'] . ')']);
    $cp = json_decode((string)$j['checkpoint'], true) ?: [];
    $mode = (string)($cp['next_mode'] ?? 'plan');
    if (!in_array($mode, ['plan', 'test'], true)) $mode = 'plan';
    ai_spawn_worker($mode, $taskId);
    ai_step($taskId, 'warn', 'Pekerjaan dilanjutkan otomatis dari checkpoint',
        'Tahap terakhir: ' . (string)($j['current_step'] ?? '-') . ' · mode ' . $mode);
    return ['aksi' => 'dilanjutkan', 'mode' => $mode, 'retry' => $retry + 1];
}

/**
 * Data job siap-tampil (dipakai UI & titik AJAX) — SATU sumber.
 *
 * @return array{ada:bool,job_id:string,status:string,label:string,tone:string,progress:int,
 *   current_step:string,steps:array,langkah:array,heartbeat:string,umur:int,retry:int,max_retry:int,
 *   note:string,error:string,cancel:bool,berjalan:bool,checkpoint:array}
 */
function ai_job_view(int $taskId): array
{
    $t = ai_task($taskId);
    $j = ai_job_latest($taskId);
    $tmpl = ai_job_steps_template();
    $statusJob = $t ? ai_job_status_from_task((string)$t['status'], (string)($t['workflow'] ?? '')) : 'QUEUED';
    /* Selama pekerjaan BERJALAN, status job (yang ditulis worker) lebih tepat daripada
       status tugas yang masih 'draft/analyzing'. Saat final, status TUGAS yang menang —
       sehingga aturan AUDIT_INCOMPLETE/NO_CHANGE tetap berasal dari satu sumber. */
    $finalTask = ['COMPLETED', 'FAILED', 'BLOCKED', 'CANCELLED', 'NO_CHANGE', 'AUDIT_INCOMPLETE', 'READY_TO_APPLY'];
    if ($j && !in_array($statusJob, $finalTask, true)
        && !in_array((string)$j['status'], ['QUEUED', ''], true)
        && isset(ai_job_status_map()[(string)$j['status']])) {
        $statusJob = (string)$j['status'];
    }
    $steps = $j ? (array)$j['steps'] : [];
    $langkah = [];
    foreach ($tmpl as $k => $nama) {
        $st = (string)($steps[$k] ?? 'pending');
        $langkah[] = ['kunci' => $k, 'nama' => $nama, 'status' => $st,
            'ikon' => $st === 'done' ? '✓' : ($st === 'failed' ? '✕' : ($st === 'running' ? '●' : '○'))];
    }
    $selesai = count(array_filter($steps, fn($v) => in_array($v, ['done', 'skipped'], true)));
    $progress = $j ? (int)$j['progress'] : 0;
    /* Bila job sudah final, progres = 100 (kecuali gagal/diblokir). */
    if (in_array($statusJob, ['COMPLETED', 'NO_CHANGE', 'READY_TO_APPLY'], true)) $progress = 100;
    $map = ai_job_status_map();
    [$label, $tone] = $map[$statusJob] ?? [$statusJob, 'gray'];
    $umur = $j ? max(0, time() - strtotime((string)$j['heartbeat'])) : 0;
    $berjalan = in_array($statusJob, ['QUEUED', 'DISCOVERING', 'AUDITING', 'IMPLEMENTING', 'TESTING',
        'REGRESSION', 'FINAL_AUDIT', 'APPLYING', 'STALLED'], true);
    return [
        'ada' => (bool)$j,
        'job_id' => (string)($j['job_id'] ?? ''),
        'status' => $statusJob, 'label' => $label, 'tone' => $tone,
        'progress' => $progress, 'current_step' => (string)($j['current_step'] ?? ''),
        'current_step_label' => (string)($tmpl[(string)($j['current_step'] ?? '')] ?? ''),
        'steps' => $steps, 'langkah' => $langkah,
        'selesai' => $selesai, 'total' => count($tmpl),
        'heartbeat' => (string)($j['heartbeat'] ?? ''), 'umur' => $umur,
        'retry' => (int)($j['retry_count'] ?? 0), 'max_retry' => (int)($j['max_retry'] ?? 2),
        'note' => (string)($j['note'] ?? ''), 'error' => (string)($j['error'] ?? ''),
        'cancel' => (bool)($j['cancel_requested'] ?? false),
        'berjalan' => $berjalan,
        'checkpoint' => json_decode((string)($j['checkpoint'] ?? ''), true) ?: [],
        'files_found' => (int)($j['files_found'] ?? 0),
        'files_read' => (int)($j['files_read'] ?? 0),
        'files_skipped' => (int)($j['files_skipped'] ?? 0),
        'started_at' => (string)($j['started_at'] ?? ''),
        'finished_at' => (string)($j['finished_at'] ?? ''),
    ];
}

/** Ringkasan satu baris untuk percakapan ("Job #… · IMPLEMENTING · 45%"). */
function ai_job_summary_line(array $v): string
{
    if (empty($v['ada'])) return '';
    return 'Job ' . $v['job_id'] . ' · ' . $v['status'] . ' · ' . (int)$v['progress'] . '%'
        . ($v['current_step_label'] !== '' ? ' — ' . $v['current_step_label'] : '');
}
