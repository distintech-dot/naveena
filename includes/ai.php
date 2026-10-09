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
        /* RONDE 44 — MODE KOLABORASI (opsional): satu model "Arsitek" menerjemahkan
           perintah bebas pemilik menjadi brief teknis, lalu model utama (Pelaksana)
           yang mengerjakan patch. Bawaan: NONAKTIF (tanpa kunci kedua tetap jalan). */
        'collab'    => (string)setting('ai_collab_mode', 'off'),
        'arch_provider' => (string)setting('ai_architect_provider', 'openai'),
        'arch_model'    => (string)setting('ai_architect_model', 'gpt-4.1-mini'),
        'arch_key'      => (string)setting('ai_architect_key', ''),
        /* Perkiraan biaya (opsional; 0 = tidak dihitung). Satuan: per 1 juta token. */
        'price_in'  => (float)setting('ai_price_in', '0'),
        'price_out' => (float)setting('ai_price_out', '0'),
        /* RONDE 45 — CADANGAN OTOMATIS (model kedua). Bila model pelaksana gagal
           (kuota habis / penyedia menolak / jaringan), pekerjaan TIDAK berhenti:
           model cadangan yang menyelesaikannya. Inilah yang membuat saldo dua
           penyedia dapat dipakai bergantian tanpa menghentikan pekerjaan. */
        'fallback_on' => setting('ai_fallback_on', '1') === '1',
        'fallback_provider' => (string)setting('ai_fallback_provider', 'gemini'),
        'fallback_model' => (string)setting('ai_fallback_model', 'gemini-3-flash-preview'),
        'fallback_key' => (string)setting('ai_fallback_key', ''),
        /* RONDE 45 — ANGGARAN KONTEKS (KB): batas TOTAL isi berkas yang dikirim ke
           model pelaksana. Tanpa batas ini, satu permintaan kecil pernah memakai
           ~300.000 token karena 8 berkas besar (termasuk berkas inti 120 KB) ikut
           dikirim ulang pada setiap putaran. */
        'context_kb' => max(20, min(1200, (int)setting('ai_context_kb', '100'))),
        'max_rounds' => max(1, min(5, (int)setting('ai_max_rounds', '2'))),
        /* RONDE 49 — UJI OTOMATIS: setelah usulan selesai, suite uji dijalankan
           SENDIRI di salinan aplikasi (pemilik tidak perlu menekan tombol uji).
           Dapat dimatikan dari AI Settings (mis. saat ingin memeriksa usulan dulu). */
        'auto_test' => setting('ai_auto_test', '1') === '1',
        /* RONDE 50 — MODE OBROLAN: AI membedakan "sedang bertanya/berdiskusi/minta
           saran/audit/rencana" dari "minta dikerjakan", sehingga percakapan biasa
           TIDAK langsung mengubah berkas. Dapat dimatikan (selalu minta dikerjakan). */
        'chat_mode' => setting('ai_chat_mode', '1') === '1',
        /* RONDE 50 — PENELUSURAN DAMPAK: dari berkas sasaran, sistem menelusuri
           simbol (fungsi/tabel/setelan/izin/halaman) ke SELURUH berkas cakupan
           untuk menemukan bagian lain yang ikut terdampak. */
        'impact_scan' => setting('ai_impact_scan', '1') === '1',
        /* RONDE 51 — AUTO ERROR RECOVERY (self-healing): bila uji gagal / sintaks
           bermasalah, AI memperbaiki sendiri lalu menguji ulang (DETECT → ANALYZE →
           FIX → TEST → REGRESSION → FINAL CHECK) tanpa menyembunyikan error. */
        'heal_on' => setting('ai_heal_on', '1') === '1',
        'heal_rounds' => max(1, min(5, (int)setting('ai_heal_rounds', '3'))),
        /* Alamat API OpenAI dapat diarahkan ke gateway/proxy sendiri (opsional).
           Kosong = alamat resmi. Dipakai juga oleh uji otomatis. */
        'api_base' => rtrim((string)setting('ai_api_base', ''), '/'),
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
        /* Urutan = urutan pada daftar pilihan; yang pertama otomatis dipakai bila
           model dikosongkan. RONDE 43: daftar Gemini disesuaikan dengan model yang
           benar-benar tersedia untuk kunci pemilik (diperiksa lewat
           "Muat Daftar Model" di AI Settings). */
        'gemini' => [
            'gemini-3-flash-preview', 'gemini-3.8-flash', 'gemini-3.7-flash', 'gemini-3.6-flash',
            'gemini-3.5-flash', 'gemini-3.1-pro-preview', 'gemini-3.5-flash-lite',
            'gemini-flash-latest', 'gemini-pro-latest', 'gemini-2.5-pro', 'gemini-2.5-flash',
        ],
        /* Urutan = urutan pada daftar pilihan. RONDE 45: ditambah model terbaru
           (termasuk keluarga "luna") — daftar dapat diperiksa/dimuat dari penyedia
           lewat tombol "Muat Daftar Model" di AI Settings. */
        'openai' => ['gpt-6-luna', 'gpt-5.6-luna', 'gpt-5.4', 'gpt-5.1', 'gpt-4.1', 'gpt-4.1-mini',
                     'gpt-4o', 'gpt-4o-mini'],
        'mock'   => ['mock-1'],
    ];
}

/** Model yang DISARANKAN per penyedia (ditandai pada daftar pilihan). */
function ai_model_recommended(string $provider): string
{
    return $provider === 'gemini' ? 'gemini-3-flash-preview' : ($provider === 'openai' ? 'gpt-6-luna' : 'mock-1');
}

/**
 * Apakah model ini termasuk keluarga "penalaran" (reasoning)?
 *
 * RONDE 45 — temuan nyata dari kunci OpenAI pemilik: model `gpt-5.6-luna`,
 * `gpt-6-luna`, dan seluruh keluarga o-series/gpt-5/gpt-6
 *   • MENOLAK parameter `max_tokens` (harus `max_completion_tokens`), dan
 *   • MENOLAK `temperature` selain nilai bawaan (1).
 * Tanpa penyesuaian ini setiap permintaan dijawab HTTP 400
 * ("Unsupported parameter: 'max_tokens' is not supported with this model").
 */
function ai_is_reasoning_model(string $model): bool
{
    return (bool)preg_match('/^(o[1-9]|gpt-5|gpt-6|gpt-7)/i', trim($model));
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
 * @param array $opts     'thinking_budget' (int|null)  anggaran "berpikir" Gemini
 *                        'attachments' (array)        lampiran ['mime'=>..,'data'=>bytes,'name'=>..]
 *                        'provider'/'api_key'          memanggil penyedia lain
 *                        'purpose' (string)           untuk catatan pemakaian token
 * @return array{ok:bool,text:string,error:string,raw:string,truncated:bool,finish:string,usage:array}
 */
function ai_call(array $messages, float $temperature = 0.2, int $maxTokens = 8192, ?string $modelOverride = null, array $opts = []): array
{
    $gagal = ['ok' => false, 'text' => '', 'error' => '', 'raw' => '', 'truncated' => false, 'finish' => '', 'usage' => []];
    $s = ai_settings();
    if (!empty($opts['provider'])) $s['provider'] = (string)$opts['provider'];
    if (array_key_exists('api_key', $opts)) $s['api_key'] = (string)$opts['api_key'];
    if ($modelOverride !== null && $modelOverride !== '') $s['model'] = $modelOverride;
    if (!$s['enabled']) return array_merge($gagal, ['error' => 'AI Developer dimatikan.']);
    if ($s['provider'] === 'mock') {
        /* Jawaban tiruan: dipakai uji otomatis. Diambil dari setelan sehingga uji
           dapat menentukan persis apa yang "dikatakan" AI. Bila `ai_mock_queue`
           diisi (array JSON), jawaban diambil SATU PER SATU dari antrean — dipakai
           untuk menguji perbaikan otomatis (putaran pertama gagal, kedua benar). */
        $reply = ai_mock_next();
        /* Pemakaian token tiruan (supaya uji mode tampilan token deterministic). */
        $usage = ai_usage_parse_mock($reply);
        return $reply === ''
            ? array_merge($gagal, ['error' => 'Jawaban tiruan belum disetel (ai_mock_reply kosong).'])
            : ['ok' => true, 'text' => $reply, 'error' => '', 'raw' => 'mock',
               'truncated' => false, 'finish' => 'STOP', 'usage' => $usage];
    }
    if (trim($s['api_key']) === '') return array_merge($gagal, ['error' => 'Kunci API belum diisi di AI Settings.']);

    $prov = ai_providers()[$s['provider']] ?? null;
    if (!$prov) return array_merge($gagal, ['error' => 'Penyedia AI tidak dikenal.']);

    $lampiran = (array)($opts['attachments'] ?? []);
    if ($s['provider'] === 'gemini') {
        $url = str_replace('{model}', rawurlencode($s['model']), (string)$prov['url']);
        $contents = [];
        foreach ($messages as $i => $m) {
            $parts = [['text' => (string)$m['text']]];
            /* Lampiran (gambar/PDF) dikirim hanya SEKALI, menempel pada pesan terakhir. */
            if ($lampiran && $i === count($messages) - 1) {
                foreach ($lampiran as $at) {
                    $parts[] = ['inlineData' => [
                        'mimeType' => (string)($at['mime'] ?? 'application/octet-stream'),
                        'data' => base64_encode((string)($at['data'] ?? '')),
                    ]];
                }
            }
            $contents[] = ['role' => $m['role'] === 'model' ? 'model' : 'user', 'parts' => $parts];
        }
        $gen = ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens];
        if (array_key_exists('thinking_budget', $opts) && $opts['thinking_budget'] !== null) {
            $gen['thinkingConfig'] = ['thinkingBudget' => (int)$opts['thinking_budget']];
        }
        $payload = ['contents' => $contents, 'generationConfig' => $gen];
        $headers = ['Content-Type: application/json', 'x-goog-api-key: ' . $s['api_key']];
        $res = ai_http_post($url, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $headers);
        if (!$res['ok']) return array_merge($gagal, ['error' => $res['error'], 'raw' => $res['body']]);
        $j = json_decode($res['body'], true);
        if (isset($j['error'])) {
            return array_merge($gagal, ['error' => 'Penyedia menolak: ' . (string)($j['error']['message'] ?? 'tidak diketahui'), 'raw' => $res['body']]);
        }
        if (isset($j['promptFeedback']['blockReason'])) {
            return array_merge($gagal, ['error' => 'Penyedia menolak permintaan (alasan: '
                . (string)$j['promptFeedback']['blockReason'] . ').', 'raw' => $res['body']]);
        }
        $text = '';
        foreach ((array)($j['candidates'][0]['content']['parts'] ?? []) as $part) {
            $text .= (string)($part['text'] ?? '');
        }
        $finish = (string)($j['candidates'][0]['finishReason'] ?? '');
        $usage = ai_usage_parse('gemini', (array)($j['usageMetadata'] ?? []));
        if (trim($text) === '') {
            return array_merge($gagal, ['error' => 'Jawaban AI kosong' . ($finish !== '' ? ' (finishReason: ' . $finish . ')' : '') . '.',
                'raw' => $res['body'], 'finish' => $finish, 'usage' => $usage]);
        }
        /* JEBAKAN (ronde 43): model Gemini "berpikir" dulu, dan token berpikir itu
           IKUT memakan batas keluaran. Bila batasnya habis, jawaban berhenti di
           tengah JSON (finishReason MAX_TOKENS) sehingga parser melaporkan "JSON
           tidak sah" — padahal penyebabnya kehabisan tempat. Sekarang dibedakan
           dan jawaban separuhnya tetap dikembalikan supaya dapat diperbaiki. */
        if ($finish === 'MAX_TOKENS') {
            return ['ok' => false, 'text' => $text, 'raw' => $res['body'], 'truncated' => true, 'finish' => $finish,
                'usage' => $usage,
                'error' => 'Jawaban AI TERPOTONG karena kehabisan batas panjang (finishReason: MAX_TOKENS). '
                    . 'Token "berpikir" model ikut memakan batas keluaran — akan dicoba ulang dengan berpikir dimatikan.'];
        }
        return ['ok' => true, 'text' => $text, 'error' => '', 'raw' => $res['body'], 'truncated' => false,
                'finish' => $finish, 'usage' => $usage];
    }

    /* OpenAI (dan penyedia bergaya sama) — lampiran gambar dikirim sebagai
       gambar ber-embed; PDF/dokumen TIDAK didukung API ini sehingga isinya
       diekstrak menjadi teks lebih dulu (lihat ai_attachment_inline()). */
    $apiMessages = [];
    foreach ($messages as $i => $m) {
        $role = $m['role'] === 'model' ? 'assistant' : 'user';
        $gambar = [];
        if ($lampiran && $i === count($messages) - 1) {
            foreach ($lampiran as $at) {
                if (strpos((string)($at['mime'] ?? ''), 'image/') === 0) {
                    $gambar[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $at['mime']
                        . ';base64,' . base64_encode((string)$at['data'])]];
                }
            }
        }
        if ($gambar) {
            $apiMessages[] = ['role' => $role, 'content' => array_merge(
                [['type' => 'text', 'text' => (string)$m['text']]], $gambar)];
        } else {
            $apiMessages[] = ['role' => $role, 'content' => (string)$m['text']];
        }
    }
    $reasoning = ai_is_reasoning_model($s['model']);
    $payload = [
        'model' => $s['model'],
        'messages' => $apiMessages,
    ];
    if ($reasoning) {
        /* Keluarga o-series/gpt-5/gpt-6: WAJIB `max_completion_tokens` dan
           temperature hanya boleh nilai bawaan → jangan dikirim sama sekali. */
        $payload['max_completion_tokens'] = max(16, $maxTokens);
    } else {
        $payload['temperature'] = $temperature;
        $payload['max_tokens'] = $maxTokens;
    }
    if (!empty($opts['json'])) {
        /* Format JSON dipaksa penyedia — membuat jawaban lebih patuh bentuk. */
        $payload['response_format'] = ['type' => 'json_object'];
    }
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $s['api_key']];
    /* Alamat dapat dialihkan ke gateway/proxy (setelan `ai_api_base`) — dipakai juga
       oleh uji otomatis supaya bentuk permintaan dapat diperiksa tanpa memakai kuota. */
    $urlOpenai = (string)$prov['url'];
    if ($s['api_base'] !== '' && $s['provider'] === 'openai') {
        $urlOpenai = $s['api_base'] . (substr($s['api_base'], -8) === '/chat/completions' ? '' : '/chat/completions');
    }
    $res = ai_http_post($urlOpenai, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $headers);
    if (!$res['ok']) return array_merge($gagal, ['error' => $res['error'], 'raw' => $res['body']]);
    $j = json_decode($res['body'], true);
    if (isset($j['error'])) {
        return array_merge($gagal, ['error' => 'Penyedia menolak: ' . (string)($j['error']['message'] ?? 'tidak diketahui'), 'raw' => $res['body']]);
    }
    $text = (string)($j['choices'][0]['message']['content'] ?? '');
    $finish = (string)($j['choices'][0]['finish_reason'] ?? '');
    $usage = ai_usage_parse('openai', (array)($j['usage'] ?? []));
    if (trim($text) === '') {
        return array_merge($gagal, ['error' => 'Jawaban AI kosong' . ($finish !== '' ? ' (finish_reason: ' . $finish . ')' : '') . '.',
            'raw' => $res['body'], 'finish' => $finish, 'usage' => $usage]);
    }
    if ($finish === 'length') {
        return ['ok' => false, 'text' => $text, 'raw' => $res['body'], 'truncated' => true, 'finish' => $finish,
            'usage' => $usage,
            'error' => 'Jawaban AI TERPOTONG karena kehabisan batas panjang (finish_reason: length).'];
    }
    return ['ok' => true, 'text' => $text, 'error' => '', 'raw' => $res['body'], 'truncated' => false,
            'finish' => $finish, 'usage' => $usage];
}

/**
 * Jawaban berikutnya dari penyedia TIRUAN (hanya untuk uji otomatis).
 * Bila `ai_mock_queue` berisi array JSON, jawaban diambil satu per satu (dan
 * antreannya dipendekkan) sehingga uji dapat mensimulasikan beberapa putaran:
 * jawaban pertama salah bentuk, putaran perbaikan berikutnya benar.
 */
