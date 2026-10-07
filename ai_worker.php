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

/* JOB ENGINE (ronde 53): pekerjaan didaftarkan sebagai JOB dengan identitas, heartbeat
   dan checkpoint supaya dapat dipantau, dilanjutkan, atau dihentikan. */
ai_job_begin($id, $mode === 'test' ? 'test' : 'pipeline');
ai_job_beat($id, '', 'running', ['status' => $mode === 'test' ? 'TESTING' : 'DISCOVERING']);

/* SATU pekerja per tugas per mode (ronde 49). Tanpa ini, dua suite berjalan pada
   salinan yang sama; yang satu selesai lebih dulu membersihkan berkas salinan
   sehingga yang lain berhenti di tengah dan dilaporkan gagal. */
$kunciPekerja = null;
if (!ai_job_lock($id, $mode, $kunciPekerja)) {
    fwrite(STDERR, "Pekerja $mode untuk tugas #$id sudah berjalan — yang baru dibatalkan.\n");
    exit(0);
}
register_shutdown_function(function () use ($id, $mode, &$kunciPekerja) {
    ai_job_unlock($id, $mode, $kunciPekerja);
});

/**
 * Tulis keterangan langkah berjalan. RONDE 45: setiap keterangan juga disimpan
 * sebagai LANGKAH (`ai_steps`) supaya pemilik dapat melihat prosesnya bertahap
 * — "sedang membaca berkas", "sedang menyusun patch", "sedang diuji" — seperti
 * percakapan dengan agen.
 */
$setStage = function (string $s, string $kind = 'work', string $detail = '') use ($id): void {
    ai_task_update($id, ['stage' => $s, 'error' => '']);
    ai_step($id, $kind, $s, $detail);
    /* JOB ENGINE (ronde 53): setiap tahap menjadi HEARTBEAT + kemajuan NYATA pada job,
       sehingga UI dapat menampilkan proses tanpa memuat ulang halaman. */
    $langkah = ai_job_step_for_stage($s);
    ai_job_beat($id, $langkah, 'running', ['note' => $s]);
    /* Tombol BERHENTI pemilik: diperiksa di setiap tahap supaya worker benar-benar
       berhenti (bukan hanya status di layar). */
    if (ai_job_cancelled($id)) {
        ai_task_update($id, ['status' => 'cancelled', 'stage' => 'Dihentikan oleh pemilik',
            'workflow' => 'REJECTED', 'error' => '']);
        ai_msg($id, 'ai', '⏹ **Pekerjaan dihentikan atas permintaan Anda.** Tidak ada penerapan '
            . 'yang dijalankan. Tulis perintah baru di kotak chat bila ingin memulai lagi.');
        ai_step($id, 'warn', 'Pekerjaan dihentikan oleh pemilik');
        ai_job_finish($id, 'CANCELLED', 'Dihentikan oleh pemilik');
        ai_trace($id, 'job-cancelled', ['tahap' => $s]);
        exit(0);
    }
};

/**
 * Petakan teks tahap → kunci langkah baku job (ai_job_steps_template()).
 * Dipakai untuk menghitung progres NYATA dari pekerjaan yang benar-benar selesai.
 */
function ai_job_step_for_stage(string $s): string
{
    $t = strtolower($s);
    $peta = [
        'discovery' => ['membaca daftar berkas', 'memilih berkas', 'menyusuri berkas', 'membaca isi'],
        'audit' => ['standar audit', 'bukti audit', 'pembacaan', 'audit'],
        'dependency' => ['dampak', 'dependency', 'menelusuri bagian lain'],
        'plan' => ['menyusun usulan', 'rencana', 'arsitek', 'jawab', 'menjawab', 'memeriksa kode'],
        'implement' => ['menerapkan', 'menyiapkan pratinjau', 'perbaikan'],
        'test' => ['uji', 'staging', 'menjalankan suite'],
        'regression' => ['regresi', 'regression'],
        'final_audit' => ['pemeriksaan akhir', 'final'],
    ];
    foreach ($peta as $kunci => $kata) {
        foreach ($kata as $k) {
            if (strpos($t, $k) !== false) return $kunci;
        }
    }
    return '';
}

