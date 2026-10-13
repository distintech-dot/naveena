<?php
declare(strict_types=1);
require_once __DIR__.'/system_update_guard.php';

/** Standalone engine: its journal and rescue runtime live outside application and SQLite. */
final class NaveenaSystemUpdate {
    public string $app;
    public string $root;
    private ?Closure $fault;
    public array $deploymentWarnings=[];
    public const REQUIRED=['index.php','login.php','includes/config.php','includes/schema.php','includes/db_manager.php','includes/db_route.php','includes/system_update_guard.php','includes/system_update.php','includes/system_update_worker.php','includes/system_update_rescue.php','includes/system_snapshot.php','update_system.php','app-version.json'];
    public function __construct(string $app, ?string $root=null, ?Closure $fault=null) {
        $this->app=str_replace('\\','/',(string)realpath($app));
        if($this->app==='')throw new RuntimeException('Folder aplikasi tidak ditemukan.');
        $this->root=str_replace('\\','/',$root??nv_system_root($this->app));$this->fault=$fault;
        if(!is_dir($this->root)&&!mkdir($this->root,0700,true))throw new RuntimeException('Folder sistem tidak dapat dibuat.');
        $this->root=str_replace('\\','/',(string)realpath($this->root));
        if(str_starts_with(strtolower($this->root.'/'),strtolower($this->app.'/')))throw new RuntimeException('Penyimpanan updater wajib di luar folder publik.');
        $documentRoot=(string)($_SERVER['DOCUMENT_ROOT']??'');
        $docroot=$documentRoot!==''?str_replace('\\','/',(string)realpath($documentRoot)):'';
        if($docroot!==''&&str_starts_with(strtolower($this->root.'/'),strtolower(rtrim($docroot,'/').'/')))throw new RuntimeException('Folder updater berada dalam DOCUMENT_ROOT. Atur NAVEENA_SYSTEM_ROOT ke lokasi di luar web root.');
        foreach(['history','archives','staging','rollback'] as $d)if(!is_dir($this->root.'/'.$d)&&!mkdir($this->root.'/'.$d,0700,true))throw new RuntimeException('Gagal membuat folder '.$d);
        if(!is_file($this->root.'/backup.key')){$keyFile=@fopen($this->root.'/backup.key','xb');if($keyFile){$keyBytes=bin2hex(random_bytes(32));if(fwrite($keyFile,$keyBytes)!==64){fclose($keyFile);throw new RuntimeException('Kunci backup gagal dibuat.');}fclose($keyFile);@chmod($this->root.'/backup.key',0600);}}
        if(strlen((string)file_get_contents($this->root.'/backup.key'))!==64)throw new RuntimeException('Kunci backup instalasi tidak lengkap.');
        // Independent recovery can still load the last known updater if new PHP bootstrap fails.
        if(!is_file($this->root.'/rescue-engine.php'))copy(__FILE__,$this->root.'/rescue-engine.php');
        if(!is_file($this->root.'/system_update_guard.php'))copy(__DIR__.'/system_update_guard.php',$this->root.'/system_update_guard.php');
        if(!is_file($this->root.'/rescue.php')&&is_file(__DIR__.'/system_update_rescue.php'))copy(__DIR__.'/system_update_rescue.php',$this->root.'/rescue.php');
        if(!is_file($this->root.'/system_update_worker.php')&&is_file(__DIR__.'/system_update_worker.php'))copy(__DIR__.'/system_update_worker.php',$this->root.'/system_update_worker.php');
        if(!is_file($this->root.'/maintenance-portal.php')&&is_file(__DIR__.'/system_maintenance_portal.php'))copy(__DIR__.'/system_maintenance_portal.php',$this->root.'/maintenance-portal.php');
        if(!is_file($this->root.'/maintenance-qr.php')&&is_file(__DIR__.'/qr.php'))copy(__DIR__.'/qr.php',$this->root.'/maintenance-qr.php');
    }
    private function failpoint(string $point):void {if($this->fault)($this->fault)($point);}
    public function atomic(string $path,string $bytes):void {
        $temp=$path.'.'.bin2hex(random_bytes(5)).'.part';
        if(file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes)){@unlink($temp);throw new RuntimeException('Penulisan tidak lengkap (kapasitas/permission).');}
        if(!rename($temp,$path)){@unlink($temp);throw new RuntimeException('Gagal menyimpan berkas secara aman.');}
        @chmod($path,0600);
    }
    private function copyAtomic(string $from,string $to):void {
        $temp=$to.'.'.bin2hex(random_bytes(5)).'.part';clearstatcache(true,$from);
        $size=filesize($from);$expected=hash_file('sha256',$from);$in=fopen($from,'rb');$out=fopen($temp,'xb');
        if(!$in||!$out){if(is_resource($in))fclose($in);if(is_resource($out))fclose($out);@unlink($temp);throw new RuntimeException('Tidak dapat membuka sumber/tujuan salinan: '.basename($to));}
        $written=stream_copy_to_stream($in,$out);fflush($out);fclose($in);fclose($out);clearstatcache(true,$temp);
        if($written!==$size||hash_file('sha256',$temp)!==$expected){@unlink($temp);throw new RuntimeException('Salinan/checksum tidak lengkap: '.basename($to).' ('.$written.'/'.$size.' byte).');}
        error_clear_last();if(!@rename($temp,$to)){
            $error=error_get_last()['message']??'permission atau file sedang digunakan';
            // Windows Apache can deny renaming the currently executing PHP endpoint.
            // Under maintenance, write the already verified bytes without deleting the old file.
            $inApp=str_starts_with(strtolower(str_replace('\\','/',$to)),strtolower($this->app.'/'));
            if(PHP_OS_FAMILY==='Windows'&&$inApp&&is_file($to)&&!is_link($to)&&is_file($this->root.'/maintenance.json')){
                $target=@fopen($to,'c+b');$source=@fopen($temp,'rb');$ok=false;
                if($target&&$source&&flock($target,LOCK_EX|LOCK_NB)){
                    $ok=ftruncate($target,0)&&rewind($target)&&stream_copy_to_stream($source,$target)===$size&&fflush($target);
                    flock($target,LOCK_UN);
                }
                if(is_resource($source))fclose($source);if(is_resource($target))fclose($target);clearstatcache(true,$to);
                if($ok&&hash_file('sha256',$to)===$expected){@unlink($temp);$this->deploymentWarnings[]='Windows: penulisan terverifikasi saat maintenance (non-atomik): '.substr(str_replace('\\','/',$to),strlen($this->app)+1);return;}
            }
            @unlink($temp);throw new RuntimeException('Penggantian file gagal: '.basename($to).'. '.$error.'; source snapshot tersedia, maintenance tetap aktif.');
        }
        @chmod($to,0600);clearstatcache(true,$to);
    }
    public function id():string{return date('Ymd-His').'-'.bin2hex(random_bytes(6));}
    public function read(string $id):array {
        if(!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/D',$id))throw new RuntimeException('ID tidak sah.');
        $p=$this->root.'/history/'.$id.'.json';if(!is_file($p))throw new RuntimeException('Riwayat tidak ditemukan.');
        return json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
    }
    public function save(array $record):void{$this->atomic($this->root.'/history/'.$record['id'].'.json',json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
    public function history():array {
        $rows=[];foreach(glob($this->root.'/history/*.json')?:[] as $p){try{$rows[]=json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){$rows[]=['id'=>basename($p,'.json'),'status'=>'journal_error','kind'=>'error','created_at'=>'','error'=>'Jurnal tidak dapat dibaca'];}}
        usort($rows,fn($a,$b)=>strcmp($b['id'],$a['id']));return $rows;
    }
    public function version():array{return json_decode((string)file_get_contents($this->app.'/app-version.json'),true,512,JSON_THROW_ON_ERROR);}
    public const BACKUP_KEEP = 15;
    public function deletionReason(array $r):string {
        if(is_file($this->root.'/maintenance.json'))return 'Sistem sedang update atau memerlukan pemulihan.';
        if(in_array($r['status']??'', ['processing','recovery_required','journal_error'],true))return 'Proses/riwayat ini masih diperlukan untuk pemeriksaan atau pemulihan.';
        $updateId=(string)($r['update_id']??'');
        if($updateId!==''){
            if(strtotime((string)($r['protected_until']??''))>time())return 'Snapshot update dilindungi selama 30 hari.';
            try{$parent=$this->read($updateId);$parentStatus=isset($parent['deleted_at'])?($parent['previous_status']??''):($parent['status']??'');if(!in_array($parentStatus, ['success','restored'],true))return 'Snapshot masih diperlukan untuk pemulihan update.';}catch(Throwable $e){return 'Riwayat update terkait belum dapat diverifikasi.';}
        }
        return '';
    }
    // Caller must hold the process lock. Audit journals are retained after deleting artifacts.
    public function deleteArchive(string $id,?int $operator=null,string $reason='manual'):array {
        $r=$this->read($id);$blocked=$this->deletionReason($r);if($blocked!=='')throw new RuntimeException($blocked);
        if(isset($r['deleted_at']))return $r;
        $r['delete_requested_at']=date(DATE_ATOM);$r['delete_operator']=$operator;$r['delete_reason']=$reason;$this->save($r);
        $zip=$this->root.'/archives/'.$id.'.zip';
        if(is_link($zip))throw new RuntimeException('Symlink arsip ditolak.');
        if(is_file($zip)&&!unlink($zip))throw new RuntimeException('File backup gagal dihapus. Periksa permission.');
        foreach([$id,$id.'-restore-test'] as $name){
            $dir=$this->root.'/staging/'.$name;
            if(!is_dir($dir))continue;
            $base=realpath($this->root.'/staging');$resolved=realpath($dir);
            if(is_link($dir)||$base===false||$resolved===false||!str_starts_with(strtolower($resolved.DIRECTORY_SEPARATOR),strtolower($base.DIRECTORY_SEPARATOR)))throw new RuntimeException('Path staging tidak aman.');
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as $f){if($f->isLink())throw new RuntimeException('Symlink staging ditolak.');if($f->isDir()){if(!rmdir($f->getPathname()))throw new RuntimeException('Folder staging gagal dihapus.');}elseif(!unlink($f->getPathname()))throw new RuntimeException('File staging gagal dihapus.');}
            if(!rmdir($dir))throw new RuntimeException('Staging gagal dihapus.');
        }
        $r['previous_status']=$r['status'];$r['status']='deleted';$r['deleted_at']=date(DATE_ATOM);
        $r['logs'][]=['time'=>date(DATE_ATOM),'message'=>'Arsip dihapus ('.$reason.'); jurnal audit dipertahankan.'];$this->save($r);return $r;
    }
    public function pruneSourceBackups(?int $operator=null):array {
        $backups=array_values(array_filter($this->history(),fn($r)=>($r['kind']??'')==='source_backup'&&($r['status']??'')==='success'&&!isset($r['deleted_at'])&&is_file($this->root.'/archives/'.$r['id'].'.zip')));
        $excess=count($backups)-self::BACKUP_KEEP;$deleted=[];$errors=[];
        foreach(array_reverse(array_slice($backups,self::BACKUP_KEEP)) as $r){if($excess<=0)break;if($this->deletionReason($r)!=='')continue;
            try{$this->deleteArchive($r['id'],$operator,'retention-15');$deleted[]=$r['id'];$excess--;}catch(Throwable $e){$errors[$r['id']]=$e->getMessage();}
        }
        return ['keep'=>self::BACKUP_KEEP,'deleted'=>$deleted,'remaining'=>count($backups)-count($deleted),'protected_excess'=>max(0,$excess),'errors'=>$errors];
    }
    public function allowed(string $rel):bool {
        if($rel===''||strlen($rel)>240||str_contains($rel,'\\')||str_contains($rel,':')||str_contains($rel,"\0")||preg_match('~(^|/)(\.|\.\.|[^/]*[. ]$|CON(?:\.|$)|NUL(?:\.|$)|AUX(?:\.|$)|PRN(?:\.|$)|COM[1-9](?:\.|$)|LPT[1-9](?:\.|$))~i',$rel))return false;
        if(preg_match('~(^|/)(storage|uploads|naveena_uploads|naveena_storage|backups|cache|logs|sessions|\.git|node_modules|tests|work|system|secrets)(/|$)~i',$rel))return false;
        if(preg_match('~(^|/)(config[._-]local|secrets|credentials|php-local|composer\.auth|auth\.json)|\.(sqlite(?:3)?(?:-wal|-shm)?|db|sql|zip|gz|log|ini|pem|key|p12|pfx|env|bak|part)$~i',$rel))return false;
        return (bool)preg_match('~\.(php|css|js|mjs|json|lock|map|svg|png|jpe?g|gif|webp|ico|woff2?|ttf|otf|eot|tsv|csv|txt|md|html|xml|yml|yaml)$~i',$rel);
    }
    public function inventory(?string $dir=null):array {
        $dir=$dir??$this->app;$out=[];
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
        foreach($it as $f){$rel=str_replace('\\','/',substr($f->getPathname(),strlen($dir)+1));if(!$this->allowed($rel))continue;if($f->isLink())throw new RuntimeException('Symlink sumber ditolak: '.$rel);if(!$f->isFile())continue;$out[$rel]=['size'=>$f->getSize(),'sha256'=>hash_file('sha256',$f->getPathname())];}
        ksort($out);return $out;
    }
    private function requirements(array $files):void {foreach(self::REQUIRED as $n)if(!isset($files[$n]))throw new RuntimeException('Source tidak lengkap: '.$n);}
    public function preflight(int $bytes):array {
        if(!class_exists('ZipArchive')||!class_exists('SQLite3')||!function_exists('openssl_verify'))throw new RuntimeException('Ekstensi ZIP, SQLite3 dan OpenSSL wajib tersedia.');
        $free=disk_free_space($this->root);if($free===false||$free<max(33554432,$bytes*4))throw new RuntimeException('Ruang disk tidak cukup untuk staging dan snapshot.');
        foreach([$this->app,$this->root] as $dir){$probe=$dir.'/.nv-write-'.bin2hex(random_bytes(6));$written=@file_put_contents($probe,'probe');if($written!==5){@unlink($probe);throw new RuntimeException('Permission aplikasi/penyimpanan tidak mencukupi.');}if(!unlink($probe))throw new RuntimeException('Permission penghapusan file sementara tidak mencukupi.');}
        $this->failpoint('disk');return ['disk_free'=>$free,'php'=>PHP_VERSION,'memory_limit'=>ini_get('memory_limit'),'max_execution_time'=>ini_get('max_execution_time')];
    }
    private function checkMemory(array $files):void {
        $limit=trim((string)ini_get('memory_limit'));if($limit==='-1')return;$unit=strtolower(substr($limit,-1));$max=(int)$limit*match($unit){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};
        $largest=max(array_column($files,'size')?:[0]);if($max-memory_get_usage(true)<$largest*3+16777216)throw new RuntimeException('memory_limit tidak cukup untuk file terbesar; pemasangan dibatalkan.');
    }
    private function newRecord(string $kind,?int $user,string $name=''):array {
        return ['id'=>$this->id(),'kind'=>$kind,'operator'=>$user,'created_at'=>date(DATE_ATOM),'status'=>'processing','progress'=>0,'phase'=>'Mulai','package_name'=>basename($name),'from'=>$this->version()['version'],'to'=>'','logs'=>[],'started'=>microtime(true),'duration'=>0];
    }
    public function log(array &$r,string $phase,int $progress):void{$r['phase']=$phase;$r['progress']=$progress;$r['logs'][]=['time'=>date(DATE_ATOM),'message'=>$phase];$r['duration']=round(microtime(true)-$r['started'],3);$this->save($r);}
    public function lock(){
        nv_update_release_request_lock();$h=fopen($this->root.'/process.lock','c+');if(!$h||!flock($h,LOCK_EX|LOCK_NB)){if($h)fclose($h);throw new RuntimeException('Proses lain atau transaksi aktif masih berjalan. Coba kembali setelah selesai.');}return $h;
    }
    public function unlock($h):void {flock($h,LOCK_UN);fclose($h);}
    public function backup(?int $user=null,?string $updateId=null):array {
        $r=$this->newRecord('source_backup',$user);$r['update_id']=$updateId;$r['protected_until']=$updateId!==null?date(DATE_ATOM,time()+30*86400):null;$this->save($r);
        try{
            $files=$this->inventory();$this->requirements($files);$this->preflight(array_sum(array_column($files,'size')));$this->checkMemory($files);
            $v=$this->version();$manifest=['format'=>1,'application'=>'Klinik Naveena','kind'=>'source_backup','version'=>$v['version'],'schema_min'=>$v['schema'],'schema_max'=>$v['schema'],'php_min'=>'8.2.0','created_at'=>date(DATE_ATOM),'files'=>$files,'removed'=>[],'migrations'=>[],'auth'=>['type'=>'hmac-sha256','key_id'=>'installation']];
            $json=json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$zip=$this->root.'/archives/'.$r['id'].'.zip';$part=$zip.'.part';$z=new ZipArchive();
            if($z->open($part,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP tidak dapat dibuat.');
            foreach($files as $n=>$f){if(!$z->addFile($this->app.'/'.$n,'source/'.$n)||!$z->setCompressionName('source/'.$n,ZipArchive::CM_DEFLATE,6))throw new RuntimeException('Kompresi gagal: '.$n);}
            $z->addFromString('manifest.json',$json);$z->addFromString('manifest.sig',hash_hmac('sha256',$json,(string)file_get_contents($this->root.'/backup.key')));
            if(!$z->close())throw new RuntimeException('Penulisan ZIP tidak lengkap.');$this->failpoint('backup_write');
            $verified=$this->validate($part,'source_backup');$stage=$this->root.'/staging/'.$r['id'].'-restore-test';$this->extract($part,$verified,$stage);$this->lint($stage,$files);
            if($this->inventory($stage)!==$files||$this->inventory()!==$files)throw new RuntimeException('Uji restore/checksum atau konsistensi sumber gagal.');
            $this->failpoint('backup_verify');if(!rename($part,$zip))throw new RuntimeException('Gagal finalisasi ZIP.');
            $r+=['file'=>$r['id'].'.zip','size'=>filesize($zip),'raw_size'=>array_sum(array_column($files,'size')),'file_count'=>count($files),'zip_sha256'=>hash_file('sha256',$zip),'restore_test'=>'extracted, checksum verified, PHP lint passed'];$r['to']=$v['version'];$r['status']='success';$this->log($r,'Backup ZIP dan uji restore berhasil',100);if(!is_file($this->root.'/maintenance.json')){$r['retention']=$this->pruneSourceBackups($user);$this->save($r);}return $r;
        }catch(Throwable $e){$r['status']='failed';$r['error']=$e->getMessage();$this->log($r,'Backup gagal: '.$e->getMessage(),0);throw $e;}
    }
    public function validate(string $path,string $kind='update'):array {
        if(!class_exists('ZipArchive'))throw new RuntimeException('ZIP extension diperlukan.');
        $z=new ZipArchive();if($z->open($path,ZipArchive::CHECKCONS)!==true)throw new RuntimeException('ZIP rusak/tidak dapat dibuka.');
        try {
            if($z->numFiles<3||$z->numFiles>20000)throw new RuntimeException('Jumlah entri ZIP tidak sah.');
            $names=[];$total=0;
            for($i=0;$i<$z->numFiles;$i++){$s=$z->statIndex($i);$n=$s['name'];$lower=strtolower($n);if(isset($names[$lower])||str_contains($n,'\\')||str_contains($n,"\0"))throw new RuntimeException('Entri duplikat/tidak sah.');$names[$lower]=$n;
                if($n!=='manifest.json'&&$n!=='manifest.sig'&&(!str_starts_with($n,'source/')||!$this->allowed(substr($n,7))))throw new RuntimeException('Path/file ZIP ditolak: '.$n);
                $ops=0;$attr=0;if($z->getExternalAttributesIndex($i,$ops,$attr)&&(($attr>>16)&0170000)===0120000)throw new RuntimeException('Symlink ZIP ditolak.');
                if(($s['encryption_method']??0)!==0||$s['size']>134217728||($s['size']>1048576&&$s['size']/max(1,$s['comp_size'])>200))throw new RuntimeException('ZIP terenkripsi atau rasio/ukuran tidak aman.');
                $total+=$s['size'];if($total>536870912)throw new RuntimeException('ZIP melebihi batas ekstraksi 512 MB.');
            }
            if(($z->statName('manifest.json')['size']??PHP_INT_MAX)>8388608||($z->statName('manifest.sig')['size']??PHP_INT_MAX)>16384)throw new RuntimeException('Manifest/signature terlalu besar.');
            $json=$z->getFromName('manifest.json');$sig=$z->getFromName('manifest.sig');if(!is_string($json)||!is_string($sig))throw new RuntimeException('Manifest/signature tidak ada.');
            $m=json_decode($json,true,512,JSON_THROW_ON_ERROR);
            if(($m['format']??0)!==1)throw new RuntimeException('Versi format manifest tidak didukung; paket harus memakai format 1.');
            if(($m['application']??'')!=='Klinik Naveena')throw new RuntimeException('Identitas aplikasi pada manifest bukan Klinik Naveena.');
            if(($m['kind']??'')!==$kind){
                if($kind==='update'&&($m['kind']??'')==='source_backup')throw new RuntimeException('ZIP ini adalah Backup Source Code, bukan paket update. Gunakan Restore Source Code atau Pratinjau Restore pada riwayat. Update memerlukan paket kind=update dengan tanda tangan RSA build tepercaya.');
                throw new RuntimeException('Jenis paket tidak sesuai formulir. Update memerlukan kind=update; Restore memerlukan kind=source_backup.');
            }
            foreach(['version','schema_min','schema_max','php_min'] as $field)if(!preg_match('/^\d+\.\d+\.\d+$/D',(string)($m[$field]??'')))throw new RuntimeException('Versi manifest tidak sah.');
            if(version_compare(PHP_VERSION,$m['php_min'],'<'))throw new RuntimeException('PHP tidak kompatibel.');
            if($kind==='source_backup'){
                if(($m['auth']['type']??'')!=='hmac-sha256'||!hash_equals(hash_hmac('sha256',$json,(string)file_get_contents($this->root.'/backup.key')),$sig))throw new RuntimeException('Backup bukan dari instalasi tepercaya atau telah diubah.');
            } else {
                if(($m['auth']['type']??'')!=='rsa-sha256')throw new RuntimeException('Paket update wajib ditandatangani build tepercaya.');
                $trust=is_file($this->root.'/trusted-keys.json')?json_decode((string)file_get_contents($this->root.'/trusted-keys.json'),true):[];
                $key=$trust[$m['auth']['key_id']??'']??'';$decoded=base64_decode($sig,true);
                $public=$key!==''?openssl_pkey_get_public($key):false;$details=$public?openssl_pkey_get_details($public):[];
                if(($details['type']??null)!==OPENSSL_KEYTYPE_RSA||($details['bits']??0)<2048)throw new RuntimeException('Kunci tepercaya harus RSA minimal 2048 bit.');
                if(!$key||!is_string($decoded)||openssl_verify($json,$decoded,$key,OPENSSL_ALGO_SHA256)!==1)throw new RuntimeException('Tanda tangan paket tidak dipercaya.');
                if(!isset($m['from'])||$m['from']!==$this->version()['version']||version_compare($m['version'],$m['from'],'<='))throw new RuntimeException('Versi asal/tujuan update tidak kompatibel.');
            }
            if(!is_array($m['files']??null)||!is_array($m['removed']??null)||!is_array($m['migrations']??null))throw new RuntimeException('Daftar manifest tidak lengkap.');$this->requirements($m['files']);
            if(count($names)!==count($m['files'])+2)throw new RuntimeException('ZIP mempunyai file tambahan atau tidak lengkap.');
            foreach($m['files'] as $n=>$f){if(!$this->allowed($n)||!is_array($f)||!is_int($f['size']??null)||!preg_match('/^[a-f0-9]{64}$/D',$f['sha256']??''))throw new RuntimeException('Manifest file tidak sah.');$s=$z->statName('source/'.$n);if(!$s||$s['size']!==$f['size'])throw new RuntimeException('File hilang/ukuran berbeda: '.$n);$stream=$z->getStream('source/'.$n);if(!$stream)throw new RuntimeException('File ZIP tidak dapat diekstrak.');$hash=hash_init('sha256');hash_update_stream($hash,$stream);fclose($stream);if(!hash_equals($f['sha256'],hash_final($hash)))throw new RuntimeException('Checksum berbeda: '.$n);}
            foreach($m['removed'] as $n)if(!is_string($n)||!$this->allowed($n)||in_array($n,self::REQUIRED,true)||isset($m['files'][$n]))throw new RuntimeException('Daftar penghapusan tidak sah.');
            $av=json_decode((string)$z->getFromName('source/app-version.json'),true,512,JSON_THROW_ON_ERROR);if(($av['version']??'')!==$m['version']||($av['schema']??'')!==$m['schema_max'])throw new RuntimeException('Versi source berbeda dari manifest.');
            $this->validateMigrations($m['migrations']);ksort($m['files']);$m['_bytes']=$total;return $m;
        }finally{$z->close();}
    }
    public function validateMigrations(array $steps):void {
        $previous='';foreach($steps as $s){if(!is_array($s)||!preg_match('/^[0-9]{3,}-[a-z0-9_-]+$/D',$s['id']??'')||strcmp($s['id'],$previous)<=0||!in_array($s['scope']??'',['central','branch'],true)||!is_array($s['sql']??null))throw new RuntimeException('Urutan/scope migrasi tidak sah.');$previous=$s['id'];
            foreach($s['sql'] as $sql)if(!is_string($sql)||str_contains($sql,';')||preg_match('/--|\/\*|\b(DROP|DELETE|UPDATE|INSERT|REPLACE|ATTACH|DETACH|PRAGMA|TRIGGER|VACUUM|SELECT)\b/i',$sql)||!preg_match('/^\s*(CREATE\s+(?:UNIQUE\s+)?(?:TABLE|INDEX)\s+IF\s+NOT\s+EXISTS|ALTER\s+TABLE\s+[a-zA-Z_][a-zA-Z0-9_]*\s+ADD\s+COLUMN)\s+/i',$sql))throw new RuntimeException('Migrasi wajib aditif; SQL berisiko ditolak.');
        }
    }
    public function extract(string $zip,array $m,string $dest):void {
        if(!is_dir($dest)&&!mkdir($dest,0700,true))throw new RuntimeException('Staging tidak dapat dibuat.');$z=new ZipArchive();if($z->open($zip)!==true)throw new RuntimeException('ZIP tidak dapat dibuka.');
        try{foreach($m['files'] as $n=>$f){$path=$dest.'/'.$n;if(!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true))throw new RuntimeException('Gagal membuat staging.');$in=$z->getStream('source/'.$n);$out=fopen($path,'xb');if(!$in||!$out)throw new RuntimeException('Gagal ekstraksi.');$copied=stream_copy_to_stream($in,$out);fclose($in);fclose($out);if($copied!==$f['size']||hash_file('sha256',$path)!==$f['sha256'])throw new RuntimeException('Checksum ekstraksi gagal: '.$n);}}finally{$z->close();}
    }
    public function phpBinary():string {
        $env=(string)getenv('NAVEENA_PHP_CLI');$bin=$env!==''?$env:(PHP_SAPI==='cli'?PHP_BINARY:'');
        if($bin===''||!is_file($bin))throw new RuntimeException('Atur NAVEENA_PHP_CLI ke PHP CLI 8.2+ Hostinger sebelum instalasi.');return $bin;
    }
    public function command(array $args,array $env=[]):string {
        if(!function_exists('proc_open'))throw new RuntimeException('proc_open diperlukan untuk lint dan verifikasi proses baru.');
        $base=getenv();foreach(['NAVEENA_DB','NAVEENA_DB_ROOT','NAVEENA_UPLOAD_DIR','NAVEENA_BACKUP_DIR','NAVEENA_PHP_CLI','NAVEENA_PHP_INI'] as $key){$value=getenv($key);if($value!==false)$base[$key]=$value;}$base['NAVEENA_SYSTEM_ROOT']=$this->root;$p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge($base,$env));if(!is_resource($p))throw new RuntimeException('PHP CLI gagal dimulai.');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$text='';$start=microtime(true);$timeout=in_array('-l',$args,true)?30:600;
        do{$text.=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);$status=proc_get_status($p);if(strlen($text)>2097152||microtime(true)-$start>$timeout){proc_terminate($p);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);throw new RuntimeException('PHP CLI melebihi batas waktu/output; maintenance tetap aktif bila pemasangan telah dimulai.');}if($status['running'])usleep(10000);}while($status['running']);
        $text.=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($p);$code=$status['exitcode']>=0?$status['exitcode']:$closed;if($code!==0)throw new RuntimeException('PHP CLI gagal: '.substr($text,0,3000));return $text;
    }
    private function phpArgs():array {$args=[$this->phpBinary()];$ini=(string)getenv('NAVEENA_PHP_INI');if($ini==='')$ini=(string)php_ini_loaded_file();if($ini!==''){$args[]='-c';$args[]=$ini;}return $args;}
    public function lint(string $root,array $files):void {
        $bin=$this->phpBinary();$ini=(string)getenv('NAVEENA_PHP_INI');if($ini==='')$ini=(string)php_ini_loaded_file();
        $runtime=hash('sha256',hash_file('sha256',$bin).'|'.PHP_VERSION.'|'.($ini!==''&&is_file($ini)?hash_file('sha256',$ini):''));
        $cachePath=$this->root.'/lint-cache.json';$cache=is_file($cachePath)?json_decode((string)file_get_contents($cachePath),true):[];if(!is_array($cache)||($cache['runtime']??'')!==$runtime)$cache=['runtime'=>$runtime,'files'=>[]];
        foreach($files as $n=>$f)if(str_ends_with(strtolower($n),'.php')){if(hash_file('sha256',$root.'/'.$n)!==$f['sha256'])throw new RuntimeException('Checksum sebelum lint berbeda.');if(empty($cache['files'][$f['sha256']])){$this->command(array_merge($this->phpArgs(),['-l',$root.'/'.$n]));$cache['files'][$f['sha256']]=true;}}
        $this->atomic($cachePath,json_encode($cache,JSON_THROW_ON_ERROR));
    }
    public function plan(array $m):array {
        $current=$this->inventory();$out=['added'=>[],'changed'=>[],'removed'=>[]];foreach($m['files'] as $n=>$f){if(!isset($current[$n]))$out['added'][]=$n;elseif($current[$n]['sha256']!==$f['sha256'])$out['changed'][]=$n;}
        foreach($current as $n=>$f)if(!isset($m['files'][$n])){if(($m['kind']??'')==='update'&&!in_array($n,$m['removed'],true))throw new RuntimeException('Manifest penuh tidak menjelaskan penghapusan '.$n);$out['removed'][]=$n;}return $out;
    }
    public function stage(string $zip,string $kind,?int $user,?string $packageName=null):array {
        $r=$this->newRecord($kind==='update'?'update':'restore',$user,basename($packageName??$zip));$this->save($r);
        try{$m=$this->validate($zip,$kind);$this->preflight($m['_bytes']);$this->checkMemory($m['files']);$r['manifest']=$m;$r['to']=$m['version'];$r['size']=filesize($zip);$r['file_count']=count($m['files']);$r['plan']=$this->plan($m);$stage=$this->root.'/staging/'.$r['id'];$this->extract($zip,$m,$stage);$this->lint($stage,$m['files']);$this->copyAtomic($zip,$this->root.'/archives/'.$r['id'].'.zip');$r['zip_sha256']=hash_file('sha256',$zip);$r['rehearsal']=$this->rehearse($r);$r['status']='awaiting_confirmation';$this->log($r,'Validasi ZIP, source, migrasi salinan, integrity/FK, preservasi dan dashboard berhasil; menunggu konfirmasi',10);return $r;}catch(Throwable $e){$r['status']='failed';$r['error']=$e->getMessage();$this->log($r,'Paket ditolak: '.$e->getMessage(),0);throw $e;}
    }
    public function snapshotDatabase(string $updateId,?int $user):array {
        // Extended existing package mechanism: full media + SQLite online backup + normal backups history.
        if(!function_exists('backup_create_package'))throw new RuntimeException('Modul Backup Database belum dimuat.');
        $r=backup_create_package('Snapshot Otomatis Sebelum Update Sistem · '.$updateId,$user,['system_snapshot'=>$updateId,'full_media'=>true]);
        if(!$r['ok'])throw new RuntimeException('Snapshot Database gagal: '.$r['error']);$r['path']=backup_dir_ensure().'/'.$r['file'];return $r;
    }
    private function verifyStage(array $r):void {
        $zip=$this->root.'/archives/'.$r['id'].'.zip';if(hash_file('sha256',$zip)!==$r['zip_sha256'])throw new RuntimeException('Paket staging berubah.');$m=$this->validate($zip,$r['manifest']['kind']);
        if($this->inventory($this->root.'/staging/'.$r['id'])!==$m['files'])throw new RuntimeException('Source staging berubah.');
        if($r['from']!==$this->version()['version'])throw new RuntimeException('Versi aktif berubah sejak pratinjau.');
    }
    public function checkDatabaseCompatibility(array $m):array {
        $paths=[db_central_path()];foreach(db_central_all('SELECT id FROM branches ORDER BY id') as $b)$paths[]=db_branch_path((int)$b['id']);$result=[];
        foreach($paths as $path){if(!is_file($path))throw new RuntimeException('Database tidak ada; instalasi dibatalkan.');$v=db_schema_version_of($path);if($v['error']!==''||$v['version']===''||version_compare($v['version'],$m['schema_min'],'<')||version_compare($v['version'],$m['schema_max'],'>'))throw new RuntimeException('Skema aktual tidak kompatibel: '.basename($path));if($m['kind']==='source_backup'&&$v['version']!==$m['schema_max'])throw new RuntimeException('Restore akan menurunkan skema yang tidak kompatibel.');$result[basename($path)]=$v;}
        return $result;
    }
    public function apply(string $id,?int $user):array {
        $r=$this->read($id);if($r['status']!=='awaiting_confirmation'||$r['operator']!==$user)throw new RuntimeException('Status/operator konfirmasi tidak cocok.');
        // Full rehearsal uses online SQLite copies and the staged source, never the active DBs.
        try{$this->verifyStage($r);$r['rehearsal']=$this->rehearse($r);$this->save($r);}catch(Throwable $e){$r['status']='failed';$r['source_changed']=false;$r['error']=$e->getMessage();$this->log($r,'Pemeriksaan salinan gagal; web aktif tidak diubah: '.$e->getMessage(),0);throw $e;}
        $h=$this->lock();$marker=$this->root.'/maintenance.json';$changed=false;$migrationStarted=false;$oldMaintenance=[];
        try{
            if(is_file($marker))throw new RuntimeException('Ada proses/pemulihan tertunda. Jangan instal paket baru.');
            $this->verifyStage($r);$this->preflight($r['manifest']['_bytes']);$this->phpBinary();$r['database_before']=$this->checkDatabaseCompatibility($r['manifest']);
            $oldMaintenance=['mode'=>setting('maintenance_mode'),'branch'=>setting('maintenance_branch')];
            $this->prepareMaintenancePortal();
            $token=bin2hex(random_bytes(32));$r['previous_maintenance']=$oldMaintenance;$r['worker_token']=$token;$r['status']='processing';
            $this->atomic($marker,json_encode(['id'=>$id,'worker_token'=>$token,'created_at'=>date(DATE_ATOM)],JSON_THROW_ON_ERROR));
            set_setting('maintenance_mode','1');set_setting('maintenance_branch','0');
            $this->log($r,'Sistem terkunci; membuat snapshot Database lengkap',20);
            $r['database_snapshot']=$this->snapshotDatabase($id,$user);$this->save($r);$this->failpoint('database_snapshot');
            $this->log($r,'Membuat snapshot Source Code dan uji restore',35);$r['source_snapshot']=$this->backup($user,$id);$this->save($r);$this->failpoint('snapshots_complete');
            // Freeze the verifier before restore can replace its source with an older version.
            $worker=$this->root.'/worker.php';if(!copy(__DIR__.'/system_update_worker.php',$worker))throw new RuntimeException('Worker tidak dapat disiapkan.');
            $this->log($r,'Memasang file; database tidak diganti',50);$changed=true;
            $this->deploy($this->root.'/staging/'.$id,$r['manifest']);$r['deployment_warnings']=$this->deploymentWarnings;$this->save($r);$this->failpoint('deployed');
            $this->log($r,'Migrasi dan verifikasi pada proses PHP baru',70);
            $job=$this->root.'/staging/'.$id.'-job.json';$this->atomic($job,json_encode(['app'=>$this->app,'manifest'=>$r['manifest'],'db_snapshot'=>$r['database_snapshot'],'report'=>$this->root.'/staging/'.$id.'-result.json'],JSON_THROW_ON_ERROR));
            $migrationStarted=true;$this->command(array_merge($this->phpArgs(),[$worker,$job]),['NAVEENA_UPDATE_WORKER_TOKEN'=>$token]);
            $r['verification']=json_decode((string)file_get_contents($this->root.'/staging/'.$id.'-result.json'),true,512,JSON_THROW_ON_ERROR);
            if(empty($r['verification']['ok']))throw new RuntimeException('Pemeriksaan hasil update gagal.');
            $r['status']=$r['kind']==='restore'?'restored':'success';$this->log($r,'Instalasi, migrasi, checksum dan pemeriksaan hasil berhasil',100);
            $this->command(array_merge($this->phpArgs(),[$worker,$job,'release']),['NAVEENA_UPDATE_WORKER_TOKEN'=>$token]);
            if(!unlink($marker))throw new RuntimeException('Marker maintenance gagal dibuka.');
            if(function_exists('opcache_reset'))opcache_reset();return $r;
        }catch(Throwable $e){
            if($changed&&!$migrationStarted&&!empty($r['source_snapshot'])){
                try{
                    $snapshot=$this->root.'/archives/'.$r['source_snapshot']['file'];$baseline=$this->validate($snapshot,'source_backup');$restore=$this->root.'/staging/'.$id.'-automatic-recovery';$this->extract($snapshot,$baseline,$restore);$this->lint($restore,$baseline['files']);
                    $fault=$this->fault;$this->fault=null;try{$this->deploy($restore,$baseline);}finally{$this->fault=$fault;}
                    foreach($r['database_before'] as $name=>$before){$path=$name==='central.sqlite'?db_central_path():dirname(db_central_path()).'/branches/'.$name;$p=new PDO('sqlite:'.$path);$p->exec('PRAGMA query_only=ON');if($p->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$p->query('PRAGMA foreign_key_check')->fetchAll())throw new RuntimeException('Database tidak lolos pemeriksaan pemulihan.');}
                    $r['automatic_source_recovery']=['ok'=>true,'database_not_replaced'=>true,'at'=>date(DATE_ATOM)];$changed=false;
                    if(function_exists('opcache_reset'))opcache_reset();
                }catch(Throwable $recovery){$r['automatic_source_recovery']=['ok'=>false,'error'=>$recovery->getMessage()];}
            }
            $r['status']=$changed?'recovery_required':'failed';$r['error']=$e->getMessage();$r['source_changed']=$changed;
            $partial=$this->root.'/staging/'.$id.'-result.json';if(is_file($partial))$r['verification']=json_decode((string)file_get_contents($partial),true);
            $this->log($r,'Gagal: '.$e->getMessage().($changed?'; maintenance tetap aktif; database tidak di-rollback otomatis':''),$r['progress']);
            // If no source was touched, there was no migration and it is safe to release our marker.
            if(!$changed&&is_file($marker)&&($r['worker_token']??'')!==''){
                if($oldMaintenance){set_setting('maintenance_mode',$oldMaintenance['mode']);set_setting('maintenance_branch',$oldMaintenance['branch']);}
                @unlink($marker);
            }throw $e;
        }finally{$this->unlock($h);}
    }
    public function prepareMaintenancePortal():void {
        $this->atomic($this->root.'/maintenance-css.txt',(string)file_get_contents($this->app.'/assets/css/app.css'));
        $theme=function_exists('theme_css')?theme_css():':root{--magenta:#C2185B;--magenta-dark:#8E0E42;--pink:#E91E63;--pink-soft:#F8D9E7;--rose-bg:#FBF6F9;--leaf:#8BC34A;--line:#F0DDE7;}';
        $this->atomic($this->root.'/maintenance-theme.txt',$theme);
        foreach(['system_maintenance_portal.php'=>'maintenance-portal.php','qr.php'=>'maintenance-qr.php'] as $from=>$to)if(!copy(__DIR__.'/'.$from,$this->root.'/'.$to))throw new RuntimeException('Portal maintenance tidak dapat disiapkan.');
    }
    public function rehearse(array $r):array {
        $this->preflight($r['manifest']['_bytes']);$base=$this->root.'/staging/'.$r['id'].'-rehearsal-'.bin2hex(random_bytes(3));
        foreach(['/db/databases/branches','/backups','/uploads','/system/history','/system/staging'] as $dir)if(!mkdir($base.$dir,0700,true))throw new RuntimeException('Folder rehearsal gagal dibuat.');
        $dbs=[['path'=>db_central_path(),'entry'=>'backup/central.sqlite','dest'=>$base.'/db/databases/central.sqlite']];
        foreach(db_central_all('SELECT id FROM branches ORDER BY id') as $b){$name='branch_'.str_pad((string)$b['id'],3,'0',STR_PAD_LEFT).'.sqlite';$dbs[]=['path'=>db_branch_path((int)$b['id']),'entry'=>'backup/branches/'.$name,'dest'=>$base.'/db/databases/branches/'.$name];}
        foreach($dbs as $db){$src=new SQLite3($db['path'],SQLITE3_OPEN_READONLY);$dst=new SQLite3($db['dest']);if(!$src->backup($dst))throw new RuntimeException('Salinan online SQLite gagal.');$src->close();$dst->close();}
        $snapshot=$base.'/backups/baseline.zip';$z=new ZipArchive();if($z->open($snapshot,ZipArchive::CREATE)!==true)throw new RuntimeException('Baseline rehearsal gagal dibuat.');foreach($dbs as $db)$z->addFile($db['dest'],$db['entry']);if(!$z->close())throw new RuntimeException('Baseline rehearsal tidak lengkap.');
        $token=bin2hex(random_bytes(32));$this->atomic($base.'/system/maintenance.json',json_encode(['id'=>$r['id'],'worker_token'=>$token]));
        $report=$base.'/report.json';$job=$base.'/job.json';$this->atomic($job,json_encode(['app'=>$this->root.'/staging/'.$r['id'],'manifest'=>$r['manifest'],'db_snapshot'=>['file'=>'baseline.zip','path'=>$snapshot,'sha256'=>hash_file('sha256',$snapshot)],'report'=>$report],JSON_THROW_ON_ERROR));
        try{$this->command(array_merge($this->phpArgs(),[__DIR__.'/system_update_worker.php',$job]),['NAVEENA_DB'=>'','NAVEENA_DB_ROOT'=>$base.'/db','NAVEENA_BACKUP_DIR'=>$base.'/backups','NAVEENA_UPLOAD_DIR'=>$base.'/uploads','NAVEENA_SYSTEM_ROOT'=>$base.'/system','NAVEENA_UPDATE_WORKER_TOKEN'=>$token]);}catch(Throwable $e){throw new RuntimeException('Rehearsal source/migrasi gagal pada salinan: '.$e->getMessage());}
        $result=json_decode((string)file_get_contents($report),true,512,JSON_THROW_ON_ERROR);if(empty($result['ok']))throw new RuntimeException('Rehearsal tidak lolos.');$result['active_system_untouched']=true;return $result;
    }
    public function deploy(string $stage,array $m):void {
        foreach($m['files'] as $n=>$f){$dest=$this->app.'/'.$n;$dir=dirname($dest);$cursor=$this->app;foreach(explode('/',dirname($n)) as $part){if($part==='.')continue;$cursor.='/'.$part;if(is_link($cursor))throw new RuntimeException('Folder target berupa symlink.');}if(is_link($dest))throw new RuntimeException('Target symlink ditolak.');if(!is_dir($dir)&&!mkdir($dir,0755,true))throw new RuntimeException('Folder target tidak dapat dibuat.');$this->copyAtomic($stage.'/'.$n,$dest);@chmod($dest,0644);$this->failpoint('file_deployed');}
        foreach($this->inventory() as $n=>$f)if(!isset($m['files'][$n])){if(!unlink($this->app.'/'.$n))throw new RuntimeException('File usang gagal dihapus: '.$n);}
        if($this->inventory()!==$m['files'])throw new RuntimeException('Checksum source setelah deployment tidak cocok.');
    }
}

