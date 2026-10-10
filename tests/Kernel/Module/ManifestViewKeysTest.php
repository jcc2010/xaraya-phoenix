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

    public function testBlocksAndBlockDefaults(): void
    {
        $m = Manifest::fromDirectory(Fixtures::module($this->tmp, 'shop', [
            'blocks' => ['shop.note' => 'S\\Note'],
            'blockDefaults' => [
                ['type' => 'shop.note', 'region' => 'sidebar', 'title' => 'Note', 'config' => ['text' => 'hi'], 'visibility' => ['routes' => ['shop.*'], 'extra' => true], 'sort' => 3],
                ['type' => 'text', 'region' => 'footer'],
            ],
        ]));
        self::assertSame(['shop.note' => 'S\\Note'], $m->blocks());
        self::assertSame([
            ['type' => 'shop.note', 'region' => 'sidebar', 'title' => 'Note', 'config' => ['text' => 'hi'], 'visibility' => ['routes' => ['shop.*']], 'sort' => 3],
            ['type' => 'text', 'region' => 'footer', 'title' => null, 'config' => [], 'visibility' => [], 'sort' => 0],
        ], $m->blockDefaults());
    }

    public function testBlockTypesMustBeNamespacedByTheModule(): void
    {
        $bad = [
            ['blocks' => ['other.note' => 'X']],
            ['blocks' => ['shop' => 'X']],
            ['blocks' => ['S\\Note']],
            ['blockDefaults' => [['region' => 'sidebar']]],
            ['blockDefaults' => [['type' => 'text', 'region' => 'footer', 'config' => 'nope']]],
            ['blockDefaults' => [['type' => 'text', 'region' => 'footer', 'visibility' => ['roles' => 'Admin']]]],
            ['blockDefaults' => [['type' => 'text', 'region' => 'Bad Region']]],
            ['blockDefaults' => [['type' => 'Bad Type', 'region' => 'footer']]],
            ['blockDefaults' => [['type' => 'shop.undeclared', 'region' => 'footer']]],
        ];
        foreach ($bad as $i => $json) {
            $m = Manifest::fromDirectory(Fixtures::module($this->tmp . '/b' . $i, 'shop', $json));
            try {
                $m->blocks();
                $m->blockDefaults();
                self::fail('expected a ModuleException for ' . json_encode($json));
            } catch (ModuleException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
