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
use Twig\TwigFunction;
use Vereinskiste\Auth\AuthService;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Db\Connection;
use Vereinskiste\Http\Middleware\Auth;
use Vereinskiste\Http\Middleware\Csrf;
use Vereinskiste\Http\Middleware\Session;
use Vereinskiste\Http\Session\NativeSessionStore;
use Vereinskiste\Http\Session\SessionStore;
use Vereinskiste\Web\AuthController;

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
            return $next->handle($req->withAttribute('auth', $auth)->withAttribute('view', $view));
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
