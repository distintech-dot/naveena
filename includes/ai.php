<?php
/**
 * AI DEVELOPER — mesin bantu pengembangan (revisi / perbaikan / tambah fitur).
 * ==========================================================================
 *
 * Permintaan pemilik: menu AI Developer yang dapat membantu merevisi, memperbaiki,
 * dan menambah fitur di web ini sendiri, dengan alur seperti agen:
 *
 *   baca kode → analisis permintaan → usulkan perubahan (patch)
 *      → PRATINJAU (diff + pemeriksaan sintaks) → JALANKAN UJI di folder staging
 *      → Super Admin setujui → TERAPKAN ke aplikasi → uji ulang
 *
 * Prinsip yang dipegang modul ini:
 *   1. **AI TIDAK PERNAH menulis berkas aplikasi secara langsung.** AI hanya
 *      menghasilkan teks patch; penerapan dilakukan kode aplikasi SETELAH
 *      persetujuan Super Admin, dan selalu didahului salinan pengaman berkas.
 *   2. **Setiap patch wajib cocok** (`search` harus ditemukan PERSIS). Bila tidak
 *      cocok, patch DITOLAK seluruhnya — tidak ada penerapan sebagian yang bisa
 *      merusak berkas.
 *   3. **Cakupan berkas dibatasi** setelan (`ai_scope`): hanya folder aplikasi dan
 *      skrip uji. Path dibuat relatif + ditolak bila keluar dari cakupan.
 *   4. **Uji dijalankan di folder STAGING** (salinan aplikasi + skrip uji), bukan
 *      di aplikasi terbit — sehingga menjalankan uji tidak pernah menyentuh data
 *      produksi.
 *   5. Rahasia (kunci API) TIDAK pernah ditulis ke halaman/JSON/log.
 */
declare(strict_types=1);

/** Setelan AI Developer. */
function ai_settings(): array
{
    return [
        'enabled'  => setting('ai_enabled', '1') === '1',
        'provider' => (string)setting('ai_provider', 'gemini'),
        'api_key'  => (string)setting('ai_api_key', ''),
        'model'    => (string)setting('ai_model', 'gemini-flash-latest'),
        'scope'    => array_values(array_filter(array_map('trim', explode(',', (string)setting('ai_scope', 'naveena,naveena_dev/test'))))),
        'max_files' => max(1, (int)setting('ai_max_files', '12')),
        'max_file_kb' => max(4, (int)setting('ai_max_file_kb', '120')),
        'default_suite' => (string)setting('ai_default_suite', 'sintaks-js'),
    ];
}

/** Pilihan penyedia AI. */
function ai_providers(): array
{
    return [
        'gemini' => ['name' => 'Google Gemini', 'url' => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent'],
        'openai' => ['name' => 'OpenAI (ChatGPT)', 'url' => 'https://api.openai.com/v1/chat/completions'],
        /* Penyedia tiruan: dipakai uji otomatis supaya alur lengkap dapat diperiksa
           TANPA jaringan & tanpa memakai kuota kunci milik pemilik. Jawabannya
           dibaca dari setelan `ai_mock_reply`. */
        'mock'   => ['name' => 'Tiruan (untuk uji otomatis)', 'url' => ''],
    ];
}

/** Pilihan model per penyedia (daftar nyata yang tersedia untuk kunci pemilik). */
function ai_model_options(): array
{
    return [
        'gemini' => ['gemini-flash-latest', 'gemini-3-flash-preview', 'gemini-3.5-flash', 'gemini-3.8-flash', 'gemini-pro-latest'],
        'openai' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1'],
        'mock'   => ['mock-1'],
    ];
}

/** Apakah AI siap dipakai (aktif + kunci terisi + cakupan ada)? */
function ai_ready(): bool
{
    $s = ai_settings();
    if (!$s['enabled']) return false;
    if ($s['provider'] !== 'mock' && trim($s['api_key']) === '') return false;
    return $s['scope'] !== [];
}

/** Alasan singkat bila AI belum siap (dipakai tampilan, tanpa membocorkan kunci). */
function ai_ready_text(): string
{
    $s = ai_settings();
    if (!$s['enabled']) return 'AI Developer sedang dimatikan di AI Settings.';
    if ($s['provider'] !== 'mock' && trim($s['api_key']) === '') return 'Kunci API belum diisi di AI Settings.';
    if ($s['scope'] === []) return 'Cakupan folder belum diatur di AI Settings.';
    return 'Siap dipakai (' . (ai_providers()[$s['provider']]['name'] ?? $s['provider']) . ' · ' . $s['model'] . ').';
}

/* ------------------------------------------------------------------ *
 * PEMANGGILAN PENYEDIA AI
 * ------------------------------------------------------------------ */

/**
 * Kirim satu percakapan ke penyedia AI dan kembalikan teks jawabannya.
 *
 * @param array $messages [['role' => 'user'|'model', 'text' => '...'], ...]
 * @return array{ok:bool,text:string,error:string,raw:string}
 */
function ai_call(array $messages, float $temperature = 0.2, int $maxTokens = 8192, ?string $modelOverride = null): array
{
    $s = ai_settings();
    if ($modelOverride !== null && $modelOverride !== '') $s['model'] = $modelOverride;
    if (!$s['enabled']) return ['ok' => false, 'text' => '', 'error' => 'AI Developer dimatikan.', 'raw' => ''];
    if ($s['provider'] === 'mock') {
        /* Jawaban tiruan: dipakai uji otomatis. Diambil dari setelan sehingga uji
           dapat menentukan persis apa yang "dikatakan" AI. */
        $reply = (string)setting('ai_mock_reply', '');
        return $reply === ''
            ? ['ok' => false, 'text' => '', 'error' => 'Jawaban tiruan belum disetel (ai_mock_reply kosong).', 'raw' => '']
            : ['ok' => true, 'text' => $reply, 'error' => '', 'raw' => 'mock'];
    }
    if (trim($s['api_key']) === '') return ['ok' => false, 'text' => '', 'error' => 'Kunci API belum diisi di AI Settings.', 'raw' => ''];

    $prov = ai_providers()[$s['provider']] ?? null;
    if (!$prov) return ['ok' => false, 'text' => '', 'error' => 'Penyedia AI tidak dikenal.', 'raw' => ''];

    if ($s['provider'] === 'gemini') {
        $url = str_replace('{model}', rawurlencode($s['model']), (string)$prov['url']);
        $contents = [];
        foreach ($messages as $m) {
            $contents[] = ['role' => $m['role'] === 'model' ? 'model' : 'user',
                           'parts' => [['text' => (string)$m['text']]]];
        }
        $payload = [
            'contents' => $contents,
            'generationConfig' => ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens],
        ];
        $headers = ['Content-Type: application/json', 'x-goog-api-key: ' . $s['api_key']];
        $res = ai_http_post($url, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $headers);
        if (!$res['ok']) return ['ok' => false, 'text' => '', 'error' => $res['error'], 'raw' => $res['body']];
        $j = json_decode($res['body'], true);
        if (isset($j['error'])) {
            return ['ok' => false, 'text' => '', 'error' => 'Penyedia menolak: ' . (string)($j['error']['message'] ?? 'tidak diketahui'), 'raw' => $res['body']];
        }
        $text = '';
        foreach ((array)($j['candidates'][0]['content']['parts'] ?? []) as $part) {
            $text .= (string)($part['text'] ?? '');
        }
        if (trim($text) === '') return ['ok' => false, 'text' => '', 'error' => 'Jawaban AI kosong.', 'raw' => $res['body']];
        return ['ok' => true, 'text' => $text, 'error' => '', 'raw' => $res['body']];
    }

    /* OpenAI (dan penyedia bergaya sama) */
    $payload = [
        'model' => $s['model'],
        'messages' => array_map(fn($m) => ['role' => $m['role'] === 'model' ? 'assistant' : 'user', 'content' => (string)$m['text']], $messages),
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
    ];
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $s['api_key']];
    $res = ai_http_post((string)$prov['url'], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $headers);
    if (!$res['ok']) return ['ok' => false, 'text' => '', 'error' => $res['error'], 'raw' => $res['body']];
    $j = json_decode($res['body'], true);
    if (isset($j['error'])) {
        return ['ok' => false, 'text' => '', 'error' => 'Penyedia menolak: ' . (string)($j['error']['message'] ?? 'tidak diketahui'), 'raw' => $res['body']];
    }
    $text = (string)($j['choices'][0]['message']['content'] ?? '');
    if (trim($text) === '') return ['ok' => false, 'text' => '', 'error' => 'Jawaban AI kosong.', 'raw' => $res['body']];
    return ['ok' => true, 'text' => $text, 'error' => '', 'raw' => $res['body']];
}

