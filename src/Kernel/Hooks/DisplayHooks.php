<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Hooks;

use InvalidArgumentException;
use LogicException;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Module\ModuleRegistry;

/** Runs the display hooks of the enabled observer modules bound to an item's module and itemtype. */
final class DisplayHooks
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly HookBindings $bindings,
        private readonly Container $container,
    ) {}

    /**
     * @param array<string, mixed> $item at least "module" and "itemtype"
     * @param array<string, mixed> $input form fields, for item.form.save
     */
    public function call(string $hook, array $item, array $input = []): string
    {
        if (!in_array($hook, DisplayHook::HOOKS, true)) {
            throw new InvalidArgumentException("Unknown display hook '{$hook}'");
        }
        $module = $item['module'] ?? null;
        $itemtype = $item['itemtype'] ?? null;
        if (!is_string($module) || $module === '' || !is_string($itemtype) || $itemtype === '') {
            throw new InvalidArgumentException('Display hooks need an item with string "module" and "itemtype"');
        }
        $enabled = $this->modules->enabled();
        $html = '';
        foreach ($this->bindings->observers($module, $itemtype) as $observer) {
            $class = isset($enabled[$observer]) ? ($enabled[$observer]->displayHooks()[$hook] ?? null) : null;
            if ($class === null) {
                continue;
            }
            $handler = $this->container->get($class);
            if (!$handler instanceof DisplayHook) {
                throw new LogicException("{$observer}: {$class} must implement DisplayHook");
            }
            $html .= $handler->handle($hook, $item, $input);
        }

        return $hook === 'item.form.save' ? '' : $html;
    }
}
