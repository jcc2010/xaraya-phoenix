<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Psr\Http\Message\ResponseInterface;

final class Emitter
{
    public function emit(ResponseInterface $response, bool $withBody = true): void
    {
        if (!headers_sent()) {
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $i => $value) {
                    header("{$name}: {$value}", $i === 0 && strtolower((string) $name) !== 'set-cookie');
                }
            }
            http_response_code($response->getStatusCode());
        }
        if ($withBody) {
            echo $response->getBody();
        }
    }
}
