<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Events;

use LogicException;
use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Events\ItemEvent;
use Xaraya\Kernel\Events\ItemUpdated;

final class RecordingListener
{
    /** @var list<string> */
    public array $seen = [];

    public function __invoke(ItemEvent $event): void
    {
        $this->seen[] = $event::class . ':' . $event->id;
    }
}

final class EventDispatcherTest extends TestCase
{
    public function testPriorityThenRegistrationOrder(): void
    {
        $d = new EventDispatcher(new Container());
        $log = [];
        $d->listen(ItemCreated::class, function () use (&$log): void {
            $log[] = 'low';
        }, -5);
        $d->listen(ItemCreated::class, function () use (&$log): void {
            $log[] = 'first-zero';
        });
        $d->listen(ItemCreated::class, function () use (&$log): void {
            $log[] = 'high';
        }, 10);
        $d->listen(ItemCreated::class, function () use (&$log): void {
            $log[] = 'second-zero';
        });

        $event = new ItemCreated('blog', 'post', '01m3t19wn8reaww81zg1m47tjq', ['title' => 'T']);
        self::assertSame($event, $d->dispatch($event));
        self::assertSame(['high', 'first-zero', 'second-zero', 'low'], $log);
        self::assertSame('T', $event->item['title']);
    }

    public function testParentClassListenersReceiveSubclasses(): void
    {
        $d = new EventDispatcher(new Container());
        $count = 0;
        $d->listen(ItemEvent::class, function () use (&$count): void {
            $count++;
        });
        $d->dispatch(new ItemCreated('a', 'b', '1'));
        $d->dispatch(new ItemUpdated('a', 'b', '1'));
        self::assertSame(2, $count);
    }

    public function testStopPropagation(): void
    {
        $d = new EventDispatcher(new Container());
        $ran = [];
        $d->listen(ItemCreated::class, function (ItemCreated $e) use (&$ran): void {
            $ran[] = 1;
            $e->stopPropagation();
        }, 1);
        $d->listen(ItemCreated::class, function () use (&$ran): void {
            $ran[] = 2;
        });
        $event = $d->dispatch(new ItemCreated('a', 'b', '1'));
        self::assertSame([1], $ran);
        self::assertTrue($event->isPropagationStopped());
    }

    public function testClassStringListenersResolveFromContainer(): void
    {
        $c = new Container();
        $d = new EventDispatcher($c);
        $d->listen(ItemCreated::class, RecordingListener::class);
        $d->dispatch(new ItemCreated('a', 'b', 'x1'));
        self::assertSame([ItemCreated::class . ':x1'], $c->get(RecordingListener::class)->seen);
    }

    public function testNonInvokableListenerThrows(): void
    {
        $d = new EventDispatcher(new Container());
        $d->listen(ItemCreated::class, \stdClass::class);
        $this->expectException(LogicException::class);
        $d->dispatch(new ItemCreated('a', 'b', '1'));
    }
}
