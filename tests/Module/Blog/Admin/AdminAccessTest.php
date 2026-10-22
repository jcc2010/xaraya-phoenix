<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Admin;

use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\User;
use Xaraya\Kernel\Http\Exception\Forbidden;
use Xaraya\Module\Blog\Admin\AdminAccess;

final class AdminAccessTest extends AdminTestCase
{
    public function testWhoMayDoWhat(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $users = [
            'root' => $this->user($app, 'root', ['Administrators']),
            'ed' => $this->user($app, 'ed', ['Editors']),
            'ada' => $this->user($app, 'ada', ['Authors']),
            'bob' => $this->user($app, 'bob', ['Authors']),
            'mia' => $this->user($app, 'mia'),
        ];
        $posts = [
            'ada' => $this->postAs($app, $users['ada'], $blog, ['content_html' => '<p>a</p>']),
            'bob' => $this->postAs($app, $users['bob'], $blog, ['content_html' => '<p>b</p>']),
            'synced' => $this->posts($app)->find($this->addPost($app, $blog, self::postRecord(1))),
        ];
        $caps = function (?User $user) use ($app, $posts): string {
            $app->container()->get(Auth::class)->actAs($user);
            $access = $app->container()->get(AdminAccess::class);
            $out = array_filter(['manage' => $access->canManagePosts(), 'create' => $access->canCreate()]);
            foreach ($posts as $name => $post) {
                self::assertNotNull($post);
                $out += array_filter(["edit:{$name}" => $access->canEdit($post), "delete:{$name}" => $access->canDelete($post)]);
            }
            $out += array_filter(['pin' => $access->canPin(), 'admin' => $access->canAdminBlogs()]);

            return implode(' ', array_keys($out));
        };
        $all = 'manage create edit:ada delete:ada edit:bob delete:bob edit:synced delete:synced pin';
        self::assertSame($all . ' admin', $caps($users['root']));
        self::assertSame($all, $caps($users['ed']));
        self::assertSame('manage create edit:ada delete:ada', $caps($users['ada']));
        self::assertSame('', $caps($users['mia']));
        self::assertSame('', $caps(null));
    }

    public function testAssertEditAndAssertDeleteThrowForbidden(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $bobs = $this->postAs($app, $this->user($app, 'bob', ['Authors']), $blog, ['content_html' => '<p>b</p>']);
        $app->container()->get(Auth::class)->actAs($this->user($app, 'ada', ['Authors']));
        $access = $app->container()->get(AdminAccess::class);
        foreach (['assertEdit', 'assertDelete'] as $method) {
            try {
                $access->{$method}($bobs);
                self::fail("{$method} must refuse");
            } catch (Forbidden) {
                self::assertSame(['id' => $bobs->id, 'owner_id' => $bobs->ownerId], AdminAccess::item($bobs));
            }
        }
    }
}
