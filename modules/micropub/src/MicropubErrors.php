<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Module\Tokens\TokenError;

/**
 * Answers every 4xx in Micropub's shape, {"error": …, "error_description": …}: 401 unauthorized, 403 forbidden or
 * insufficient_scope, and 400 invalid_request for everything else (validation, unknown posts, conflicts, sizes).
 * 5xx errors go on to the kernel ErrorHandler, which logs them.
 */
final class MicropubErrors implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            $status = $e->status();
            if ($status >= 500) {
                throw $e;
            }
            [$status, $error] = match (true) {
                $e instanceof TokenError => [$status, $e->error],
                $status === 401 => [401, 'unauthorized'],
                $status === 403 => [403, 'forbidden'],
                default => [400, 'invalid_request'],
            };
            $response = Controller::jsonResponse(['error' => $error, 'error_description' => self::describe($e)], $status);
            foreach ($e->headers() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $response;
        }
    }

    private static function describe(HttpException $e): string
    {
        if (!$e instanceof ValidationFailed) {
            return $e->getMessage();
        }
        $parts = [];
        foreach ($e->fields() as $field => $message) {
            $parts[] = "{$field}: {$message}";
        }

        return implode('; ', $parts);
    }
}
