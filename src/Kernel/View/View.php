<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Container\Container;

final class View
{
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

        return (str_ends_with($path, '.twig') ? $this->twig : $this->php)->render($name, $path, $data, $x);
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
