<?php
/** Offline Hostinger SSH / Windows CLI recovery. Copied outside public_html on initialization. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
try{
    if(count($argv)<4)throw new RuntimeException('Usage: php rescue.php APP_PATH UPDATE_ID CONFIRM_UPDATE_ID');
    [$script,$app,$id,$confirmation]=$argv;if($id!==$confirmation)throw new RuntimeException('Konfirmasi ID tidak cocok.');
    $root=__DIR__;require_once $root.'/rescue-engine.php';$engine=new NaveenaSystemUpdate($app,$root);$r=$engine->read($id);
    if(!in_array($r['status'],['processing','recovery_required'],true)||empty($r['source_snapshot']))throw new RuntimeException('Tidak ada pemulihan source tertunda untuk ID ini.');
    $marker=json_decode((string)file_get_contents($root.'/maintenance.json'),true,512,JSON_THROW_ON_ERROR);if($marker['id']!==$id)throw new RuntimeException('Marker update tidak cocok.');
    $dbRoot=(string)getenv('NAVEENA_DB_ROOT');if($dbRoot==='')$dbRoot=dirname($engine->app).'/naveena_storage';
    $p=new PDO('sqlite:'.$dbRoot.'/databases/central.sqlite');$p->exec('PRAGMA query_only=ON');$v=$p->query("SELECT value FROM settings WHERE key='schema_version'")->fetchColumn();
    $backup=$r['source_snapshot'];$zip=$root.'/archives/'.$backup['file'];$m=$engine->validate($zip,'source_backup');
    $backupRoot=(string)getenv('NAVEENA_BACKUP_DIR');
    if($backupRoot==='')$backupRoot=isset($r['database_snapshot']['path'])?dirname($r['database_snapshot']['path']):dirname($engine->app).'/naveena_backups';
    if(!is_file($backupRoot.'/'.$r['database_snapshot']['file']))throw new RuntimeException('Snapshot Database tidak ditemukan. Atur NAVEENA_BACKUP_DIR ke folder snapshot yang benar.');
    if($v!==$m['schema_max'])throw new RuntimeException('Skema Central sudah berubah. Gunakan source perbaikan yang kompatibel; jangan rollback database.');
    foreach($p->query('SELECT id FROM branches') as $b){$name='branch_'.str_pad((string)$b['id'],3,'0',STR_PAD_LEFT).'.sqlite';$path=$dbRoot.'/databases/branches/'.$name;if(!is_file($path))throw new RuntimeException('Database cabang tidak ditemukan.');$branch=new PDO('sqlite:'.$path);$code=(int)$branch->query('PRAGMA user_version')->fetchColumn();if($code!==($r['database_before'][$name]['code']??null))throw new RuntimeException('Skema cabang berubah. Pemulihan otomatis source lama ditolak.');}
    $h=$engine->lock();try{
        $dest=$root.'/staging/'.$id.'-rescue-'.bin2hex(random_bytes(3));$engine->extract($zip,$m,$dest);$engine->lint($dest,$m['files']);$engine->deploy($dest,$m);
        $job=$root.'/staging/'.$id.'-rescue-job.json';$report=$root.'/staging/'.$id.'-rescue-result.json';$engine->atomic($job,json_encode(['app'=>$engine->app,'manifest'=>$m,'db_snapshot'=>$r['database_snapshot'],'report'=>$report],JSON_THROW_ON_ERROR));
        $args=[PHP_BINARY];if(php_ini_loaded_file())array_push($args,'-c',php_ini_loaded_file());$env=['NAVEENA_UPDATE_WORKER_TOKEN'=>$marker['worker_token'],'NAVEENA_DB_ROOT'=>$dbRoot,'NAVEENA_SYSTEM_ROOT'=>$root,'NAVEENA_BACKUP_DIR'=>$backupRoot];
        $engine->command(array_merge($args,[$root.'/system_update_worker.php',$job]),$env);$r['rescue_verification']=json_decode((string)file_get_contents($report),true,512,JSON_THROW_ON_ERROR);if(empty($r['rescue_verification']['ok']))throw new RuntimeException('Verifikasi pemulihan gagal.');
        $r['status']='restored';$r['recovery_at']=date(DATE_ATOM);$r['recovery_method']='offline source restore; database not replaced';$engine->log($r,'Source dipulihkan melalui CLI; integrity/FK/preservasi/dashboard lolos',100);
        $engine->command(array_merge($args,[$root.'/system_update_worker.php',$job,'release']),$env);if(!unlink($root.'/maintenance.json'))throw new RuntimeException('Maintenance gagal dilepas.');echo 'Recovery verified. Database was not restored or replaced.'.PHP_EOL;
    }finally{$engine->unlock($h);}
}catch(Throwable $e){fwrite(STDERR,'Pemulihan gagal; maintenance tetap aktif: '.$e->getMessage().PHP_EOL);exit(1);}
