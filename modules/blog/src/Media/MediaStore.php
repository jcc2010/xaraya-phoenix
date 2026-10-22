<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Media;

use DateTimeImmutable;
use finfo;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Source\HttpFetcher;

final class MediaStore
{
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'audio/mpeg' => 'mp3',
    ];
    public const MAX_BYTES = 10_000_000;

    public function __construct(
        private readonly Connection $db,
        private readonly HttpFetcher $fetcher,
        private readonly LoggerInterface $logger,
        private readonly string $directory,
    ) {}

    /** @return array<string, mixed>|null */
    public function localize(Blog $blog, string $url, DateTimeImmutable $now): ?array
    {
        try {
            $existing = $this->db->select('media')
                ->where('blog_id', '=', $blog->id)
                ->where('source_url_hash', '=', sha1($url))
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            $result = $this->fetcher->get($url);
        } catch (Throwable $e) {
            $this->logger->warning('Media {url} for {blog} could not be localized: {reason}', ['url' => $url, 'blog' => $blog->handle, 'reason' => $e->getMessage()]);

            return null;
        }
        if ($result->status !== 200) {
            $this->logger->warning('Media {url} for {blog} returned HTTP {status}', ['url' => $url, 'blog' => $blog->handle, 'status' => $result->status]);

            return null;
        }

        return $this->store($blog, $result->body, $url, $now);
    }

    /**
     * Like ingest(), but logs any failure and returns null: sync and media:localize carry on without the file.
     *
     * @return array<string, mixed>|null
     */
    public function store(Blog $blog, string $bytes, ?string $sourceUrl, DateTimeImmutable $now): ?array
    {
        try {
            return $this->ingest($blog, $bytes, $sourceUrl, $now);
        } catch (Throwable $e) {
            $this->logger->warning('Media {url} for {blog} could not be stored: {reason}', ['url' => $sourceUrl ?? '(upload)', 'blog' => $blog->handle, 'reason' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Stores $bytes as a new media row of $blog. The type comes from finfo (never from a client), the file name is a
     * fresh ULID with the extension of that type, under {uploads}/{handle}/. Identical bytes share one file.
     * A non-empty $types narrows TYPES for this call (the post form takes images only); nothing is written before
     * every check has passed.
     *
     * @param list<string> $types
     * @return array<string, mixed> the media row
     * @throws MediaRejected when the bytes are empty, too large, of a type not allowed or not a valid image
     */
    public function ingest(Blog $blog, string $bytes, ?string $sourceUrl, DateTimeImmutable $now, array $types = []): array
    {
        $size = strlen($bytes);
        if ($size === 0) {
            throw new MediaRejected(422, 'The file is empty');
        }
        if ($size > self::MAX_BYTES) {
            throw new MediaRejected(413, 'The file is larger than ' . self::MAX_BYTES . ' bytes');
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($types !== [] && !in_array($mime, $types, true)) {
            throw new MediaRejected(415, 'Choose a file of type: ' . implode(', ', $types));
        }
        $ext = self::TYPES[$mime] ?? null;
        if ($ext === null) {
            throw new MediaRejected(415, "Files of type {$mime} are not allowed; send " . implode(', ', array_keys(self::TYPES)));
        }
        if (str_starts_with($mime, 'image/') && $mime !== 'image/avif' && !self::isRealImage($bytes)) {
            throw new MediaRejected(422, "The file is not a valid {$mime} image");
        }
        $sha = hash('sha256', $bytes);
        $same = $this->db->select('media')->columns('path')->where('blog_id', '=', $blog->id)->where('sha256', '=', $sha)->first();
        $path = $same !== null ? (string) $same['path'] : $this->write($blog->handle, $bytes, $ext);
        [$width, $height] = str_starts_with($mime, 'image/') ? self::dimensions($bytes) : [null, null];
        $id = Ulid::generate();
        $this->db->insert('media', [
            'id' => $id,
            'blog_id' => $blog->id,
            'source_url' => $sourceUrl,
            'source_url_hash' => $sourceUrl === null ? null : sha1($sourceUrl),
            'path' => $path,
            'mime' => $mime,
            'bytes' => $size,
            'sha256' => $sha,
            'width' => $width,
            'height' => $height,
            'created_at' => $now,
        ]);

        return $this->find($id) ?? throw new RuntimeException("Media {$id} vanished after insert");
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->db->select('media')->where('id', '=', $id)->first();
    }

    /**
     * @param list<string> $urls
     * @return array<string, array{id: string, ext: string}> source URL => media
     */
    public function lookup(int $blogId, array $urls): array
    {
        if ($urls === []) {
            return [];
        }
        $byHash = [];
        foreach ($urls as $url) {
            $byHash[sha1($url)] = $url;
        }
        $out = [];
        foreach (array_chunk(array_keys($byHash), 500) as $chunk) {
            $rows = $this->db->select('media')->columns('id', 'mime', 'source_url_hash')
                ->where('blog_id', '=', $blogId)->whereIn('source_url_hash', $chunk)->all();
            foreach ($rows as $row) {
                $url = $byHash[(string) $row['source_url_hash']] ?? null;
                $ext = self::TYPES[(string) $row['mime']] ?? null;
                if ($url !== null && $ext !== null) {
                    $out[$url] = ['id' => (string) $row['id'], 'ext' => $ext];
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    public function absolutePath(array $row): string
    {
        return rtrim($this->directory, '/') . '/' . (string) $row['path'];
    }

    private function write(string $handle, string $bytes, string $ext): string
    {
        $dir = rtrim($this->directory, '/') . '/' . $handle;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create media directory {$dir}");
        }
        $name = Ulid::generate() . '.' . $ext;
        $tmp = $dir . '/.' . $name . '.tmp';
        if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || !@rename($tmp, $dir . '/' . $name)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot write media file in {$dir}");
        }

        return $handle . '/' . $name;
    }

    private static function isRealImage(string $bytes): bool
    {
        $info = @getimagesizefromstring($bytes);

        // No GD decode: the check must behave the same on every host, with or without GD.
        return $info !== false && $info[0] >= 1 && $info[1] >= 1 && $info[0] * $info[1] <= 50_000_000;
    }

    /** @return array{0: ?int, 1: ?int} */
    private static function dimensions(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        return $info === false ? [null, null] : [(int) $info[0], (int) $info[1]];
    }
}