function ai_mock_next(): string
{
    $q = trim((string)setting('ai_mock_queue', ''));
    if ($q !== '') {
        $arr = json_decode($q, true);
        if (is_array($arr) && $arr) {
            $first = array_shift($arr);
            set_setting('ai_mock_queue', $arr ? json_encode($arr, JSON_UNESCAPED_UNICODE) : '');
            return is_string($first) ? $first : (string)json_encode($first, JSON_UNESCAPED_UNICODE);
        }
    }
    return (string)setting('ai_mock_reply', '');
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
 * Panggilan AI dengan PERCOBAAN ULANG + model cadangan + pemulihan jawaban terpotong.
 *
 * Model gratis sering menjawab "high demand" / kelebihan beban (HTTP 503) atau
 * "rate limit" (429) secara sementara. Tanpa penanganan, satu gangguan sesaat
 * membuat tugas gagal dan pengguna harus mengulang dari awal. Di sini panggilan
 * dicoba beberapa kali, lalu dicoba juga dengan MODEL LAIN dari penyedia yang
 * sama, sehingga peluang berhasil jauh lebih besar.
 *
 * RONDE 43: ditambahkan pemulihan khusus untuk jawaban yang TERPOTONG
 * (finishReason MAX_TOKENS / length). Penyebabnya token "berpikir" model Gemini
 * ikut memakan batas keluaran. Bila itu terjadi, panggilan diulang dengan
 * `thinkingConfig.thinkingBudget = 0` dan batas keluaran digandakan — cara ini
 * terbukti mengembalikan jawaban utuh pada model Gemini 3.
 *
 * RONDE 44: pemakaian token SEMUA percobaan (termasuk yang gagal) dijumlahkan dan
 * dikembalikan pada kunci `usage` supaya biaya/kuota per pengerjaan terlihat jujur.
 *
 * @param array $opts diteruskan ke ai_call() (mis. ['thinking_budget' => 0])
 */
function ai_call_resilient(array $messages, float $temperature = 0.2, int $maxTokens = 8192, array $opts = []): array
{
    $s = ai_settings();
    if (!empty($opts['provider'])) $s['provider'] = (string)$opts['provider'];
    $utama = (string)($opts['model'] ?? '') !== '' ? (string)$opts['model'] : $s['model'];
    $cadangan = [];
    foreach ((ai_model_options()[$s['provider']] ?? []) as $m) {
        if ($m !== $utama && $m !== 'mock-1') $cadangan[] = $m;
    }
    $model = [$utama];
    foreach (array_slice($cadangan, 0, 2) as $c) $model[] = $c;   // utama + maksimal 2 cadangan

    $pesanTerakhir = '';
    $teksTerakhir = '';
    $terpotong = false;
    $finishTerakhir = '';
    $usageTotal = ai_usage_empty();
    $panggilan = 0;
    foreach ($model as $i => $m) {
        $batas = $maxTokens;
        $opsModel = $opts;
        unset($opsModel['model']);
        for ($coba = 1; $coba <= 3; $coba++) {
            $r = ai_call($messages, $temperature, $batas, $m, $opsModel);
            $panggilan++;
            $usageTotal = ai_usage_add($usageTotal, (array)($r['usage'] ?? []));
            if ($r['ok']) {
                $r['usage'] = $usageTotal;
                $r['calls'] = $panggilan;
                ai_usage_record((int)($opts['task_id'] ?? 0), $s['provider'], $m,
                    (string)($opts['purpose'] ?? ''), $r['usage'], 1);
                return $r;
            }
            if (!empty($r['text'])) $teksTerakhir = (string)$r['text'];
            $pesanTerakhir = (string)$r['error'];
            $finishTerakhir = (string)($r['finish'] ?? '');
            if (!empty($r['truncated'])) {
                /* Kehabisan tempat: matikan "berpikir" dan perbesar batas keluaran. */
                $terpotong = true;
                $opsModel['thinking_budget'] = 0;
                $batas = min(65536, max($batas * 2, 16384));
                continue;
            }
            $terpotong = false;
            /* Hanya gangguan SEMENTARA yang layak diulang/dicoba model lain. */
            $sementara = (bool)preg_match('/high demand|overloaded|rate limit|quota|temporarily|503|429|timeout|Gagal menghubungi/i', $pesanTerakhir);
            if (!$sementara) break;
            if ($coba < 3) usleep(1500000);
        }
        if ($i + 1 < count($model)) sleep(1);
    }
    if ($panggilan > 0) {
        ai_usage_record((int)($opts['task_id'] ?? 0), $s['provider'], $utama,
            (string)($opts['purpose'] ?? '') . ' (gagal)', $usageTotal, $panggilan);
    }
    return ['ok' => false, 'text' => $teksTerakhir, 'raw' => '', 'truncated' => $terpotong, 'finish' => $finishTerakhir,
        'usage' => $usageTotal, 'calls' => $panggilan,
        'error' => 'Penyedia AI tidak memberi jawaban yang dapat dipakai pada semua model yang dicoba ('
            . implode(', ', $model) . '). ' . $pesanTerakhir
            . ' Silakan coba lagi beberapa saat lagi, atau ganti model di AI Settings.'];
}

/**
 * Pilih berkas yang benar-benar dikirim ke model pelaksana, dengan ANGGARAN TOTAL
 * (KB). Urutan prioritas:
 *   1. berkas yang disebut pada brief/badan permintaan (paling mungkin jadi sasaran),
 *   2. berkas yang isinya paling banyak menyebut kata kunci permintaan,
 *   3. berkas yang lebih kecil lebih dulu (lebih murah, biasanya berkas fitur).
 * Berkas yang tidak kebagian anggaran DILAPORKAN (bukan dirahasiakan) karena AI
 * tidak dapat mengubah berkas yang belum dibacanya.
 *
 * @return array{isi:array<string,string>,dilewati:array<int,string>,kb:float}
 */
function ai_context_trim(array $isi, string $permintaan, string $brief = '', array $wajib = [], ?int $anggaranKb = null): array
{
    if (!$isi) return ['isi' => [], 'dilewati' => [], 'kb' => 0.0];
    $anggaran = ($anggaranKb ?? ai_settings()['context_kb']) * 1024;
    /* Berkas yang DIPILIH AI dianggap sasaran perubahan → selalu diutamakan.
       Menghemat token tidak boleh sampai membuat patch gagal karena berkas
       sasarannya tidak dikirim (pernah terjadi: importer.php dipangkas sehingga
       permintaan impor gagal seluruhnya). */
    $wajib = array_map('strval', $wajib);
    $kata = [];
    foreach (preg_split('/[^A-Za-z0-9_]+/', strtolower($permintaan . ' ' . $brief)) ?: [] as $w) {
        if (strlen($w) >= 5) $kata[$w] = true;
    }
    $nilai = [];
    foreach ($isi as $rel => $teks) {
        $skor = 0;
        if (in_array((string)$rel, $wajib, true)) $skor += 5000;
        if ($brief !== '' && strpos($brief, (string)$rel) !== false) $skor += 1000;
        if (strpos($permintaan, (string)basename((string)$rel)) !== false) $skor += 500;
        $cocok = 0;
        foreach (array_keys($kata) as $w) {
            $cocok += min(5, substr_count(strtolower((string)$teks), $w));
        }
        $skor += min(200, $cocok);
        $nilai[(string)$rel] = [$skor, strlen((string)$teks)];
    }
    /* Nilai terbesar dulu; bila sama, berkas lebih kecil dulu. */
    uksort($nilai, function ($a, $b) use ($nilai) {
        if ($nilai[$a][0] === $nilai[$b][0]) return $nilai[$a][1] <=> $nilai[$b][1];
        return $nilai[$b][0] <=> $nilai[$a][0];
    });
    $pakai = [];
    $lewati = [];
    $total = 0;
    foreach (array_keys($nilai) as $rel) {
        $ukuran = $nilai[$rel][1];
        $harus = in_array((string)$rel, $wajib, true);
        /* Berkas WAJIB selalu ikut walau melebihi anggaran (benar dulu, hemat kedua). */
        if ($harus || $total + $ukuran <= $anggaran || !$pakai) {
            $pakai[$rel] = $isi[$rel];
            $total += $ukuran;
        } else {
            $lewati[] = $rel . ' (' . num(round($ukuran / 1024)) . ' KB)';
        }
    }
    return ['isi' => $pakai, 'dilewati' => $lewati, 'kb' => round($total / 1024, 1),
            'kb_wajib' => round(array_sum(array_map(fn($r) => strlen((string)($isi[$r] ?? '')), $wajib)) / 1024, 1)];
}

/* ------------------------------------------------------------------ *
 * RANTAI PELAKSANA + CADANGAN OTOMATIS (ronde 45)
 * ------------------------------------------------------------------ */

/**
 * Daftar pelaksana yang akan dicoba berurutan: model utama, lalu (bila diaktifkan)
 * model cadangan pada penyedia lain. Dipakai supaya pekerjaan TIDAK berhenti hanya
 * karena satu penyedia kehabisan kuota — dua saldo (Gemini & OpenAI) jadi saling
 * menutupi.
 *
 * @return array<int,array{provider:string,model:string,key:string,utama:bool}>
 */
function ai_executor_chain(): array
{
    $s = ai_settings();
    $chain = [[
        'provider' => $s['provider'], 'model' => $s['model'], 'key' => $s['api_key'], 'utama' => true,
    ]];
    if (!$s['fallback_on']) return $chain;
    $fb = [
        'provider' => $s['fallback_provider'], 'model' => $s['fallback_model'],
        'key' => $s['fallback_key'], 'utama' => false,
    ];
    /* Cadangan tanpa kunci tidak berguna — kecuali penyedia tiruan (untuk uji). */
    $sama = ($fb['provider'] === $s['provider'] && $fb['model'] === $s['model']);
    if (!$sama && ($fb['key'] !== '' || $fb['provider'] === 'mock')) $chain[] = $fb;
    return $chain;
}

/** Keterangan rantai pelaksana untuk ditampilkan (jujur apa adanya). */
function ai_executor_text(): string
{
    $c = ai_executor_chain();
    $out = [];
    foreach ($c as $i => $e) {
        $out[] = ($i === 0 ? 'utama: ' : 'cadangan: ') . strtoupper($e['provider']) . ' (' . $e['model'] . ')';
        if ($i === 0 && $e['key'] === '' && $e['provider'] !== 'mock') $out[count($out) - 1] .= ' — kunci belum diisi';
    }
    return implode(' · ', $out);
}

/**
 * Panggil AI memakai RANTAI pelaksana: model utama lebih dulu, lalu cadangan.
 * Pemakaian token SETIAP percobaan tetap tercatat (termasuk yang gagal), sehingga
 * laporan token jujur walau ada perpindahan model.
 *
 * @param array $opts diteruskan ke ai_call_resilient()
 */
function ai_call_chain(array $messages, float $temperature, int $maxTokens, array $opts = []): array
{
    $chain = ai_executor_chain();
    $pesanAkhir = '';
    $teksAkhir = '';
    $usageSemua = ai_usage_empty();
    $panggilanSemua = 0;
    foreach ($chain as $i => $e) {
        $o = $opts;
        $o['provider'] = $e['provider'];
        $o['api_key'] = $e['key'];
        $o['model'] = $e['model'];
        $o['purpose'] = (string)($opts['purpose'] ?? '') . ($i > 0 ? ' [cadangan]' : '');
        /* JSON dipaksa hanya untuk keluarga OpenAI (Gemini memakai prompt). */
        if ($e['provider'] === 'openai') $o['json'] = true;
        $r = ai_call_resilient($messages, $temperature, $maxTokens, $o);
        $usageSemua = ai_usage_add($usageSemua, (array)($r['usage'] ?? []));
        $panggilanSemua += (int)($r['calls'] ?? 0);
        if ($r['ok']) {
            $r['usage'] = $usageSemua;
            $r['calls'] = $panggilanSemua;
            $r['provider'] = $e['provider'];
            $r['model'] = $e['model'];
            $r['pindah_model'] = $i > 0;
            return $r;
        }
        $pesanAkhir = (string)$r['error'];
        if (trim((string)$r['text']) !== '') $teksAkhir = (string)$r['text'];
        if ($i + 1 < count($chain)) {
            ai_step((int)($opts['task_id'] ?? 0), 'warn',
                'Model ' . strtoupper($e['provider']) . ' (' . $e['model'] . ') gagal — beralih ke model cadangan',
                short_text((string)$r['error'], 220));
        }
    }
    return ['ok' => false, 'text' => $teksAkhir, 'raw' => '', 'truncated' => false, 'finish' => '',
            'usage' => $usageSemua, 'calls' => $panggilanSemua,
            'error' => 'Semua model pada rantai pelaksana gagal (' . ai_executor_text() . '). ' . $pesanAkhir];
}

/* ------------------------------------------------------------------ *
 * LANGKAH BERJALAN & PERCAKAPAN (ronde 45)
 * ------------------------------------------------------------------ */

/** Catat satu langkah pekerjaan (dibaca halaman untuk menampilkan prosesnya). */
function ai_step(int $taskId, string $kind, string $text, string $detail = ''): void
{
    if ($taskId <= 0) return;
    try {
        q('INSERT INTO ai_steps (task_id, kind, text, detail, created_at)
           VALUES (?,?,?,?,datetime("now","localtime"))', [$taskId, $kind, short_text($text, 300), short_text($detail, 600)]);
    } catch (Throwable $e) { /* langkah tidak boleh menggagalkan pekerjaan */ }
}

/** Daftar langkah sebuah tugas. */
function ai_steps(int $taskId, int $limit = 200): array
{
    try {
        return all('SELECT * FROM ai_steps WHERE task_id = ? ORDER BY id LIMIT ' . max(1, $limit), [$taskId]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Tambah satu pesan percakapan (pemilik atau AI). */
function ai_msg(int $taskId, string $role, string $text): void
{
    if ($taskId <= 0 || trim($text) === '') return;
    $role = $role === 'ai' ? 'ai' : 'user';
    try {
        q('INSERT INTO ai_messages (task_id, role, text, created_at)
           VALUES (?,?,?,datetime("now","localtime"))', [$taskId, $role, $text]);
    } catch (Throwable $e) { /* abaikan */ }
}

/** Pesan percakapan sebuah tugas. */
function ai_messages(int $taskId): array
{
    try {
        return all('SELECT * FROM ai_messages WHERE task_id = ? ORDER BY id', [$taskId]);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Riwayat percakapan sebagai teks untuk prompt — sehingga perintah lanjutan
 * ("yang tadi kurang …", "tambahkan juga …") dipahami sebagai lanjutan.
 */
function ai_conversation_text(int $taskId, int $maxChars = 8000): string
{
    $m = ai_messages($taskId);
    if (count($m) <= 1) return '';
    $out = [];
    foreach ($m as $x) {
        $out[] = ($x['role'] === 'ai' ? 'Asisten (AI)' : 'Pemilik aplikasi') . ': ' . short_text((string)$x['text'], 1500);
    }
    $teks = implode("\n\n", $out);
    return ai_strlen($teks) > $maxChars ? substr($teks, -$maxChars) : $teks;
}

/** Tandai tugas selesai dengan ringkasan + saran untuk pemilik. */
function ai_finish_summary(array $task, array $saran = []): string
{
    $berkas = json_decode((string)($task['files'] ?? '[]'), true) ?: [];
    $baris = [];
    $baris[] = '**Yang dikerjakan:** ' . (trim((string)($task['plan'] ?? '')) !== ''
        ? (string)$task['plan'] : 'menyusun usulan perubahan');
    if ($berkas) {
        $baris[] = '**Berkas yang diubah (' . count($berkas) . '):** ' . implode(', ', $berkas);
    }
    $uji = (string)($task['test_status'] ?? '');
    if ($uji !== '') $baris[] = '**Hasil uji di salinan:** ' . $uji;
    $lint = trim((string)($task['lint'] ?? ''));
    if ($lint !== '') $baris[] = '**Pemeriksaan sintaks:** ' . (strpos($lint, 'GAGAL') === false ? 'semua berkas OK' : 'ADA MASALAH — periksa');
    /* JEBAKAN (ronde 45, sudah terjadi): `ai_usage_task()['total']` adalah ANGKA,
       bukan array pemakaian — memasukkannya langsung ke `ai_usage_text()` melempar
       TypeError yang MEMATIKAN pekerja (exit 255) SETELAH semua pekerjaan selesai,
       sehingga pesan laporan & saran tidak pernah tersimpan. Bentuk array-nya dulu. */
    $pakai = ai_usage_task((int)$task['id']);
    if ((int)$pakai['total'] > 0) {
        $baris[] = '**Pemakaian:** ' . ai_usage_text([
            'in' => (int)$pakai['in'], 'out' => (int)$pakai['out'],
            'think' => (int)$pakai['think'], 'total' => (int)$pakai['total'],
        ], (int)$pakai['calls']);
    }
    if ($saran) {
        $baris[] = '**Saran untuk Anda:**';
        foreach (array_slice($saran, 0, 6) as $x) $baris[] = '• ' . trim((string)$x);
    }
    return implode("\n", $baris);
}

/* ------------------------------------------------------------------ *
 * PEMAKAIAN TOKEN (ronde 44)
 * ------------------------------------------------------------------ */

/** Bentuk kosong catatan pemakaian token. */
function ai_usage_empty(): array
{
    return ['in' => 0, 'out' => 0, 'think' => 0, 'total' => 0];
}

/** Jumlahkan dua catatan pemakaian token. */
function ai_usage_add(array $a, array $b): array
{
    return [
        'in'    => (int)($a['in'] ?? 0) + (int)($b['in'] ?? 0),
        'out'   => (int)($a['out'] ?? 0) + (int)($b['out'] ?? 0),
        'think' => (int)($a['think'] ?? 0) + (int)($b['think'] ?? 0),
        'total' => (int)($a['total'] ?? 0) + (int)($b['total'] ?? 0),
    ];
}

/**
 * Baca pemakaian token dari balasan penyedia.
 * Gemini: usageMetadata {promptTokenCount, candidatesTokenCount, thoughtsTokenCount, totalTokenCount}
 * OpenAI: usage {prompt_tokens, completion_tokens, total_tokens, completion_tokens_details.reasoning_tokens}
 */
function ai_usage_parse(string $provider, array $u): array
{
    if (!$u) return ai_usage_empty();
    if ($provider === 'gemini') {
        $in = (int)($u['promptTokenCount'] ?? 0);
        $out = (int)($u['candidatesTokenCount'] ?? 0);
        $think = (int)($u['thoughtsTokenCount'] ?? 0);
        $total = (int)($u['totalTokenCount'] ?? ($in + $out + $think));
    } else {
        $in = (int)($u['prompt_tokens'] ?? 0);
        $out = (int)($u['completion_tokens'] ?? 0);
        $think = (int)($u['completion_tokens_details']['reasoning_tokens'] ?? 0);
        $total = (int)($u['total_tokens'] ?? ($in + $out));
    }
    if ($total <= 0) $total = $in + $out + $think;
    return ['in' => $in, 'out' => $out, 'think' => $think, 'total' => $total];
}

/**
 * Pemakaian token tiruan untuk penyedia "mock" (dipakai uji otomatis).
 * Bila jawaban berbentuk {"usage":{"in":…,"out":…}} angkanya diambil dari situ,
 * sehingga uji dapat memeriksa tampilan pemakaian token tanpa jaringan.
 */
function ai_usage_parse_mock(string $reply): array
{
    $j = json_decode(trim($reply), true);
    if (is_array($j) && isset($j['usage']) && is_array($j['usage'])) {
        $in = (int)($j['usage']['in'] ?? 0);
        $out = (int)($j['usage']['out'] ?? 0);
        $think = (int)($j['usage']['think'] ?? 0);
        return ['in' => $in, 'out' => $out, 'think' => $think,
                'total' => (int)($j['usage']['total'] ?? ($in + $out + $think))];
    }
    /* Bawaan: hitung kasar dari panjang teks supaya tetap ada angka untuk diuji. */
    $perkiraan = max(1, (int)round(strlen($reply) / 4));
    return ['in' => 0, 'out' => $perkiraan, 'think' => 0, 'total' => $perkiraan];
}

/** Catat satu panggilan AI ke `ai_usage_log` + tambahkan ke total tugasnya. */
function ai_usage_record(int $taskId, string $provider, string $model, string $purpose, array $usage, int $calls = 1): void
{
    try {
        q('INSERT INTO ai_usage_log (task_id, provider, model, purpose, calls, tokens_in, tokens_out, tokens_think, tokens_total)
           VALUES (?,?,?,?,?,?,?,?,?)',
            [$taskId > 0 ? $taskId : null, $provider, $model, $purpose, $calls,
             (int)($usage['in'] ?? 0), (int)($usage['out'] ?? 0), (int)($usage['think'] ?? 0), (int)($usage['total'] ?? 0)]);
        if ($taskId > 0 && ((int)($usage['total'] ?? 0) > 0 || $calls > 0)) {
            q('UPDATE ai_tasks SET calls = COALESCE(calls,0) + ?, tokens_in = COALESCE(tokens_in,0) + ?,
                      tokens_out = COALESCE(tokens_out,0) + ?, tokens_think = COALESCE(tokens_think,0) + ?,
                      tokens_total = COALESCE(tokens_total,0) + ?
               WHERE id = ?',
                [$calls, (int)($usage['in'] ?? 0), (int)($usage['out'] ?? 0),
                 (int)($usage['think'] ?? 0), (int)($usage['total'] ?? 0), $taskId]);
        }
    } catch (Throwable $e) {
        /* Pencatatan pemakaian TIDAK boleh menggagalkan pekerjaan AI. */
    }
}

/** Pemakaian token satu tugas (dan rincian tiap panggilan). */
function ai_usage_task(int $taskId): array
{
    $t = one('SELECT COALESCE(calls,0) calls, COALESCE(tokens_in,0) tokens_in, COALESCE(tokens_out,0) tokens_out,
                     COALESCE(tokens_think,0) tokens_think, COALESCE(tokens_total,0) tokens_total
              FROM ai_tasks WHERE id = ?', [$taskId]) ?: [];
    $baris = all('SELECT * FROM ai_usage_log WHERE task_id = ? ORDER BY id', [$taskId]);
    return [
        'calls' => (int)($t['calls'] ?? 0),
        'in' => (int)($t['tokens_in'] ?? 0),
        'out' => (int)($t['tokens_out'] ?? 0),
        'think' => (int)($t['tokens_think'] ?? 0),
        'total' => (int)($t['tokens_total'] ?? 0),
        'log' => $baris,
    ];
}

/** Ringkasan pemakaian token seluruh tugas (opsional dibatasi jumlah hari). */
function ai_usage_summary(int $days = 0): array
{
    $where = '';
    $par = [];
    if ($days > 0) {
        $where = 'WHERE created_at >= datetime("now","localtime",?)';
        $par[] = '-' . $days . ' days';
    }
    $rows = all('SELECT provider, model, COUNT(*) baris, SUM(calls) calls, SUM(tokens_in) tin, SUM(tokens_out) tout,
                        SUM(tokens_think) tthink, SUM(tokens_total) ttotal
                 FROM ai_usage_log ' . $where . ' GROUP BY provider, model ORDER BY ttotal DESC', $par);
    $total = ai_usage_empty();
    $panggilan = 0;
    $perModel = [];
    foreach ($rows as $r) {
        $u = ['in' => (int)$r['tin'], 'out' => (int)$r['tout'], 'think' => (int)$r['tthink'], 'total' => (int)$r['ttotal']];
        $total = ai_usage_add($total, $u);
        $panggilan += (int)$r['calls'];
        $perModel[] = ['provider' => (string)$r['provider'], 'model' => (string)$r['model'],
                       'calls' => (int)$r['calls'], 'usage' => $u, 'tugas' => (int)$r['baris'],
                       'biaya' => ai_usage_cost($u)];
    }
    $tugas = (int)scalar('SELECT COUNT(*) FROM ai_tasks WHERE tokens_total > 0', [], 0);
    return ['total' => $total, 'calls' => $panggilan, 'per_model' => $perModel,
            'tugas' => $tugas, 'biaya' => ai_usage_cost($total)];
}

/**
 * Perkiraan biaya dari jumlah token — HANYA bila harga diisi di AI Settings
 * (bawaan 0 = tidak dihitung, karena harga tiap model berbeda-beda dan berubah).
 */
function ai_usage_cost(array $usage): float
{
    $s = ai_settings();
    if ($s['price_in'] <= 0 && $s['price_out'] <= 0) return 0.0;
    return ((int)($usage['in'] ?? 0) / 1000000) * $s['price_in']
         + (((int)($usage['out'] ?? 0) + (int)($usage['think'] ?? 0)) / 1000000) * $s['price_out'];
}

/** Teks ringkas pemakaian token (dipakai di banyak tempat). */
function ai_usage_text(array $usage, int $calls = 0): string
{
    if ((int)($usage['total'] ?? 0) <= 0) return 'belum ada pemakaian tercatat';
    $t = num((int)$usage['total']) . ' token';
    $rinci = [];
    if ((int)$usage['in'] > 0) $rinci[] = 'masuk ' . num((int)$usage['in']);
    if ((int)$usage['out'] > 0) $rinci[] = 'keluar ' . num((int)$usage['out']);
    if ((int)($usage['think'] ?? 0) > 0) $rinci[] = 'berpikir ' . num((int)$usage['think']);
    if ($rinci) $t .= ' (' . implode(' · ', $rinci) . ')';
    if ($calls > 0) $t = num($calls) . ' panggilan · ' . $t;
    $biaya = ai_usage_cost($usage);
    if ($biaya > 0) $t .= ' · perkiraan biaya ' . money($biaya);
    return $t;
}

/* ------------------------------------------------------------------ *
 * LAMPIRAN (ronde 44) — Excel/PDF/gambar/dokumen apa pun
 * ------------------------------------------------------------------ */

/** Folder lampiran sebuah tugas (DI LUAR folder aplikasi yang disajikan publik). */
function ai_attach_dir(int $taskId): string
{
    $d = ai_tmp_dir() . '/uploads/task-' . max(0, $taskId);
    if (!is_dir($d)) @mkdir($d, 0770, true);
    return $d;
}

/** Ubah nilai singkatan ukuran PHP ("2M", "64M", "512K") menjadi MB. */
function ai_ini_mb(string $nama, float $bawaan): float
{
    $v = trim((string)ini_get($nama));
    if ($v === '') return $bawaan;
    $satuan = strtolower(substr($v, -1));
    $angka = (float)$v;
    if ($satuan === 'g') return $angka * 1024;
    if ($satuan === 'm') return $angka;
    if ($satuan === 'k') return $angka / 1024;
    return $angka / 1048576;   // nilai dalam byte
}

/**
 * Batas ukuran satu lampiran (MB) — dibatasi juga oleh batas SERVER yang berlaku,
 * supaya petunjuknya jujur: mengizinkan 20 MB padahal server hanya menerima 2 MB
 * akan membuat unggahan gagal tanpa penjelasan yang jelas.
 */
function ai_attach_max_mb(): int
{
    $setelan = max(1, min(40, (int)setting('ai_attach_max_mb', '20')));
    $perBerkasServer = (int)floor(ai_ini_mb('upload_max_filesize', 2));
    return max(1, min($setelan, max(1, $perBerkasServer)));
}

/** Keterangan batas unggahan yang BERLAKU (dipakai petunjuk di halaman). */
function ai_upload_limit_text(): string
{
    $perBerkas = ai_attach_max_mb();
    $total = (int)floor(ai_ini_mb('post_max_size', 8));
    $t = 'Batas yang berlaku di server ini: maksimal ' . num($perBerkas) . ' MB per berkas';
    if ($total > 0) {
        $t .= ', dan TOTAL ' . num($total) . ' MB untuk seluruh berkas dalam satu permintaan';
    }
    return $t . '. Bila lebih besar, kompres atau pecah berkasnya dulu.';
}

/** Jenis lampiran dari ekstensi (menentukan cara membacanya). */
function ai_attach_kind(string $ext): string
{
    $ext = strtolower($ext);
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'heic', 'tif', 'tiff'], true)) return 'image';
    if ($ext === 'pdf') return 'pdf';
    if (in_array($ext, ['xlsx', 'xlsm', 'csv', 'tsv', 'txt', 'json', 'md', 'xls'], true)) return 'sheet';
    if (in_array($ext, ['docx', 'pptx', 'odt', 'ods', 'rtf'], true)) return 'doc';
    return 'other';
}

/** MIME sederhana dari ekstensi (dipakai saat mengirim lampiran ke penyedia). */
function ai_mime_for(string $ext): string
{
    $m = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
        'gif' => 'image/gif', 'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
        'heic' => 'image/heic', 'pdf' => 'application/pdf',
    ];
    return $m[strtolower($ext)] ?? 'application/octet-stream';
}

/**
 * Teks dari berkas lampiran (best-effort, jujur bila formatnya belum terbaca).
 *
 * Format yang dibaca tanpa pustaka tambahan:
 *   • .xlsx/.xlsm/.csv/.tsv/.txt/.json/.md  → tabel/teks (importer.php)
 *   • .docx/.pptx/.odt/.ods                 → teks di dalam paket ZIP
 *   • .pdf                                  → `pdftotext` bila tersedia di server
 * Sisanya (mis. .xls lama) dilaporkan apa adanya dan berkasnya tetap disimpan
 * (gambar & PDF tetap dapat dibaca model AI secara langsung).
 */
function ai_attach_extract(string $path, string $name, ?string &$note = null): array
{
    $note = '';
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $kind = ai_attach_kind($ext);
    try {
        if ($kind === 'sheet') {
            if ($ext === 'xls') { $note = 'Format .xls (Excel lama) tidak dapat dibaca — simpan ulang sebagai .xlsx/CSV.'; return ['text' => '', 'klip' => true]; }
            if (in_array($ext, ['csv', 'tsv', 'txt'], true)) {
                $isi = (string)file_get_contents($path);
                $note = 'Teks apa adanya ('.num(strlen($isi)).' huruf).';
                return ['text' => $isi, 'klip' => false];
            }
            if ($ext === 'md') {
                $isi = (string)file_get_contents($path);
                return ['text' => $isi, 'klip' => false];
            }
            require_once __DIR__ . '/importer.php';
            $tab = read_tabular($path, $name);
            $baris = [];
            $baris[] = 'Kolom: ' . implode(' | ', array_map(fn($h) => (string)$h, $tab['headers']));
            $n = 0;
            foreach ($tab['rows'] as $r) {
                $nilai = [];
                foreach ($tab['headers'] as $i => $h) $nilai[] = (string)($r[$i] ?? '');
                $baris[] = implode(' | ', $nilai);
                if (++$n >= 400) { $baris[] = '… (sisanya dipotong)'; break; }
            }
            $note = num(count($tab['rows'])) . ' baris data dibaca.';
            return ['text' => implode("\n", $baris), 'klip' => false];
        }
        if ($kind === 'doc') {
            require_once __DIR__ . '/importer.php';
            $entries = zip_read_entries($path);
            $teks = '';
            foreach ($entries as $nm => $isi) {
                if (!preg_match('~(word/document\.xml|ppt/slides/slide\d+\.xml|content\.xml)$~i', (string)$nm)) continue;
                $bersih = preg_replace('~<[^>]+>~', ' ', (string)$isi);
                $bersih = html_entity_decode((string)$bersih, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $teks .= trim(preg_replace('/\s+/', ' ', (string)$bersih)) . "\n\n";
            }
            $teks = trim($teks);
            $note = $teks !== '' ? 'Teks di dalam dokumen dibaca.' : 'Isi dokumen tidak dapat dibaca.';
            return ['text' => $teks, 'klip' => false];
        }
        if ($kind === 'pdf') {
            /* pdftotext tersedia di banyak server; bila tidak ada, PDF tetap dapat
               dibaca model AI (dikirim sebagai lampiran langsung untuk Gemini). */
            $bin = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
            if ($bin !== '') {
                $out = @shell_exec(escapeshellarg($bin) . ' -layout ' . escapeshellarg($path) . ' - 2>/dev/null');
                $teks = trim((string)$out);
                if ($teks !== '') {
                    $note = 'Teks PDF dibaca (' . num(strlen($teks)) . ' huruf).';
                    return ['text' => $teks, 'klip' => false];
                }
            }
            $note = 'Teks PDF tidak dapat diambil di server ini — PDF akan dibaca langsung oleh model AI (Gemini).';
            return ['text' => '', 'klip' => false];
        }
        if ($kind === 'image') {
            $note = 'Gambar — dibaca langsung oleh model AI.';
            return ['text' => '', 'klip' => false];
        }
    } catch (Throwable $ex) {
        $note = 'Gagal membaca isi berkas: ' . $ex->getMessage();
        return ['text' => '', 'klip' => false];
    }
    $note = 'Format .' . $ext . ' disimpan, tetapi isinya belum dapat dibaca otomatis.';
    return ['text' => '', 'klip' => false];
}

/* ------------------------------------------------------------------ *
 * PENELUSURAN OTOMATIS (ronde 44) — tanpa perlu memilih menu/pilihan
 * ------------------------------------------------------------------ */

/**
 * Tebak halaman PRATINJAU yang paling tepat dari berkas yang diubah.
 * Dipakai untuk menampilkan pratinjau visual otomatis setelah AI selesai.
 */
function ai_preview_page(array $files, string $request = ''): string
{
    $kandidat = [];
    foreach ($files as $f) {
        $f = str_replace('\\', '/', (string)$f);
        if (preg_match('~^naveena/([a-z0-9_]+\.php)$~i', $f, $m)) $kandidat[] = $m[1];
    }
    foreach ($kandidat as $k) {
        if (in_array($k, ['ai_developer.php', 'ai_settings.php'], true)) return $k;
    }
    foreach (['keuangan.php', 'dashboard.php', 'laporan.php', 'order_baru.php', 'pasien.php',
              'rekam_medis.php', 'reservasi.php', 'settings.php', 'developer.php'] as $utama) {
        if (in_array($utama, $kandidat, true)) return $utama;
    }
    if ($kandidat) return $kandidat[0];
    /* Hanya berkas bersama (layout/CSS/helper) → dashboard menampilkan sidebar & CSS. */
    return 'dashboard.php';
}

/**
 * Pilih SUITE UJI otomatis dari permintaan + berkas yang berubah.
 * Dipakai ketika pemilik tidak memilih suite (bawaan "Otomatis") — supaya tetap
 * ada pemeriksaan yang relevan tanpa harus memahami daftar suite.
 *
 * @return array{suite:string,alasan:string}
 */
function ai_auto_suite(array $files, string $request): array
{
    $ada = ai_suite_list();
    /* PENTING: kata kunci pendek dicocokkan dengan BATAS KATA dan hanya pada teks
       permintaan + NAMA berkas (tanpa ekstensi). Tanpa ini, akhiran ".php" ternyata
       memuat huruf "hp" sehingga perubahan apa pun dianggap soal tampilan HP
       (pernah terjadi: permintaan tentang komentar dashboard dianggap uji
       `responsif-hp-tablet`). */
    $teksPermintaan = ' ' . strtolower($request) . ' ';
    $namaBerkas = ' ' . strtolower(implode(' ', array_map(
        fn($f) => (string)pathinfo((string)$f, PATHINFO_FILENAME), $files))) . ' ';
    $aturan = [
        [['keuangan'], ['keuangan', 'laba', 'hpp', 'biaya operasional', 'omzet']],
        [['paket'], ['paket', 'package']],
        [['kartu-member'], ['kartu member', 'diskon member']],
        /* 'kode unik' & 'wajib bayar' ada di suite pembayaran-transaksi; kata kunci
           gateway (midtrans/xendit) ada di suite pembayaran-otomatis. */
        [['pembayaran-otomatis'], ['gateway', 'midtrans', 'xendit', 'webhook', 'tagihan otomatis']],
        [['pembayaran-transaksi'], ['pembayaran', 'wajib bayar', 'kode unik', 'qris', 'transfer']],
        /* RONDE 50: kata "whatsapp" saja tidak cukup — permintaan seperti
           "tambahkan tombol Salin WA pada tabel pasien" bukan soal struk. Suite ini
           hanya dipakai bila BERKAS yang diubah memang berkaitan dengan struk/order. */
        [['struk-whatsapp'], ['struk', 'whatsapp', 'cetak struk'], '(struk|order|receipt|pembayaran|wa_)'],
        [['import-data'], ['impor', 'import', 'excel', 'csv']],
        [['reservasi-fitur'], ['reservasi', 'appointment', 'jadwal']],
        [['ronde38-keamanan-login'], ['2fa', 'verifikasi 2 langkah', 'lupa kata sandi', 'kata sandi', 'login']],
        /* 'ai-developer' menguji AI Developer DARI DALAM salinan → rekursif dan berat.
           Karena itu hanya dipakai bila permintaannya memang tentang menu/UI AI Developer,
           bukan sekadar menyebut kata "AI Developer" pada permintaan besar lain. */
        [['ai-developer'], ['ai developer', 'ai settings', 'prompt ai'], NULL],
        [['nama-klinik'], ['nama klinik', 'branding']],
        [['retensi-data'], ['retensi', 'hapus otomatis']],
        [['backup-database'], ['backup', 'cadangan']],
        [['dokumen-fungsi'], ['dokumen fungsi', 'ringkasan fungsi']],
        [['ui-rekam-medis'], ['rekam medis', 'soap', 'icd']],
        [['ekspor-excel-foto'], ['ekspor', 'export', 'unduh excel', 'xlsx']],
        /* Awalan "!" = kata kunci HANYA dicocokkan pada teks permintaan ("hp" aman
           sebagai kata utuh di sana, tetapi menyesatkan bila dicocokkan ke NAMA berkas
           seperti "dashboard.php"). */
        [['responsif-hp-tablet'], ['!hp', 'tablet', 'responsif', 'mobile', 'layar kecil']],
        [['filter-semua-menu'], ['filter', 'penyaring']],
        [['warna-grafik'], ['warna grafik', 'palet']],
        [['ui-tema'], ['tema', 'warna tampilan']],
        [['kartu-member'], ['member']],
    ];
    foreach ([['permintaan', $teksPermintaan], ['berkas', $namaBerkas]] as [$sumber, $teks]) {
        foreach ($aturan as $baris) {
            [$suiteKandidat, $kata] = [$baris[0], $baris[1]];
            /* Syarat TAMBAHAN (opsional): pola yang harus cocok pada NAMA BERKAS yang
               diubah. Dipakai untuk suite yang kata kuncinya mudah salah tangkap. */
            $syaratBerkas = (string)($baris[2] ?? '');
            if ($syaratBerkas !== '' && !preg_match('~' . $syaratBerkas . '~i', trim($namaBerkas))) continue;
            foreach ($kata as $k) {
                $hanyaPermintaan = ($k[0] === '!');
                if ($hanyaPermintaan) {
                    if ($sumber !== 'permintaan') continue;
                    $k = substr($k, 1);
                }
                if (!preg_match('~\b' . preg_quote($k, '~') . '\b~u', $teks)) continue;
                foreach ($suiteKandidat as $s) {
                    /* Anti-rekursi: suite 'ai-developer' menguji AI Developer DARI DALAM
                       salinan (berat & bisa pecah sendiri). Hanya dipakai bila permintaan
                       memang tentang menu/UI AI Developer — bukan refactor besar yang
                       kebetulan menyebut namanya. */
                    if ($s === 'ai-developer'
                        && !preg_match('~\b(menu|halaman|kartu|tombol|chat|panduan|sidebar|ikon|tampilan)~i',
                            $teksPermintaan)) continue;
                    if (in_array($s, $ada, true)) {
                        return ['suite' => $s, 'alasan' => 'kata kunci "' . $k . '" pada ' . $sumber];
                    }
                }
            }
        }
    }
    /* Perubahan tampilan/kecil: sintaks-js paling cepat & tepat. */
    if (in_array('sintaks-js', $ada, true)) {
        return ['suite' => 'sintaks-js', 'alasan' => 'pemeriksaan sintaks & tampilan (paling cepat)'];
    }
    return ['suite' => ai_settings()['default_suite'], 'alasan' => 'suite bawaan AI Settings'];
}

/** Apakah mode kolaborasi (Arsitek + Pelaksana) siap dipakai? */
function ai_architect_ready(): bool
{
    $s = ai_settings();
    if ($s['collab'] !== 'on') return false;
    return trim($s['arch_key']) !== '';
}

/** Keterangan singkat keadaan mode kolaborasi (jujur apa adanya). */
function ai_architect_text(): string
{
    $s = ai_settings();
    if ($s['collab'] !== 'on') return 'Mode kolaborasi NONAKTIF (hanya model utama yang bekerja).';
    if (trim($s['arch_key']) === '') {
        return 'Mode kolaborasi aktif tetapi kunci API ' . strtoupper($s['arch_provider'])
            . ' belum diisi — sementara hanya model utama yang bekerja.';
    }
    return 'Kolaborasi aktif: ' . strtoupper($s['arch_provider']) . ' (' . $s['arch_model']
        . ') menerjemahkan perintah → ' . strtoupper($s['provider']) . ' (' . $s['model'] . ') mengerjakan.';
}

/**
 * "ARSITEK" (mode kolaborasi): menerjemahkan perintah bebas pemilik menjadi brief
 * teknis yang jelas untuk model pelaksana. Mengembalikan teks brief ('' bila gagal).
 */
function ai_architect_brief(string $request, string $attachText, int $taskId, ?string &$err = null): string
{
    $err = '';
    if (!ai_architect_ready()) { $err = 'Mode kolaborasi belum siap.'; return ''; }
    $s = ai_settings();
    $daftar = [];
    foreach (ai_scope_files() as $f) $daftar[] = $f['rel'];
    $pesan = "Perintah pemilik aplikasi (bahasa sehari-hari):\n" . $request . "\n\n";
    if ($attachText !== '') {
        $pesan .= "Sebagian isi lampiran (dipotong):\n" . substr($attachText, 0, 20000)
            . "\n\n(Isi lampiran lengkap akan diberikan kepada model pelaksana.)\n\n";
    }
    if ($daftar) {
        $pesan .= "Daftar berkas aplikasi yang boleh diubah:\n" . implode("\n", array_slice($daftar, 0, 400)) . "\n\n";
    }
    $pesan .= "TUGAS ANDA: jadilah ARSITEK. Terjemahkan perintah di atas menjadi BRIEF teknis yang sangat jelas "
        . "untuk model pelaksana (yang akan menulis patch kode). Jawab dalam bahasa Indonesia, ringkas, "
        . "dan PASTI memuat:\n"
        . "1. TUJUAN: apa yang harus berubah dari sudut pandang pemakai.\n"
        . "2. LANGKAH: urutan perubahan teknis yang diperlukan.\n"
        . "3. BERKAS: perkiraan berkas/fungsi yang perlu disentuh (sebut path relatif bila tahu).\n"
        . "4. ATURAN DATA: tabel/kolom basis data yang terlibat, sumber data, dan aturan validasi "
        . "(termasuk kolom WAJIB, nilai bawaan, dan apa yang harus dibuat otomatis).\n"
        . "5. KRITERIA SELESAI: hal-hal yang harus benar setelah perubahan (agar bisa diuji).\n"
        . "6. RISIKO: hal yang mudah salah.\n"
        . "Jangan menulis kode. Cukup brief-nya saja.";
    $r = ai_call_resilient([['role' => 'user', 'text' => $pesan]], 0.1, 4096, [
        'provider' => $s['arch_provider'],
        'api_key' => $s['arch_key'],
        'model' => $s['arch_model'],
        'task_id' => $taskId,
        'purpose' => 'brief arsitek',
    ]);
    if (!$r['ok'] || trim((string)$r['text']) === '') {
        $err = $r['error'] !== '' ? $r['error'] : 'Jawaban arsitek kosong.';
        return '';
    }
    return trim((string)$r['text']);
}

/**
 * Simpan satu lampiran unggahan ke sebuah tugas.
 * @return array|null baris tersimpan, atau null bila ditolak ($err diisi)
 */
function ai_attachment_save(int $taskId, array $file, ?string &$err = null): ?array
{
    $err = '';
    $name = (string)($file['name'] ?? '');
    if ($name === '') { $err = 'Nama berkas kosong.'; return null; }
    $err0 = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err0 !== UPLOAD_ERR_OK) {
        $err = $err0 === UPLOAD_ERR_INI_SIZE || $err0 === UPLOAD_ERR_FORM_SIZE
            ? 'Ukuran berkas melebihi batas server.' : 'Berkas gagal diunggah (kode ' . $err0 . ').';
        return null;
    }
    $size = (int)($file['size'] ?? 0);
    $max = ai_attach_max_mb() * 1048576;
    if ($size <= 0) { $err = 'Berkas kosong.'; return null; }
    if ($size > $max) { $err = 'Berkas "' . $name . '" terlalu besar (' . num(round($size / 1048576, 1), 1)
        . ' MB). Batas ' . ai_attach_max_mb() . ' MB per berkas.'; return null; }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) { $err = 'Berkas unggahan tidak sah.'; return null; }

    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $aman = preg_replace('/[^A-Za-z0-9._\-]+/', '_', (string)pathinfo($name, PATHINFO_FILENAME));
    $aman = substr($aman !== '' ? $aman : 'berkas', 0, 60);
    $simpan = $aman . '-' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');
    $dir = ai_attach_dir($taskId);
    $tujuan = $dir . '/' . $simpan;
    if (!@move_uploaded_file($tmp, $tujuan)) {
        $err = 'Gagal menyimpan berkas unggahan.';
        return null;
    }
    @chmod($tujuan, 0640);
    $catatan = '';
    $baca = ai_attach_extract($tujuan, $name, $catatan);
    $teks = trim((string)$baca['text']);
    q('INSERT INTO ai_task_files (task_id, name, ext, mime, size, path, chars, sent, note)
       VALUES (?,?,?,?,?,?,?,?,?)',
        [$taskId, $name, $ext, ai_mime_for($ext), $size, $tujuan, strlen($teks), 0, $catatan]);
    $id = (int)db()->lastInsertId();
    audit('Unggah Lampiran AI', 'AI Developer', $taskId, null,
        ['berkas' => $name, 'ukuran' => $size, 'jenis' => ai_attach_kind($ext)],
        'Lampiran diunggah untuk permintaan AI: ' . $name);
    return one('SELECT * FROM ai_task_files WHERE id = ?', [$id]);
}

/** Daftar lampiran sebuah tugas. */
function ai_attachment_list(int $taskId): array
{
    try {
        return all('SELECT * FROM ai_task_files WHERE task_id = ? ORDER BY id', [$taskId]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Hapus satu lampiran (berkas + barisnya). */
function ai_attachment_delete(int $id): bool
{
    $r = one('SELECT * FROM ai_task_files WHERE id = ?', [$id]);
    if (!$r) return false;
    $path = (string)$r['path'];
    if ($path !== '' && is_file($path)) @unlink($path);
    q('DELETE FROM ai_task_files WHERE id = ?', [$id]);
    return true;
}

/** Semua lampiran sebuah tugas dihapus (dipakai saat riwayat tugas dihapus). */
function ai_attachment_delete_task(int $taskId): void
{
    foreach (ai_attachment_list($taskId) as $r) ai_attachment_delete((int)$r['id']);
    ai_rmdir(ai_attach_dir($taskId));
}

/** Gabungan teks lampiran untuk dimasukkan ke prompt (dipotong bila terlalu panjang). */
function ai_attachment_text(int $taskId, int $maxChars = 60000): string
{
    $out = [];
    $sisa = $maxChars;
    foreach (ai_attachment_list($taskId) as $r) {
        if ($sisa <= 0) break;
        $path = (string)$r['path'];
        if (!is_file($path)) continue;
        $isi = '';
        try {
            $catatan = '';
            $baca = ai_attach_extract($path, (string)$r['name'], $catatan);
            $isi = trim((string)$baca['text']);
        } catch (Throwable $e) { $isi = ''; }
        if ($isi === '') continue;
        if (strlen($isi) > $sisa) $isi = substr($isi, 0, $sisa) . "\n… (dipotong)";
        $sisa -= strlen($isi);
        $out[] = '===== LAMPIRAN: ' . $r['name'] . ' =====' . "\n" . $isi . "\n===== AKHIR " . $r['name'] . ' =====';
    }
    return implode("\n\n", $out);
}

/**
 * Lampiran yang dikirim LANGSUNG ke model (gambar & PDF) — dipakai Gemini
 * (multimodal). Batas total supaya permintaan tidak terlalu besar.
 */
function ai_attachment_inline(int $taskId, int $maxBytes = 12 * 1048576): array
{
    $out = [];
    $total = 0;
    foreach (ai_attachment_list($taskId) as $r) {
        $ext = (string)$r['ext'];
        $kind = ai_attach_kind($ext);
        if (!in_array($kind, ['image', 'pdf'], true)) continue;
        $path = (string)$r['path'];
        if (!is_file($path)) continue;
        $size = (int)filesize($path);
        if ($size <= 0 || $total + $size > $maxBytes) continue;
        $data = @file_get_contents($path);
        if ($data === false) continue;
        $total += $size;
        $out[] = ['mime' => ai_mime_for($ext), 'data' => $data, 'name' => (string)$r['name']];
    }
    return $out;
}

/** Tandai lampiran sudah ikut dikirim ke AI (untuk keterangan jujur di halaman). */
function ai_attachment_mark_sent(int $taskId, bool $sent): void
{
    try { q('UPDATE ai_task_files SET sent = ? WHERE task_id = ?', [$sent ? 1 : 0, $taskId]); } catch (Throwable $e) {}
}

/**
 * Uji koneksi penyedia AI: kirim pertanyaan sangat pendek.
 *
 * JEBAKAN (ditemukan ronde 44): permintaan 16 token membuat model "berpikir"
 * (Gemini 3) kehabisan tempat sebelum menulis jawaban → dilaporkan "Jawaban AI
 * kosong" dan uji koneksi GAGAL padahal kuncinya sehat. Sekarang: berpikir
 * DIMATIKAN, batas keluaran lebih longgar, dan dicoba ulang lewat jalur tahan gagal.
 */
function ai_provider_test(?string &$err = null): bool
{
    $pesan = [['role' => 'user', 'text' => 'Balas tepat satu kata: OK']];
    $r = ai_call($pesan, 0.0, 512, null, ['thinking_budget' => 0, 'purpose' => 'uji koneksi']);
    if (!$r['ok']) {
        $r = ai_call_resilient($pesan, 0.0, 1024, ['purpose' => 'uji koneksi']);
    }
    if ($r['ok']) {
        set_setting('ai_last_test_usage', json_encode($r['usage'] ?? [], JSON_UNESCAPED_UNICODE));
        return true;
    }
    $err = $r['error'] !== '' ? $r['error'] : 'Tidak ada jawaban.';
    return false;
}

/**
 * GET JSON sederhana (dipakai untuk membaca DAFTAR MODEL dari penyedia).
 * Ditulis terpisah dari ai_http_post supaya kegagalan GET tidak mengubah
 * perilaku pemanggilan utama.
 */
function ai_http_get(string $url, array $headers, int $timeout = 30): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $out = curl_exec($ch);
        $err = (string)curl_error($ch);
        curl_close($ch);
        if ($out === false) return ['ok' => false, 'body' => '', 'error' => 'Gagal menghubungi penyedia: ' . $err];
        return ['ok' => true, 'body' => (string)$out, 'error' => ''];
    }
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => $timeout, 'ignore_errors' => true]]);
    $out = @file_get_contents($url, false, $ctx);
    if ($out === false) return ['ok' => false, 'body' => '', 'error' => 'Gagal menghubungi penyedia (jaringan).'];
    return ['ok' => true, 'body' => (string)$out, 'error' => ''];
}

/**
 * Daftar model yang BENAR-BENAR tersedia untuk kunci API yang tersimpan.
 *
 * Permintaan pemilik (ronde 43): "saya sudah upgrade gemininya, kenapa di AI
 * Settings tidak otomatis berubah?" — model TIDAK berubah sendiri karena model
 * adalah setelan yang dipilih manual. Supaya pemilik dapat melihat model apa
 * saja yang tersedia untuk kuncinya (tanpa menebak), halaman AI Settings punya
 * tombol "Muat Daftar Model" yang memanggil fungsi ini.
 *
 * @return array<int,string> daftar nama model (kosong bila gagal — $err diisi)
 */
function ai_provider_models(?string &$err = null): array
{
    $s = ai_settings();
    $err = '';
    if ($s['provider'] === 'mock') return ['mock-1'];
    if (trim($s['api_key']) === '') { $err = 'Kunci API belum diisi.'; return []; }
    if ($s['provider'] === 'gemini') {
        $res = ai_http_get('https://generativelanguage.googleapis.com/v1beta/models?pageSize=200',
            ['x-goog-api-key: ' . $s['api_key']]);
        if (!$res['ok']) { $err = $res['error']; return []; }
        $j = json_decode($res['body'], true);
        if (isset($j['error'])) { $err = 'Penyedia menolak: ' . (string)($j['error']['message'] ?? '-'); return []; }
        $out = [];
        foreach ((array)($j['models'] ?? []) as $m) {
            if (!in_array('generateContent', (array)($m['supportedGenerationMethods'] ?? []), true)) continue;
            $nama = (string)str_replace('models/', '', (string)($m['name'] ?? ''));
            if ($nama !== '') $out[] = $nama;
        }
        sort($out);
        if (!$out) $err = 'Tidak ada model yang dapat dipakai (generateContent) pada kunci ini.';
        return $out;
    }
    /* OpenAI & penyedia bergaya sama */
    $modelUrl = ($s['api_base'] !== '' ? $s['api_base'] : 'https://api.openai.com/v1') . '/models';
    $res = ai_http_get($modelUrl, ['Authorization: Bearer ' . $s['api_key']]);
    if (!$res['ok']) { $err = $res['error']; return []; }
    $j = json_decode($res['body'], true);
    if (isset($j['error'])) { $err = 'Penyedia menolak: ' . (string)($j['error']['message'] ?? '-'); return []; }
    $out = [];
    foreach ((array)($j['data'] ?? []) as $m) {
        $nama = (string)($m['id'] ?? '');
        if ($nama !== '') $out[] = $nama;
    }
    sort($out);
    if (!$out) $err = 'Daftar model kosong.';
    return $out;
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
 * Baca berkas secara BERTAHAP bila berkasnya besar (ronde 50).
 *
 * Permintaan pemilik (V2.3 bagian 2 & 6): "Jika file besar, jangan langsung
 * menyatakan file dilewati lalu menyimpulkan tidak ada perubahan. Gunakan
 * pembacaan bertahap/chunk/retrieval"; dan "Jika sebagian file belum berhasil
 * dibaca, catat file dan alasannya, lalu jangan menyatakan audit lengkap".
 *
 * Cara kerjanya: kepala berkas + potongan-potongan di sekitar baris yang paling
 * banyak menyebut kata kunci permintaan (retrieval sederhana). Hasilnya diberi
 * penanda baris supaya AI tahu bagian mana yang sedang dilihat, dan `lengkap`
 * menyatakan apakah SELURUH berkas benar-benar terbaca.
 *
 * @return array{ok:bool,text:string,error:string,size:int,lengkap:bool,bagian:int,alasan:string}
 */
function ai_read_smart(string $rel, string $permintaan = '', ?int $anggaranKb = null): array
{
    $b = ai_read($rel);
    if (!$b['ok']) {
        return ['ok' => false, 'text' => '', 'error' => $b['error'], 'size' => 0,
                'lengkap' => false, 'bagian' => 0, 'alasan' => $b['error']];
    }
    $anggaran = ($anggaranKb ?? ai_settings()['max_file_kb']) * 1024;
    if (empty($b['truncated'])) {
        return ['ok' => true, 'text' => (string)$b['text'], 'error' => '', 'size' => (int)$b['size'],
                'lengkap' => true, 'bagian' => 1, 'alasan' => ''];
    }
    $abs = ai_path($rel);
    $isi = $abs !== null ? (string)@file_get_contents($abs) : '';
    if ($isi === '') {
        return ['ok' => true, 'text' => (string)$b['text'], 'error' => '', 'size' => (int)$b['size'],
                'lengkap' => false, 'bagian' => 1, 'alasan' => 'berkas hanya terbaca sebagian'];
    }
    $baris = preg_split('/\R/', $isi) ?: [];
    $total = count($baris);
    /* Kata kunci: dari permintaan (minimal 4 huruf, kata umum dibuang). */
    $kata = [];
    foreach (preg_split('/[^A-Za-z0-9_]+/', strtolower($permintaan)) ?: [] as $w) {
        if (strlen($w) >= 4 && !in_array($w, ['untuk', 'dengan', 'yang', 'pada', 'dari', 'tidak', 'agar',
            'halaman', 'berkas', 'file', 'kolom', 'tabel', 'semua', 'juga', 'bisa', 'tolong'], true)) {
            $kata[$w] = true;
        }
    }
    $kata = array_keys($kata);
    /* Skor tiap baris terhadap kata kunci. */
    $skor = [];
    foreach ($baris as $i => $l) {
        $s = 0;
        $ll = strtolower($l);
        foreach ($kata as $w) if (strpos($ll, $w) !== false) $s++;
        if ($s > 0) $skor[$i] = $s;
    }
    arsort($skor);
    $headBaris = min(40, max(10, (int)floor($total * 0.15)));
    $pakai = [];
    for ($i = 0; $i < $headBaris && $i < $total; $i++) $pakai[$i] = true;
    /* Potongan di sekitar baris paling relevan (masing-masing ±12 baris). */
    foreach (array_keys($skor) as $i) {
        if (count($pakai) >= 400) break;
        for ($j = max(0, $i - 12); $j <= min($total - 1, $i + 12); $j++) $pakai[$j] = true;
    }
    ksort($pakai);
    $out = [];
    $prev = -2;
    $ukuran = 0;
    $dibaca = 0;
    foreach (array_keys($pakai) as $i) {
        $barisTeks = $baris[$i];
        if ($prev >= 0 && $i > $prev + 1) {
            $out[] = '… (baris ' . ($prev + 2) . '–' . $i . ' tidak dikirim karena anggaran konteks) …';
        }
        $out[] = '[' . ($i + 1) . '] ' . $barisTeks;
        $ukuran += strlen($barisTeks) + 12;
        $dibaca++;
        $prev = $i;
        if ($ukuran > $anggaran) break;
    }
    $lengkap = ($dibaca >= $total);
    $teks = "===== BERKAS: " . $rel . " (dibaca BERTAHAP; " . $dibaca . " dari " . $total . " baris) =====\n"
        . implode("\n", $out) . "\n===== AKHIR " . $rel . " =====";
    return ['ok' => true, 'text' => $teks, 'error' => '', 'size' => (int)$b['size'],
            'lengkap' => $lengkap, 'bagian' => $dibaca,
            'alasan' => $lengkap ? '' : 'berkas besar: hanya ' . $dibaca . ' dari ' . $total . ' baris dikirim'];
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
function ai_patch_parse(string $text, array $berkasBolehBaru = []): array
{
    $gagal = ['ok' => false, 'ops' => [], 'error' => '', 'plan' => '', 'files' => []];
    $json = ai_extract_json($text);
    if ($json === null) {
        /* Bedakan "tidak ada JSON sama sekali" dengan "JSON-nya terpotong":
           yang kedua biasanya berarti jawaban AI kehabisan batas panjang. */
        $gagal['error'] = strpos($text, '{') !== false
            ? 'Jawaban AI memuat JSON yang TIDAK LENGKAP (terpotong di tengah) sehingga tidak dapat dibaca.'
            : 'Jawaban AI tidak memuat JSON yang sah.';
        return $gagal;
    }
    /* AI boleh MEMINTA membaca berkas lain lebih dulu ({"read":[...]}) — ini yang
       membuatnya dapat menelusuri sendiri bagian yang belum dikirim, bukan menebak. */
    if (is_array($json) && !empty($json['read']) && is_array($json['read'])) {
        $minta = [];
        foreach ($json['read'] as $f) {
            $f = trim(str_replace('\\', '/', (string)$f));
            if ($f !== '' && ai_path($f) !== null) $minta[] = $f;
        }
        if ($minta) {
            return ['ok' => true, 'ops' => [], 'error' => '', 'noop' => false,
                    'minta_baca' => array_values(array_unique($minta)),
                    'plan' => trim((string)($json['plan'] ?? '')), 'files' => [],
                    'suggestions' => []];
        }
    }
    if (!is_array($json) || !isset($json['ops']) || !is_array($json['ops'])) {
        $gagal['error'] = 'JSON tidak memuat daftar "ops".'; return $gagal;
    }
    $ops = [];
    foreach ($json['ops'] as $i => $o) {
        if (!is_array($o)) { $gagal['error'] = 'Operasi #' . ($i + 1) . ' bukan objek.'; return $gagal; }
        $file = (string)($o['file'] ?? '');
        $action = (string)($o['action'] ?? 'replace');
        if ($file === '') { $gagal['error'] = 'Operasi #' . ($i + 1) . ' tidak menyebut berkas.'; return $gagal; }
        /* $berkasBolehBaru = berkas yang sudah ada di rantai patch (mis. dibuat oleh
           patch pertama) tetapi BELUM ada di aplikasi asli — dipakai tahap perbaikan
           otomatis agar tidak salah ditolak. */
        if (ai_path($file) === null && $action !== 'create' && !in_array($file, $berkasBolehBaru, true)) {
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
    /* SARAN untuk pemilik (ronde 45) — ditampilkan pada pesan laporan akhir. */
    $saran = [];
    foreach ((array)($json['suggestions'] ?? $json['saran'] ?? []) as $x) {
        $x = trim((string)(is_array($x) ? json_encode($x, JSON_UNESCAPED_UNICODE) : $x));
        if ($x !== '') $saran[] = $x;
    }
    if (!$ops) {
        return ['ok' => true, 'ops' => [], 'error' => '', 'noop' => true,
                'plan' => trim((string)($json['plan'] ?? '')),
                'note' => trim((string)($json['note'] ?? $json['reason'] ?? '')),
                'suggestions' => $saran,
                'files' => []];
    }
    return ['ok' => true, 'ops' => $ops, 'error' => '', 'noop' => false,
            'plan' => trim((string)($json['plan'] ?? '')),
            'suggestions' => $saran,
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
    $hasil = ['staging' => 0, 'snapshot' => 0, 'log' => 0, 'uploads' => 0, 'preview' => 0, 'mb' => 0.0];
    foreach (['staging', 'snapshot'] as $jenis) {
        $base = ai_tmp_dir() . '/' . $jenis;
        if (!is_dir($base)) continue;
        $daftar = [];
        foreach (scandir($base) ?: [] as $d) {
            if ($d === '.' || $d === '..') continue;
            $path = $base . '/' . $d;
            if (!is_dir($path)) continue;
            /* Folder pratinjau (`preview-task-*`) dibersihkan dengan aturan sendiri
               (berdasarkan umur) supaya tidak mengusik salinan uji yang baru dipakai
               dan sebaliknya. */
            if ($jenis === 'staging' && strpos($d, 'preview-task-') === 0) {
                $idT = (int)substr($d, 13);
                $stT = $idT > 0 ? (string)scalar('SELECT status FROM ai_tasks WHERE id = ?', [$idT], '') : '';
                /* Salinan pratinjau dibuang bila: tugasnya sudah tidak ada, sudah
                   selesai/ditolak/dibatalkan, atau sudah lebih dari 12 jam. */
                $bolehBuang = ($idT <= 0 || $stT === '')
                    || in_array($stT, ['applied', 'rejected', 'rolledback'], true)
                    || (time() - (int)@filemtime($path)) > 12 * 3600;
                if ($bolehBuang) {
                    ai_rmdir($path);
                    $hasil['preview'] = (int)($hasil['preview'] ?? 0) + 1;
                }
                continue;
            }
            $daftar[$path] = (int)@filemtime($path);
        }
        arsort($daftar);
        $i = 0;
        foreach ($daftar as $path => $t) {
            /* SALINAN TUGAS YANG SUDAH TIDAK ADA dibuang LEBIH DULU, tanpa ikut
               hitungan "simpan N terbaru". Alasan: bila tiga folder terbaru kebetulan
               semuanya milik tugas yang sudah dihapus, aturan "simpan 3" akan
               mempertahankannya SELAMANYA (pemeriksaan tugas tidak pernah tercapai
               karena baris `if ($i <= $simpan) continue` di bawah). Kejadian nyata:
               156 MB salinan uji tertinggal di `naveena_ai/staging` padahal tugasnya
               sudah tidak ada di basis data. */
            if ($jenis === 'staging' && strpos(basename($path), 'task-') === 0) {
                $idTugas = (int)substr(basename($path), 5);
                if ($idTugas > 0
                    && (string)scalar('SELECT status FROM ai_tasks WHERE id = ?', [$idTugas], '') === '') {
                    ai_rmdir($path);
                    $hasil[$jenis]++;
                    continue;
                }
            }
            $i++;
            if ($i <= $simpan) continue;
            /* JANGAN hapus salinan yang SEDANG DIPAKAI (ronde 48).
               Permintaan pemilik: "jalankan uji staging gagal terus padahal pratinjau
               berhasil". Penyebabnya ditemukan di log: suite kehilangan berkasnya di
               tengah jalan ("naveena_tmp/seed-*.log: No such file or directory")
               karena fungsi pembersih ini menghapus folder salinan uji yang MASIH
               dijalankan — terpicu setiap kali halaman AI Developer dibuka/pratinjau
               dilihat. Sekarang salinan dilindungi bila:
                 • tugasnya masih berstatus analyzing/testing (sedang dikerjakan), atau
                 • foldernya masih SANGAT BARU (< 60 menit) — bisa jadi sedang dipakai.
               Selain itu, hanya `$simpan` folder TERTUA di luar perlindungan yang
               dibuang, sehingga jumlahnya tetap terkendali. */
            if ($jenis === 'staging') {
                $nama = basename($path);
                if (strpos($nama, 'task-') === 0) {
                    $idTugas = (int)substr($nama, 5);
                    if ($idTugas > 0) {
                        $st = (string)scalar('SELECT status FROM ai_tasks WHERE id = ?', [$idTugas], '');
                        /* Tugas sudah tidak ada di basis data → salinannya tidak berguna. */
                        if ($st === '') { ai_rmdir($path); $hasil[$jenis]++; continue; }
                        if (in_array($st, ['analyzing', 'testing', 'draft'], true)) continue;
                    }
                }
                if ((time() - $t) < 3600) continue;   // masih baru → jangan sentuh
            }
            ai_rmdir($path);
            $hasil[$jenis]++;
        }
    }
    /* Lampiran tugas yang riwayatnya sudah dihapus ikut dibuang supaya tidak
       menumpuk di disk (satu tugas dapat membawa beberapa berkas Excel/PDF). */
    $upBase = ai_tmp_dir() . '/uploads';
    if (is_dir($upBase)) {
        foreach (scandir($upBase) ?: [] as $d) {
            if (strpos($d, 'task-') !== 0) continue;
            $idTugas = (int)substr($d, 5);
            $masihAda = $idTugas > 0 ? (int)scalar('SELECT COUNT(*) FROM ai_tasks WHERE id = ?', [$idTugas], 0) : 0;
            if ($masihAda === 0) {
                ai_rmdir($upBase . '/' . $d);
                $hasil['uploads'] = (int)($hasil['uploads'] ?? 0) + 1;
            }
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

/**
 * Buang sisa BERKAS UJI di dalam folder staging setelah suite selesai.
 *
 * Suite uji menulis artefaknya sendiri (`naveena_tmp`, sesi, unggahan, backup)
 * di dalam staging; berkas itu tidak dipakai lagi sesudahnya tetapi ukurannya
 * besar (pernah terukur 89 MB hanya dari SATU staging) dan menumpuk bersama
 * salinan aplikasi. Folder staging-nya sendiri tetap disimpan (tiga terbaru)
 * supaya pratinjau/uji masih bisa diperiksa.
 */
function ai_staging_cleanup_runs(int $taskId): int
{
    $dir = ai_staging_dir($taskId);
    if (!is_dir($dir)) return 0;
    /* JANGAN bersihkan selagi ada pekerja yang MEMAKAI salinan ini (ronde 49):
       menghapus `naveena_tmp` di tengah suite membuat skrip uji kehilangan berkas
       keluarnya dan berhenti ("No such file or directory") — itulah salah satu
       sebab uji staging gagal terus. */
    if (ai_job_running($taskId, 'test') || ai_job_running($taskId, 'plan')) return 0;
    $hapus = 0;
    foreach (['naveena_tmp', 'naveena_sessions', 'naveena_uploads', 'naveena_backups', 'naveena_imports'] as $sub) {
        if (is_dir($dir . '/' . $sub)) { ai_rmdir($dir . '/' . $sub); $hapus++; }
    }
    return $hapus;
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
 * KETERANGAN SETIAP SUITE UJI (dipakai kartu panduan di AI Developer).
 *
 * Permintaan pemilik (ronde 43): "di kartu permintaan ada banyak pilihan tapi saya
 * tidak tahu pilihan itu untuk apa — buatkan tabel keterangannya". Peta di bawah
 * ditulis manual supaya keterangannya benar-benar menjelaskan APA yang diperiksa
 * dengan bahasa pemilik (bukan sekadar nama berkas).
 *
 * @return array<string,array{lama:string,ringkas:bool,ket:string}>
 */
function ai_suite_extra(): array
{
    $D = [
        /* 'nama suite' => ['berat'|'sedang'|'ringan', 'keterangan'] */
        'smoke-fungsional' => ['berat', 'Alur inti aplikasi dari awal sampai akhir: masuk, data pasien, reservasi, transaksi (order), struk, sampai laporan. Paling luas dan paling lama (pakai bila perubahan menyentuh banyak menu).'],
        'struk-whatsapp' => ['berat', 'Struk: tampilan cetak, PDF struk, dan pengiriman pesan WhatsApp (termasuk status jujur "Disiapkan/terkirim").'],
        'import-data' => ['sedang', 'Impor Excel/CSV: pasien, treatment, stok, dan rekam medis (termasuk kolom HPP & penanganan NIK panjang).'],
        'fitur-baru' => ['berat', 'Fitur lama sebagai penjaga regresi: label SOAP, akses rekam medis semua level, ekspor Excel, hapus permanen (Super Admin), dan logo klinik.'],
        'ronde2-zona-tema-email' => ['sedang', 'Zona waktu WIB, tema warna (80 palet), status rekam medis, integrasi laporan, dan email.'],
        'menu-sweep' => ['berat', 'Membuka SEMUA menu dengan 3 level pengguna (Super Admin, Admin/Dokter, Kasir) memastikan tidak ada halaman rusak & hak akses sesuai.'],
        'ronde3-foto-grafik' => ['berat', 'Foto pasien/dokter/terapis + rekam medis (kompresi), grafik di layar/Excel/PDF, email 2 lampiran, dan tombol Hapus Semua Data.'],
        'pemeliharaan' => ['sedang', 'Mode pemeliharaan: semua tindakan tulis/impor/ekspor ditolak untuk level selain Super Admin dan data tidak berubah.'],
        'bahan-treatment' => ['sedang', 'Bahan treatment pada transaksi: harga 0, tidak muncul di struk, stok berkurang, void mengembalikan stok, pemakaian pecahan (0,5 liter).'],
        'level-direktur' => ['sedang', 'Level Direktur/Owner: cakupan semua cabang, blokir backup/pemeliharaan/cabang, dan batas kelola akun Super Admin.'],
        'nomor-dokumen' => ['ringan', 'Penomoran dokumen (pasien, member, rekam medis, invoice, reservasi) dan serinya yang tidak reset saat hari berganti.'],
        'email-cakupan-member' => ['sedang', 'Kolom email pasien, kirim struk lewat email (manual/otomatis/tautan publik), dan cakupan diskon kartu member per transaksi.'],
        'ekspor-excel-foto' => ['berat', 'Tombol ekspor tiap menu, Excel .xlsx asli, dan lembar foto yang ikut terbawa (dengan pembatasan cabang).'],
        'kartu-member' => ['berat', 'Kartu member: diskon otomatis per level, kartu otomatis, kartu digital + PDF (termasuk PDF lengkap 2 halaman A4), sinkronisasi ke struk/laporan/dashboard.'],
        'sintaks-js' => ['ringan', 'PEMERIKSAAN PALING RINGAN & CEPAT: memastikan tidak ada kesalahan sintaks pada seluruh blok JavaScript, atribut onclick, dan halaman — paling cocok untuk perubahan tampilan/kecil.'],
        'tautan-tombol' => ['ringan', 'Memeriksa semua tautan (href/action) pada halaman menunjuk berkas yang benar-benar ada dan jenis ekspor yang dikenal.'],
        'warna-grafik' => ['ringan', 'Palet warna grafik: jarak warna antar seri, warna seri ke-11 tidak mengulang, dan kartu pengatur warna di Pengaturan.'],
        'kartu-layout' => ['sedang', 'Tata letak PDF kartu member dibandingkan pratinjau di layar (logo, garis, nama, level, rincian) dan teks tidak keluar kartu.'],
        'bersihkan-berkas' => ['ringan', 'Pembersih berkas gambar tidak terpakai di Developer Settings (dan memastikan foto pasien/staf tidak tersentuh).'],
        'periode-member' => ['sedang', 'Periode akumulasi kartu member (1/3/5 tahun/tanpa periode) dan penurunan level saat periode habis.'],
        'pengaturan-kartu' => ['sedang', 'Kerapian kartu Pengaturan + pengaman bagian sistem (Payment Gateway & jalur email hanya Super Admin).'],
        'keuangan' => ['berat', 'Menu Keuangan: hak akses, HPP, omzet, laba bersih, biaya operasional per cakupan, prorata periode, dan ekspor keuangan.'],
        'ronde34-cabang-duplikat' => ['sedang', 'Keterangan cabang pada reservasi, ANTI-DUPLIKAT data (token sekali-pakai), cabang transaksi mengikuti pasien, dan variabel {klinik} di WhatsApp.'],
        'paket' => ['berat', 'Paket treatment/produk: penyusun paket, pembatasan cabang, penjualan paket oleh kasir, stok komponen, dan angkanya di Keuangan/Laporan.'],
        'migrasi-skema' => ['ringan', 'KEAMANAN MIGRASI: 7 permintaan bersamaan saat versi skema naik — cap waktu & data tidak boleh berubah.'],
        'responsif-hp-tablet' => ['berat', 'Tampilan HP/tablet/PC dari 320px sampai 1440px: tidak meluber, tabel tetap tabel & bisa digeser, huruf tabel tetap terbaca.'],
        'ui-umum' => ['sedang', 'Uji antarmuka umum (menu, kartu, modal, notifikasi) dengan peramban sungguhan.'],
        'ui-icd' => ['sedang', 'Halaman kamus ICD-10/9-CM: pencarian, saran otomatis, dan pemakaiannya di rekam medis.'],
        'ui-import' => ['sedang', 'Tampilan alur impor: unggah, pemetaan kolom, pratinjau, dan laporan per baris.'],
        'ui-struk' => ['sedang', 'Tampilan halaman struk & modal kirim WhatsApp.'],
        'ui-laporan' => ['sedang', 'Tampilan menu Laporan: kartu KPI, grafik, dan tombol unduh.'],
        'ui-konfirmasi' => ['sedang', 'Panel konfirmasi tindakan berisiko (konfirmasi 2 tahap).'],
        'ui-rekam-medis' => ['sedang', 'Form rekam medis: keterangan foto, mode Lihat baca-saja, posisi tombol simpan, dan penanda "belum disimpan".'],
        'ui-pemeliharaan' => ['sedang', 'Tampilan halaman pemeliharaan, spanduk, tombol yang dimatikan, dan halaman masuk.'],
        'ui-bahan-treatment' => ['sedang', 'Alur pemilihan bahan treatment di Order Baru (kartu terpisah, satuan, angka koma).'],
        'ui-direktur' => ['sedang', 'Tampilan untuk level Direktur: sidebar, panel Pengaturan, halaman cabang baca-saja, dan kerapiannya.'],
        'ui-tema' => ['sedang', 'Pemilih tema: 80 tema dalam 20 keluarga warna + pemilih keluarga di atas daftar.'],
        'nama-rekam-medis' => ['ringan', 'Nama menu "Rekam Medis Elektronik" pada sidebar/judul/ekspor, sekaligus memastikan tabel, nomor RM, dan izin tidak berubah.'],
        'dokumen-fungsi' => ['sedang', 'Dokumen Ringkasan Fungsi (PDF) di Developer Settings: bab lengkap, sinkron dengan database, tanpa rahasia.'],
        'ronde37-pengaturan-cabang' => ['sedang', 'Pengaturan Umum di Developer Settings, akun bawaan per peran di halaman masuk, dan filter cabang menyeluruh.'],
        'ronde38-keamanan-login' => ['berat', 'Keamanan login: "Ingat saya", batas tidak aktif, verifikasi 2 langkah (QR + kode), kode pemulihan, dan lupa kata sandi.'],
        'ronde39-tampilan-email' => ['sedang', 'Skala tampilan (80%), jarak kartu, modal tahan klik luar, dan pengaman kirim email ulang.'],
        'ronde40-geser-lupasandi' => ['sedang', 'Geser tabel dengan tahan-klik dan halaman Lupa Kata Sandi yang jujur.'],
        'ai-developer' => ['berat', 'AI Developer itu sendiri (menu, mesin patch, uji staging, persetujuan, pembatalan). Paling tepat dipakai bila Anda mengubah menu AI.'],
        'ronde42-ai-workspace-2fa' => ['sedang', 'Kelompok sidebar AI Workspace, tinggi kolom permintaan AI, dan penyiapan 2FA per level.'],
        'ronde43-ai-tahan-gagal' => ['sedang', 'AI Developer itu sendiri: perbaikan otomatis saat jawaban AI rusak/terpotong, panduan pilihan & tombol, dan daftar model yang tersedia.'],
        'ui-konfirmasi-kartu' => ['sedang', 'Alur konfirmasi aktivasi kartu member di Order Baru.'],
        'filter-rekam-medis' => ['sedang', 'Filter menu Rekam Medis (dokter/terapis/tanggal) benar-benar menyaring, termasuk saat tombol Filter ditekan.'],
        'filter-semua-menu' => ['sedang', 'Filter di SEMUA menu (termasuk perubahan filter kedua kali) dan tata letak grafik tren.'],
        'reservasi-fitur' => ['sedang', 'Reservasi: filter, multi-treatment, pra-isi rekam medis, dan tombol ke transaksi.'],
        'pembayaran-transaksi' => ['berat', 'Pembayaran: wajib bayar dulu sebelum transaksi tersimpan, kode unik, dan ubah metode.'],
        'pembayaran-otomatis' => ['berat', 'Jalur pembayaran otomatis (gateway): tagihan, webhook, tombol Cek Status, dan penolakan jujur bila kredensial kosong.'],
        'backup-database' => ['sedang', 'Backup database: membuat/mengunduh/restore, kompresi, batas penyimpanan, dan pemangkasan.'],
        'hapus-data-demo' => ['berat', 'Tombol "Isi Data Demo" dan "Hapus Semua Data" beserta integrasinya.'],
        'nama-klinik' => ['berat', 'Ubah Nama Klinik: hak akses, verifikasi 2 tahap, propagasi ke teks/template/cabang, dan tampilannya di struk/dokumen.'],
        'retensi-data' => ['berat', 'Retensi data: hapus otomatis & manual per periode/jenis data, pasien tidak aktif, dan aktivitas akun.'],
        'status-pasien' => ['sedang', 'Status pasien otomatis (Baru → Lama setelah 3 hari kunjungan berbeda).'],
    ];
    return $D;
}

/**
 * Daftar suite uji + keterangannya untuk kartu panduan AI Developer.
 *
 * Keterangan diambil dari `ai_suite_extra()`; bila ada suite baru yang belum
 * didaftarkan di sana, keterangannya dibaca dari komentar pembuka berkas ujinya
 * (sehingga suite baru tetap muncul dengan penjelasan yang masuk akal).
 *
 * @return array<int,array{nama:string,berat:string,ket:string,berkas:string}>
 */
function ai_suite_info(): array
{
    $extra = ai_suite_extra();
    $berkas = [];
    $f = ai_root() . '/naveena_dev/test/run_all.sh';
    if (is_readable($f)) {
        $txt = (string)file_get_contents($f);
        if (preg_match_all('/^\s*run_(?:sh|js|js_gd|sh_gd)\s+"([^"]+)"\s+"\$HERE\/([^"]+)"/m', $txt, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) $berkas[$row[1]] = 'naveena_dev/test/' . $row[2];
        }
    }
    $out = [];
    foreach (ai_suite_list() as $nama) {
        $ket = $extra[$nama][1] ?? '';
        $berat = $extra[$nama][0] ?? 'sedang';
        if ($ket === '') $ket = ai_suite_header_text($berkas[$nama] ?? '');
        if ($ket === '') $ket = 'Suite uji aplikasi (lihat berkas ujinya untuk rincian).';
        $out[] = ['nama' => $nama, 'berat' => $berat, 'ket' => $ket, 'berkas' => $berkas[$nama] ?? ''];
    }
    return $out;
}

/** Ambil satu-dua kalimat pertama dari komentar pembuka berkas uji (cadangan keterangan). */
function ai_suite_header_text(string $rel): string
{
    if ($rel === '') return '';
    $abs = ai_root() . '/' . $rel;
    if (!is_readable($abs)) return '';
    $kepala = (string)file_get_contents($abs, false, null, 0, 1400);
    $baris = preg_split('/\R/', $kepala);
    if (!is_array($baris)) $baris = [$kepala];
    $teks = [];
    foreach (array_slice($baris, 0, 14) as $b) {
        $b = trim((string)$b);
        if ($b === '' || strpos($b, '#!') === 0) continue;
        /* Bersihkan penanda komentar. Catatan: pola memakai pembatas `~` (BUKAN
           `#`) karena `#` termasuk penanda komentar bash — memakai `#` sebagai
           pembatas membuat polanya tidak sah, preg_replace mengembalikan NULL,
           dan trim(NULL) melempar TypeError yang mematikan halaman AI Developer. */
        $bersih = preg_replace('~^(/\*\*?|\*/|\*|#|//)\s*~', '', $b);
        $b = trim($bersih === null ? $b : $bersih);
        if ($b === '' || stripos($b, 'use strict') !== false) continue;
        if (preg_match('/^(require|const |let |var |set -u|declare|import)/', $b)) break;
        $teks[] = $b;
        if (count($teks) >= 3) break;
    }
    $gabung = trim(implode(' ', $teks));
    if ($gabung === '') return '';
    /* Ambil maksimal 2 kalimat pertama supaya tidak kepanjangan. */
    if (preg_match('/^(.{20,240}?[.?!])(\s|$)/s', $gabung, $kk)) $gabung = $kk[1];
    return ai_strlen($gabung) > 260 ? substr($gabung, 0, 257) . '…' : $gabung;
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
    /* Server sisa dari uji staging sebelumnya dibersihkan lebih dulu, lalu PORT
       dipilih yang benar-benar bebas.
       BUG yang diperbaiki (ronde 46): port staging ditulis TETAP (offset 3000)
       sehingga bila ada server sisa dari uji sebelumnya, server baru GAGAL start
       ("Address already in use") → suite selalu gagal → perubahan yang sebenarnya
       benar tidak pernah bisa diterapkan. */
    $dibersihkan = ai_kill_stale_staging_servers();
    if ($dibersihkan > 0) ai_step($taskId, 'info', 'Menutup ' . $dibersihkan . ' server uji sisa (agar port bebas)');
    $offset = ai_free_staging_offset($taskId, $suite);
    ai_step($taskId, 'work', 'Port uji staging dipilih bebas (offset ' . $offset . ')');
    /* LINGKUNGAN UJI (perbaikan ronde 52b — akar "suite berhenti" di produksi):
       suite uji memakai Playwright (`require('playwright')` + Chromium dari cache
       bersama). Modul & browser itu TIDAK ada di lingkungan PHP-FPM, sehingga node
       langsung berhenti tanpa keluaran ("SKRIP BERHENTI", 0 PASS / 0 FAIL) — padahal
       dijalankan dari shell biasa suite yang sama LULUS. Karena itu variabel
       lingkungannya DISET EKSPLISIT di sini (dapat diatur lewat setelan bila lokasinya
       berbeda). TZ=Asia/Jakarta juga penting agar uji tanggal tidak meleset. */
    $envUji = ai_suite_env();
    $cmd = 'cd ' . escapeshellarg($dir . '/naveena_dev/test')
        . ' && ' . $envUji
        . 'NAVEENA_UPLOAD_ROOT=' . escapeshellarg($dir . '/uploads')
        . ' NAVEENA_BACKUP_DIR=' . escapeshellarg($dir . '/backups')
        . ' NV_PORT_OFFSET=' . (int)$offset
        . ' bash run_all.sh ' . escapeshellarg($suite);
    /* PENTING (perbaikan ronde 52) — jalankan SINKRON, bukan di latar belakang.
       Dijalankan sebagai proses latar (`... &`), suite bisa MENGGANTUNG tanpa
       menghasilkan apa pun (terbukti pada produksi: `backup-database` berhenti di
       header suite, 0 PASS / 0 FAIL, lalu dilaporkan sebagai kegagalan lingkungan
       padahal dijalankan langsung suite itu LULUS 46/0). Pekerja AI memang sudah
       berjalan di latar belakang sendiri, jadi menjalankan suite secara sinkron di
       dalamnya tidak menghambat permintaan web sedikit pun. */
    $r = ai_exec($cmd, max(300, (int)setting('ai_suite_timeout', '1500')), '');
    @file_put_contents($log, $r['out']);
    if ($r['code'] !== 0 && trim($r['out']) === '') {
        $err = 'Suite uji tidak menghasilkan keluaran (exit ' . $r['code'] . ').';
    }
    return $log;
}


/**
 * Variabel lingkungan untuk menjalankan suite uji di salinan (ronde 52b).
 *
 * PENTING: PHP-FPM tidak mewarisi NODE_PATH/PLAYWRIGHT_BROWSERS_PATH dari shell
 * operator. Tanpa keduanya, `require('playwright')` gagal dan Chromium tidak ditemukan
 * sehingga node berhenti seketika (gejala: "SKRIP BERHENTI", 0 PASS / 0 FAIL) walau
 * suite yang sama LULUS bila dijalankan dari shell. Nilainya dapat diatur lewat
 * setelan `ai_node_path` / `ai_pw_cache` bila instalasi berbeda.
 */
function ai_suite_env(): string
{
    $nodeMod = (string)setting('ai_node_path', getenv('NODE_PATH') ?: '/var/lib/vibecoderco/shared-node-modules/node_modules');
    $pwCache = (string)setting('ai_pw_cache', getenv('PLAYWRIGHT_BROWSERS_PATH') ?: '/var/lib/vibecoderco/shared-playwright-cache');
    $out = '';
    if ($nodeMod !== '' && (is_dir($nodeMod) || getenv('NODE_PATH'))) {
        $out .= 'NODE_PATH=' . escapeshellarg($nodeMod) . ' ';
    }
    if ($pwCache !== '' && (is_dir($pwCache) || getenv('PLAYWRIGHT_BROWSERS_PATH'))) {
        $out .= 'PLAYWRIGHT_BROWSERS_PATH=' . escapeshellarg($pwCache) . ' ';
    }
    $out .= 'TZ=' . escapeshellarg('Asia/Jakarta') . ' ';
    return $out;
}

/**
 * Tutup SERVER UJI STAGING yang masih hidup dari uji sebelumnya.
 *
 * Server `php -S` milik uji staging bisa tertinggal (mis. pekerja AI berhenti
 * sebelum suite selesai). Server sisa itu memegang port sehingga uji staging
 * BERIKUTNYA gagal start — dan akibatnya perubahan yang benar tidak pernah bisa
 * diterapkan. Fungsi ini hanya menyentuh proses yang cwd-nya berada di dalam folder
 * staging MILIK APLIKASI INI (tidak pernah proses lain).
 */
function ai_kill_stale_staging_servers(): int
{
    $root = realpath(ai_tmp_dir() . '/staging');
    if ($root === false) return 0;
    $n = 0;
    foreach (glob('/proc/[0-9]*') ?: [] as $p) {
        $pid = (int)basename($p);
        if ($pid <= 1) continue;
        $cmd = @file_get_contents($p . '/cmdline');
        if ($cmd === false) continue;
        $cmd = str_replace(chr(0), ' ', $cmd);
        if (strpos($cmd, 'php') === false || strpos($cmd, '-S 127.0.0.1:') === false) continue;
        $cwd = @readlink($p . '/cwd');
        if ($cwd === false || strpos($cwd, $root) !== 0) continue;
        if (function_exists('posix_kill')) @posix_kill($pid, 15);
        else @shell_exec('kill ' . $pid . ' 2>/dev/null');
        $n++;
    }
    if ($n > 0) usleep(400000);   // beri waktu port dilepas
    return $n;
}

/**
 * Pilih OFFSET PORT bebas untuk uji staging.
 *
 * run_all.sh memakai port tetap (gateway 8179, API email 8211, gateway bayar 8399)
 * ditambah satu port per suite (8136–8490). Pemeriksaan dilakukan dengan mencoba
 * MENYAMBUNG ke seluruh rentang itu; koneksi yang ditolak (belum ada server) kembali
 * seketika sehingga pemeriksaan 375 port tetap cepat. Offset dimulai dari slot milik
 * tugas ini (agar dua tugas berbeda tidak memakai port yang sama), lalu naik 40.
 */
function ai_free_staging_offset(int $taskId, string $suite = ''): int
{
    $base = 2000 + (($taskId % 200) * 40);           // 2000 … 9960
    for ($coba = 0; $coba < 40; $coba++) {
        $off = $base + ($coba * 40);
        if ($off > 30000) $off = 2000 + ($coba * 40); // jaga tetap di rentang aman
        if (ai_ports_free($off)) return $off;
    }
    return $base;
}

/** Benar bila seluruh port yang dipakai run_all.sh pada offset ini bebas. */
function ai_ports_free(int $offset): bool
{
    foreach (range(8136, 8510) as $p) {
        if (ai_port_busy($p + $offset)) return false;
    }
    return true;
}

/** Satu pemeriksaan port (koneksi ditolak = bebas). */
function ai_port_busy(int $port): bool
{
    $errno = 0; $errstr = '';
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.15);
    if ($fp === false) return !($errno === 111 || $errno === 0);
    fclose($fp);
    return true;
}

/** Baca ringkasan hasil uji dari berkas log (PASS/FAIL + baris penting). */
function ai_test_result(string $logFile): array
{
    if ($logFile === '' || !is_file($logFile)) return ['jalan' => false, 'ringkas' => '', 'pass' => 0, 'fail' => 0];
    $txt = (string)file_get_contents($logFile);
    /* Angka diambil dari baris KESIMPULAN ("TOTAL  PASS: n  FAIL: m"). Bila baris
       itu belum ada, uji dianggap masih berjalan — bukan 0/0. */
    $pass = 0; $fail = 0;
    $adaTotal = (bool)preg_match('/TOTAL\s+PASS:\s*(\d+)\s+FAIL:\s*(\d+)/', $txt, $m);
    if ($adaTotal) { $pass = (int)$m[1]; $fail = (int)$m[2]; }
    $berhenti = (bool)preg_match('/SKRIP BERHENTI|Tidak ada.*dijalankan|command not found/i', $txt);
    /* Selesai HANYA bila baris kesimpulan muncul, atau skrip uji berhenti.
       JEBAKAN yang diperbaiki (ronde 47): dulu "selesai" juga dianggap benar begitu
       teks "SKRIP BERHENTI" muncul, tanpa melihat angka. Bila saat itu PASS/FAIL masih
       0/0, hasilnya dinilai LULUS — sehingga uji yang GAGAL TOTAL (0 pemeriksaan
       dijalankan karena salinan rusak) dilaporkan "LULUS, perubahan siap disetujui".
       Ini berbahaya: perubahan yang tidak pernah diuji bisa diterapkan. */
    $selesai = $adaTotal || $berhenti;
    $baris = array_values(array_filter(array_map('trim', explode("\n", $txt)), fn($l) => $l !== ''));
    return [
        'jalan' => !$selesai && count($baris) > 0,
        'selesai' => $selesai,
        'ada_total' => $adaTotal,
        'berhenti' => $berhenti,
        'pass' => $pass, 'fail' => $fail,
        'ringkas' => implode("\n", array_slice($baris, -12)),
        'gagal_baris' => array_values(array_filter($baris, fn($l) => stripos($l, 'FAIL') === 0
            || stripos($l, '  FAIL') === 0 || stripos($l, 'SKRIP BERHENTI') !== false)),
    ];
}

/* ------------------------------------------------------------------ *
 * STATUS AUDIT: SATU SUMBER KEBENARAN (ronde 52)
 * ------------------------------------------------------------------ *
 * Regression yang diperbaiki: Request #926, #927, #930 di produksi berakhir dengan
 * status NO_CHANGE ("tidak ada perubahan yang perlu dilakukan") PADAHAL AI sendiri
 * menulis di rencananya bahwa "berkas yang tersedia belum cukup untuk menyusun patch
 * aman" — bahkan ada yang menulis "AUDIT_INCOMPLETE" — dan permintaannya MEMINTA
 * membaca berkas tambahan.
 *
 * Aturan yang kini ditegakkan (permintaan pemilik):
 *   • AUDIT_INCOMPLETE TIDAK PERNAH boleh dikonversi menjadi NO_CHANGE.
 *   • NO_CHANGE hanya bila audit benar-benar lengkap: semua berkas sasaran terbaca
 *     utuh, tidak ada berkas yang gagal dibaca, lampiran berhasil diproses, permintaan
 *     baca AI terpenuhi, dan untuk pekerjaan ber-area luas (architecture/database/
 *     migrasi/security/refactor/lintas modul) bukti area wajib + cakupan dependency
 *     sudah cukup.
 *   • Bila audit belum lengkap → status `audit_incomplete` (atau `blocked`), dan
 *     berkas yang gagal dibaca WAJIB ditampilkan beserta alasannya.
 *   • Status internal, workflow, dan UI diambil dari SATU fungsi yang sama
 *     (`ai_audit_state()` + `ai_task_status_view()`), sehingga tidak mungkin lagi
 *     mesin menyatakan AUDIT_INCOMPLETE sementara layar menampilkan NO_CHANGE.
 * ------------------------------------------------------------------ */

/**
 * Area pekerjaan yang membutuhkan standar audit LEBIH LUAS.
 *
 * @return array<string,array<int,string>> kode area => kata kunci pemicu
 */
function ai_audit_area_keywords(): array
{
    return [
        'ARCHITECTURE' => ['arsitektur', 'architecture', 'multi-database', 'multi database',
            'central.sqlite', 'per cabang', 'struktur data', 'redesign', 'restrukturisasi',
            'shared database', 'database terpusat'],
        'DATABASE'     => ['database', 'schema', 'skema', 'sqlite', 'data.sqlite', 'tabel',
            'kolom', 'query', 'relasi', 'index', 'backup data'],
        'MIGRATION'    => ['migrasi', 'migration', 'pindah data', 'backfill', 'migrasikan'],
        'SECURITY'     => ['keamanan', 'security', 'hak akses', 'permission', 'izin', 'csrf',
            'kata sandi', 'password', 'enkripsi', 'login'],
        'REFACTOR'     => ['refactor', 'refaktor', 'rombak', 'pecah modul', 'pisahkan modul',
            'menyeluruh', 'lintas modul', 'seluruh aplikasi', 'semua halaman', 'cross-module'],
        'TESTING'      => ['suite uji', 'regression', 'regresi menyeluruh'],
    ];
}

/**
 * Berkas bukti WAJIB per area (dibaca UTUH) sebelum kesimpulan boleh dibuat.
 *
 * @return array<int,string> path relatif
 */
function ai_audit_required_files(array $areas): array
{
    $peta = [
        'ARCHITECTURE' => ['naveena/includes/config.php', 'naveena/includes/schema.php', 'naveena/backup.php'],
        'DATABASE'     => ['naveena/includes/config.php', 'naveena/includes/schema.php'],
        'MIGRATION'    => ['naveena/includes/schema.php', 'naveena/backup.php'],
        'SECURITY'     => ['naveena/includes/config.php', 'naveena/includes/login_security.php'],
        'REFACTOR'     => ['naveena/includes/config.php'],
        'TESTING'      => [],
    ];
    $out = [];
    foreach ($areas as $a) {
        foreach ((array)($peta[$a] ?? []) as $f) $out[$f] = true;
    }
    return array_keys($out);
}

/**
 * Tentukan area pekerjaan dari teks permintaan (+ kategori hasil klasifikasi).
 *
 * @return array{areas:array<int,string>,luas:bool,wajib:array<int,string>}
 */
function ai_audit_area(string $request, string $kategori = ''): array
{
    $t = ' ' . strtolower($request) . ' ';
    $areas = [];
    foreach (ai_audit_area_keywords() as $kode => $kata) {
        if (ai_has_word($t, $kata)) $areas[] = $kode;
    }
    /* Kategori hasil klasifikasi ikut menentukan (mis. DATABASE/SECURITY/REFACTOR). */
    if (in_array(strtoupper($kategori), ['DATABASE', 'REFACTOR', 'SECURITY', 'TESTING'], true)
        && !in_array(strtoupper($kategori), $areas, true)) {
        $areas[] = strtoupper($kategori);
    }
    return ['areas' => $areas, 'luas' => $areas !== [], 'wajib' => ai_audit_required_files($areas)];
}

/**
 * Baca berkas secara PENUH untuk keperluan BUKTI AUDIT (ronde 52).
 *
 * Dipisah dari `ai_read_smart()` yang sengaja memangkas isi agar hemat token:
 *   • `ai_read_smart()`  → bahan yang DIKIRIM ke model (boleh sebagian, bertahap)
 *   • `ai_read_full()`   → bukti bahwa berkas benar-benar DIBACA sistem sampai tuntas
 *
 * Permintaan pemilik: "Jika file besar, jangan langsung menyatakan file dilewati lalu
 * menyimpulkan tidak ada perubahan." Karena itu untuk berkas BUKTI area wajib, sistem
 * membacanya penuh (sampai batas aman) walau isinya tidak seluruhnya dikirim ke model.
 * Bila berkasnya melebihi batas aman, hasilnya dinyatakan TIDAK utuh apa adanya —
 * status audit pun tetap belum lengkap (jujur, tidak mengaku lengkap).
 *
 * @return array{ok:bool,text:string,error:string,size:int,utuh:bool,baris:int,alasan:string}
 */
function ai_read_full(string $rel, ?int $capKb = null): array
{
    $gagal = ['ok' => false, 'text' => '', 'error' => '', 'size' => 0, 'utuh' => false,
        'baris' => 0, 'alasan' => ''];
    $abs = ai_path($rel);
    if ($abs === null) return array_merge($gagal, ['error' => 'berkas di luar cakupan atau tidak ada']);
    $cap = ($capKb ?? max(64, (int)setting('ai_audit_full_kb', '512'))) * 1024;
    $size = (int)@filesize($abs);
    if ($size > $cap) {
        return array_merge($gagal, ['size' => $size,
            'alasan' => 'berkas melebihi batas baca penuh (' . round($size / 1024) . ' KB > '
                . round($cap / 1024) . ' KB)']);
    }
    $teks = @file_get_contents($abs);
    if ($teks === false) return array_merge($gagal, ['size' => $size, 'error' => 'berkas tidak dapat dibaca']);
    return ['ok' => true, 'text' => $teks, 'error' => '', 'size' => $size, 'utuh' => true,
        'baris' => substr_count($teks, "\n") + 1, 'alasan' => ''];
}

/** Ambang minimal berkas terkait (dependency) yang harus dibaca untuk area LUAS. */
function ai_audit_dependency_min(): int
{
    return max(2, (int)setting('ai_audit_dep_min', '3'));
}

/**
 * SATU SUMBER KEBENARAN status audit.
 *
 * @param array $in
 *   'sasaran'          => [rel => 'utuh'|'sebagian'|'tidak dikirim'|'tidak terbaca']
 *   'gagal'            => [rel => alasan]   berkas yang gagal dibaca sama sekali
 *   'terkait'          => [rel => alasan]   hasil penelusuran dampak
 *   'terkait_dibaca'   => [rel]             berkas terkait yang benar-benar dibaca
 *   'minta_baca_gagal' => [rel => alasan]   berkas yang diminta AI tetapi gagal dibaca
 *   'lampiran_gagal'   => [nama => alasan]  lampiran yang isinya tidak terbaca
 *   'area'             => hasil ai_audit_area()
 *   'ai_ragu'          => bool   AI sendiri menyatakan audit belum cukup
 * @return array{lengkap:bool,status:string,alasan:array<int,string>,bukti:array<string,mixed>,boleh_no_change:bool}
 */
function ai_audit_state(array $in): array
{
    $sasaran = (array)($in['sasaran'] ?? []);
    $gagal = (array)($in['gagal'] ?? []);
    $terkait = (array)($in['terkait'] ?? []);
    $terkaitDibaca = array_values(array_unique(array_map('strval', (array)($in['terkait_dibaca'] ?? []))));
    $mintaGagal = (array)($in['minta_baca_gagal'] ?? []);
    $lampiranGagal = (array)($in['lampiran_gagal'] ?? []);
    $area = (array)($in['area'] ?? ['areas' => [], 'luas' => false, 'wajib' => []]);
    $ragu = !empty($in['ai_ragu']);

    $alasan = [];

    /* (1) Berkas yang gagal dibaca sama sekali. */
    foreach ($gagal as $rel => $ket) {
        if (is_int($rel)) { $alasan[] = 'berkas gagal dibaca: ' . (string)$ket; continue; }
        $alasan[] = 'berkas gagal dibaca: ' . (string)$rel . ' (' . (string)$ket . ')';
    }
    /* (2) Berkas SASARAN harus terbaca UTUH. */
    foreach ($sasaran as $rel => $st) {
        if ($st !== 'utuh') {
            $alasan[] = 'berkas sasaran belum terbaca utuh: ' . (string)$rel . ' (' . (string)$st . ')';
        }
    }
    /* (3) Permintaan baca dari AI yang tidak dapat dipenuhi. */
    foreach ($mintaGagal as $rel => $ket) {
        $alasan[] = 'berkas yang diminta AI gagal dibaca: ' . (string)$rel . ' (' . (string)$ket . ')';
    }
    /* (4) Lampiran yang isinya tidak terbaca (mis. PDF gagal diekstrak). */
    foreach ($lampiranGagal as $nama => $ket) {
        $alasan[] = 'lampiran tidak dapat diproses: ' . (string)$nama . ' (' . (string)$ket . ')';
    }
    /* (5) AI SENDIRI menyatakan auditnya belum cukup — ini yang dulu diabaikan. */
    if ($ragu) {
        $alasan[] = 'AI menyatakan auditnya BELUM cukup untuk menyimpulkan perubahan '
            . '(meminta berkas/konteks tambahan atau menyebut audit belum lengkap).';
    }
    /* (6) Standar audit lebih luas untuk pekerjaan ber-area besar. */
    if (!empty($area['luas'])) {
        $sudah = array_keys($sasaran);
        foreach ((array)($area['wajib'] ?? []) as $perlu) {
            $ok = false;
            foreach ($sudah as $rel) {
                if ($rel === $perlu && ($sasaran[$rel] ?? '') === 'utuh') { $ok = true; break; }
            }
            if (!$ok) {
                $alasan[] = 'bukti area ' . implode('/', (array)$area['areas'])
                    . ' belum lengkap: berkas wajib belum terbaca utuh → ' . $perlu;
            }
        }
        $minDep = ai_audit_dependency_min();
        if (count($terkait) >= $minDep && count($terkaitDibaca) < $minDep) {
            $alasan[] = 'dependency belum lengkap: baru ' . count($terkaitDibaca) . ' dari '
                . count($terkait) . ' berkas terkait yang diperiksa (minimal ' . $minDep . ')';
        }
    }

    $lengkap = ($alasan === []);
    return [
        'lengkap' => $lengkap,
        'status' => $lengkap ? 'lengkap' : 'belum_lengkap',
        'alasan' => array_values(array_unique($alasan)),
        'bukti' => [
            'sasaran' => $sasaran, 'gagal' => $gagal, 'minta_baca_gagal' => $mintaGagal,
            'lampiran_gagal' => $lampiranGagal, 'terkait_total' => count($terkait),
            'terkait_dibaca' => $terkaitDibaca, 'area' => $area['areas'] ?? [],
            'ai_ragu' => $ragu,
        ],
        'boleh_no_change' => $lengkap,
    ];
}

/**
 * Apakah jawaban/rencana AI SENDIRI menyatakan auditnya belum cukup?
 *
 * Dulu jawaban seperti "berkas yang tersedia belum cukup untuk menyusun patch aman",
 * "AUDIT_INCOMPLETE", atau permintaan membaca berkas tambahan tetap dikonversi menjadi
 * NO_CHANGE hanya karena daftar `ops`-nya kosong — itulah regression #926/#927/#930.
 *
 * @return array{ragu:bool,kutipan:string}
 */
function ai_answer_says_incomplete(string $teks): array
{
    $t = strtolower((string)$teks);
    if (trim($t) === '') return ['ragu' => false, 'kutipan' => ''];
    $pola = [
        'audit_incomplete', 'audit belum lengkap', 'audit belum cukup', 'belum lengkap untuk',
        'belum cukup untuk', 'tidak cukup untuk', 'kurang lengkap', 'belum dapat disimpulkan',
        'belum bisa disimpulkan', 'belum dapat dipastikan', 'tidak dapat dipastikan',
        'perlu membaca', 'perlu diperiksa lebih', 'diperlukan berkas tambahan',
        'berkas tambahan', 'insufficient', 'not enough context', 'need more context',
        'belum tersedia', 'belum terbaca',
    ];
    foreach ($pola as $p) {
        $pos = strpos($t, $p);
        if ($pos === false) continue;
        $awal = max(0, $pos - 60);
        return ['ragu' => true, 'kutipan' => trim(substr((string)$teks, $awal, 220))];
    }
    return ['ragu' => false, 'kutipan' => ''];
}

/**
 * SATU SUMBER untuk tampilan status sebuah tugas (dipakai UI + titik ajax).
 *
 * Menjamin tidak mungkin lagi mesin menyatakan AUDIT_INCOMPLETE sementara layar
 * menampilkan NO_CHANGE: bila status `noop` tetapi auditnya belum lengkap, tampilan
 * memakai AUDIT_INCOMPLETE beserta alasannya.
 *
 * @return array{kode:string,label:string,tone:string,workflow:string,konsisten:bool,catatan:string}
 */
function ai_task_status_view(array $task): array
{
    $status = (string)($task['status'] ?? 'draft');
    $audit = (string)($task['audit_status'] ?? '');
    $wf = ai_workflow_status($status);
    $label = ai_status_label($status);
    $konsisten = true;
    $catatan = '';
    /* PENJAGA KONSISTENSI: NO_CHANGE tanpa audit lengkap = tidak sah. */
    if ($status === 'noop' && $audit !== '' && $audit !== 'lengkap') {
        $wf = ai_workflow_status('audit_incomplete');
        $label = ai_status_label('audit_incomplete');
        $konsisten = false;
        $catatan = 'Status internal pernah menyatakan NO_CHANGE padahal audit BELUM lengkap — '
            . 'ditampilkan sebagai AUDIT_INCOMPLETE (aturan ronde 52).';
    }
    return ['kode' => $wf['kode'], 'label' => $label[0], 'tone' => $label[1],
        'workflow' => $wf['kode'], 'konsisten' => $konsisten, 'catatan' => $catatan];
}

/** Ringkasan satu baris alasan audit belum lengkap (untuk pesan/UI). */
function ai_audit_state_text(array $state): string
{
    if (!empty($state['lengkap'])) return 'Audit LENGKAP — semua berkas relevan terbaca utuh.';
    return 'Audit BELUM LENGKAP (' . count((array)$state['alasan']) . ' hal): '
        . implode(' | ', array_slice((array)$state['alasan'], 0, 5));
}

/* ------------------------------------------------------------------ *
 * AUTO ERROR RECOVERY / SELF-HEALING (ronde 51)
 * ------------------------------------------------------------------ *
 * Permintaan pemilik: setiap kali AI Developer menemukan bug/error (syntax, runtime,
 * test failure, database/migration, dependency, API, regresi), AI TIDAK berhenti dan
 * menyerahkan perbaikannya ke pemilik. Alurnya:
 *
 *   DETECT ERROR → ANALYZE ROOT CAUSE → AUTO FIX → TEST ULANG → REGRESSION → FINAL CHECK
 *
 * Batas yang WAJIB dijaga (diminta pemilik):
 *   • tidak boleh memakai jalan pintas yang menyembunyikan error (menghapus uji,
 *     melemahkan asersi, membuat uji "PASS" palsu);
 *   • berhenti & minta persetujuan bila butuh keputusan bisnis, kredensial,
 *     penghapusan data, atau perubahan berisiko tinggi;
 *   • status tidak boleh "selesai" bila masih ada error yang bisa diperbaiki;
 *   • setiap error, akar masalah, perbaikan, hasil uji, dan regresi dicatat.
 * ------------------------------------------------------------------ */

/**
 * Baca isi berkas DARI FOLDER SALINAN (staging) — dipakai saat memperbaiki error,
 * karena perbaikan harus dihitung dari isi yang sudah memuat usulan sebelumnya.
 *
 * @return array<string,string> rel => isi
 */
function ai_staging_read(int $taskId, array $rels): array
{
    $base = ai_staging_dir($taskId) . '/';
    $out = [];
    foreach ($rels as $rel) {
        $rel = trim(str_replace('\\', '/', (string)$rel), '/');
        if ($rel === '' || strpos($rel, '..') !== false) continue;
        $abs = $base . $rel;
        if (!is_file($abs)) continue;
        $teks = @file_get_contents($abs);
        if ($teks === false) continue;
        $out[$rel] = $teks;
    }
    return $out;
}

/** Tipe error yang dikenali mesin self-healing (dipakai untuk label & jejak). */
function ai_error_types(): array
{
    return ['sintaks', 'runtime_php', 'uji_gagal', 'uji_tidak_jalan', 'database', 'dependensi',
        'api', 'regresi', 'di_luar_cakupan', 'butuh_keputusan'];
}

/**
 * DETEKSI + ANALISIS AKAR MASALAH dari hasil pemeriksaan sintaks & keluaran suite.
 *
 * Fungsi ini murni membaca keluaran (tanpa memanggil AI), sehingga dapat diuji
 * langsung: pola error dicocokkan ke tipe yang dikenali, lalu ditentukan apakah
 * perbaikannya AMAN dilakukan otomatis atau harus meminta persetujuan pemilik.
 *
 * @param array  $lint   hasil ai_lint_contents(): ['ok'=>bool,'hasil'=>[{file,ok,msg}]]
 * @param array  $hasil  hasil ai_test_result(): pass, fail, gagal_baris, berhenti, ada_total, ringkas
 * @param string $log    keluaran mentah suite (untuk mencari error runtime)
 * @param array  $ubah   berkas yang diubah patch (rel => ada/baru)
 * @return array{ada:bool,tipe:string,ringkas:string,detail:array,bisa_otomatis:bool,alasan:string}
 */
function ai_error_analyze(array $lint, array $hasil, string $log = '', array $ubah = []): array
{
    $detail = [];
    $tipe = '';
    $ringkas = '';
    $teks = $log . "\n" . (string)($hasil['ringkas'] ?? '');
    $gagalBaris = array_slice((array)($hasil['gagal_baris'] ?? []), 0, 12);

    /* (1) Sintaks — paling jelas & hampir selalu dapat diperbaiki otomatis. */
    $sintaksGagal = [];
    foreach ((array)($lint['hasil'] ?? []) as $h) {
        if (empty($h['ok'])) $sintaksGagal[] = (string)$h['file'] . ' → ' . (string)$h['msg'];
    }
    if ($sintaksGagal) {
        $tipe = 'sintaks';
        $ringkas = 'Pemeriksaan sintaks gagal pada ' . count($sintaksGagal) . ' berkas.';
        $detail = array_slice($sintaksGagal, 0, 6);
    } elseif (!empty($hasil['berhenti'])
        || empty($hasil['ada_total'])
        || ((int)($hasil['pass'] ?? 0) + (int)($hasil['fail'] ?? 0)) === 0) {
        /* HARNESS uji sendiri yang tidak jalan (skrip berhenti / 0 pemeriksaan) —
           BUKAN bug pada kode yang diubah. Perbaikannya bukan patch kode, melainkan
           menjalankan ulang suite (kadang salinan/port bermasalah), jadi jangan
           meminta AI "memperbaiki" apa pun. */
        $tipe = 'uji_tidak_jalan';
        $ringkas = 'Harness uji tidak berjalan sampai selesai (skrip berhenti / 0 pemeriksaan)';
        $detail = array_slice(array_filter(array_map('trim', explode("\n", (string)($hasil['ringkas'] ?? '')))),
            0, 8);
        /* Baris gagal tetap dilampirkan sebagai bukti (mis. baris "SKRIP BERHENTI") supaya
           pemilik melihat keluaran sebenarnya, tanpa mengubah tipe error. */
        foreach (array_slice($gagalBaris, 0, 5) as $gb) {
            if (!in_array($gb, $detail, true)) $detail[] = $gb;
        }
        $gagalBaris = [];
    } elseif ($gagalBaris) {
        $tipe = 'uji_gagal';
        $ringkas = count($gagalBaris) . ' pemeriksaan uji GAGAL.';
        $detail = $gagalBaris;
    }

    /* (2) Perbaikan tipe berdasarkan jejak error di keluaran (mis. Parse/Fatal/SQLSTATE). */
    if ($tipe === 'uji_gagal' || $tipe === 'uji_tidak_jalan') {
        /* PENTING (urutan): pola spesifik diperiksa LEBIH DULU. Pola runtime di bawah
           memuat "Fatal error" yang juga cocok untuk "Fatal error: Class X not found"
           dan "Fatal error: ... SQLSTATE" — kalau diperiksa duluan, error dependensi &
           basis data selalu salah diklasifikasi sebagai runtime_php. */
        if (preg_match('/SQLSTATE|no such table|no such column|database is locked|duplicate column|'
            . 'FOREIGN KEY constraint|constraint failed|unable to open database|no such module/i', $teks)) {
            $tipe = 'database';
            $ringkas = 'Error basis data / migrasi.';
        } elseif (preg_match('/Class .{0,60} not found|Failed opening required|cannot redeclare|'
            . 'Call to undefined function|Trait .{0,40} not found|Interface .{0,40} not found|'
            . 'Class .{0,40} not found/i', $teks)) {
            $tipe = 'dependensi';
            $ringkas = 'Error dependensi (berkas/kelas/fungsi tidak ditemukan atau bentrok).';
        } elseif (preg_match('/HTTP\s?5\d\d|cURL|timed? ?out|Connection refused|Could not resolve host/i', $teks)) {
            $tipe = 'api';
            $ringkas = 'Error saat menghubungi layanan/API.';
        } elseif (preg_match('/Fatal error|Uncaught|Call to undefined|Undefined (variable|array key|property)|'
            . 'Cannot access offset/i', $teks)) {
            $tipe = 'runtime_php';
            $ringkas = 'Error PHP saat aplikasi dijalankan (fatal/undefined).';
        }
    }
    /* (3) Regresi: ada pemeriksaan yang GAGAL pada suite yang memeriksa fitur DI LUAR
       berkas yang diubah → kemungkinan perubahan kita merusak bagian lain. */
    $regresi = false;
    if ($tipe === 'uji_gagal' || $tipe === 'runtime_php' || $tipe === 'database') {
        $namaUbah = array_map(fn($r) => strtolower((string)basename((string)$r)), array_keys($ubah));
        foreach ($gagalBaris as $g) {
            if (preg_match('/struk|invoice|order|pasien|reservasi|keuangan|laporan|export|imp|member|bahan|paket/i', $g)
                && $namaUbah && !array_filter($namaUbah, fn($n) => strpos(strtolower($g), $n) !== false)) {
                $regresi = true;
                break;
            }
        }
    }
    if ($regresi) {
        $tipe = 'regresi';
        $ringkas = 'Kemungkinan REGRESI: pemeriksaan fitur lain ikut gagal setelah perubahan.';
    }

    /* (4) Apakah aman diperbaiki otomatis? Bila butuh keputusan bisnis/kredensial/
       perubahan destruktif, AI WAJIB berhenti dan meminta persetujuan pemilik.
       PENTING: pola ini HANYA diperiksa bila memang ADA error ($tipe !== '') dan
       hanya pada BAGIAN YANG GAGAL — bukan seluruh keluaran suite. Baris PASS bisa
       memuat kata seperti "Kunci API belum diisi" (teks pemeriksaan status jujur)
       sehingga uji yang LULUS dulu salah dilaporkan "butuh kredensial". */
    $alasan = '';
    $bisa = ($tipe !== '');
    $teksGagal = '';
    if ($tipe !== '') {
        $barisGagal = [];
        foreach (array_filter(array_map('trim', explode("\n", $teks))) as $l) {
            if (stripos($l, 'FAIL') !== false || stripos($l, 'Fatal') !== false
                || stripos($l, 'SKRIP BERHENTI') !== false || stripos($l, 'Error') !== false
                || stripos($l, 'SQLSTATE') !== false || stripos($l, 'exception') !== false) {
                $barisGagal[] = $l;
            }
        }
        $teksGagal = implode("\n", array_slice($barisGagal, 0, 40));
        /* Bila tidak ada baris bertanda gagal, pakai potongan keluaran terakhir
           (tempat error biasanya tercetak) sebagai bahan perkiraan. */
        if (trim($teksGagal) === '') $teksGagal = substr($teks, -3000);
    }
    $polaTolak = [
        '/kredensial|credential|api[ _-]?key (belum|tidak)|kunci api (belum|tidak)|token .*(belum|invalid|kedaluwarsa)'
            . '|akses ditolak oleh penyedia|unauthorized|403 Forbidden/i'
            => 'butuh KREDENSIAL/akses layanan luar yang hanya dapat diisi pemilik.',
        '/hapus (semua|data)|DROP TABLE|TRUNCATE|penghapusan data|purge/i'
            => 'menyangkut PENGHAPUSAN DATA (tindakan berisiko) — perlu persetujuan.',
        '/migrasi .*(destruktif|kolom dihapus|tabel dihapus)|rename kolom/i'
            => 'perubahan STRUKTUR BASIS DATA yang berdampak data lama — perlu persetujuan.',
        '/keputusan bisnis|aturan bisnis|kebijakan (harga|diskon|pajak|tarif)/i'
            => 'menyangkut KEPUTUSAN BISNIS pemilik.',
    ];
    foreach ($polaTolak as $pola => $pesan) {
        if ($teksGagal !== '' && preg_match($pola, $teksGagal)) {
            $bisa = false; $alasan = $pesan; $tipe = 'butuh_keputusan'; break;
        }
    }
    if ($tipe === 'uji_tidak_jalan') {
        /* Pemulihannya: JALANKAN ULANG suite di salinan baru (ditangani pekerja) —
           bukan menambal kode. Menambal kode di sini justru menyembunyikan masalah. */
        $bisa = false;
        $alasan = 'Harness uji tidak berjalan sampai selesai; perlu DIJALANKAN ULANG di salinan baru '
            . '(bukan bug kode). Bila tetap gagal, pemilik perlu memeriksa lingkungan uji.';
    } elseif ($bisa) {
        $alasan = 'Error dapat diperbaiki di dalam cakupan pekerjaan (tidak menyentuh data/kredensial).';
    }
    return ['ada' => ($tipe !== ''), 'tipe' => $tipe, 'ringkas' => $ringkas, 'detail' => $detail,
            'bisa_otomatis' => $bisa && $tipe !== '', 'alasan' => $alasan];
}

/**
 * PENJAGA ANTI-JALAN-PINTAS untuk perbaikan otomatis (ronde 51).
 *
 * Permintaan pemilik: "Jangan menggunakan workaround yang hanya menyembunyikan error,
 * menghapus test, atau membuat test terlihat PASS secara palsu."
 *
 * Aturan yang ditegakkan:
 *   1. Saat yang GAGAL adalah pemeriksaan uji (bukan sintaks), perbaikan TIDAK BOLEH
 *      menyentuh berkas skrip uji sama sekali — kalau tidak, AI bisa "memperbaiki"
 *      dengan menghapus/melemahkan pemeriksaan yang gagal (PASS palsu).
 *   2. Operasi apa pun yang MENGURANGI penanda pemeriksaan pada skrip uji
 *      (ok(/bad(/assert/expect/CASE/FAIL) ditolak, walau berkasnya di luar folder uji.
 *   3. Operasi yang menghapus seluruh isi berkas uji ditolak.
 *
 * @return array{ok:bool,ditolak:array<int,array{op:int,alasan:string}>}
 */
function ai_heal_guard(array $ops, bool $ujiGagal = true): array
{
    $ditolak = [];
    foreach ($ops as $i => $o) {
        $file = str_replace('\\', '/', (string)($o['file'] ?? ''));
        $search = (string)($o['search'] ?? '');
        $replace = (string)($o['replace'] ?? '');
        $isiUji = (strpos($file, 'naveena_dev/test') === 0) || preg_match('/_check\.(js|sh)$/i', $file)
            || preg_match('~^(test|tests)/~i', $file);
        if ($ujiGagal && $isiUji) {
            $ditolak[] = ['op' => $i + 1, 'alasan' => 'menyentuh skrip uji selagi UJI-nya yang gagal '
                . '(bisa menghapus/melemahkan pemeriksaan → PASS palsu): ' . $file];
            continue;
        }
        if ($isiUji) {
            $hitung = fn(string $t) => preg_match_all('~\b(ok\(|bad\(|assert|expect|eq\(|check\s|CASE|FAIL)~',
                $t, $m) ? count($m[0]) : 0;
            if ($hitung($replace) < $hitung($search)) {
                $ditolak[] = ['op' => $i + 1, 'alasan' => 'mengurangi jumlah pemeriksaan pada skrip uji: ' . $file];
                continue;
            }
        }
        /* Menghapus berkas uji ATAU isinya tidak boleh jadi cara memperbaiki error. */
        if (($o['action'] ?? 'replace') === 'replace' && trim($replace) === '' && $isiUji) {
            $ditolak[] = ['op' => $i + 1, 'alasan' => 'menghapus isi skrip uji: ' . $file];
        }
    }
    return ['ok' => !$ditolak, 'ditolak' => $ditolak];
}

/**
 * Prompt untuk tahap AUTO FIX: AI diberi error sebenarnya (beserta keluaran uji) dan
 * isi berkas saat ini (dari salinan), lalu diminta memperbaiki AKAR MASALAHNYA.
 */
function ai_heal_prompt(string $request, array $analisa, string $lintTeks, string $logRingkas,
                        array $isi, string $kategori = '', string $riwayat = ''): string
{
    $bagian = [];
    foreach ($isi as $rel => $teks) {
        $bagian[] = "===== BERKAS: " . $rel . " =====\n" . $teks . "\n===== AKHIR " . $rel . " =====";
    }
    $p = "PERMINTAAN PEMILIK (yang sedang dikerjakan):\n" . $request . "\n\n";
    if ($riwayat !== '') $p .= "RIWAYAT PERCAKAPAN (konteks):\n" . $riwayat . "\n\n";
    if ($kategori !== '') $p .= "Jenis pekerjaan: " . $kategori . "\n\n";
    $p .= "PERUBAHAN SEBELUMNYA SUDAH DITERAPKAN DI SALINAN, TETAPI PEMERIKSAAN MENEMUKAN MASALAH:\n"
        . "• Jenis error   : " . ($analisa['tipe'] ?? 'tidak diketahui') . "\n"
        . "• Ringkasan     : " . ($analisa['ringkas'] ?? '') . "\n";
    if (!empty($analisa['detail'])) {
        $p .= "• Rincian yang GAGAL:\n  - " . implode("\n  - ", array_slice((array)$analisa['detail'], 0, 10)) . "\n";
    }
    $p .= "\n--- PEMERIKSAAN SINTAKS ---\n" . trim($lintTeks) . "\n";
    if (trim($logRingkas) !== '') {
        $p .= "\n--- KELUARAN SUITE UJI (bagian yang gagal) ---\n" . trim($logRingkas) . "\n";
    }
    $p .= "\nIsi berkas SAAT INI (sudah memuat perubahan sebelumnya — salin \"search\" PERSIS dari sini):\n\n"
        . implode("\n\n", $bagian) . "\n\n";
    $p .= "TUGAS ANDA: temukan AKAR MASALAHNYA lalu perbaiki sehingga pemeriksaan LULUS.\n"
        . "ATURAN PERBAIKAN (WAJIB — pelanggaran membuat perbaikan DITOLAK sistem):\n"
        . "1. Perbaiki KODE APLIKASINYA, bukan pemeriksaannya. JANGAN menyentuh berkas skrip uji\n"
        . "   (naveena_dev/test/…), jangan menghapus/melemahkan pemeriksaan, dan jangan membuat uji\n"
        . "   tampak lulus padahal belum. Jangan menonaktifkan fitur hanya supaya uji lolos.\n"
        . "2. Satu op = satu perubahan kecil; \"search\" harus ada PERSIS SATU KALI pada isi di atas.\n"
        . "3. Bila error terjadi karena kode sebelumnya tidak konsisten (mis. nama variabel/fungsi\n"
        . "   salah, tanda kurung tidak seimbang, kolom/tabel belum ada di skema), perbaiki bagian itu\n"
        . "   sekaligus — termasuk menambahkan kolom di `schema_ddl()` + daftar `\$adds` bila perlu.\n"
        . "4. Bila AKAR MASALAHNYA di luar jangkauan Anda (butuh kredensial, keputusan bisnis, atau\n"
        . "   penghapusan data), JANGAN memaksa: balas {\"plan\":\"...\",\"ops\":[],\"butuh_approval\":true,\n"
        . "   \"alasan\":\"...\"} supaya pemilik dapat memutuskan.\n\n"
        . "Jawab HANYA satu objek JSON:\n"
        . "{\"plan\":\"akar masalah + cara memperbaikinya (singkat)\",\"files\":[\"path/berkas.php\"],"
        . "\"ops\":[{\"file\":\"path/berkas.php\",\"action\":\"replace\",\"search\":\"teks lama PERSIS\","
        . "\"replace\":\"teks baru\"}],\"suggestions\":[\"...\"]}";
    return $p;
}

/**
 * Daftar periksa FINAL CHECK setelah perbaikan otomatis: memastikan hasil akhir
 * benar-benar bersih (sintaks + uji) dan tidak ada jalan pintas yang dipakai.
 *
 * @return array{ok:bool,catatan:array<int,string>}
 */
function ai_heal_final_check(array $lint, array $hasil): array
{
    $catatan = [];
    if (empty($lint['ok'])) {
        foreach ((array)($lint['hasil'] ?? []) as $h) {
            if (empty($h['ok'])) $catatan[] = 'sintaks masih bermasalah: ' . $h['file'] . ' — ' . $h['msg'];
        }
    }
    $pass = (int)($hasil['pass'] ?? 0);
    $fail = (int)($hasil['fail'] ?? 0);
    if ($fail > 0) $catatan[] = 'masih ada ' . $fail . ' pemeriksaan uji yang gagal';
    if (empty($hasil['ada_total'])) $catatan[] = 'suite tidak menyelesaikan pemeriksaan (hasil tidak sah)';
    if ($pass + $fail === 0) $catatan[] = 'suite tidak menjalankan pemeriksaan apa pun (0 PASS / 0 FAIL)';
    return ['ok' => !$catatan, 'catatan' => $catatan];
}

/** Ringkasan singkat hasil analisis error untuk ditampilkan/dicatat. */
function ai_error_text(array $analisa): string
{
    if (empty($analisa['ada'])) return 'tidak ada error yang terdeteksi';
    return strtoupper((string)$analisa['tipe']) . ' — ' . (string)$analisa['ringkas']
        . (!empty($analisa['detail']) ? ' · ' . implode(' | ', array_slice((array)$analisa['detail'], 0, 3)) : '');
}

/**
 * Angka hasil uji sebuah tugas (PASS/FAIL) — dari berkas log BILA MASIH ADA,
 * kalau tidak dari ringkasan yang DISIMPAN di basis data (`test_ringkas`).
 *
 * Penting supaya pesan hasil uji tidak pernah berbunyi "LULUS (0 pemeriksaan)"
 * hanya karena berkas lognya sudah dibersihkan pembersih folder sementara —
 * angka yang dilaporkan harus sama dengan yang benar-benar dijalankan.
 */
function ai_test_counts(array $task): array
{
    $uji = ai_test_result((string)($task['test_log'] ?? ''));
    $ringkasSimpan = trim((string)($task['test_ringkas'] ?? ''));
    if (empty($uji['ada_total']) && $ringkasSimpan !== ''
        && preg_match('/PASS\s+(\d+)\s*·\s*FAIL\s+(\d+)/', $ringkasSimpan, $m)) {
        $uji['pass'] = (int)$m[1];
        $uji['fail'] = (int)$m[2];
        $uji['ada_total'] = true;
        if (trim((string)($uji['ringkas'] ?? '')) === '') $uji['ringkas'] = $ringkasSimpan;
    }
    /* Penerapan hanya boleh setelah uji LULUS — nilai `test_status` tetap menjadi
       penentu akhir, jadi ringkasan tersimpan tidak bisa "meluluskan" uji. */
    if (!empty($uji['ada_total']) && (int)$uji['fail'] > 0) $uji['lulus'] = false;
    else $uji['lulus'] = ($uji['lulus'] ?? null);
    return $uji;
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
    /* PANDUAN APLIKASI (includes/ai_playbook.php) dikirim lebih dulu: berisi peta
       aplikasi, konvensi wajib, helper, resep tugas, dan jebakan yang sudah terbukti.
       Tanpa ini AI hanya tahu potongan kode yang dikirim sehingga jawabannya sering
       salah konteks (mis. menyusun URL absolut, atau tidak tahu berkas mana yang
       mengurus pembayaran). */
    $playbook = function_exists('ai_playbook') ? ai_playbook() : '';
    return ($playbook !== '' ? $playbook . "\n\n" : '')
        . "Anda asisten pengembang untuk aplikasi manajemen klinik berbasis PHP 8 + SQLite "
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
        . "{\"plan\":\"...\",\"files\":[],\"ops\":[]} dan jelaskan alasannya di plan.\n"
        . "9. JANGAN mengubah bagian yang tidak diminta (jangan merapikan atau menulis ulang "
        . "kode lain) supaya perubahan kecil dan mudah diperiksa.\n"
        . "10. Ringkas — \"replace\" cukup sepanjang yang perlu. Jawaban yang sangat panjang "
        . "berisiko TERPOTONG, jadi utamakan perubahan sekecil mungkin.\n\n"
        . "CATATAN PENTING (ronde 44):\n"
        . "• Pemilik dapat mengirim LAMPIRAN (Excel/CSV/PDF/dokumen/gambar). Bila ada lampiran, "
        . "pakai isinya sebagai acuan nyata (nama kolom, contoh data) dan JANGAN mengarang isi.\n"
        . "• Bila permintaan berkaitan dengan IMPOR DATA, sebutkan dengan jelas kolom WAJIB yang "
        . "diambil, ke tabel/kolom mana datanya masuk, serta bagian yang harus dibuat OTOMATIS "
        . "(mis. nomor pasien/nomor member) — dan tulis kode yang benar-benar menangani itu.\n"
        . "• WAJIB menambahkan kunci \"suggestions\": daftar 1-3 saran singkat (bahasa Indonesia) untuk "
        . "pemilik — mis. hal yang sebaiknya diperiksa setelah perubahan, langkah lanjutan yang masuk "
        . "akal, atau efek samping yang perlu diwaspadai. Bila memang tidak ada saran, kirim [].\n"
        . "• Boleh menambahkan kunci \"preview\" berisi nama halaman (mis. \"pasien.php\") yang "
        . "paling tepat dilihat pemilik untuk menilai hasil perubahan.\n"
        . "• BILA DIMINTA MENGUBAH URUTAN bagian/kartu: lakukan dengan DUA op per blok: "
        . "(1) hapus blok dari posisi lama dengan \"replace\": \"\" (search = SELURUH blok itu persis, "
        . "mulai dari baris pembukanya sampai penutupnya), lalu (2) sisipkan blok yang sama pada posisi "
        . "baru dengan \"search\" = penanda di sekitar posisi baru dan \"replace\" = penanda itu + blok "
        . "yang dipindahkan. Jangan menulis ulang isi blok — cukup pindahkan apa adanya supaya tampilannya "
        . "tidak berubah.\n"
        . "• Jangan pernah menghapus blok tanpa menyisipkannya kembali (isi halaman akan hilang).\n\n"
        . "ATURAN PENAMBAHAN FITUR (ronde 50 — wajib):\n"
        . "• Fitur TIDAK dianggap selesai hanya karena satu berkas/halaman dibuat. Periksa daftar "
        . "\"HASIL PENELUSURAN DAMPAK\" yang dikirim bersama prompt, lalu IKUT ubah bagian yang memang "
        . "relevan: database/skema (schema_ddl + daftar `\$adds` migrasi), backend, tampilan, menu "
        . "sidebar (nav_items + izin), hak akses, ekspor/impor, laporan, dashboard, audit log, dan "
        . "pengujian. Jangan mengubah modul yang tidak berkaitan (perubahan harus sekecil mungkin).\n"
        . "• Bila ada bagian yang Anda TIDAK dapat ubah karena berkasnya belum dikirim, mintalah "
        . "berkas itu lewat {\"read\":[…]} atau sebutkan di \"plan\" bagian mana yang masih perlu "
        . "dilanjutkan — jangan diam-diam melewatkannya.\n"
        . "• IMPLEMENTASI BERTAHAP (ronde 52): untuk pekerjaan BESAR yang mustahil diselesaikan dalam "
        . "satu patch (mis. mengganti arsitektur database, refactor lintas modul), Anda BOLEH — dan "
        . "dianjurkan — mengirim patch untuk LANGKAH PERTAMA YANG AMAN (mis. menambah helper koneksi "
        . "per cabang, menyiapkan skema/migrasi, menambahkan titik masuk tanpa mengubah perilaku lama). "
        . "Tulis jelas di \"plan\": ini FASE 1 dari N, apa yang sudah dikerjakan, dan bagian mana yang "
        . "belum. Patch bertahap yang benar SELALU lebih berguna daripada tidak ada perubahan sama sekali. "
        . "Yang DILARANG: menyatakan \"tidak ada perubahan\" (ops kosong) ketika audit Anda belum lengkap, "
        . "atau menonaktifkan/menghapus bagian yang ada hanya agar tampak beres.\n"
        . "• Ubah perilaku bisnis yang sudah ada HANYA bila memang diminta.\n\n"
        . "MEMBEDAKAN DISKUSI DARI PEKERJAAN:\n"
        . "• Bila permintaan hanya bertanya/berdiskusi/minta saran, jawab dengan penjelasan — jangan "
        . "mengubah kode (kosongkan ops). Bila pemilik sudah menetapkan pilihan atau meminta "
        . "dikerjakan, bawa keputusan dari percakapan sebelumnya ke dalam patch.\n\n"
        . "CONTOH jawaban yang benar (hanya bentuk, bukan isi):\n"
        . "{\"plan\":\"Menambah kolom email pada daftar pasien\",\"files\":[\"naveena/pasien.php\"],"
        . "\"ops\":[{\"file\":\"naveena/pasien.php\",\"action\":\"replace\","
        . "\"search\":\"<th>Telepon</th>\",\"replace\":\"<th>Telepon</th><th>Email</th>\"}]}";
}

/**
 * Prompt putaran PERBAIKAN: dipakai bila jawaban sebelumnya tidak dapat dibaca
 * (mis. JSON terpotong). Ditulis supaya AI tahu PERSIS apa yang salah.
 */
function ai_repair_prompt(string $request, string $jawabanSebelumnya, string $masalah, ?array $isi = null): string
{
    $before = ai_strlen($jawabanSebelumnya) > 12000
        ? substr($jawabanSebelumnya, 0, 12000) . "\n… (dipotong)"
        : $jawabanSebelumnya;
    $p = "Permintaan pengguna:\n" . $request . "\n\n"
        . "Jawaban Anda sebelumnya TIDAK DAPAT DIPAKAI karena: " . $masalah . "\n\n"
        . "--- jawaban sebelumnya (mentah) ---\n" . $before . "\n--- akhir jawaban sebelumnya ---\n\n"
        . "PERBAIKI: kirim ULANG seluruh jawaban sebagai SATU objek JSON yang SAH dan LENGKAP "
        . "(plan, files, ops). Bila jawaban sebelumnya terlalu panjang, PECAH menjadi lebih sedikit op "
        . "(mis. satu op untuk bagian penting dulu) — jangan biarkan JSON terpotong.";
    if ($isi !== null) {
        $bagian = [];
        foreach ($isi as $rel => $teks) $bagian[] = "===== BERKAS: " . $rel . " =====\n" . $teks . "\n===== AKHIR " . $rel . " =====";
        if ($bagian) $p .= "\n\nIsi berkas yang relevan (acuan \"search\" harus PERSIS seperti di sini):\n\n" . implode("\n\n", $bagian);
    }
    return $p;
}

/**
 * Prompt putaran PERBAIKAN saat patch tidak dapat DITERAPKAN (mis. teks "search"
 * tidak ditemukan persis / muncul lebih dari sekali). AI diberi tahu kesalahan
 * persisnya dan diminta mengirim patch yang benar.
 */
function ai_repair_apply_prompt(string $request, array $isi, array $ops, string $masalah): string
{
    $p = "Permintaan pengguna:\n" . $request . "\n\n"
        . "Patch yang Anda usulkan TIDAK DAPAT DITERAPKAN.\n"
        . "Pesan kesalahan dari sistem: " . $masalah . "\n\n"
        . "--- patch Anda sebelumnya ---\n" . json_encode($ops, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n--- akhir patch ---\n\n";
    $bagian = [];
    foreach ($isi as $rel => $teks) $bagian[] = "===== BERKAS: " . $rel . " =====\n" . $teks . "\n===== AKHIR " . $rel . " =====";
    if ($bagian) $p .= "Isi berkas SESUNGGUHNYA (salin \"search\" PERSIS dari sini, termasuk spasi/indentasi):\n\n"
        . implode("\n\n", $bagian) . "\n\n";
    $p .= "Kirim ulang patch JSON yang benar (plan, files, ops). Pastikan \"search\" ada PERSIS "
        . "SATU KALI di berkas, dan tulis \"replace\" hanya untuk bagian yang memang diubah.";
    return $p;
}

/** Prompt putaran patch SATU BERKAS (dipakai bila jawaban gabungan selalu terpotong). */
function ai_patch_one_prompt(string $request, string $plan, string $rel, string $teks): string
{
    return "Permintaan pengguna:\n" . $request . "\n\n"
        . ($plan !== '' ? "Rencana yang disepakati: " . $plan . "\n\n" : '')
        . "Kerjakan HANYA berkas berikut. Kirim ops yang menyentuh berkas ini saja.\n\n"
        . "===== BERKAS: " . $rel . " =====\n" . $teks . "\n===== AKHIR " . $rel . " =====\n\n"
        . "Jawab HANYA JSON: {\"plan\":\"…\",\"files\":[\"" . $rel . "\"],\"ops\":[{\"file\":\"" . $rel
        . "\",\"action\":\"replace\",\"search\":\"teks lama PERSIS\",\"replace\":\"teks baru\"}]}";
}

/** Prompt tahap 1: AI memilih berkas yang relevan. */
function ai_pick_prompt(string $request, array $daftar): string
{
    $baris = [];
    foreach ($daftar as $f) $baris[] = $f['rel'];
    /* PETA BERKAS + keterangannya sangat membantu tahap ini: AI jadi tahu berkas mana
       yang mengurus suatu hal (mis. pembayaran → includes/payment.php) tanpa menebak.
       Keterangannya dibaca otomatis dari komentar kepala tiap berkas. */
    $peta = function_exists('ai_app_index_text') ? ai_app_index_text(180) : '';
    return "Permintaan pengguna:\n" . $request . "\n\n"
        . ($peta !== ''
            ? "PETA APLIKASI (path — keterangan; dipakai untuk menebak bagian yang tepat):\n" . $peta . "\n\n"
            : '')
        . "Berikut daftar berkas yang boleh diubah (path relatif, dipisah baris baru):\n"
        . implode("\n", $baris) . "\n\n"
        . "Pilih berkas yang PALING RELEVAN untuk memenuhi permintaan (maksimal "
        . ai_settings()['max_files'] . " berkas). Sertakan berkas yang memuat BAGIAN yang "
        . "disebut pemilik (mis. 'pembayaran' → includes/payment.php + halaman terkait). "
        . "Jawab HANYA JSON: {\"files\":[\"path/relatif.php\"]}";
}

/**
 * Prompt tahap 2: AI menyusun patch dari isi berkas yang dipilih.
 *
 * RONDE 50: ditambah HASIL PENELUSURAN DAMPAK (berkas lain yang memakai fungsi/
 * tabel/setelan/izin yang sama) supaya penambahan fitur langsung diintegrasikan
 * ke bagian yang relevan — bukan berhenti pada satu berkas.
 */
function ai_patch_prompt(string $request, array $isi, string $brief = '', string $attach = '',
                         string $impact = '', string $kategori = '', string $riwayat = ''): string
{
    $bagian = [];
    foreach ($isi as $rel => $teks) {
        $bagian[] = (strpos((string)$teks, '===== BERKAS:') === 0 ? '' : "===== BERKAS: " . $rel . " =====\n")
            . $teks . (strpos((string)$teks, '===== AKHIR') !== false ? '' : "\n===== AKHIR " . $rel . " =====");
    }
    $p = "Permintaan pengguna:\n" . $request . "\n\n";
    if ($riwayat !== '') {
        $p .= "RIWAYAT PERCAKAPAN (konteks; permintaan terbaru di bawah):\n" . $riwayat . "\n\n";
    }
    if ($brief !== '') {
        $p .= "BRIEF dari tahap Arsitek (patuhi ini):\n" . $brief . "\n\n";
    }
    if ($kategori !== '') {
        $p .= "Jenis pekerjaan: " . $kategori . "\n\n";
    }
    if ($attach !== '') {
        $p .= "LAMPIRAN dari pengguna (data/berkas yang dikirim; pakai sebagai acuan isi/kolom):\n"
            . $attach . "\n\n";
    }
    if ($impact !== '') {
        $p .= "HASIL PENELUSURAN DAMPAK (berkas lain yang memakai simbol yang sama dengan berkas "
            . "sasaran). Untuk penambahan/penyesuaian fitur, periksa daftar ini dan IKUT ubah berkas "
            . "yang memang perlu supaya fiturnya terintegrasi (mis. menu/navigasi, izin, ekspor, "
            . "laporan, dashboard, migrasi database). Jangan mengubah berkas yang tidak berkaitan:\n"
            . $impact . "\n\n";
    }
    $p .= "Isi berkas yang relevan" . (count($isi) > 1 ? ' (maksimal ' . count($isi) . ' berkas)' : '') . ":\n\n"
        . implode("\n\n", $bagian) . "\n\n"
        . "Susun patch sesuai format JSON yang diminta (plan, files, ops). "
        . "Pastikan setiap \"search\" benar-benar ada pada isi berkas di atas.\n"
        . "Bila pekerjaannya BESAR: kirim patch untuk LANGKAH PERTAMA YANG AMAN dan sebutkan di plan "
        . "bahwa ini fase 1 dari N beserta bagian yang belum dikerjakan (perubahan bertahap selalu "
        . "lebih berguna daripada tidak ada patch). JANGAN mengirim ops kosong kecuali audit Anda "
        . "memang sudah lengkap dan benar-benar tidak ada yang perlu diubah.";
    return $p;
}

/**
 * Prompt MODE OBROLAN/AUDIT/RENCANA (ronde 50).
 *
 * Permintaan pemilik (V2.3): AI harus bisa "ngobrol/tanya jawab seperti ChatGPT",
 * membedakan diskusi dari tindakan, memberi rekomendasi, lalu beralih ke
 * implementasi ketika benar-benar diminta. Karena itu prompt ini meminta jawaban
 * BERBENTUK OBROLAN (bukan patch), tetapi tetap MENGIZINKAN patch bila ternyata
 * memang itu yang dibutuhkan — model yang memutuskan, bukan tebakan sistem.
 */
function ai_chat_prompt(string $mode, string $request, array $isi, string $brief = '',
                        string $attach = '', string $riwayat = '', string $impact = ''): string
{
    $bagian = [];
    foreach ($isi as $rel => $teks) {
        $bagian[] = (strpos((string)$teks, '===== BERKAS:') === 0 ? '' : "===== BERKAS: " . $rel . " =====\n")
            . $teks . (strpos((string)$teks, '===== AKHIR') !== false ? '' : "\n===== AKHIR " . $rel . " =====");
    }
    $judul = [
        'jawab'   => 'JAWAB pertanyaan/obrolan pemilik',
        'audit'   => 'PERIKSA (audit) aplikasi lalu laporkan temuan',
        'rencana' => 'SUSUN RENCANA pengerjaan (belum mengubah kode)',
    ][$mode] ?? 'JAWAB pemilik';

    $p = "TUGAS SAAT INI: " . $judul . ".\n"
        . "Anda asisten pengembang aplikasi ini (PHP 8 + SQLite, tanpa framework). Anda SEDANG MENGobrol "
        . "dengan pemiliknya, jadi jawab dengan bahasa Indonesia yang jelas, ramah, dan mudah dipahami "
        . "orang non-teknis. JANGAN menyusun patch kecuali pemilik memang meminta dikerjakan atau "
        . "memang jelas perlu dikerjakan sekarang.\n\n";
    if ($riwayat !== '') {
        $p .= "RIWAYAT PERCAKAPAN (yang terakhir = pesan terbaru; pakai sebagai konteks supaya "
            . "pertanyaan lanjutan seperti \"kalau begitu bagaimana?\" atau \"lanjutkan yang tadi\" "
            . "dipahami tanpa bertanya ulang):\n" . $riwayat . "\n\n";
    }
    $p .= "PESAN PEMILIK:\n" . $request . "\n\n";
    if ($brief !== '') $p .= "BRIEF dari tahap Arsitek (ikuti):\n" . $brief . "\n\n";
    if ($impact !== '') $p .= "HASIL PENELUSURAN DAMPAK (berkas lain yang memakai bagian yang sama — "
        . "sebutkan bila relevan pada jawaban/temuan):\n" . $impact . "\n\n";
    if ($attach !== '') {
        $p .= "LAMPIRAN dari pemilik (dipakai sebagai acuan/sumber spesifikasi — jangan mengarang isi):\n"
            . $attach . "\n\n";
    }
    if ($bagian) {
        $p .= "ISI BERKAS YANG RELEVAN (bahan analisis; nomor baris dipakai untuk menunjuk letak):\n\n"
            . implode("\n\n", $bagian) . "\n\n";
    }
    $p .= "BENTUK JAWABAN — satu objek JSON (tanpa pagar kode):\n"
        . "{\"answer\":\"jawaban lengkap dalam bahasa Indonesia (boleh beberapa paragraf, ringkas tapi jelas)\","
        . "\"suggestions\":[\"saran singkat untuk pemilik\"],\"next_steps\":[\"langkah lanjutan yang masuk akal\"],"
        . "\"files\":[\"path/berkas.php\"],\"ops\":[]}\n\n"
        . "ATURAN:\n"
        . "• \"answer\" WAJIB ada dan berisi jawaban sesungguhnya — bukan sekadar menyebut berkas.\n"
        . "   Untuk mode PERIKSA: tulis temuan apa adanya (bagian yang bermasalah, bukti dari kode, "
        . "tingkat bahayanya, dan usulan perbaikan) — bila tidak menemukan masalah, katakan apa adanya "
        . "beserta apa yang sudah diperiksa.\n"
        . "   Untuk mode RENCANA: tulis tahapan berurutan, berkas yang akan disentuh, dan risikonya.\n"
        . "• \"ops\" DIISI HANYA bila pemilik memang meminta perubahan dikerjakan SEKARANG. Bila belum, "
        . "kirim \"ops\":[] — pemilik akan menulis \"kerjakan\" bila sudah setuju.\n"
        . "• Bila Anda butuh membaca berkas lain lebih dulu, jawab {\"read\":[\"path/berkas.php\"]} "
        . "dan sistem akan mengirimkannya.\n"
        . "• Jangan menulis kunci API/sandi/token. Jangan mengarang isi berkas yang tidak dikirim.\n"
        . "• \"suggestions\" berisi 1–3 saran singkat (boleh [] bila tidak ada).";
    return $p;
}

/**
 * Baca jawaban mode obrolan/audit/rencana.
 *
 * Menerima jawaban JSON (bentuk yang diminta prompt) MAUPUN teks biasa — model
 * kadang menjawab dengan penjelasan panjang tanpa JSON, dan itu tetap harus
 * ditampilkan sebagai jawaban (bukan dianggap gagal).
 *
 * @return array{ok:bool,answer:string,suggestions:array,next:array,files:array,ops:array,minta_baca:array,error:string}
 */
function ai_answer_parse(string $text): array
{
    $out = ['ok' => false, 'answer' => '', 'suggestions' => [], 'next' => [], 'files' => [],
            'ops' => [], 'minta_baca' => [], 'error' => ''];
    $bersih = trim((string)preg_replace('/^```(?:json)?\s*/i', '', trim($text)));
    $bersih = trim((string)preg_replace('/\s*```$/', '', $bersih));
    $json = ai_extract_json($bersih);
    if (is_array($json)) {
        $out['answer'] = trim((string)($json['answer'] ?? $json['jawaban'] ?? $json['plan'] ?? $json['penjelasan'] ?? ''));
        foreach ((array)($json['suggestions'] ?? $json['saran'] ?? []) as $s) {
            $s = trim((string)(is_array($s) ? json_encode($s, JSON_UNESCAPED_UNICODE) : $s));
            if ($s !== '') $out['suggestions'][] = $s;
        }
        foreach ((array)($json['next_steps'] ?? $json['langkah'] ?? $json['steps'] ?? []) as $s) {
            $s = trim((string)(is_array($s) ? json_encode($s, JSON_UNESCAPED_UNICODE) : $s));
            if ($s !== '') $out['next'][] = $s;
        }
        foreach ((array)($json['files'] ?? []) as $f) {
            $f = trim(str_replace('\\', '/', (string)$f));
            if ($f !== '' && ai_path($f) !== null) $out['files'][] = $f;
        }
        foreach ((array)($json['read'] ?? []) as $f) {
            $f = trim(str_replace('\\', '/', (string)$f));
            if ($f !== '' && ai_path($f) !== null) $out['minta_baca'][] = $f;
        }
        if (isset($json['ops']) && is_array($json['ops']) && $json['ops']) {
            /* Model memilih langsung memberi patch — diteruskan ke alur patch. */
            $out['ops'] = $json['ops'];
        }
        if ($out['answer'] !== '') $out['ok'] = true;
    }
    if (!$out['ok']) {
        /* Bukan JSON (atau JSON tanpa "answer"): pakai teks mentahnya sebagai jawaban
           bila memang berisi penjelasan — jangan buang jawaban yang sudah benar. */
        $teks = trim($text);
        if ($teks !== '' && strpos($teks, '{') === false) {
            $out['answer'] = $teks;
            $out['ok'] = true;
        }
    }
    if (!$out['ok']) $out['error'] = 'Jawaban AI tidak memuat penjelasan yang dapat dibaca.';
    return $out;
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
                       COALESCE(tokens_total,0) tokens_total, preview_page, engine,
                       COALESCE(intent,\'\') intent, COALESCE(audit_status,\'\') audit_status,
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
    /* SATU pekerja per tugas per mode (ronde 49). Sebelumnya menekan tombol uji
       dua kali menjalankan DUA suite pada SALINAN YANG SAMA: salah satu selesai
       lebih dulu lalu membersihkan `naveena_tmp` salinan itu, sehingga suite yang
       masih berjalan kehilangan berkasnya ("No such file or directory") dan
       dilaporkan GAGAL — inilah "uji staging gagal terus" yang dilaporkan pemilik. */
    if (ai_job_running($taskId, $mode)) return;
    $php = ai_php_cli();
    $worker = __DIR__ . '/../ai_worker.php';
    $log = ai_tmp_dir() . '/log/worker-' . preg_replace('/[^a-z]/', '', $mode) . '-' . $taskId . '.log';
    $cmd = escapeshellarg($php) . ' -d extension=pdo -d extension=pdo_sqlite '
        . escapeshellarg($worker) . ' ' . escapeshellarg($mode) . ' ' . $taskId;
    ai_exec_background($cmd, $log);
}

/**
 * Berkas penanda pekerja (plan/test) untuk satu tugas.
 *
 * Nama berkas memuat sidik basis data supaya tugas dengan id yang sama pada basis
 * data BERBEDA (suite uji memakai DB sendiri, staging memakai DB salinan) tidak
 * pernah saling mengunci.
 */
function ai_job_lock_path(int $taskId, string $mode): string
{
    $sidik = substr(md5((string)(defined('DB_PATH') ? DB_PATH : 'tanpa-db')), 0, 8);
    return ai_tmp_dir() . '/tmp/task-' . max(0, $taskId) . '-'
        . preg_replace('/[^a-z]/', '', $mode) . '-' . $sidik . '.lock';
}

/**
 * Apakah pekerja mode ini SEDANG berjalan untuk tugas tersebut?
 *
 * Memakai `flock` (kunci berkas milik sistem operasi), BUKAN memeriksa PID di isi
 * berkas. Alasannya: PID mudah dipakai ulang oleh proses lain sehingga penanda sisa
 * dari pekerjaan lama tampak "masih hidup" — akibatnya pekerja berikutnya menolak
 * berjalan dan tugas menggantung selamanya di status `draft` (pernah terjadi pada
 * uji ronde 50). Kunci flock otomatis dilepas oleh sistem saat prosesnya berakhir,
 * termasuk bila proses mati mendadak.
 */
function ai_job_running(int $taskId, string $mode): bool
{
    $f = ai_job_lock_path($taskId, $mode);
    if (!is_file($f)) return false;
    $h = @fopen($f, 'c+');
    if ($h === false) return false;
    $bisa = @flock($h, LOCK_EX | LOCK_NB);
    if ($bisa) { @flock($h, LOCK_UN); }
    @fclose($h);
    return !$bisa;   // tidak bisa dikunci = ada pemakai
}

/**
 * Ambil penanda pekerja. false bila sudah ada pekerja lain untuk mode yang sama.
 * Kunci dipegang selama proses hidup; `ai_job_unlock()` menutupnya.
 */
function ai_job_lock(int $taskId, string $mode, &$handle = null): bool
{
    $dir = ai_tmp_dir() . '/tmp';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $f = ai_job_lock_path($taskId, $mode);
    $h = @fopen($f, 'c+');
    if ($h === false) return true;          // tidak bisa menulis penanda: jangan halangi pekerjaan
    if (!@flock($h, LOCK_EX | LOCK_NB)) { @fclose($h); return false; }
    @ftruncate($h, 0);
    @fwrite($h, getmypid() . ' ' . date('c'));
    $handle = $h;
    return true;
}

/** Lepas penanda pekerja (kunci dilepas sistem; berkasnya boleh tetap ada). */
function ai_job_unlock(int $taskId, string $mode, &$handle = null): void
{
    if ($handle) { @flock($handle, LOCK_UN); @fclose($handle); $handle = null; }
}

/* ------------------------------------------------------------------ *
 * KLASIFIKASI PERMINTAAN: OBROLAN vs PEKERJAAN (ronde 50)
 * ------------------------------------------------------------------ */

/** Kata kerja yang jelas MEMINTA perubahan (dipakai klasifikasi). */
function ai_action_verbs(): array
{
    return ['perbaiki', 'perbaikilah', 'benahi', 'betulkan', 'benerin', 'fix', 'reparasi',
        'tambah', 'tambahkan', 'buat', 'buatkan', 'bikin', 'hilangkan', 'hapus', 'hapuskan',
        'ganti', 'ubah', 'ubahlah', 'tukar', 'tukarkan', 'urutkan', 'susun', 'pindah', 'pindahkan',
        'geser', 'rapikan', 'sederhanakan', 'pisahkan', 'gabungkan', 'pasang', 'aktifkan', 'matikan',
        'nonaktifkan', 'tampilkan', 'sembunyikan', 'sesuaikan', 'lengkapi', 'selesaikan', 'kerjakan',
        'lakukan', 'jalankan', 'terapkan', 'implementasikan', 'integrasikan', 'migrasikan',
        'optimalkan', 'refactor', 'refaktor', 'tingkatkan', 'kurangi', 'percepat', 'setel', 'atur',
        'taruh', 'letakkan', 'perpanjang', 'perpendek', 'panjangkan', 'perjelas', 'perkecil',
        'perbesar', 'pindai', 'koreksi', 'rombak', 'ubahnya'];
}

/**
 * Kata kerja "KUAT" (mengubah perilaku/tampilan). Dipakai membedakan permintaan
 * yang memakai kata kerja lemah seperti "buat/bikin/susun" — mis. "buat RENCANA
 * migrasi…" adalah permintaan RENCANA, bukan perintah mengubah kode.
 */
function ai_action_verbs_strong(): array
{
    $lemah = ['buat', 'buatkan', 'bikin', 'susun', 'tulis'];
    return array_values(array_diff(ai_action_verbs(), $lemah));
}

/** Kata tanya — dipakai membedakan "bertanya" dari "meminta dikerjakan". */
function ai_question_words(): array
{
    return ['apa', 'apakah', 'kenapa', 'kok', 'mengapa', 'bagaimana', 'gimana', 'berapa',
        'kapan', 'siapa', 'mana', 'bisakah', 'bolehkah', 'menurut'];
}

/** Kata kunci kategori pekerjaan (untuk label & petunjuk tambahan). */
function ai_intent_categories(): array
{
    return [
        'BUGFIX'         => ['perbaiki', 'bug', 'error', 'eror', 'rusak', 'salah', 'tidak jalan',
                             'tidak muncul', 'gagal', 'masalah', 'bermasalah', 'bentrok', 'hilang',
                             'kosong', 'tidak bisa', 'tidak tersimpan', 'tertukar'],
        'FEATURE'        => ['tambah', 'tambahkan', 'fitur', 'halaman baru', 'menu baru', 'kolom baru',
                             'kartu baru', 'buatkan', 'buat', 'bikin', 'tombol baru', 'laporan baru'],
        'REFACTOR'       => ['refactor', 'refaktor', 'rapikan struktur', 'pecah', 'pisahkan',
                             'gabungkan', 'sederhanakan', 'bersihkan kode'],
        'DATABASE'       => ['database', 'tabel', 'schema', 'skema', 'migrasi', 'kolom db', 'index',
                             'query', 'relasi', 'backup data'],
        'CONFIGURATION'  => ['setelan', 'setting', 'konfigurasi', 'config', 'default', 'bawaan',
                             'matikan fitur', 'aktifkan fitur', 'atur', 'setel'],
        'TESTING'        => ['uji', 'test', 'testing', 'pengujian', 'suite'],
        'SECURITY'       => ['keamanan', 'aman', 'hak akses', 'izin', 'permission', 'sandi', 'password',
                             'csrf', 'role'],
        'PERFORMANCE'    => ['cepat', 'lambat', 'kinerja', 'performa', 'berat', 'lemot', 'optimalkan'],
        'DOCUMENTATION'  => ['dokumen', 'dokumentasi', 'panduan', 'petunjuk', 'readme'],
        'MAINTENANCE'    => ['bersihkan', 'hapus data', 'pemeliharaan', 'perawatan', 'tidak terpakai'],
        'INTEGRATION'    => ['integrasi', 'webhook', 'api', 'email', 'whatsapp', 'gateway', 'satu sehat'],
    ];
}

/** Apakah teks memuat salah satu kata (awal kata, toleran awalan/akhiran Indonesia). */
function ai_has_word(string $teks, array $kata): bool
{
    foreach ($kata as $k) {
        $k = trim($k);
        if ($k === '') continue;
        if (preg_match('~(?<![a-z])' . preg_quote($k, '~') . '~i', $teks)) return true;
    }
    return false;
}

/**
 * Jumlah kata berbeda yang cocok (bukan jumlah entri kata kunci).
 *
 * PENTING: tanpa ini, "tambahkan" dihitung DUA kali (kata kunci "tambah" dan
 * "tambahkan" cocok pada posisi yang sama), sehingga "tambahkan kolom baru di
 * tabel pasien lewat migrasi database" terbaca FEATURE padahal jelas DATABASE.
 * Setiap posisi kecocokan hanya dihitung SEKALI.
 */
function ai_word_hits(string $teks, array $kata): int
{
    $pos = [];
    foreach ($kata as $k) {
        $k = trim((string)$k);
        if ($k === '') continue;
        if (preg_match_all('~(?<![a-z])' . preg_quote($k, '~') . '~i', $teks, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) $pos[(int)$hit[1]] = true;
        }
    }
    return count($pos);
}

/** Ucapan singkat yang jelas bukan perintah kerja ("halo", "terima kasih", dst). */
function ai_intent_trivial(string $teks): bool
{
    $t = trim((string)preg_replace('/[^a-z\s]+/i', ' ', strtolower($teks)));
    $t = trim((string)preg_replace('/\s+/', ' ', $t));
    if ($t === '') return true;
    $daftar = ['halo', 'hai', 'hi', 'hello', 'hey', 'assalamualaikum', 'pagi', 'siang', 'sore', 'malam',
        'terima kasih', 'thanks', 'thank you', 'makasih', 'oke', 'ok', 'sip', 'mantap', 'bagus',
        'baik', 'ya', 'yoi', 'siap', 'oke bagus', 'oke sip', 'baik sip', 'oke mantap', 'lanjut'];
    return in_array($t, $daftar, true);
}

/**
 * Baca maksud pesan pemilik: SEDANG BERTANYA/BERDISKUSI/MINTA SARAN/AUDIT/RENCANA,
 * atau MEMINTA DIKERJAKAN.
 *
 * Permintaan pemilik (spesifikasi V2.3): "jangan setiap pesan dianggap sebagai
 * perintah coding — percakapan biasa harus dijawab sebagai percakapan", TETAPI
 * "jangan berhenti setelah membuat rencana padahal pengguna meminta implementasi".
 *
 * Hasil:
 *   'mode'    : jawab | audit | rencana | kerjakan  → menentukan alur pekerja
 *   'intent'  : question|discussion|recommendation|decision|check|plan|action
 *   'kategori': BUGFIX / FEATURE / REFACTOR / DATABASE / CONFIGURATION / …
 *   'tindakan': true bila pemilik memang meminta perubahan kode
 *   'label'   : kalimat pendek bahasa Indonesia untuk ditampilkan
 *   'alasan'  : dasar penetapannya (dipakai jejak kerja/trace)
 *
 * PENTING: ini hanya PETUNJUK, bukan pengunci. Pekerja tetap menerima jawaban
 * model yang berisi patch (`ops`) walau klasifikasinya "jawab", dan sebaliknya
 * jawaban yang murni penjelasan tetap ditampilkan sebagai jawaban — sehingga
 * salah klasifikasi tidak pernah membuat pekerjaan hilang atau mengubah berkas
 * tanpa diminta.
 */
function ai_intent_classify(string $teks, string $riwayat = ''): array
{
    $asli = trim($teks);
    $t = strtolower((string)preg_replace('/\s+/', ' ', $asli));
    $hasil = ['intent' => 'action', 'mode' => 'kerjakan', 'kategori' => 'ENGINEERING',
        'tindakan' => true, 'label' => 'Minta dikerjakan', 'alasan' => 'permintaan berisi tindakan perubahan'];

    if ($t === '') {
        return ['intent' => 'question', 'mode' => 'jawab', 'kategori' => 'QUESTION', 'tindakan' => false,
            'label' => 'Pertanyaan', 'alasan' => 'pesan kosong'];
    }

    /* Kategori pekerjaan dibaca lebih dulu (dipakai juga saat mode = kerjakan).
       Dihitung dengan SKOR: kategori yang paling banyak disebut yang menang, sehingga
       "tambahkan kolom baru di tabel pasien lewat migrasi database" terbaca DATABASE
       (3 kata cocok) — bukan FEATURE hanya karena ada kata "tambahkan". */
    $skorKategori = [];
    foreach (ai_intent_categories() as $kat => $kata) {
        $n = ai_word_hits($t, $kata);
        if ($n > 0) $skorKategori[$kat] = $n;
    }
    if ($skorKategori) {
        arsort($skorKategori);
        $hasil['kategori'] = (string)array_key_first($skorKategori);
    }

    $aksi = ai_has_word($t, ai_action_verbs());
    $kataAwal = (string)strtok($t, ' ');
    $mulaiAksi = ($kataAwal !== '' && ai_has_word($kataAwal, ai_action_verbs()));
    $tanya = (strpos($t, '?') !== false) || ai_has_word($t, ai_question_words());
    $kataRencana = ['rencana', 'rencanakan', 'rancang', 'plan', 'strategi', 'tahapan',
        'langkah-langkah', 'step by step', 'roadmap'];

    if ($aksi && ($mulaiAksi || !$tanya)) {
        /* "Buat rencana …" / "Susun tahapan …" = minta RENCANA (belum mengerjakan),
           sedangkan "Buat halaman baru …" = minta dikerjakan. Pembedaannya dari ada/
           tidaknya kata kerja KUAT. */
        if (ai_has_word($t, $kataRencana) && !ai_has_word($t, ai_action_verbs_strong())) {
            return ['intent' => 'plan', 'mode' => 'rencana', 'kategori' => 'PLAN', 'tindakan' => false,
                'label' => 'Minta rencana', 'alasan' => 'pesan meminta rencana/tahapan, bukan perubahan langsung'];
        }
        if (ai_has_word($t, ['cek', 'periksa', 'audit', 'tinjau', 'review', 'analisa', 'analisis', 'telusuri'])
            && !ai_has_word($t, ai_action_verbs_strong())) {
            return ['intent' => 'check', 'mode' => 'audit', 'kategori' => 'AUDIT', 'tindakan' => false,
                'label' => 'Minta diperiksa/diaudit', 'alasan' => 'pesan meminta pemeriksaan, bukan perubahan langsung'];
        }
        return array_merge($hasil, ['intent' => 'action', 'mode' => 'kerjakan', 'tindakan' => true,
            'label' => 'Minta dikerjakan (' . $hasil['kategori'] . ')',
            'alasan' => 'pesan memuat kata kerja permintaan perubahan ("' . $kataAwal . '"…)']);
    }
    if ($tanya) {
        /* Pertanyaan/rekomendasi/audit — dijawab, berkas TIDAK diubah. */
        if (ai_has_word($t, ['cek', 'periksa', 'audit', 'tinjau', 'review', 'analisa', 'analisis',
                'telusuri', 'cari tahu', 'apa yang salah', 'kenapa', 'mengapa', 'kok'])) {
            return ['intent' => 'check', 'mode' => 'audit', 'kategori' => 'AUDIT', 'tindakan' => false,
                'label' => 'Minta diperiksa/diaudit', 'alasan' => 'pertanyaan pemeriksaan (tanpa permintaan ubah)'];
        }
        if (ai_has_word($t, ['saran', 'rekomendasi', 'sebaiknya', 'lebih baik', 'terbaik', 'opsi',
                'pilihan', 'alternatif', 'menurutmu', 'menurut'])) {
            return ['intent' => 'recommendation', 'mode' => 'jawab', 'kategori' => 'RECOMMENDATION',
                'tindakan' => false, 'label' => 'Minta saran/rekomendasi',
                'alasan' => 'pertanyaan meminta saran (bukan perintah ubah)'];
        }
        return ['intent' => 'question', 'mode' => 'jawab', 'kategori' => 'QUESTION', 'tindakan' => false,
            'label' => 'Pertanyaan', 'alasan' => 'pesan berbentuk pertanyaan'];
    }
    if (ai_has_word($t, ['cek', 'periksa', 'audit', 'tinjau', 'review', 'analisa', 'analisis', 'telusuri'])) {
        return ['intent' => 'check', 'mode' => 'audit', 'kategori' => 'AUDIT', 'tindakan' => false,
            'label' => 'Minta diperiksa/diaudit', 'alasan' => 'pesan meminta pemeriksaan'];
    }
    if (ai_has_word($t, $kataRencana)) {
        return ['intent' => 'plan', 'mode' => 'rencana', 'kategori' => 'PLAN', 'tindakan' => false,
            'label' => 'Minta rencana', 'alasan' => 'pesan meminta rencana/tahapan'];
    }
    if (ai_has_word($t, ['pakai opsi', 'gunakan opsi', 'pakai solusi', 'gunakan solusi', 'pilih opsi',
            'ambil opsi', 'pakai yang', 'gunakan yang'])) {
        return ['intent' => 'decision', 'mode' => 'jawab', 'kategori' => 'DECISION', 'tindakan' => false,
            'label' => 'Menetapkan pilihan', 'alasan' => 'pemilik memilih salah satu opsi yang dibahas'];
    }
    if (ai_intent_trivial($asli)) {
        return ['intent' => 'discussion', 'mode' => 'jawab', 'kategori' => 'CONVERSATION', 'tindakan' => false,
            'label' => 'Sapaan/obrolan', 'alasan' => 'pesan singkat bukan perintah'];
    }
    /* Tidak ada penanda khusus: anggap permintaan pekerjaan (perilaku lama yang paling
       sering benar), TETAPI pekerja tetap menampilkan jawaban apa adanya bila model
       tidak mengusulkan perubahan. */
    return $hasil;
}

/** Pilihan mode yang dapat dipaksa pemilik (opsional di kotak chat). */
function ai_intent_modes(): array
{
    return [
        'auto'     => 'Otomatis — AI menebak sendiri (disarankan)',
        'jawab'    => 'Jawab saja (obrolan/penjelasan — tanpa mengubah kode)',
        'audit'    => 'Periksa/audit (laporan temuan — tanpa mengubah kode)',
        'rencana'  => 'Susun rencana (tahapan & risiko — tanpa mengubah kode)',
        'kerjakan' => 'Kerjakan (ubah kode + uji + validasi)',
    ];
}

/* ------------------------------------------------------------------ *
 * JEJAK KERJA (TRACE) — supaya kegagalan dapat ditelusuri (ronde 50)
 * ------------------------------------------------------------------ */

/** Catat satu fase pekerjaan beserta datanya (untuk penelusuran). */
function ai_trace(int $taskId, string $phase, array $data = []): void
{
    if ($taskId <= 0) return;
    try {
        $teks = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        /* Data besar dipangkas supaya baris jejak tetap ringan dibaca. */
        if (ai_strlen((string)$teks) > 6000) $teks = substr((string)$teks, 0, 6000) . '…';
        q('INSERT INTO ai_traces (task_id, phase, data, created_at)
           VALUES (?,?,?,datetime("now","localtime"))', [$taskId, short_text($phase, 60), $teks]);
    } catch (Throwable $e) { /* jejak tidak boleh menggagalkan pekerjaan */ }
}

/** Daftar jejak kerja sebuah tugas. */
function ai_traces(int $taskId, int $limit = 60): array
{
    try {
        return all('SELECT * FROM ai_traces WHERE task_id = ? ORDER BY id LIMIT ' . max(1, $limit), [$taskId]);
    } catch (Throwable $e) {
        return [];
    }
}

/* ------------------------------------------------------------------ *
 * PENELUSURAN DAMPAK (dependency/impact) — ronde 50
 * ------------------------------------------------------------------ */

/**
 * Indeks simbol: peta kata-identifier → berkas yang memuatnya.
 *
 * Dibuat SEKALI per proses (di-cache di variabel statis) supaya penelusuran
 * dampak tidak membaca semua berkas berulang kali. Yang diindeks hanya kata
 * minimal 4 huruf (mis. `order_create`, `inv_apply`, `patients`, `branch_sql`).
 *
 * @return array<string,array<int,string>>
 */
function ai_symbol_index(): array
{
    static $index = null;
    if ($index !== null) return $index;
    $index = [];
    foreach (ai_scope_files() as $f) {
        $abs = ai_root() . '/' . $f['rel'];
        $teks = @file_get_contents($abs);
        if ($teks === false) continue;
        if (preg_match_all('/[A-Za-z_][A-Za-z0-9_.]{3,}/', $teks, $m)) {
            foreach (array_unique($m[0]) as $w) {
                $w = strtolower($w);
                /* Kata umum bahasa/pustaka dibuang: kalau tidak, hampir SEMUA berkas
                   dianggap "terkait" (mis. karena sama-sama menyebut RuntimeException,
                   resolve_branch_input, per_page_inline) sehingga laporan dampak
                   tidak lagi menolong. */
                if (in_array($w, ai_impact_noise(), true)) continue;
                $index[$w][$f['rel']] = true;
            }
        }
    }
    foreach ($index as $k => $v) $index[$k] = array_keys($v);
    return $index;
}

/**
 * Cari bagian lain yang IKUT TERDAMPAK oleh perubahan pada berkas sasaran.
 *
 * Permintaan pemilik (V2.3 bagian 2): "AI wajib menemukan file, fungsi, class,
 * route, database/schema, konfigurasi, frontend, API/AJAX, permission, menu,
 * report, testing, dan komponen lain yang terdampak. Jangan hanya mencari
 * berdasarkan satu keyword."
 *
 * Cara kerjanya (deterministik, tanpa memanggil AI — jadi murah & dapat diuji):
 *   1. Dari berkas sasaran, kumpulkan SIMBOL yang khas: berkas yang di-require,
 *      nama fungsi/helper, nama tabel, kunci setelan, kode izin, halaman .php,
 *      dan action API.
 *   2. Cari setiap simbol di indeks → berkas lain yang menyebutnya menjadi
 *      "terkait", dengan alasan berupa simbol yang menghubungkan.
 *
 * @return array{terkait:array<string,string>,simbol:array<int,string>,tidak_ada:array<int,string>}
 */
function ai_impact_noise(): array
{
    return ['runtimeexception', 'exception', 'throwable', 'closure', 'static', 'string', 'array',
        'bool', 'float', 'void', 'null', 'true', 'false', 'public', 'private', 'protected',
        'function', 'return', 'class', 'extends', 'implements', 'namespace', 'interface', 'declare',
        'strict_types', 'parent', 'self', 'iterable', 'mixed', 'never', 'callable', 'final', 'abstract',
        'const', 'echo', 'print', 'resolve_branch_input', 'per_page_inline', 'per_page_select',
        'empty_state', 'short_text', 'is_owner_level', 'is_super', 'has_perm', 'require_perm',
        'page_head', 'page_foot', 'csrf_field', 'verify_csrf', 'flash', 'audit', 'branch_sql',
        'bscope', 'scope_branch', 'user_branch', 'current_user', 'resolve_period', 'money', 'num',
        'tgl', 'badge', 'icon', 'qty_text', 'qty_unit', 'qty_parse', 'setting', 'set_setting',
        'settings', 'table_has_column', 'ensure_schema', 'run_migrations'];
}

/**
 * Cari bagian lain yang IKUT TERDAMPAK oleh perubahan pada berkas sasaran.
 */
function ai_impact_scan(array $sasaran, string $permintaan = '', int $maks = 8): array
{
    $sasaran = array_values(array_unique(array_filter(array_map('strval', $sasaran))));
    if (!$sasaran) return ['terkait' => [], 'simbol' => [], 'tidak_ada' => []];
    $simbol = [];
    foreach ($sasaran as $rel) {
        $b = ai_read($rel);
        if (!$b['ok']) continue;
        $teks = (string)$b['text'];
        /* (1) berkas yang di-include/di-require dari berkas sasaran. */
        if (preg_match_all('~require(?:_once)?[^;]{0,40}?[\'"]([A-Za-z0-9_\-./]+\.php)[\'"]~i', $teks, $m)) {
            foreach ($m[1] as $inc) {
                $inc = trim($inc, '/');
                if (strpos($inc, '..') !== false) continue;
                $simbol[strtolower(basename($inc))] = true;
            }
        }
        /* (2) nama fungsi/helper yang dipanggil (mis. order_create( , inv_apply( ). */
        if (preg_match_all('~\b([a-z_][a-z0-9_]{3,})\s*\(~i', $teks, $m)) {
            $umum = ['array', 'function', 'if', 'for', 'foreach', 'while', 'switch', 'isset', 'empty',
                'print', 'echo', 'sprintf', 'preg_match', 'preg_replace', 'file_get_contents', 'implode',
                'explode', 'substr', 'strlen', 'count', 'trim', 'reset', 'array_map', 'array_filter',
                'array_merge', 'array_keys', 'array_values', 'in_array', 'number_format', 'date', 'time',
                'json_encode', 'json_decode', 'htmlspecialchars', 'urlencode', 'str_replace', 'strpos',
                'header', 'file_exists', 'is_dir', 'is_file', 'mkdir', 'strtolower', 'strtoupper',
                'ucfirst', 'round', 'floor', 'ceil', 'max', 'min', 'abs', 'intval', 'floatval', 'strval'];
            foreach ($m[1] as $fn) {
                $fn = strtolower($fn);
                if (in_array($fn, $umum, true)) continue;
                $simbol[$fn] = true;
            }
        }
        /* (3) nama tabel basis data. */
        if (preg_match_all('~\b(?:FROM|JOIN|INTO|UPDATE|TABLE(?:\s+IF\s+NOT\s+EXISTS)?)\s+([a-z_][a-z0-9_]*)~i', $teks, $m)) {
            foreach ($m[1] as $tbl) {
                $tbl = strtolower($tbl);
                if (strlen($tbl) < 4) continue;
                $simbol[$tbl] = true;
            }
        }
        /* (4) kunci setelan + kode izin (permission). */
        if (preg_match_all('~set_setting\(\s*[\'"]([a-z0-9_]{4,})[\'"]|setting\(\s*[\'"]([a-z0-9_]{4,})[\'"]~i', $teks, $m)) {
            foreach (array_merge($m[1], $m[2]) as $k) if ($k !== '') $simbol[strtolower($k)] = true;
        }
        if (preg_match_all('~has_perm\(\s*[\'"]([a-z.]{4,})[\'"]|require_perm\(\s*[\'"]([a-z.]{4,})[\'"]~i', $teks, $m)) {
            foreach (array_merge($m[1], $m[2]) as $k) if ($k !== '') $simbol[strtolower($k)] = true;
        }
        /* (5) halaman .php yang ditautkan/redirect + action API. */
        if (preg_match_all('~[\'"]([a-z0-9_]{3,}\.php)[\'"]~i', $teks, $m)) {
            foreach ($m[1] as $pg) $simbol[strtolower($pg)] = true;
        }
    }
    /* Simbol dari permintaan juga ikut (mis. nama menu/tabel yang disebut pemilik). */
    if ($permintaan !== '' && preg_match_all('/[A-Za-z_]{5,}/', $permintaan, $m)) {
        foreach ($m[0] as $w) $simbol[strtolower($w)] = true;
    }
    /* Batasi jumlah simbol yang ditelusuri supaya tetap cepat (yang paling
       menjelaskan lebih dulu: nama yang lebih panjang = lebih spesifik). */
    $daftar = array_keys($simbol);
    usort($daftar, function ($a, $b) { return strlen($b) <=> strlen($a); });
    $daftar = array_slice($daftar, 0, 60);

    $index = ai_symbol_index();
    $skor = [];
    $alasan = [];
    $tidakAda = [];
    foreach ($daftar as $s) {
        $hit = $index[$s] ?? [];
        if (!$hit) { $tidakAda[] = $s; continue; }
        foreach ($hit as $rel) {
            if (in_array($rel, $sasaran, true)) continue;
            $skor[$rel] = ($skor[$rel] ?? 0) + 1;
            if (count($alasan[$rel] ?? []) < 5) $alasan[$rel][] = $s;
        }
    }
    arsort($skor);
    $terkait = [];
    foreach (array_keys($skor) as $rel) {
        if (count($terkait) >= max(1, $maks)) break;
        $terkait[$rel] = implode(', ', array_slice($alasan[$rel] ?? [], 0, 5));
    }
    return ['terkait' => $terkait, 'simbol' => array_slice($daftar, 0, 20), 'tidak_ada' => array_slice($tidakAda, 0, 10)];
}

/**
 * Apakah pesan dari kotak chat berarti "terapkan perubahan"?
 *
 * Dipakai jalur `action=pesan` (satu kotak chat): pemilik menulis
 * "lanjutkan dan terapkan" — dan itu diteruskan ke jalur penerapan yang SUDAH ada
 * (kata sandi + uji wajib lulus + salinan pengaman + konfirmasi), jadi tidak ada
 * pengaman yang dilonggarkan. Kalimat penolakan ("jangan terapkan dulu") TIDAK
 * dianggap perintah menerapkan.
 */
function ai_apply_command(string $teks): bool
{
    $t = strtolower(trim($teks));
    $t = trim((string)preg_replace('/[^a-z ]+/', ' ', $t));
    $t = trim((string)preg_replace('/\s+/', ' ', $t));
    if ($t === '' || strlen($t) > 60) return false;
    /* Penolakan eksplisit tidak boleh dianggap perintah menerapkan. */
    foreach (['jangan', 'belum', 'tidak', 'tolak', 'batalkan'] as $neg) {
        if (strpos($t, $neg) !== false) return false;
    }
    if (strpos($t, 'terapkan') !== false) return true;
    if (in_array($t, ['apply', 'terap'], true)) return true;
    return false;
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
        /* RONDE 50 — obrolan/analisa/rencana & audit yang belum lengkap.
           Status ini DIBEDAKAN dari 'noop' supaya UI tidak pernah menampilkan
           "tidak ada perubahan yang perlu dilakukan" padahal AI sedang menjawab,
           atau padahal auditnya belum lengkap (permintaan pemilik: status mesin &
           status tampilan harus konsisten). */
        'answered'   => ['AI menjawab (tanpa mengubah berkas)', 'blue'],
        'analyzed'   => ['Pemeriksaan/audit selesai (tanpa mengubah berkas)', 'blue'],
        'planned'    => ['Rencana siap (belum ada perubahan)', 'blue'],
        'audit_incomplete' => ['Audit BELUM lengkap — belum dapat disimpulkan', 'yellow'],
        'blocked'    => ['Terhenti — butuh keputusan Anda', 'yellow'],
        /* RONDE 51: AI sedang memperbaiki error yang ditemukan uji (self-healing). */
        'healing'    => ['AI memperbaiki error sendiri lalu menguji ulang', 'yellow'],
        /* RONDE 64c: pekerjaan yang dihentikan (tombol Berhenti) ATAU yang
           terputus di tengah jalan. Tanpa label ini, kartu riwayat menampilkan
           kata mentah "cancelled" dalam huruf kecil. */
        'cancelled'  => ['Dihentikan — pekerjaan tidak dilanjutkan', 'gray'],
    ][$status] ?? [$status, 'gray'];
}

/**
 * Status workflow RESMI (satu sumber kebenaran untuk mesin & tampilan).
 *
 * Permintaan pemilik (V2.3 bagian 4): status internal, status workflow, status
 * UI, dan hasil akhir harus dari sumber yang sama — jangan sampai mesin
 * menyatakan audit belum lengkap tetapi layar menampilkan "tidak ada perubahan".
 *
 * @return array{kode:string,label:string,tone:string,fase:string}
 */
function ai_workflow_status(string $status): array
{
    $peta = [
        'draft'      => ['DRAFT', 'Menunggu dijalankan', 'gray', 'REQUEST'],
        'analyzing'  => ['IMPLEMENTING', 'Sedang mengerjakan (menelusuri & menyusun)', 'yellow', 'DISCOVER'],
        'answered'   => ['ANSWERED', 'Dijawab (tanpa perubahan berkas)', 'blue', 'ANSWER'],
        'analyzed'   => ['ANALYSIS_COMPLETE', 'Pemeriksaan selesai', 'blue', 'AUDIT'],
        'planned'    => ['PLAN_READY', 'Rencana siap', 'blue', 'PLAN'],
        'audit_incomplete' => ['AUDIT_INCOMPLETE', 'Audit belum lengkap', 'yellow', 'AUDIT'],
        'proposed'   => ['WAITING_APPROVAL', 'Menunggu persetujuan Anda', 'blue', 'REVIEW'],
        'testing'    => ['TESTING', 'Sedang diuji di salinan', 'yellow', 'TEST'],
        'tested'     => ['TESTED', 'Selesai diuji — siap diterapkan', 'green', 'VALIDATE'],
        'applied'    => ['COMPLETED', 'Selesai & sudah diterapkan', 'green', 'COMPLETED'],
        'noop'       => ['NO_CHANGE', 'Tidak ada perubahan yang diperlukan', 'blue', 'COMPLETED'],
        'blocked'    => ['BLOCKED', 'Terhenti — butuh keputusan', 'yellow', 'BLOCKED'],
        'healing'    => ['HEALING', 'Memperbaiki error sendiri (auto recovery)', 'yellow', 'HEAL'],
        'failed'     => ['FAILED', 'Gagal', 'red', 'FAILED'],
        'rejected'   => ['REJECTED', 'Ditolak', 'gray', 'CLOSED'],
        'rolledback' => ['ROLLEDBACK', 'Dibatalkan', 'gray', 'CLOSED'],
        /* RONDE 64c: pekerja yang dihentikan pemilik (atau terputus) — dulu jatuh ke
           cabang bawaan sehingga lencananya berbunyi "CANCELLED" dengan label
           "cancelled" (huruf kecil). */
        'cancelled'  => ['CANCELLED', 'Dihentikan — tidak dilanjutkan', 'gray', 'CLOSED'],
    ];
    $p = $peta[$status] ?? [strtoupper($status), $status, 'gray', '-'];
    return ['kode' => $p[0], 'label' => $p[1], 'tone' => $p[2], 'fase' => $p[3]];
}