/** POST JSON sederhana (curl bila ada, kalau tidak stream context). */
function ai_http_post(string $url, string $body, array $headers, int $timeout = 120): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 20,
        ]);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string)curl_error($ch);
        curl_close($ch);
        if ($out === false) return ['ok' => false, 'body' => '', 'error' => 'Gagal menghubungi penyedia AI: ' . $err];
        if ($code < 200 || $code >= 300) {
            return ['ok' => true, 'body' => (string)$out, 'error' => ''];   // biar pesan asli provider yang dibaca
        }
        return ['ok' => true, 'body' => (string)$out, 'error' => ''];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
        'timeout' => $timeout, 'ignore_errors' => true,
    ]]);
    $out = @file_get_contents($url, false, $ctx);
    if ($out === false) return ['ok' => false, 'body' => '', 'error' => 'Gagal menghubungi penyedia AI (jaringan).'];
    return ['ok' => true, 'body' => (string)$out, 'error' => ''];
}

/**
 * Panggilan AI dengan PERCOBAAN ULANG + model cadangan.
 *
 * Model gratis sering menjawab "high demand" / kelebihan beban (HTTP 503) atau
 * "rate limit" (429) secara sementara. Tanpa penanganan, satu gangguan sesaat
 * membuat tugas gagal dan pengguna harus mengulang dari awal. Di sini panggilan
 * dicoba beberapa kali, lalu dicoba juga dengan MODEL LAIN dari penyedia yang
 * sama, sehingga peluang berhasil jauh lebih besar.
 */
function ai_call_resilient(array $messages, float $temperature = 0.2, int $maxTokens = 8192): array
{
    $s = ai_settings();
    $utama = $s['model'];
    $cadangan = [];
    foreach ((ai_model_options()[$s['provider']] ?? []) as $m) {
        if ($m !== $utama && $m !== 'mock-1') $cadangan[] = $m;
    }
    $model = [$utama];
    foreach (array_slice($cadangan, 0, 2) as $c) $model[] = $c;   // utama + maksimal 2 cadangan

    $pesanTerakhir = '';
    foreach ($model as $i => $m) {
        for ($coba = 1; $coba <= 2; $coba++) {
            $r = ai_call($messages, $temperature, $maxTokens, $m);
            if ($r['ok']) return $r;
            $pesanTerakhir = $r['error'];
            /* Hanya gangguan SEMENTARA yang layak diulang/dicoba model lain. */
            $sementara = (bool)preg_match('/high demand|overloaded|rate limit|quota|temporarily|503|429|timeout|Gagal menghubungi/i', $r['error']);
            if (!$sementara) return $r;
            if ($coba === 1) sleep(2);
        }
        if ($i + 1 < count($model)) sleep(1);
    }
    return ['ok' => false, 'text' => '', 'raw' => '',
        'error' => 'Penyedia AI sedang sibuk/kelebihan beban pada semua model yang dicoba ('
            . implode(', ', $model) . '). ' . $pesanTerakhir
            . ' Silakan coba lagi beberapa saat lagi, atau ganti model di AI Settings.'];
}

/** Uji koneksi penyedia AI: kirim pertanyaan sangat pendek. */
function ai_provider_test(?string &$err = null): bool
{
    $r = ai_call([['role' => 'user', 'text' => 'Balas tepat satu kata: OK']], 0.0, 16);
    if ($r['ok']) return true;
    $err = $r['error'] !== '' ? $r['error'] : 'Tidak ada jawaban.';
    return false;
}

