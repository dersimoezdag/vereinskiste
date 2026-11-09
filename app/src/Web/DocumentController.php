<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Club\Profile;
use Vereinskiste\Files\DocumentAccess;
use Vereinskiste\Files\DocumentStore;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Receipts\UploadInput;

final class DocumentController extends AdminController
{
    public function __construct(private Closure $pdo, private array $roots) {}

    public function logo(Request $req, Response $res): Response
    {
        return $this->handleLogoUpload($req, $res, false);
    }

    public function logoDark(Request $req, Response $res): Response
    {
        return $this->handleLogoUpload($req, $res, true);
    }

    private function handleLogoUpload(Request $req, Response $res, bool $isDark): Response
    {
        $body = $this->body($req); $pdo = ($this->pdo)();
        try {
            Fields::read($body, ['reason' => 500], ['reason']);
            $upload = UploadInput::fromRequest($req) ?? throw new ValidationError(['receipt' => 'validation.receipt.none']);
            Transaction::run($pdo, function () use ($pdo, $upload, $body, $req, $isDark): void {
                $profile = new Profile($pdo);
                $version = Fields::version($body['version'] ?? null);
                if ($profile->get(true)['version'] !== $version) { throw new Conflict(); }
                $id = (new DocumentStore($pdo, $this->roots))->addImage($upload['path'], $upload['name'], $this->userId($req));
                $profile->setLogo($id, $version, $this->userId($req), $this->str($body, 'reason'), $isDark);
            });
        } catch (ValidationError $e) {
            return \Vereinskiste\Http\ErrorPage::render($req->getAttribute('view'), 422, 'foundation.logo',
                array_values($e->errors)[0], '/settings/club');
        } catch (Conflict) {
            return \Vereinskiste\Http\ErrorPage::render($req->getAttribute('view'), 409, 'foundation.logo', 'foundation.conflict', '/settings/club');
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/settings/club');
    }

    public function deleteLogo(Request $req, Response $res): Response
    {
        $body = $this->body($req); $pdo = ($this->pdo)();
        $isDark = ($body['target'] ?? '') === 'dark';
        try {
            Fields::read($body, ['reason' => 500], ['reason']);
            Transaction::run($pdo, function () use ($pdo, $body, $req, $isDark): void {
                $profile = new Profile($pdo);
                $version = Fields::version($body['version'] ?? null);
                if ($profile->get(true)['version'] !== $version) { throw new Conflict(); }
                $profile->removeLogo($version, $this->userId($req), $this->str($body, 'reason'), $isDark);
            });
        } catch (ValidationError $e) {
            return \Vereinskiste\Http\ErrorPage::render($req->getAttribute('view'), 422, 'foundation.logo',
                array_values($e->errors)[0], '/settings/club');
        } catch (Conflict) {
            return \Vereinskiste\Http\ErrorPage::render($req->getAttribute('view'), 409, 'foundation.logo', 'foundation.conflict', '/settings/club');
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/settings/club');
    }

    public function publicLogo(Request $req, Response $res, array $args = []): Response
    {
        $isDark = ($args['variant'] ?? '') === 'dark' || str_ends_with($req->getUri()->getPath(), '-dark');
        $pdo = ($this->pdo)();
        $profile = (new Profile($pdo))->get();
        $fileId = $isDark ? ($profile['logo_dark_id'] ?? null) : ($profile['logo_id'] ?? null);
        if ($fileId === null && $isDark) {
            $fileId = $profile['logo_id'] ?? null;
        }
        if ($fileId === null) {
            return $res->withStatus(404);
        }
        $file = (new DocumentStore($pdo, $this->roots))->open($fileId);
        if ($file === null) {
            return $res->withStatus(404);
        }
        $handle = fopen($file['path'], 'rb');
        if ($handle === false) {
            return $res->withStatus(404);
        }
        return $res->withBody(new \Slim\Psr7\Stream($handle))->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function download(Request $req, Response $res, array $args): Response
    {
        $pdo = ($this->pdo)(); $id = (int) $args['id'];
        if (!(new DocumentAccess($pdo))->canRead($id, $req->getAttribute('auth'))) { return $this->notFound($req); }
        $file = (new DocumentStore($pdo, $this->roots))->open($id);
        if ($file === null) { return $this->notFound($req); }
        $handle = fopen($file['path'], 'rb');
        if ($handle === false) { return $this->notFound($req); }
        return $res->withBody(new \Slim\Psr7\Stream($handle))->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Disposition', ReceiptController::contentDisposition($file['original_name']))
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
