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

    private bool $ready = false;

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
        $this->ready = false;
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
        $this->ready = false;
    }

    /** @return list<string> enabled observer module names bound to $subject for $itemtype (exact or '*'), sorted */
    public function observers(string $subject, string $itemtype): array
    {
        $key = $subject . "\0" . $itemtype;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        if (!$this->ready && !($this->ready = $this->db->hasTable('hooks'))) {
            return [];
        }
        $rows = $this->db->select('hooks')
            ->columns('observer_module', 'itemtype', 'enabled')
            ->where('subject_module', '=', $subject)
            ->whereIn('itemtype', array_values(array_unique([$itemtype, '*'])))
            ->all();
        // The most specific binding wins per observer: an exact itemtype row overrides the '*' row.
        $decided = [];
        foreach ([false, true] as $exactPass) {
            foreach ($rows as $row) {
                if (((string) $row['itemtype'] === $itemtype) === $exactPass) {
                    $decided[(string) $row['observer_module']] = (bool) $row['enabled'];
                }
            }
        }
        $names = array_keys(array_filter($decided));
        $names = array_map('strval', $names);
        sort($names, SORT_STRING);

        return $this->memo[$key] = $names;
    }

    /** Whether $value is a valid module name or itemtype for a binding ('*' allowed for itemtypes). */
    public static function isValidPart(string $value, bool $wildcard = false): bool
    {
        return ($wildcard && $value === '*') || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $value) === 1;
    }

    private static function check(string $value, bool $wildcard = false): void
    {
        if (self::isValidPart($value, $wildcard)) {
            return;
        }
        throw new InvalidArgumentException("Invalid hook binding part '{$value}'");
    }
}
