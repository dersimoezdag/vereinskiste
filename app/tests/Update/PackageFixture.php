<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Update;

use Vereinskiste\Update\Files;
use Vereinskiste\Update\HttpClient;

trait PackageFixture
{
    private function package(string $root,string $migration=''): array
    {
        $files=['app/bootstrap.php'=>'<?php','app/cron.php'=>'<?php','app/update-bootstrap.php'=>'<?php',
            'app/src/Update/Runtime.php'=>'<?php','app/vendor/autoload.php'=>'<?php','app/VERSION'=>'1.1.0',
            'public/index.php'=>'<?php','public/assets/app.css'=>'body {}'];
        foreach (glob(dirname(__DIR__,2) . '/migrations/*.sql') as $path) { $files['app/migrations/' . basename($path)]=file_get_contents($path); }
        if ($migration!=='') { $files['app/migrations/0011_update_test.sql']=$migration; }
        $zip=new \ZipArchive(); $path=$root . '/fixture.zip'; $zip->open($path,\ZipArchive::CREATE);
        foreach ($files as $name=>$body) { $zip->addFromString($name,$body); } $zip->close();
        return $this->signed($root,file_get_contents($path),'1.1.0');
    }
    private function signed(string $root,string $zip,string $version): array
    {
        $pair=sodium_crypto_sign_keypair(); Files::mkdir($root);
        Files::json($root . '/update-trust.json',['key_id'=>'fixture','public_key'=>base64_encode(sodium_crypto_sign_publickey($pair))]);
        $manifest=json_encode(['key_id'=>'fixture','version'=>$version,'protocol'=>1,'package'=>'vereinskiste-' . $version . '.zip',
            'size'=>strlen($zip),'sha256'=>hash('sha256',$zip),'php'=>'8.4.0'],JSON_THROW_ON_ERROR);
        $signature=base64_encode(sodium_crypto_sign_detached($manifest,sodium_crypto_sign_secretkey($pair)));
        $url='https://github.com/dersimoezdag/vereinskiste/releases/download/' . $version . '/vereinskiste-' . $version . '.zip';
        $release=['status'=>'available','version'=>$version,'download'=>$url,'manifest'=>$url . '.manifest.json','signature'=>$url . '.manifest.sig'];
        $client=new HttpClient(static function(string $requested,int $limit,?array $range) use($manifest,$signature,$zip): array {
            $body=str_ends_with($requested,'.manifest.json')?$manifest:(str_ends_with($requested,'.manifest.sig')?$signature:$zip);
            if ($range!==null) { return ['status'=>206,'headers'=>['content-range'=>'bytes ' . $range[0] . '-' . $range[1] . '/' . strlen($zip)],'body'=>substr($zip,$range[0],$range[1]-$range[0]+1)]; }
            return ['status'=>200,'headers'=>[],'body'=>$body];
        });
        return [$release,$client];
    }
}
