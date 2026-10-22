<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Assets\AssetUrls;
use Xaraya\Kernel\Auth\Http\LoginController;
use Xaraya\Kernel\View\Page;

/** What every admin screen shares: the title, blog.css, the flash messages, and the redirect after a POST. */
final class AdminPage
{
    public function __construct(private readonly AssetUrls $assets) {}

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $data body template variables; "flash" is added (and the queue emptied) */
    public function page(ServerRequestInterface $request, string $template, array $data, string $title, int $status = 200): Page
    {
        return new Page(
            $template,
            [...$data, 'flash' => LoginController::session($request)->takeFlash()],
            title: $title . ' · Admin',
            styles: [$this->assets->url('module/blog/blog.css')],
            status: $status,
        );
    }

    /** Queues a message for the next screen and answers 303 to $location. */
    public function done(ServerRequestInterface $request, string $location, string $message, string $kind = 'status'): ResponseInterface
    {
        LoginController::session($request)->flash($message, $kind);

        return new Response(303, ['Location' => $location]);
    }
}
