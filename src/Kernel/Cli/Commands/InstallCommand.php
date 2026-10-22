<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Assets\AssetPublisher;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\View\ThemeRegistry;

/**
 * `xar install` (kernel spec §4.5): checks PHP, checks the database, writes .env from .env.example when it is
 * missing (mode 0600, never overwritten), migrates, seeds roles and grants, creates the first administrator and
 * publishes the theme's assets. A database that already has users is refused unless --force is given; the
 * refusal comes before anything is written. The .env is a copy of .env.example: it holds no secrets.
 */
final class InstallCommand extends Command
{
    use AsksForPassword;

    public const EXTENSIONS = ['pdo', 'mbstring', 'json'];

    public function __construct(
        private readonly App $app,
        private readonly Connection $db,
        private readonly Migrator $migrator,
        private readonly ModuleRegistry $modules,
        private readonly Grants $grants,
        private readonly UserRepository $users,
        private readonly ThemeRegistry $themes,
        private readonly AssetPublisher $assets,
    ) {}

    public function name(): string
    {
        return 'install';
    }

    public function description(): string
    {
        return 'Set up the site: .env, database, roles and grants, the first administrator and the theme';
    }

    public function usage(): string
    {
        return 'install [--admin=<handle>] [--email=<email>] [--name=<name>] [--password-stdin] [--password=<password>, unsafe: shows in shell history and ps] [--force]';
    }

    /**
     * @param callable(string): bool $loaded
     * @return list<string> what is missing
     */
    public static function requirements(string $phpVersion, callable $loaded): array
    {
        $problems = version_compare($phpVersion, '8.3.0', '>=') ? [] : ["PHP 8.3 or later is required; this is PHP {$phpVersion}."];
        foreach (self::EXTENSIONS as $extension) {
            if (!$loaded($extension)) {
                $problems[] = "The PHP extension '{$extension}' is missing.";
            }
        }

        return $problems;
    }

    public function run(Input $input, Output $output): int
    {
        $problems = self::requirements(PHP_VERSION, 'extension_loaded');
        foreach ($problems as $problem) {
            $output->error($problem);
        }
        if ($problems !== []) {
            return 1;
        }

        try {
            $this->db->tables();
        } catch (Throwable $e) {
            $output->error("Cannot use the database: {$e->getMessage()} Check the database settings in .env (DB_DSN, DB_USER, DB_PASSWORD).");

            return 1;
        }
        if ($this->users->count() > 0 && !$input->flag('force')) {
            $output->error('This database is already installed: it has users. Run `xar install --force` to repeat the steps.');

            return 1;
        }

        $output->line('PHP ' . PHP_VERSION . ' with ' . implode(', ', self::EXTENSIONS) . '.');
        $output->line($this->writeEnv());
        $output->line('Database: ' . $this->db->dialect()->name() . '.');

        $ran = $this->migrator->migrate($this->modules->migrationPaths());
        $output->line($ran === [] ? 'The database schema is up to date.' : 'Ran ' . count($ran) . ' migration(s).');
        foreach ($this->modules->enabled() as $manifest) {
            $this->grants->seed($manifest);
        }
        $output->line('Roles and grants are in place.');

        $handle = $input->option('admin') ?? $output->ask('Administrator handle:') ?? throw new InvalidArgumentException('Give --admin=<handle> or type one');
        if ($input->flag('force') && $this->users->findByHandle($handle) !== null) {
            $output->line("The user '{$handle}' exists; left as it is.");
        } else {
            $email = $input->option('email') ?? $output->ask('Administrator email:') ?? throw new InvalidArgumentException('Give --email=<email> or type one');
            $this->users->create($handle, $email, $this->password($input, $output), $input->option('name') ?? '', 'active', ['Administrators']);
            $output->line("Created the administrator '{$handle}'.");
        }

        $theme = $this->themes->active();
        $this->assets->publish();
        $output->line("Theme '{$theme->name}' is active and its assets are published.");
        $output->line('Installed. Start it with `bin/xar serve` and log in at ' . rtrim((string) $this->app->config()->get('app.url', ''), '/') . '/login');

        return 0;
    }

    /** Creates .env exclusively (an existing file is never opened for writing) with mode 0600 from the start. */
    private function writeEnv(): string
    {
        $env = (string) $this->app->config()->get('install.env_file', $this->app->path('.env'));
        if (file_exists($env)) {
            return '.env exists; left as it is.';
        }
        $example = $this->app->path('.env.example');
        $content = is_file($example) ? file_get_contents($example) : false;
        if ($content === false) {
            throw new RuntimeException("Cannot read {$example} to write {$env}");
        }
        $umask = umask(0177);
        try {
            $handle = @fopen($env, 'xb');
        } finally {
            umask($umask);
        }
        if ($handle === false) {
            if (file_exists($env)) {
                return '.env exists; left as it is.';
            }

            throw new RuntimeException("Cannot write {$env}");
        }
        $written = fwrite($handle, $content);
        fclose($handle);
        if ($written !== strlen($content)) {
            throw new RuntimeException("Cannot write {$env} completely");
        }

        return 'Wrote .env from .env.example; edit APP_URL and the database settings there.';
    }
}
