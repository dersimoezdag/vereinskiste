<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\BookingImport\BookingImportService;
use Vereinskiste\Events\EventRepository;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Http\ErrorPage;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\CategoryRepository;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Receipts\UploadInput;

final class BookingImportController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function index(Request $req, Response $res, array $errors = []): Response
    {
        return $req->getAttribute('view')->render($res, 'imports/bookings/index.twig', [
            'imports' => (new BookingImportService(($this->pdo)()))->list(),
            'accounts' => array_values(array_filter((new AccountRepository(($this->pdo)()))->all(), static fn (array $a): bool => $a['active'])),
            'errors' => $errors,
        ]);
    }

    public function upload(Request $req, Response $res): Response
    {
        try {
            $file = UploadInput::fromRequest($req);
            if ($file === null) { throw new ValidationError(['receipt' => 'booking_import.file_empty']); }
            $id = (new BookingImportService(($this->pdo)()))->upload($this->str($this->body($req), 'account_id'), $file['path'], $file['name'], $this->userId($req));
        } catch (ValidationError $e) {
            return $this->index($req, $res->withStatus(422), $e->errors);
        }
        return $this->redirect('/imports/bookings/' . $id);
    }

    public function show(Request $req, Response $res, array $args, array $errors = [], array $input = []): Response
    {
        $service = new BookingImportService(($this->pdo)());
        $import = $service->find((int) $args['id']);
        if ($import === null) { return $this->notFound($req); }
        $context = ['import' => $import, 'errors' => $errors, 'input' => $input];
        if ($import['status'] === 'draft') {
            $settings = $import['settings'];
            $requested = $req->getQueryParams()['sheet'] ?? ($input['sheet'] ?? ($settings['sheet'] ?? 0));
            $index = is_string($requested) || is_int($requested) ? (int) $requested : 0;
            $index = isset($import['sheets'][$index]) ? $index : 0;
            $sheet = $import['sheets'][$index];
            $configured = $settings !== [] && $settings['sheet'] === $index;
            $mapping = $configured ? $settings : $service->guess($sheet);
            $context += ['sheet_index' => $index, 'mapping' => $mapping, 'columns' => $service->columns($sheet, (int) $mapping['header_row']),
                'categories' => array_values(array_filter((new CategoryRepository(($this->pdo)()))->all(), static fn (array $c): bool => $c['active'])),
                'events' => (new EventRepository(($this->pdo)()))->options(),
                'preview' => $configured ? $service->preview($import) : null];
        }
        return $req->getAttribute('view')->render($res, 'imports/bookings/show.twig', $context);
    }

    public function action(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $service = new BookingImportService(($this->pdo)());
        $body = $this->body($req);
        $version = Fields::version($body['version'] ?? null);
        try {
            switch ($args['action']) {
                case 'mapping':
                    $service->saveMapping($id, $body, $version);
                    return $this->redirect('/imports/bookings/' . $id);
                case 'confirm':
                    $service->confirm($id, $body, $version, $this->userId($req));
                    $this->flash($req, 'booking_import.confirmed');
                    return $this->redirect('/imports/bookings/' . $id);
                default:
                    $service->delete($id);
                    $this->flash($req, 'booking_import.deleted');
                    return $this->redirect('/imports/bookings');
            }
        } catch (ValidationError $e) {
            return $this->show($req, $res->withStatus(422), $args, $e->errors, $body);
        } catch (Conflict) {
            return ErrorPage::render($req->getAttribute('view'), 409, 'error.409.title', 'error.409.text', '/imports/bookings/' . $id, 'common.back');
        }
    }
}
