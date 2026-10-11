<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Feed\Cursor;

/** Query parameters of paged pages; anything malformed is a 400. */
final class Paging
{
    /** @return array{0: DateTimeImmutable, 1: string}|null the decoded ?before= cursor */
    public static function before(ServerRequestInterface $request): ?array
    {
        $value = self::query($request)['before'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(400, 'Invalid before cursor');
        }
        try {
            return Cursor::decode($value);
        } catch (InvalidArgumentException) {
            throw new HttpException(400, 'Invalid before cursor');
        }
    }

    /** The 1-based ?page= number; 1 when absent. */
    public static function page(ServerRequestInterface $request): int
    {
        $value = self::query($request)['page'] ?? null;
        if ($value === null) {
            return 1;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,5}$/D', $value) !== 1) {
            throw new HttpException(400, 'Invalid page number');
        }

        return (int) $value;
    }

    /**
     * Route parameters for a link to the same page: ['before' => cursor] or [].
     *
     * @param array{0: DateTimeImmutable, 1: string}|null $before
     * @return array<string, string>
     */
    public static function cursorParam(?array $before): array
    {
        return $before === null ? [] : ['before' => Cursor::encode($before[0], $before[1])];
    }

    /** @return array<mixed> */
    public static function query(ServerRequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);

        return $query;
    }
}
