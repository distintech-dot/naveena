<?php
/**
 * GAMBAR LATAR WEB (wallpaper) — ACAK SETIAP HALAMAN DIBUKA.
 * ==========================================================
 * Permintaan pemilik: di Pengaturan Sistem (di bawah kartu Identitas Klinik) dapat
 * mengatur **gambar latar web** yang otomatis BERUBAH setiap kali halaman dimuat
 * ulang, memakai **tautan gambar daring** (bukan unggahan) supaya hemat ruang
 * penyimpanan, dengan pilihan:
 *   • ditampilkan di MANA: nonaktif · halaman login & ubah kata sandi · semua web;
 *   • TEMA gambar: kecantikan · treatment · skincare · kesehatan ·
 *     kecantikan+treatment+skincare (campuran).
 *
 * CATATAN PENTING soal gambar bawaan: daftar bawaan memakai gambar dari Unsplash
 * (tautan langsung ke CDN mereka) — gratis dipakai dan memang diizinkan untuk
 * ditampilkan lewat tautan. Seluruh tautan bawaan SUDAH DIVERIFIKASI hidup
 * (HTTP 200, image/*) saat fitur ini dibuat; izin & ketersediaannya sepenuhnya
 * milik penyedianya, jadi pemilik dapat menggantinya dengan tautan sendiri.
 *
 * Pemilihan gambar dilakukan di SISI SERVER (satu gambar per permintaan halaman),
 * sehingga setiap penyegaran halaman menampilkan gambar yang berbeda tanpa perlu
 * JavaScript — dan tidak ada berkas yang disimpan di server.
 */

/** Pilihan tema gambar (kunci => label). */
function wallpaper_categories(): array
{
    return [
        'campuran'  => 'Kecantikan + Treatment + Skincare',
        'kecantikan' => 'Kecantikan',
        'treatment' => 'Treatment',
        'skincare'  => 'Skincare',
        'kesehatan' => 'Kesehatan / Wellness',
    ];
}

/** Pilihan tempat pemakaian. */
function wallpaper_modes(): array
{
    return [
        'off'   => 'Nonaktif',
        'login' => 'Halaman login & ubah kata sandi saja',
        'all'   => 'Semua halaman web',
    ];
}

/**
 * Daftar gambar BAWAAN per tema (tautan daring, tanpa unggahan).
 * Semuanya sudah diperiksa hidup saat fitur dibuat.
 */
function wallpaper_default_urls(string $kategori): array
{
    $data = [
        'kecantikan' => [
            'photo-1580870069867-74c57ee1bb07', 'photo-1570172619644-dfd03ed5d881',
            'photo-1616394584738-fc6e612e71b9', 'photo-1620916297397-a4a5402a3c6c',
            'photo-1599847987657-881f11b92a75', 'photo-1631730486572-226d1f595b68',
            'photo-1670201203208-055d6d79db4a', 'photo-1560066984-138dadb4c035',
            'photo-1512496015851-a90fb38ba796', 'photo-1522335789203-aabd1fc54bc9',
        ],
        'treatment' => [
            'photo-1570172619644-dfd03ed5d881', 'photo-1596178065887-1198b6148b2b',
            'photo-1519823551278-64ac92734fb1', 'photo-1616394584738-fc6e612e71b9',
            'photo-1665763630810-e6251bdd392d', 'photo-1540555700478-4be289fbecef',
            'photo-1552693673-1bf958298935', 'photo-1526947425960-945c6e72858f',
            'photo-1519415943484-9fa1873496d4', 'photo-1600334089648-b0d9d3028eb2',
        ],
        'skincare' => [
            'photo-1620916297397-a4a5402a3c6c', 'photo-1556228578-8c89e6adf883',
            'photo-1571781926291-c477ebfd024b', 'photo-1612817288484-6f916006741a',
            'photo-1608248543803-ba4f8c70ae0b', 'photo-1617897903246-719242758050',
            'photo-1631730359585-38a4935cbec4', 'photo-1598440947619-2c35fc9aa908',
            'photo-1556228720-195a672e8a03', 'photo-1596755389378-c31d21fd1273',
        ],
        'kesehatan' => [
            'photo-1544367567-0f2fcb009e0b', 'photo-1506126613408-eca07ce68773',
            'photo-1518611012118-696072aa579a', 'photo-1600334129128-685c5582fd35',
            'photo-1571019613454-1cb2f99b2d8b', 'photo-1490645935967-10de6ba17061',
            'photo-1545205597-3d9d02c29597', 'photo-1571902943202-507ec2618e8f',
            'photo-1512290923902-8a9f81dc236c', 'photo-1505576399279-565b52d4ac71',
        ],
    ];
    $base = 'https://images.unsplash.com/';
    $suffix = '?auto=format&fit=crop&w=1600&q=80';
    $kategori = strtolower(trim($kategori));
    if ($kategori === 'campuran') {
        /* Campuran = gabungan tiga tema yang diminta pemilik. */
        $gabung = array_merge($data['kecantikan'], $data['treatment'], $data['skincare']);
        $out = [];
        foreach ($gabung as $id) $out[] = $base . $id . $suffix;
        return array_values(array_unique($out));
    }
    if (!isset($data[$kategori])) return [];
    $out = [];
    foreach ($data[$kategori] as $id) $out[] = $base . $id . $suffix;
    return $out;
}