/* ------------------------------------------------------------------ *
 * MEMBACA KODE (cakupan terbatas + aman)
 * ------------------------------------------------------------------ */

/** Akar workspace (folder induk aplikasi) — semua path dihitung dari sini. */
function ai_root(): string { return dirname(APP_DIR); }

/** Daftar berkas yang BOLEH dibaca/diubah AI (mengikuti setelan ai_scope). */
function ai_scope_files(): array
{
    $s = ai_settings();
    $out = [];
    foreach ($s['scope'] as $rel) {
        $rel = trim($rel, '/');
        if ($rel === '' || strpos($rel, '..') !== false) continue;
        $base = ai_root() . '/' . $rel;
        if (!is_dir($base)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $path = $f->getPathname();
            /* Hanya berkas kode yang masuk akal untuk diubah AI (berkas biner,
               unggahan, database, dan salinan cadangan TIDAK pernah ikut). */
            $ext = strtolower($f->getExtension());
            if (!in_array($ext, ['php', 'js', 'css', 'md', 'sh', 'json'], true)) continue;
            if (strpos($path, '/assets/vendor/') !== false) continue;
            if (strpos($path, '/node_modules/') !== false) continue;
            $relPath = substr($path, strlen(ai_root()) + 1);
            $out[$relPath] = ['rel' => $relPath, 'size' => $f->getSize()];
        }
    }
    ksort($out);
    return array_values($out);
}

/** Path absolut sebuah berkas cakupan (null bila di luar cakupan / tidak ada). */
function ai_path(string $rel): ?string
{
    $rel = trim(str_replace('\\', '/', $rel), '/');
    if ($rel === '' || strpos($rel, '..') !== false) return null;
    $s = ai_settings();
    $masuk = false;
    foreach ($s['scope'] as $sc) {
        $sc = trim($sc, '/');
        if ($sc !== '' && strpos($rel, $sc . '/') === 0) { $masuk = true; break; }
    }
    if (!$masuk) return null;
    $abs = ai_root() . '/' . $rel;
    return file_exists($abs) ? $abs : null;
}

/** Isi berkas (dibatasi ukuran; berkas besar dipotong dengan penanda jujur). */
function ai_read(string $rel): array
{
    $abs = ai_path($rel);
    if ($abs === null) return ['ok' => false, 'text' => '', 'error' => 'Berkas di luar cakupan atau tidak ada: ' . $rel];
    $s = ai_settings();
    $max = $s['max_file_kb'] * 1024;
    $size = (int)filesize($abs);
    $text = (string)file_get_contents($abs);
    $dipotong = false;
    if ($size > $max) { $text = substr($text, 0, $max); $dipotong = true; }
    return ['ok' => true, 'text' => $text, 'error' => '', 'size' => $size, 'truncated' => $dipotong];
}

/**
 * Pencarian teks sederhana pada berkas cakupan (dipakai AI memilih berkas yang
 * relevan, dan bila AI tidak mengembalikan daftar berkas).
 *
 * @return array<int,array{rel:string,line:int,text:string}>
 */
function ai_search(string $needle, int $limit = 40): array
{
    $needle = trim($needle);
    if (mb_strlen_ai($needle) < 3) return [];
    $hasil = [];
    foreach (ai_scope_files() as $f) {
        $abs = ai_root() . '/' . $f['rel'];
        $lines = @file($abs, FILE_IGNORE_NEW_LINES);
        if (!$lines) continue;
        foreach ($lines as $i => $line) {
            if (stripos($line, $needle) !== false) {
                $hasil[] = ['rel' => $f['rel'], 'line' => $i + 1, 'text' => trim(substr($line, 0, 160))];
                if (count($hasil) >= $limit) return $hasil;
            }
        }
    }
    return $hasil;
}

/** Panjang teks (menghindari mbstring yang tidak selalu ada di CLI). */
function mb_strlen_ai(string $s): int
{
    return (int)preg_match_all('/./us', $s);
}

/* ------------------------------------------------------------------ *
 * PATCH: bentuk, pemeriksaan, pratinjau
 * ------------------------------------------------------------------ */

/**
 * Baca balasan AI menjadi daftar operasi patch.
 *
 * Bentuk yang diterima (JSON):
 *   {"files":["a.php"],
 *    "ops":[{"file":"a.php","action":"replace","search":"teks lama","replace":"teks baru"},
 *           {"file":"b.php","action":"create","content":"isi berkas baru"}]}
 *
 * @return array{ok:bool,ops:array,error:string,plan:string,files:array}
 */
function ai_patch_parse(string $text): array
{
    $gagal = ['ok' => false, 'ops' => [], 'error' => '', 'plan' => '', 'files' => []];
    $json = ai_extract_json($text);
    if ($json === null) { $gagal['error'] = 'Jawaban AI tidak memuat JSON yang sah.'; return $gagal; }
    if (!is_array($json) || !isset($json['ops']) || !is_array($json['ops'])) {
        $gagal['error'] = 'JSON tidak memuat daftar "ops".'; return $gagal;
    }
    $ops = [];
    foreach ($json['ops'] as $i => $o) {
        if (!is_array($o)) { $gagal['error'] = 'Operasi #' . ($i + 1) . ' bukan objek.'; return $gagal; }
        $file = (string)($o['file'] ?? '');
        $action = (string)($o['action'] ?? 'replace');
        if ($file === '') { $gagal['error'] = 'Operasi #' . ($i + 1) . ' tidak menyebut berkas.'; return $gagal; }
        if (ai_path($file) === null && $action !== 'create') {
            $gagal['error'] = 'Berkas di luar cakupan atau tidak ada: ' . $file; return $gagal;
        }
        if ($action === 'create') {
            if (ai_path($file) !== null) { $gagal['error'] = 'Berkas sudah ada, tidak bisa "create": ' . $file; return $gagal; }
            $cek = ai_rel_ok($file);
            if (!$cek) { $gagal['error'] = 'Berkas baru di luar cakupan: ' . $file; return $gagal; }
            if (!isset($o['content']) || trim((string)$o['content']) === '') {
                $gagal['error'] = 'Operasi create tanpa isi: ' . $file; return $gagal;
            }
            $ops[] = ['file' => $file, 'action' => 'create', 'content' => (string)$o['content']];
            continue;
        }
        if ($action !== 'replace') { $gagal['error'] = 'Aksi tidak dikenal: ' . $action; return $gagal; }
        if (!isset($o['search']) || (string)$o['search'] === '') {
            $gagal['error'] = 'Operasi replace tanpa "search": ' . $file; return $gagal;
        }
        $ops[] = ['file' => $file, 'action' => 'replace',
                  'search' => (string)$o['search'], 'replace' => (string)($o['replace'] ?? '')];
    }
    /* Tidak ada operasi BUKAN kegagalan teknis: AI bisa saja menyimpulkan bahwa
       tidak ada yang perlu diubah (mis. permintaan hanya minta penjelasan). Karena
       itu penjelasannya tetap disimpan dan ditampilkan kepada admin sebagai
       "tidak ada perubahan yang diusulkan", bukan sebagai galat. */
    if (!$ops) {
        return ['ok' => true, 'ops' => [], 'error' => '', 'noop' => true,
                'plan' => trim((string)($json['plan'] ?? '')),
                'note' => trim((string)($json['note'] ?? $json['reason'] ?? '')),
                'files' => []];
    }
    return ['ok' => true, 'ops' => $ops, 'error' => '', 'noop' => false,
            'plan' => trim((string)($json['plan'] ?? '')),
            'files' => array_values(array_unique(array_map(fn($o) => $o['file'], $ops)))];
}

