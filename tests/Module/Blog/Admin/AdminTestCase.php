<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Admin;

use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\User;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Write\PostWriter;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Support\Browser;
use Xaraya\Tests\Support\Html;

/** Base for the admin screen tests: a blog-enabled site, users with roles, and signed-in browsers. */
abstract class AdminTestCase extends BlogTestCase
{
    public const PASSWORD = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableBlog();
    }

    /** @param array<string, mixed> $overrides */
    protected function app(array $overrides = []): App
    {
        return parent::app([
            'themes.paths' => ['themes'],
            'app.theme' => 'phoenix',
            'app.name' => 'Phoenix',
            'app.locale' => 'en',
            'auth.password_options' => ['cost' => 4],
            'session.gc_probability' => 0,
            'view.engine' => 'php',
            ...$overrides,
        ]);
    }

    protected function site(string $engine = 'php'): App
    {
        return $this->app(['view.engine' => $engine]);
    }

    /** @param list<string> $roles */
    protected function user(App $app, string $handle, array $roles = []): User
    {
        return $app->container()->get(UserRepository::class)
            ->create($handle, $handle . '@example.com', self::PASSWORD, ucfirst($handle), 'active', $roles);
    }

    protected function signIn(App $app, string $handle): Browser
    {
        $browser = new Browser($app);
        self::assertSame(303, $browser->login($handle, self::PASSWORD)->getStatusCode());

        return $browser;
    }

    /**
     * Creates a post in $blog as $owner (a guest when null), then acts as a guest again.
     *
     * @param array<array-key, mixed> $item
     */
    protected function postAs(App $app, ?User $owner, Blog $blog, array $item): Post
    {
        $auth = $app->container()->get(Auth::class);
        $auth->actAs($owner);
        try {
            return $app->container()->get(PostWriter::class)->create($blog, $item);
        } finally {
            $auth->actAs(null);
        }
    }

    protected function posts(App $app): PostRepository
    {
        return $app->container()->get(PostRepository::class);
    }

    /**
     * Signs $handle in on a PHP and a Twig site, GETs $path on each and asserts both give 200 and the same HTML
     * (CSRF tokens masked); returns the PHP HTML.
     */
    protected function both(string $path, string $handle): string
    {
        $html = [];
        foreach (['php', 'twig'] as $engine) {
            $browser = $this->signIn($this->site($engine), $handle);
            $response = $browser->get($path);
            self::assertSame(200, $response->getStatusCode(), $engine . ' ' . $path);
            $html[$engine] = Html::normalize(preg_replace('/name="_csrf" value="[^"]*"/', 'name="_csrf" value=""', $browser->body()) ?? '');
        }
        self::assertSame($html['php'], $html['twig'], "{$path} differs between php and twig");

        return $html['php'];
    }

    /** The contents of the page's <main>. */
    public static function main(string $html): string
    {
        return preg_match('~<main\b[^>]*>(.*)</main>~s', $html, $m) === 1 ? $m[1] : '';
    }

    public static function assertPrivateNoStore(ResponseInterface $response): void
    {
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }
}
