<?php

declare(strict_types=1);

namespace Xaraya\Tests\Support;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\User;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\ModuleRegistry;

/** Boots the app with the phoenix theme and cheap password hashes; installed() also runs the kernel migrations. */
abstract class AuthTestCase extends AppTestCase
{
    public const PASSWORD = 'correct horse battery';

    /** The fixture module "probe": routes that expose the session and the identity. */
    public const PROBE = __DIR__ . '/../fixtures/auth';

    /**
     * @param array<string, mixed> $overrides
     * @param list<string> $modulePaths
     */
    protected function app(array $overrides = [], array $modulePaths = []): App
    {
        return $this->boot($modulePaths, [
            'themes.paths' => ['themes'],
            'app.theme' => 'phoenix',
            'app.locale' => 'en',
            'auth.password_options' => ['cost' => 4],
            ...$overrides,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<string> $modulePaths
     */
    protected function installed(array $overrides = [], array $modulePaths = []): App
    {
        $app = $this->app($overrides, $modulePaths);
        $this->migrateKernel($app);

        return $app;
    }

    /**
     * An installed app with the probe module enabled and session garbage collection off.
     *
     * @param array<string, mixed> $overrides
     */
    protected function probeApp(array $overrides = []): App
    {
        $overrides = ['session.gc_probability' => 0, ...$overrides];
        $this->installed($overrides, [self::PROBE])->container()->get(ModuleRegistry::class)->enable('probe');

        return $this->app($overrides, [self::PROBE]);
    }

    /**
     * Runs a CLI command; $stdin is what ask() reads.
     *
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    protected function xar(App $app, array $args, string $stdin = ''): array
    {
        $streams = [];
        foreach (['out', 'err', 'in'] as $name) {
            $streams[$name] = fopen('php://memory', 'w+') ?: throw new \RuntimeException('no memory stream');
        }
        fwrite($streams['in'], $stdin);
        rewind($streams['in']);
        $code = (new Application($app))->run(['xar', ...$args], new Output($streams['out'], $streams['err'], $streams['in']));
        rewind($streams['out']);
        rewind($streams['err']);

        return [$code, (string) stream_get_contents($streams['out']), (string) stream_get_contents($streams['err'])];
    }

    protected function users(App $app): UserRepository
    {
        return $app->container()->get(UserRepository::class);
    }

    protected function db(App $app): Connection
    {
        return $app->container()->get(Connection::class);
    }

    /** @param list<string> $roles */
    protected function user(App $app, string $handle, array $roles = [], string $status = 'active', string $password = self::PASSWORD): User
    {
        return $this->users($app)->create($handle, $handle . '@example.com', $password, ucfirst($handle), $status, $roles);
    }
}