/** Apakah path RELATIF berada di dalam cakupan (walau berkasnya belum ada)? */
function ai_rel_ok(string $rel): bool
{
    $rel = trim(str_replace('\\', '/', $rel), '/');
    if ($rel === '' || strpos($rel, '..') !== false) return false;
    foreach (ai_settings()['scope'] as $sc) {
        $sc = trim($sc, '/');
        if ($sc !== '' && strpos($rel, $sc . '/') === 0) return true;
    }
    return false;
}

/** Ambil objek/array JSON pertama yang seimbang dari teks AI. */
function ai_extract_json(string $text): ?array
{
    $text = trim($text);
    /* Buang pagar kode markdown bila ada. */
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $start = strpos($text, '{');
    if ($start === false) return null;
    $depth = 0; $inStr = false; $esc = false;
    $len = strlen($text);
    for ($i = $start; $i < $len; $i++) {
        $c = $text[$i];
        if ($inStr) {
            if ($esc) { $esc = false; continue; }
            if ($c === '\\') { $esc = true; continue; }
            if ($c === '"') { $inStr = false; }
            continue;
        }
        if ($c === '"') { $inStr = true; continue; }
        if ($c === '{') $depth++;
        elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                $json = substr($text, $start, $i - $start + 1);
                $j = json_decode($json, true);
                return is_array($j) ? $j : null;
            }
        }
    }
    return null;
}

/**
 * Terapkan patch pada ISI BERKAS yang diberikan (tanpa menulis apa pun).
 * Dipakai untuk pratinjau, pemeriksaan sintaks, dan penerapan sesungguhnya
 * sehingga ketiganya memakai ATURAN YANG SAMA.
 *
 * @return array{ok:bool,error:string,files:array<string,string>,created:array<int,string>}
 */
function ai_apply_patch_to_content(array $ops): array
{
    $hasil = [];      // rel => isi baru
    $baru = [];
    foreach ($ops as $o) {
        $rel = (string)$o['file'];
        if ($o['action'] === 'create') {
            /* PENGAMAN GANDA: fungsi ini juga memeriksa CAKUPAN (bukan hanya
               parser). Tanpa pemeriksaan di sini, pemanggil yang menyusun ops
               sendiri (mis. uji atau kode lain) dapat menulis berkas di luar
               cakupan — pernah terbukti dari uji otomatis (berkas basis data
               sempat lolos). */
            if (!ai_rel_ok($rel)) {
                return ['ok' => false, 'error' => 'Berkas baru di luar cakupan AI: ' . $rel, 'files' => [], 'created' => []];
            }
            if (ai_path($rel) !== null) return ['ok' => false, 'error' => 'Berkas sudah ada: ' . $rel, 'files' => [], 'created' => []];
            $hasil[$rel] = (string)$o['content'];
            $baru[] = $rel;
            continue;
        }
        $isi = array_key_exists($rel, $hasil) ? $hasil[$rel] : null;
        if ($isi === null) {
            $baca = ai_read($rel);
            if (!$baca['ok']) return ['ok' => false, 'error' => $baca['error'], 'files' => [], 'created' => []];
            if (!empty($baca['truncated'])) {
                return ['ok' => false, 'error' => 'Berkas terlalu besar untuk diubah AI: ' . $rel
                    . ' (naikkan "Ukuran maksimal berkas" di AI Settings bila memang perlu).', 'files' => [], 'created' => []];
            }
            $isi = $baca['text'];
        }
        $cari = (string)$o['search'];
        $pos = strpos($isi, $cari);
        if ($pos === false) {
            return ['ok' => false, 'error' => 'Teks yang dicari tidak ditemukan PERSIS di ' . $rel
                . ' — patch ditolak (tidak ada perubahan diterapkan). Cuplikan: "'
                . substr(str_replace("\n", ' ', $cari), 0, 80) . '…"', 'files' => [], 'created' => []];
        }
        if (strpos($isi, $cari, $pos + 1) !== false) {
            return ['ok' => false, 'error' => 'Teks yang dicari muncul lebih dari sekali di ' . $rel
                . ' — patch ditolak agar tidak salah tempat.', 'files' => [], 'created' => []];
        }
        $hasil[$rel] = substr($isi, 0, $pos) . (string)$o['replace'] . substr($isi, $pos + strlen($cari));
    }
    return ['ok' => true, 'error' => '', 'files' => $hasil, 'created' => $baru];
}

