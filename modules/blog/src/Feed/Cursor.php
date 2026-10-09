<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Feed;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Xaraya\Kernel\Support\Ulid;

/** Athena's keyset cursor: base64url, unpadded, of "Y-m-d H:i:s|<ulid>" in UTC. */
final class Cursor
{
    public static function encode(DateTimeImmutable $published, string $id): string
    {
        $raw = $published->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') . '|' . $id;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array{0: DateTimeImmutable, 1: string} */
    public static function decode(string $cursor): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $cursor) !== 1) {
            throw new InvalidArgumentException('Malformed cursor');
        }
        $padded = strtr($cursor, '-_', '+/') . str_repeat('=', (4 - strlen($cursor) % 4) % 4);
        $raw = base64_decode($padded, true);
        if ($raw === false || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|([0-9a-z]{26})$/D', $raw, $m) !== 1 || !Ulid::isValid($m[2])) {
            throw new InvalidArgumentException('Malformed cursor');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1], new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $m[1]) {
            throw new InvalidArgumentException('Malformed cursor');
        }

        return [$date, $m[2]];
    }
}
