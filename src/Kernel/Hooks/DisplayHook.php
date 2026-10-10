<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Hooks;

/** An observer module's display hook, named for each hook it handles in the manifest's "displayHooks". */
interface DisplayHook
{
    public const HOOKS = ['item.display', 'item.form', 'item.form.save'];

    /**
     * Returns an HTML fragment for item.display and item.form. For item.form.save it persists the observer's
     * own fields from $input and returns ''.
     *
     * @param array<string, mixed> $item at least "module", "itemtype" and "id"
     * @param array<string, mixed> $input the submitted form fields (item.form.save only)
     */
    public function handle(string $hook, array $item, array $input = []): string;
}
