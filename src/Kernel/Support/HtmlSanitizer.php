<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Support;

use DOMCdataSection;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Allowlist HTML sanitiser for untrusted fragments (blog posts, descriptions). Parses with DOMDocument as UTF-8,
 * keeps only the allowed elements and attributes, checks every URL, and serialises again. Idempotent:
 * clean(clean($x)) === clean($x).
 */
final class HtmlSanitizer
{
    /** Elements nested deeper than this are dropped with their content. */
    public const MAX_DEPTH = 64;

    /** @var array<string, list<string>> element => allowed attributes */
    public const ALLOWED = [
        'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'u' => [], 's' => [],
        'blockquote' => ['cite'], 'q' => ['cite'], 'cite' => [], 'code' => [], 'pre' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'a' => ['href', 'title'], 'img' => ['src', 'alt', 'width', 'height'],
        'figure' => [], 'figcaption' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'hr' => [],
        'sup' => [], 'sub' => [], 'span' => [],
    ];

    /** Removed together with everything inside them. */
    public const DROPPED = [
        'script', 'style', 'iframe', 'frame', 'object', 'embed', 'form', 'input', 'button', 'textarea',
        'select', 'svg', 'math', 'template', 'noscript',
    ];

    private const URL_ATTRIBUTES = ['href', 'src', 'cite'];

    public const LINK_REL = 'nofollow noopener ugc';

    /** @param ?string $siteHost links to any other host open in a new tab */
    public function __construct(private readonly ?string $siteHost = null) {}

    public function clean(string $html): string
    {
        $html = str_replace("\0", '', mb_scrub($html, 'UTF-8'));
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            // The XML declaration makes libxml read the fragment as UTF-8 instead of Latin-1.
            $doc->loadHTML('<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        $this->children($body, 0);
        $out = '';
        foreach ($body->childNodes as $node) {
            $out .= (string) $doc->saveHTML($node);
        }

        return $out;
    }

    /** True for http:, https:, mailto: (when allowed) and scheme-less URLs, after decoding entities and removing whitespace and control characters. */
    public static function isSafeUrl(string $value, bool $allowMailto = false): bool
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $check = (string) preg_replace('/[\s\x00-\x1f\x7f]+/u', '', $decoded);
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $check, $m) !== 1) {
            return true;
        }
        $scheme = strtolower($m[1]);

        return $scheme === 'http' || $scheme === 'https' || ($allowMailto && $scheme === 'mailto');
    }

    private function children(DOMNode $parent, int $depth): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            $this->node($node, $depth);
        }
    }

    private function node(DOMNode $node, int $depth): void
    {
        $parent = $node->parentNode;
        if ($parent === null) {
            return;
        }
        if ($node instanceof DOMCdataSection) {
            $parent->replaceChild(new DOMText($node->data), $node);

            return;
        }
        if ($node instanceof DOMText) {
            return;
        }
        if (!$node instanceof DOMElement) {
            $parent->removeChild($node); // comments, processing instructions

            return;
        }
        $name = strtolower($node->nodeName);
        if (in_array($name, self::DROPPED, true) || $depth >= self::MAX_DEPTH) {
            $parent->removeChild($node);

            return;
        }
        $this->children($node, $depth + 1);
        if ($name === 'h1') {
            $node = $this->rename($node, 'h2');
            $name = 'h2';
        }
        if (!array_key_exists($name, self::ALLOWED)) {
            while ($node->firstChild !== null) {
                $parent->insertBefore($node->firstChild, $node);
            }
            $parent->removeChild($node);

            return;
        }
        $this->attributes($node, self::ALLOWED[$name]);
        if ($name === 'img' && !$node->hasAttribute('src')) {
            $parent->removeChild($node);
        } elseif ($name === 'a') {
            $this->link($node);
        }
    }

    private function rename(DOMElement $node, string $name): DOMElement
    {
        $renamed = new DOMElement($name);
        $node->parentNode?->replaceChild($renamed, $node);
        while ($node->firstChild !== null) {
            $renamed->appendChild($node->firstChild);
        }

        return $renamed;
    }

    /** @param list<string> $allowed */
    private function attributes(DOMElement $node, array $allowed): void
    {
        $names = [];
        foreach ($node->attributes as $attribute) {
            $names[] = $attribute->nodeName;
        }
        foreach ($names as $attribute) {
            $key = strtolower($attribute);
            $value = $node->getAttribute($attribute);
            $keep = in_array($key, $allowed, true) && match (true) {
                in_array($key, self::URL_ATTRIBUTES, true) => self::isSafeUrl($value, $key === 'href'),
                $key === 'width', $key === 'height' => preg_match('/^\d{1,5}$/D', $value) === 1,
                default => true,
            };
            if (!$keep) {
                $node->removeAttribute($attribute);
            }
        }
    }

    private function link(DOMElement $node): void
    {
        $node->setAttribute('rel', self::LINK_REL);
        $host = $node->hasAttribute('href') ? parse_url($node->getAttribute('href'), PHP_URL_HOST) : null;
        if (is_string($host) && $host !== '' && ($this->siteHost === null || strcasecmp($host, $this->siteHost) !== 0)) {
            $node->setAttribute('target', '_blank');
        }
    }
}
