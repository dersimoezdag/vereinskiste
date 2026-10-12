<?php
declare(strict_types=1);

namespace Vereinskiste\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;

final class ErrorPage
{
    public static function render(Twig $view, int $status, string $titleKey, string $textKey): ResponseInterface
    {
        return $view->render(
            (new Response())->withStatus($status),
            'error.twig',
            ['title_key' => $titleKey, 'text_key' => $textKey]
        );
    }
}
