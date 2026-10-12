<?php
declare(strict_types=1);

namespace Vereinskiste\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;

final class ErrorPage
{
    /**
     * `$backUrl`: optionaler Link (z. B. "Buchung neu laden") unter dem Text.
     * `$params`: Platzhalter im Text (z. B. ['{date}' => '31.03.2026']), HTML-escaped beim Rendern.
     *
     * @param array<string,string> $params
     */
    public static function render(
        Twig $view,
        int $status,
        string $titleKey,
        string $textKey,
        ?string $backUrl = null,
        string $backKey = 'common.back',
        array $params = []
    ): ResponseInterface {
        return $view->render(
            (new Response())->withStatus($status),
            'error.twig',
            ['title_key' => $titleKey, 'text_key' => $textKey, 'back_url' => $backUrl, 'back_key' => $backKey, 'params' => $params]
        );
    }
}
