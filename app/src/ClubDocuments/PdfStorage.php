<?php
declare(strict_types=1);
namespace Vereinskiste\ClubDocuments;
use PDO;
use Vereinskiste\Files\FileStorage;
use Vereinskiste\Files\UploadValidator;
use Vereinskiste\Ledger\ValidationError;
final class PdfStorage
{
 public function __construct(private PDO $pdo) {}
 public function store(array $upload): int
 {
  $size=filesize($upload['path']);
  if (!$size) { throw new ValidationError(['receipt'=>'validation.receipt.empty']); }
  if ($size>10485760) { throw new ValidationError(['receipt'=>'validation.receipt.too_large']); }
  if ((new UploadValidator())->inspect($upload['path'])['mime'] !== 'application/pdf') { throw new ValidationError(['receipt'=>'validation.receipt.type']); }
  $bytes=file_get_contents($upload['path']);
  if ($bytes===false || strlen($bytes)!==$size) { throw new ValidationError(['receipt'=>'validation.receipt.upload_failed']); }
  $stmt=$this->pdo->prepare('INSERT INTO document_files(name,size,sha256) VALUES (?,?,?) RETURNING id');
  $stmt->execute([FileStorage::cleanName($upload['name'],'pdf'),$size,hash('sha256',$bytes)]); $id=(int)$stmt->fetchColumn();
  $stmt=$this->pdo->prepare("INSERT INTO document_file_chunks(file_id,part,content) VALUES (?,?,decode(?,'hex'))");
  foreach (str_split($bytes,131072) as $part=>$chunk) { $stmt->execute([$id,$part,bin2hex($chunk)]); }
  return $id;
 }
 public function open(int $id): ?array
 {
  $stmt=$this->pdo->prepare('SELECT name,size,sha256 FROM document_files WHERE id=?');$stmt->execute([$id]);$file=$stmt->fetch();
  if (!$file) { return null; }
  $stmt=$this->pdo->prepare("SELECT encode(content,'hex') FROM document_file_chunks WHERE file_id=? ORDER BY part");$stmt->execute([$id]);$bytes='';
  while (($chunk=$stmt->fetchColumn())!==false) { $bytes.=hex2bin($chunk); }
  if (strlen($bytes)!==(int)$file['size'] || !hash_equals($file['sha256'],hash('sha256',$bytes))) { throw new \RuntimeException('PDF integrity check failed'); }
  return $file+['bytes'=>$bytes];
 }
}
