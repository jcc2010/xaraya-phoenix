<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Xaraya\Kernel\Db\Connection;

final class BlogRepository
{
    private const COLUMNS = [
        'handle', 'title', 'description_html', 'author_name', 'author_url', 'home_page_url', 'post_url_pattern',
        'language', 'icon', 'favicon', 'mode', 'source_url', 'source_format', 'media', 'pinned_item_id',
        'source_etag', 'last_synced_at', 'last_full_sync_at', 'extra',
    ];

    public function __construct(private readonly Connection $db) {}

    public function find(string $handle): ?Blog
    {
        return $this->load($handle);
    }

    private function load(string $handle): ?Blog
    {
        $row = $this->db->select('blogs')->where('handle', '=', $handle)->first();

        return $row === null ? null : Blog::fromRow($row);
    }

    public function findById(int $id): ?Blog
    {
        $row = $this->db->select('blogs')->where('id', '=', $id)->first();

        return $row === null ? null : Blog::fromRow($row);
    }

    /** @return list<Blog> */
    public function all(): array
    {
        return array_map(Blog::fromRow(...), $this->db->select('blogs')->orderBy('handle')->all());
    }

    /** @param array<string, mixed> $fields column => value; null values are ignored */
    public function create(array $fields, DateTimeImmutable $now): Blog
    {
        $fields = array_filter($fields, static fn(mixed $v): bool => $v !== null);
        $handle = $fields['handle'] ?? null;
        $row = array_merge(['title' => $handle, 'author_name' => $handle, 'language' => 'en', 'media' => 'remote'], $fields);
        $this->only($row);
        $this->validate($row);
        if ($this->find((string) $row['handle']) !== null) {
            throw new InvalidArgumentException("Blog '{$row['handle']}' already exists");
        }
        $this->db->insert('blogs', [...$row, 'created_at' => $now, 'updated_at' => $now]);

        return $this->load((string) $row['handle']) ?? throw new RuntimeException('Blog insert failed');
    }

    /** @param array<string, mixed> $fields column => value; null clears a nullable column */
    public function update(Blog $blog, array $fields, DateTimeImmutable $now): Blog
    {
        $this->only($fields);
        $current = $this->db->select('blogs')->where('id', '=', $blog->id)->first()
            ?? throw new RuntimeException("Blog {$blog->id} no longer exists");
        $this->validate(array_merge($current, $fields));
        if ($fields !== []) {
            $this->db->update('blogs', [...$fields, 'updated_at' => $now], ['id' => $blog->id]);
        }

        return $this->findById($blog->id) ?? throw new RuntimeException("Blog {$blog->id} no longer exists");
    }

    /** @param array<string, mixed> $row */
    private function validate(array $row): void
    {
        $handle = $row['handle'] ?? null;
        if (!is_string($handle) || preg_match('/^[a-z0-9_-]{2,32}$/D', $handle) !== 1) {
            throw new InvalidArgumentException('Handle must match [a-z0-9_-]{2,32}');
        }
        if (!in_array($row['mode'] ?? null, Blog::MODES, true)) {
            throw new InvalidArgumentException('Mode must be mirror or native');
        }
        if (!in_array($row['media'] ?? null, Blog::MEDIA, true)) {
            throw new InvalidArgumentException('Media must be remote or local');
        }
        if (!is_string($row['title'] ?? null) || $row['title'] === '') {
            throw new InvalidArgumentException('Title is required');
        }
        if ($row['mode'] === 'mirror') {
            if (!self::isHttpUrl($row['source_url'] ?? null)) {
                throw new InvalidArgumentException('A mirror blog needs an http(s) source URL');
            }
            if (!in_array($row['source_format'] ?? null, Blog::FORMATS, true)) {
                throw new InvalidArgumentException('Source format must be one of: ' . implode(', ', Blog::FORMATS));
            }
        }
        $pattern = $row['post_url_pattern'] ?? null;
        if ($pattern !== null && $pattern !== '') {
            $pattern = (string) $pattern;
            if (substr_count($pattern, '{id}') !== 1 || !self::isHttpUrl(str_replace('{id}', 'x', $pattern))) {
                throw new InvalidArgumentException('Post URL pattern must be an http(s) URL containing {id} exactly once');
            }
        }
    }

    /** @param array<string, mixed> $fields */
    private function only(array $fields): void
    {
        $unknown = array_diff(array_keys($fields), self::COLUMNS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown blog fields: ' . implode(', ', $unknown));
        }
    }

    private static function isHttpUrl(mixed $url): bool
    {
        return is_string($url) && preg_match('#^https?://[^\s/?\#]+#i', $url) === 1;
    }
}
