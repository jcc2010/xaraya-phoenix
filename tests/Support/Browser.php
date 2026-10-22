<?php

declare(strict_types=1);

namespace Xaraya\Tests\Support;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Xaraya\Kernel\App;

/** Sends requests through App::handle() like a browser: it keeps cookies and comes from one IP address. */
final class Browser
{
    /** @var array<string, string> */
    public array $cookies = [];

    public ?ResponseInterface $last = null;

    public function __construct(
        private readonly App $app,
        public string $ip = '203.0.113.7',
        private readonly string $origin = 'http://xar.test',
    ) {}

    /** @param array<string, string> $headers */
    public function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->send($this->request('GET', $path, $headers));
    }

    /**
     * Posts a form. Unless $fields has "_csrf", the token of the last page's form is added.
     *
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public function post(string $path, array $fields = [], array $headers = []): ResponseInterface
    {
        if (!array_key_exists('_csrf', $fields) && $this->last !== null && ($token = $this->csrfField()) !== null) {
            $fields['_csrf'] = $token;
        }
        $request = $this->request('POST', $path, ['Content-Type' => 'application/x-www-form-urlencoded', ...$headers], http_build_query($fields));

        return $this->send($request->withParsedBody($fields));
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $path, array $headers = [], string $body = ''): ServerRequestInterface
    {
        return new ServerRequest($method, $this->origin . $path, $headers, $body, '1.1', ['REMOTE_ADDR' => $this->ip]);
    }

    public function send(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->app->handle($request->withCookieParams($this->cookies));
        foreach ($response->getHeader('Set-Cookie') as $line) {
            [$pair] = explode(';', $line, 2);
            [$name, $value] = explode('=', $pair, 2);
            if (str_contains(strtolower($line), 'max-age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return $this->last = $response;
    }

    public function body(): string
    {
        return $this->last === null ? throw new RuntimeException('No request yet') : (string) $this->last->getBody();
    }

    /** The _csrf hidden field of the last page, if it has one. */
    public function csrfField(): ?string
    {
        return preg_match('/name="_csrf" value="([^"]+)"/', $this->body(), $m) === 1 ? $m[1] : null;
    }
}
