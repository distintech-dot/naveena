<?php
/**
 * PETUNJUK AKUN BAWAAN DI HALAMAN LOGIN.
 *
 * Permintaan pemilik: tampilan akun bawaan di halaman login jangan hanya
 * "semua atau tidak sama sekali" — harus bisa dipilih AKUN PERAN MANA yang
 * ditampilkan (mis. hanya Owner, Admin/Dokter, dan Kasir).
 *
 * Aturan keamanan yang dipakai modul ini:
 *   • Hanya akun yang SANDINYA MASIH SANDI BAWAAN yang ditampilkan. Begitu
 *     pemilik mengganti sandinya, akun itu otomatis hilang dari daftar ini —
 *     jadi petunjuk ini tidak pernah membocorkan sandi yang benar-benar dipakai.
 *   • Akun nonaktif tidak pernah ditampilkan.
 *   • Bila semua akun sudah berganti sandi, halaman login hanya menampilkan
 *     keterangan singkat (tidak ada email/sandi).
 */
declare(strict_types=1);

/** Daftar peran yang boleh muncul pada petunjuk login + labelnya. */
function login_hint_roles_all(): array
{
    return [
        'super_admin' => 'Super Admin',
        'direktur' => 'Direktur / Owner',
        'admin_dokter' => 'Admin / Dokter',
        'kasir' => 'Kasir',
    ];
}

/** Sandi bawaan bawaan sistem per peran (hanya dipakai untuk MEMERIKSA). */
function login_hint_default_passwords(): array
{
    return [
        'super_admin' => 'SuperAdmin#2025',
        'direktur' => 'Direktur#2025',
        'admin_dokter' => 'Admin#2025',
        'kasir' => 'Kasir#2025',
    ];
}

/** Peran yang dipilih pemilik untuk ditampilkan (bawaan: semua). */
function login_hint_roles(): array
{
    $all = array_keys(login_hint_roles_all());
    $raw = trim((string)setting('login_hint_roles', ''));
    if ($raw === '') return $all;                       // belum disetel → tampilkan semua
    $pick = array_values(array_intersect($all, array_map('trim', explode(',', $raw))));
    return $pick;
}

/** Apakah petunjuk akun bawaan ditampilkan sama sekali? */
function login_hint_enabled(): bool
{
    return setting('show_login_hint', '1') === '1';
}

/**
 * Akun yang boleh ditampilkan pada halaman login.
 *
 * @return array<int,array{role:string,role_label:string,name:string,email:string,password:string,branch:string}>
 */
function login_hint_accounts(): array
{
    if (!login_hint_enabled()) return [];
    $roles = login_hint_roles();
    if (!$roles) return [];
    $def = login_hint_default_passwords();
    $labels = login_hint_roles_all();
    try {
        $users = all('SELECT u.id, u.name, u.email, u.password_hash, u.branch_id, r.code AS role_code
                      FROM users u JOIN roles r ON r.id = u.role_id
                      WHERE u.status = "active" ORDER BY u.id');
    } catch (Throwable $e) {
        return [];
    }
    $branchName = [];
    foreach (branches() as $b) $branchName[(int)$b['id']] = (string)$b['name'];
    $out = [];
    foreach ($users as $u) {
        $rc = (string)$u['role_code'];
        if (!in_array($rc, $roles, true)) continue;
        $pwd = (string)($def[$rc] ?? '');
        if ($pwd === '') continue;
        /* HANYA bila sandinya masih sandi bawaan (bukti pemilik belum mengganti). */
        if (!password_verify($pwd, (string)$u['password_hash'])) continue;
        $out[] = [
            'role' => $rc,
            'role_label' => (string)($labels[$rc] ?? $rc),
            'name' => (string)$u['name'],
            'email' => (string)$u['email'],
            'password' => $pwd,
            'branch' => $u['branch_id'] !== null ? (string)($branchName[(int)$u['branch_id']] ?? '') : '',
        ];
    }
    return $out;
}

/** Ringkas akun bawaan per peran (maks. $maxPerRole baris per peran). */
function login_hint_grouped(int $maxPerRole = 3): array
{
    $out = [];
    foreach (login_hint_accounts() as $a) {
        $key = $a['role'];
        if (!isset($out[$key])) $out[$key] = ['label' => $a['role_label'], 'items' => [], 'total' => 0];
        $out[$key]['total']++;
        if (count($out[$key]['items']) < $maxPerRole) $out[$key]['items'][] = $a;
    }
    return $out;
}
