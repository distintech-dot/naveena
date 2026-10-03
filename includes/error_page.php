<?php
/** 403 / error page (included by deny()). */
$u = current_user();
$title = 'Akses Ditolak';
$active = '';
require_once __DIR__ . '/layout.php';
$useLayout = (bool)$u;
if ($useLayout) {
    page_head($title, $active);
} else {
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Akses Ditolak · ' . e(clinic_name()) . '</title><link rel="stylesheet" href="assets/css/app.css"></head><body class="auth-body"><div class="auth-wrap">';
}
echo '<div class="card deny-card"><div class="deny-ico">' . (function_exists('icon') ? icon('lock') : '') . '</div>';
echo '<h2>Akses Ditolak (403)</h2>';
echo '<p class="muted">' . e($msg) . '</p>';
echo '<a class="btn btn-primary" href="dashboard.php">Kembali ke Dashboard</a></div>';
if ($useLayout) {
    page_foot();
} else {
    echo '</div></body></html>';
}
