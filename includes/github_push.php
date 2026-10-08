<?php
/** Push perubahan aplikasi ke GitHub melalui Git Database API (tanpa shell/git lokal). */
declare(strict_types=1);

/**
 * Alamat API GitHub yang dipakai.
 *
 * Dapat diarahkan lewat setelan `github_api_base` (mis. untuk GitHub Enterprise
 * atau SERVER TIRUAN pada pengujian otomatis) — tanpa ini, jalur push tidak dapat
 * diuji tanpa memakai repository & token sungguhan.
 */
function github_api_base(): string
{
    $b = trim((string)setting('github_api_base', ''));
    if ($b === '') return 'https://api.github.com';
    if (!preg_match('~^https?://[A-Za-z0-9._:\-/]+$~', $b)) return 'https://api.github.com';
    return rtrim($b, '/');
}

function github_push_api(string $method, string $path, string $token, ?array $payload = null): array
{
    $url = github_api_base() . $path;
    $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $headers = ['Accept: application/vnd.github+json', 'Authorization: Bearer ' . $token,
        'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: Naveena-Clinic-App'];
    if ($body !== '') $headers[] = 'Content-Type: application/json';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45]);
        if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $netError = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException('Tidak dapat menghubungi GitHub: ' . short_text($netError, 180));
    } else {
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $body, 'timeout' => 45, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) $code = (int)$m[1];
        }
        if ($raw === false) throw new RuntimeException('Tidak dapat menghubungi GitHub. Periksa koneksi HTTPS server.');
    }
    $data = json_decode((string)$raw, true);
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        $message = is_array($data) ? (string)($data['message'] ?? '') : '';
        if ($message === '') $message = 'GitHub menolak permintaan (HTTP ' . $code . ').';
        $message = str_replace($token, '[token disamarkan]', $message);
        throw new RuntimeException('GitHub API: ' . short_text($message, 220));
    }
    return $data;
}

/**
 * Unggah beberapa blob SEKALIGUS (paralel) memakai curl_multi.
 *
 * KENAPA PENTING: versi lama mengunggah blob satu per satu. Untuk aplikasi ini
 * (130+ berkas) itu berarti 130 permintaan berurutan (~0,3 detik masing-masing)
 * sehingga proses melewati batas waktu PHP/web server dan permintaan terputus di
 * tengah — pengguna hanya melihat "Memproses push…" tanpa hasil, dan jejaknya pun
 * tidak sempat tercatat di Audit Log. Dengan paralel, seluruh unggahan selesai
 * dalam beberapa detik.
 *
 * @param array<int,array{path:string,content:string}> $berkas
 * @return array<string,array{sha:string,error:string}> path => sha/kesalahan
 */
function github_push_blobs_parallel(string $base, string $token, array $berkas, int $paralel = 8): array
{
    $hasil = [];
    if (!function_exists('curl_multi_init')) {
        /* Tanpa curl_multi: unggah berurutan (tetap benar, hanya lebih lambat). */
        foreach ($berkas as $b) {
            try {
                $r = github_push_api('POST', $base . '/git/blobs', $token,
                    ['content' => base64_encode($b['content']), 'encoding' => 'base64']);
                $hasil[$b['path']] = ['sha' => (string)$r['sha'], 'error' => ''];
            } catch (Throwable $e) {
                $hasil[$b['path']] = ['sha' => '', 'error' => $e->getMessage()];
            }
        }
        return $hasil;
    }

    $antre = array_values($berkas);
    $total = count($antre);
    $pos = 0;
    while ($pos < $total) {
        $mh = curl_multi_init();
        $aktif = [];
        for ($i = 0; $i < $paralel && $pos < $total; $i++, $pos++) {
            $b = $antre[$pos];
            $body = json_encode(['content' => base64_encode($b['content']), 'encoding' => 'base64'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ch = curl_init(github_api_base() . $base . '/git/blobs');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json',
                    'Authorization: Bearer ' . $token, 'X-GitHub-Api-Version: 2022-11-28',
                    'User-Agent: Naveena-Clinic-App', 'Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
            ]);
            curl_multi_add_handle($mh, $ch);
            $aktif[] = ['ch' => $ch, 'path' => $b['path']];
        }
        $jalan = null;
        do {
            $status = curl_multi_exec($mh, $jalan);
            if ($jalan > 0) curl_multi_select($mh, 0.5);
        } while ($jalan > 0 && $status === CURLM_OK);
        foreach ($aktif as $a) {
            $raw = (string)curl_multi_getcontent($a['ch']);
            $code = (int)curl_getinfo($a['ch'], CURLINFO_HTTP_CODE);
            $err = curl_error($a['ch']);
            curl_multi_remove_handle($mh, $a['ch']);
            curl_close($a['ch']);
            if ($err !== '') {
                $hasil[$a['path']] = ['sha' => '', 'error' => 'Tidak dapat menghubungi GitHub: ' . short_text($err, 160)];
                continue;
            }
            $data = json_decode($raw, true);
            if ($code < 200 || $code >= 300 || !is_array($data)) {
                $msg = is_array($data) ? (string)($data['message'] ?? '') : '';
                $msg = $msg !== '' ? $msg : 'GitHub menolak unggahan blob (HTTP ' . $code . ').';
                $hasil[$a['path']] = ['sha' => '', 'error' => str_replace($token, '[token disamarkan]', $msg)];
                continue;
            }
            $hasil[$a['path']] = ['sha' => (string)($data['sha'] ?? ''), 'error' => ''];
        }
        curl_multi_close($mh);
    }
    return $hasil;
}

