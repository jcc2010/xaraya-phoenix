<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Support;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Support\Ulid;

final class UlidTest extends TestCase
{
    public function testGeneratesValidLowerCaseUlids(): void
    {
        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $id = Ulid::generate();
            self::assertSame(26, strlen($id));
            self::assertTrue(Ulid::isValid($id), $id);
            self::assertSame(strtolower($id), $id);
            $ids[$id] = true;
        }
        self::assertCount(200, $ids, 'ids are unique');
    }

    public function testTimestampRoundTripAndOrdering(): void
    {
        self::assertSame(1_760_000_000_123, Ulid::timestamp(Ulid::generate(1_760_000_000_123)));
        self::assertLessThan(0, strcmp(Ulid::generate(1000), Ulid::generate(2000)));
    }

    public function testAcceptsAthenaIds(): void
    {
        self::assertTrue(Ulid::isValid('01m3t19wn8reaww81zg1m47tjq'));
    }

    public function testRejectsInvalid(): void
    {
        self::assertFalse(Ulid::isValid('01M3T19WN8REAWW81ZG1M47TJQ'), 'upper case');
        self::assertFalse(Ulid::isValid('01m3t19wn8reaww81zg1m47tj'), 'too short');
        self::assertFalse(Ulid::isValid('01m3t19wn8reaww81zg1m47tji'), 'contains i');
        self::assertFalse(Ulid::isValid('81m3t19wn8reaww81zg1m47tjq'), 'overflows 48 bits');
        self::assertFalse(Ulid::isValid("01m3t19wn8reaww81zg1m47tjq\n"), 'trailing newline');
    }

    public function testRejectsOutOfRangeTimestamp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ulid::generate(-1);
    }
}
