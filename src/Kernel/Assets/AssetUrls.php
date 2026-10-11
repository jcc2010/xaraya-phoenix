<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Assets;

use InvalidArgumentException;

/** URLs of published assets: {assets.url}/{path}, e.g. /assets/module/blog/blog.css. Templates reach it as asset(). */
final class AssetUrls
{
    public function __construct(private readonly string $baseUrl = '/assets') {}

    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        if ($path === '' || in_array('..', explode('/', $path), true)) {
            throw new InvalidArgumentException("Invalid asset path '{$path}'");
        }

        return rtrim($this->baseUrl, '/') . '/' . $path;
    }
}
