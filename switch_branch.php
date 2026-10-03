<?php
/** Super Admin & Direktur/Owner: memilih cakupan cabang yang sedang dilihat
 *  (satu cabang, atau semua cabang). Ini hanya mengubah cakupan TAMPILAN —
 *  tidak mengubah data. */
require_once __DIR__ . '/includes/config.php';
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}
verify_csrf();
if (!is_owner_level($u)) {
    deny('Hanya Super Admin dan Direktur/Owner yang dapat berpindah cakupan cabang.');
}
$bid = trim((string)($_POST['branch_id'] ?? ''));
$back = (string)($_POST['back'] ?? ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
$back = strpos($back, '://') !== false ? 'dashboard.php' : $back;
if ($bid === '') {
    unset($_SESSION['active_branch']);
    $_SESSION['active_branch_name'] = null;
} else {
    $b = one('SELECT * FROM branches WHERE id = ?', [(int)$bid]);
    if (!$b) deny('Cabang tidak ditemukan.');
    $_SESSION['active_branch'] = (int)$b['id'];
}
flash('Cakupan cabang diperbarui.');
header('Location: ' . $back);
exit;
