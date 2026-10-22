<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens;

use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Module\Blog\BlogRepository;

/**
 * The blog module's BlogRepository while blog is enabled, and null otherwise. Tokens work without the blog module;
 * only a blog pin (tokens.blog_id) needs it, to be set, checked or shown.
 */
final class OptionalBlogs
{
    public function __construct(private readonly ModuleRegistry $modules, private readonly Container $container) {}

    public function repository(): ?BlogRepository
    {
        return $this->modules->isEnabled('blog') ? $this->container->get(BlogRepository::class) : null;
    }
}
