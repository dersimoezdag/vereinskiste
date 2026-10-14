<?php
declare(strict_types=1);
namespace Vereinskiste\ClubDocuments;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\ValidationError;
final class Input
{
 public static function read(array $body,string $kind): array
 {
  $data=Fields::read($body,['title'=>200,'label'=>100,'document_date'=>10,'meeting_type'=>20,'participants'=>5000,'source'=>10,'content'=>200000,'status'=>20],['title','document_date','source','status']);
  $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$data['document_date']);
  if (!$date || $date->format('Y-m-d')!==$data['document_date']) { throw new ValidationError(['document_date'=>'foundation.invalid']); }
  foreach (['source'=>['pdf','editor'],'status'=>['draft','published']] as $key=>$allowed) {
   if (!in_array($data[$key],$allowed,true)) { throw new ValidationError([$key=>'foundation.invalid']); }
  }
  if ($kind==='minutes' && !in_array($data['meeting_type'],['board','assembly'],true)) { throw new ValidationError(['meeting_type'=>'foundation.invalid']); }
  if ($kind!=='minutes' && $data['label']==='') { throw new ValidationError(['label'=>'foundation.required']); }
  if ($kind!=='minutes') { $data['meeting_type']=null;$data['participants']=''; }
  $data['content']=$data['source']==='editor' ? Content::clean($data['content']) : '';
  if ($data['source']==='editor' && trim(html_entity_decode(strip_tags($data['content'])))==='') { throw new ValidationError(['content'=>'foundation.required']); }
  return $data;
 }
}
