<?php
declare(strict_types=1);

namespace Vereinskiste\Http;

use Closure;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use RuntimeException;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Vereinskiste\Text\Message;
use Vereinskiste\Auth\AuthService;
use Vereinskiste\Auth\RateLimiter;
use Vereinskiste\Auth\PasswordResetService;
use Vereinskiste\Mail\Mailer;
use Vereinskiste\Web\PasswordResetController;
use Vereinskiste\Web\InstallController;
use Vereinskiste\Web\UpdateController;
use Vereinskiste\Http\Middleware\Maintenance;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Db\Connection;
use Vereinskiste\Http\Middleware\Auth;
use Vereinskiste\Http\Middleware\Csrf;
use Vereinskiste\Http\Middleware\Session;
use Vereinskiste\Http\Middleware\TransportSecurity;
use Vereinskiste\Http\Session\NativeSessionStore;
use Vereinskiste\Http\Session\SessionStore;
use Vereinskiste\Ledger\MoneyFormat;
use Vereinskiste\Web\AccountController;
use Vereinskiste\Web\AuthController;
use Vereinskiste\Web\BookingController;
use Vereinskiste\Web\CategoryController;
use Vereinskiste\Web\CorrectionController;
use Vereinskiste\Web\ReceiptController;
use Vereinskiste\Web\ReportController;
use Vereinskiste\Web\SettingsController;
use Vereinskiste\Web\TransferController;
use Vereinskiste\Web\UserController;

final class Kernel
{
    public static function createInstaller(array $config = []): App
    {
        return self::create(['installer_mode' => true] + $config);
    }

