<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Settings;

use InvalidArgumentException;
use Xaraya\Kernel\Db\Connection;

/** The general key-value store (xar_settings): theme settings use scope "theme.<name>", modules their own name. */
final class Settings
{
    /** @var array<string, array<string, mixed>> */
    private array $loaded = [];

    private ?bool $ready = null;

    public function __construct(private readonly Connection $db) {}

    public function get(string $scope, string $key, mixed $default = null): mixed
    {
        $values = $this->all($scope);

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    /** @return array<string, mixed> */
    public function all(string $scope): array
    {
        self::check($scope, 'scope', 64);
        if (isset($this->loaded[$scope])) {
            return $this->loaded[$scope];
        }
        $values = [];
        if ($this->ready()) {
            foreach ($this->db->select('settings')->where('scope', '=', $scope)->orderBy('key')->all() as $row) {
                $values[(string) $row['key']] = json_decode((string) $row['value'], true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $this->loaded[$scope] = $values;
    }

    public function set(string $scope, string $key, mixed $value): void
    {
        self::check($scope, 'scope', 64);
        self::check($key, 'key', 191);
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $this->db->upsert('settings', ['scope' => $scope, 'key' => $key, 'value' => $json], ['scope', 'key']);
        unset($this->loaded[$scope]);
        $this->ready = null;
    }

    public function forget(string $scope, string $key): void
    {
        self::check($scope, 'scope', 64);
        self::check($key, 'key', 191);
        if ($this->ready()) {
            $this->db->delete('settings', ['scope' => $scope, 'key' => $key]);
        }
        unset($this->loaded[$scope]);
    }

    private function ready(): bool
    {
        return $this->ready ??= $this->db->hasTable('settings');
    }

    private static function check(string $value, string $what, int $max): void
    {
        if (strlen($value) > $max || preg_match('/^[\x21-\x7e]+$/D', $value) !== 1) {
            throw new InvalidArgumentException("A setting {$what} must be 1-{$max} printable ASCII characters without spaces");
        }
    }
}
