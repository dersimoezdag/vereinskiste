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
use Vereinskiste\Auth\AuthService;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Db\Connection;
use Vereinskiste\Http\Middleware\Auth;
use Vereinskiste\Http\Middleware\Csrf;
use Vereinskiste\Http\Middleware\Session;
use Vereinskiste\Http\Session\NativeSessionStore;
use Vereinskiste\Http\Session\SessionStore;
use Vereinskiste\Ledger\MoneyFormat;
use Vereinskiste\Web\AccountController;
use Vereinskiste\Web\AuthController;
use Vereinskiste\Web\BookingController;
use Vereinskiste\Web\CategoryController;

final class Kernel
{
    /**
     * Unterstuetzte Konfiguration (alle Schluessel optional):
     * - debug (bool)
     * - pdo (PDO) oder db (Verbindungsdaten fuer Connection::fromConfig, Verbindung erst bei Bedarf)
     * - session_store (SessionStore; Standard: NativeSessionStore)
     * - templates (Verzeichnis; Standard: app/templates)
     * - texts (array<string,string>; Standard: app/src/Text/de.php)
     *
     * @param array<string,mixed> $config
     */
    public static function create(array $config): App
    {
        $app = AppFactory::create();

        $store = $config['session_store'] ?? new NativeSessionStore();
        if (!$store instanceof SessionStore) {
            throw new RuntimeException('session_store muss ein SessionStore sein.');
        }
        $pdoProvider = self::pdoProvider($config);
        $texts = $config['texts'] ?? require dirname(__DIR__) . '/Text/de.php';
        $templates = $config['templates'] ?? dirname(__DIR__, 2) . '/templates';

        $state = new RequestState();
        $view = Twig::create($templates, ['cache' => false, 'autoescape' => 'html']);
        $env = $view->getEnvironment();
        $env->addFunction(new TwigFunction(
            't',
            static fn (string $key): string => $texts[$key] ?? $key
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
        $env->addFunction(new TwigFunction(
            'current_user',
            static fn (): ?array => $state->auth?->currentUser()
        ));

        // Ausfuehrung von aussen nach innen: Fehler, Session, Kontext, Body-Parsing, CSRF, Routing.
        $app->addRoutingMiddleware();
        $app->add(new Csrf($store, $view));
        $app->addBodyParsingMiddleware();
        $app->add(function (Request $req, Handler $next) use ($state, $store, $view, $pdoProvider): Response {
            $auth = new AuthService(new UserRepository($pdoProvider), $store);
            $state->auth = $auth;
            $state->path = $req->getUri()->getPath();
            return $next->handle(
                $req->withAttribute('auth', $auth)->withAttribute('view', $view)->withAttribute('session', $store)
            );
        });
        $app->add(new Session($store));
        $app->addErrorMiddleware((bool) ($config['debug'] ?? false), true, true);

        $app->get('/health', function (Request $req, Response $res): Response {
            $res->getBody()->write('ok');
            return $res->withHeader('Content-Type', 'text/plain; charset=utf-8');
        });

        $auth = new AuthController();
        $app->get('/', [$auth, 'home'])->add(Auth::authenticated());
        $app->get('/login', [$auth, 'showLogin']);
        $app->post('/login', [$auth, 'login']);
        $app->post('/logout', [$auth, 'logout']);

        $accounts = new AccountController($pdoProvider);
        $app->get('/accounts', [$accounts, 'index'])->add(Auth::requires('bookings.read'));
        $app->post('/accounts', [$accounts, 'create'])->add(Auth::requires('settings.manage'));
        $app->post('/accounts/{id:[0-9]+}', [$accounts, 'rename'])->add(Auth::requires('settings.manage'));
        $app->post('/accounts/{id:[0-9]+}/toggle', [$accounts, 'toggle'])->add(Auth::requires('settings.manage'));

        $categories = new CategoryController($pdoProvider);
        $app->get('/categories', [$categories, 'index'])->add(Auth::requires('bookings.read'));
        $app->post('/categories', [$categories, 'create'])->add(Auth::requires('settings.manage'));
        $app->post('/categories/{id:[0-9]+}', [$categories, 'rename'])->add(Auth::requires('settings.manage'));
        $app->post('/categories/{id:[0-9]+}/toggle', [$categories, 'toggle'])->add(Auth::requires('settings.manage'));

        $bookings = new BookingController($pdoProvider);
        $app->get('/bookings', [$bookings, 'index'])->add(Auth::requires('bookings.read'));
        $app->get('/bookings/new', [$bookings, 'showNew'])->add(Auth::requires('bookings.write'));
        $app->post('/bookings/new', [$bookings, 'create'])->add(Auth::requires('bookings.write'));
        $app->get('/bookings/{id:[0-9]+}', [$bookings, 'show'])->add(Auth::requires('bookings.read'));
        $app->get('/bookings/{id:[0-9]+}/edit', [$bookings, 'showEdit'])->add(Auth::requires('bookings.write'));
        $app->post('/bookings/{id:[0-9]+}/edit', [$bookings, 'update'])->add(Auth::requires('bookings.write'));
        $app->post('/bookings/{id:[0-9]+}/cancel', [$bookings, 'cancel'])->add(Auth::requires('bookings.write'));
        $app->post('/bookings/{id:[0-9]+}/restore', [$bookings, 'restore'])->add(Auth::requires('bookings.write'));
        $app->get('/corrections/new', [$bookings, 'showCorrection'])->add(Auth::requires('bookings.write'));
        $app->post('/corrections/new', [$bookings, 'createCorrection'])->add(Auth::requires('bookings.write'));
        $app->get('/transfers/new', [$bookings, 'showTransfer'])->add(Auth::requires('bookings.write'));
        $app->post('/transfers/new', [$bookings, 'createTransfer'])->add(Auth::requires('bookings.write'));

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
