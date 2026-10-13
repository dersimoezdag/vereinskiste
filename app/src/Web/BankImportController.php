<?php
declare(strict_types=1);
namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\BankImport\ConfirmService;
use Vereinskiste\BankImport\ParseService;
use Vereinskiste\BankImport\Parsers\CsvMapping;
use Vereinskiste\BankImport\Repository;
use Vereinskiste\BankImport\ReviewService;
use Vereinskiste\BankImport\Upload;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Receipts\UploadInput;

final class BankImportController extends AdminController
{
    public function __construct(private Closure $pdo, private string $directory) {}
    public function index(Request $req, Response $res): Response
    {
        $page = max(1, min(100000, (int) ($req->getQueryParams()['page'] ?? 1)));
        return $req->getAttribute('view')->render($res, 'imports/bank/index.twig', [
            'imports' => (new Repository(($this->pdo)()))->list($page), 'page' => $page]);
    }
    public function create(Request $req, Response $res, array $errors = []): Response
    {
        return $req->getAttribute('view')->render($res, 'imports/bank/new.twig', [
            'accounts' => ($this->pdo)()->query("SELECT id,name,iban,type FROM accounts WHERE active=true AND type='bank' ORDER BY name,id")->fetchAll(),
            'errors' => $errors]);
    }
    public function upload(Request $req, Response $res): Response
    {
        try {
            $file = UploadInput::fromRequest($req);
            if (!$file) { throw new ValidationError(['receipt' => 'bank.file.empty']); }
            $body = $this->body($req);
            $delimiter = $this->str($body, 'delimiter'); if ($delimiter === 'tab') { $delimiter = "\t"; }
            $id = (new Upload(($this->pdo)(), $this->directory))->create((int) $this->str($body, 'account_id'),
                $file['path'], $file['name'], $this->userId($req), $delimiter, $this->str($body, 'encoding'));
            return $this->redirect('/imports/bank/' . $id);
        } catch (ValidationError $e) { return $this->create($req, $res->withStatus(422), $e->errors); }
    }
    public function show(Request $req, Response $res, array $args, array $errors = []): Response
    {
        $id = (int) $args['id']; $db = ($this->pdo)(); $repo = new Repository($db); $import = $repo->find($id);
        if (!$import) { return $this->notFound($req); }
        $query = $req->getQueryParams(); $page = max(1, min(40, (int) ($query['page'] ?? 1))); $defaults = [];
        $profileId = Fields::version($query['profile'] ?? null);
        if ($profileId && $import['status'] === 'uploaded') {
            $stmt = $db->prepare('SELECT settings FROM bank_csv_profiles WHERE id=?'); $stmt->execute([$profileId]); $json = $stmt->fetchColumn();
            if ($json) {
                $defaults = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
                if (($defaults['headers'] ?? []) !== $import['settings']['headers']) {
                    $defaults = []; $errors['profile'] = 'bank.profile.columns';
                }
            }
        }
        return $req->getAttribute('view')->render($res, 'imports/bank/detail.twig', [
            'import' => $import, 'rows' => $repo->rows($id, $page), 'summary' => $repo->summary($id),
            'fields' => CsvMapping::FIELDS, 'profiles' => $repo->profiles(), 'defaults' => $defaults,
            'page' => $page, 'errors' => $errors]);
    }
    public function action(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id']; $db = ($this->pdo)();
        if (!(new Repository($db))->find($id)) { return $this->notFound($req); }
        $body = $this->body($req); $version = Fields::version($body['version'] ?? null); $actor = $this->userId($req);
        try {
            switch ($args['action']) {
                case 'mapping': (new ParseService($db, $this->directory))->configure($id, $version, $body, $actor); break;
                case 'parse': (new ParseService($db, $this->directory))->step($id); break;
                case 'decision': (new ReviewService($db))->decide($id, $version, (int) $this->str($body, 'row_number'), $body, $actor); break;
                case 'confirm':
                    if ($this->str($body, 'checked') !== '1') { throw new ValidationError(['confirm' => 'bank.confirm.checked']); }
                    (new ConfirmService($db))->step($id, $version, $actor); break;
                case 'cancel': (new ReviewService($db))->cancel($id, $version, $actor); break;
                case 'reset': (new ReviewService($db))->reset($id, $version, $this->str($body, 'reason'), $actor); break;
            }
        } catch (ValidationError $e) { return $this->show($req, $res->withStatus(422), $args, $e->errors); }
        catch (Conflict) { return $this->show($req, $res->withStatus(409), $args, ['version' => 'foundation.conflict']); }
        $page = max(1, min(40, (int) $this->str($body, 'page')));
        return $this->redirect('/imports/bank/' . $id . '?page=' . $page);
    }
    public function status(Request $req, Response $res, array $args): Response
    {
        $import = (new Repository(($this->pdo)()))->find((int) $args['id']);
        if (!$import) { return $this->notFound($req); }
        $res->getBody()->write(json_encode(['status' => $import['status'], 'parsed' => (int) $import['parsed_rows'],
            'total' => (int) $import['total_rows']], JSON_THROW_ON_ERROR));
        return $res->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
