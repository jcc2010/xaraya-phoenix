<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http\Api;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Exception\HttpException;

/**
 * Reads a JSON object request body: 415 for another content type, 413 over MAX_BYTES (declared or read), 400 when
 * it is not valid JSON or not an object. Nothing else in the kernel caps a request body, so this is the bound.
 */
final class JsonBody
{
    public const MAX_BYTES = 1_000_000;

    /**
     * @param list<string> $types accepted media types
     * @return array<string, mixed>
     */
    public static function decode(ServerRequestInterface $request, array $types = ['application/json', 'application/feed+json']): array
    {
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        if (!in_array($type, $types, true)) {
            throw new HttpException(415, 'Send the body as ' . implode(' or ', $types));
        }
        $tooLarge = new HttpException(413, 'The body is larger than ' . self::MAX_BYTES . ' bytes');
        $declared = $request->getHeaderLine('Content-Length');
        if (ctype_digit($declared) && (strlen($declared) > 12 || (int) $declared > self::MAX_BYTES)) {
            throw $tooLarge;
        }
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = '';
        while (!$stream->eof() && strlen($body) <= self::MAX_BYTES) {
            $chunk = $stream->read(65536);
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw $tooLarge;
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'The body is not valid JSON');
        }
        // `[]` and `{}` both decode to an empty array: only the latter is an object.
        if (!is_array($data) || ($data !== [] && array_is_list($data)) || !str_starts_with(ltrim($body), '{')) {
            throw new HttpException(400, 'The body must be a JSON object');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
