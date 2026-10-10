<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Hooks;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Hooks\DisplayHooks;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class DisplayHooksTest extends AppTestCase
{
    private const ITEM = ['module' => 'subj', 'itemtype' => 'thing', 'id' => '7'];

    protected function setUp(): void
    {
        parent::setUp();
        $base = $this->tmp . '/modules';
        Fixtures::module($base, 'subj');
        Fixtures::module($base, 'obs', [
            'displayHooks' => [
                'item.display' => 'Xaraya\\Module\\Obs\\ObsHook',
                'item.form' => 'Xaraya\\Module\\Obs\\ObsHook',
                'item.form.save' => 'Xaraya\\Module\\Obs\\ObsHook',
            ],
            'hookDefaults' => [['subject' => 'subj', 'itemtype' => 'thing']],
        ], ['src/ObsHook.php' => self::hookClass('Obs', 'ObsHook', 'obs')]);
        Fixtures::module($base, 'obs2', [
            'displayHooks' => ['item.display' => 'Xaraya\\Module\\Obs2\\Obs2Hook'],
            'hookDefaults' => [['subject' => 'subj']],
        ], ['src/Obs2Hook.php' => self::hookClass('Obs2', 'Obs2Hook', 'obs2')]);
    }

    private static function hookClass(string $namespace, string $class, string $label): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Xaraya\\Module\\{$namespace};

            use Xaraya\\Kernel\\Hooks\\DisplayHook;

            final class {$class} implements DisplayHook
            {
                /** @var list<array<string, mixed>> */
                public array \$saved = [];

                public function handle(string \$hook, array \$item, array \$input = []): string
                {
                    if (\$hook === 'item.form.save') {
                        \$this->saved[] = \$input;

                        return '';
                    }

                    return '<p>{$label} ' . \$hook . ' ' . \$item['id'] . '</p>';
                }
            }
            PHP;
    }

    private function app(): App
    {
        return $this->boot([$this->tmp . '/modules']);
    }

    /** @param list<string> $args */
    private function xar(array $args): int
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('no memory stream');

        return (new Application($this->app()))->run(['xar', ...$args], new Output($out, $out));
    }

    private function hooks(): DisplayHooks
    {
        return $this->app()->container()->get(DisplayHooks::class);
    }

    public function testSeededBindingRendersTheObserverFragment(): void
    {
        self::assertSame(0, $this->xar(['module:enable', 'subj']));
        self::assertSame(0, $this->xar(['module:enable', 'obs']));
        self::assertSame('<p>obs item.display 7</p>', $this->hooks()->call('item.display', self::ITEM));
        self::assertSame('<p>obs item.form 7</p>', $this->hooks()->call('item.form', self::ITEM));
        self::assertSame('', $this->hooks()->call('item.display', [...self::ITEM, 'itemtype' => 'other']));
        self::assertSame('', $this->hooks()->call('item.display', [...self::ITEM, 'module' => 'nobody']));
    }

    public function testWildcardBindingsRunInObserverNameOrder(): void
    {
        foreach (['subj', 'obs', 'obs2'] as $module) {
            self::assertSame(0, $this->xar(['module:enable', $module]));
        }
        self::assertSame('<p>obs item.display 7</p><p>obs2 item.display 7</p>', $this->hooks()->call('item.display', self::ITEM));
        self::assertSame('<p>obs2 item.display 7</p>', $this->hooks()->call('item.display', [...self::ITEM, 'itemtype' => 'other']));
        self::assertSame('', $this->hooks()->call('item.form', [...self::ITEM, 'itemtype' => 'other']), 'obs2 has no item.form hook');
    }

    public function testFormSaveReturnsNothingAndPassesTheInput(): void
    {
        self::assertSame(0, $this->xar(['module:enable', 'obs']));
        $app = $this->app();
        self::assertSame('', $app->container()->get(DisplayHooks::class)->call('item.form.save', self::ITEM, ['note' => 'hi']));
        self::assertSame([['note' => 'hi']], $app->container()->get('Xaraya\\Module\\Obs\\ObsHook')->saved);
    }

    public function testCliTogglesBindingsAndReEnablingKeepsTheChoice(): void
    {
        $this->xar(['module:enable', 'subj']);
        $this->xar(['module:enable', 'obs']);
        self::assertSame(0, $this->xar(['hook:disable', 'obs', 'subj', 'thing']));
        self::assertSame('', $this->hooks()->call('item.display', self::ITEM));
        self::assertSame(0, $this->xar(['module:enable', 'obs']));
        self::assertSame('', $this->hooks()->call('item.display', self::ITEM), 're-enabling must not re-seed a disabled binding');
        self::assertSame(0, $this->xar(['hook:enable', 'obs', 'subj', 'thing']));
        self::assertSame('<p>obs item.display 7</p>', $this->hooks()->call('item.display', self::ITEM));
    }

    public function testDisabledObserverModuleIsIgnored(): void
    {
        $this->xar(['module:enable', 'obs']);
        $this->xar(['module:disable', 'obs']);
        self::assertSame('', $this->hooks()->call('item.display', self::ITEM));
    }

    public function testCliRejectsUnknownModulesAndObserversWithoutHooks(): void
    {
        $this->xar(['module:enable', 'obs']);
        self::assertSame(1, $this->xar(['hook:enable', 'subj', 'obs']));
        self::assertSame(1, $this->xar(['hook:enable', 'nobody', 'subj']));
        self::assertSame(1, $this->xar(['hook:enable', 'obs']));
    }

    public function testUnknownHookOrIncompleteItemThrows(): void
    {
        try {
            $this->hooks()->call('item.delete', self::ITEM);
            self::fail('expected an unknown hook to throw');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->hooks()->call('item.display', ['id' => '7']);
    }
}
