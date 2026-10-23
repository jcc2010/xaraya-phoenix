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
    /** Titles in flash messages are cut to this many columns. */
    private const FLASH_TITLE_WIDTH = 80;

    public function __construct(private readonly AssetUrls $assets) {}

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** A title shortened for a flash message ("Saved “…”."). */
    public static function flashTitle(string $title): string
    {
        return mb_strimwidth($title, 0, self::FLASH_TITLE_WIDTH, '…');
    }

    /** @return array<array-key, mixed> the parsed form body, or [] when there is none */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /**
     * @param array<string, mixed> $data body template variables; "flash" is added (and the queue emptied)
     * @param list<string> $scripts published module asset paths ("module/blog/…"), loaded with defer
     */
    public function page(ServerRequestInterface $request, string $template, array $data, string $title, int $status = 200, array $scripts = []): Page
    {
        return new Page(
            $template,
            [...$data, 'flash' => LoginController::session($request)->takeFlash()],
            title: $title . ' · Admin',
            styles: [$this->assets->url('module/blog/blog.css')],
            status: $status,
            scripts: array_map($this->assets->url(...), $scripts),
        );
    }

    /** Queues a message for the next screen and answers 303 to $location. */
    public function done(ServerRequestInterface $request, string $location, string $message, string $kind = 'status'): ResponseInterface
    {
        LoginController::session($request)->flash($message, $kind);

        return new Response(303, ['Location' => $location]);
    }
}
