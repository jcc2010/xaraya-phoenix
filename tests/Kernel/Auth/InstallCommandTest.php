<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Commands\InstallCommand;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class InstallCommandTest extends AuthTestCase
{
    /** @var array<string, string|null> the repo's real .env and sqlite file: a test must never touch them */
    private array $real = [];

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 3);
        foreach (['/.env', '/var/database.sqlite'] as $file) {
            $this->real[$root . $file] = is_file($root . $file) ? md5_file($root . $file) : null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->real as $file => $hash) {
            self::assertSame($hash, is_file($file) ? md5_file($file) : null, "{$file} was touched by the test");
        }
        parent::tearDown();
    }

    /** @param list<string> $modulePaths */
    private function fresh(array $modulePaths = []): App
    {
        return $this->app(['install.env_file' => $this->tmp . '/.env', 'assets.path' => $this->tmp . '/assets', 'session.gc_probability' => 0], $modulePaths);
    }

    private const FLAGS = ['install', '--admin=admin', '--email=admin@example.com', '--name=Site Admin', '--password=' . self::PASSWORD];

    public function testInstallsAnEmptyDatabaseWithoutQuestions(): void
    {
        $app = $this->fresh();
        [$code, $out, $err] = $this->xar($app, self::FLAGS);
        self::assertSame(0, $code, $err);
        $lines = explode("\n", trim($out));
        self::assertMatchesRegularExpression('/^PHP 8\.\d+\.\d+.* with pdo, mbstring, json\.$/', $lines[0]);
        self::assertSame([
            'Wrote .env from .env.example; edit APP_URL and the database settings there.',
            'Database: ' . $this->db($app)->dialect()->name() . '.',
        ], array_slice($lines, 1, 2));
        self::assertMatchesRegularExpression('/^Ran \d+ migration\(s\)\.$/', $lines[3]);
        self::assertSame([
            'Roles and grants are in place.',
            "Created the administrator 'admin'.",
            "Theme 'phoenix' is active and its assets are published.",
            'Installed. Start it with `bin/xar serve` and log in at http://xar.test/login',
        ], array_slice($lines, 4));
        self::assertFileEquals(dirname(__DIR__, 3) . '/.env.example', $this->tmp . '/.env');
        self::assertFileExists($this->tmp . '/assets/theme/phoenix/phoenix.css');
        self::assertStringNotContainsString(self::PASSWORD, $out . $err . (string) file_get_contents($this->tmp . '/.env'));

        $app = $this->fresh();
        $admin = $this->users($app)->findByHandle('admin');
        self::assertNotNull($admin);
        self::assertSame(['Administrators'], $this->users($app)->rolesOf($admin));
        self::assertSame(200, $app->handle(new ServerRequest('GET', '/'))->getStatusCode());
        $browser = new Browser($app);
        self::assertSame(303, $browser->login('admin', self::PASSWORD)->getStatusCode());
    }

    public function testTheNewEnvFileIsPrivate(): void
    {
        $this->xar($this->fresh(), self::FLAGS);
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->tmp . '/.env')), -4));
    }

    public function testAnExistingEnvFileIsNeverOverwritten(): void
    {
        file_put_contents($this->tmp . '/.env', "DB_PASSWORD=keep-me\n");
        [$code, $out, $err] = $this->xar($this->fresh(), self::FLAGS);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('.env exists; left as it is.', $out);
        self::assertSame("DB_PASSWORD=keep-me\n", file_get_contents($this->tmp . '/.env'));
    }

    public function testThePasswordCanComeFromStandardInput(): void
    {
        [$code, $out, $err] = $this->xar($this->fresh(), ['install', '--admin=ci', '--email=ci@example.com', '--password-stdin'], self::PASSWORD . "\n");
        self::assertSame(0, $code, $err);
        self::assertSame('', $err);
        self::assertStringContainsString("Created the administrator 'ci'.", $out);
        $browser = new Browser($this->fresh());
        self::assertSame(303, $browser->login('ci', self::PASSWORD)->getStatusCode());
    }

    public function testBothPasswordFlagsAreRefusedBeforeAnythingIsCreated(): void
    {
        $app = $this->fresh();
        [$code, , $err] = $this->xar($app, [...self::FLAGS, '--password-stdin'], self::PASSWORD . "\n");
        self::assertSame(1, $code);
        self::assertStringContainsString('not both', $err);
        self::assertSame(0, $this->users($this->fresh())->count());
    }

    public function testAsksForTheAdministratorWhenFlagsAreMissing(): void
    {
        [$code, $out, $err] = $this->xar($this->fresh(), ['install'], "boss\nboss@example.com\n" . self::PASSWORD . "\n" . self::PASSWORD . "\n");
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("Administrator handle: Administrator email: Password: Password again: Created the administrator 'boss'.", $out);
        [$code, , $err] = $this->xar($this->fresh(), ['install', '--force']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Give --admin=<handle> or type one', $err);
    }

    public function testAnInstalledDatabaseIsRefusedUnlessForced(): void
    {
        $this->xar($this->fresh(), self::FLAGS);
        file_put_contents($this->tmp . '/.env', "APP_URL=http://mine.example\n");
        [$code, $out, $err] = $this->xar($this->fresh(), self::FLAGS);
        self::assertSame(1, $code);
        self::assertStringContainsString('This database is already installed: it has users.', $err);
        self::assertStringNotContainsString('Created', $out);

        [$code, $out, $err] = $this->xar($this->fresh(), [...self::FLAGS, '--force']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('.env exists; left as it is.', $out);
        self::assertStringContainsString('The database schema is up to date.', $out);
        self::assertStringContainsString("The user 'admin' exists; left as it is.", $out);
        self::assertSame("APP_URL=http://mine.example\n", file_get_contents($this->tmp . '/.env'));
        self::assertSame(1, $this->users($this->fresh())->count());
    }

    public function testARefusedRunChangesNothing(): void
    {
        $this->xar($this->fresh(), self::FLAGS);
        unlink($this->tmp . '/.env');
        [$code] = $this->xar($this->fresh(), self::FLAGS);
        self::assertSame(1, $code);
        self::assertFileDoesNotExist($this->tmp . '/.env');
    }

    public function testInstallingADatabaseThatHasModulesSeedsTheirGrants(): void
    {
        $modules = [dirname(__DIR__, 3) . '/modules'];
        $app = $this->app(['blog.locks' => $this->tmp . '/locks'], $modules);
        $app->container()->get(ModuleRegistry::class)->enable('blog');
        $this->db($app)->query("DELETE FROM {grants} WHERE module = 'blog'");

        [$code, , $err] = $this->xar($this->fresh($modules), self::FLAGS);
        self::assertSame(0, $code, $err);
        self::assertSame(3, $this->db($app)->select('grants')->where('module', '=', 'blog')->count());
    }

    public function testRequirements(): void
    {
        self::assertSame([], InstallCommand::requirements('8.3.0', static fn(): bool => true));
        self::assertSame([
            'PHP 8.3 or later is required; this is PHP 8.2.9.',
            "The PHP extension 'pdo' is missing.",
            "The PHP extension 'json' is missing.",
        ], InstallCommand::requirements('8.2.9', static fn(string $e): bool => $e === 'mbstring'));
    }
}
