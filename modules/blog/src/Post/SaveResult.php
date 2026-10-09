<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Post;

final class SaveResult
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';

    public function __construct(public readonly string $status, public readonly string $id) {}
}
