<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;
use Xaraya\Kernel\Container\Container;

final class EventDispatcher
{
    /** @var array<string, list<array{priority: int, order: int, listener: callable|string}>> */
    private array $listeners = [];

    private int $order = 0;

    public function __construct(private readonly Container $container) {}

    public function listen(string $event, callable|string $listener, int $priority = 0): void
    {
        $this->listeners[$event][] = ['priority' => $priority, 'order' => $this->order++, 'listener' => $listener];
    }

    /**
     * @template T of object
     * @param T $event
     * @return T
     */
    public function dispatch(object $event): object
    {
        foreach ($this->listenersFor($event) as $listener) {
            if ($event instanceof Event && $event->isPropagationStopped()) {
                break;
            }
            $this->resolve($listener)($event);
        }

        return $event;
    }

    /**
     * dispatch() for news about something already done (a post committed): each listener runs on its own, and one
     * that throws is logged and skipped, so it can neither undo the caller's success (a client would retry and
     * duplicate the post) nor keep the listeners after it (the search index) from running.
     *
     * @template T of object
     * @param T $event
     * @return T
     */
    public function notify(object $event): object
    {
        foreach ($this->listenersFor($event) as $listener) {
            if ($event instanceof Event && $event->isPropagationStopped()) {
                break;
            }
            try {
                // Resolved here, so a listener that cannot even be built is isolated too.
                $this->resolve($listener)($event);
            } catch (Throwable $e) {
                $this->container->get(LoggerInterface::class)->error('A listener for ' . $event::class . ' failed: ' . $e->getMessage(), ['exception' => $e]);
            }
        }

        return $event;
    }

    /** @return list<callable|string> in calling order, not yet resolved */
    private function listenersFor(object $event): array
    {
        $types = [$event::class, ...array_values(class_parents($event) ?: []), ...array_values(class_implements($event) ?: [])];
        $matched = [];
        foreach ($types as $type) {
            foreach ($this->listeners[$type] ?? [] as $entry) {
                $matched[] = $entry;
            }
        }
        usort($matched, fn(array $a, array $b): int => [$b['priority'], $a['order']] <=> [$a['priority'], $b['order']]);

        return array_column($matched, 'listener');
    }

    private function resolve(callable|string $listener): callable
    {
        if (is_callable($listener)) {
            return $listener;
        }
        $object = $this->container->get($listener);
        if (!is_callable($object)) {
            throw new LogicException("Event listener {$listener} is not invokable");
        }

        return $object;
    }
}