/** Mode pemakaian yang berlaku (dinormalkan). */
function wallpaper_mode(): string
{
    $m = (string)setting('wallpaper_mode', 'off');
    return isset(wallpaper_modes()[$m]) ? $m : 'off';
}

/** Tema gambar yang berlaku (dinormalkan). */
function wallpaper_category(): string
{
    $c = (string)setting('wallpaper_category', 'campuran');
    return isset(wallpaper_categories()[$c]) ? $c : 'campuran';
}

/**
 * Tautan gambar milik pemilik (setelan `wallpaper_urls`, satu per baris).
 * Hanya alamat http/https yang diterima — nilai lain diabaikan supaya CSS/atribut
 * tidak dapat disusupi dari kolom pengaturan.
 */
function wallpaper_custom_urls(): array
{
    $raw = (string)setting('wallpaper_urls', '');
    if (trim($raw) === '') return [];
    $out = [];
    foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
        $u = trim($line);
        if ($u === '' || strlen($u) > 500) continue;
        /* Hanya http/https yang diterima, dan harus benar-benar alamat yang sah.
           PENTING: validasinya TIDAK memakai pola dengan pembatas `~` karena
           karakter itu juga ada di dalam kelas karakter alamat — pola seperti itu
           ditolak PHP ("Unknown modifier") sehingga SEMUA tautan ikut tertolak
           (jebakan yang pernah terjadi). */
        if (!preg_match('#^https?://#i', $u)) continue;
        if (filter_var($u, FILTER_VALIDATE_URL) === false) continue;
        /* Tolak karakter yang dapat memutus nilai `url(...)` pada CSS. */
        if (strpbrk($u, "'\"()\\ \t") !== false) continue;
        $out[] = $u;
    }
    return array_values(array_unique($out));
}

/** Kumpulan gambar yang dipakai: tautan pemilik bila ada, kalau tidak bawaan tema. */
function wallpaper_pool(): array
{
    $custom = wallpaper_custom_urls();
    if ($custom) return $custom;
    return wallpaper_default_urls(wallpaper_category());
}

/**
 * Pilih SATU gambar secara acak (berubah setiap halaman dimuat).
 * Dikunci per-permintaan supaya satu halaman memakai gambar yang SAMA walau
 * fungsi ini dipanggil beberapa kali.
 */
function wallpaper_pick(): string
{
    static $pilih = null;
    if ($pilih !== null) return $pilih;
    $pool = wallpaper_pool();
    if (!$pool) { $pilih = ''; return $pilih; }
    $pilih = $pool[random_int(0, count($pool) - 1)];
    return $pilih;
}

/** Halaman yang dianggap "halaman masuk" (login & ubah kata sandi). */
function wallpaper_auth_pages(): array
{
    return ['login.php', 'lupa_password.php', 'reset_password.php', 'two_factor.php'];
}

/** Nama berkas halaman yang sedang dibuka (tanpa query). */
function wallpaper_current_page(): string
{
    $s = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $b = basename($s);
    return $b !== '' ? $b : 'index.php';
}

/**
 * Apakah gambar latar dipakai di halaman ini?
 *
 * @param bool $authPage paksa dianggap halaman masuk (dipakai halaman login itu sendiri)
 */
function wallpaper_show_on(bool $authPage = false): bool
{
    $mode = wallpaper_mode();
    if ($mode === 'off') return false;
    $isAuth = $authPage || in_array(wallpaper_current_page(), wallpaper_auth_pages(), true);
    if ($mode === 'login') return $isAuth;
    return true;                              // 'all'
}

/**
 * Blok CSS gambar latar (kosong bila tidak dipakai di halaman ini).
 *
 * Lapisan warna transparan di atas gambar menjaga tulisan tetap terbaca
 * (halaman masuk lebih terang, halaman aplikasi lebih pekat karena banyak kartu).
 * Tidak ikut tercetak (`@media print`).
 */
function wallpaper_style_tag(bool $authPage = false): string
{
    if (!wallpaper_show_on($authPage)) return '';
    $url = wallpaper_pick();
    if ($url === '') return '';
    /* Kutip tunggal & tanda kurung sudah disaring pada wallpaper_custom_urls();
       urlencode tetap dipakai untuk karakter yang bisa memutus url() di CSS. */
    $aman = str_replace(["\\", "'", '(', ')'], ['%5C', '%27', '%28', '%29'], $url);
    $overlay = $authPage || in_array(wallpaper_current_page(), wallpaper_auth_pages(), true) ? .45 : .86;
    $css = 'body{background-image:linear-gradient(rgba(243,240,255,' . $overlay . '),rgba(243,240,255,' . $overlay . ')),'
        . "url('" . $aman . "');background-size:cover,cover;background-position:center center,center center;"
        . 'background-attachment:fixed,fixed;background-repeat:no-repeat,no-repeat;}'
        . '@media print{body{background-image:none !important}}'
        . 'body.auth-body{background-image:linear-gradient(rgba(243,240,255,' . $overlay . '),rgba(243,240,255,' . $overlay . ')),'
        . "url('" . $aman . "');}";
    return '<style id="bgWallpaper">' . $css . '</style>';
}
