<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Settings\Settings;

final class View
{
    private const MAX_DEPTH = 50;

    private int $depth = 0;

    public function __construct(
        private readonly TemplateLocator $locator,
        private readonly PhpEngine $php,
        private readonly TwigEngine $twig,
        private readonly Container $container,
    ) {}

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = [], ?ServerRequestInterface $request = null): string
    {
        return $this->renderWith($name, $data, $this->helpers($request));
    }

    /**
     * @param array<string, mixed> $data
     * @param string|null $engine 'php' or 'twig' to stay in one engine; null lets the file found decide
     */
    public function renderWith(string $name, array $data, Helpers $x, ?string $engine = null): string
    {
        $path = $this->locator->find($name, $engine) ?? throw $this->notFound($name, $engine);
        if ($this->depth >= self::MAX_DEPTH) {
            throw new ViewException("Template recursion deeper than " . self::MAX_DEPTH . " while rendering '{$name}'");
        }
        $this->depth++;
        try {
            return (str_ends_with($path, '.twig') ? $this->twig : $this->php)->render($name, $path, $data, $x);
        } finally {
            $this->depth--;
        }
    }

    /** Renders the page body, then the theme's "layout" around it. */
    public function page(Page $page, ?ServerRequestInterface $request = null): string
    {
        $x = $this->helpers($request);
        $content = $this->renderWith($page->template, $page->data, $x);
        $site = (string) $x->config('app.name', 'Xaraya Phoenix');

        return $this->renderWith('layout', [
            'content' => $content,
            'title' => $page->title !== '' ? $page->title : $site,
            'site' => $site,
            'lang' => str_replace('_', '-', $page->lang ?? (string) $x->config('app.locale', 'en')),
            'meta' => $page->meta,
            'feeds' => $page->feeds,
            'canonical' => $page->canonical,
            'styles' => $page->styles,
            'settings' => $this->themeSettings(),
        ], $x);
    }

    /** @return array<string, mixed> theme.json defaults (parents first), overridden by stored "theme.<name>" settings */
    public function themeSettings(): array
    {
        $themes = $this->container->get(ThemeRegistry::class);
        $defaults = $themes->settingDefaults();
        $stored = $this->container->get(Settings::class)->all('theme.' . $themes->active()->name);

        return [...$defaults, ...array_intersect_key($stored, $defaults)];
    }

    public function exists(string $name): bool
    {
        return $this->locator->find($name) !== null;
    }

    public function helpers(?ServerRequestInterface $request = null): Helpers
    {
        return new Helpers($this, $this->container, $request);
    }

    private function notFound(string $name, ?string $engine): ViewException
    {
        $file = TemplateLocator::parse($name)[1];
        $files = array_map(static fn(string $ext): string => "{$file}.{$ext}", $engine === null ? TemplateLocator::EXTENSIONS : [$engine]);

        return new ViewException(
            "Template '{$name}'" . ($engine === null ? '' : " ({$engine})") . ' not found in: '
            . implode(', ', $this->locator->directories($name)) . ' (looking for ' . implode(', ', $files) . ')',
        );
    }
}
