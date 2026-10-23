<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Support\Html;

/** HTTP tests of the blog pages, rendered in the phoenix theme with either engine. */
abstract class BlogPageTestCase extends BlogTestCase
{
    /** @param array<string, mixed> $overrides */
    protected function site(string $engine = 'php', array $overrides = []): App
    {
        return $this->app([
            'themes.paths' => ['themes'],
            'app.theme' => 'phoenix',
            'app.name' => 'Phoenix',
            'app.locale' => 'en',
            'view.engine' => $engine,
            ...$overrides,
        ]);
    }

    /** @param array<string, string> $headers */
    protected function get(string $uri, string $engine = 'php', array $headers = []): ResponseInterface
    {
        $request = new ServerRequest('GET', $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->site($engine)->handle($request);
    }

    /**
     * Requests $uri with the PHP and the Twig engine, asserts the same status and normalised HTML.
     *
     * @return array{0: ResponseInterface, 1: string} the PHP response and the normalised HTML
     */
    protected function both(string $uri, int $status = 200): array
    {
        $php = $this->get($uri, 'php');
        $twig = $this->get($uri, 'twig');
        self::assertSame($status, $php->getStatusCode(), $uri . "\n" . $php->getBody());
        self::assertSame($status, $twig->getStatusCode(), $uri);
        $html = Html::normalize((string) $php->getBody());
        self::assertSame($html, Html::normalize((string) $twig->getBody()), "{$uri}: PHP and Twig differ");

        return [$php, $html];
    }

    /** The normalised contents of <main>, so sidebar blocks never satisfy a needle. */
    protected static function main(string $html): string
    {
        return preg_match('#<main[^>]*>(.*)</main>#s', $html, $m) === 1 ? $m[1] : '';
    }

    /** Error pages (404, 400) come from the ErrorHandler and keep secureHtml's Referrer-Policy. */
    protected const ERROR_REFERRER = SecureHtml::HEADERS['Referrer-Policy'];

    /** A blog page's headers: secureHtml's, except Referrer-Policy, which blog pages set to same-origin. */
    protected static function assertSecureHtml(ResponseInterface $response, string $referrerPolicy = 'same-origin'): void
    {
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        foreach ([...SecureHtml::HEADERS, 'Referrer-Policy' => $referrerPolicy] as $name => $value) {
            self::assertSame($value, $response->getHeaderLine($name), $name);
        }
        self::assertStringEndsWith("; form-action 'self'; frame-ancestors 'self'", $response->getHeaderLine('Content-Security-Policy'), 'no framing by other sites');
    }
}
