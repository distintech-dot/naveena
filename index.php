<?php
require_once __DIR__ . '/includes/config.php';
header('Location: ' . (current_user() ? 'dashboard.php' : 'login.php'));
exit;
