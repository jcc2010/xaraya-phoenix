<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Settings;

use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Settings\Settings;
use Xaraya\Tests\Support\DbTestCase;

final class SettingsTest extends DbTestCase
{
    private function settings(): Settings
    {
        (new Migrator($this->db))->migrate(['kernel' => dirname(__DIR__, 3) . '/src/Kernel/migrations']);

        return new Settings($this->db);
    }

    public function testReadsDefaultsWithoutTheTable(): void
    {
        $settings = new Settings($this->db);
        self::assertSame('d', $settings->get('theme.phoenix', 'tagline', 'd'));
        self::assertSame([], $settings->all('theme.phoenix'));
    }

    public function testRoundTripsJsonValuesPerScope(): void
    {
        $settings = $this->settings();
        $values = ['string' => 'Small & neutral', 'int' => 42, 'float' => 1.5, 'bool' => false, 'null' => null, 'list' => ['a', 'b'], 'map' => ['x' => 1]];
        foreach ($values as $key => $value) {
            $settings->set('demo', $key, $value);
        }
        $settings->set('other', 'string', 'elsewhere');

        $fresh = new Settings($this->db);
        foreach ($values as $key => $value) {
            self::assertSame($value, $fresh->get('demo', $key, 'missing'), $key);
        }
        self::assertSame(['bool', 'float', 'int', 'list', 'map', 'null', 'string'], array_keys($fresh->all('demo')));
        self::assertSame(['string' => 'elsewhere'], $fresh->all('other'));
    }

    public function testOverwriteAndForget(): void
    {
        $settings = $this->settings();
        $settings->set('demo', 'k', 'one');
        self::assertSame('one', $settings->get('demo', 'k'));
        $settings->set('demo', 'k', 'two');
        self::assertSame('two', $settings->get('demo', 'k'));
        $settings->forget('demo', 'k');
        self::assertNull($settings->get('demo', 'k'));
    }

    public function testSeesTheTableOnceItIsCreated(): void
    {
        $settings = new Settings($this->db);
        self::assertSame('d', $settings->get('demo', 'k', 'd'));

        (new Migrator($this->db))->migrate(['kernel' => dirname(__DIR__, 3) . '/src/Kernel/migrations']);
        (new Settings($this->db))->set('demo', 'k', 'now');

        self::assertSame('now', $settings->get('demo', 'k', 'd'));
    }

    public function testOrdersKeysBytewise(): void
    {
        $settings = $this->settings();
        foreach (['Zeta', 'alpha', 'a_b', 'ab', '123'] as $key) {
            $settings->set('demo', $key, 1);
        }
        $fresh = new Settings($this->db);
        self::assertSame(['123', 'Zeta', 'a_b', 'ab', 'alpha'], array_map('strval', array_keys($fresh->all('demo'))));
        self::assertSame(1, $fresh->get('demo', '123'));
    }

    public function testReadsAndForgetRejectBadScopesAndKeys(): void
    {
        $settings = $this->settings();
        $calls = [
            fn() => $settings->get('demo', ''),
            fn() => $settings->get('has space', 'k'),
            fn() => $settings->all(''),
            fn() => $settings->forget('', 'k'),
            fn() => $settings->forget('demo', 'bad key'),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('should be rejected');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRejectsBadScopesAndKeys(): void
    {
        $settings = $this->settings();
        foreach ([['', 'k'], ['demo', ''], ['has space', 'k'], ['demo', str_repeat('k', 192)], [str_repeat('s', 65), 'k']] as [$scope, $key]) {
            try {
                $settings->set($scope, $key, 1);
                self::fail("'{$scope}'/'{$key}' should be rejected");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
