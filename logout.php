<?php
require_once __DIR__ . '/includes/config.php';
$u = current_user();
if ($u) audit('Logout', 'Auth', $u['id'], null, null, 'Logout');
/* Hapus juga token "Ingat saya" — tanpa ini, cookie akan langsung memasukkan
   pengguna kembali walaupun ia sudah menekan Keluar. */
remember_token_clear();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
}
session_destroy();
header('Location: login.php');
exit;
