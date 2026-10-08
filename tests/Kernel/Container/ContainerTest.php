<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Container;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Container\ContainerException;

interface Clock
{
    public function now(): string;
}

final class FixedClock implements Clock
{
    public function now(): string
    {
        return '2026-10-08';
    }
}

final class Leaf {}

final class Branch
{
    public function __construct(public readonly Leaf $leaf, public readonly string $label = 'default') {}
}

final class NeedsClock
{
    public function __construct(public readonly Clock $clock) {}
}

final class MaybeClock
{
    public function __construct(public readonly ?Clock $clock) {}
}

final class NeedsScalar
{
    public function __construct(public readonly string $name) {}
}

final class LoopA
{
    public function __construct(public readonly LoopB $b) {}
}

final class LoopB
{
    public function __construct(public readonly LoopA $a) {}
}

final class ContainerTest extends TestCase
{
    public function testAutowiresAndSharesByDefault(): void
    {
        $c = new Container();
        $branch = $c->get(Branch::class);
        self::assertInstanceOf(Leaf::class, $branch->leaf);
        self::assertSame('default', $branch->label);
        self::assertSame($branch, $c->get(Branch::class));
        self::assertSame($branch->leaf, $c->get(Leaf::class));
    }

    public function testMakeAlwaysBuildsNewAndAcceptsNamedParams(): void
    {
        $c = new Container();
        $a = $c->make(Branch::class, ['label' => 'custom']);
        self::assertInstanceOf(Branch::class, $a);
        self::assertSame('custom', $a->label);
        self::assertNotSame($a, $c->make(Branch::class));
    }

    public function testFactoriesSharedAndUnshared(): void
    {
        $c = new Container();
        $c->set(Clock::class, fn(): Clock => new FixedClock());
        $c->set('counter', function (): \stdClass {
            static $n = 0;
            $o = new \stdClass();
            $o->n = ++$n;

            return $o;
        }, shared: false);

        self::assertSame($c->get(Clock::class), $c->get(Clock::class));
        self::assertSame('2026-10-08', $c->get(NeedsClock::class)->clock->now());
        self::assertNotSame($c->get('counter'), $c->get('counter'));
    }

    public function testInstancesAndSelf(): void
    {
        $c = new Container();
        $c->instance('answer', 42);
        self::assertSame(42, $c->get('answer'));
        self::assertSame($c, $c->get(Container::class));
        self::assertTrue($c->has('answer'));
        self::assertFalse($c->has('nope'));
    }

    public function testUnboundInterfaceIsNullWhenNullable(): void
    {
        self::assertNull((new Container())->get(MaybeClock::class)->clock);
    }

    public function testUnboundInterfaceThrows(): void
    {
        $this->expectException(ContainerException::class);
        (new Container())->get(NeedsClock::class);
    }

    public function testUnresolvableScalarThrows(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$name');
        (new Container())->get(NeedsScalar::class);
    }

    public function testCircularDependencyThrows(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');
        (new Container())->get(LoopA::class);
    }

    public function testUnknownIdThrows(): void
    {
        $this->expectException(ContainerException::class);
        (new Container())->get('no.such.service');
    }
}
