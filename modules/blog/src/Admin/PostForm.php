<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Module\Blog\Source\JsonFeedAdapter;
use Xaraya\Module\Blog\Write\ItemValidator;

/**
 * The post form's fields as the user typed them, and their mapping to the JSON Feed item PostWriter takes. The
 * form checks only what it adds on top of the item (the kind list, the local date, the source pair); everything
 * else is PostWriter's validation, mapped back to form fields through field().
 */
final class PostForm
{
    /** Item key (as ItemValidator names it) => form field; a key not listed is shown above the form. */
    public const FIELDS = [
        '_athenana.kind' => 'kind',
        'title' => 'title',
        'content_html' => 'content',
        'external_url' => 'external_url',
        '_athenana.quote' => 'quote',
        '_source' => 'source_name',
        'tags' => 'tags',
        'date_published' => 'date_published',
        'image' => 'file',
        '_athenana.images' => 'file',
    ];

    /** datetime-local values: minutes, or seconds when the browser sends them. */
    private const DATE_FORMATS = ['Y-m-d\TH:i', 'Y-m-d\TH:i:s'];

    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $content,
        public readonly string $externalUrl,
        public readonly string $quote,
        public readonly string $sourceName,
        public readonly string $sourceUrl,
        public readonly string $tags,
        public readonly string $published,
        public readonly bool $pinned,
    ) {}

    public static function blank(): self
    {
        return new self('note', '', '', '', '', '', '', '', '', false);
    }

    /** @param array<array-key, mixed> $body the parsed POST body */
    public static function fromInput(array $body): self
    {
        $s = static fn(string $name): string => is_string($body[$name] ?? null) ? trim($body[$name]) : '';
        $text = static fn(string $name): string => is_string($body[$name] ?? null) ? str_replace(["\r\n", "\r"], "\n", $body[$name]) : '';

        return new self(
            $s('kind'),
            $s('title'),
            $text('content'),
            $s('external_url'),
            $text('quote'),
            $s('source_name'),
            $s('source_url'),
            $s('tags'),
            $s('date_published'),
            $s('pinned') !== '',
        );
    }

    /** @return array<string, string> form field => message */
    public function check(string $timezone): array
    {
        $errors = [];
        if (!in_array($this->kind, JsonFeedAdapter::KINDS, true)) {
            $errors['kind'] = 'Choose note, link, photo or highlight';
        }
        if ($this->published !== '' && $this->localDate($timezone) === null) {
            $errors['date_published'] = 'Use a date and time like 2026-10-22T09:30';
        }
        if ($this->sourceUrl !== '' && !ItemValidator::isHttpUrl($this->sourceUrl)) {
            $errors['source_url'] = 'Must be an http(s) URL';
        }
        if ($this->sourceUrl !== '' && $this->sourceName === '') {
            $errors['source_name'] = 'Give the source a name';
        }

        return $errors;
    }

    /** @return array<string, mixed> the JSON Feed item: only the fields that were filled in */
    public function toItem(string $timezone): array
    {
        $athenana = ['kind' => $this->kind];
        if ($this->quote !== '') {
            $athenana['quote'] = $this->quote;
        }
        $item = ['_athenana' => $athenana];
        foreach (['title' => $this->title, 'content_html' => $this->content, 'external_url' => $this->externalUrl] as $key => $value) {
            if ($value !== '') {
                $item[$key] = $value;
            }
        }
        if ($this->sourceName !== '') {
            $item['_source'] = ['name' => $this->sourceName] + ($this->sourceUrl === '' ? [] : ['url' => $this->sourceUrl]);
        }
        $tags = array_values(array_unique(array_filter(array_map(trim(...), explode(',', $this->tags)), static fn(string $t): bool => $t !== '')));
        if ($tags !== []) {
            $item['tags'] = $tags;
        }
        $date = $this->localDate($timezone);
        if ($date !== null) {
            $item['date_published'] = $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        }

        return $item;
    }

    /** @return array<string, string|bool> keyed by the HTML field names */
    public function values(): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'content' => $this->content,
            'external_url' => $this->externalUrl,
            'quote' => $this->quote,
            'source_name' => $this->sourceName,
            'source_url' => $this->sourceUrl,
            'tags' => $this->tags,
            'date_published' => $this->published,
            'pinned' => $this->pinned,
        ];
    }

    /** The form field an item error belongs to; null for one shown above the form. */
    public static function field(string $itemKey): ?string
    {
        if ($itemKey === '_athenana.tags' || str_starts_with($itemKey, '_athenana.tags.')) {
            return 'tags';
        }

        return self::FIELDS[$itemKey] ?? null;
    }

    /** The published date read in $timezone; null when empty or not exactly one of DATE_FORMATS. */
    private function localDate(string $timezone): ?DateTimeImmutable
    {
        if ($this->published === '') {
            return null;
        }
        $zone = new DateTimeZone($timezone);
        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $this->published, $zone);
            // The round trip refuses overflow (Feb 30), DST gaps and anything after the time.
            if ($date !== false && $date->format($format) === $this->published) {
                return $date;
            }
        }

        return null;
    }
}
