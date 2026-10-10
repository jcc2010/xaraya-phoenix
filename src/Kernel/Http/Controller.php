<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\View\Page;

abstract class Controller
{
    protected function json(mixed $data, int $status = 200, string $contentType = 'application/json'): ResponseInterface
    {
        return self::jsonResponse($data, $status, $contentType);
    }

    protected function html(string $html, int $status = 200): ResponseInterface
    {
        return self::htmlResponse($html, $status);
    }

    /** @param array<string, mixed> $data */
    protected function view(string $template, array $data = [], string $title = '', int $status = 200): Page
    {
        return new Page($template, $data, $title, status: $status);
    }

    protected function redirect(string $url, int $status = 302): ResponseInterface
    {
        return new Response($status, ['Location' => $url]);
    }

    protected function notFound(string $message = ''): never
    {
        throw new NotFound($message);
    }

    public static function jsonResponse(mixed $data, int $status = 200, string $contentType = 'application/json'): ResponseInterface
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new Response($status, ['Content-Type' => $contentType], $body);
    }

    public static function htmlResponse(string $html, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }
}