    /**
     * Unterstuetzte Konfiguration (alle Schluessel optional):
     * - debug (bool)
     * - pdo (PDO) oder db (Verbindungsdaten fuer Connection::fromConfig, Verbindung erst bei Bedarf)
     * - session_store (SessionStore; Standard: NativeSessionStore)
     * - templates (Verzeichnis; Standard: app/templates)
     * - receipts_dir (Beleg-Verzeichnis ausserhalb des Webroots; Standard: app/storage/receipts)
     * - texts (array<string,string>; Standard: app/src/Text/de.php)
     *
     * @param array<string,mixed> $config
     */
    public static function create(array $config): App
    {
        $app = AppFactory::create();

        $appDir = $config['app_dir'] ?? dirname(__DIR__, 2);
        $store = $config['session_store'] ?? new NativeSessionStore($appDir . '/storage/sessions');
        if (!$store instanceof SessionStore) {
            throw new RuntimeException('session_store muss ein SessionStore sein.');
        }
        $pdoProvider = self::pdoProvider($config);
        $installerMode = (bool) ($config['installer_mode'] ?? false);
        $limiter = $config['rate_limiter'] ?? new RateLimiter($config['security_dir'] ?? $appDir . '/storage/security');
        $migrationDir = $config['migration_dir'] ?? $appDir . '/migrations';
        $texts = $config['texts'] ?? require dirname(__DIR__) . '/Text/de.php';
        $templates = $config['templates'] ?? dirname(__DIR__, 2) . '/templates';
        $mailAccounts = new \Vereinskiste\Mail\MailAccounts($appDir . '/config', $config['smtp'] ?? []);
        $mailer = $config['mailer'] ?? new Mailer($mailAccounts->configuration());
        $resetService = new PasswordResetService($pdoProvider, $mailer, rtrim($config['base_url'] ?? '', '/'), $texts, $limiter);

        $state = new RequestState();
        $view = Twig::create($templates, ['cache' => false, 'autoescape' => 'html']);
        $env = $view->getEnvironment();
        $env->addFunction(new TwigFunction(
            't',
            static fn (string $key): string => Message::resolve($texts, $key)
        ));
        $env->addFunction(new TwigFunction(
            'csrf_field',
            static fn (): string => '<input type="hidden" name="' . Csrf::FIELD . '" value="'
                . htmlspecialchars(Csrf::token($store), ENT_QUOTES) . '">',
            ['is_safe' => ['html']]
        ));
        $env->addFunction(new TwigFunction(
            'can',
            static fn (string $permission): bool => $state->auth?->can($permission) ?? false
        ));
        $env->addFunction(new TwigFunction(
            'flash',
            // Einmalige Meldung: Textschluessel aus der Sitzung lesen und entfernen.
            static function () use ($store, $texts): ?string {
                $key = $store->get('flash');
                if (!is_string($key)) {
                    return null;
                }
                $store->remove('flash');
                return $texts[$key] ?? $key;
            }
        ));
        $env->addFunction(new TwigFunction(
            'current_path',
            static fn (): string => $state->path
        ));
        $env->addFilter(new TwigFilter('eur', [MoneyFormat::class, 'eur']));
        $runtime = new \Vereinskiste\Update\Runtime($appDir);
        $env->addFunction(new TwigFunction('asset', static fn (string $path): string => $runtime->asset($path)));
        $env->addFunction(new TwigFunction('system_mail_available', static fn (): bool => $mailer->isConfigured()));
        $env->addFunction(new TwigFunction(
            'password_reset_available',
            static fn (): bool => $resetService->isConfigured()
        ));
        $env->addFunction(new TwigFunction(
            'current_user',
            static fn (): ?array => $state->auth?->currentUser()
        ));
        $env->addFunction(new TwigFunction(
            'pending_applications_count',
            static function () use ($pdoProvider, $state): int {
                if (!($state->auth?->can('members.read') ?? false)) {
                    return 0;
                }
                try {
                    return (new \Vereinskiste\Members\ApplicationRepository($pdoProvider()))->countPending();
                } catch (\Throwable) {
                    return 0;
                }
            }
        ));

        // Ausfuehrung von aussen nach innen: Fehler, Session, Kontext, Body-Parsing, CSRF, Routing.
        $app->addRoutingMiddleware();
        if (!$installerMode && ($config['maintenance'] ?? false)) {
            $app->add(new Maintenance($pdoProvider, $migrationDir, $runtime));
            $app->add(new \Vereinskiste\Http\Middleware\UpdateBarrier($pdoProvider,$runtime));
        }
        $app->add(new Csrf($store, $view));
        $app->addBodyParsingMiddleware();
        $app->add(function (Request $req, Handler $next) use ($state, $store, $view, $pdoProvider, $installerMode, $limiter): Response {
            $auth = $installerMode ? null : new AuthService(new UserRepository($pdoProvider), $store, null, $limiter);
            $state->auth = $auth;
            $state->path = $req->getUri()->getPath();
            return $next->handle(
                $req->withAttribute('auth', $auth)->withAttribute('view', $view)->withAttribute('session', $store)
            );
        });
        $app->add(new Session($store));
        $app->addErrorMiddleware((bool) ($config['debug'] ?? false), true, true);
        $app->add(new TransportSecurity((bool) ($config['require_https'] ?? true), $config['base_url'] ?? ''));

        $app->get('/health', function (Request $req, Response $res): Response {
            $res->getBody()->write('ok');
            return $res->withHeader('Content-Type', 'text/plain; charset=utf-8');
        });

        if ($installerMode) {
            $installer = new InstallController($appDir, $limiter);
            $app->get('/install', [$installer, 'show']);
            $app->post('/install/unlock', [$installer, 'unlock']);
            $app->post('/install', [$installer, 'install']);
            $app->map(['GET', 'POST'], '/{path:.*}', static fn (Request $req, Response $res): Response =>
                $res->withStatus(302)->withHeader('Location', '/install'));
            return $app;
        }
        $versionFile = ($config['code_dir'] ?? $appDir) . '/VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '–';
        $updater = new UpdateController($pdoProvider, $migrationDir, $version,
            $config['release_checker'] ?? new \Vereinskiste\Install\GitHubReleases());
        $automatic=new \Vereinskiste\Web\AutoUpdateController($pdoProvider,$runtime,$version,$migrationDir,
            $config['release_checker']??new \Vereinskiste\Install\GitHubReleases());
        $app->get('/settings/auto-update',[$automatic,'show'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/auto-update/start',[$automatic,'start'])->add(Auth::requires('settings.manage'));
        $app->get('/settings/auto-update/status',[$automatic,'status'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/auto-update/step',[$automatic,'step'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/auto-update/cancel',[$automatic,'cancel'])->add(Auth::requires('settings.manage'));
        $app->get('/_update-probe/{token:[a-f0-9]{64}}',[$automatic,'probe']);
        $app->get('/settings', [$updater, 'show'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/check-updates', [$updater, 'check'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/update', [$updater, 'update'])->add(Auth::requires('settings.manage'));
        $app->get('/update', [$updater, 'show'])->add(Auth::requires('settings.manage'));
        $app->post('/update', [$updater, 'update'])->add(Auth::requires('settings.manage'));

        $auth = new AuthController();
        $home = new \Vereinskiste\Web\HomeDashboardController($pdoProvider);
        $app->get('/', [$home, 'index'])->add(Auth::authenticated());
        $app->get('/dashboard/preferences', [$home, 'preferences'])->add(Auth::authenticated());
        $app->post('/dashboard/preferences', [$home, 'save'])->add(Auth::authenticated());
        $app->get('/login', [$auth, 'showLogin']);
        $app->post('/login', [$auth, 'login']);
        $app->post('/logout', [$auth, 'logout']);
        $resets = new PasswordResetController($resetService);
        $app->get('/password-reset', [$resets, 'showRequest']);
        $app->post('/password-reset', [$resets, 'request']);
        $app->get('/password-reset/{token}', [$resets, 'showConfirm']);
        $app->post('/password-reset/{token}', [$resets, 'confirm']);

        $accounts = new AccountController($pdoProvider);
        $app->get('/accounts', [$accounts, 'index'])->add(Auth::requires('bookings.read'));
        $app->post('/accounts', [$accounts, 'create'])->add(Auth::requires('settings.manage'));
        $app->post('/accounts/{id:[0-9]+}', [$accounts, 'rename'])->add(Auth::requires('settings.manage'));
        $app->post('/accounts/{id:[0-9]+}/toggle', [$accounts, 'toggle'])->add(Auth::requires('settings.manage'));
        $accountBank = new \Vereinskiste\Web\AccountBankController($pdoProvider);
        $app->get('/accounts/{id:[0-9]+}/bank', [$accountBank, 'show'])->add(Auth::requires('settings.manage'));
        $app->post('/accounts/{id:[0-9]+}/bank', [$accountBank, 'save'])->add(Auth::requires('settings.manage'));

        $categories = new CategoryController($pdoProvider);
        $app->get('/categories', [$categories, 'index'])->add(Auth::requires('bookings.read'));
        $app->post('/categories', [$categories, 'create'])->add(Auth::requires('settings.manage'));
        $app->post('/categories/{id:[0-9]+}', [$categories, 'rename'])->add(Auth::requires('settings.manage'));
        $app->post('/categories/{id:[0-9]+}/toggle', [$categories, 'toggle'])->add(Auth::requires('settings.manage'));

        $receiptsDir = $config['receipts_dir'] ?? dirname(__DIR__, 2) . '/storage/receipts';
        if (!is_string($receiptsDir) || trim($receiptsDir) === '') {
            throw new RuntimeException('receipts_dir muss ein Verzeichnispfad sein.');
        }

        $bookings = new BookingController($pdoProvider, $receiptsDir);
        $app->get('/bookings', [$bookings, 'index'])->add(Auth::requires('bookings.read'));
        $app->get('/bookings/new', [$bookings, 'showNew'])->add(Auth::requires('bookings.write'));
        $app->post('/bookings/new', [$bookings, 'create'])->add(Auth::requires('bookings.write'));
        $app->get('/bookings/{id:[0-9]+}', [$bookings, 'show'])->add(Auth::requires('bookings.read'));
        $app->get('/bookings/{id:[0-9]+}/edit', [$bookings, 'showEdit'])->add(Auth::requires('bookings.write'));
        $app->post('/bookings/{id:[0-9]+}/edit', [$bookings, 'update'])->add(Auth::requires('bookings.write'));
        $app->post('/bookings/{id:[0-9]+}/cancel', [$bookings, 'cancel'])->add(Auth::requires('bookings.write'));
        $app->post('/bookings/{id:[0-9]+}/restore', [$bookings, 'restore'])->add(Auth::requires('bookings.write'));

        $corrections = new CorrectionController($pdoProvider);
        $app->get('/corrections/new', [$corrections, 'showCorrection'])->add(Auth::requires('bookings.write'));
        $app->post('/corrections/new', [$corrections, 'createCorrection'])->add(Auth::requires('bookings.write'));

        $transfers = new TransferController($pdoProvider);
        $app->get('/transfers/new', [$transfers, 'showTransfer'])->add(Auth::requires('bookings.write'));
        $app->post('/transfers/new', [$transfers, 'createTransfer'])->add(Auth::requires('bookings.write'));

        $receipts = new ReceiptController($pdoProvider, $receiptsDir);
        $app->post('/bookings/{id:[0-9]+}/receipts', [$receipts, 'upload'])->add(Auth::requires('bookings.write'));
        $app->post('/receipts/{id:[0-9]+}/replace', [$receipts, 'replace'])->add(Auth::requires('bookings.write'));
        $app->get('/receipts/{id:[0-9]+}', [$receipts, 'download'])->add(Auth::requires('bookings.read'));

        $reports = new ReportController($pdoProvider, $texts);
        $app->get('/reports/summary', [$reports, 'summary'])->add(Auth::requires('reports.read'));
        $app->get('/reports/dashboard', [$reports, 'dashboard'])->add(Auth::requires('reports.read'));
        $app->get('/reports/bookings.csv', [$reports, 'csv'])->add(Auth::requires('reports.read'));
        $app->get('/reports/summary.pdf', [$reports, 'pdf'])->add(Auth::requires('reports.read'));

        $mailSettings = new \Vereinskiste\Web\MailSettingsController($mailAccounts);
        $app->get('/settings/email', [$mailSettings, 'show'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/email', [$mailSettings, 'save'])->add(Auth::requires('settings.manage'));

        $settings = new SettingsController($pdoProvider);
        $club = new \Vereinskiste\Web\ClubController($pdoProvider);
        $documents = new \Vereinskiste\Web\DocumentController($pdoProvider,
            ['receipts' => $receiptsDir, 'documents' => $config['documents_dir'] ?? $appDir . '/storage/documents']);
        $app->post('/settings/club/logo', [$documents, 'logo'])->add(Auth::requires('settings.manage'));
        $app->get('/documents/{id:[0-9]+}', [$documents, 'download'])->add(Auth::authenticated());
        $app->get('/settings/club', [$club, 'show'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/club', [$club, 'save'])->add(Auth::requires('settings.manage'));
        $contacts = new \Vereinskiste\Web\ContactController($pdoProvider);
        $bankImports = new \Vereinskiste\Web\BankImportController($pdoProvider, $config['documents_dir'] ?? $appDir . '/storage/documents');
        $app->get('/imports/bank', [$bankImports, 'index'])->add(Auth::requires('imports.read'));
        $app->get('/imports/bank/new', [$bankImports, 'create'])->add(Auth::requires('imports.write'));
        $app->post('/imports/bank/new', [$bankImports, 'upload'])->add(Auth::requires('imports.write'));
        $app->get('/imports/bank/{id:[0-9]+}', [$bankImports, 'show'])->add(Auth::requires('imports.read'));
        $app->get('/imports/bank/{id:[0-9]+}/status', [$bankImports, 'status'])->add(Auth::requires('imports.read'));
        $app->post('/imports/bank/{id:[0-9]+}/{action:mapping|parse|decision|confirm|cancel|reset}', [$bankImports, 'action'])->add(Auth::requires('imports.write'));
        $app->get('/contacts', [$contacts, 'index'])->add(Auth::requires('contacts.read'));
        $app->get('/contacts.csv', [$contacts, 'csv'])->add(Auth::requires('contacts.read'));
        $app->get('/contacts/new', [$contacts, 'create'])->add(Auth::requires('contacts.write'));
        $app->post('/contacts/new', [$contacts, 'save'])->add(Auth::requires('contacts.write'));
        $app->get('/contacts/{id:[0-9]+}', [$contacts, 'show'])->add(Auth::requires('contacts.read'));
        $app->post('/contacts/{id:[0-9]+}', [$contacts, 'save'])->add(Auth::requires('contacts.write'));

        $onlineApp = new \Vereinskiste\Web\MembershipApplicationController($pdoProvider, $limiter);
        $app->get('/antrag/danke', [$onlineApp, 'thankYou']);
        $app->get('/antrag/{token}', [$onlineApp, 'show']);
        $app->post('/antrag/{token}/unlock', [$onlineApp, 'unlock']);
        $app->post('/antrag/{token}', [$onlineApp, 'submit']);

        $decisions = new \Vereinskiste\Web\ApplicationDecisionController($pdoProvider);
        $app->get('/members/applications/{id:[0-9]+}/decision', [$decisions, 'show'])->add(Auth::requires('members.write'));
        $app->post('/members/applications/{id:[0-9]+}/decision', [$decisions, 'save'])->add(Auth::requires('members.write'));
        $app->post('/members/application-mails/{id:[0-9]+}/retry', [$decisions, 'retry'])->add(Auth::requires('members.write'));
        $privacy = new \Vereinskiste\Web\PrivacyController($pdoProvider);
        $app->get('/members/applications/{id:[0-9]+}/privacy', [$privacy, 'application'])->add(Auth::requires('settings.manage'));
        $app->post('/members/applications/{id:[0-9]+}/anonymize', [$privacy, 'anonymizeApplication'])->add(Auth::requires('settings.manage'));
        $app->get('/settings/privacy', [$privacy, 'settings'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/privacy', [$privacy, 'saveSettings'])->add(Auth::requires('settings.manage'));
        $app->get('/members/{id:[0-9]+}/privacy', [$privacy, 'member'])->add(Auth::requires('settings.manage'));
        $app->post('/members/{id:[0-9]+}/consents', [$privacy, 'consent'])->add(Auth::requires('settings.manage'));
        $app->get('/members/{id:[0-9]+}/disclosure.json', [$privacy, 'disclosure'])->add(Auth::requires('settings.manage'));
        $app->post('/members/{id:[0-9]+}/anonymize', [$privacy, 'anonymize'])->add(Auth::requires('settings.manage'));
        $intake = new \Vereinskiste\Web\IntakeController($pdoProvider);
        $app->get('/settings/payments', [$intake, 'paymentSettings'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/payments', [$intake, 'savePaymentSettings'])->add(Auth::requires('settings.manage'));
        $app->get('/receipt-inbox', [$intake, 'index'])->add(Auth::requires('invoices.read'));
        $app->post('/invoices/{id:[0-9]+}/ocr/{document:[0-9]+}', [$intake, 'ocr'])->add(Auth::requires('invoices.write'));
        $app->post('/invoices/{id:[0-9]+}/payment', [$intake, 'payment'])->add(Auth::requires('invoices.write'));
        $invoices = new \Vereinskiste\Web\InvoiceController($pdoProvider, $config['documents_dir'] ?? $appDir . '/storage/documents');
        $app->get('/invoices', [$invoices, 'index'])->add(Auth::requires('invoices.read'));
        $app->get('/invoices/new', [$invoices, 'create'])->add(Auth::requires('invoices.write'));
        $app->post('/invoices/new', [$invoices, 'save'])->add(Auth::requires('invoices.write'));
        $app->get('/invoices/import', [$invoices, 'import'])->add(Auth::requires('invoices.write'));
        $app->post('/invoices/import', [$invoices, 'upload'])->add(Auth::requires('invoices.write'));
        $app->get('/invoices/{id:[0-9]+}', [$invoices, 'show'])->add(Auth::requires('invoices.read'));
        $app->post('/invoices/{id:[0-9]+}', [$invoices, 'save'])->add(Auth::requires('invoices.write'));
        $app->post('/invoices/{id:[0-9]+}/documents', [$invoices, 'upload'])->add(Auth::requires('invoices.write'));
        $app->post('/invoices/{id:[0-9]+}/status', [$invoices, 'transition'])->add(Auth::requires('invoices.write'));
        $members = new \Vereinskiste\Web\MemberController($pdoProvider);
        $app->get('/members', [$members, 'index'])->add(Auth::requires('members.read'));
        $app->get('/members.csv', [$members, 'csv'])->add(Auth::requires('members.read'));
        $app->get('/members/applications', [$members, 'applications'])->add(Auth::requires('members.write'));
        $app->post('/members/invites/new', [$members, 'createInvite'])->add(Auth::requires('members.write'));
        $app->post('/members/applications/{id:[0-9]+}/approve', [$members, 'approveApplication'])->add(Auth::requires('members.write'));
        $app->post('/members/applications/{id:[0-9]+}/reject', [$members, 'rejectApplication'])->add(Auth::requires('members.write'));
        $app->get('/members/new', [$members, 'create'])->add(Auth::requires('members.write'));
        $app->post('/members/new', [$members, 'save'])->add(Auth::requires('members.write'));
        $app->get('/members/{id:[0-9]+}', [$members, 'show'])->add(Auth::requires('members.read'));
        $app->post('/members/{id:[0-9]+}', [$members, 'save'])->add(Auth::requires('members.write'));
        $app->post('/members/{id:[0-9]+}/join', [$members, 'join'])->add(Auth::requires('members.write'));
        $app->post('/members/{id:[0-9]+}/leave', [$members, 'leave'])->add(Auth::requires('members.write'));
        $app->post('/members/{id:[0-9]+}/user', [$members, 'linkUser'])->add(Auth::requires('users.manage'));
        $app->post('/members/{id:[0-9]+}/user/unlink', [$members, 'unlinkUser'])->add(Auth::requires('users.manage'));
        $app->post('/members/{id:[0-9]+}/club-roles', [$members, 'assignRole'])->add(Auth::requires('members.write'));
        $app->post('/members/{id:[0-9]+}/club-roles/{assignment:[0-9]+}/end', [$members, 'endRole'])->add(Auth::requires('members.write'));

        $clubRoles = new \Vereinskiste\Web\ClubRoleController($pdoProvider);
        $app->get('/settings/club-roles', [$clubRoles, 'index'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/club-roles', [$clubRoles, 'create'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/club-roles/{id:[0-9]+}', [$clubRoles, 'update'])->add(Auth::requires('settings.manage'));

        $app->get('/settings/closing', [$settings, 'closing'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/closing', [$settings, 'saveClosing'])->add(Auth::requires('settings.manage'));
        $app->get('/audit-log', [$settings, 'auditLog'])->add(Auth::requires('settings.manage'));

        $users = new UserController($pdoProvider);
        $app->get('/users', [$users, 'index'])->add(Auth::requires('users.manage'));
        $app->get('/users/new', [$users, 'showNew'])->add(Auth::requires('users.manage'));
        $app->post('/users/new', [$users, 'create'])->add(Auth::requires('users.manage'));
        $app->get('/users/{id:[0-9]+}/edit', [$users, 'showEdit'])->add(Auth::requires('users.manage'));
        $app->post('/users/{id:[0-9]+}/edit', [$users, 'update'])->add(Auth::requires('users.manage'));
        $app->post('/users/{id:[0-9]+}/password', [$users, 'resetPassword'])->add(Auth::requires('users.manage'));
        $app->get('/account/password', [$users, 'showOwnPassword'])->add(Auth::authenticated());
        $app->post('/account/password', [$users, 'changeOwnPassword'])->add(Auth::authenticated());

        return $app;
    }

    /** @param array<string,mixed> $config */
    private static function pdoProvider(array $config): Closure
    {
        $pdo = $config['pdo'] ?? null;
        $db = $config['db'] ?? null;

        return static function () use (&$pdo, $db): PDO {
            if ($pdo instanceof PDO) {
                return $pdo;
            }
            if (!is_array($db)) {
                throw new RuntimeException('Keine Datenbankverbindung konfiguriert.');
            }
            return $pdo = Connection::fromConfig($db);
        };
    }
}
