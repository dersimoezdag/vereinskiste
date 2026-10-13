<?php
declare(strict_types=1);
namespace Vereinskiste\Intake;

final class MimeParts
{
    public static function flatten(object $structure): array
    {
        $parts = []; $visited = 0;
        self::walk($structure,'',0,$visited,$parts);
        return $parts;
    }
    private static function walk(object $node,string $section,int $depth,int &$visited,array &$parts): void
    {
        if (++$visited>40 || $depth>6) { throw new \DomainException('mime_limit'); }
        if (!empty($node->parts)) {
            if (($node->type??0)===2) { throw new \DomainException('nested_message'); }
            foreach ($node->parts as $i=>$child) { self::walk($child,$section===''?(string)($i+1):$section.'.'.($i+1),$depth+1,$visited,$parts); }
            return;
        }
        $params = [];
        foreach (array_merge($node->parameters??[],$node->dparameters??[]) as $p) { $params[strtolower($p->attribute)] = $p->value; }
        $types = ['TEXT','MULTIPART','MESSAGE','APPLICATION','AUDIO','IMAGE','VIDEO','OTHER'];
        $mime = strtolower(($types[$node->type??7]??'OTHER').'/'.($node->subtype??''));
        $name = $params['filename']??$params['name']??'';
        $text = in_array($mime,['text/plain','text/html'],true) && $name==='';
        if (!$text && !in_array($mime,['application/pdf','image/jpeg','image/png','application/xml','text/xml'],true)) {
            if ($name!=='' || strtoupper($node->disposition??'')==='ATTACHMENT') { throw new \DomainException('attachment_type'); }
            return; // HTML, links and remote content are never rendered or downloaded.
        }
        $parts[] = ['section'=>$section===''?'1':$section,'encoding'=>(int)($node->encoding??0),
            'name'=>$name!==''?mb_decode_mimeheader($name):'beleg','text'=>$text,'html'=>$mime==='text/html','charset'=>$params['charset']??'UTF-8'];
        if (count($parts)>12) { throw new \DomainException('attachment_limit'); }
    }
    public static function decode(string $body,int $encoding): string
    {
        $decoded = match($encoding) {3 => base64_decode($body,true),4 => quoted_printable_decode($body),0,1,2 => $body,default => false};
        if ($decoded===false || strlen($decoded)>10485760) { throw new \DomainException('attachment_limit'); }
        return $decoded;
    }
}
