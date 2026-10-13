<?php
/** Offline build tool. Private signing keys never belong in the web application. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit('CLI only');
try{
    if(count($argv)!==7)throw new RuntimeException('Usage: php build-system-update.php SOURCE MANIFEST_TEMPLATE PRIVATE_PEM KEY_ID OUTPUT_ZIP SYSTEM_WORK_DIR');
    [, $source,$template,$keyPath,$keyId,$output,$work]=$argv;require dirname(__DIR__).'/includes/system_update.php';
    $engine=new NaveenaSystemUpdate($source,$work);$keyReal=str_replace('\\','/',(string)realpath($keyPath));if(str_starts_with(strtolower($keyReal),strtolower($engine->app.'/')))throw new RuntimeException('Private key wajib berada di luar source.');$m=json_decode((string)file_get_contents($template),true,512,JSON_THROW_ON_ERROR);$v=$engine->version();
    $m['format']=1;$m['application']='Klinik Naveena';$m['kind']='update';$m['version']=$v['version'];$m['files']=$engine->inventory();$m['created_at']=date(DATE_ATOM);$m['auth']=['type'=>'rsa-sha256','key_id'=>$keyId];
    $engine->validateMigrations($m['migrations']??[]);$key=openssl_pkey_get_private((string)file_get_contents($keyPath));if(!$key)throw new RuntimeException('Private key tidak dapat dibaca.');
    $details=openssl_pkey_get_details($key);if(($details['type']??null)!==OPENSSL_KEYTYPE_RSA||($details['bits']??0)<2048)throw new RuntimeException('Signing memerlukan RSA minimal 2048 bit.');
    $json=json_encode($m,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(!openssl_sign($json,$sig,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('Penandatanganan gagal.');
    $z=new ZipArchive();if($z->open($output,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP tidak dapat dibuat.');foreach($m['files'] as $n=>$f){$z->addFile($engine->app.'/'.$n,'source/'.$n);$z->setCompressionName('source/'.$n,ZipArchive::CM_DEFLATE,6);}$z->addFromString('manifest.json',$json);$z->addFromString('manifest.sig',base64_encode($sig));if(!$z->close())throw new RuntimeException('ZIP tidak selesai.');
    $z=new ZipArchive();if($z->open($output,ZipArchive::CHECKCONS)!==true)throw new RuntimeException('ZIP gagal dibuka ulang.');foreach($m['files'] as $n=>$f){$stream=$z->getStream('source/'.$n);if(!$stream)throw new RuntimeException('File tidak lengkap.');$hash=hash_init('sha256');hash_update_stream($hash,$stream);fclose($stream);if(hash_final($hash)!==$f['sha256'])throw new RuntimeException('Checksum ZIP build berbeda.');}$z->close();echo 'Signed package built; SHA256 '.hash_file('sha256',$output).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