/** Diff ringkas (per baris) untuk pratinjau — cukup untuk dibaca manusia. */
function ai_diff_text(string $rel, string $lama, string $baru): string
{
    $a = explode("\n", $lama);
    $b = explode("\n", $baru);
    $out = [];
    $max = max(count($a), count($b));
    $konteks = 3;
    $beda = [];
    for ($i = 0; $i < $max; $i++) {
        $x = $a[$i] ?? null; $y = $b[$i] ?? null;
        if ($x !== $y) $beda[] = $i;
    }
    if (!$beda) return '(tidak ada perbedaan)';
    $tampil = [];
    foreach ($beda as $i) {
        for ($k = max(0, $i - $konteks); $k <= min($max - 1, $i + $konteks); $k++) $tampil[$k] = true;
    }
    $kunci = array_keys($tampil);
    sort($kunci);
    $prev = -2;
    $out[] = '--- ' . $rel . ' (sebelum)';
    $out[] = '+++ ' . $rel . ' (sesudah)';
    foreach ($kunci as $i) {
        if ($prev >= 0 && $i > $prev + 1) $out[] = '  @@ … @@';
        $x = $a[$i] ?? null; $y = $b[$i] ?? null;
        if ($x === $y) { $out[] = '   ' . $x; }
        else {
            if ($x !== null) $out[] = ' - ' . $x;
            if ($y !== null) $out[] = ' + ' . $y;
        }
        $prev = $i;
    }
    return implode("\n", $out);
}

/**
 * Pemeriksaan sintaks atas ISI BARU (tanpa menyentuh berkas asli):
 * PHP → `php -l`, JavaScript → `node --check`, CSS → kurung kurawal seimbang.
 *
 * @return array{ok:bool,hasil:array<int,array{file:string,ok:bool,msg:string}>}
 */
function ai_lint_contents(array $files): array
{
    $tmp = ai_tmp_dir() . '/lint-' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0770, true);
    $out = [];
    $semua = true;
    foreach ($files as $rel => $isi) {
        $ext = strtolower(pathinfo((string)$rel, PATHINFO_EXTENSION));
        $f = $tmp . '/' . basename((string)$rel);
        file_put_contents($f, $isi);
        $ok = true; $msg = 'sintaks OK';
        if ($ext === 'php') {
            $r = ai_exec('php -l ' . escapeshellarg($f), 60);
            $ok = ($r['code'] === 0) && !preg_match('/Parse error|Fatal error/i', $r['out']);
            if (!$ok) $msg = trim(str_replace($f, $rel, $r['out'])) ?: 'PHP gagal diperiksa';
        } elseif ($ext === 'js') {
            $r = ai_exec('node --check ' . escapeshellarg($f), 60);
            $ok = ($r['code'] === 0);
            if (!$ok) $msg = trim($r['out']) ?: 'JavaScript bermasalah';
        } elseif ($ext === 'css') {
            $buka = substr_count($isi, '{'); $tutup = substr_count($isi, '}');
            $ok = ($buka === $tutup);
            $msg = $ok ? 'kurung kurawal seimbang' : 'kurung kurawal TIDAK seimbang (' . $buka . ' vs ' . $tutup . ')';
        }
        if (!$ok) $semua = false;
        $out[] = ['file' => (string)$rel, 'ok' => $ok, 'msg' => $msg];
    }
    ai_rmdir($tmp);
    return ['ok' => $semua, 'hasil' => $out];
}

/* ------------------------------------------------------------------ *
 * MENJALANKAN PERINTAH SHELL (terbatas: hanya perintah yang kita susun)
 * ------------------------------------------------------------------ */

/** Jalankan perintah shell, kembalikan keluaran + kode keluar. */
function ai_exec(string $cmd, int $timeout = 300, string $cwd = ''): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $opts = $cwd !== '' ? ['bypass_shell' => false] : [];
    $proc = @proc_open($cmd, $descriptors, $pipes, $cwd !== '' ? $cwd : null, null);
    if (!is_resource($proc)) return ['code' => 127, 'out' => 'Gagal menjalankan perintah.'];
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $mulai = time();
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]);
        $out .= (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) { $code = (int)$st['exitcode']; break; }
        if ((time() - $mulai) > $timeout) { proc_terminate($proc, 9); $code = 124; $out .= "\n[batas waktu tercapai]"; break; }
        usleep(120000);
    }
    $out .= (string)stream_get_contents($pipes[1]);
    $out .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    return ['code' => $code, 'out' => $out];
}

/** Jalankan perintah di LATAR BELAKANG (tanpa menunggu selesai). */
function ai_exec_background(string $cmd, string $logFile): void
{
    $full = $cmd . ' > ' . escapeshellarg($logFile) . ' 2>&1 &';
    @exec($full);
}

/**
 * Jalur biner PHP untuk CLI.
 *
 * PENTING: saat aplikasi berjalan di bawah PHP-FPM/web server, `PHP_BINARY`
 * menunjuk biner FPM (bukan CLI) sehingga memanggilnya untuk menjalankan worker
 * tidak akan bekerja. Karena itu dicari biner CLI yang benar.
 */
function ai_php_cli(): string
{
    $kandidat = [PHP_BINDIR . '/php', '/usr/local/bin/php', '/usr/bin/php'];
    foreach ($kandidat as $c) {
        if ($c !== '' && is_file($c) && is_executable($c)) return $c;
    }
    return 'php';
}

/** Folder sementara AI (di luar folder aplikasi yang disajikan publik). */
function ai_tmp_dir(): string
{
    $d = ai_root() . '/naveena_ai';
    foreach (['', '/tmp', '/staging', '/snapshot', '/log'] as $sub) {
        if (!is_dir($d . $sub)) @mkdir($d . $sub, 0770, true);
    }
    return $d;
}

/** Hapus folder rekursif (hanya dipakai untuk folder sementara milik AI). */
function ai_rmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/**
 * Pembersih folder sementara AI (staging/log/snapshot lama).
 *
 * PENTING untuk pemakaian jangka panjang: setiap kali uji dijalankan, staging
 * menyalin seluruh aplikasi + skrip uji (± 6 MB). Bila tidak dibersihkan, folder
 * ini menumpuk dan memakan disk. Di sini hanya disimpan beberapa yang terbaru
 * (bawaan 3) dan sisanya dihapus otomatis.
 */
