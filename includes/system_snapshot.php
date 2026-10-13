<?php
declare(strict_types=1);
/** Full local recovery snapshot; called exclusively by existing backup_create_package(). */
function backup_snapshot_protected(array $row):bool {
    $value=(string)($row['auto_run']??'');
    return preg_match('/^system:[^:]+:(\d+)$/D',$value,$m) && (int)$m[1]>time();
}
function backup_system_media_required(array $inventory):void {
    $root=local_upload_dir();$missing=[];
    foreach($inventory as $db){$pdo=new PDO('sqlite:'.$db['path']);
        if($db['kind']==='central')continue;
        foreach(['patients'=>'pasien','doctors'=>'dokter','therapists'=>'terapis'] as $table=>$sub){
            if(!(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=".$pdo->quote($table))->fetchColumn())continue;
            foreach($pdo->query('SELECT photo_file FROM '.$table." WHERE photo_file IS NOT NULL AND photo_file<>''") as $r){$f=$sub.'/'.basename($r['photo_file']);if(!is_file($root.'/'.$f))$missing[]=$f;}
        }
        if((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='medical_record_photos'")->fetchColumn()){
            foreach($pdo->query('SELECT storage,local_path,file_url FROM medical_record_photos') as $r){
                if($r['storage']==='remote'&&preg_match('~^https?://~i',(string)$r['file_url']))throw new RuntimeException('Snapshot lengkap memerlukan salinan media rekam medis remote. Ekspor media ke penyimpanan lokal dahulu.');
                $name=(string)($r['local_path']?:$r['file_url']);if($name!==''&&!is_file($root.'/rekam-medis/'.basename($name)))$missing[]='rekam-medis/'.basename($name);
            }
        }
    }
    foreach(['logo_file','member_card_bg_file','pay_qris_file','favicon_file'] as $k){$v=setting($k);if($v!==''&&!is_file($root.'/'.basename($v)))$missing[]=basename($v);}
    if($missing)throw new RuntimeException('Media yang dirujuk tidak tersedia: '.implode(', ',array_slice(array_unique($missing),0,10)));
}
function backup_create_system_snapshot(string $note,?int $user,array $opts):array {
    $tmpDir='';$part='';
    try{
        if(!class_exists('ZipArchive')||!class_exists('SQLite3'))throw new RuntimeException('Snapshot memerlukan ZIP dan SQLite3.');
        $id=(string)$opts['system_snapshot'];if(!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/D',$id))throw new RuntimeException('ID snapshot tidak sah.');
        $central=new PDO('sqlite:'.db_central_path(),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $inv=[['kind'=>'central','branch_id'=>null,'label'=>'central.sqlite','path'=>db_central_path()]];
        foreach($central->query('SELECT id FROM branches ORDER BY id') as $row){$bid=(int)$row['id'];$inv[]=['kind'=>'branch','branch_id'=>$bid,'label'=>'branches/branch_'.str_pad((string)$bid,3,'0',STR_PAD_LEFT).'.sqlite','path'=>db_branch_path($bid)];}
        foreach($inv as $db)if(!is_file($db['path']))throw new RuntimeException('Database cabang/Central tidak ada; snapshot dibatalkan.');
        backup_system_media_required($inv);
        $root=backup_dir_ensure();$file='naveena-system-snapshot-'.$id.'.zip';if(is_file($root.'/'.$file))throw new RuntimeException('Snapshot ID sudah digunakan.');
        $tmpDir=$root.'/.snapshot-'.$id;if(!mkdir($tmpDir,0700))throw new RuntimeException('Folder snapshot tidak dapat dibuat.');$part=$root.'/'.$file.'.part';
        $media=[];$bytes=0;$upload=local_upload_dir();
        if(is_dir($upload))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($upload,FilesystemIterator::SKIP_DOTS)) as $f){if($f->isLink())throw new RuntimeException('Symlink media ditolak.');if(!$f->isFile())continue;$rel=str_replace('\\','/',substr($f->getPathname(),strlen($upload)+1));if(str_contains($rel,'..')||str_contains($rel,':'))throw new RuntimeException('Path media tidak sah.');$media[]=['rel'=>$rel,'abs'=>$f->getPathname(),'bytes'=>$f->getSize()];$bytes+=$f->getSize();}
        foreach($inv as $db)$bytes+=filesize($db['path']);$free=disk_free_space($root);
        if($free===false||$free<$bytes*3+33554432)throw new RuntimeException('Disk tidak cukup untuk snapshot penuh.');
        $info=backup_storage_info();if($info['used']+$bytes>backup_quota_bytes())throw new RuntimeException('Kuota backup tidak cukup untuk snapshot penuh. Tingkatkan kapasitas; media tidak dipotong.');
        $z=new ZipArchive();if($z->open($part,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP snapshot tidak dapat dibuat.');$manifest=[];$checks=[];
        foreach($inv as $db){$dest=$tmpDir.'/'.basename($db['label']);$src=new SQLite3($db['path'],SQLITE3_OPEN_READONLY);$copy=new SQLite3($dest);$src->busyTimeout(5000);
            if(!$src->backup($copy))throw new RuntimeException('SQLite online backup gagal: '.$db['label']);$copy->close();$src->close();
            $verify=new PDO('sqlite:'.$dest);if($verify->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$verify->query('PRAGMA foreign_key_check')->fetchAll())throw new RuntimeException('Snapshot SQLite gagal integrity/FK check.');$verify=null;
            $name='backup/'.$db['label'];$z->addFile($dest,$name);$z->setCompressionName($name,ZipArchive::CM_DEFLATE,6);$checks[$name]=hash_file('sha256',$dest);$manifest[]=['kind'=>$db['kind'],'branch_id'=>$db['branch_id'],'file'=>$name,'bytes'=>filesize($dest)];
        }
        $mediaMeta=[];foreach($media as $f){$name='backup/media/'.$f['rel'];$z->addFile($f['abs'],$name);$z->setCompressionName($name,ZipArchive::CM_DEFLATE,6);$checks[$name]=hash_file('sha256',$f['abs']);$mediaMeta[]=['rel'=>$f['rel'],'file'=>$name,'bytes'=>$f['bytes'],'sha256'=>$checks[$name]];}
        $meta=['update_id'=>$id,'snapshot_complete'=>true,'dibuat'=>date(DATE_ATOM),'schema_version'=>backup_schema_version_text(),'arsitektur'=>'central_branch','media'=>['jumlah_total'=>count($media),'ikut_ke_paket'=>count($media),'batas_mb'=>null],'database_count'=>count($inv)];
        $z->addFromString('manifest.json',json_encode(['files'=>$manifest],JSON_THROW_ON_ERROR));$z->addFromString('checksums.json',json_encode($checks,JSON_THROW_ON_ERROR));$z->addFromString('metadata.json',json_encode($meta,JSON_THROW_ON_ERROR));$z->addFromString('media.json',json_encode(['berkas'=>$mediaMeta,'tidak_ikut'=>[]],JSON_THROW_ON_ERROR));
        if(!$z->close())throw new RuntimeException('ZIP snapshot gagal diselesaikan.');
        $z=new ZipArchive();if($z->open($part,ZipArchive::CHECKCONS)!==true)throw new RuntimeException('ZIP snapshot rusak.');
        foreach($checks as $name=>$expected){$stream=$z->getStream($name);if(!$stream)throw new RuntimeException('Snapshot tidak lengkap.');$hash=hash_init('sha256');hash_update_stream($hash,$stream);fclose($stream);if(!hash_equals($expected,hash_final($hash)))throw new RuntimeException('Checksum snapshot tidak cocok.');}$z->close();
        if(!rename($part,$root.'/'.$file))throw new RuntimeException('Snapshot gagal disimpan.');$size=filesize($root.'/'.$file);$until=time()+30*86400;
        q('INSERT INTO backups (filename,size,note,created_by,kind,auto_run) VALUES (?,?,?,?,?,?)',[$file,$size,$note,$user,'package','system:'.$id.':'.$until]);$bid=(int)scalar('SELECT id FROM backups WHERE filename=?',[$file]);
        return ['ok'=>true,'id'=>$bid,'file'=>$file,'size'=>$size,'update_id'=>$id,'protected_until'=>$until,'entries'=>$manifest,'media_count'=>count($media),'complete'=>true,'sha256'=>hash_file('sha256',$root.'/'.$file),'error'=>''];
    }catch(Throwable $e){return ['ok'=>false,'error'=>$e->getMessage(),'file'=>'','entries'=>[]];}
    finally{if($part!==''&&is_file($part))@unlink($part);if($tmpDir!==''&&is_dir($tmpDir)){foreach(glob($tmpDir.'/*')?:[] as $p)if(is_file($p))@unlink($p);@rmdir($tmpDir);}}
}
