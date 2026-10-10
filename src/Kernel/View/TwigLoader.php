<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/** Resolves Twig template names through the TemplateLocator, .twig files only. */
final class TwigLoader implements LoaderInterface
{
    public function __construct(private readonly TemplateLocator $locator) {}

    public function getSourceContext(string $name): Source
    {
        $path = $this->path($name);

        return new Source((string) file_get_contents($path), $name, $path);
    }

    public function getCacheKey(string $name): string
    {
        return $this->path($name);
    }

    public function isFresh(string $name, int $time): bool
    {
        $modified = @filemtime($this->path($name));

        return $modified !== false && $modified <= $time;
    }

    public function exists(string $name): bool
    {
        try {
            return $this->locator->find($name, 'twig') !== null;
        } catch (ViewException) {
            return false;
        }
    }

    private function path(string $name): string
    {
        try {
            $path = $this->locator->find($name, 'twig');
        } catch (ViewException $e) {
            throw new LoaderError($e->getMessage());
        }

        return $path ?? throw new LoaderError("Twig template '{$name}' not found in: " . implode(', ', $this->locator->directories($name)));
    }
}
