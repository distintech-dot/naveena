<?php
/**
 * Halaman "Mode Pemeliharaan" — juga dapat dibuka langsung oleh Super Admin
 * (mis. dari Pengaturan) untuk melihat/menjelaskan tampilan yang dilihat
 * pengguna lain. Bila pemeliharaan sedang TIDAK aktif, halaman ini
 * mengarahkan ke dashboard.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

if (!maintenance_on()) {
    flash('Mode pemeliharaan sedang tidak aktif — tidak ada pembatasan akses.', 'info');
    header('Location: dashboard.php');
    exit;
}

maintenance_page('Halaman ini menampilkan keadaan sistem saat mode pemeliharaan aktif.', 200);
