<?php
/** Standalone maintenance UI: no normal bootstrap, migration, or clinical write. */
declare(strict_types=1);
if(PHP_SAPI==='cli')throw new RuntimeException('Portal maintenance hanya untuk HTTP.');
$root=$systemRoot??nv_system_root(APP_DIR);$app=$systemApp??(defined('APP_DIR')?APP_DIR:dirname(__DIR__));
$state=json_decode((string)file_get_contents($root.'/maintenance.json'),true);
$id=(string)($state['id']??'');if(!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/D',$id)){http_response_code(503);exit('Marker maintenance tidak sah.');}
$dbRoot=(string)getenv('NAVEENA_DB_ROOT');if($dbRoot==='')$dbRoot=dirname($app).'/naveena_storage';
$central=$dbRoot.'/databases/central.sqlite';$error='';$user=null;$p=null;
if(session_status()!==PHP_SESSION_ACTIVE){foreach([dirname($app).'/naveena_sessions',rtrim(sys_get_temp_dir(),'/').'/naveena_sessions'] as $dir)if(is_dir($dir)&&is_writable($dir)){session_save_path($dir);break;}session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax']);session_name('naveena_sid');session_start();}
$_SESSION['nv_system_csrf']??=bin2hex(random_bytes(32));
function nv_portal_escape($s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
try{if(!is_file($central))throw new RuntimeException('Central tidak tersedia; gunakan recovery CLI.');$p=new PDO('sqlite:'.$central,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$p->exec('PRAGMA query_only=ON');
    $uid=(int)($_SESSION['nv_system_admin_uid']??$_SESSION['user_id']??0);$seen=(int)($_SESSION['nv_system_admin_seen']??$_SESSION['last_seen']??0);
    if($uid&&$seen>time()-1800){$q=$p->prepare("SELECT u.* FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active' AND r.code='super_admin'");$q->execute([$uid]);$user=$q->fetch()?:null;}
}catch(Throwable $e){$error=$e->getMessage();}
$record=json_decode((string)file_get_contents($root.'/history/'.$id.'.json'),true);unset($record['worker_token']);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        if(!hash_equals($_SESSION['nv_system_csrf'],(string)($_POST['_csrf']??'')))throw new RuntimeException('Token form tidak sah.');
        if(!$p)throw new RuntimeException('Database tidak tersedia.');
        $lock=fopen($root.'/portal-auth.lock','c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Autentikasi sedang sibuk.');
        try{
            $rateFile=$root.'/portal-rate-'.hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'')).'.json';$rate=is_file($rateFile)?json_decode((string)file_get_contents($rateFile),true):[];
            if((int)($rate['until']??0)>time())throw new RuntimeException('Terlalu banyak percobaan. Tunggu 2 menit.');
            $q=$p->prepare("SELECT u.* FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? AND u.status='active' AND r.code='super_admin'");$q->execute([(string)($_POST['email']??$user['email']??'')]);$candidate=$q->fetch();
            $ok=$candidate&&password_verify((string)($_POST['password']??''),$candidate['password_hash']);
            if($ok&&(int)($candidate['totp_enabled']??0)===1&&($candidate['totp_confirmed_at']??'')!==''&&($candidate['totp_secret']??'')!==''){require_once $root.'/maintenance-qr.php';$ok=totp_verify($candidate['totp_secret'],(string)($_POST['totp']??''));}
            if(!$ok){$rate['fail']=(int)($rate['fail']??0)+1;if($rate['fail']>=5){$rate['until']=time()+120;$rate['fail']=0;}file_put_contents($rateFile,json_encode($rate),LOCK_EX);throw new RuntimeException('Email/password atau kode authenticator tidak sesuai.');}
            file_put_contents($rateFile,'{}',LOCK_EX);session_regenerate_id(true);$user=$candidate;$_SESSION['nv_system_admin_uid']=(int)$user['id'];$_SESSION['nv_system_admin_seen']=time();
        }finally{flock($lock,LOCK_UN);fclose($lock);}
        if(($_POST['action']??'')==='recover'){
            if(($record['status']??'')!=='recovery_required')throw new RuntimeException('Recovery hanya tersedia setelah proses berhenti dan memerlukan pemulihan.');
            if(($_POST['confirmation']??'')!=='SAYA SETUJU')throw new RuntimeException('Ketik SAYA SETUJU.');
            require_once $root.'/rescue-engine.php';$engine=new NaveenaSystemUpdate($app,$root);
            $php=(string)getenv('NAVEENA_PHP_CLI');$ini=(string)getenv('NAVEENA_PHP_INI');if($php===''||!is_file($php))throw new RuntimeException('PHP CLI belum dikonfigurasi; gunakan recovery SSH.');
            $args=[$php];if($ini!=='')array_push($args,'-c',$ini);array_push($args,$root.'/rescue.php',$app,$id,$id);
            session_write_close();$engine->command($args,['NAVEENA_DB_ROOT'=>$dbRoot,'NAVEENA_SYSTEM_ROOT'=>$root]);header('Location: login.php');exit;
        }
        header('Location: update_system.php');exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
if(isset($_GET['status'])){if(!$user){http_response_code(403);exit;}session_write_close();header('Content-Type: application/json');echo json_encode(['status'=>$record['status'],'phase'=>$record['phase'],'progress'=>$record['progress'],'error'=>$record['error']??'']);exit;}
$company='Naveena';if($p){$q=$p->query("SELECT value FROM settings WHERE key='company_name'");$company=(string)($q->fetchColumn()?:'Naveena');}
http_response_code($user?200:503);header('Cache-Control: no-store');header('Retry-After: 120');
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mode Pemeliharaan · <?= nv_portal_escape($company) ?></title><style><?= (string)@file_get_contents($root.'/maintenance-css.txt') ?><?= (string)@file_get_contents($root.'/maintenance-theme.txt') ?></style></head><body class="auth-body maint-body"><div class="maint-wrap"><div class="maint-card"><div class="maint-top"><strong><?= nv_portal_escape($company) ?></strong><span class="maint-pill">Mode Pemeliharaan Aktif</span></div><h1 class="maint-title">Update &amp; Pemulihan Sistem</h1><div class="maint-msg">Sistem sedang diperiksa atau dipulihkan. Super Admin tetap dapat masuk ke pusat pemeliharaan. Transaksi klinik dibuka setelah source dan database terverifikasi aman.</div><?php if($error!==''):?><div class="alert alert-danger" role="alert"><?= nv_portal_escape($error) ?></div><?php endif;?>
<?php if($user):?><div class="notice">Super Admin: <?= nv_portal_escape($user['email']) ?></div><progress class="system-progress" max="100" value="<?= (int)($record['progress']??0) ?>"></progress><p id="portal-status" role="status"><?= nv_portal_escape(($record['status']??'').' · '.($record['phase']??'')) ?></p><?php if(($record['status']??'')==='recovery_required'):?><details><summary>Pulihkan Source Sebelum Pemasangan</summary><p>Database tidak diganti. Maintenance dilepas hanya setelah recovery lulus.</p><?php endif;?><?php endif;?>
<?php if(!$user||($record['status']??'')==='recovery_required'):?><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= nv_portal_escape($_SESSION['nv_system_csrf']) ?>"><input type="hidden" name="action" value="<?= $user?'recover':'login' ?>"><div class="field"><label for="portal-email">Email Super Admin</label><input class="input" type="email" id="portal-email" name="email" value="<?= nv_portal_escape($user['email']??'') ?>" required autocomplete="username"></div><div class="field"><label for="portal-password">Password</label><input class="input" type="password" id="portal-password" name="password" required autocomplete="current-password"></div><div class="field"><label for="portal-totp">Kode authenticator (jika 2FA aktif)</label><input class="input" id="portal-totp" name="totp" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></div><?php if($user):?><div class="field"><label for="portal-confirmation">Ketik SAYA SETUJU</label><input class="input" id="portal-confirmation" name="confirmation" pattern="SAYA SETUJU" required></div><?php endif;?><button class="btn btn-primary" type="submit"><?= $user?'Verifikasi & Pulihkan Source':'Masuk sebagai Super Admin' ?></button></form><?php endif;?><?php if($user&&($record['status']??'')==='recovery_required'):?></details><?php endif;?></div></div><?php if($user):?><script>setInterval(async()=>{try{const res=await fetch('update_system.php?status=1');if(res.redirected){location.href=res.url;return;}const r=await res.json();document.querySelector('progress').value=r.progress||0;document.getElementById('portal-status').textContent=r.status+' · '+r.phase;}catch(e){}},3000);</script><?php endif;?></body></html><?php exit;
