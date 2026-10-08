<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Support;

use InvalidArgumentException;

final class Ulid
{
    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    public static function generate(?int $milliseconds = null): string
    {
        $ms = $milliseconds ?? (int) floor(microtime(true) * 1000);
        if ($ms < 0 || $ms > 0xFFFFFFFFFFFF) {
            throw new InvalidArgumentException('ULID timestamp out of range');
        }
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$ms % 32] . $time;
            $ms = intdiv($ms, 32);
        }
        $bits = '';
        foreach (str_split(random_bytes(10)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $random = '';
        foreach (str_split($bits, 5) as $chunk) {
            $random .= self::ALPHABET[(int) bindec($chunk)];
        }

        return $time . $random;
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/', $value) === 1;
    }

    public static function timestamp(string $ulid): int
    {
        if (!self::isValid($ulid)) {
            throw new InvalidArgumentException("Invalid ULID '{$ulid}'");
        }
        $ms = 0;
        for ($i = 0; $i < 10; $i++) {
            $ms = $ms * 32 + (int) strpos(self::ALPHABET, $ulid[$i]);
        }

        return $ms;
    }
}
