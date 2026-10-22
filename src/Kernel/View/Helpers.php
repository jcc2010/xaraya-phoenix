<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Stringable;
use Xaraya\Kernel\Assets\AssetUrls;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\User;
use Xaraya\Kernel\Blocks\BlockRenderer;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Hooks\DisplayHooks;
use Xaraya\Kernel\Http\Middleware\VerifyCsrf;
use Xaraya\Kernel\Routing\UrlGenerator;

/**
 * The helper set both engines share: $x->name() in PHP templates, name() in Twig (positional arguments).
 * Services are resolved lazily, so a template that uses no helper touches no database.
 */
final class Helpers
{
    /** Twig function name => whether it returns HTML (is_safe). */
    public const TWIG = [
        'url' => false,
        'asset' => false,
        'can' => false,
        'hooks' => true,
        'blocks' => true,
        'csrf' => true,
        't' => false,
        'e' => true,
        'user' => false,
        'render' => true,
        'config' => false,
    ];

    public function __construct(
        private readonly View $view,
        private readonly Container $container,
        private readonly ?ServerRequestInterface $request = null,
    ) {}

    public function view(): View
    {
        return $this->view;
    }

    public function request(): ?ServerRequestInterface
    {
        return $this->request;
    }

    /** @param array<string, string|int> $params */
    public function url(string $name, array $params = [], bool $absolute = false): string
    {
        return $this->container->get(UrlGenerator::class)->generate($name, $params, $absolute);
    }

    /** The URL of a published asset, e.g. asset('theme/phoenix/phoenix.css'). */
    public function asset(string $path): string
    {
        return $this->container->get(AssetUrls::class)->url($path);
    }

    public function can(string $level, string $module = '*', string $component = '*', mixed $item = null): bool
    {
        return $this->container->get(Access::class)->can($level, $module, $component, $item);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $input
     */
    public function hooks(string $hook, array $item, array $input = []): string
    {
        return $this->container->get(DisplayHooks::class)->call($hook, $item, $input);
    }

    /** Renders the enabled, visible blocks of a theme region ('' when there are none). */
    public function blocks(string $region): string
    {
        return $this->container->get(BlockRenderer::class)->region($region, $this);
    }

    /** The CSRF hidden field for a form that posts back to this site ('' outside a request). */
    public function csrf(): string
    {
        $session = $this->request?->getAttribute(Session::class);

        return $session instanceof Session
            ? '<input type="hidden" name="' . VerifyCsrf::FIELD . '" value="' . $this->e($session->token()) . '">'
            : '';
    }

    /** @param array<string, mixed> $params */
    public function t(string $key, array $params = []): string
    {
        return $this->container->get(Translator::class)->t($key, $params);
    }

    public function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_scalar($value) && !$value instanceof Stringable) {
            throw new InvalidArgumentException('e() needs a scalar or Stringable, got ' . get_debug_type($value));
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** The signed-in user (a guest: null). Templates read ->handle, ->name, ->email or ->displayName(). */
    public function user(): ?User
    {
        return $this->container->get(Access::class)->user();
    }

    /**
     * Renders another template in whichever engine its file uses.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $name, array $data = []): string
    {
        return $this->view->renderWith($name, $data, $this);
    }

    /**
     * PHP templates only: renders a .php template, the PHP twin of Twig's include.
     *
     * @param array<string, mixed> $data
     */
    public function include(string $name, array $data = []): string
    {
        return $this->view->renderWith($name, $data, $this, 'php');
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->container->get(Config::class)->get($key, $default);
    }
}
