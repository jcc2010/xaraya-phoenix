<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Cli;

use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Commands\ServeCommand;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Tests\Support\AppTestCase;

final class ApplicationTest extends AppTestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/modules';

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /** @param list<string> $args */
    private function xar(array $args): int
    {
        $this->out = fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        $this->err = fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        $app = $this->boot([self::FIXTURES]);

        return (new Application($app))->run(['xar', ...$args], new Output($this->out, $this->err));
    }

    private function stdout(): string
    {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    }

    private function stderr(): string
    {
        rewind($this->err);

        return (string) stream_get_contents($this->err);
    }

    public function testHelpListsCommands(): void
    {
        self::assertSame(0, $this->xar(['help']));
        foreach (['migrate', 'migrate:rollback', 'migrate:status', 'module:list', 'module:enable', 'module:disable', 'serve', 'cache:clear'] as $name) {
            self::assertStringContainsString($name, $this->stdout());
        }
    }

    public function testModuleLifecycle(): void
    {
        self::assertSame(0, $this->xar(['module:list']));
        self::assertMatchesRegularExpression('/alpha\s+1\.2\.0\s+no/', $this->stdout());

        self::assertSame(0, $this->xar(['module:enable', 'alpha']));
        self::assertStringContainsString("Enabled module 'alpha'", $this->stdout());

        self::assertSame(0, $this->xar(['migrate:status']));
        self::assertMatchesRegularExpression('/alpha\s+2026_10_08_000001_create_alpha_items\s+\d+/', $this->stdout());

        self::assertSame(1, $this->xar(['module:enable', 'zeta']));
        self::assertStringContainsString("Unknown module 'zeta'", $this->stderr());

        self::assertSame(0, $this->xar(['module:disable', 'alpha']));
        self::assertSame(0, $this->xar(['module:list']));
        self::assertMatchesRegularExpression('/alpha\s+1\.2\.0\s+no/', $this->stdout());
    }

    public function testMigrateAndRollback(): void
    {
        $this->xar(['module:enable', 'alpha']);
        self::assertSame(0, $this->xar(['migrate']));
        self::assertStringContainsString('Nothing to migrate', $this->stdout());

        self::assertSame(0, $this->xar(['migrate:rollback', '--step=1']));
        self::assertStringContainsString('Rolled back: alpha/2026_10_08_000001_create_alpha_items', $this->stdout());
        self::assertStringNotContainsString('kernel/', $this->stdout(), 'kernel tables survive a module rollback');
        $db = $this->boot([self::FIXTURES])->container()->get(Connection::class);
        self::assertFalse($db->hasTable('alpha_items'));
    }

    public function testErrorsAndCommandHelp(): void
    {
        self::assertSame(1, $this->xar(['nope']));
        self::assertStringContainsString("Unknown command 'nope'", $this->stderr());

        self::assertSame(1, $this->xar(['module:enable']));
        self::assertStringContainsString('Usage: xar module:enable <name>', $this->stderr());

        self::assertSame(0, $this->xar(['cache:clear', '--help']));
        self::assertStringContainsString('Usage: xar cache:clear', $this->stdout());
    }

    public function testEnabledModuleContributesCommands(): void
    {
        self::assertSame(0, $this->xar(['module:enable', 'alpha']));
        self::assertSame(0, $this->xar(['help']));
        self::assertStringContainsString('alpha:ping', $this->stdout());

        self::assertSame(0, $this->xar(['alpha:ping']));
        self::assertStringContainsString('pong', $this->stdout());
    }

    public function testInputParsing(): void
    {
        $in = new Input(['blog', '--step=2', '--force', 'extra']);
        self::assertSame('blog', $in->argument(0));
        self::assertSame('extra', $in->argument(1));
        self::assertNull($in->argument(2));
        self::assertSame('2', $in->option('step'));
        self::assertSame('d', $in->option('missing', 'd'));
        self::assertTrue($in->flag('force'));
        self::assertFalse($in->flag('quiet'));
    }

    public function testServeCommandLine(): void
    {
        $app = $this->boot();
        $line = (new ServeCommand($app))->commandLine('127.0.0.1:9000');
        self::assertStringContainsString("-S '127.0.0.1:9000'", $line);
        self::assertStringContainsString("-t '" . $app->path('public') . "'", $line);
        self::assertStringEndsWith("'" . $app->path('public/index.php') . "'", $line);
    }

    /** Writes a module "delta" into a temp module path and enables it. */
    /** @param array<string, string> $classes class name => body after the namespace line */
    private function deltaModule(string $manifestExtra, array $classes): string
    {
        $path = $this->tmp . '/modules';
        mkdir($path . '/delta/src', 0775, true);
        file_put_contents($path . '/delta/module.json', '{"name":"delta","version":"1.0.0"' . $manifestExtra . '}');
        foreach ($classes as $class => $source) {
            file_put_contents("{$path}/delta/src/{$class}.php", "<?php\n\nnamespace Xaraya\\Module\\Delta;\n\n" . $source);
        }
        $this->boot([$path])->container()->get(\Xaraya\Kernel\Module\ModuleRegistry::class)->enable('delta');

        return $path;
    }

    /** @param list<string> $args */
    private function xarIn(string $modulePath, array $args): int
    {
        $this->out = fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        $this->err = fopen('php://memory', 'w+') ?: throw new \RuntimeException();

        return Application::main(dirname(__DIR__, 3), ['xar', ...$args], $this->overrides([$modulePath]), new Output($this->out, $this->err));
    }

    public function testBadModuleCommandsAreSkippedWithWarnings(): void
    {
        $path = $this->deltaModule(
            ',"commands":["Xaraya\\\\Module\\\\Delta\\\\Missing","Xaraya\\\\Module\\\\Delta\\\\NotACommand","Xaraya\\\\Module\\\\Delta\\\\Shadow"]',
            [
                'NotACommand' => 'final class NotACommand {}',
                'Shadow' => <<<'PHP'
                    use Xaraya\Kernel\Cli\Command;
                    use Xaraya\Kernel\Cli\Input;
                    use Xaraya\Kernel\Cli\Output;

                    final class Shadow extends Command
                    {
                        public function name(): string { return 'migrate'; }
                        public function description(): string { return 'shadowed migrate'; }
                        public function run(Input $input, Output $output): int { $output->line('hijacked'); return 0; }
                    }
                    PHP,
            ],
        );

        self::assertSame(0, $this->xarIn($path, ['help']));
        self::assertStringContainsString('module:disable', $this->stdout());
        self::assertStringNotContainsString('shadowed migrate', $this->stdout());
        self::assertStringContainsString('Missing', $this->stderr());
        self::assertStringContainsString('NotACommand', $this->stderr());
        self::assertStringContainsString("'migrate'", $this->stderr());

        self::assertSame(0, $this->xarIn($path, ['migrate']));
        self::assertStringNotContainsString('hijacked', $this->stdout());

        self::assertSame(0, $this->xarIn($path, ['module:disable', 'delta']));
        self::assertStringContainsString("Disabled module 'delta'", $this->stdout());
    }

    public function testBrokenProviderFallsBackToSafeMode(): void
    {
        $path = $this->deltaModule(
            ',"provider":"Xaraya\\\\Module\\\\Delta\\\\Provider"',
            ['Provider' => <<<'PHP'
                use Xaraya\Kernel\Container\Container;
                use Xaraya\Kernel\Container\ServiceProvider;

                final class Provider implements ServiceProvider
                {
                    public function register(Container $c): void { throw new \RuntimeException('provider exploded'); }
                }
                PHP],
        );

        self::assertSame(0, $this->xarIn($path, ['module:disable', 'delta']));
        self::assertStringContainsString('provider exploded', $this->stderr());
        self::assertStringContainsString('safe mode', $this->stderr());
        self::assertStringContainsString("Disabled module 'delta'", $this->stdout());

        self::assertSame(0, $this->xarIn($path, ['help']));
        self::assertSame('', $this->stderr(), 'boots normally once the module is disabled');
    }
}
