<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use Xaraya\Module\Blog\Support\Text;

final class AthenaAdapter extends JsonFeedAdapter
{
    protected function kind(array $item, ?string $content, ?string $externalUrl, ?string $image): string
    {
        $athenana = $item['_athenana'] ?? null;
        $kind = is_array($athenana) ? ($athenana['kind'] ?? null) : null;

        return is_string($kind) && in_array($kind, self::KINDS, true)
            ? $kind
            : parent::kind($item, $content, $externalUrl, $image);
    }

    protected function tags(array $item): array
    {
        $athenana = $item['_athenana'] ?? null;
        if (!is_array($athenana) || !is_array($athenana['tags'] ?? null)) {
            return parent::tags($item);
        }
        $tags = [];
        foreach ($athenana['tags'] as $tag) {
            if (!is_array($tag) || ($name = self::str($tag, 'name')) === null) {
                continue;
            }
            $name = mb_substr($name, 0, 128);
            $slug = Text::slug(self::str($tag, 'slug') ?? $name);
            $tags[$slug] ??= ['name' => $name, 'slug' => $slug];
        }

        return array_values($tags);
    }
}
