<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Migrations;

use DateTimeImmutable;
use RuntimeException;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\Schema\Blueprint;

final class Migrator
{
    /** @var array<string, Migration> */
    private array $loaded = [];

    public function __construct(private readonly Connection $db) {}

    public function ensureTable(): void
    {
        if ($this->db->hasTable('migrations')) {
            return;
        }
        $this->db->schema()->create('migrations', function (Blueprint $t): void {
            $t->increments();
            $t->string('module', 64);
            $t->string('name', 191);
            $t->int('batch');
            $t->datetime('ran_at');
            $t->unique('module', 'name');
        });
    }

    /**
     * @param array<string, string> $paths module => directory
     * @return list<string>
     */
    public function migrate(array $paths): array
    {
        $this->ensureTable();
        $ran = $this->ran();
        $batch = (int) $this->db->fetchValue('SELECT MAX(batch) FROM {migrations}') + 1;
        $done = [];
        foreach ($paths as $module => $dir) {
            foreach ($this->files($dir) as $name => $file) {
                if (isset($ran["{$module}/{$name}"])) {
                    continue;
                }
                $migration = $this->load($file);
                $this->atomically(function () use ($migration, $module, $name, $batch): void {
                    $migration->up($this->db->schema());
                    $this->db->insert('migrations', [
                        'module' => $module,
                        'name' => $name,
                        'batch' => $batch,
                        'ran_at' => new DateTimeImmutable(),
                    ]);
                });
                $done[] = "{$module}/{$name}";
            }
        }

        return $done;
    }

    /**
     * @param array<string, string> $paths module => directory
     * @return list<string>
     */
    public function rollback(array $paths, int $steps = 1): array
    {
        $this->ensureTable();
        $batches = array_map(
            'intval',
            array_column($this->db->fetchAll('SELECT DISTINCT batch FROM {migrations} ORDER BY batch DESC'), 'batch'),
        );
        $batches = array_slice($batches, 0, max(1, $steps));
        $rows = $this->db->select('migrations')->whereIn('batch', $batches)->orderBy('id', 'desc')->all();
        $done = [];
        foreach ($rows as $row) {
            $module = (string) $row['module'];
            $name = (string) $row['name'];
            $dir = $paths[$module] ?? throw new RuntimeException("No migration path known for module '{$module}'");
            $migration = $this->load("{$dir}/{$name}.php");
            $this->atomically(function () use ($migration, $row): void {
                $migration->down($this->db->schema());
                $this->db->delete('migrations', ['id' => $row['id']]);
            });
            $done[] = "{$module}/{$name}";
        }

        return $done;
    }

    /**
     * @param array<string, string> $paths
     * @return list<array{module: string, name: string, batch: ?int}>
     */
    public function status(array $paths): array
    {
        $this->ensureTable();
        $ran = $this->ran();
        $status = [];
        foreach ($paths as $module => $dir) {
            foreach (array_keys($this->files($dir)) as $name) {
                $status[] = ['module' => $module, 'name' => $name, 'batch' => $ran["{$module}/{$name}"] ?? null];
            }
        }

        return $status;
    }

    /** @return array<string, int> "module/name" => batch */
    private function ran(): array
    {
        $ran = [];
        foreach ($this->db->fetchAll('SELECT module, name, batch FROM {migrations}') as $row) {
            $ran[$row['module'] . '/' . $row['name']] = (int) $row['batch'];
        }

        return $ran;
    }

    /** @return array<string, string> name => file, sorted */
    private function files(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.php') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $file) {
            $out[basename($file, '.php')] = $file;
        }

        return $out;
    }

    private function load(string $file): Migration
    {
        if (isset($this->loaded[$file])) {
            return $this->loaded[$file];
        }
        if (!is_file($file)) {
            throw new RuntimeException("Migration file {$file} not found");
        }
        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new RuntimeException("Migration {$file} must return a Migration instance");
        }

        return $this->loaded[$file] = $migration;
    }

    private function atomically(callable $fn): void
    {
        if ($this->db->dialect()->transactionalDdl()) {
            $this->db->transaction(fn() => $fn());
        } else {
            $fn();
        }
    }
}
