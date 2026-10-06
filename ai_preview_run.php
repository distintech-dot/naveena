<?php
/**
 * PENJALAN PRATINJAU — hanya untuk baris perintah (CLI).
 * =====================================================
 *
 * Dipanggil `ai_preview_render()` (includes/ai_preview.php) untuk MENJALANKAN satu
 * halaman dari SALINAN PRATINJAU aplikasi dan mengembalikan HTML-nya. Halaman yang
 * dijalankan memakai basis data salinan di dalam folder staging, jadi pratinjau
 * tidak mungkin mengubah data produksi.
 *
 * Berkas ini MENOLAK dijalankan dari peramban (hanya CLI) — sama seperti ai_worker.php.
 *
 * Pemakaian:
 *   php ai_preview_run.php <folder_app> <halaman.php> <id_user> <id_tugas> <query> <berkas_post|-> 
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Berkas ini hanya dapat dijalankan dari baris perintah.';
    exit(1);
}

$argvv = $argv ?? [];
$app = (string)($argvv[1] ?? '');
$page = (string)($argvv[2] ?? '');
$userId = (int)($argvv[3] ?? 1);
$taskId = (int)($argvv[4] ?? 0);
$query = (string)($argvv[5] ?? '');
$postFile = (string)($argvv[6] ?? '-');

if ($app === '' || !is_dir($app) || !preg_match('~^[A-Za-z0-9_\-]+\.php$~', $page) || !is_file($app . '/' . $page)) {
    fwrite(STDERR, "Pemakaian tidak sah.\n");
    exit(1);
}

/* Sesi pratinjau yang STABIL per tugas: token CSRF di dalam halaman tetap sama
   antar permintaan, dan sesi ini berada di folder salinan (bukan sesi aplikasi). */
session_id('aiprev' . $taskId . 'x' . substr(hash('sha256', __DIR__), 0, 8));
$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = ($postFile !== '-') ? 'POST' : 'GET';
$_SERVER['SCRIPT_NAME'] = '/' . $page;
$_SERVER['PHP_SELF'] = '/' . $page;
$_SERVER['REQUEST_URI'] = '/' . $page . ($query !== '' ? '?' . $query : '');
$_SERVER['QUERY_STRING'] = $query;
$_SERVER['HTTP_HOST'] = (string)($_SERVER['HTTP_HOST'] ?? 'pratinjau');
$_SERVER['SERVER_NAME'] = 'pratinjau';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Naveena-Pratinjau/1.0';
$_SERVER['HTTPS'] = 'on';
/* Basis data & folder unggahan salinan (di dalam folder staging). Folder unggahan
   berisi SALINAN logo/latar kartu saja — foto pasien sengaja tidak disalin supaya
   pratinjau tidak pernah menyentuh berkas produksi. */
$appDir = realpath($app) ?: $app;
putenv('NAVEENA_DB=' . dirname($appDir) . '/naveena_data/data.sqlite');
putenv('NAVEENA_UPLOAD_DIR=' . dirname($appDir) . '/naveena_uploads');
putenv('NAVEENA_BACKUP_DIR=' . dirname($appDir) . '/naveena_backups');

/* $_POST dari berkas sementara (dikirim pemanggil, bukan lewat argumen). */
if ($postFile !== '-' && is_file($postFile)) {
    $j = json_decode((string)file_get_contents($postFile), true);
    $_POST = is_array($j) ? $j : [];
    $_REQUEST = array_merge($_GET, $_POST);
}

/* Konstanta ini dibaca includes/config.php untuk mengisi sesi sebagai Super Admin. */
define('AI_PREVIEW_USER_ID', $userId);

$__out = '';
register_shutdown_function(function () use (&$__out, $page) {
    $isi = ob_get_level() > 0 ? (string)ob_get_clean() : '';
    $__out .= $isi;
    /* Keluaran halaman diteruskan ke stdout (buffer ditutup tepat di sini). */
    echo $__out;
    /* Pengalihan (header Location) di CLI tidak dikirim ke peramban — diteruskan
       lewat penanda khusus supaya router pratinjau bisa mengalihkan iframe. */
    foreach (headers_list() as $h) {
        if (stripos($h, 'Location:') === 0) {
            echo "\n<!--AIPREVIEW-REDIRECT:" . trim(substr($h, 9)) . '-->';
            break;
        }
    }
});

chdir($app);
ob_start();
try {
    include $app . '/' . $page;
} catch (Throwable $e) {
    $isi = ob_get_level() > 0 ? (string)ob_get_clean() : '';
    ob_start();
    echo $isi;
    echo "\n<!--AIPREVIEW-ERROR:" . str_replace('-->', '', $e->getMessage()) . '-->';
}
