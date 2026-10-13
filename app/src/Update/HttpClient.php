<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

/** Bounded HTTPS GETs, including manually validated GitHub CDN redirects. */
final class HttpClient
{
    private const HOSTS=['github.com','release-assets.githubusercontent.com','objects.githubusercontent.com','github-releases.githubusercontent.com'];
    public function __construct(private ?\Closure $transport=null) {}
    public function get(string $url,int $limit,?array $range=null): array
    {
        for ($redirect=0;$redirect<4;$redirect++) {
            $parts=parse_url($url);
            if (!is_array($parts) || ($parts['scheme']??'')!=='https' || !in_array($parts['host']??'',self::HOSTS,true)
                || isset($parts['user']) || isset($parts['pass']) || (($parts['port']??443)!==443) || strlen($url)>4096) { throw new Failure('download_url'); }
            $response=$this->transport!==null ? ($this->transport)($url,$limit,$range) : $this->request($url,$limit,$range);
            if (strlen($response['body'])>$limit) { throw new Failure('download_limit'); }
            if (in_array($response['status'],[301,302,303,307,308],true)) { $url=$response['headers']['location']??''; continue; }
            if (!in_array($response['status'],[200,206],true)) { throw new Failure('download'); }
            return $response;
        }
        throw new Failure('download');
    }
    private function request(string $url,int $limit,?array $range): array
    {
        $headers=['User-Agent: Vereinskiste-Updater','Accept-Encoding: identity'];
        if ($range!==null) { $headers[]='Range: bytes=' . $range[0] . '-' . $range[1]; }
        if (!function_exists('curl_init')) { return $this->stream($url,$headers,$limit); }
        $handle=curl_init($url); $body=''; $responseHeaders=[]; $status=0;
        if ($handle===false) { throw new Failure('download'); }
        try {
            curl_setopt_array($handle,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT_MS=>2000,CURLOPT_TIMEOUT_MS=>4000,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>static function($unused,string $chunk) use(&$body,$limit,&$status): int {
                    if (in_array($status,[301,302,303,307,308],true)) { return strlen($chunk); }
                    if (strlen($body)+strlen($chunk)>$limit) { return 0; } $body.=$chunk; return strlen($chunk);
                },CURLOPT_HEADERFUNCTION=>static function($unused,string $header) use(&$responseHeaders,&$status): int {
                    if (preg_match('~^HTTP/\S+ ([0-9]{3})~',$header,$m)) { $status=(int)$m[1]; $responseHeaders=[]; }
                    if (preg_match('/^([A-Za-z-]+):\s*(.*?)\r?\n?$/',$header,$m)) { $responseHeaders[strtolower($m[1])]=trim($m[2]); }
                    return strlen($header);
                }]);
            if (curl_exec($handle)===false) { throw new Failure('download'); }
            return ['status'=>(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE),'headers'=>$responseHeaders,'body'=>$body];
        } finally { curl_close($handle); }
    }
    private function stream(string $url,array $headers,int $limit): array
    {
        if (!filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN)) { throw new Failure('requirements'); }
        $context=stream_context_create(['http'=>['timeout'=>4,'follow_location'=>0,'ignore_errors'=>true,'header'=>implode("\r\n",$headers) . "\r\n"],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $stream=@fopen($url,'rb',false,$context); if (!$stream) { throw new Failure('download'); }
        try {
            $metadata=stream_get_meta_data($stream); $status=0; $result=[];
            foreach ($metadata['wrapper_data']??[] as $header) {
                if (preg_match('~^HTTP/\S+ ([0-9]{3})~',$header,$m)) { $status=(int)$m[1]; }
                if (preg_match('/^([A-Za-z-]+):\s*(.*)$/',$header,$m)) { $result[strtolower($m[1])]=trim($m[2]); }
            }
            $body=in_array($status,[301,302,303,307,308],true)?'':stream_get_contents($stream,$limit+1);
            if ($body===false || stream_get_meta_data($stream)['timed_out'] || strlen($body)>$limit) { throw new Failure('download'); }
            return ['status'=>$status,'headers'=>$result,'body'=>$body];
        } finally { fclose($stream); }
    }
}
