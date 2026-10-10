<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use InvalidArgumentException;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\Manifest;

final class BlockRepository
{
    private ?bool $ready = null;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string, mixed> $config
     * @param array{routes?: list<string>, roles?: list<string>} $visibility
     */
    public function create(
        string $type,
        string $region,
        ?string $title = null,
        array $config = [],
        array $visibility = [],
        int $sort = 0,
        bool $enabled = true,
    ): int {
        if (strlen($type) > 128 || preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)*$/D', $type) !== 1) {
            throw new InvalidArgumentException("Invalid block type '{$type}'");
        }
        if (strlen($region) > 64 || preg_match('/^[a-z][a-z0-9_-]*$/D', $region) !== 1) {
            throw new InvalidArgumentException("Invalid block region '{$region}'");
        }
        $this->db->insert('blocks', [
            'type' => $type,
            'region' => $region,
            'title' => $title,
            'config' => $config,
            'sort' => $sort,
            'visibility' => $visibility,
            'enabled' => $enabled,
        ]);
        $this->ready = true;

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function find(int $id): ?BlockInstance
    {
        $row = $this->db->select('blocks')->where('id', '=', $id)->first();

        return $row === null ? null : BlockInstance::fromRow($row);
    }

    /** @return list<BlockInstance> the region's enabled blocks, by sort then id */
    public function forRegion(string $region): array
    {
        if (!($this->ready ??= $this->db->hasTable('blocks'))) {
            return [];
        }
        $rows = $this->db->select('blocks')
            ->where('region', '=', $region)
            ->where('enabled', '=', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->all();

        return array_map(BlockInstance::fromRow(...), $rows);
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $this->db->update('blocks', ['enabled' => $enabled], ['id' => $id]);
    }

    /** Creates the manifest's blockDefaults; called once, on the module's first install. */
    public function seed(Manifest $manifest): void
    {
        foreach ($manifest->blockDefaults() as $default) {
            $this->create($default['type'], $default['region'], $default['title'], $default['config'], $default['visibility'], $default['sort']);
        }
    }
}
