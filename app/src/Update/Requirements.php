<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

final class Requirements
{
    public static function supported(Runtime $runtime): bool
    {
        $webRoot=realpath($_SERVER['DOCUMENT_ROOT']??''); $appRoot=realpath($runtime->instance);
        if ($webRoot!==false && $appRoot!==false && ($_SERVER['DOCUMENT_ROOT']??'')!=='') {
            $normalize=static fn(string $path): string=>strtolower(str_replace('\\','/',$path));
            if (str_starts_with($normalize($appRoot) . '/',$normalize($webRoot) . '/')) { return false; }
        }
        $memory=trim((string)ini_get('memory_limit'));
        $bytes=-1;
        if ($memory!=='-1') {
            if (!preg_match('/^(\d+)\s*([KMG]?)\z/i',$memory,$match)) { return false; }
            $bytes=(int)$match[1]*(match(strtoupper($match[2])) {'K'=>1024,'M'=>1048576,'G'=>1073741824,default=>1});
        }
        return ($bytes===-1 || $bytes>=134217728) && extension_loaded('zip') && extension_loaded('sodium')
            && (function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN))
            && is_file($runtime->instance . '/update-trust.json') && is_dir($runtime->instance . '/storage')
            && is_writable($runtime->instance . '/storage');
    }
}