function ai_prune_temp(int $simpan = 3): array
{
    $hasil = ['staging' => 0, 'snapshot' => 0, 'log' => 0, 'mb' => 0.0];
    foreach (['staging', 'snapshot'] as $jenis) {
        $base = ai_tmp_dir() . '/' . $jenis;
        if (!is_dir($base)) continue;
        $daftar = [];
        foreach (scandir($base) ?: [] as $d) {
            if ($d === '.' || $d === '..') continue;
            $path = $base . '/' . $d;
            if (!is_dir($path)) continue;
            $daftar[$path] = (int)@filemtime($path);
        }
        arsort($daftar);
        $i = 0;
        foreach ($daftar as $path => $t) {
            $i++;
            if ($i <= $simpan) continue;
            ai_rmdir($path);
            $hasil[$jenis]++;
        }
    }
    /* Berkas log worker/uji: simpan 40 terbaru. */
    $logBase = ai_tmp_dir() . '/log';
    if (is_dir($logBase)) {
        $logs = [];
        foreach (scandir($logBase) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $logBase . '/' . $f;
            if (is_file($p)) $logs[$p] = (int)@filemtime($p);
        }
        arsort($logs);
        $i = 0;
        foreach ($logs as $p => $t) {
            $i++;
            if ($i <= 40) continue;
            @unlink($p);
            $hasil['log']++;
        }
    }
    /* Ukuran total folder sementara (dilaporkan di halaman). */
    $hasil['mb'] = round(ai_dir_size(ai_tmp_dir()) / 1048576, 2);
    return $hasil;
}

/** Ukuran folder (byte) — dipakai melaporkan pemakaian folder sementara AI. */
function ai_dir_size(string $dir): int
{
    if (!is_dir($dir)) return 0;
    $total = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) $total += (int)$f->getSize();
    return $total;
}

/** Salin folder rekursif (dipakai menyiapkan staging & salinan pengaman). */
function ai_copy_dir(string $src, string $dst, array $skip = []): void
{
    if (!is_dir($dst)) @mkdir($dst, 0770, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($src) + 1);
        foreach ($skip as $s) { if ($rel === $s || strpos($rel, $s . '/') === 0) continue 2; }
        $target = $dst . '/' . $rel;
        if ($f->isDir()) { if (!is_dir($target)) @mkdir($target, 0770, true); }
        else { @copy($f->getPathname(), $target); }
    }
}

/** Folder staging untuk sebuah tugas (salinan aplikasi + skrip uji). */
function ai_staging_dir(int $taskId): string
{
    return ai_tmp_dir() . '/staging/task-' . $taskId;
}

/**
 * Siapkan staging: salinan aplikasi + folder skrip uji (supaya `run_all.sh`
 * menjalankan uji pada SALINAN, bukan aplikasi terbit). Folder data & unggahan
 * TIDAK ikut disalin (uji membuat basis datanya sendiri).
 */
function ai_staging_prepare(int $taskId): array
{
    $dir = ai_staging_dir($taskId);
    if (is_dir($dir)) ai_rmdir($dir);
    @mkdir($dir, 0770, true);
    $root = ai_root();
    /* Struktur harus menyerupai workspace asli: <staging>/naveena + <staging>/naveena_dev/test
       karena `run_all.sh` menghitung folder aplikasi dari letak skripnya. */
    ai_copy_dir(APP_DIR, $dir . '/naveena');
    if (is_dir($root . '/naveena_dev')) {
        ai_copy_dir($root . '/naveena_dev', $dir . '/naveena_dev');
    }
    return ['dir' => $dir, 'app' => $dir . '/naveena'];
}

