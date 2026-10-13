<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

use ZipArchive;

final class Archive
{
    public function inspect(string $path,string $version): array
    {
        $zip=$this->open($path); $seen=[]; $total=0;
        try {
            if ($zip->numFiles>20000) { throw new Failure('archive'); }
            for ($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i); $name=$stat['name'];
                if (!preg_match('~^(app|public|docs)/[A-Za-z0-9_./@+-]+\z~',$name) && !in_array($name,['README.md','LICENSE'],true)) { throw new Failure('archive'); }
                if (str_contains($name,'..') || str_contains($name,'//') || isset($seen[strtolower($name)])
                    || preg_match('~^app/(config|storage)/.+~',$name) || ($stat['encryption_method']??0)!==0) { throw new Failure('archive'); }
                $seen[strtolower($name)]=true;
                $zip->getExternalAttributesIndex($i,$opsys,$attributes);
                $kind=($attributes>>16)&0170000;
                if ($kind!==0 && !in_array($kind,[0100000,0040000],true)) { throw new Failure('archive'); }
                $total+=$stat['size'];
                if ($stat['size']>8388608 || $total>268435456 || (str_ends_with($name,'.php') && $stat['size']>262144)) { throw new Failure('archive_limit'); }
            }
            foreach (['app/bootstrap.php','app/cron.php','app/update-bootstrap.php','app/VERSION','app/vendor/autoload.php',
                'app/src/Update/Runtime.php','public/index.php','public/assets/app.css'] as $required) {
                if ($zip->locateName($required)===false) { throw new Failure('archive'); }
            }
            if (trim((string)$zip->getFromName('app/VERSION'))!==$version) { throw new Failure('manifest'); }
            return ['entries'=>$zip->numFiles,'unpacked'=>$total];
        } finally { $zip->close(); }
    }
    public function extract(string $zipPath,string $root,int $offset): int
    {
        $zip=$this->open($zipPath); $deadline=microtime(true)+3; $bytes=0; $done=0;
        try {
            while ($offset<$zip->numFiles && $done<100 && $bytes<2097152 && microtime(true)<$deadline) {
                $stat=$zip->statIndex($offset); $name=$stat['name'];
                if (str_ends_with($name,'/')) { Files::mkdir($root . '/' . $name); }
                else {
                    $file=$root . '/' . $name; Files::mkdir(dirname($file));
                    if (is_link($file)) { throw new Failure('archive'); }
                    $stream=$zip->getStream($name); $target=fopen($file,'wb');
                    if (!$stream || !$target) { throw new Failure('storage'); }
                    try { if (stream_copy_to_stream($stream,$target)!==$stat['size']) { throw new Failure('archive'); } }
                    finally { fclose($stream); fclose($target); }
                    chmod($file,0600); $bytes+=$stat['size'];
                    if (str_ends_with($name,'.php')) {
                        try { token_get_all((string)file_get_contents($file),TOKEN_PARSE); } catch (\ParseError) { throw new Failure('php'); }
                    }
                }
                $offset++; $done++;
            }
            return $offset;
        } finally { $zip->close(); }
    }
    private function open(string $path): ZipArchive
    {
        $zip=new ZipArchive(); if ($zip->open($path,ZipArchive::RDONLY)!==true) { throw new Failure('archive'); } return $zip;
    }
}
