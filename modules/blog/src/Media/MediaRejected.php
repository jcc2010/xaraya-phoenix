<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Media;

use RuntimeException;

/** Bytes MediaStore refuses to keep. $status is the HTTP answer for an upload: 413 too large, 415 type, 422 invalid. */
final class MediaRejected extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