if ($mode === 'plan') {
    ai_step($id, 'info', 'Permintaan diterima — AI mulai bekerja',
        'Model pelaksana: ' . ai_executor_text() . (ai_architect_ready() ? ' · ' . ai_architect_text() : ''));
    /* ==========================================================================
     * 0. KLASIFIKASI MAKSUD (ronde 50)
     * Permintaan pemilik (V2.3 bagian 1): AI Developer harus bisa diajak ngobrol —
     * membedakan SEDANG BERTANYA / BERDISKUSI / MINTA SARAN / MINTA DIPERIKSA /
     * MINTA RENCANA dari MINTA DIKERJAKAN — sehingga percakapan biasa tidak
     * langsung mengubah berkas, tetapi permintaan pekerjaan tetap dikerjakan
     * sampai selesai (bukan berhenti di rencana).
     * ======================================================================== */
    $riwayatAwal = ai_conversation_text($id);
    $intent = ai_intent_classify((string)$task['request'], $riwayatAwal);
    /* Pemilik (atau uji otomatis) dapat MEMAKSA mode lewat kolom `intent` tugas. */
    $paksa = trim((string)($task['intent_mode'] ?? ''));
    if (in_array($paksa, ['jawab', 'audit', 'rencana', 'kerjakan'], true)) {
        $intent['mode'] = $paksa;
        $intent['tindakan'] = ($paksa === 'kerjakan');
        $intent['alasan'] = 'mode dipilih pemilik: ' . $paksa;
    } elseif ($paksa === 'auto') {
        $intent['alasan'] .= ' (mode: otomatis)';
    }
    if (!ai_settings()['chat_mode']) {
        /* Mode obrolan dimatikan di AI Settings → semuanya dikerjakan seperti dulu. */
        $intent['mode'] = 'kerjakan';
        $intent['tindakan'] = true;
        $intent['alasan'] = 'mode obrolan dimatikan di AI Settings';
    }
    ai_task_update($id, [
        'intent' => $intent['kategori'], 'intent_note' => $intent['label'],
        'intent_mode' => $intent['mode'],
        'workflow' => ai_workflow_status('analyzing')['kode'],
    ]);
    ai_trace($id, 'klasifikasi', [
        'mode' => $intent['mode'], 'intent' => $intent['intent'], 'kategori' => $intent['kategori'],
        'tindakan' => (bool)$intent['tindakan'], 'alasan' => $intent['alasan'],
        'permintaan' => short_text((string)$task['request'], 300),
    ]);
    ai_step($id, 'info', 'Permintaan dibaca sebagai: ' . $intent['label'], $intent['alasan']);
    /* ---------- 0. Lampiran & brief Arsitek (ronde 44) ---------- */
    $attText = ai_attachment_text($id);
    $attList = ai_attachment_list($id);
    $attInline = ai_attachment_inline($id);
    ai_attachment_mark_sent($id, false);
    $engine = 'model utama saja';
    $brief = '';
    if (ai_architect_ready()) {
        $setStage('Arsitek (' . strtoupper(ai_settings()['arch_provider']) . ') menerjemahkan perintah');
        $errB = '';
        $brief = ai_architect_brief((string)$task['request'], $attText, $id, $errB);
        if ($brief !== '') {
            $engine = 'kolaborasi: arsitek (' . ai_settings()['arch_provider'] . ' ' . ai_settings()['arch_model']
                . ') + pelaksana (' . ai_settings()['provider'] . ' ' . ai_settings()['model'] . ')';
            ai_task_update($id, ['brief' => $brief, 'engine' => $engine]);
        } else {
            $engine = 'model utama saja (arsitek gagal: ' . short_text($errB, 120) . ')';
            ai_task_update($id, ['engine' => $engine]);
        }
    } else {
        ai_task_update($id, ['engine' => $engine]);
    }

    /* ---------- 0b. STANDAR AUDIT & KUOTA BERKAS ----------
       RONDE 52 (perbaikan file discovery): pekerjaan ber-area luas (architecture/database/
       migrasi/security/refactor) membutuhkan penelusuran berkas JAUH lebih banyak daripada
       perubahan kecil. Tanpa ini, AI kehabisan berkas sebelum dependency penting terbaca
       lalu berhenti sebagai AUDIT_INCOMPLETE padahal sistem masih mampu mengambilnya. */
    $auditArea = ai_audit_area((string)$task['request'], (string)($task['intent'] ?? ''));
    $maksBerkas = !empty($auditArea['luas'])
        ? max(ai_settings()['max_files'], (int)setting('ai_audit_max_files', '20'))
        : ai_settings()['max_files'];
    if (!empty($auditArea['luas'])) {
        ai_step($id, 'info', 'Standar audit diperluas: area ' . implode('/', $auditArea['areas']),
            'Bukti area wajib: ' . implode(', ', $auditArea['wajib'])
            . ' · kuota berkas dinaikkan menjadi ' . $maksBerkas);
    }

    /* ---------- 1. Pilih berkas relevan ---------- */
    ai_task_update($id, ['status' => 'analyzing', 'error' => '']);
    $setStage('Membaca daftar berkas');
    $daftar = ai_scope_files();
    if (!$daftar) {
        ai_task_update($id, ['status' => 'failed', 'error' => 'Tidak ada berkas pada cakupan AI Settings.', 'stage' => '']);
        exit(1);
    }

    $setStage('AI memilih berkas yang relevan');
    $permintaanUntukAi = (string)$task['request'];
    if ($brief !== '') $permintaanUntukAi .= "\n\nBRIEF ARSITEK (patuhi):\n" . $brief;
    $pick = ai_call_chain([['role' => 'user', 'text' => ai_pick_prompt($permintaanUntukAi, $daftar)]],
        0.0, 2048, ['task_id' => $id, 'purpose' => 'pilih berkas']);
    $pilih = [];
    if ($pick['ok']) {
        $j = ai_extract_json($pick['text']);
        foreach ((array)($j['files'] ?? []) as $f) {
            $f = trim((string)$f);
            if ($f !== '' && ai_path($f) !== null) $pilih[] = $f;
        }
    }
    /* BERKAS SASARAN = yang dipilih AI sendiri. Berkas yang hanya ikut lewat
       penelusuran kata kunci / penelusuran dampak dihitung sebagai PENDUKUNG, sehingga
       tidak membuat status audit menjadi "belum lengkap" hanya karena berkas
       pendukungnya besar (aturan jujur tapi tetap berguna, ronde 50). */
    $pilihAi = array_values(array_unique($pilih));
    /* Penelusuran kata kunci SELALU dijalankan dan digabung dengan pilihan AI —
       inilah yang membuat pemilik cukup menulis perintah biasa tanpa memilih menu:
       sistem menyusuri sendiri berkas yang menyebut kata-kata pentingnya. */
    $setStage('Menyusuri berkas lewat kata kunci dari permintaan');
    $kata = [];
    /* PENTING (perbaikan bug): kata kunci diambil dari PERMINTAAN SEKARANG + brief +
       lampiran — BUKAN dari riwayat percakapan. Sebelumnya riwayat ikut dihitung
       sehingga perintah lanjutan ("perbaiki urutan kartu") justru mencari kata kunci
       dari percakapan LAMA (nama menu lain) dan berkas sasarannya tidak terpilih. */
    $teksCari = (string)$task['request'] . ' ' . $brief . ' ' . $attText;
    if (preg_match_all('/[A-Za-z_]{4,}/', $teksCari, $m)) {
        $umum = ['untuk', 'dengan', 'yang', 'saya', 'pada', 'dari', 'tidak', 'agar', 'data', 'bisa',
                 'file', 'berkas', 'halaman', 'menambah', 'tambahkan', 'buatkan', 'perbaiki', 'ubah',
                 'kolom', 'tabel', 'nomor', 'nama', 'alamat', 'email', 'cabang', 'member', 'jika', 'ini', 'itu'];
        foreach (array_slice(array_unique($m[0]), 0, 40) as $w) {
            if (in_array(strtolower($w), $umum, true)) continue;
            $kata[] = $w;
            if (count($kata) >= 12) break;
        }
    }
    foreach ($kata as $w) {
        foreach (ai_search($w, 8) as $hit) {
            if (!in_array($hit['rel'], $pilih, true)) $pilih[] = $hit['rel'];
        }
        if (count($pilih) >= $maksBerkas * 2) break;
    }
    /* Nama berkas yang disebut LANGSUNG (permintaan/brief/riwayat) diletakkan paling
       depan supaya tidak terpotong oleh batas jumlah berkas. */
    $disebut = [];
    $teksSebut = strtolower((string)$task['request'] . ' ' . $brief . ' ' . ai_conversation_text($id, 3000));
    foreach ($daftar as $f) {
        $base = strtolower((string)basename((string)$f['rel']));
        if ($base !== '' && strpos($teksSebut, $base) !== false) $disebut[] = (string)$f['rel'];
    }
    if ($disebut) {
        $pilih = array_values(array_unique(array_merge($disebut, $pilih)));
        ai_step($id, 'info', 'Berkas yang disebut pada perintah diutamakan',
            implode(', ', array_slice($disebut, 0, 4)));
    }
    /* Berkas inti HANYA dipakai sebagai cadangan terakhir bila tidak ada berkas lain
       yang terpilih. Sebelumnya berkas inti (config.php 62 KB + layout.php 60 KB)
       selalu ikut, dan itu membuat satu permintaan kecil memakai ratusan ribu token. */
    if (!$pilih) {
        foreach (['naveena/includes/config.php', 'naveena/includes/layout.php'] as $inti) {
            if (count($pilih) < $maksBerkas && ai_path($inti) !== null) $pilih[] = $inti;
        }
    }
    $pilih = array_slice(array_values(array_unique($pilih)), 0, $maksBerkas);
    if (!$pilih) {
        ai_task_update($id, ['status' => 'failed', 'stage' => '',
            'error' => 'Tidak dapat menentukan berkas yang relevan. Tulis permintaan lebih spesifik (sebutkan menu atau nama berkas).']);
        exit(1);
    }

    /* ---------- 2. Baca isi berkas (BERTAHAP untuk berkas besar) ----------
       Permintaan pemilik (V2.3 bagian 2 & 6): berkas besar TIDAK boleh langsung
       dianggap "dilewati" lalu disimpulkan tidak ada perubahan. Sekarang berkas
       besar dibaca bertahap (kepala + bagian yang paling relevan dengan kata kunci
       permintaan), dan bila masih ada bagian yang belum terbaca hal itu DICATAT
       (status audit menjadi "belum lengkap") — bukan disembunyikan. */
    $setStage('Membaca isi ' . count($pilih) . ' berkas');
    $isi = [];
    $lewat = [];
    $dlmTidakLengkap = [];
    $dlmTerbaca = [];
    /* RONDE 52 — variabel bukti audit (dideklarasikan di awal supaya tidak ada
       "undefined variable" pada jalur mana pun): berkas terkait yang dibaca,
       permintaan baca AI yang gagal/sudah ada, dan lampiran yang gagal diproses. */
    $terkaitDibaca = [];
    $mintaBacaGagal = [];
    $mintaBacaSudahAda = [];
    $lampiranGagal = [];
    foreach ($pilih as $rel) {
        $b = ai_read_smart($rel, (string)$task['request'] . ' ' . $brief);
        if (!$b['ok']) { $lewat[] = $rel . ' (' . $b['error'] . ')'; continue; }
        $isi[$rel] = $b['text'];
        if (!empty($b['lengkap'])) {
            $dlmTerbaca[$rel] = 'utuh';
        } else {
            $dlmTidakLengkap[$rel] = (string)$b['alasan'];
            $dlmTerbaca[$rel] = 'sebagian';
        }
    }
    if (!$isi) {
        ai_step($id, 'error', 'Berkas yang relevan tidak dapat dibaca', implode(', ', $lewat));
        ai_task_update($id, ['status' => 'failed', 'stage' => '',
            'error' => 'Berkas yang relevan tidak dapat dibaca: ' . implode(', ', $lewat)]);
        exit(1);
    }
    ai_step($id, 'ok', count($isi) . ' berkas dibaca sebagai bahan perubahan',
        implode(', ', array_map(fn($r, $v) => $r . ' (' . $v . ')', array_keys($dlmTerbaca), $dlmTerbaca)));
    if ($lewat) ai_step($id, 'warn', 'Sebagian berkas tidak dapat dibaca sama sekali', implode(', ', $lewat));
    if ($dlmTidakLengkap) {
        ai_step($id, 'warn', count($dlmTidakLengkap) . ' berkas besar dibaca BERTAHAP (belum utuh)',
            implode(' · ', array_map(fn($r, $v) => $r . ': ' . $v, array_keys($dlmTidakLengkap), $dlmTidakLengkap)));
    }
    /* Berkas SASARAN = pilihan AI + berkas yang disebut namanya pada permintaan/brief.
       Bila keduanya kosong, semua berkas terpilih dianggap sasaran. */
    $sasaran = array_values(array_unique(array_filter(array_map('strval',
        array_merge($pilihAi, $disebut ?? [])))));
    if (!$sasaran) $sasaran = array_keys($dlmTerbaca);
    $dlmSasaran = [];
    $sasaranBermasalah = [];
    foreach ($sasaran as $rel) {
        if (!isset($dlmTerbaca[$rel])) continue;             // tidak terbaca sama sekali
        $dlmSasaran[$rel] = $dlmTerbaca[$rel];
        if ($dlmTerbaca[$rel] !== 'utuh') $sasaranBermasalah[] = $rel . ' (' . ($dlmTidakLengkap[$rel] ?? 'sebagian') . ')';
    }
    ai_trace($id, 'pembacaan', [
        'berkas_dibaca' => $dlmTerbaca, 'tidak_dibaca' => $lewat,
        'belum_utuh' => $dlmTidakLengkap,
    ]);
    /* Hemat token: hanya berkas yang paling relevan (dan muat anggaran) yang dikirim
       ke model pelaksana. Berkas yang dipangkas DILAPORKAN supaya jelas. */
    $pangkas = ai_context_trim($isi, (string)$task['request'], $brief, $pilih);
    if ($pangkas['dilewati']) {
        $isi = $pangkas['isi'];
        ai_step($id, 'info', 'Konteks dibatasi ' . num($pangkas['kb']) . ' KB (hemat token) — '
            . count($pangkas['dilewati']) . ' berkas tidak dikirim',
            'Dilewati: ' . implode(', ', array_slice($pangkas['dilewati'], 0, 6)));
    }
    if (!$isi) {
        ai_task_update($id, ['status' => 'failed', 'stage' => '',
            'error' => 'Tidak ada berkas yang dapat dikirim ke AI (semua melebihi anggaran konteks). '
                . 'Naikkan "Anggaran konteks" di AI Settings.']);
        exit(1);
    }
    /* ---------- 2b. PENELUSURAN DAMPAK (dependency/impact) ----------
       Permintaan pemilik (V2.3 bagian 2): jangan hanya mencari dari satu keyword;
       temukan fungsi/tabel/setelan/izin/halaman lain yang IKUT TERDAMPAK, supaya
       penambahan fitur benar-benar terintegrasi (bukan berhenti di satu berkas). */
    $impactTeks = '';
    $impactTerkait = [];
    if (ai_settings()['impact_scan']) {
        $setStage('Menelusuri bagian lain yang ikut terdampak');
        $impact = ai_impact_scan(array_keys($isi), (string)$task['request'], 8);
        $impactTerkait = $impact['terkait'];
        if ($impactTerkait) {
            $baris = [];
            foreach ($impactTerkait as $rel => $alasan) $baris[] = $rel . '  ← memakai: ' . $alasan;
            $impactTeks = implode("\n", $baris);
            ai_step($id, 'info', 'Penelusuran dampak: ' . count($impactTerkait) . ' berkas lain terkait',
                implode(' · ', array_slice(array_keys($impactTerkait), 0, 6)));
            /* Berkas terkait ikut DIBACA (bila masih ada ruang anggaran) supaya AI
               dapat mengubahnya tanpa menebak isinya — inilah yang membuat
               penambahan fitur terintegrasi pada sekali jalan. */
            $sisa = max(0, ai_settings()['max_files'] - count($isi));
            $tambah = 0;
            foreach ($impactTerkait as $rel => $alasan) {
                if ($tambah >= $sisa || $tambah >= 3) break;
                if (isset($isi[$rel])) continue;
                $b = ai_read_smart($rel, (string)$task['request']);
                if (!$b['ok']) continue;
                $isi[$rel] = $b['text'];
                $tambah++;
            }
            if ($tambah > 0) {
                ai_step($id, 'info', $tambah . ' berkas terkait ikut dibaca (bahan integrasi)',
                    implode(', ', array_slice(array_keys($impactTerkait), 0, 4)));
            }
            /* PENELUSURAN DEPENDENCY OTOMATIS (ronde 52): untuk pekerjaan ber-area luas,
               berkas terkait dibaca lebih banyak SEKALIGUS (bukan menunggu AI memintanya
               satu per satu) supaya audit benar-benar dapat diselesaikan. Batas total
               konteks tetap dijaga supaya biaya token wajar. */
            if (!empty($auditArea['luas'])) {
                $kuotaDep = (int)setting('ai_audit_dep_files', '10');
                $batasKb = (int)setting('ai_audit_dep_kb', '160') * 1024;
                $dibacaDep = 0; $ukuranDep = 0;
                foreach ($impactTerkait as $relD => $alasanD) {
                    if ($dibacaDep >= $kuotaDep || $ukuranDep >= $batasKb) break;
                    if (isset($isi[$relD])) continue;
                    $b = ai_read_smart($relD, (string)$task['request'], 24);
                    if (empty($b['ok'])) continue;
                    $isi[$relD] = $b['text'];
                    $terkaitDibaca[] = (string)$relD;
                    $ukuranDep += strlen((string)$b['text']);
                    $dibacaDep++;
                }
                if ($dibacaDep > 0) {
                    ai_step($id, 'info', $dibacaDep . ' berkas dependency ikut dibaca (audit luas)',
                        'total konteks dependency ' . num(round($ukuranDep / 1024)) . ' KB');
                    ai_trace($id, 'dependency-sweep', ['dibaca' => $dibacaDep,
                        'kb' => round($ukuranDep / 1024), 'berkas' => array_slice($terkaitDibaca, 0, 15)]);
                }
            }
            /* Catat berkas terkait yang BENAR-BENAR masuk konteks — dipakai syarat
               "dependency lengkap" untuk pekerjaan ber-area luas (ronde 52). */
            foreach ($impactTerkait as $relT => $alasanT) {
                if (isset($isi[$relT])) $terkaitDibaca[] = (string)$relT;
            }
        } else {
            $impactTeks = '(tidak ada berkas lain yang memakai simbol yang sama — perubahan kemungkinan lokal)';
        }
        ai_task_update($id, ['impact_note' => $impactTeks]);
        ai_trace($id, 'dampak', ['terkait' => $impactTerkait, 'simbol' => $impact['simbol'] ?? [],
            'tidak_ditemukan' => $impact['tidak_ada'] ?? []]);
        ai_job_beat($id, 'dependency', 'done', ['note' => count($impactTerkait) . ' berkas terkait']);
        ai_job_checkpoint($id, ['next_mode' => 'plan', 'tahap' => 'dependency',
            'terkait' => array_slice(array_keys($impactTerkait), 0, 15)]);
    }
    /* Hemat token SETELAH berkas terkait ikut masuk (anggarannya tetap dijaga). */
    /* Pangkas konteks kedua: yang WAJIB ikut hanya BERKAS SASARAN (berkas pendukung
       boleh dipangkas bila anggaran tidak cukup). Sebelumnya seluruh berkas dianggap
       wajib sehingga konteks bisa membengkak jauh melebihi anggaran. */
    $pangkas2 = ai_context_trim($isi, (string)$task['request'], $brief, $sasaran);
    if (!empty($pangkas2['dilewati']) && count($pangkas2['isi']) >= 1) {
        $isi = $pangkas2['isi'];
        ai_step($id, 'info', 'Konteks akhir ' . num($pangkas2['kb']) . ' KB — '
            . count($pangkas2['dilewati']) . ' berkas pendukung tidak dikirim',
            'Tidak dikirim: ' . implode(', ', array_slice($pangkas2['dilewati'], 0, 6)));
    }
    /* --- BUKTI AUDIT (ronde 52): berkas gagal dibaca, lampiran gagal diproses, area --- */
    $gagalBacaDetail = [];
    foreach ($lewat as $l) {
        $nama = (string)strtok($l, ' ');
        $gagalBacaDetail[$nama] = trim((string)substr($l, strlen($nama)), ' ()');
    }
    foreach ($attList as $lf) {
        $ext = strtolower((string)($lf['ext'] ?? ''));
        if ((int)($lf['chars'] ?? 0) > 0) continue;
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) continue;
        $lampiranGagal[(string)($lf['name'] ?? 'lampiran')] = 'isi lampiran tidak terbaca (0 huruf)';
    }
    if (!empty($auditArea['luas'])) {
        /* BUKTI AUDIT: berkas wajib area dibaca PENUH (bukan dipangkas). Isi lengkapnya
           tidak seluruhnya dikirim ke model (hemat token) — yang dikirim cuplikan
           bertahap, sementara bukti auditnya utuh. Ini yang membuat pekerjaan
           architecture/database TIDAK lagi berakhir "audit belum lengkap" padahal
           berkasnya sebenarnya mampu dibaca sistem. */
        $bukti = [];
        $ukuranBukti = 0;
        foreach ((array)$auditArea['wajib'] as $wajib) {
            $penuh = ai_read_full($wajib);
            if (!empty($penuh['utuh'])) {
                $bukti[$wajib] = $penuh['baris'] . ' baris';
                $dlmSasaran[$wajib] = 'utuh';
                /* Yang dikirim ke model hanya CUPLIKAN (bukan berkas penuh) supaya
                   biaya token tetap wajar; bukti auditnya tetap "dibaca penuh" dan hal
                   itu dicatat di jejak. Batas total cuplikan bukti juga dijaga. */
                if (!isset($isi[$wajib]) && $ukuranBukti < 49152) {
                    $cuplik = ai_read_smart($wajib, (string)$task['request'], 20);
                    if (!empty($cuplik['ok'])) {
                        $isi[$wajib] = $cuplik['text'];
                        $ukuranBukti += strlen((string)$cuplik['text']);
                    }
                }
            } else {
                $alasan = $penuh['alasan'] !== '' ? $penuh['alasan'] : $penuh['error'];
                $dlmSasaran[$wajib] = 'tidak terbaca';
                $gagalBacaDetail[$wajib] = $alasan;
                ai_step($id, 'warn', 'Berkas bukti area TIDAK dapat dibaca penuh: ' . $wajib, $alasan);
            }
        }
        if ($bukti) {
            ai_step($id, 'ok', 'Bukti audit area dibaca penuh (' . count($bukti) . ' berkas)',
                implode(' · ', array_map(fn($r, $b) => $r . ' (' . $b . ')', array_keys($bukti), $bukti)));
        }
        ai_trace($id, 'bukti-audit', ['wajib' => $auditArea['wajib'], 'dibaca_penuh' => $bukti]);
    }
    ai_trace($id, 'standar-audit', ['areas' => $auditArea['areas'], 'wajib' => $auditArea['wajib'],
        'lampiran_gagal' => $lampiranGagal, 'gagal_baca' => $gagalBacaDetail]);
    /* Langkah job: discovery + audit selesai → checkpoint (titik resume). */
    ai_job_beat($id, 'discovery', 'done', ['files_found' => count($pilih), 'files_read' => count($isi)]);
    ai_job_beat($id, 'audit', ($auditStatus === 'lengkap' ? 'done' : 'running'),
        ['files_skipped' => count($lampiranGagal) + count($gagalBacaDetail)]);
    ai_job_checkpoint($id, ['next_mode' => 'plan', 'tahap' => 'audit',
        'audit_status' => $auditStatus, 'berkas_dibaca' => count($isi)]);

    /* Berkas SASARAN yang tidak ikut terkirim ke model = belum benar-benar diperiksa. */
    foreach ($sasaran as $rel) {
        if (isset($dlmSasaran[$rel]) && !isset($isi[$rel])) {
            $sasaranBermasalah[] = $rel . ' (tidak dikirim ke model: anggaran konteks)';
            $dlmSasaran[$rel] = 'tidak dikirim';
        }
    }
    /* STATUS AUDIT diambil dari SATU SUMBER KEBENARAN (ai_audit_state) — status internal,
       workflow, dan tampilan memakai nilai yang sama sehingga tidak mungkin lagi mesin
       menyatakan AUDIT_INCOMPLETE sementara layar menampilkan NO_CHANGE. */
    $auditState = ai_audit_state([
        'sasaran' => $dlmSasaran, 'gagal' => $gagalBacaDetail,
        'terkait' => $impactTerkait ?? [], 'terkait_dibaca' => array_values(array_unique($terkaitDibaca ?? [])),
        'minta_baca_gagal' => $mintaBacaGagal ?? [], 'lampiran_gagal' => $lampiranGagal,
        'area' => $auditArea, 'ai_ragu' => false,
    ]);
    $auditStatus = (string)$auditState['status'];
    ai_task_update($id, [
        'audit_status' => $auditStatus,
        'files_read' => json_encode($dlmTerbaca, JSON_UNESCAPED_UNICODE),
        'files_skipped' => json_encode(array_merge($sasaranBermasalah, $lewat), JSON_UNESCAPED_UNICODE),
    ]);
    ai_trace($id, 'audit', ['sasaran' => $dlmSasaran, 'bermasalah' => $sasaranBermasalah,
        'pendukung_sebagian' => $dlmTidakLengkap, 'audit' => $auditStatus,
        'alasan' => $auditState['alasan'], 'area' => $auditArea['areas'],
        'terkait_dibaca' => array_values(array_unique($terkaitDibaca ?? []))]);
    $dlmSasaranTdkLengkap = $sasaranBermasalah;

    /* Riwayat percakapan ikut dikirim supaya perintah lanjutan dipahami sebagai
       lanjutan pekerjaan yang sama, bukan permintaan yang berdiri sendiri. */
    $riwayat = ai_conversation_text($id);
    if ($riwayat !== '') {
        $permintaanUntukAi = "Riwayat percakapan dengan pemilik (yang terakhir = permintaan terbaru):\n"
            . $riwayat . "\n\nPermintaan yang harus dikerjakan SEKARANG:\n" . (string)$task['request'];
        ai_step($id, 'info', 'Riwayat percakapan diikutkan (perintah lanjutan)', short_text($riwayat, 200));
    }

    /* ---------- 2c. MODE OBROLAN: JAWAB / PERIKSA / RENCANA ----------
       Bila pemilik sedang bertanya, berdiskusi, minta saran, minta diperiksa, atau
       minta rencana → AI MENJAWAB dan TIDAK mengubah berkas (tetapi jawaban yang
       berisi patch tetap dihormati: model yang memutuskan, bukan sistem). */
    $parse = ['ok' => false, 'ops' => [], 'error' => 'belum dicoba', 'plan' => '', 'files' => []];
    $terap = ['ok' => false, 'error' => 'belum dicoba', 'files' => [], 'created' => []];
    $raw = '';
    $finish = '';
    $noop = false;
    $lanjutPatch = false;
    if ($intent['mode'] !== 'kerjakan') {
        $setStage($intent['mode'] === 'audit' ? 'Memeriksa kode & menyusun laporan temuan'
            : ($intent['mode'] === 'rencana' ? 'Menyusun rencana pengerjaan' : 'Menjawab pertanyaan pemilik'));
        $pesanChat = [
            ['role' => 'user', 'text' => ai_system_prompt()],
            ['role' => 'model', 'text' => 'Baik, saya akan menjawab sesuai kebutuhan pemilik dengan JSON (answer/suggestions/next_steps).'],
            ['role' => 'user', 'text' => ai_chat_prompt($intent['mode'], (string)$task['request'], $isi,
                $brief, $attText, $riwayat, $impactTeks)],
        ];
        $jawab = ['ok' => false, 'text' => '', 'error' => '', 'finish' => ''];
        $jawaban = ['ok' => false, 'answer' => '', 'suggestions' => [], 'next' => [], 'files' => [],
                    'ops' => [], 'minta_baca' => [], 'error' => ''];
        for ($putaran = 1; $putaran <= 3; $putaran++) {
            $jawab = ai_call_chain($pesanChat, 0.3, 16384, [
                'task_id' => $id,
                'purpose' => ($intent['mode'] === 'audit' ? 'periksa/audit' : ($intent['mode'] === 'rencana' ? 'susun rencana' : 'jawab obrolan'))
                    . ($putaran > 1 ? ' (lanjutan ' . $putaran . ')' : ''),
                'attachments' => $attInline,
            ]);
            $raw = (string)$jawab['text'];
            $finish = (string)($jawab['finish'] ?? '');
            if (trim($raw) === '') {
                ai_task_update($id, ['status' => 'failed', 'stage' => '', 'workflow' => 'FAILED',
                    'error' => (string)($jawab['error'] ?? 'AI tidak memberi jawaban.'), 'finish_reason' => $finish]);
                ai_trace($id, 'jawaban-gagal', ['error' => (string)($jawab['error'] ?? '')]);
                exit(1);
            }
            $jawaban = ai_answer_parse($raw);
            /* AI minta membaca berkas lain → dikirim, lalu jawab lagi. */
            if ($jawaban['ok'] && $jawaban['minta_baca']) {
                $baru = [];
                foreach ($jawaban['minta_baca'] as $rel) {
                    if (isset($isi[$rel])) continue;
                    $b = ai_read_smart($rel, (string)$task['request']);
                    if ($b['ok']) $baru[$rel] = $b['text'];
                }
                if ($baru) {
                    $isi = array_merge($isi, $baru);
                    ai_step($id, 'info', 'AI meminta ' . count($baru) . ' berkas tambahan untuk dipelajari',
                        implode(', ', array_keys($baru)));
                    $pesanChat[] = ['role' => 'model', 'text' => $raw];
                    $pesanChat[] = ['role' => 'user', 'text' => 'Berkas yang Anda minta sudah dikirim di bawah. '
                        . "Lanjutkan menjawab.\n\n" . ai_chat_prompt($intent['mode'], (string)$task['request'],
                            $baru, $brief, '', '', $impactTeks)];
                    continue;
                }
                ai_step($id, 'warn', 'Berkas tambahan yang diminta AI tidak dapat dibaca',
                    implode(', ', $jawaban['minta_baca']));
            }
            break;
        }
        /* Model memilih langsung memberi patch walau klasifikasinya "obrolan"
           (mis. permintaan campuran: bertanya sambil minta diperbaiki) → diteruskan
           ke alur patch di bawah, bukan dibuang. */
        if ($jawaban['ok'] && $jawaban['ops']) {
            $parse = ai_patch_parse($raw);
            if (empty($parse['ok'])) {
                $parse = ['ok' => true, 'ops' => $jawaban['ops'], 'error' => '',
                    'plan' => $jawaban['answer'], 'files' => array_values(array_unique(
                        array_map(fn($o) => (string)($o['file'] ?? ''), $jawaban['ops'])))];
            }
            $terap = ai_apply_patch_to_content($parse['ops']);
            if (!empty($terap['ok'])) {
                $lanjutPatch = true;
                ai_step($id, 'info', 'AI sekaligus mengusulkan perubahan (permintaan campuran) — dilanjutkan ke penerapan');
            }
        }
        if (!$lanjutPatch) {
            /* ---- SIMPAN JAWABAN (tidak ada berkas yang diubah) ---- */
            $kode = ['jawab' => 'answered', 'audit' => 'analyzed', 'rencana' => 'planned'][$intent['mode']] ?? 'answered';
            /* RONDE 52: audit dihitung ULANG dari satu sumber, termasuk pengakuan AI
               sendiri bahwa auditnya belum cukup (dulu diabaikan pada semua mode). */
            $raguJawab = ai_answer_says_incomplete((string)($jawaban['answer'] ?? '') . "\n" . $raw);
            $auditState = ai_audit_state([
                'sasaran' => $dlmSasaran, 'gagal' => $gagalBacaDetail,
                'terkait' => $impactTerkait ?? [], 'terkait_dibaca' => array_values(array_unique($terkaitDibaca ?? [])),
                'minta_baca_gagal' => $mintaBacaGagal, 'lampiran_gagal' => $lampiranGagal,
                'area' => $auditArea, 'ai_ragu' => $raguJawab['ragu'],
            ]);
            $auditStatus = (string)$auditState['status'];
            /* Audit/pemeriksaan yang belum lengkap TIDAK boleh mengaku selesai, dan
               pada mode "kerjakan" pun tidak boleh lanjut menyimpulkan apa pun. */
            if ($auditStatus !== 'lengkap') $kode = 'audit_incomplete';
            $gagalBaca = (array)$auditState['alasan'];
            $wf = ai_workflow_status($kode);
            $jawabanTeks = $jawaban['answer'];
            if (!$jawaban['ok'] && $jawabanTeks === '') $jawabanTeks = short_text($raw, 4000);
            /* Bila penguraian gagal tetapi teksnya ada, tetap tampilkan apa adanya
               LENGKAP dengan catatan jujur; jangan mengaku "selesai" tanpa isi. */
            $ringkas = ($intent['mode'] === 'audit' ? '**Hasil pemeriksaan**' :
                    ($intent['mode'] === 'rencana' ? '**Rencana**' : '**Jawaban AI**'))
                . "\n\n" . $jawabanTeks;
            if ($jawaban['files']) {
                $ringkas .= "\n\n**Berkas yang perlu diperhatikan:**\n• " . implode("\n• ", $jawaban['files']);
            }
            if ($jawaban['next']) {
                $ringkas .= "\n\n**Langkah berikutnya yang saya sarankan:**\n• " . implode("\n• ", $jawaban['next']);
            }
            if ($jawaban['suggestions']) {
                $ringkas .= "\n\n**Saran untuk Anda:**\n• " . implode("\n• ", $jawaban['suggestions']);
            }
            if ($gagalBaca) {
                $ringkas .= ($intent['mode'] === 'audit'
                        ? "\n\n**AUDIT BELUM LENGKAP** — ada berkas yang belum terbaca utuh, jadi "
                          . "kesimpulan di atas belum menyeluruh:"
                        : "\n\n**Catatan jujur — bagian yang BELUM selesai saya periksa:**")
                    . "\n• " . implode("\n• ", array_slice($gagalBaca, 0, 8))
                    . "\nBerkas di atas belum terbaca utuh, jadi kesimpulan ini belum menyeluruh. "
                    . "Naikkan \"Ukuran maksimal berkas\"/\"Anggaran konteks\" di AI Settings bila perlu "
                    . "diperiksa sampai tuntas.";
            }
            if (!$jawaban['ok']) {
                $ringkas .= "\n\n(Jawaban AI tidak berbentuk JSON yang diminta, jadi isi aslinya di atas "
                    . "ditampilkan apa adanya. Bila ada yang kurang, tulis lagi di kotak chat ini.)";
            }
            $ringkas .= "\n\n_Tidak ada berkas yang diubah pada langkah ini._ "
                . "Kalau ingin dikerjakan, tulis **kerjakan** di kotak chat di bawah.";
            ai_msg($id, 'ai', $ringkas);
            ai_task_update($id, [
                'status' => $kode, 'stage' => $wf['label'] . ' (' . $wf['kode'] . ')',
                'workflow' => $wf['kode'], 'audit_status' => $auditStatus,
                'files_skipped' => $auditStatus === 'lengkap' ? (string)($task['files_skipped'] ?? '')
                    : json_encode($gagalBaca, JSON_UNESCAPED_UNICODE),
                'plan' => short_text($jawabanTeks, 4000),
                'patch' => '', 'diff' => '', 'lint' => '', 'files' => '[]', 'error' => '',
                'raw_reply' => short_text($raw, 4000), 'finish_reason' => $finish,
                'suggestions' => $jawaban['suggestions'] ? json_encode($jawaban['suggestions'], JSON_UNESCAPED_UNICODE) : '',
            ]);
            ai_trace($id, 'jawaban', ['status' => $kode, 'panjang' => ai_strlen($jawabanTeks),
                'berkas_disarankan' => $jawaban['files'], 'saran' => $jawaban['suggestions'],
                'next' => $jawaban['next'], 'audit' => $auditStatus,
                'alasan_audit' => $auditState['alasan'], 'ai_ragu' => $raguJawab['ragu']]);
            ai_step($id, 'ok', $wf['label'], short_text($jawabanTeks, 200));
            ai_attachment_mark_sent($id, $attList !== []);
            audit('AI Menjawab Permintaan', 'AI Developer', $id, null,
                ['mode' => $intent['mode'], 'kategori' => $intent['kategori'], 'status' => $kode],
                'AI ' . ($intent['mode'] === 'audit' ? 'memeriksa' : ($intent['mode'] === 'rencana' ? 'menyusun rencana untuk' : 'menjawab'))
                . ': ' . short_text((string)$task['request'], 120));
                ai_job_finish($id, $kode === 'audit_incomplete' ? 'AUDIT_INCOMPLETE' : 'COMPLETED',
                    $kode === 'audit_incomplete' ? implode(' | ', array_slice((array)$gagalBaca, 0, 3)) : '');
            exit(0);
        }
    }

    /* ---------- 3. Minta patch (dengan PUTARAN PERBAIKAN OTOMATIS) ----------
       RONDE 43 (permintaan pemilik: "biar AI Developer mudah memahami perintah"):
       AI tidak lagi hanya sekali coba. Bila jawabannya tidak dapat dibaca (JSON
       terpotong/salah bentuk) atau patch-nya tidak dapat diterapkan (teks "search"
       tidak cocok), AI diberi tahu KESALAHAN PERSISNYA lalu diminta memperbaiki —
       sampai 3 putaran. Bila tetap gagal, pekerjaan dilanjutkan dengan meminta
       patch PER BERKAS (jawabannya lebih pendek sehingga tidak terpotong). */
    $batasKeluaran = 32768;   // lebih besar dari sebelumnya agar tidak mudah terpotong
    /* Putaran penyusunan patch: pekerjaan ber-area luas (bertahap, banyak berkas) diberi
       ruang iterasi lebih banyak daripada perubahan kecil — tetap dibatasi agar tidak
       berputar tanpa akhir. */
    $maksPutaran = !empty($auditArea['luas'])
        ? max(ai_settings()['max_rounds'], (int)setting('ai_audit_rounds', '4'))
        : ai_settings()['max_rounds'];
    /* Putaran TAMBAHAN khusus untuk permintaan berkas ("read"): tanpa ini, bila AI
       meminta berkas pada putaran TERAKHIR, permintaannya tidak pernah dilayani
       dan hasilnya "usulan" tanpa satu pun perubahan (tombol pratinjau/uji/penerapan
       tidak muncul — keluhan pemilik ronde 49). */
    /* Putaran baca ADAPTIF (ronde 52): pekerjaan ber-area luas (architecture/database/
       migrasi/security/refactor) membutuhkan penelusuran berkas lebih banyak daripada
       perubahan kecil. Tanpa ini, AI kehabisan putaran sebelum dependency penting
       terbaca → berhenti sebagai AUDIT_INCOMPLETE (padahal sebenarnya masih mampu). */
    $maksBaca = !empty($auditArea['luas']) ? max(2, (int)setting('ai_audit_read_rounds', '5')) : 2;
    $bacaDipakai = 0;
    /* Kepala pesan konteks dipakai ulang pada putaran perbaikan — supaya setiap
       putaran TIDAK menggandakan konteks berkas (pemborosan token terbesar).
       WAJIB didefinisikan SEBELUM dipakai (pernah salah urutan → fatal error). */
    $pesanKonteks = [
        ['role' => 'user', 'text' => ai_system_prompt()],
        ['role' => 'model', 'text' => 'Baik, saya akan menjawab hanya dengan JSON sesuai aturan.'],
        ['role' => 'user', 'text' => ai_patch_prompt((string)$task['request'], $isi, $brief, $attText,
            $impactTeks, $intent['kategori'], $riwayat)],
    ];
    $pesan = $pesanKonteks;
    $catatan = [];        // jejak tiap putaran (disimpan di keterangan/err bila gagal)
    /**
     * SIMPULKAN "AUDIT_INCOMPLETE" lalu HENTIKAN pekerjaan (ronde 52).
     *
     * Dipakai oleh gerbang NO_CHANGE: begitu audit terbukti belum lengkap, AI TIDAK
     * boleh melanjutkan ke percobaan menyusun patch (apalagi menyimpulkan "tidak ada
     * perubahan") — status harus AUDIT_INCOMPLETE beserta daftar berkas & alasannya.
     */
    $simpulkanAuditBelumLengkap = function (array $auditState, array $raguFinal, string $penjelasan,
                                            string $rawTeks, string $finishTeks) use ($id): void {
        $rincian = (array)$auditState['alasan'];
        $wf = ai_workflow_status('audit_incomplete');
        ai_task_update($id, [
            'status' => 'audit_incomplete', 'stage' => $wf['label'] . ' (' . $wf['kode'] . ')',
            'workflow' => $wf['kode'], 'audit_status' => 'belum_lengkap',
            'plan' => $penjelasan !== '' ? $penjelasan : 'Belum dapat disimpulkan.',
            'patch' => '', 'diff' => '', 'lint' => '', 'files' => '[]', 'error' => '',
            'raw_reply' => short_text($rawTeks, 4000), 'finish_reason' => $finishTeks,
            'files_skipped' => json_encode($rincian, JSON_UNESCAPED_UNICODE),
        ]);
        ai_msg($id, 'ai', "**AUDIT_INCOMPLETE — saya TIDAK dapat menyimpulkan "
            . "\u{201C}tidak ada perubahan\u{201D}.**"
            . "\n\nAlasan (berkas/area yang belum selesai diperiksa):\n• "
            . implode("\n• ", array_slice($rincian, 0, 8))
            . ($penjelasan !== '' ? "\n\n**Sementara ini yang dapat saya sampaikan:** " . $penjelasan : '')
            . "\n\n**Langkah berikutnya:** sebutkan bagian/berkas yang paling penting supaya saya "
            . "memeriksa lebih sempit dan tuntas, atau naikkan \"Anggaran konteks\"/\"Ukuran maksimal "
            . "berkas\" di AI Settings. Setelah auditnya lengkap barulah kesimpulan (termasuk "
            . "\u{201C}tidak ada perubahan\u{201D}) boleh diambil.");
        ai_trace($id, 'kesimpulan', ['status' => 'audit_incomplete', 'audit' => 'belum_lengkap',
            'alasan' => $rincian, 'bukti' => $auditState['bukti'],
            'ai_ragu' => (bool)($raguFinal['ragu'] ?? false), 'penjelasan' => short_text($penjelasan, 500)]);
        ai_step($id, 'warn', 'Kesimpulan DITAHAN: AUDIT_INCOMPLETE (bukan NO_CHANGE)',
            implode(' · ', array_slice($rincian, 0, 4)));
        audit('AI Audit Belum Lengkap', 'AI Developer', $id, null,
            ['alasan' => $rincian, 'audit' => 'belum_lengkap', 'ai_ragu' => (bool)($raguFinal['ragu'] ?? false)],
            'AI tidak menyimpulkan "tidak ada perubahan" karena audit belum lengkap');
            ai_job_finish($id, 'AUDIT_INCOMPLETE', implode(' | ', array_slice($rincian, 0, 3)));
        exit(0);
    };
    for ($putaran = 1; $putaran <= $maksPutaran + $maksBaca; $putaran++) {
        /* Bila model sudah memberi patch pada fase obrolan (permintaan campuran),
           putaran ini tidak perlu dijalankan lagi. */
        if ($lanjutPatch) break;
        $setStage($putaran === 1 ? 'AI menyusun usulan perubahan'
                                 : ('AI memperbaiki usulannya (putaran ' . $putaran . '/' . $maksPutaran . ')'));
        $jawab = ai_call_chain($pesan, 0.15, $batasKeluaran, [
            'task_id' => $id, 'purpose' => 'susun perubahan' . ($putaran > 1 ? ' (perbaikan ' . $putaran . ')' : ''),
            'attachments' => $attInline,
        ]);
        $raw = (string)$jawab['text'];
        $finish = (string)($jawab['finish'] ?? '');
        if (!$jawab['ok'] && trim($raw) === '') {
            ai_task_update($id, ['status' => 'failed', 'stage' => '', 'error' => (string)$jawab['error'],
                'raw_reply' => '', 'finish_reason' => $finish]);
            exit(1);
        }
        $parse = ai_patch_parse($raw);
        /* AI MENELUSURI SENDIRI: bila ia meminta berkas lain ({"read":[...]}), kirimkan
           berkas itu lalu ulangi pada putaran berikutnya. Inilah yang membuat AI dapat
           mencari bagian yang belum ada di konteks, bukan menebak — dan inilah bedanya
           dengan "sekali coba". Dibatasi 2 kali supaya tidak berputar tanpa akhir. */
        if (!empty($parse['ok']) && !empty($parse['minta_baca']) && $bacaDipakai < $maksBaca) {
            $bacaDipakai++;
            $baru = [];
            $sudahAda = [];
            foreach ($parse['minta_baca'] as $rel) {
                /* Sudah ada di konteks: catat (jangan dianggap gagal — AI hanya minta ulang). */
                if (isset($isi[$rel])) { $sudahAda[] = (string)$rel; continue; }
                $b = ai_read($rel);
                if (!$b['ok']) { $mintaBacaGagal[(string)$rel] = (string)$b['error']; continue; }
                if (!empty($b['truncated'])) {
                    $mintaBacaGagal[(string)$rel] = 'berkas terlalu besar: hanya terbaca sebagian';
                    continue;
                }
                $baru[$rel] = $b['text'];
            }
            if ($sudahAda) $mintaBacaSudahAda = array_values(array_unique(array_merge($mintaBacaSudahAda, $sudahAda)));
            if ($mintaBacaGagal) {
                ai_step($id, 'warn', 'Berkas yang diminta AI TIDAK dapat dibaca',
                    implode(' · ', array_map(fn($r, $a) => $r . ' (' . $a . ')',
                        array_keys($mintaBacaGagal), $mintaBacaGagal)));
                ai_trace($id, 'baca-gagal', ['diminta' => array_keys($mintaBacaGagal), 'alasan' => $mintaBacaGagal]);
            }
            if ($baru) {
                $isi = array_merge($isi, $baru);
                ai_step($id, 'info', 'AI meminta ' . count($baru) . ' berkas tambahan untuk dipelajari',
                    implode(', ', array_keys($baru)));
                /* PENTING (ronde 52): konteks sebelumnya JANGAN dibuang. Dulu pesan konteks
                   DIGANTI dengan versi yang hanya memuat berkas BARU, sehingga berkas dari
                   putaran pertama "hilang" dari percakapan — AI lalu melaporkan "berkas X
                   tidak terdapat dalam konteks" dan tugas berhenti sebagai AUDIT_INCOMPLETE
                   padahal berkasnya sudah dibaca sistem (bug file discovery). Sekarang
                   berkas tambahan DITAMBAHKAN sebagai pesan lanjutan. */
                $pesanKonteks[] = ['role' => 'model', 'text' => $raw];
                $pesanKonteks[] = ['role' => 'user', 'text' =>
                    "Berkas tambahan yang Anda minta sudah dibaca sistem; isinya ada di bawah ini.\n"
                    . "Berkas yang Anda terima SEBELUMNYA tetap ada pada pesan-pesan di atas — daftar "
                    . "berkas yang sudah Anda terima: " . implode(', ', array_slice(array_keys($isi), 0, 25)) . ".\n"
                    . 'Bila informasi sudah cukup, susun patch (plan, files, ops). Bila memang belum cukup, '
                    . 'tulis "AUDIT_INCOMPLETE" pada plan beserta berkas/area yang belum terperiksa.\n\n'
                    . ai_patch_prompt((string)$task['request'], $baru, $brief, '')];
                $pesan = $pesanKonteks;
                continue;
            }
            /* TIDAK ada berkas baru: bisa karena semuanya SUDAH ada di konteks, atau
               karena gagal dibaca. Keduanya TIDAK boleh langsung berujung kesimpulan:
               AI diberi satu putaran lanjutan dengan keterangan apa adanya sehingga ia
               dapat menyusun patch dari berkas yang sudah ada, atau menyatakan dengan
               jujur bahwa auditnya belum cukup (→ AUDIT_INCOMPLETE, bukan NO_CHANGE). */
            $keterangan = [];
            if ($sudahAda) {
                $keterangan[] = 'Berkas berikut SUDAH ADA di konteks yang Anda terima sebelumnya: '
                    . implode(', ', array_slice($sudahAda, 0, 8)) . '. Baca ulang bagian di atas.';
            }
            if ($mintaBacaGagal) {
                $keterangan[] = 'Berkas berikut TIDAK dapat dibaca oleh sistem: '
                    . implode('; ', array_map(fn($r, $a) => $r . ' (' . $a . ')',
                        array_keys($mintaBacaGagal), $mintaBacaGagal)) . '.';
            }
            $pesanKonteks[] = ['role' => 'model', 'text' => $raw];
            $pesanKonteks[] = ['role' => 'user', 'text' =>
                'Daftar SELURUH berkas yang sudah Anda terima pada percakapan ini: '
                . implode(', ', array_slice(array_keys($isi), 0, 25)) . ".\n"
                . implode("\n", $keterangan) . "\n\n"
                . 'Lanjutkan dengan informasi yang SUDAH Anda miliki: kirim patch (ops) bila memang '
                . 'sudah cukup, ATAU nyatakan dengan jujur bila audit Anda belum lengkap. '
                . 'Jawab HANYA JSON: {"plan":"...","files":[...],"ops":[...],"suggestions":[...]}. '
                . 'Bila audit belum lengkap, tulis "AUDIT_INCOMPLETE" pada plan dan biarkan ops kosong — '
                . 'sistem TIDAK akan menyimpulkan "tidak ada perubahan" untuk audit yang belum lengkap.'];
            $pesan = $pesanKonteks;
            ai_step($id, 'info', 'AI diberi putaran lanjutan: berkas sudah ada / tidak terbaca',
                implode(' · ', array_slice($keterangan, 0, 2)));
            continue;
        }
        if (empty($parse['ok'])) {
            /* Jawaban tidak dapat dibaca → minta ulang sebagai JSON yang sah. */
            $catatan[] = 'Putaran ' . $putaran . ': ' . $parse['error'];
            ai_step($id, 'warn', 'Jawaban AI belum berbentuk JSON yang sah (putaran ' . $putaran . '/' . $maksPutaran . ') — minta perbaikan',
                (string)$parse['error']);
            $pesan = array_merge($pesanKonteks, [
                ['role' => 'model', 'text' => $raw],
                ['role' => 'user', 'text' => ai_repair_prompt((string)$task['request'], $raw, (string)$parse['error'])],
            ]);
            continue;
        }
        if (!empty($parse['noop'])) { $noop = true; break; }
        /* PENTING (ronde 52): patch KOSONG tidak boleh dianggap "berhasil diterapkan".
           Dulu ai_apply_patch_to_content([]) mengembalikan ok=true sehingga loop berhenti
           seolah usulan selesai, lalu dikonversi menjadi NO_CHANGE. */
        if (empty($parse['ops'])) {
            $raguKini = ai_answer_says_incomplete((string)($parse['plan'] ?? '') . "
" . $raw);
            if ($raguKini['ragu'] || $mintaBacaGagal) {
                /* AI menyatakan auditnya belum cukup (atau ada berkas yang dimintanya gagal
                   dibaca). JANGAN putar ulang tanpa guna dan JANGAN menyimpulkan "tidak ada
                   perubahan": keluar dari loop supaya gerbang NO_CHANGE di bagian 3a
                   menurunkan statusnya menjadi AUDIT_INCOMPLETE beserta alasannya. */
                $catatan[] = 'AI menyatakan audit belum cukup'
                    . ($mintaBacaGagal ? ' dan ' . count($mintaBacaGagal) . ' berkas gagal dibaca' : '')
                    . ' → kesimpulan "tidak ada perubahan" DITOLAK';
                ai_step($id, 'warn', 'AI menyatakan auditnya BELUM cukup — kesimpulan ditahan',
                    short_text($raguKini['kutipan'] !== '' ? $raguKini['kutipan'] : (string)$parse['plan'], 200));
                break;
            }
            $noop = true;
            break;
        }
        $terap = ai_apply_patch_to_content($parse['ops']);
        if (!empty($terap['ok'])) break;
        /* Patch tidak dapat diterapkan (search tidak cocok / kembar) → minta perbaikan. */
        $catatan[] = 'Putaran ' . $putaran . ': ' . $terap['error'];
        ai_step($id, 'warn', 'Patch belum cocok dengan berkas (putaran ' . $putaran . '/' . $maksPutaran . ') — minta perbaikan',
            (string)$terap['error']);
        $pesan = array_merge($pesanKonteks, [
            ['role' => 'model', 'text' => $raw],
            ['role' => 'user', 'text' => ai_repair_apply_prompt((string)$task['request'], $isi, $parse['ops'], (string)$terap['error'])],
        ]);
    }

    /* ---------- 3a. AI menyimpulkan TIDAK ADA yang perlu diubah ----------
       PENTING (perbaikan bug): pemeriksaan ini WAJIB lebih dulu daripada pemeriksaan
       kegagalan penerapan. Sebelumnya urutannya terbalik sehingga jawaban
       {"ops":[]} (AI menilai tidak ada yang perlu diubah) dilaporkan sebagai
       "GAGAL" — padahal tidak ada yang salah. Itulah yang membuat pemilik melihat
       kegagalan berulang padahal AI sudah menjawab dengan benar. */
    /* PENGAMAN TAMBAHAN (ronde 49): "usulan" TANPA satu pun perubahan BUKAN usulan.
       Dulu hal ini bisa terjadi (mis. AI meminta berkas tambahan tepat pada putaran
       terakhir) sehingga tugas berstatus "ada usulan" tetapi daftar ops-nya kosong —
       halaman pun menampilkan pesan "AI sudah selesai" tanpa tombol pratinjau, uji,
       maupun penerapan (persis keluhan pemilik). Sekarang diperlakukan sebagai
       "tidak ada perubahan" dengan penjelasan yang jujur. */
    /* PENTING: hanya berlaku bila jawaban AI memang BERBENTUK JSON yang sah
       (`$parse['ok']`). Jawaban yang tidak dapat dibaca tetap dilaporkan sebagai
       KEGAGALAN beserta jawaban mentahnya — jangan diubah menjadi "tidak ada
       perubahan", karena itu menyembunyikan masalah nyata. */
    /* RONDE 52 — GERBANG NO_CHANGE (inti perbaikan regression #926/#927/#930).
       NO_CHANGE hanya boleh bila audit BENAR-BENAR lengkap menurut satu sumber
       kebenaran `ai_audit_state()`: berkas sasaran terbaca utuh, tidak ada berkas
       gagal dibaca, lampiran berhasil diproses, permintaan baca AI terpenuhi,
       dependency & bukti area (untuk pekerjaan luas) cukup, DAN AI sendiri tidak
       menyatakan auditnya belum cukup. */
    $raguFinal = ai_answer_says_incomplete((string)($parse['plan'] ?? '') . "\n" . $raw);
    if ($noop || (!$noop && !empty($parse['ok']) && empty($parse['ops']))) {
        $auditState = ai_audit_state([
            'sasaran' => $dlmSasaran, 'gagal' => $gagalBacaDetail,
            'terkait' => $impactTerkait ?? [], 'terkait_dibaca' => array_values(array_unique($terkaitDibaca ?? [])),
            'minta_baca_gagal' => $mintaBacaGagal, 'lampiran_gagal' => $lampiranGagal,
            'area' => $auditArea, 'ai_ragu' => $raguFinal['ragu'],
        ]);
        $auditStatus = (string)$auditState['status'];
        if ($noop && !empty($auditState['boleh_no_change'])) {
            /* Sah: audit lengkap + AI memang menyimpulkan tidak perlu perubahan. */
            if (trim((string)($parse['plan'] ?? '')) === '') {
                $parse['plan'] = 'AI memeriksa seluruh berkas relevan dan menilai tidak ada yang perlu diubah'
                    . ($catatan ? ' (setelah ' . count($catatan) . ' putaran)' : '') . '.';
            }
        } else {
            /* TIDAK SAH: jangan pernah menyimpulkan NO_CHANGE. Turunkan ke AUDIT_INCOMPLETE
               dan berhenti dengan jujur (bukan mengaku selesai). */
            $noop = false;
            $auditStatus = 'belum_lengkap';
            ai_task_update($id, ['audit_status' => $auditStatus]);
            ai_trace($id, 'noop-ditolak', ['alasan' => $auditState['alasan'], 'bukti' => $auditState['bukti'],
                'ai_ragu' => $raguFinal['ragu'], 'kutipan' => $raguFinal['kutipan']]);
            ai_step($id, 'warn', 'Kesimpulan "tidak ada perubahan" DITOLAK — audit belum lengkap',
                ai_audit_state_text($auditState));
            /* HENTIKAN di sini: jangan mencoba menyusun patch dari audit yang belum
               lengkap (dan jangan berakhir sebagai "gagal" yang membingungkan). */
            $simpulkanAuditBelumLengkap($auditState, $raguFinal,
                trim((string)($parse['plan'] ?? '') . "\n\n" . (string)($parse['note'] ?? '')), $raw, $finish);
        }
    }
    if ($noop) {
        $penjelasan = trim((string)$parse['plan'] . "\n\n" . (string)($parse['note'] ?? ''));
        /* PENTING (V2.3 bagian 3 & 4): "tidak ada perubahan" hanya boleh dikatakan
           setelah audit benar-benar lengkap. Bila ada berkas sasaran yang belum
           terbaca utuh, statusnya AUDIT_INCOMPLETE — bukan NO_CHANGE — dan pemilik
           diberi tahu berkas mana yang belum diperiksa. */
        /* Penjaga KEDUA (pertahanan berlapis): dihitung ulang dari sumber yang sama
           supaya tidak ada jalur mana pun yang bisa menghasilkan NO_CHANGE saat audit
           belum lengkap (mis. $parse['noop'] dari jalur lain). */
        $auditState = ai_audit_state([
            'sasaran' => $dlmSasaran, 'gagal' => $gagalBacaDetail,
            'terkait' => $impactTerkait ?? [], 'terkait_dibaca' => array_values(array_unique($terkaitDibaca ?? [])),
            'minta_baca_gagal' => $mintaBacaGagal, 'lampiran_gagal' => $lampiranGagal,
            'area' => $auditArea, 'ai_ragu' => $raguFinal['ragu'],
        ]);
        $auditStatus = (string)$auditState['status'];
        if (empty($auditState['boleh_no_change'])) {
            $simpulkanAuditBelumLengkap($auditState, $raguFinal, $penjelasan, $raw, $finish);
        }
        ai_task_update($id, [
            'status' => 'noop', 'stage' => 'AI menilai tidak ada perubahan yang diperlukan',
            'workflow' => ai_workflow_status('noop')['kode'],
            'plan' => $penjelasan !== '' ? $penjelasan : 'AI tidak memberi penjelasan.',
            'patch' => '', 'diff' => '', 'lint' => '', 'files' => '[]', 'error' => '',
            'raw_reply' => short_text($raw, 4000), 'finish_reason' => $finish,
        ]);
        ai_step($id, 'info', 'AI menilai tidak ada perubahan yang perlu dilakukan', $penjelasan);
        ai_trace($id, 'kesimpulan', ['status' => 'noop', 'audit' => $auditStatus,
            'penjelasan' => short_text($penjelasan, 500)]);
        /* Pesan SELALU dibuka dengan kalimat yang jelas ("tidak ada perubahan"),
           baru penjelasan AI — supaya pemilik tidak mengira ini kegagalan. */
        ai_msg($id, 'ai', '**Tidak ada perubahan yang perlu dilakukan.** AI sudah memeriksa kode terkait '
            . '(seluruh berkas sasaran terbaca utuh) dan menilai permintaan ini tidak memerlukan perubahan.'
            . ($penjelasan !== '' ? "\n\n**Penilaian AI:** " . $penjelasan : '')
            . "\n\n**Langkah berikutnya:** bila memang perlu ada perubahan, balas di percakapan ini dengan "
            . 'sebutan yang lebih spesifik — mis. nama kartu/bagian yang ingin diubah, urutan yang Anda '
            . 'inginkan, atau nama berkasnya. Saya mengingat percakapan ini sehingga bisa langsung melanjutkan.');
        audit('AI Tidak Mengusulkan Perubahan', 'AI Developer', $id, null, null,
            'AI menyimpulkan tidak ada perubahan yang perlu dilakukan untuk permintaan ini');
            ai_job_finish($id, 'NO_CHANGE');
        exit(0);
    }

    /* ---------- 3b. Masih gagal? Coba PER BERKAS (jawaban lebih pendek) ---------- */
    if (empty($terap['ok'])) {
        $opsGabung = [];
        $planGabung = (string)($parse['plan'] ?? '');
        $gagalBerkas = [];
        foreach ($isi as $rel => $teks) {
            $setStage('Menyusun perubahan per berkas: ' . $rel);
            $j = ai_call_chain([
                ['role' => 'user', 'text' => ai_system_prompt()],
                ['role' => 'model', 'text' => 'Baik, saya akan menjawab hanya dengan JSON sesuai aturan.'],
                ['role' => 'user', 'text' => ai_patch_one_prompt((string)$task['request'], $planGabung, $rel, $teks)],
            ], 0.15, 16384, ['task_id' => $id, 'purpose' => 'susun perubahan per berkas: ' . $rel]);
            if (trim((string)$j['text']) === '') { $gagalBerkas[] = $rel; continue; }
            $raw = (string)$j['text'];
            $finish = (string)($j['finish'] ?? '');
            $p1 = ai_patch_parse($raw);
            if (empty($p1['ok']) || empty($p1['ops'])) { $gagalBerkas[] = $rel; continue; }
            if ($planGabung === '' && !empty($p1['plan'])) $planGabung = (string)$p1['plan'];
            $ada = false;
            foreach ($p1['ops'] as $o) {
                if (str_replace('\\', '/', (string)($o['file'] ?? '')) !== $rel) continue;
                $opsGabung[] = $o;
                $ada = true;
            }
            if (!$ada) $gagalBerkas[] = $rel;
        }
        if ($opsGabung) {
            $parse = ['ok' => true, 'ops' => $opsGabung, 'plan' => $planGabung,
                      'files' => array_values(array_unique(array_map(fn($o) => $o['file'], $opsGabung)))];
            $terap = ai_apply_patch_to_content($opsGabung);
            $catatan[] = 'Dilanjutkan per berkas (' . count($opsGabung) . ' perubahan'
                . ($gagalBerkas ? '; berkas yang belum berhasil: ' . implode(', ', $gagalBerkas) : '') . ').';
        }
    }

    if (empty($terap['ok'])) {
        /* RONDE 52: bila kegagalan menyusun patch DISEBABKAN audit yang belum lengkap
           (berkas gagal dibaca / AI menyatakan belum cukup), statusnya WAJIB
           AUDIT_INCOMPLETE — bukan "gagal" biasa, supaya UI & workflow jujur. */
        $auditGagal = ai_audit_state([
            'sasaran' => $dlmSasaran, 'gagal' => $gagalBacaDetail,
            'terkait' => $impactTerkait ?? [], 'terkait_dibaca' => array_values(array_unique($terkaitDibaca ?? [])),
            'minta_baca_gagal' => $mintaBacaGagal, 'lampiran_gagal' => $lampiranGagal,
            'area' => $auditArea, 'ai_ragu' => (bool)($raguFinal['ragu'] ?? false),
        ]);
        if (empty($auditGagal['boleh_no_change'])) {
            $simpulkanAuditBelumLengkap($auditGagal, $raguFinal,
                trim((string)($parse['plan'] ?? '') . "\n\n" . implode(' | ', array_slice($catatan, -2))), $raw, $finish);
        }
        $pesanGagal = 'Usulan AI belum berhasil setelah ' . $maksPutaran . ' putaran perbaikan + percobaan per berkas. '
            . implode(' | ', array_slice($catatan, -3))
            . ' — Anda dapat menekan "Kirim ke AI" lagi, memilih model lain di AI Settings, '
            . 'atau menulis permintaan yang lebih spesifik.';
        ai_step($id, 'error', 'AI belum berhasil menyusun perubahan', $pesanGagal);
        ai_msg($id, 'ai', 'Maaf, saya belum berhasil menyusun perubahan yang bisa diterapkan. '
            . "\n\nAlasan teknis terakhir: " . short_text(implode(' | ', array_slice($catatan, -2)), 400)
            . "\n\nSaran saya: (1) sebutkan menu/halaman yang tepat, (2) lampirkan contoh berkas bila "
            . 'menyangkut data, atau (3) balas di percakapan ini dengan penjelasan tambahan — saya akan coba lagi.');
        ai_task_update($id, ['status' => 'failed', 'stage' => '',
            'workflow' => ai_workflow_status('failed')['kode'],
            'error' => $pesanGagal, 'raw_reply' => short_text($raw, 4000), 'finish_reason' => $finish]);
        ai_job_finish($id, 'FAILED', $pesanGagal);
        exit(1);
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

    /* Halaman pratinjau: pakai saran AI bila masuk akal, kalau tidak ditebak dari
       berkas yang berubah (agar pemilik selalu punya sesuatu untuk DILIHAT). */
    $berkasUbah = array_keys($terap['files']);
    $halaman = trim((string)($parse['preview'] ?? $parse['preview_page'] ?? ''));
    $halaman = preg_match('~^[a-z0-9_]+\.php$~i', $halaman) ? $halaman : '';
    if ($halaman !== '' && ai_path('naveena/' . $halaman) === null) $halaman = '';
    if ($halaman === '') $halaman = ai_preview_page($berkasUbah, (string)$task['request']);

    /* Suite uji OTOMATIS: bila pemilik tidak memilih (kosong/auto), dipilih dari
       permintaan + berkas yang berubah supaya tetap ada pemeriksaan yang relevan. */
    if (trim((string)$task['suite']) === '' || (string)$task['suite'] === 'auto') {
        $pilihSuite = ai_auto_suite($berkasUbah, (string)$task['request']);
        $autoSuite = $pilihSuite['suite'] . ' (otomatis: ' . $pilihSuite['alasan'] . ')';
    } else {
        $autoSuite = '';
    }

    $saran = [];
    foreach ((array)($parse['suggestions'] ?? $parse['saran'] ?? []) as $x) {
        $x = trim((string)$x);
        if ($x !== '') $saran[] = $x;
    }
    ai_task_update($id, [
        'status' => 'proposed',
        'stage' => 'Selesai — menunggu pratinjau & persetujuan'
            . ($catatan ? ' (setelah ' . count($catatan) . ' putaran perbaikan otomatis)' : ''),
        'workflow' => ai_workflow_status('proposed')['kode'],
        'plan' => $parse['plan'], 'patch' => json_encode($parse['ops'], JSON_UNESCAPED_UNICODE),
        'diff' => implode("\n\n", $diff),
        'lint' => implode("\n", $petaLint),
        'files' => json_encode($berkasUbah, JSON_UNESCAPED_UNICODE),
        'preview_page' => $halaman,
        'suggestions' => $saran ? json_encode($saran, JSON_UNESCAPED_UNICODE) : '',
        'error' => '', 'finish_reason' => $finish,
    ] + ($autoSuite !== '' ? ['suite' => $pilihSuite['suite']] : []));
    ai_attachment_mark_sent($id, $attList !== []);
    /* Langkah rinci supaya pemilik melihat apa saja yang terjadi. */
    ai_step($id, 'ok', count($parse['ops']) . ' perubahan disusun pada ' . count($berkasUbah) . ' berkas',
        implode(', ', $berkasUbah));
    ai_step($id, $lint['ok'] ? 'ok' : 'error', 'Pemeriksaan sintaks: ' . ($lint['ok'] ? 'semua berkas OK' : 'ADA MASALAH'),
        implode(' · ', array_slice($petaLint, 0, 6)));
    if ($autoSuite !== '') {
        ai_task_update($id, ['stage' => 'Selesai — menunggu pratinjau & persetujuan. Suite uji otomatis: ' . $autoSuite]);
        ai_step($id, 'info', 'Suite uji dipilih otomatis: ' . $pilihSuite['suite'], $pilihSuite['alasan']);
    }
    if ($catatan) ai_step($id, 'info', 'Jawaban AI diperbaiki otomatis ' . count($catatan) . ' kali',
        implode(' | ', array_slice($catatan, -3)));
    /* Pesan AI (ringkasan + saran) — inilah yang dibaca pemilik di percakapan. */
    $tugasSekarang = ai_task($id) ?: [];
    /* Dibungkus try/catch: laporan gagal TIDAK boleh menggagalkan tugas yang sudah
       berhasil disusun (pelajaran dari bug di atas). */
    try {
        $ringkas = ai_finish_summary(array_merge($tugasSekarang, [
            'plan' => $parse['plan'], 'files' => json_encode($berkasUbah, JSON_UNESCAPED_UNICODE),
            'lint' => implode("\n", $petaLint),
        ]), $saran);
    } catch (Throwable $exRingkas) {
        $ringkas = '**Yang dikerjakan:** ' . $parse['plan'] . "\n**Berkas:** " . implode(', ', $berkasUbah)
            . "\n(laporan rinci tidak dapat disusun: " . $exRingkas->getMessage() . ')';
        ai_step($id, 'warn', 'Laporan ringkas tidak dapat disusun otomatis', $exRingkas->getMessage());
    }
    ai_msg($id, 'ai', 'Usulan perubahan sudah siap (BELUM diterapkan).' . "\n\n" . $ringkas . "\n\n"
        . (($lint['ok'] && ai_settings()['auto_test'])
            ? 'Saya **uji sendiri dulu** di salinan aplikasi (tidak perlu Anda tekan tombol apa pun) — '
              . 'hasilnya muncul di percakapan ini.'
            : ($lint['ok']
                ? 'Uji otomatis sedang DIMATIKAN di AI Settings, jadi jalankan uji dari bagian '
                  . 'Rincian teknis bila ingin menerapkan perubahan ini.'
                : 'PERHATIAN: pemeriksaan sintaks menemukan masalah, jadi uji otomatis tidak dijalankan. '
                  . 'Perbaikannya bisa Anda tuliskan di percakapan ini.')));
    audit('AI Usulkan Perubahan', 'AI Developer', $id, null,
        ['berkas' => array_keys($terap['files']), 'sintaks_ok' => $lint['ok']],
        'AI menyusun usulan perubahan untuk: ' . short_text((string)$task['request'], 120));
    /* JEJAK KERJA (traceability) — supaya kegagalan/kejanggalan dapat ditelusuri
       sendiri: apa yang dibaca, apa yang terdampak, apa yang diubah, dan hasil uji. */
    ai_job_beat($id, 'plan', 'done');
    ai_job_beat($id, 'implement', 'done');
    ai_job_checkpoint($id, ['next_mode' => 'test', 'tahap' => 'usulan-tersimpan']);
    ai_trace($id, 'usulan', [
        'berkas_diubah' => $berkasUbah, 'jumlah_ops' => count($parse['ops']),
        'sintaks_ok' => (bool)$lint['ok'], 'putaran_perbaikan' => $catatan,
        'suite' => (string)($pilihSuite['suite'] ?? ($task['suite'] ?: '')), 'halaman_pratinjau' => $halaman,
        'audit' => $auditStatus,
    ]);

    /* ---------- 5. UJI OTOMATIS (ronde 49, permintaan pemilik) ----------
       "uji sendiri di chat AI-nya, tidak usah saya klik tombol uji staging — kelamaan
       dan jadi bug panjang". Karena itu suite uji dijalankan SENDIRI begitu usulan
       selesai disusun. Status `test_status = menunggu` dipakai supaya halaman tetap
       memantau (menunggu) sampai pekerja uji benar-benar mulai. */
    if ($lint['ok'] && ai_settings()['auto_test']) {
        ai_task_update($id, ['test_status' => 'menunggu', 'stage' => 'Usulan siap — uji otomatis di salinan segera dijalankan']);
        ai_step($id, 'info', 'Uji otomatis di salinan dijalankan (tanpa perlu menekan tombol)');
        ai_spawn_worker('test', $id);
    } elseif ($lint['ok']) {
        /* Pemilik mematikan "Uji otomatis" di AI Settings: ujinya tetap bisa
           dijalankan dari rincian teknis, jadi jangan diklaim sudah diuji. */
        ai_task_update($id, ['test_status' => '', 'stage' => 'Usulan siap — uji otomatis dimatikan di AI Settings']);
    } else {
        ai_task_update($id, ['test_status' => '', 'stage' => 'Selesai — pemeriksaan sintaks ADA MASALAH']);
    }
    exit(0);
}

if ($mode === 'test') {
    /* ==========================================================================
     * UJI DI STAGING + AUTO ERROR RECOVERY / SELF-HEALING (ronde 51)
     * ==========================================================================
     * Permintaan pemilik: bila uji menemukan error, AI JANGAN berhenti dan menyerahkan
     * perbaikannya ke pemilik. Alurnya:
     *   DETECT ERROR → ANALYZE ROOT CAUSE → AUTO FIX → TEST ULANG → REGRESSION → FINAL CHECK
     * dan diulang selama masih aman diperbaiki. Semua langkahnya dicatat di ai_traces
     * + Audit Log. Batas: tidak boleh menghapus/melemahkan uji (PASS palsu), dan harus
     * minta persetujuan bila butuh kredensial / keputusan bisnis / penghapusan data.
     * ======================================================================== */
    $patch = json_decode((string)$task['patch'], true) ?: [];
    if (!$patch) {
        ai_task_update($id, ['status' => 'failed', 'error' => 'Belum ada usulan perubahan untuk diuji.', 'stage' => '']);
        exit(1);
    }
    ai_task_update($id, ['status' => 'testing', 'test_status' => 'menyiapkan staging',
        'workflow' => ai_workflow_status('testing')['kode'], 'stage' => 'Menyiapkan folder staging']);
    ai_step($id, 'work', 'Menyiapkan salinan aplikasi (staging) untuk uji');
    $staging = ai_staging_prepare($id);
    ai_task_update($id, ['staging_dir' => $staging['dir']]);

    ai_task_update($id, ['stage' => 'Menerapkan usulan ke staging']);
    ai_step($id, 'work', 'Menerapkan usulan ke SALINAN (aplikasi asli belum disentuh)');
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

    $suite = trim((string)($task['suite'] ?? ''));
    if ($suite === '') $suite = ai_settings()['default_suite'];
    $healOn = ai_settings()['heal_on'];
    $healMaks = ai_settings()['heal_rounds'];

    /**
     * Jalankan pemeriksaan sintaks + suite uji pada kondisi SALINAN SAAT INI.
     * Dipakai berulang oleh loop self-healing (setelah setiap perbaikan).
     */
    $jalankanUji = function (array $berkasUji) use ($id, $suite): array {
        $lint = ai_lint_contents($berkasUji);
        $petaLint = [];
        foreach ($lint['hasil'] as $h) $petaLint[] = ($h['ok'] ? 'OK  ' : 'GAGAL  ') . $h['file'] . ' — ' . $h['msg'];
        $hasil = ['selesai' => false, 'pass' => 0, 'fail' => 0, 'ringkas' => '', 'gagal_baris' => []];
        $log = '';
        if ($lint['ok']) {
            /* Suite uji hanya dijalankan bila sintaksnya sehat — menjalankan suite
               dengan berkas rusak hanya menghasilkan kegagalan yang membingungkan. */
            $err = '';
            $log = ai_run_suite($id, $suite, $err);
            if ($log === null) {
                $hasil = ['selesai' => true, 'pass' => 0, 'fail' => 1, 'ringkas' => (string)$err,
                    'gagal_baris' => ['tidak dapat menjalankan suite: ' . (string)$err], 'ada_total' => true];
            } else {
                ai_task_update($id, ['test_log' => $log]);
                $mulai = time();
                while ((time() - $mulai) < 1800) {
                    $hasil = ai_test_result($log);
                    if (!empty($hasil['selesai'])) break;
                    sleep(3);
                }
            }
        }
        return ['lint' => $lint, 'petaLint' => $petaLint, 'hasil' => $hasil, 'log' => (string)$log];
    };

    /* Kondisi awal: jalankan pemeriksaan & uji. */
    ai_task_update($id, ['stage' => 'Menjalankan suite uji "' . $suite . '" di staging', 'test_status' => 'berjalan']);
    ai_step($id, 'work', 'Menjalankan suite uji "' . $suite . '" di salinan', 'uji ini wajib LULUS sebelum penerapan');
    $j = $jalankanUji($terap['files']);
    $lint = $j['lint']; $petaLint = $j['petaLint']; $hasil = $j['hasil'];

    /* ---- DETEKSI + ANALISIS AKAR MASALAH ---- */
    $logTeks = (!empty($j['log']) && is_file((string)$j['log'])) ? (string)@file_get_contents((string)$j['log']) : '';
    $analisa = ai_error_analyze($lint, $hasil, $logTeks, $terap['files']);
    $healCatatan = [];
    $healRonde = 0;
    $healDihentikan = '';
    $healBlokirDibuang = [];
    $sidikSebelum = '';

    /* ---------- PEMULIHAN HARNESS (ronde 52) ----------
       Bila suite uji TIDAK berjalan sampai selesai (skrip berhenti / 0 pemeriksaan),
       yang bermasalah adalah LINGKUNGAN uji — bukan kode yang diubah. Menambal kode di
       situ justru menyembunyikan masalah, jadi yang benar: jalankan ULANG suite di
       salinan yang baru disiapkan. Ini pemulihan otomatis yang jujur. */
    /* Harness yang gagal biasanya SEKALI jalan (port/sisa proses/lingkungan) — jadi
       percobaan ulang dibatasi tersendiri (bawaan 2×) agar tidak menghabiskan jatah
       putaran perbaikan kode. */
    $harnessCoba = 0;
    $harnessMaks = max(1, (int)setting('ai_harness_retry', '2'));
    while ($analisa['tipe'] === 'uji_tidak_jalan' && $healOn && $harnessCoba < $harnessMaks
        && $healRonde < $healMaks + $harnessMaks) {
        ai_step($id, 'warn', 'Harness uji tidak berjalan sampai selesai — mencoba ULANG di salinan baru',
            short_text((string)$analisa['ringkas'], 200));
        ai_trace($id, 'harness-retry', ['alasan' => $analisa['ringkas'], 'detail' => $analisa['detail']]);
        $healRonde++; $harnessCoba++;
        ai_task_update($id, ['status' => 'testing',
            'test_status' => 'menjalankan ulang uji (percobaan ' . $harnessCoba . '/' . $harnessMaks . ')',
            'workflow' => ai_workflow_status('healing')['kode'],
            'stage' => 'Menjalankan ulang suite uji (percobaan ' . $harnessCoba . '/' . $harnessMaks . ')']);
        /* Salinan baru: buang salinan lama (mungkin rusak setengah jalan) lalu siapkan lagi. */
        ai_staging_cleanup_runs($id);
        ai_rmdir(ai_staging_dir($id));
        $stagingUlang = ai_staging_prepare($id);
        ai_task_update($id, ['staging_dir' => $stagingUlang['dir']]);
        $tulisUlang = ai_staging_apply($id, $terap['files']);
        if (!empty($tulisUlang['ok'])) {
            $j = $jalankanUji($terap['files']);
            $lint = $j['lint']; $petaLint = $j['petaLint']; $hasil = $j['hasil'];
            $logTeks = (!empty($j['log']) && is_file((string)$j['log']))
                ? (string)@file_get_contents((string)$j['log']) : '';
            $analisa = ai_error_analyze($lint, $hasil, $logTeks, $terap['files']);
            $healCatatan[] = 'percobaan ' . $harnessCoba . ': menjalankan ulang suite uji (harness) — '
                . ($analisa['ada'] ? 'masih ada masalah' : 'BERSIH');
            if (!$analisa['ada']) break;   // sudah bersih → lanjut ke pemeriksaan akhir
            ai_step($id, $analisa['ada'] ? 'warn' : 'ok',
                $analisa['ada'] ? 'Uji ulang (harness): masih ada masalah' : 'Uji ulang (harness): BERSIH',
                'PASS ' . num((int)($hasil['pass'] ?? 0)) . ' · FAIL ' . num((int)($hasil['fail'] ?? 0)));
            ai_trace($id, 'heal-uji-ulang', ['putaran' => $healRonde, 'harness' => true,
                'pass' => (int)($hasil['pass'] ?? 0), 'fail' => (int)($hasil['fail'] ?? 0),
                'lint_ok' => (bool)$lint['ok'], 'bersih' => !$analisa['ada']]);
        }
    }

    while ($analisa['ada'] && $healOn && $healRonde < $healMaks) {
        /* Tidak boleh "memperbaiki" bila butuh keputusan pemilik. */
        if (!$analisa['bisa_otomatis']) {
            $healDihentikan = (string)$analisa['alasan'];
            if ($analisa['tipe'] === 'uji_tidak_jalan') {
                $healDihentikan = 'Suite uji tidak dapat dijalankan sampai selesai di salinan '
                    . '(sudah dicoba ulang ' . $harnessCoba . '×). Ini masalah LINGKUNGAN uji, bukan kode '
                    . 'yang diubah — AI tidak akan menambal kode untuk "menutupi" kegagalan suite. '
                    . 'Perubahan tetap tersimpan & dapat diuji ulang setelah lingkungan uji sehat.';
            }
            ai_step($id, 'warn', 'Perbaikan otomatis DIHENTIKAN — butuh keputusan pemilik', $healDihentikan);
            ai_trace($id, 'heal-berhenti', ['tipe' => $analisa['tipe'], 'alasan' => $healDihentikan,
                'detail' => $analisa['detail']]);
            break;
        }
        /* Bila hasilnya TIDAK berubah dari putaran sebelumnya, memperbaiki lagi hanya
           membuang waktu & token → berhenti dan minta arahan pemilik. */
        $sidik = md5(ai_error_text($analisa));
        if ($sidik === $sidikSebelum) {
            $healDihentikan = 'Perbaikan sebelumnya tidak mengubah hasil pemeriksaan — '
                . 'masalah ini perlu arahan pemilik (mis. bagian mana yang boleh diubah).';
            ai_step($id, 'warn', 'Perbaikan otomatis berhenti: hasil tidak berubah', $healDihentikan);
            ai_trace($id, 'heal-berhenti', ['tipe' => $analisa['tipe'], 'alasan' => $healDihentikan]);
            break;
        }
        $sidikSebelum = $sidik;
        $healRonde++;
        ai_task_update($id, ['status' => 'testing', 'test_status' => 'memperbaiki error (' . $healRonde . '/' . $healMaks . ')',
            'workflow' => ai_workflow_status('healing')['kode'],
            'stage' => 'Menemukan error → memperbaiki sendiri (putaran ' . $healRonde . '/' . $healMaks . ')']);
        ai_step($id, 'warn', 'Error terdeteksi: ' . ai_error_text($analisa),
            'AI akan menganalisis akar masalah lalu memperbaikinya sendiri');
        ai_trace($id, 'error-terdeteksi', ['putaran' => $healRonde, 'tipe' => $analisa['tipe'],
            'ringkas' => $analisa['ringkas'], 'detail' => $analisa['detail'],
            'bisa_otomatis' => (bool)$analisa['bisa_otomatis'], 'catatan' => $analisa['alasan']]);

        /* AUTO FIX: minta AI memperbaiki akar masalahnya, dengan isi berkas SALINAN
           saat ini (jadi perbaikan dihitung di atas perubahan sebelumnya). */
        $isiKini = ai_staging_read($id, array_keys($terap['files']));
        if (!$isiKini) $isiKini = $terap['files'];
        $promptHeal = ai_heal_prompt((string)$task['request'], $analisa,
            implode("\n", $petaLint), (string)($hasil['ringkas'] ?? ''), $isiKini,
            (string)($task['intent'] ?? ''), ai_conversation_text($id, 4000));
        $jwb = ai_call_chain([
            ['role' => 'user', 'text' => ai_system_prompt()],
            ['role' => 'model', 'text' => 'Baik, saya akan memperbaiki akar masalahnya dan menjawab hanya dengan JSON.'],
            ['role' => 'user', 'text' => $promptHeal],
        ], 0.1, 32768, ['task_id' => $id, 'purpose' => 'perbaiki error (putaran ' . $healRonde . ')']);
        $rawHeal = (string)$jwb['text'];
        /* Berkas yang dibuat patch awal belum ada di aplikasi asli → diizinkan saat
           memperbaiki (kalau tidak, perbaikan atas berkas baru selalu ditolak). */
        $parseHeal = ai_patch_parse($rawHeal, array_keys($terap['files']));
        if (empty($parseHeal['ok']) || empty($parseHeal['ops'])) {
            $healDihentikan = 'AI belum dapat menyusun perbaikan yang dapat diterapkan'
                . (empty($parseHeal['ok']) ? ' (' . (string)$parseHeal['error'] . ')' : ' (tidak ada perubahan diusulkan)')
                . '. Perlu arahan pemilik untuk bagian ini.';
            ai_step($id, 'error', 'Perbaikan otomatis gagal menyusun patch', $healDihentikan);
            ai_trace($id, 'heal-gagal', ['putaran' => $healRonde, 'error' => (string)$parseHeal['error'],
                'raw' => short_text($rawHeal, 2000)]);
            break;
        }
        /* PENJAGA ANTI-JALAN-PINTAS: tolak perbaikan yang menghapus/melemahkan uji. */
        $guard = ai_heal_guard($parseHeal['ops'], ($analisa['tipe'] !== 'sintaks'));
        if (!$guard['ok']) {
            $alasanTolak = [];
            foreach ($guard['ditolak'] as $d) $alasanTolak[] = 'op#' . $d['op'] . ': ' . $d['alasan'];
            $healBlokirDibuang[] = $alasanTolak;
            ai_step($id, 'error', 'Perbaikan DITOLAK sistem (jalan pintas yang menyembunyikan error)',
                implode(' · ', $alasanTolak));
            ai_trace($id, 'heal-ditolak', ['putaran' => $healRonde, 'alasan' => $alasanTolak,
                'ops' => array_slice($parseHeal['ops'], 0, 4)]);
            /* Minta AI mengulang TANPA jalan pintas itu (satu kesempatan per putaran). */
            $healDihentikan = 'Perbaikan yang diusulkan menyentuh pemeriksaan uji (ditolak sistem). '
                . 'AI tidak boleh menghapus/melemahkan uji — perlu perbaikan kode yang sebenarnya, '
                . 'atau arahan pemilik bila bagian ini memang tidak boleh diubah.';
            break;
        }
        /* Terapkan perbaikan ke SALINAN lalu uji ulang. */
        $terapHeal = ai_apply_patch_to_content(array_merge($patch, $parseHeal['ops']));
        if (empty($terapHeal['ok'])) {
            $healDihentikan = 'Perbaikan tidak dapat diterapkan: ' . (string)$terapHeal['error'];
            ai_step($id, 'error', 'Perbaikan otomatis tidak dapat diterapkan', $healDihentikan);
            ai_trace($id, 'heal-gagal', ['putaran' => $healRonde, 'error' => (string)$terapHeal['error']]);
            break;
        }
        $tulisHeal = ai_staging_apply($id, $terapHeal['files']);
        if (empty($tulisHeal['ok'])) {
            $healDihentikan = 'Gagal menulis perbaikan ke salinan: ' . (string)$tulisHeal['error'];
            break;
        }
        $patch = array_merge($patch, $parseHeal['ops']);
        $terap = $terapHeal;
        $healCatatan[] = 'putaran ' . $healRonde . ': ' . trim((string)($parseHeal['plan'] ?? ''))
            . ' (' . count($parseHeal['ops']) . ' perubahan)';
        ai_step($id, 'work', 'Perbaikan diterapkan (putaran ' . $healRonde . ')',
            trim((string)($parseHeal['plan'] ?? '')) . ' · ' . count($parseHeal['ops']) . ' perubahan');
        ai_trace($id, 'heal-perbaikan', ['putaran' => $healRonde, 'plan' => (string)($parseHeal['plan'] ?? ''),
            'ops' => count($parseHeal['ops']), 'berkas' => array_values(array_unique(array_map(
                fn($o) => (string)($o['file'] ?? ''), $parseHeal['ops'])))]);

        /* TEST ULANG + REGRESSION (suite yang sama; bila lulus, hasilnya juga
           menggambarkan regresi karena suite memeriksa fitur terkait). */
        ai_task_update($id, ['stage' => 'Menguji ulang setelah perbaikan (putaran ' . $healRonde . ')',
            'test_status' => 'menguji ulang']);
        $j = $jalankanUji($terap['files']);
        $lint = $j['lint']; $petaLint = $j['petaLint']; $hasil = $j['hasil'];
        $logTeks = (!empty($j['log']) && is_file((string)$j['log']))
            ? (string)@file_get_contents((string)$j['log']) : '';
        $analisa = ai_error_analyze($lint, $hasil, $logTeks, $terap['files']);
        ai_step($id, $analisa['ada'] ? 'warn' : 'ok',
            $analisa['ada'] ? 'Uji ulang: masih ada masalah' : 'Uji ulang setelah perbaikan: BERSIH',
            'PASS ' . num((int)($hasil['pass'] ?? 0)) . ' · FAIL ' . num((int)($hasil['fail'] ?? 0)));
        ai_trace($id, 'heal-uji-ulang', ['putaran' => $healRonde, 'pass' => (int)($hasil['pass'] ?? 0),
            'fail' => (int)($hasil['fail'] ?? 0), 'lint_ok' => (bool)$lint['ok'], 'bersih' => !$analisa['ada']]);
    }

    /* ---- FINAL CHECK ---- */
    $final = ai_heal_final_check($lint, $hasil);
    $adaPemeriksaan = ((int)($hasil['pass'] ?? 0) + (int)($hasil['fail'] ?? 0)) > 0;
    $lulus = !empty($hasil['selesai']) && !empty($hasil['ada_total']) && $adaPemeriksaan
        && (int)($hasil['fail'] ?? 0) === 0 && $lint['ok'];
    /* JANGAN pernah menyatakan selesai bila masih ada error yang dapat diperbaiki. */
    if ($lulus && $analisa['ada']) $lulus = false;

    $ringkas = trim(implode("\n", $petaLint)) . "\n\n" . (string)($hasil['ringkas'] ?? '');
    if ($healCatatan) {
        $ringkas = "PERBAIKAN OTOMATIS (" . count($healCatatan) . " putaran):\n- "
            . implode("\n- ", $healCatatan) . "\n\n" . $ringkas;
    }
    if (!$lulus && !empty($hasil['ada_total']) && !$adaPemeriksaan) {
        $ringkas .= "\n\nCATATAN: suite tidak menjalankan pemeriksaan apa pun (0 PASS / 0 FAIL) — "
            . 'kemungkinan salinan uji bermasalah. Hasil ini TIDAK dianggap lulus.';
    }
    if (!$final['ok']) {
        $ringkas .= "\n\nFINAL CHECK belum bersih:\n- " . implode("\n- ", array_slice($final['catatan'], 0, 6));
    }

    $pesanUji = '';
    if (!$lulus) {
        if ($healDihentikan !== '') {
            $pesanUji = 'Perbaikan otomatis berhenti dan MEMINTA KEPUTUSAN Anda: ' . $healDihentikan;
        } elseif (!$adaPemeriksaan) {
            $pesanUji = 'Uji TIDAK dapat diandalkan: suite tidak menjalankan pemeriksaan apa pun '
                . '(0 PASS / 0 FAIL) — biasanya karena salinan uji bermasalah saat suite dijalankan. '
                . 'Penerapan tetap terkunci. Coba tekan "Jalankan Uji di Staging" sekali lagi.';
        } elseif (!$lint['ok']) {
            $pesanUji = 'Pemeriksaan sintaks masih bermasalah setelah ' . $healRonde . ' putaran perbaikan otomatis '
                . '— penerapan terkunci. Baca bagian "Pemeriksaan sintaks".';
        } else {
            $pesanUji = 'Uji belum lulus: ' . num((int)($hasil['fail'] ?? 0)) . ' pemeriksaan gagal'
                . ($healRonde > 0 ? ' (setelah ' . $healRonde . ' putaran perbaikan otomatis)' : '') . '. '
                . 'Penerapan terkunci sampai uji lulus.';
        }
    }
    /* Ringkasan + daftar pemeriksaan yang GAGAL disimpan ke basis data supaya
       alasannya tetap terbaca walau berkas log sudah dibersihkan. */
    $teksUji = 'PASS ' . (int)($hasil['pass'] ?? 0) . ' · FAIL ' . (int)($hasil['fail'] ?? 0);
    if ($healCatatan) $teksUji = "Perbaikan otomatis: " . count($healCatatan) . " putaran\n" . $teksUji;
    if (!empty($hasil['gagal_baris'])) {
        $teksUji .= "\n\nPemeriksaan yang GAGAL:\n- " . implode("\n- ", array_slice((array)$hasil['gagal_baris'], 0, 12));
    }
    if (trim((string)($hasil['ringkas'] ?? '')) !== '') $teksUji .= "\n\n" . (string)$hasil['ringkas'];

    /* Status akhir: `tested` hanya bila BENAR-BENAR bersih. Bila berhenti karena butuh
       keputusan pemilik → `blocked` (bukan "selesai", bukan pula "gagal biasa"). */
    $statusAkhir = $lulus ? 'tested' : ($healDihentikan !== '' ? 'blocked' : 'failed');
    /* Langkah job pada tahap uji. */
    ai_job_beat($id, 'test', 'done', ['note' => 'PASS ' . (int)($hasil['pass'] ?? 0) . ' · FAIL ' . (int)($hasil['fail'] ?? 0)]);
    ai_job_beat($id, 'regression', $lulus ? 'done' : 'running');
    ai_job_beat($id, 'final_audit', !empty($final['ok']) ? 'done' : 'running');
    ai_job_checkpoint($id, ['next_mode' => 'test', 'tahap' => 'uji', 'lulus' => (bool)$lulus]);

    /* RONDE 51: bila ada perbaikan otomatis, DIFF & daftar berkas disegarkan supaya
       pemilik melihat perbedaan yang BENAR (sesuai kondisi yang diuji & akan diterapkan). */
    $ubahanDiff = '';
    if ($healRonde > 0) {
        $bagianDiff = [];
        foreach ($terap['files'] as $rel => $baru) {
            $lama = in_array($rel, $terap['created'], true) ? '' : (string)(ai_read($rel)['text'] ?? '');
            $bagianDiff[] = ai_diff_text($rel, $lama, $baru);
        }
        $ubahanDiff = implode("\n\n", $bagianDiff);
        if ($healCatatan) {
            $ubahanDiff = "Catatan perbaikan otomatis:\n- " . implode("\n- ", $healCatatan)
                . "\n\n" . $ubahanDiff;
        }
    }
    ai_task_update($id, [
        'status' => $statusAkhir,
        'test_status' => $lulus ? 'lulus' : ($healDihentikan !== '' ? 'butuh keputusan Anda' : 'perlu diperiksa'),
        'workflow' => ai_workflow_status($statusAkhir)['kode'],
        'test_ringkas' => $teksUji,
        'lint' => implode("\n", $petaLint),
        'stage' => $lulus
            ? ('Uji selesai: LULUS' . ($healRonde > 0 ? ' setelah ' . $healRonde . ' perbaikan otomatis' : '')
                . ' — menunggu persetujuan')
            : ($healDihentikan !== '' ? 'Uji selesai: BUTUH KEPUTUSAN Anda — ' . short_text($healDihentikan, 160)
                : 'Uji selesai: ADA KEGAGALAN'),
        'error' => $pesanUji,
        /* Patch final (termasuk perbaikan otomatis) supaya penerapan menghasilkan
           kondisi yang SAMA dengan yang diuji. */
        'patch' => json_encode(array_values($patch), JSON_UNESCAPED_UNICODE),
        'files' => json_encode(array_keys($terap['files']), JSON_UNESCAPED_UNICODE),
    ] + ($ubahanDiff !== '' ? ['diff' => $ubahanDiff] : []));
    ai_step($id, $lulus ? 'ok' : ($healDihentikan !== '' ? 'warn' : 'error'),
        $lulus ? ('Uji LULUS' . ($healRonde > 0 ? ' setelah ' . $healRonde . ' perbaikan otomatis' : '')
            . ' — perubahan siap Anda setujui')
               : ($healDihentikan !== '' ? 'Butuh keputusan Anda untuk melanjutkan'
                    : 'Uji BELUM lulus (penerapan masih terkunci)'),
        'PASS ' . num((int)($hasil['pass'] ?? 0)) . ' · FAIL ' . num((int)($hasil['fail'] ?? 0))
        . ($healRonde > 0 ? ' · ' . $healRonde . ' putaran perbaikan otomatis' : '')
        . (!$adaPemeriksaan ? ' — suite tidak menjalankan pemeriksaan (salinan bermasalah?)' : '')
        . (!empty($hasil['berhenti']) ? ' — skrip uji berhenti di tengah' : ''));

    /* Pesan untuk pemilik — jujur tentang apa yang terjadi, termasuk perbaikan otomatis. */
    $msgHeal = $healCatatan
        ? "\n\n**Perbaikan otomatis dijalankan (" . count($healCatatan) . " putaran):**\n- "
            . implode("\n- ", array_slice($healCatatan, 0, 5))
        : '';
    if ($lulus) {
        ai_msg($id, 'ai', '**Selesai.** Uji di salinan **LULUS** (' . num((int)($hasil['pass'] ?? 0)) . ' pemeriksaan).'
            . $msgHeal
            . "\n\n**Silakan cek pratinjau perubahan** lewat tombol Buka pratinjau pada kotak di bawah — "
            . 'halaman aplikasi sudah memakai perubahan ini (dari salinan, jadi aplikasi asli belum berubah).'
            . "\n\nBila sudah cocok, ketik **lanjutkan dan terapkan** di kotak chat di bawah (isi juga kata sandi Anda). "
            . 'Bila masih ada yang kurang, tulis saja perbaikannya di kotak yang sama — saya lanjutkan.');
    } elseif ($healDihentikan !== '') {
        /* Pesan ini sengaja disusun sebagai satu variabel: teks panjang bertanda kutip
           mudah membuat kutip di kode PHP tidak seimbang (bug kecil yang langsung
           terdeteksi `php -l` — pelajaran saat memasang fitur ini). */
        $pesanButuhKeputusan = '**Perbaikan otomatis berhenti — saya butuh keputusan Anda.**' . "\n\n"
            . 'Alasannya: ' . $healDihentikan . $msgHeal . "\n\n"
            . 'Yang dapat saya perbaiki sendiri SUDAH dicoba (' . $healRonde . ' putaran), dan saya '
            . 'tidak akan memakai jalan pintas seperti menghapus atau melemahkan pemeriksaan uji. '
            . 'Silakan tulis keputusan/arahan Anda di kotak chat ini (mis. bagian X boleh diubah, '
            . 'atau lewati pemeriksaan Y karena memang belum dipakai) — saya lanjutkan dari sana.';
        ai_msg($id, 'ai', $pesanButuhKeputusan);
    } else {
        ai_msg($id, 'ai', 'Uji di salinan **BELUM lulus** (' . num((int)($hasil['fail'] ?? 0)) . ' pemeriksaan gagal), jadi penerapan '
            . 'masih terkunci — dan memang seharusnya begitu.'
            . $msgHeal
            . "\n\nAlasan lengkapnya ada pada kotak hasil di bawah. Bila perlu saya perbaiki lagi dengan "
            . 'arahan yang lebih spesifik, tulis di kotak chat ini.');
    }
    /* Bersihkan folder sementara agar staging tidak menumpuk di disk. */
    ai_staging_cleanup_runs($id);
    ai_prune_temp();
    ai_trace($id, 'uji', ['suite' => $suite, 'pass' => (int)($hasil['pass'] ?? 0),
        'fail' => (int)($hasil['fail'] ?? 0), 'lulus' => (bool)$lulus, 'lint_ok' => (bool)$lint['ok'],
        'ada_total' => (bool)($hasil['ada_total'] ?? false), 'berhenti' => (bool)($hasil['berhenti'] ?? false),
        'gagal_baris' => array_slice((array)($hasil['gagal_baris'] ?? []), 0, 8),
        'perbaikan_otomatis' => $healRonde, 'perbaikan_ditolak' => $healBlokirDibuang,
        'dihentikan' => $healDihentikan, 'final_check' => $final]);
    ai_trace($id, 'final-check', ['bersih' => (bool)$final['ok'], 'catatan' => $final['catatan'],
        'perbaikan_otomatis' => $healRonde, 'status' => $statusAkhir]);
    audit($lulus ? 'AI Uji di Staging' : 'AI Uji di Staging Gagal', 'AI Developer', $id, null,
        ['suite' => $suite, 'pass' => (int)($hasil['pass'] ?? 0), 'fail' => (int)($hasil['fail'] ?? 0),
         'lulus' => $lulus, 'perbaikan_otomatis' => $healRonde, 'dihentikan' => $healDihentikan],
        'Hasil uji staging: ' . ($lulus ? 'LULUS' : ($healDihentikan !== '' ? 'BUTUH KEPUTUSAN' : 'ADA KEGAGALAN'))
        . ($healRonde > 0 ? ' (setelah ' . $healRonde . ' perbaikan otomatis)' : ''));
    foreach ($healCatatan as $i => $cat) {
        audit('AI Perbaikan Otomatis', 'AI Developer', $id, null,
            ['putaran' => $i + 1, 'catatan' => $cat], 'AI memperbaiki error sendiri lalu menguji ulang');
    }
    ai_job_finish($id, $lulus ? 'READY_TO_APPLY' : ($healDihentikan !== '' ? 'BLOCKED' : 'FAILED'),
        (string)$pesanUji, ['note' => 'PASS ' . (int)($hasil['pass'] ?? 0) . ' · FAIL ' . (int)($hasil['fail'] ?? 0)]);
    exit(0);
}

exit(0);
