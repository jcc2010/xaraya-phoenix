<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Hooks;

use InvalidArgumentException;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\Manifest;

/** xar_hooks: which observer modules' display hooks run for which subject module and itemtype. */
final class HookBindings
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    private ?bool $ready = null;

    public function __construct(private readonly Connection $db) {}

    /** Adds the manifest's hookDefaults as enabled bindings; existing rows, and their enabled flag, are kept. */
    public function seed(Manifest $observer): void
    {
        foreach ($observer->hookDefaults() as $default) {
            $key = ['observer_module' => $observer->name, 'subject_module' => $default['subject'], 'itemtype' => $default['itemtype']];
            $existing = $this->db->select('hooks')
                ->where('observer_module', '=', $key['observer_module'])
                ->where('subject_module', '=', $key['subject_module'])
                ->where('itemtype', '=', $key['itemtype'])
                ->first();
            if ($existing === null) {
                $this->db->insert('hooks', [...$key, 'enabled' => true]);
            }
        }
        $this->memo = [];
        $this->ready = null;
    }

    public function set(string $observer, string $subject, string $itemtype, bool $enabled): void
    {
        self::check($observer);
        self::check($subject);
        self::check($itemtype, true);
        $this->db->upsert('hooks', [
            'observer_module' => $observer,
            'subject_module' => $subject,
            'itemtype' => $itemtype,
            'enabled' => $enabled,
        ], ['observer_module', 'subject_module', 'itemtype']);
        $this->memo = [];
        $this->ready = null;
    }

    /** @return list<string> enabled observer module names bound to $subject for $itemtype (exact or '*'), sorted */
    public function observers(string $subject, string $itemtype): array
    {
        $key = $subject . "\0" . $itemtype;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        if (!($this->ready ??= $this->db->hasTable('hooks'))) {
            return [];
        }
        $rows = $this->db->select('hooks')
            ->columns('observer_module')
            ->where('subject_module', '=', $subject)
            ->whereIn('itemtype', array_values(array_unique([$itemtype, '*'])))
            ->where('enabled', '=', true)
            ->all();
        $names = array_values(array_unique(array_map(static fn(array $row): string => (string) $row['observer_module'], $rows)));
        sort($names);

        return $this->memo[$key] = $names;
    }

    private static function check(string $value, bool $wildcard = false): void
    {
        if (($wildcard && $value === '*') || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $value) === 1) {
            return;
        }
        throw new InvalidArgumentException("Invalid hook binding part '{$value}'");
    }
}
