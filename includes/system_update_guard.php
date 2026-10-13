<?php
/** A file lock held for the complete request drains transactions before deployment. */
declare(strict_types=1);
function nv_system_root(string $app): string {
    $custom=(string)getenv('NAVEENA_SYSTEM_ROOT');
    $dbRoot=(string)getenv('NAVEENA_DB_ROOT');
    return $custom!=='' ? rtrim($custom,'/\\') : ($dbRoot!=='' ? rtrim($dbRoot,'/\\').'/system' : dirname($app).'/naveena_system');
}
function nv_update_release_request_lock(): void {
    if(isset($GLOBALS['NV_SYSTEM_REQUEST_LOCK'])) { flock($GLOBALS['NV_SYSTEM_REQUEST_LOCK'],LOCK_UN); fclose($GLOBALS['NV_SYSTEM_REQUEST_LOCK']); unset($GLOBALS['NV_SYSTEM_REQUEST_LOCK']); }
}
function nv_system_request_guard(): void {
    $root=nv_system_root(APP_DIR);
    $marker=$root.'/maintenance.json';
    $script=basename((string)($_SERVER['SCRIPT_NAME']??''));
    $state=is_file($marker)?json_decode((string)file_get_contents($marker),true):null;
    $worker=$state && hash_equals((string)($state['worker_token']??''),(string)getenv('NAVEENA_UPDATE_WORKER_TOKEN')) && getenv('NAVEENA_UPDATE_WORKER_TOKEN')!==false;
    if($worker)return;
    if(is_file($marker)) {
        $portal=$root.'/maintenance-portal.php';
        if(is_file($portal)){ $systemRoot=$root;require $portal;exit; }
        http_response_code(503);header('Retry-After: 120');exit('Portal pemeliharaan belum tersedia. Hubungi Super Admin untuk recovery CLI.');
    }
    if(!is_dir($root)&&!@mkdir($root,0700,true)){http_response_code(503);exit('Folder penguncian sistem tidak dapat dibuat.');}
    $fh=fopen($root.'/process.lock','c+');
    if(!$fh || !flock($fh,LOCK_SH|LOCK_NB)){http_response_code(503);exit('Sistem dikunci untuk update. Coba kembali nanti.');}
    // Recheck after locking: the updater may have set maintenance while we waited.
    if(is_file($marker)){flock($fh,LOCK_UN);fclose($fh);http_response_code(503);exit('Sistem sedang diperbarui.');}
    $GLOBALS['NV_SYSTEM_REQUEST_LOCK']=$fh;
    register_shutdown_function('nv_update_release_request_lock');
}
