<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use RuntimeException;

interface HttpFetcher
{
    /** @throws RuntimeException on transport failure (HTTP error statuses are returned, not thrown) */
    public function get(string $url, ?string $etag = null): FetchResult;
}
