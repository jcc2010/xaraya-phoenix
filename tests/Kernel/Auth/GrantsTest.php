<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Fixtures;

final class GrantsTest extends AuthTestCase
{
    private function grants(App $app): Grants
    {
        return $app->container()->get(Grants::class);
    }

    /** @return list<string> "Role module.component.instance level" */
    private function rows(App $app, string $module): array
    {
        $rows = $this->db($app)->fetchAll(
            'SELECT r.name, g.component, g.instance, g.level FROM {grants} g JOIN {roles} r ON r.id = g.role_id WHERE g.module = ? ORDER BY r.name, g.component, g.instance',
            [$module],
        );

        return array_map(static fn(array $r): string => "{$r['name']} {$module}.{$r['component']}.{$r['instance']} {$r['level']}", $rows);
    }

    public function testEnableSeedsManifestGrantsAndEditorsOnContentModules(): void
    {
        Fixtures::module($this->tmp . '/modules', 'notes', [
            'content' => true,
            'grants' => [
                ['role' => 'Members', 'component' => 'note', 'level' => 'comment'],
                ['role' => 'Moderators', 'component' => 'note', 'instance' => '*', 'level' => 'moderate'],
            ],
        ]);
        Fixtures::module($this->tmp . '/modules', 'plain', ['grants' => [['role' => 'Authors', 'component' => 'x', 'instance' => 'own', 'level' => 'edit']]]);
        $app = $this->app([], [$this->tmp . '/modules']);
        $modules = $app->container()->get(ModuleRegistry::class);
        $modules->enable('notes');
        $modules->enable('plain');
        self::assertSame(['Editors notes.*.* 700', 'Members notes.note.* 300', 'Moderators notes.note.* 400'], $this->rows($app, 'notes'));
        self::assertSame(['Authors plain.x.own 500'], $this->rows($app, 'plain'), 'no Editors grant without "content": true');

        // Re-enabling keeps changed levels and restores deleted grants, without duplicates.
        $this->db($app)->query("UPDATE {grants} SET level = 100 WHERE module = 'notes' AND component = 'note' AND level = 300");
        $this->db($app)->query("DELETE FROM {grants} WHERE module = 'notes' AND level = 700");
        $this->app([], [$this->tmp . '/modules'])->container()->get(ModuleRegistry::class)->enable('notes');
        self::assertSame(['Editors notes.*.* 700', 'Members notes.note.* 100', 'Moderators notes.note.* 400'], $this->rows($app, 'notes'));
    }

    public function testTheBlogSeedsItsAuthorsGrants(): void
    {
        $modules = dirname(__DIR__, 3) . '/modules';
        $app = $this->app(['blog.locks' => $this->tmp . '/locks'], [$modules]);
        $app->container()->get(ModuleRegistry::class)->enable('blog');
        self::assertSame(['Authors blog.post.* 200', 'Authors blog.post.own 700', 'Editors blog.*.* 700'], $this->rows($app, 'blog'));
    }

    public function testInvalidManifestGrantsStopTheEnable(): void
    {
        Fixtures::module($this->tmp . '/modules', 'bad', ['grants' => [['role' => 'Members', 'component' => 'x', 'level' => 'superuser']]]);
        $app = $this->app([], [$this->tmp . '/modules']);
        try {
            $app->container()->get(ModuleRegistry::class)->enable('bad');
            self::fail('an unknown level must be refused');
        } catch (ModuleException $e) {
            self::assertStringContainsString('bad: grants[0] needs', $e->getMessage());
        }
        self::assertFalse($app->container()->get(ModuleRegistry::class)->isEnabled('bad'));
    }

    public function testSetParentRefusesSelfAndLongerCycles(): void
    {
        $grants = $this->grants($this->installed());
        $grants->ensureRole('A');
        $grants->ensureRole('B', 'A');
        $grants->ensureRole('C', 'B');
        foreach ([['A', 'A'], ['A', 'B'], ['A', 'C']] as [$role, $parent]) {
            try {
                $grants->setParent($role, $parent);
                self::fail("{$role} under {$parent} must be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('own ancestor', $e->getMessage());
            }
        }
        $grants->setParent('C', 'A');
        $grants->setParent('C', null);
    }

    public function testForRolesFollowsParentsAndStopsAtACycle(): void
    {
        $app = $this->installed();
        $grants = $this->grants($app);
        $grants->ensureRole('Interns', 'Members');
        $grants->grant('Members', 'wiki', 'page', '*', 'comment');
        $grants->grant('Interns', 'wiki', 'page', 'drafts', 'edit');
        $levels = static fn(array $rows): array => array_map(static fn(array $g): string => "{$g['module']}.{$g['component']}.{$g['instance']}:{$g['level']}", $rows);
        self::assertEqualsCanonicalizing(['wiki.page.drafts:500', 'wiki.page.*:300'], $levels($grants->forRoles(['Interns'])));

        $this->db($app)->query("UPDATE {roles} SET parent_id = (SELECT id FROM (SELECT id FROM {roles} WHERE name = 'Interns') x) WHERE name = 'Members'");
        $fresh = $this->grants($this->app());
        self::assertEqualsCanonicalizing(['wiki.page.drafts:500', 'wiki.page.*:300'], $levels($fresh->forRoles(['Interns'])), 'a parent cycle ends');
        self::assertSame([], $fresh->forRoles(['Nobody']));
    }
}
