<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Module;

use Xaraya\Kernel\Module\Manifest;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class ManifestViewKeysTest extends AppTestCase
{
    public function testDisplayHooksAndHookDefaults(): void
    {
        $m = Manifest::fromDirectory(Fixtures::module($this->tmp, 'obs', [
            'displayHooks' => ['item.display' => 'A\\Display', 'item.form.save' => 'A\\Save'],
            'hookDefaults' => [['subject' => 'blog', 'itemtype' => 'post'], ['subject' => 'hello']],
        ]));
        self::assertSame(['item.display' => 'A\\Display', 'item.form.save' => 'A\\Save'], $m->displayHooks());
        self::assertSame([['subject' => 'blog', 'itemtype' => 'post'], ['subject' => 'hello', 'itemtype' => '*']], $m->hookDefaults());

        $none = Manifest::fromDirectory(Fixtures::module($this->tmp, 'plainmod'));
        self::assertSame([], $none->displayHooks());
        self::assertSame([], $none->hookDefaults());
    }

    public function testInvalidHookKeysThrow(): void
    {
        $bad = [
            ['displayHooks' => ['item.delete' => 'A']],
            ['displayHooks' => ['item.display' => 5]],
            ['hookDefaults' => [['itemtype' => 'post']]],
            ['hookDefaults' => ['blog']],
        ];
        foreach ($bad as $i => $json) {
            $m = Manifest::fromDirectory(Fixtures::module($this->tmp, 'bad' . $i, $json));
            try {
                $m->displayHooks();
                $m->hookDefaults();
                self::fail('expected a ModuleException for ' . json_encode($json));
            } catch (ModuleException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
