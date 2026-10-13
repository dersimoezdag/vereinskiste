<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Text;

use PHPUnit\Framework\TestCase;

final class CatalogueTest extends TestCase
{
    public function test_literal_template_keys_have_german_translations(): void
    {
        $texts=require dirname(__DIR__,2).'/src/Text/de.php';
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__,2).'/templates',\FilesystemIterator::SKIP_DOTS));
        foreach($files as $file) {
            if($file->getExtension()!=='twig') {continue;}
            preg_match_all("/\\bt\\(\\s*'([a-z][a-z0-9_.]+)'\\s*[,)]/",file_get_contents($file->getPathname()),$matches);
            foreach($matches[1] as $key) {$this->assertArrayHasKey($key,$texts,$file->getPathname().' uses missing translation '.$key);}
        }
    }
    public function test_catalogue_files_do_not_overwrite_each_others_keys(): void
    {
        $seen=[];
        foreach(glob(dirname(__DIR__,2).'/src/Text/de/*.php') as $path) {
            $tokens=token_get_all(file_get_contents($path));
            foreach($tokens as $i=>$token) {
                if(!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING) {continue;}
                $next=$i+1;while(isset($tokens[$next])&&is_array($tokens[$next])&&$tokens[$next][0]===T_WHITESPACE) {$next++;}
                if(!isset($tokens[$next])||!is_array($tokens[$next])||$tokens[$next][0]!==T_DOUBLE_ARROW) {continue;}
                $key=substr($token[1],1,-1);$this->assertArrayNotHasKey($key,$seen,'Duplicate translation '.$key.' in '.$path);$seen[$key]=$path;
            }
        }
    }
}
