<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use InvalidArgumentException;

final class AdapterRegistry
{
    /** @var array<string, class-string<SourceAdapter>> */
    private const ADAPTERS = [
        'jsonfeed' => JsonFeedAdapter::class,
        'athena' => AthenaAdapter::class,
    ];

    public function get(string $format): SourceAdapter
    {
        $class = self::ADAPTERS[$format] ?? throw new InvalidArgumentException("Unknown source format '{$format}'");

        return new $class();
    }

    /** @return list<string> */
    public function formats(): array
    {
        return array_keys(self::ADAPTERS);
    }
}
