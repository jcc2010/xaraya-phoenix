<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Support\Text;

/**
 * Maps h-entry properties to JSON Feed item fields and back (spec §5.5):
 * content → content_html (text is escaped into <p>…</p>, one per blank-line-separated paragraph); name → title;
 * bookmark-of → external_url (kind link); photo → image (the first) and _athenana.images (kind photo);
 * category → tags; published → date_published. Other properties are ignored.
 */
final class Mf2Mapper
{
    public const PROPERTIES = ['content', 'name', 'bookmark-of', 'photo', 'category', 'published'];

    /**
     * @param array<string, list<mixed>> $properties
     * @return array<string, mixed> item fields; `_athenana` holds kind (always) and images (with photos)
     */
    public function toItem(array $properties): array
    {
        $item = [];
        if (($content = $properties['content'][0] ?? null) !== null) {
            $item['content_html'] = self::contentHtml($content);
        }
        if (($name = $properties['name'][0] ?? null) !== null) {
            $item['title'] = self::text($name, 'name');
        }
        if (($bookmark = $properties['bookmark-of'][0] ?? null) !== null) {
            $item['external_url'] = self::url($bookmark, 'bookmark-of');
        }
        $photos = array_map(static fn(mixed $p): string => self::url($p, 'photo'), $properties['photo'] ?? []);
        if ($photos !== []) {
            $item['image'] = $photos[0];
            $item['_athenana']['images'] = array_map(static fn(string $url): array => ['url' => $url], $photos);
        }
        if (isset($properties['category'])) {
            $item['tags'] = array_map(static fn(mixed $c): string => self::text($c, 'category'), $properties['category']);
        }
        if (($published = $properties['published'][0] ?? null) !== null) {
            $item['date_published'] = self::text($published, 'published');
        }
        $item['_athenana']['kind'] = isset($item['external_url']) ? 'link' : ($photos !== [] ? 'photo' : 'note');

        return $item;
    }

    /**
     * The h-entry properties of a served item, for q=source. `name` is left out when $derivedTitle says the title is
     * only the one derived from the content (pass PostWriter::hasDerivedTitle($post)): a note has no name of its own.
     *
     * @param array<string, mixed> $item
     * @return array<string, list<mixed>>
     */
    public function fromItem(array $item, bool $derivedTitle): array
    {
        $str = static fn(string $key): ?string => is_string($item[$key] ?? null) && $item[$key] !== '' ? $item[$key] : null;
        $athenana = is_array($item['_athenana'] ?? null) ? $item['_athenana'] : [];
        $properties = [];
        if (($html = $str('content_html')) !== null) {
            $properties['content'] = [['html' => $html, 'value' => Text::plain($html)]];
        } elseif (($text = $str('content_text')) !== null) {
            $properties['content'] = [$text];
        }
        if (!$derivedTitle && ($title = $str('title')) !== null) {
            $properties['name'] = [$title];
        }
        if (($external = $str('external_url')) !== null) {
            $properties['bookmark-of'] = [$external];
        }
        $photos = [];
        foreach (is_array($athenana['images'] ?? null) ? $athenana['images'] : [] as $image) {
            if (is_array($image) && is_string($image['url'] ?? null)) {
                $photos[] = $image['url'];
            }
        }
        if ($photos === [] && ($image = $str('image')) !== null) {
            $photos[] = $image;
        }
        if ($photos !== []) {
            $properties['photo'] = $photos;
        }
        $tags = array_values(array_filter(is_array($item['tags'] ?? null) ? $item['tags'] : [], 'is_string'));
        if ($tags !== []) {
            $properties['category'] = $tags;
        }
        if (($published = $str('date_published')) !== null) {
            $properties['published'] = [$published];
        }

        return $properties;
    }

    /** Escapes plain text into paragraphs: blank lines split paragraphs, single newlines become <br>. 400 for bad UTF-8. */
    public static function textToHtml(string $text): string
    {
        $paragraphs = mb_check_encoding($text, 'UTF-8') ? preg_split('/\R\s*\R/u', trim($text)) : false;
        if ($paragraphs === false) {
            throw new HttpException(400, 'content must be valid UTF-8');
        }
        $html = '';
        foreach ($paragraphs as $paragraph) {
            if (trim($paragraph) !== '') {
                $html .= '<p>' . nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
            }
        }

        return $html;
    }

    private static function contentHtml(mixed $content): string
    {
        if (is_string($content)) {
            return self::textToHtml($content);
        }
        if (is_array($content) && is_string($content['html'] ?? null)) {
            return $content['html'];
        }
        if (is_array($content) && is_string($content['value'] ?? null)) {
            return self::textToHtml($content['value']);
        }
        throw new HttpException(400, 'content must be text or {"html": "…"}');
    }

    private static function text(mixed $value, string $property): string
    {
        if (!is_string($value)) {
            throw new HttpException(400, "{$property} must be text");
        }

        return $value;
    }

    /** A URL property: a string, or an object with a "value" (photo with alt) or a "url" (an h-cite). */
    private static function url(mixed $value, string $property): string
    {
        if (is_array($value)) {
            $value = $value['value'] ?? ($value['properties']['url'][0] ?? null);
        }
        if (!is_string($value) || $value === '') {
            throw new HttpException(400, "{$property} must be a URL");
        }

        return $value;
    }
}
