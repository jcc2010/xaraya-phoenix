<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use LogicException;
use Twig\Environment;
use Twig\TwigFunction;

/** Twig templates (optional: needs twig/twig). Helpers are Twig functions of the same names. */
final class TwigEngine implements Engine
{
    private ?Environment $twig = null;

    private ?Helpers $current = null;

    private readonly bool $installed;

    public function __construct(
        private readonly TemplateLocator $locator,
        private readonly ?string $cacheDir = null,
        private readonly bool $debug = false,
        ?bool $installed = null,
    ) {
        $this->installed = $installed ?? class_exists(Environment::class);
    }

    public function render(string $name, string $path, array $data, Helpers $x): string
    {
        $twig = $this->environment($path);
        $previous = $this->current;
        $this->current = $x;
        try {
            return $twig->render($name, $data);
        } finally {
            $this->current = $previous;
        }
    }

    private function environment(string $path): Environment
    {
        if (!$this->installed) {
            throw new ViewException("Cannot render {$path}: .twig templates need twig/twig, which is not installed. Run `composer require twig/twig`, or set view.engine to php and provide a .php template.");
        }
        if ($this->twig !== null) {
            return $this->twig;
        }
        $twig = new Environment(new TwigLoader($this->locator), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => $this->debug || $this->cacheDir === null ? false : $this->cacheDir,
            'auto_reload' => true,
            'debug' => $this->debug,
        ]);
        foreach (Helpers::TWIG as $function => $html) {
            $twig->addFunction(new TwigFunction(
                $function,
                fn(mixed ...$args): mixed => $this->helpers()->{$function}(...$args),
                $html ? ['is_safe' => ['html']] : [],
            ));
        }

        return $this->twig = $twig;
    }

    private function helpers(): Helpers
    {
        return $this->current ?? throw new LogicException('A Twig helper was called outside a render');
    }
}
