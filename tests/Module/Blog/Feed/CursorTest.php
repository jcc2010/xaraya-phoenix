<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Feed;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Feed\Cursor;

final class CursorTest extends TestCase
{
    /** Taken from Athena's live next_url for the wyome blog. */
    private const ATHENA = 'MjAyNi0xMC0wNyAxNjo0OToxN3wwMW0yZGdqMnptcHRxZWRhYTBjYjdobXRkdg';

    public function testDecodesAthenaCursor(): void
    {
        [$date, $id] = Cursor::decode(self::ATHENA);
        self::assertSame('2026-10-07 16:49:17', $date->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $date->getTimezone()->getName());
        self::assertSame('01m2dgj2zmptqedaa0cb7hmtdv', $id);
    }

    public function testEncodeMatchesAthenaAndConvertsToUtc(): void
    {
        self::assertSame(self::ATHENA, Cursor::encode(new DateTimeImmutable('2026-10-07 16:49:17', new DateTimeZone('UTC')), '01m2dgj2zmptqedaa0cb7hmtdv'));
        self::assertSame(self::ATHENA, Cursor::encode(new DateTimeImmutable('2026-10-07 12:49:17', new DateTimeZone('America/New_York')), '01m2dgj2zmptqedaa0cb7hmtdv'));
    }

    public function testRejectsMalformed(): void
    {
        $bad = [
            '',
            'not base64!',
            rtrim(strtr(base64_encode('2026-10-07 16:49:17'), '+/', '-_'), '='),
            rtrim(strtr(base64_encode('2026-13-45 99:00:00|01m2dgj2zmptqedaa0cb7hmtdv'), '+/', '-_'), '='),
            rtrim(strtr(base64_encode('2026-10-07 16:49:17|NOTAULID'), '+/', '-_'), '='),
            str_repeat('A', 300),
        ];
        foreach ($bad as $cursor) {
            try {
                Cursor::decode($cursor);
                self::fail("accepted '{$cursor}'");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