function github_push_execute(): array
{
    $repoUrl = trim(setting('github_repo', ''));
    $token = trim(setting('github_token', ''));
    $branch = trim(setting('github_branch', 'main'));
    if ($repoUrl === '' || $token === '' || $branch === '') {
        throw new RuntimeException('Repository, branch, dan token GitHub harus disimpan terlebih dahulu.');
    }
    $parts = parse_url($repoUrl);
    if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string)($parts['host'] ?? '')) !== 'github.com') {
        throw new RuntimeException('URL repository tidak valid; gunakan https://github.com/pemilik/repository.');
    }
    $segments = array_values(array_filter(explode('/', trim((string)($parts['path'] ?? ''), '/')), 'strlen'));
    if (count($segments) !== 2) throw new RuntimeException('URL harus menunjuk tepat ke satu repository GitHub.');
    $owner = $segments[0];
    $name = preg_replace('/\.git$/i', '', $segments[1]);
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $owner) || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)
        || !preg_match('~^[A-Za-z0-9._/-]{1,120}$~', $branch) || strpos($branch, '..') !== false) {
        throw new RuntimeException('Nama repository atau branch GitHub tidak valid.');
    }
    @set_time_limit(0);
    $base = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name);

    /* ---- HEAD branch tujuan ----
       Repo yang MASIH KOSONG (belum punya satu commit pun) tidak memiliki ref,
       sehingga GitHub membalas 404 "Git Repository is empty". Dulu pesannya
       membingungkan; sekarang dijelaskan apa yang harus dilakukan pengguna. */
    try {
        $ref = github_push_api('GET', $base . '/git/ref/heads/' . rawurlencode($branch), $token);
    } catch (Throwable $e) {
        $m = strtolower($e->getMessage());
        if (strpos($m, 'empty') !== false || strpos($m, 'not found') !== false || strpos($m, '404') !== false) {
            throw new RuntimeException('Branch "' . $branch . '" belum ada di ' . $owner . '/' . $name
                . '. Repository yang benar-benar kosong belum punya branch tujuan — buat satu commit lebih dulu '
                . 'di GitHub (mis. tambahkan berkas README) lalu klik Push GitHub lagi.');
        }
        throw $e;
    }
    $headSha = (string)($ref['object']['sha'] ?? '');
    if ($headSha === '') throw new RuntimeException('GitHub tidak mengembalikan commit branch tujuan.');
    $commit = github_push_api('GET', $base . '/git/commits/' . rawurlencode($headSha), $token);
    $baseTree = (string)($commit['tree']['sha'] ?? '');
    if ($baseTree === '') throw new RuntimeException('Tree repository GitHub tidak dapat dibaca.');
    $remoteTree = github_push_api('GET', $base . '/git/trees/' . rawurlencode($baseTree) . '?recursive=1', $token);
    $remote = [];
    foreach ((array)($remoteTree['tree'] ?? []) as $entry) {
        if (($entry['type'] ?? '') === 'blob') $remote[(string)$entry['path']] = (string)$entry['sha'];
    }

    $root = realpath(APP_DIR);
    if ($root === false) throw new RuntimeException('Folder aplikasi tidak dapat dibaca.');
    $skipDirs = ['.git', 'storage', 'uploads', 'backups', 'cache', 'sessions'];
    $kandidat = [];
    $totalBytes = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $absolute = $file->getPathname();
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root) + 1));
        $partsPath = explode('/', $relative);
        if (array_intersect($partsPath, $skipDirs)) continue;
        $baseName = strtolower(basename($relative));
        if (in_array($baseName, ['.env', '.env.local', '.vibecoder-media-token'], true)
            || preg_match('/\.(sqlite|sqlite3|db|log|bak|zip)$/i', $baseName)) continue;
        $size = (int)$file->getSize();
        if ($size > 4 * 1024 * 1024) {
            throw new RuntimeException('Berkas terlalu besar untuk push langsung (maks 4 MB per berkas): ' . $relative);
        }
        $totalBytes += $size;
        /* Batas dinaikkan (dulu 250 berkas / 15 MB) karena aplikasi ini sendiri sudah
           melewatinya begitu fitur baru bertambah — dan unggahan kini paralel sehingga
           jumlah berkas bukan lagi masalah waktu. */
        if (count($kandidat) >= 1200 || $totalBytes > 60 * 1024 * 1024) {
            throw new RuntimeException('Push dibatasi maksimal 1.200 berkas dan 60 MB per proses. '
                . 'Bila aplikasi Anda lebih besar dari itu, lakukan push dalam beberapa tahap.');
        }
        $content = @file_get_contents($absolute);
        if ($content === false) throw new RuntimeException('Berkas aplikasi tidak dapat dibaca: ' . $relative);
        $blobSha = sha1('blob ' . strlen($content) . "\0" . $content);
        if (($remote[$relative] ?? '') === $blobSha) continue;      // sudah sama → lewati
        $kandidat[] = ['path' => $relative, 'content' => $content];
    }
    if (!$kandidat) {
        return ['commit' => $headSha, 'files' => 0, 'total_files' => count($remote),
            'message' => 'Tidak ada perubahan berkas aplikasi untuk dikirim — branch GitHub sudah sama dengan aplikasi.'];
    }

    /* ---- Unggah blob PARALEL ---- */
    $blobs = github_push_blobs_parallel($base, $token, $kandidat);
    $gagal = [];
    $tree = [];
    foreach ($kandidat as $b) {
        $h = $blobs[$b['path']] ?? ['sha' => '', 'error' => 'tidak ada jawaban dari GitHub'];
        if ($h['error'] !== '' || $h['sha'] === '') {
            $gagal[] = $b['path'] . ' (' . short_text($h['error'], 80) . ')';
            continue;
        }
        $tree[] = ['path' => $b['path'], 'mode' => '100644', 'type' => 'blob', 'sha' => $h['sha']];
    }
    if (!$tree) {
        throw new RuntimeException('Tidak ada berkas yang berhasil diunggah ke GitHub. '
            . 'Contoh kesalahan: ' . implode(' | ', array_slice($gagal, 0, 2)));
    }

    $newTree = github_push_api('POST', $base . '/git/trees', $token,
        ['base_tree' => $baseTree, 'tree' => $tree]);
    $message = 'Push dari aplikasi: ' . date('Y-m-d H:i:s');
    $newCommit = github_push_api('POST', $base . '/git/commits', $token,
        ['message' => $message, 'tree' => (string)$newTree['sha'], 'parents' => [$headSha]]);
    $newSha = (string)($newCommit['sha'] ?? '');
    if ($newSha === '') throw new RuntimeException('GitHub tidak mengembalikan commit baru.');
    github_push_api('PATCH', $base . '/git/refs/heads/' . rawurlencode($branch), $token,
        ['sha' => $newSha, 'force' => false]);
    $pesan = 'Push berhasil ke ' . $owner . '/' . $name . ' (' . $branch . '): '
        . count($tree) . ' berkas diperbarui. Commit ' . substr($newSha, 0, 12) . '.';
    if ($gagal) $pesan .= ' ' . count($gagal) . ' berkas dilewati karena gagal diunggah.';
    return ['commit' => $newSha, 'files' => count($tree), 'total_files' => count($remote) + count($tree),
        'skipped' => $gagal, 'message' => $pesan];
}
