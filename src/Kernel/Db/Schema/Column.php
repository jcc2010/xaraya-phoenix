<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Schema;

final class Column
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public mixed $default = null;
    public bool $unique = false;
    public bool $primary = false;

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
    ) {}

    public function nullable(): self
    {
        $this->nullable = true;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;

        return $this;
    }

    public function primary(): self
    {
        $this->primary = true;

        return $this;
    }
}