/** Terapkan patch ke folder staging (untuk uji/pratinjau tanpa menyentuh aplikasi). */
function ai_staging_apply(int $taskId, array $files): array
{
    $base = ai_staging_dir($taskId) . '/';
    if (!is_dir($base . 'naveena')) return ['ok' => false, 'error' => 'Folder staging belum disiapkan.'];
    foreach ($files as $rel => $isi) {
        $abs = $base . str_replace('\\', '/', (string)$rel);
        $dir = dirname($abs);
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        if (@file_put_contents($abs, $isi) === false) {
            return ['ok' => false, 'error' => 'Gagal menulis berkas staging: ' . $rel];
        }
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * Daftar suite uji yang tersedia (dibaca dari run_all.sh, bukan ditulis manual,
 * supaya suite baru otomatis muncul).
 */
function ai_suite_list(): array
{
    $f = ai_root() . '/naveena_dev/test/run_all.sh';
    if (!is_readable($f)) return [];
    $txt = (string)file_get_contents($f);
    $out = [];
    /* Baris pemanggilan: run_sh "nama" ..., run_js "nama" ..., run_js_gd "nama" ... */
    if (preg_match_all('/^\s*run_(?:sh|js|js_gd|sh_gd)\s+"([^"]+)"/m', $txt, $m)) {
        $out = array_values(array_unique($m[1]));
    }
    return $out;
}

/**
 * Jalankan satu suite uji DI STAGING, di latar belakang.
 * Keluarannya ditulis ke berkas log yang dapat dibaca halaman (polling).
 */
function ai_run_suite(int $taskId, string $suite, ?string &$err = null): ?string
{
    $dir = ai_staging_dir($taskId);
    if (!is_dir($dir . '/naveena_dev/test')) { $err = 'Folder skrip uji tidak ada di staging.'; return null; }
    $suite = preg_replace('/[^A-Za-z0-9\-]/', '', $suite);
    if ($suite === '') { $err = 'Nama suite uji tidak sah.'; return null; }
    $log = ai_tmp_dir() . '/log/task-' . $taskId . '-test.log';
    @unlink($log);
    /* NAVEENA_UPLOAD_DIR/NAVEENA_BACKUP_DIR diarahkan ke folder staging supaya uji
       tidak menulis apa pun ke folder aplikasi terbit (kesalahan yang pernah
       membuat folder unggahan produksi tertimbun berkas uji). */
    /* NV_PORT_OFFSET WAJIB: uji staging memakai skrip yang sama dengan suite biasa
       sehingga tanpa pengalihan port keduanya berebut port yang sama (suite biasa
       lalu mengira server miliknya sudah hidup padahal itu server staging, dan
       gagal dengan "no such table"). */
    $cmd = 'cd ' . escapeshellarg($dir . '/naveena_dev/test')
        . ' && NAVEENA_UPLOAD_ROOT=' . escapeshellarg($dir . '/uploads')
        . ' NAVEENA_BACKUP_DIR=' . escapeshellarg($dir . '/backups')
        . ' NV_PORT_OFFSET=3000'
        . ' bash run_all.sh ' . escapeshellarg($suite);
    ai_exec_background($cmd, $log);
    return $log;
}

/** Baca ringkasan hasil uji dari berkas log (PASS/FAIL + baris penting). */
function ai_test_result(string $logFile): array
{
    if ($logFile === '' || !is_file($logFile)) return ['jalan' => false, 'ringkas' => '', 'pass' => 0, 'fail' => 0];
    $txt = (string)file_get_contents($logFile);
    $pass = 0; $fail = 0;
    if (preg_match_all('/PASS:\s*(\d+)/', $txt, $m)) $pass = (int)end($m[1]);
    if (preg_match_all('/FAIL:\s*(\d+)/', $txt, $m)) $fail = (int)end($m[1]);
    $baris = array_values(array_filter(array_map('trim', explode("\n", $txt)), fn($l) => $l !== ''));
    $jalan = count($baris) > 0 && (stripos($txt, 'TOTAL') === false || stripos($txt, 'TOTAL  PASS') === false)
        ? true : false;
    /* Selesai bila baris TOTAL sudah muncul atau ada kegagalan yang dilaporkan. */
    $selesai = (bool)preg_match('/TOTAL\s+PASS:\s*\d+\s+FAIL:\s*\d+/', $txt)
        || (bool)preg_match('/SKRIP BERHENTI/', $txt);
    return [
        'jalan' => !$selesai && count($baris) > 0,
        'selesai' => $selesai,
        'pass' => $pass, 'fail' => $fail,
        'ringkas' => implode("\n", array_slice($baris, -12)),
        'gagal_baris' => array_values(array_filter($baris, fn($l) => stripos($l, 'FAIL') === 0
            || stripos($l, '  FAIL') === 0 || stripos($l, 'SKRIP BERHENTI') !== false)),
    ];
}

/* ------------------------------------------------------------------ *
 * PENERAPAN KE APLIKASI (+ salinan pengaman & pembatalan)
 * ------------------------------------------------------------------ */

/**
 * Simpan salinan berkas yang akan diubah (untuk pembatalan) + daftar berkas baru.
 *
 * @param ?string $root akar yang dipakai (bawaan: folder workspace). Parameter ini
 *        ada supaya uji otomatis dapat menguji penerapan/pembatalan pada SALINAN
 *        sementara — bukan pada aplikasi yang sedang dipakai.
 */
function ai_snapshot(int $taskId, array $files, ?string $root = null): string
{
    $root = $root ?? ai_root();
    $dir = ai_tmp_dir() . '/snapshot/task-' . $taskId;
    ai_rmdir($dir);
    @mkdir($dir, 0770, true);
    $daftar = [];
    foreach ($files as $rel => $isi) {
        $abs = $root . '/' . $rel;
        if (file_exists($abs)) {
            $dest = $dir . '/' . str_replace('\\', '/', $rel);
            @mkdir(dirname($dest), 0770, true);
            @copy($abs, $dest);
            $daftar[$rel] = 'ada';
        } else {
            $daftar[$rel] = 'baru';
        }
    }
    file_put_contents($dir . '/_files.json', json_encode($daftar, JSON_UNESCAPED_UNICODE));
    return $dir;
}

/** Terapkan perubahan ke aplikasi terbit (dipakai SETELAH persetujuan). */
function ai_apply_to_app(array $files, ?string $root = null): array
{
    $root = $root ?? ai_root();
    foreach ($files as $rel => $isi) {
        $abs = $root . '/' . $rel;
        $dir = dirname($abs);
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        $tmp = $abs . '.ai-tmp';
        if (@file_put_contents($tmp, $isi) === false) {
            return ['ok' => false, 'error' => 'Gagal menulis berkas: ' . $rel];
        }
        if (!@rename($tmp, $abs)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Gagal mengganti berkas: ' . $rel];
        }
    }
    return ['ok' => true, 'error' => ''];
}

/** Batalkan (kembalikan berkas ke salinan pengaman). */
function ai_rollback(int $taskId, ?string $root = null): array
{
    $root = $root ?? ai_root();
    $dir = ai_tmp_dir() . '/snapshot/task-' . $taskId;
    $petaFile = $dir . '/_files.json';
    if (!is_file($petaFile)) return ['ok' => false, 'error' => 'Salinan pengaman tidak ditemukan.'];
    $peta = json_decode((string)file_get_contents($petaFile), true) ?: [];
    $kembali = 0; $hapus = 0;
    foreach ($peta as $rel => $keadaan) {
        $abs = $root . '/' . $rel;
        if ($keadaan === 'ada') {
            $src = $dir . '/' . $rel;
            if (is_file($src) && @copy($src, $abs)) $kembali++;
        } else {
            if (is_file($abs) && @unlink($abs)) $hapus++;
        }
    }
    return ['ok' => true, 'error' => '', 'dikembalikan' => $kembali, 'dihapus' => $hapus];
}

/* ------------------------------------------------------------------ *
 * PROMPT AI
 * ------------------------------------------------------------------ */

/** Aturan tetap yang selalu dikirim ke AI (supaya jawabannya patuh format). */
function ai_system_prompt(): string
{
    return "Anda asisten pengembang untuk aplikasi manajemen klinik berbasis PHP 8 + SQLite "
        . "(tanpa framework, tanpa build step). Tugas Anda: membantu merevisi, memperbaiki, atau "
        . "menambah fitur pada kode yang diberikan.\n\n"
        . "ATURAN WAJIB:\n"
        . "1. Jawab HANYA dengan satu objek JSON (tanpa penjelasan di luar JSON, tanpa pagar kode).\n"
        . "2. Bentuk JSON: {\"plan\":\"ringkasan singkat rencana\",\"files\":[\"path/relatif.php\"],"
        . "\"ops\":[{\"file\":\"path/relatif.php\",\"action\":\"replace\",\"search\":\"teks lama PERSIS\","
        . "\"replace\":\"teks baru\"}]}\n"
        . "3. Gunakan \"search\" berisi potongan kode yang BENAR-BENAR ADA pada berkas yang dikirim, "
        . "cukup unik (jangan terlalu pendek), dan tulis PERSIS termasuk indentasi. "
        . "Jangan pernah mengarang kode yang tidak ada.\n"
        . "4. Untuk berkas BARU: {\"file\":\"...\",\"action\":\"create\",\"content\":\"isi lengkap\"}.\n"
        . "5. Satu op = satu perubahan kecil. Bila perlu banyak tempat, buat beberapa op.\n"
        . "6. Ikuti gaya kode yang ada: komentar berbahasa Indonesia, helper yang sudah tersedia "
        . "(mis. e(), money(), num(), has_perm(), is_super(), audit(), flash()), dan jangan "
        . "menambah dependensi baru (tanpa composer/npm).\n"
        . "7. Jangan pernah menulis kunci API, sandi, atau token ke dalam kode.\n"
        . "8. Bila permintaan tidak dapat dipenuhi dari berkas yang tersedia, kembalikan "
        . "{\"plan\":\"...\",\"files\":[],\"ops\":[]} dan jelaskan alasannya di plan.";
}

/** Prompt tahap 1: AI memilih berkas yang relevan. */
function ai_pick_prompt(string $request, array $daftar): string
{
    $baris = [];
    foreach ($daftar as $f) $baris[] = $f['rel'];
    return "Permintaan pengguna:\n" . $request . "\n\n"
        . "Berikut daftar berkas yang boleh diubah (path relatif, dipisah baris baru):\n"
        . implode("\n", $baris) . "\n\n"
        . "Pilih berkas yang PALING RELEVAN untuk memenuhi permintaan (maksimal "
        . ai_settings()['max_files'] . " berkas). Jawab HANYA JSON: {\"files\":[\"path/relatif.php\"]}";
}

/** Prompt tahap 2: AI menyusun patch dari isi berkas yang dipilih. */
function ai_patch_prompt(string $request, array $isi): string
{
    $bagian = [];
    foreach ($isi as $rel => $teks) {
        $bagian[] = "===== BERKAS: " . $rel . " =====\n" . $teks . "\n===== AKHIR " . $rel . " =====";
    }
    return "Permintaan pengguna:\n" . $request . "\n\n"
        . "Isi berkas yang relevan:\n\n" . implode("\n\n", $bagian) . "\n\n"
        . "Susun patch sesuai format JSON yang diminta (plan, files, ops). "
        . "Pastikan setiap \"search\" benar-benar ada pada isi berkas di atas.";
}

/* ------------------------------------------------------------------ *
 * PENYIMPANAN TUGAS
 * ------------------------------------------------------------------ */

/** Ambil satu tugas AI. */
function ai_task(int $id): ?array
{
    $t = one('SELECT * FROM ai_tasks WHERE id = ?', [$id]);
    if ($t) {
        $t['ops'] = $t['patch'] ? (json_decode((string)$t['patch'], true) ?: []) : [];
        $t['plan_text'] = (string)($t['plan'] ?? '');
    }
    return $t;
}

/** Daftar tugas terbaru. */
function ai_tasks(int $limit = 30): array
{
    return all('SELECT id, user_id, request, provider, model, status, stage, suite, test_status,
                       created_at, updated_at, applied_at, rolled_back_at, error
                FROM ai_tasks ORDER BY id DESC LIMIT ' . max(1, $limit));
}

/** Ubah keadaan sebuah tugas. */
function ai_task_update(int $id, array $data): void
{
    $data['updated_at'] = date('Y-m-d H:i:s');
    $set = [];
    $val = [];
    foreach ($data as $k => $v) { $set[] = $k . ' = ?'; $val[] = $v; }
    $val[] = $id;
    q('UPDATE ai_tasks SET ' . implode(', ', $set) . ' WHERE id = ?', $val);
}

/** Panjang teks untuk validasi masukan (tanpa mbstring). */
function ai_strlen(string $s): int
{
    return (int)preg_match_all('/./us', $s);
}

/**
 * Jalankan pekerja AI di LATAR BELAKANG (plan/test).
 *
 * Dipisah dari permintaan web supaya panggilan AI (puluhan detik) dan suite uji
 * (beberapa menit) tidak menahan/mematikan permintaan halaman. Berkas worker
 * menolak dijalankan dari peramban, dan hanya menerima mode + id tugas.
 */
function ai_spawn_worker(string $mode, int $taskId): void
{
    $php = ai_php_cli();
    $worker = __DIR__ . '/../ai_worker.php';
    $log = ai_tmp_dir() . '/log/worker-' . preg_replace('/[^a-z]/', '', $mode) . '-' . $taskId . '.log';
    $cmd = escapeshellarg($php) . ' -d extension=pdo -d extension=pdo_sqlite '
        . escapeshellarg($worker) . ' ' . escapeshellarg($mode) . ' ' . $taskId;
    ai_exec_background($cmd, $log);
}

/** Label status tugas untuk tampilan. */
function ai_status_label(string $status): array
{
    return [
        'draft'     => ['Menunggu dijalankan AI', 'gray'],
        'analyzing' => ['AI sedang menganalisis', 'yellow'],
        'proposed'  => ['Ada usulan perubahan (belum diterapkan)', 'blue'],
        'failed'    => ['Gagal', 'red'],
        'testing'   => ['Sedang diuji', 'yellow'],
        'tested'    => ['Sudah diuji', 'green'],
        'applied'   => ['Sudah DITERAPKAN', 'green'],
        'rejected'  => ['Ditolak admin', 'gray'],
        'noop'      => ['AI: tidak ada perubahan yang perlu dilakukan', 'blue'],
        'rolledback' => ['Dibatalkan (dikembalikan)', 'gray'],
    ][$status] ?? [$status, 'gray'];
}
