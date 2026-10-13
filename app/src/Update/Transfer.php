<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

final class Transfer
{
    public function __construct(private Runtime $runtime,private State $state,private HttpClient $http) {}
    public function step(array $data): array
    {
        $path=$this->state->path($data);
        switch ($data['phase']) {
            case 'manifest':
                $response=$this->http->get($data['urls']['manifest'],16384);
                $data['manifest_raw']=$response['body']; $data['phase']='signature'; break;
            case 'signature':
                $signature=$this->http->get($data['urls']['signature'],256)['body'];
                $trust=Files::read($this->runtime->instance . '/update-trust.json');
                $data['manifest']=(new Signature($trust??[]))->verify($data['manifest_raw'],$signature,$data['version']);
                $data['size']=$data['manifest']['size']; $data['downloaded']=0; $data['phase']='download';
                unset($data['manifest_raw']); break;
            case 'download':
                $start=$data['downloaded']; $end=min($data['size']-1,$start+524287);
                $response=$this->http->get($data['urls']['package'],524288,[$start,$end]);
                $expected='bytes ' . $start . '-' . $end . '/' . $data['size'];
                if (($response['status']!==206 || ($response['headers']['content-range']??'')!==$expected)
                    && !($start===0 && $end===$data['size']-1 && $response['status']===200)) { throw new Failure('range'); }
                if (strlen($response['body'])!==$end-$start+1) { throw new Failure('download'); }
                $stream=fopen($path . '/package.zip','c+b'); if (!$stream) { throw new Failure('storage'); }
                try { ftruncate($stream,$start); fseek($stream,$start); if (fwrite($stream,$response['body'])!==strlen($response['body'])) { throw new Failure('storage'); } }
                finally { fclose($stream); chmod($path . '/package.zip',0600); }
                $data['downloaded']=$end+1;
                if ($data['downloaded']===$data['size']) {
                    if (!hash_equals($data['manifest']['sha256'],hash_file('sha256',$path . '/package.zip'))) { throw new Failure('checksum'); }
                    $data['phase']='inspect';
                }
                break;
            case 'inspect':
                $info=(new Archive())->inspect($path . '/package.zip',$data['version']);
                $free=disk_free_space($this->runtime->directory());
                if ($free===false || $free<$info['unpacked']+134217728) { throw new Failure('space'); }
                $data+=$info; $data['extracted']=0; $data['phase']='extract'; break;
            case 'extract':
                $release=$this->runtime->directory() . '/releases/' . $data['id']; Files::mkdir($release);
                $data['extracted']=(new Archive())->extract($path . '/package.zip',$release,$data['extracted']);
                if ($data['extracted']===$data['entries']) {
                    Files::json($release . '/verified.json',$data['manifest']); $data['phase']='waiting';
                }
                break;
        }
        return $data;
    }
}
