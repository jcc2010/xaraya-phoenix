<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Http\Exception\HttpException;

/**
 * Micropub lets a form body carry the token as `access_token` (spec §5.1: Micropub routes only). This parses a
 * form-encoded POST body when the server has not, and moves that field into `Authorization: Bearer`, so `token:` reads
 * a header as everywhere else. Sending the token both ways is a 400 (RFC 6750 §2). The query string is never read.
 */
final class FormToken implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only a POST carries a form: a GET's body (and any token in it) is ignored.
        if ($request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }
        $form = $request->getParsedBody();
        if (!is_array($form) && MicropubInput::mediaType($request) === 'application/x-www-form-urlencoded') {
            parse_str((string) $request->getBody(), $form);
            $request = $request->withParsedBody($form);
        }
        if (is_array($form) && array_key_exists('access_token', $form)) {
            if ($request->hasHeader('Authorization')) {
                throw new HttpException(400, 'Send the token in the Authorization header or in access_token, not both');
            }
            $token = $form['access_token'];
            unset($form['access_token']);
            $request = $request->withParsedBody($form)->withHeader('Authorization', 'Bearer ' . (is_string($token) ? $token : ''));
        }

        return $handler->handle($request);
    }
}
