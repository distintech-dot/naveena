<?php
/** Executed by CLI only; never from a public HTTP request. */
declare(strict_types=1);
if(PHP_SAPI!=='cli') {http_response_code(403);exit;}
try{
    if(empty($argv[1])||!is_file($argv[1]))throw new RuntimeException('Job worker tidak tersedia.');
    $job=json_decode((string)file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    $app=$job['app'];$m=$job['manifest'];
    // Suppress bootstrap auto-migrations until explicit per-file checks below.
    $GLOBALS['DB_ROUTE_INSPECT']=true;
    $_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/update_system.php';$_SERVER['REQUEST_URI']='/update_system.php';
    require $app.'/includes/config.php';require_once $app.'/includes/backup_lib.php';
    $systemRoot=nv_system_root($app);$marker=json_decode((string)file_get_contents($systemRoot.'/maintenance.json'),true,512,JSON_THROW_ON_ERROR);
    if(!hash_equals($marker['worker_token'],(string)getenv('NAVEENA_UPDATE_WORKER_TOKEN')))throw new RuntimeException('Worker tidak berwenang.');
    if(($argv[2]??'')==='release'){
        $record=json_decode((string)file_get_contents($systemRoot.'/history/'.$marker['id'].'.json'),true,512,JSON_THROW_ON_ERROR);
        set_setting('maintenance_mode',$record['previous_maintenance']['mode']);set_setting('maintenance_branch',$record['previous_maintenance']['branch']);exit;
    }
    $central=db_open(db_central_path());$dbs=[['kind'=>'central','branch_id'=>null,'path'=>db_central_path(),'label'=>'central.sqlite']];
    foreach($central->query('SELECT id FROM branches ORDER BY id') as $b){$bid=(int)$b['id'];$dbs[]=['kind'=>'branch','branch_id'=>$bid,'path'=>db_branch_path($bid),'label'=>'branches/branch_'.str_pad((string)$bid,3,'0',STR_PAD_LEFT).'.sqlite'];}
    $snapshotZip=$job['db_snapshot']['path']??(backup_dir_ensure().'/'.$job['db_snapshot']['file']);if(!is_file($snapshotZip)||hash_file('sha256',$snapshotZip)!==$job['db_snapshot']['sha256'])throw new RuntimeException('Snapshot Database tidak ditemukan/checksum berubah. Periksa NAVEENA_BACKUP_DIR dan lokasi snapshot.');
    $zip=new ZipArchive();if($zip->open($snapshotZip)!==true)throw new RuntimeException('Snapshot Database gagal dibuka.');$report=['ok'=>false,'databases'=>[]];
    file_put_contents($job['report'],json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    foreach($dbs as $db){
        $report['current_database']=$db['label'];file_put_contents($job['report'],json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        if(!is_file($db['path']))throw new RuntimeException('Database produksi tidak ada; pembuatan baru ditolak.');
        $oldPath=$systemRoot.'/staging/'.$marker['id'].'-'.basename($db['label']);
        $stream=$zip->getStream('backup/'.$db['label']);$out=fopen($oldPath,'wb');if(!$stream||!$out)throw new RuntimeException('Snapshot database tidak lengkap.');stream_copy_to_stream($stream,$out);fclose($stream);fclose($out);
        $old=new PDO('sqlite:'.$oldPath,null,null,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$pdo=db_open($db['path']);
        $before=db_schema_version_of($db['path']);$v=$before['version'];
        if($v===''||version_compare($v,$m['schema_min'],'<')||version_compare($v,$m['schema_max'],'>'))throw new RuntimeException('Versi database tidak kompatibel: '.$db['label'].' '.$v);
        if($m['kind']==='source_backup'&&$v!==$m['schema_max'])throw new RuntimeException('Restore source tidak kompatibel dengan skema database aktif.');
        $GLOBALS['DB_SCHEMA_BRANCH_ID']=$db['branch_id']??0;
        db_schema_with_scope($db['kind'],function()use($pdo,$m,$db){
            $pdo->exec('CREATE TABLE IF NOT EXISTS system_update_migrations (id TEXT PRIMARY KEY, checksum TEXT NOT NULL, applied_at TEXT NOT NULL)');
            foreach($m['migrations'] as $step){if($step['scope']!==$db['kind'])continue;$hash=hash('sha256',json_encode($step['sql'],JSON_THROW_ON_ERROR));$st=$pdo->prepare('SELECT checksum FROM system_update_migrations WHERE id=?');$st->execute([$step['id']]);$done=$st->fetchColumn();if($done!==false){if($done!==$hash)throw new RuntimeException('Checksum migrasi yang pernah diterapkan berubah.');continue;}
                $pdo->exec('BEGIN IMMEDIATE');try{foreach($step['sql'] as $sql)$pdo->exec($sql);$pdo->prepare('INSERT INTO system_update_migrations VALUES (?,?,?)')->execute([$step['id'],$hash,date(DATE_ATOM)]);$pdo->exec('COMMIT');}catch(Throwable $e){$pdo->exec('ROLLBACK');throw $e;}
            }
            ensure_schema($pdo);
        });
        // Check actual tables/columns/index definitions, rather than trusting version stamps alone.
        $reference=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        db_schema_with_scope($db['kind'],function()use($reference,$m,$db){foreach(schema_ddl($db['kind']) as $sql)$reference->exec($sql);run_migrations($reference);foreach($m['migrations'] as $step)if($step['scope']===$db['kind'])foreach($step['sql'] as $sql){try{$reference->exec($sql);}catch(PDOException $e){if(!str_contains($e->getMessage(),'duplicate column name'))throw $e;}}});
        $structure=['tables'=>0,'columns'=>0,'indexes'=>0];
        foreach($reference->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $table){$name=$table['name'];$q='"'.str_replace('"','""',$name).'"';$columns=$pdo->query('PRAGMA table_info('.$q.')')->fetchAll();$byName=array_column($columns,null,'name');foreach($reference->query('PRAGMA table_info('.$q.')') as $col){if(!isset($byName[$col['name']]))throw new RuntimeException('Kolom skema tidak ada: '.$name.'.'.$col['name']);foreach(['type','pk'] as $field)if($byName[$col['name']][$field]!==$col[$field])throw new RuntimeException('Definisi kolom tidak kompatibel: '.$name.'.'.$col['name']);
if($byName[$col['name']]['notnull']!==$col['notnull']){
    $oldColumns=array_column($old->query('PRAGMA table_info('.$q.')')->fetchAll(),null,'name');
    $baseline=$oldColumns[$col['name']]??null;
    if(!$baseline||$baseline['notnull']!==$byName[$col['name']]['notnull']||$baseline['type']!==$byName[$col['name']]['type']||$baseline['pk']!==$byName[$col['name']]['pk'])throw new RuntimeException('Constraint kolom berubah/tidak kompatibel: '.$name.'.'.$col['name']);
    $structure['legacy_nullable_preserved'][]=$name.'.'.$col['name'];
}$structure['columns']++;}
            if($pdo->query('PRAGMA foreign_key_list('.$q.')')->fetchAll()!==$reference->query('PRAGMA foreign_key_list('.$q.')')->fetchAll())throw new RuntimeException('Definisi foreign key tidak kompatibel: '.$name);
            $actualIndexes=array_column($pdo->query('PRAGMA index_list('.$q.')')->fetchAll(),null,'name');foreach($reference->query('PRAGMA index_list('.$q.')') as $idx){if(!isset($actualIndexes[$idx['name']])||(int)$actualIndexes[$idx['name']]['unique']!==(int)$idx['unique'])throw new RuntimeException('Indeks unik tidak kompatibel: '.$idx['name']);}$structure['tables']++;}
        foreach($reference->query("SELECT name,tbl_name FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%'") as $index){$name=$index['name'];$q='"'.str_replace('"','""',$name).'"';$actual=$pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name=".$pdo->quote($name))->fetchColumn();if(!$actual||array_map(fn($x)=>array_diff_key($x,['cid'=>true]),$pdo->query('PRAGMA index_xinfo('.$q.')')->fetchAll())!==array_map(fn($x)=>array_diff_key($x,['cid'=>true]),$reference->query('PRAGMA index_xinfo('.$q.')')->fetchAll()))throw new RuntimeException('Indeks tidak ada/berbeda: '.$name);$structure['indexes']++;}
        $result=['integrity'=>$pdo->query('PRAGMA integrity_check')->fetchColumn(),'fk'=>$pdo->query('PRAGMA foreign_key_check')->fetchAll(),'preservation'=>[]];
        $result['structure']=$structure;
        if($result['integrity']!=='ok'||$result['fk'])throw new RuntimeException('Integrity/FK gagal: '.$db['label']);
        foreach($old->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $t){$n=$t['name'];if(in_array($n,['settings','db_registry','db_migrations','backups','system_update_migrations'],true))continue;$q='"'.str_replace('"','""',$n).'"';$columns=$old->query('PRAGMA table_info('.$q.')')->fetchAll();$cols=implode(',',array_map(fn($c)=>'"'.str_replace('"','""',$c['name']).'"',$columns));$hashes=[];foreach($pdo->query('SELECT '.$cols.' FROM '.$q) as $row){$key=hash('sha256',serialize($row));$hashes[$key]=($hashes[$key]??0)+1;}
            $count=0;foreach($old->query('SELECT '.$cols.' FROM '.$q) as $row){$key=hash('sha256',serialize($row));if(empty($hashes[$key]))throw new RuntimeException('Data lama berubah/hilang: '.$db['label'].' '.$n);$hashes[$key]--;$count++;}$result['preservation'][$n]=$count;
        }
        $result['version']=db_schema_version_of($db['path']);if(!$result['version']['terkini']||$result['version']['version']!==$m['schema_max'])throw new RuntimeException('Skema aktual tidak sesuai paket.');
        $result['registry']=db_registry_sync($db['path'],$db['kind'],$db['branch_id']);$report['databases'][$db['label']]=$result;
        file_put_contents($job['report'],json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    }
    $zip->close();$actual=json_decode((string)file_get_contents($app.'/app-version.json'),true,512,JSON_THROW_ON_ERROR);if($actual['version']!==$m['version'])throw new RuntimeException('Versi aplikasi tidak sesuai.');
    $testUser=(int)$central->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='super_admin' AND u.status='active' LIMIT 1")->fetchColumn();
    if(!$testUser)throw new RuntimeException('Super Admin aktif tidak tersedia untuk smoke test.');
    define('AI_PREVIEW_USER_ID',$testUser);$_SESSION['user_id']=$testUser;$_SESSION['last_seen']=time();
    // Dashboard uses the normal routed connection, proving tables/dependencies can be read.
    ob_start();require $app.'/dashboard.php';$html=ob_get_clean();if(strlen($html)<1000)throw new RuntimeException('Smoke test dashboard gagal.');
    $report['dashboard_bytes']=strlen($html);$report['ok']=true;file_put_contents($job['report'],json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
}catch(Throwable $e){if(isset($job['report'])){$report=$report??['ok'=>false,'databases'=>[]];$report['ok']=false;$report['error']=$e->getMessage();file_put_contents($job['report'],json_encode($report,JSON_PRETTY_PRINT));}fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
