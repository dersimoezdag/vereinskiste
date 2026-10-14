<?php
declare(strict_types=1);
namespace Vereinskiste\ClubDocuments;
use PDO;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;
final class Repository
{
 public const KINDS=['minutes','constitution','rules'];
 public function __construct(private PDO $pdo) {}
 public function root(string $kind, ?int $id=null): ?array
 {
  $stmt=$this->pdo->prepare('SELECT * FROM club_documents WHERE kind=?'.($id===null?'':' AND id=?').' ORDER BY id LIMIT 1');
  $stmt->execute($id===null?[$kind]:[$kind,$id]);return $stmt->fetch() ?: null;
 }
 public function version(array $root, ?int $version=null, bool $writer=false): ?array
 {
  $number=$version ?? ($writer?$root['generation']:$root['published_version']);
  if (!$number) { return null; }
  $stmt=$this->pdo->prepare('SELECT v.*,d.kind,d.generation,d.published_version,f.name AS file_name,f.size AS file_size FROM club_document_versions v JOIN club_documents d ON d.id=v.document_id LEFT JOIN document_files f ON f.id=v.file_id WHERE document_id=? AND version=?');
  $stmt->execute([$root['id'],$number]);return $stmt->fetch() ?: null;
 }
 public function listing(bool $writer,int $page): array
 {
  $where=$writer?'':" AND v.status='published' AND v.meeting_type='assembly'";
  $stmt=$this->pdo->prepare('SELECT v.*,d.kind FROM club_documents d JOIN club_document_versions v ON v.document_id=d.id AND v.version='.($writer?'d.generation':'d.published_version')." WHERE d.kind='minutes'".$where.' ORDER BY v.document_date DESC,d.id DESC LIMIT 51 OFFSET ?');
  $stmt->bindValue(1,($page-1)*50,PDO::PARAM_INT);$stmt->execute();return $stmt->fetchAll();
 }
 public function history(array $root,bool $writer,int $page=1): array
 {
  $stmt=$this->pdo->prepare('SELECT version,label,title,document_date,status,created_at FROM club_document_versions WHERE document_id=?'.($writer?'':" AND status='published'").' ORDER BY version DESC LIMIT 51 OFFSET ?');
  $stmt->bindValue(1,$root['id'],PDO::PARAM_INT);$stmt->bindValue(2,($page-1)*50,PDO::PARAM_INT);$stmt->execute();return $stmt->fetchAll();
 }
 public function save(string $kind,?int $id,array $data,?array $upload,int $generation,int $actor): int
 {
  return Transaction::run($this->pdo,function() use($kind,$id,$data,$upload,$generation,$actor): int {
   if ($kind!=='minutes') { $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute(['club-document:'.$kind]); }
   $root=$id===null && $kind==='minutes'?null:$this->root($kind,$id);
   if ($root===null) {
    if ($id!==null || $generation!==0) { throw new Conflict(); }
    $stmt=$this->pdo->prepare('INSERT INTO club_documents(kind) VALUES (?) RETURNING id');$stmt->execute([$kind]);$id=(int)$stmt->fetchColumn();
   } else {
    $id=(int)$root['id'];$stmt=$this->pdo->prepare('SELECT * FROM club_documents WHERE id=? FOR UPDATE');$stmt->execute([$id]);$root=$stmt->fetch();
    if ((int)$root['generation']!==$generation) { throw new Conflict(); }
   }
   $old=$root ? $this->version($root,null,true) : null;
   $fileId=$data['source']==='pdf'?($old['file_id'] ?? null):null;
   if ($upload && $data['source']!=='pdf') { throw new ValidationError(['receipt'=>'documents.pdf_only']); }
   if ($upload) { $fileId=(new PdfStorage($this->pdo))->store($upload); }
   if ($data['source']==='pdf' && !$fileId) { throw new ValidationError(['receipt'=>'validation.receipt.none']); }
   $number=$generation+1;
   $stmt=$this->pdo->prepare('INSERT INTO club_document_versions(document_id,version,title,label,document_date,meeting_type,participants,source,content,file_id,status,actor_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
   $stmt->execute([$id,$number,$data['title'],$data['label'],$data['document_date'],$data['meeting_type'],$data['participants'],$data['source'],$data['content'],$fileId,$data['status'],$actor]);
   $this->pdo->prepare("UPDATE club_documents SET generation=?,published_version=CASE WHEN ?='published' THEN ? ELSE published_version END WHERE id=?")->execute([$number,$data['status'],$number,$id]);
   (new AuditLog($this->pdo))->record('document_saved',$actor,['document_id'=>$id,'version'=>$number,'status'=>$data['status']]);
   return $id;
  });
 }
}
