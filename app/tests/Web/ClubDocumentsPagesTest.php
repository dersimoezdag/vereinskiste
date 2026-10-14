<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Web;
use PDO;
use FPDF;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\ClubDocuments\Repository;
use Vereinskiste\ClubDocuments\PdfStorage;
use Vereinskiste\Tests\TestCase;
use Vereinskiste\Update\DatabaseBackup;
use Vereinskiste\Update\DatabaseRestore;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
final class ClubDocumentsPagesTest extends TestCase
{
 use WebHarness;
 private PDO $pdo;
 protected function setUp(): void
 {
  $this->pdo=$this->pdo();(new Migrator($this->pdo,dirname(__DIR__,2).'/migrations'))->migrate();
  $this->store=new ArraySessionStore();$this->app=Kernel::create(['pdo'=>$this->pdo,'session_store'=>$this->store,'require_https'=>false,'security_dir'=>$this->securityDir(),'documents_dir'=>$this->securityDir().'/unused-document-files']);
 }
 private function data(array $override=[]): array
 {
  return $override+['title'=>'Satzung erste Fassung','label'=>'2026.1','document_date'=>'2026-10-14','meeting_type'=>'board','participants'=>'Anna, Ali','source'=>'editor','content'=>'<h2>Beschlüsse</h2><p><strong>Angenommen</strong></p><table><tr><td>TOP 1</td></tr></table>','status'=>'draft','generation'=>'0'];
 }
 private function publish(string $kind,array $data=[]): void
 {
  $this->assertSame(302,$this->postForm('/documents/'.$kind.'/new',$this->data(['status'=>'published']+$data))->getStatusCode());
 }
 public function test_new_draft_does_not_replace_current_and_public_archive_remains_available(): void
 {
  $this->loginAs(['secretary']);$this->publish('constitution');
  $this->assertSame(302,$this->postForm('/documents/constitution/1',$this->data(['title'=>'Zweite Fassung','label'=>'2026.2','generation'=>'1']))->getStatusCode());
  $html=(string)$this->get('/documents/constitution')->getBody();
  $this->assertStringContainsString('Satzung erste Fassung',$html);$this->assertStringContainsString('Zweite Fassung',$html);
  $this->pdo->exec("UPDATE user_roles SET role='auditor'");
  $html=(string)$this->get('/documents/constitution')->getBody();
  $this->assertStringContainsString('Satzung erste Fassung',$html);$this->assertStringNotContainsString('Zweite Fassung',$html);
  $this->assertSame(404,$this->get('/documents/constitution/1/versions/2')->getStatusCode());
  $this->assertSame(404,$this->get('/documents/constitution/1/pdf?version=2')->getStatusCode());
  $this->pdo->exec("UPDATE user_roles SET role='secretary'");
  $this->assertSame(302,$this->postForm('/documents/constitution/1',$this->data(['title'=>'Zweite Fassung','label'=>'2026.2','status'=>'published','generation'=>'2']))->getStatusCode());
  $this->pdo->exec("UPDATE user_roles SET role='treasurer'");
  $html=(string)$this->get('/documents/constitution')->getBody();
  $this->assertStringContainsString('Zweite Fassung',$html);$this->assertStringContainsString('/versions/1',$html);
  $this->assertSame(200,$this->get('/documents/constitution/1/versions/1')->getStatusCode());
  $this->assertSame(404,$this->get('/documents/rules/1')->getStatusCode());
  $this->assertSame(404,$this->get('/documents/constitution/1/versions/999')->getStatusCode());
 }
 public function test_minutes_are_newest_first_and_readers_cannot_access_confidential_content(): void
 {
  $this->loginAs(['secretary']);
  $this->publish('minutes',['title'=>'Alte Versammlung','meeting_type'=>'assembly','document_date'=>'2025-01-01']);
  $this->publish('minutes',['title'=>'Neue Versammlung','meeting_type'=>'assembly','document_date'=>'2026-01-01']);
  $this->publish('minutes',['title'=>'Vertraulicher Vorstand','meeting_type'=>'board']);
  $this->pdo->exec("UPDATE user_roles SET role='auditor'");
  $html=(string)$this->get('/documents/minutes')->getBody();
  $this->assertLessThan(strpos($html,'Alte Versammlung'),strpos($html,'Neue Versammlung'));
  $this->assertStringNotContainsString('Vertraulicher Vorstand',$html);
  $this->assertStringContainsString('Zum Anlegen und Hochladen benötigst du',$html);
  $this->assertStringNotContainsString('href="/documents/minutes/new"',$html);
  foreach (['/documents/minutes/3','/documents/minutes/3/pdf','/documents/minutes/1/versions/1','/documents/minutes/1/edit'] as $path) { $this->assertSame(404,$this->get($path)->getStatusCode()); }
  $this->assertSame(403,$this->postForm('/documents/minutes/new',$this->data())->getStatusCode());
  $this->assertSame(200,$this->get('/documents/minutes/1')->getStatusCode());
  $this->assertSame(200,$this->get('/documents/minutes/1/pdf')->getStatusCode());
  $this->assertStringContainsString('href="/documents/minutes/1/pdf"',(string)$this->get('/documents/minutes/1')->getBody());
 }
 public function test_editor_content_is_clean_exported_and_versions_cannot_be_overwritten(): void
 {
  $this->loginAs(['secretary']);
  $this->assertSame(302,$this->postForm('/documents/rules/new',$this->data(['content'=>'<p style="color:red">Text</p><script>alert(1)</script><img src="http://127.0.0.1/"><a href="javascript:alert(1)">Link</a>']))->getStatusCode());
  $root=(new Repository($this->pdo))->root('rules');$row=(new Repository($this->pdo))->version($root,null,true);
  foreach (['<script','<img','javascript:'] as $needle) { $this->assertStringNotContainsString($needle,$row['content']); }
  $this->assertStringContainsString('color:',$row['content']);
  $pdf=$this->get('/documents/rules/1/pdf?version=1');$this->assertSame(200,$pdf->getStatusCode());$this->assertStringStartsWith('%PDF-',(string)$pdf->getBody());
  $this->assertSame(409,$this->postForm('/documents/rules/1',$this->data())->getStatusCode());
  $this->assertSame(1,(int)$this->pdo->query('SELECT count(*) FROM club_document_versions')->fetchColumn());
  $this->expectException(\PDOException::class);$this->pdo->exec("UPDATE club_document_versions SET title='overwrite'");
 }
 private function upload(array $data,string $path='/documents/constitution/new',bool $large=false): array
 {
  $file=$this->securityDir().'/pdf-upload';$pdf=new FPDF();$pdf->AddPage();$pdf->SetFont('Helvetica','',10);$pdf->Cell(0,10,'Protokoll');$bytes=$pdf->Output('S');
  if ($large) { $bytes.=random_bytes(1500000); }
  file_put_contents($file,$bytes);
  $req=(new ServerRequestFactory())->createServerRequest('POST',$path)->withParsedBody(['_csrf'=>$this->csrf()]+$this->data(['source'=>'pdf']+$data))->withUploadedFiles(['receipt'=>new UploadedFile($file,'original.pdf','application/pdf',strlen($bytes),UPLOAD_ERR_OK)]);
  return [$this->app->handle($req),$bytes];
 }
 public function test_pdfs_live_in_postgresql_and_old_originals_remain_archived(): void
 {
  $this->loginAs(['secretary']);[$res,$first]=$this->upload(['status'=>'published']);$this->assertSame(302,$res->getStatusCode());
  [$res,$second]=$this->upload(['status'=>'published','generation'=>'1','label'=>'2026.2'],'/documents/constitution/1');$this->assertSame(302,$res->getStatusCode());
  $this->pdo->exec("UPDATE user_roles SET role='auditor'");
  $this->assertSame($first,(string)$this->get('/documents/constitution/1/pdf?version=1')->getBody());
  $this->assertSame($second,(string)$this->get('/documents/constitution/1/pdf')->getBody());
  $this->assertSame(0,(int)$this->pdo->query('SELECT count(*) FROM stored_files')->fetchColumn());
  $stats=(new \Vereinskiste\Backup\BackupService($this->pdo,dirname(__DIR__,2),$this->securityDir().'/receipts',$this->securityDir().'/unused-document-files',$this->securityDir().'/config','test'))->getStats();
  $this->assertSame(2,$stats['documents_count']);
  $this->assertSame(strlen($first)+strlen($second),$stats['documents_bytes']);
  $this->assertDirectoryDoesNotExist($this->securityDir().'/unused-document-files');
 }
 public function test_upload_conflict_rolls_back_all_pdf_chunks(): void
 {
  $this->loginAs(['secretary']);[$res]=$this->upload([]);$this->assertSame(302,$res->getStatusCode());
  [$res]=$this->upload(['generation'=>'0'],'/documents/constitution/1');$this->assertSame(409,$res->getStatusCode());
  $this->assertSame(1,(int)$this->pdo->query('SELECT count(*) FROM document_files')->fetchColumn());
  $this->assertSame(1,(int)$this->pdo->query('SELECT count(*) FROM document_file_chunks')->fetchColumn());
 }
 public function test_docx_upload_becomes_editable_text_and_exports_as_pdf(): void
 {
  $this->loginAs(['secretary']);$file=$this->securityDir().'/import.docx';
  $zip=new \ZipArchive();$zip->open($file,\ZipArchive::CREATE);
  $zip->addFromString('[Content_Types].xml','<Types/>');
  $zip->addFromString('word/document.xml','<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Importierter Beschluss</w:t></w:r></w:p></w:body></w:document>');$zip->close();
  $request=(new ServerRequestFactory())->createServerRequest('POST','/documents/constitution/new')
   ->withParsedBody(['_csrf'=>$this->csrf()]+$this->data(['source'=>'docx','status'=>'published','content'=>'']))
   ->withUploadedFiles(['receipt'=>new UploadedFile($file,'import.docx','application/vnd.openxmlformats-officedocument.wordprocessingml.document',filesize($file),UPLOAD_ERR_OK)]);
  $this->assertSame(302,$this->app->handle($request)->getStatusCode());
  $repo=new Repository($this->pdo);$row=$repo->version($repo->root('constitution'));
  $this->assertSame('editor',$row['source']);$this->assertNull($row['file_id']);
  $this->assertStringContainsString('Importierter Beschluss',$row['content']);
  $this->assertStringContainsString('Importierter Beschluss',(string)$this->get('/documents/constitution/1/edit')->getBody());
  $this->assertStringStartsWith('%PDF-',(string)$this->get('/documents/constitution/1/pdf')->getBody());
 }
 public function test_large_binary_pdf_is_included_in_database_backup_and_restores_exactly(): void
 {
  $this->loginAs(['secretary']);[$res,$bytes]=$this->upload(['status'=>'published'],large:true);$this->assertSame(302,$res->getStatusCode());
  $directory=$this->securityDir().'/binary-backup';$backup=new DatabaseBackup($this->pdo,$directory);$progress=$backup->start();
  for($i=0;$i<100 && empty($progress['done']);$i++) { $progress=$backup->step($progress); }
  $this->assertTrue($progress['done']);
  (new DatabaseRestore($this->pdo,$directory))->run();
  $this->assertSame($bytes,(new PdfStorage($this->pdo))->open(1)['bytes']);
  $this->assertSame('2026.1',(new Repository($this->pdo))->version((new Repository($this->pdo))->root('constitution'))['label']);
 }
 public function test_invalid_fields_csrf_and_singleton_creation_do_not_leave_documents(): void
 {
  $this->loginAs(['secretary']);
  foreach ([['document_date'=>'2026-02-30'],['label'=>''],['source'=>'pdf'],['content'=>'<script>x</script>']] as $data) { $this->assertSame(422,$this->postForm('/documents/rules/new',$this->data($data))->getStatusCode()); }
  $this->assertSame(0,(int)$this->pdo->query('SELECT count(*) FROM club_documents')->fetchColumn());
  $request=(new ServerRequestFactory())->createServerRequest('POST','/documents/rules/new')->withParsedBody($this->data());$this->assertSame(403,$this->app->handle($request)->getStatusCode());
  $this->publish('rules');$this->assertSame(409,$this->postForm('/documents/rules/new',$this->data())->getStatusCode());
  $this->assertSame(1,(int)$this->pdo->query("SELECT count(*) FROM club_documents WHERE kind='rules'")->fetchColumn());
  $html=(string)$this->get('/documents/rules')->getBody();foreach (['Dokumente','Protokolle','Satzung','Geschäftsordnung'] as $label) { $this->assertStringContainsString($label,$html); }
 }
}
