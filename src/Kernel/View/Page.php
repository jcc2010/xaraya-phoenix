<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

/** What a controller returns for an HTML page: RouteHandler renders it through the theme layout. */
final class Page
{
    /**
     * @param array<string, mixed> $data body template variables
     * @param array<string, string> $meta <meta> tags; names starting "og:" are written as property=
     * @param list<array{type: string, title: string, href: string}> $feeds <link rel="alternate"> entries
     * @param list<string> $styles extra stylesheet URLs, after the theme's own
     * @param array<string, string> $headers extra response headers
     */
    public function __construct(
        public readonly string $template,
        public readonly array $data = [],
        public readonly string $title = '',
        public readonly array $meta = [],
        public readonly array $feeds = [],
        public readonly ?string $canonical = null,
        public readonly ?string $lang = null,
        public readonly array $styles = [],
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {}
}
