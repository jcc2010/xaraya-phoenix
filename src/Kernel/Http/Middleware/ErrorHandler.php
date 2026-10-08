<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use Closure;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;

final class ErrorHandler implements MiddlewareInterface
{
    /** @param (Closure(int, string, ServerRequestInterface): ?string)|null $renderHtml */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
        private readonly ?Closure $renderHtml = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            $status = $e->status();
            // A 5xx HttpException with no message of its own that wraps another (as Cors does) reports the cause.
            $cause = $status >= 500 && $e->getPrevious() !== null && $e->getMessage() === HttpException::reason($status)
                ? $e->getPrevious()
                : $e;
            if ($status >= 500) {
                $this->log($cause);
            }
            $message = $status >= 500 && !$this->debug ? HttpException::reason($status) : $cause->getMessage();

            return $this->respond($request, $status, $message, $e->headers(), $cause);
        } catch (Throwable $e) {
            $this->log($e);

            return $this->respond($request, 500, $this->debug ? $e->getMessage() : HttpException::reason(500), [], $e);
        }
    }

    private function log(Throwable $e): void
    {
        try {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
        } catch (Throwable) {
            // A failing logger must never break error handling.
        }
    }

    /** @param array<string, string> $headers */
    private function respond(ServerRequestInterface $request, int $status, string $message, array $headers, Throwable $e): ResponseInterface
    {
        $trace = $this->debug && $status >= 500
            ? $e::class . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString()
            : null;
        if ($this->wantsJson($request)) {
            $error = ['status' => $status, 'message' => $message];
            if ($trace !== null) {
                $error['trace'] = $trace;
            }
            $body = json_encode(['error' => $error], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $response = new Response($status, ['Content-Type' => 'application/json'], $body === false ? '{"error":{"status":' . $status . '}}' : $body);
        } else {
            $html = null;
            if ($this->renderHtml !== null) {
                try {
                    $html = ($this->renderHtml)($status, $message, $request);
                } catch (Throwable) {
                    $html = null;
                }
            }
            $response = Controller::htmlResponse($html ?? $this->fallback($status, $message, $trace), $status);
        }
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        if (str_ends_with($request->getUri()->getPath(), '.json')) {
            return true;
        }
        $accept = $request->getHeaderLine('Accept');

        return str_contains($accept, 'json') && !str_contains($accept, 'text/html');
    }

    private function fallback(int $status, string $message, ?string $trace): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = $status . ' ' . $e(HttpException::reason($status));

        return "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><title>{$title}</title></head>"
            . "<body><h1>{$title}</h1><p>{$e($message)}</p>"
            . ($trace !== null ? '<pre>' . $e($trace) . '</pre>' : '')
            . "</body></html>\n";
    }
}
