<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use InvalidArgumentException;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\Manifest;

final class BlockRepository
{
    private ?bool $ready = null; // cached only once true, so a later migration is noticed

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
        if (!BlockInstance::isValidType($type)) {
            throw new InvalidArgumentException("Invalid block type '{$type}'");
        }
        if (!BlockInstance::isValidRegion($region)) {
            throw new InvalidArgumentException("Invalid block region '{$region}'");
        }
        $visibility = BlockInstance::checkedVisibility($visibility);
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
        if ($this->ready !== true) {
            if (!$this->db->hasTable('blocks')) {
                return [];
            }
            $this->ready = true;
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
        $this->seedDefaults($manifest->blockDefaults());
    }

    /**
     * Creates all the defaults or none of them.
     *
     * @param list<array{type: string, region: string, title: ?string, config: array<string, mixed>, visibility: array{routes?: list<string>, roles?: list<string>}, sort: int}> $defaults
     */
    public function seedDefaults(array $defaults): void
    {
        $this->db->transaction(function () use ($defaults): void {
            foreach ($defaults as $default) {
                $this->create($default['type'], $default['region'], $default['title'], $default['config'], $default['visibility'], $default['sort']);
            }
        });
    }
}
