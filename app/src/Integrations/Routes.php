<?php
declare(strict_types=1);
namespace Vereinskiste\Integrations;

use Closure;
use Slim\App;
use Vereinskiste\Http\Middleware\Auth;

final class Routes
{
    public static function register(App $app, Closure $pdo, string $version, string $baseUrl): void
    {
        $settings = new SettingsController($pdo, $baseUrl);
        $app->get('/settings/integrations', [$settings, 'index'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/integrations', [$settings, 'create'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/integrations/{id:[0-9]+}/revoke', [$settings, 'revoke'])->add(Auth::requires('settings.manage'));
        $app->get('/settings/integrations/connection', [$settings, 'connection'])->add(Auth::requires('settings.manage'));
        $app->get('/settings/integrations/activity', [$settings, 'activity'])->add(Auth::requires('settings.manage'));
        $api = new RestController($pdo);
        $app->get('/api/v1/openapi.json', [$api, 'openapi']);
        $app->get('/api/v1/capabilities', [$api, 'capabilities']);
        foreach (Catalog::operations() as $name => $op) {
            $app->map([$op['method']], str_replace('{id}', '{id:[0-9]+}', $op['path']), $api->operation($name));
        }
        $app->map(['GET', 'POST', 'DELETE', 'OPTIONS'], '/mcp', [new McpController($pdo, $version), 'handle']);
    }
}
