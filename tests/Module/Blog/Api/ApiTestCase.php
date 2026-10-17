<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Api;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Tests\Module\Tokens\TokensTestCase;

/** HTTP tests of the token-protected write routes, through App::handle. */
abstract class ApiTestCase extends TokensTestCase
{
    /** @param array<string, mixed>|string|null $body an array is sent as JSON */
    protected function api(string $method, string $uri, ?string $token, array|string|null $body = null, string $type = 'application/json'): ResponseInterface
    {
        $request = new ServerRequest($method, $uri);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', $type)
                ->withBody(Stream::create(is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $body));
        }

        return $this->app()->handle($request);
    }

    /** A public page or feed, rendered in the phoenix theme. */
    protected function page(string $uri): ResponseInterface
    {
        return $this->app(['themes.paths' => ['themes'], 'app.theme' => 'phoenix', 'view.engine' => 'php'])->handle(new ServerRequest('GET', $uri));
    }

    /** @return array<string, mixed> */
    protected static function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
