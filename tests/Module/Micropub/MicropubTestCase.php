<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Micropub;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\App;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Tests\Module\Tokens\TokensTestCase;

/** HTTP tests of the micropub module, through App::handle. */
abstract class MicropubTestCase extends TokensTestCase
{
    protected string $token = '';

    /** Enables blog, tokens and micropub, creates the native blog "notes" and an unpinned blog:write,media:write token. */
    protected function enableMicropub(): App
    {
        $app = $this->enableTokens();
        [$code, $out, $err] = $this->xar(['module:enable', 'micropub']);
        self::assertSame(0, $code, $out . $err);
        $this->nativeBlog($app);
        $this->token = $this->token('blog:write,media:write');

        return $this->app();
    }

    /**
     * A form-encoded POST /micropub. $fields is sent both as the raw body and as the parsed body, as PHP does.
     *
     * @param array<string, mixed> $fields
     */
    protected function form(array $fields, ?string $token = null, string $uri = '/micropub'): ResponseInterface
    {
        $request = (new ServerRequest('POST', $uri))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody(Stream::create(http_build_query($fields)))
            ->withParsedBody($fields);
        if ($token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token));
        }

        return $this->app()->handle($request);
    }

    /** @param array<string, mixed> $body */
    protected function postJson(array $body, ?string $token = null): ResponseInterface
    {
        return $this->app()->handle((new ServerRequest('POST', '/micropub'))
            ->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))));
    }

    /** @param array<string, string> $query */
    protected function query(array $query, ?string $token = null): ResponseInterface
    {
        return $this->app()->handle((new ServerRequest('GET', '/micropub?' . http_build_query($query)))
            ->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token)));
    }

    /** The post a 201's Location points at (http://xar.test/s/{id}). */
    protected function created(ResponseInterface $response): Post
    {
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $location = $response->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^http://xar\.test/s/[0-9a-hjkmnp-tv-z]{26}$#', $location);
        $post = $this->app()->container()->get(PostRepository::class)->find(substr($location, -26));
        self::assertNotNull($post);

        return $post;
    }

    /** @return array<string, mixed> */
    protected static function body(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    protected static function assertMicropubError(ResponseInterface $response, int $status, string $error, ?string $description = null): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $body = self::body($response);
        self::assertSame($error, $body['error'] ?? null);
        self::assertIsString($body['error_description'] ?? null);
        if ($description !== null) {
            self::assertSame($description, $body['error_description']);
        }
    }
}
