<?php

declare(strict_types=1);

namespace Xaraya\Kernel;

use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Xaraya\Kernel\Assets\AssetPublisher;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\GuestAccess;
use Xaraya\Kernel\Blocks\BlockRenderer;
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Kernel\Blocks\BlockTypes;
use Xaraya\Kernel\Blocks\MenuBlock;
use Xaraya\Kernel\Blocks\RecentItemsBlock;
use Xaraya\Kernel\Blocks\TextBlock;
use Xaraya\Kernel\Cache\Cache;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Config\Env;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Container\ServiceProvider;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\ConnectionFactory;
use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Hooks\HookBindings;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\Emitter;
use Xaraya\Kernel\Http\Middleware\ConditionalGet;
use Xaraya\Kernel\Http\Middleware\Cors;
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\Pipeline;
use Xaraya\Kernel\Http\RouteHandler;
use Xaraya\Kernel\Log\FileLogger;
use Xaraya\Kernel\Module\Manifest;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\Route;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Kernel\Routing\Router;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\ErrorPages;
use Xaraya\Kernel\View\PhpEngine;
use Xaraya\Kernel\View\TemplateLocator;
use Xaraya\Kernel\View\ThemeRegistry;
use Xaraya\Kernel\View\Translator;
use Xaraya\Kernel\View\TwigEngine;
use Xaraya\Kernel\View\View;
use Xaraya\Kernel\View\ViewException;

final class App
{
    private readonly Container $container;

    /** @var list<Route>|null */
    private ?array $routes = null;

    /** @var list<string> */
    private array $globalMiddleware = [];

    /**
     * @param array<string, mixed> $overrides
     * @param bool $safe skip module service providers and event subscribers, so a broken module cannot
     *                   stop the CLI from disabling it
     * @param bool $useConfigCache read and write var/cache/config.php (web requests only; the CLI passes
     *                             false so env-var overrides are never shadowed by a stale cache)
     */
    public static function boot(string $root, array $overrides = [], bool $safe = false, bool $useConfigCache = true): self
    {
        return new self(rtrim($root, '/'), $overrides, $safe, $useConfigCache);
    }

    /** @param array<string, mixed> $overrides */
    private function __construct(private readonly string $root, array $overrides, private readonly bool $safe, bool $useConfigCache)
    {
        Env::load($root . '/.env');
        $config = Config::load(
            $root . '/config/app.php',
            $useConfigCache && $overrides === [] ? $this->configCachePath() : null,
            [$root . '/.env'],
        );
        foreach ($overrides as $key => $value) {
            $config->set($key, $value);
        }
        date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));

        $c = $this->container = new Container();
        $c->instance(self::class, $this);
        $c->instance(Config::class, $config);
        $c->set(Connection::class, fn(): Connection => ConnectionFactory::make((array) $config->get('db', [])));
        $c->set(LoggerInterface::class, fn(): LoggerInterface => new FileLogger(
            (string) $config->get('log.path', $this->path('var/logs')),
            (string) $config->get('log.level', 'info'),
        ));
        $c->set(Migrator::class, fn(Container $c): Migrator => new Migrator($c->get(Connection::class)));
        $c->set(ModuleRegistry::class, function (Container $c) use ($config): ModuleRegistry {
            $registry = new ModuleRegistry(
                $c->get(Connection::class),
                $c->get(Migrator::class),
                array_values(array_map(fn(mixed $p): string => $this->path((string) $p), (array) $config->get('modules.paths', ['modules']))),
                __DIR__ . '/migrations',
            );
            $registry->onEnable(static function (Manifest $manifest, bool $firstInstall) use ($c): void {
                $c->get(HookBindings::class)->seed($manifest);
                if ($firstInstall) {
                    $c->get(BlockRepository::class)->seed($manifest);
                }
            });

            return $registry;
        });
        $c->set(EventDispatcher::class, fn(Container $c): EventDispatcher => new EventDispatcher($c));
        $c->set(MiddlewareRegistry::class, function (Container $c): MiddlewareRegistry {
            $registry = new MiddlewareRegistry($c);
            $registry->register('cors', Cors::class);
            $registry->register('conditional', ConditionalGet::class);
            $registry->register('secureHtml', SecureHtml::class);
            $registry->registerMarker('csrf');

            return $registry;
        });
        $c->set(Router::class, fn(): Router => new Router($this->routes(), $this->debug() ? null : $this->cacheDir() . '/routes.php'));
        $c->set(UrlGenerator::class, fn(Container $c): UrlGenerator => new UrlGenerator(
            $c->get(Router::class)->routes(),
            (string) $config->get('app.url', ''),
        ));
        $c->set(ErrorPages::class, fn(Container $c): ErrorPages => new ErrorPages($c->get(View::class), $this->debug()));
        $c->set(ErrorHandler::class, fn(Container $c): ErrorHandler => new ErrorHandler(
            $c->get(LoggerInterface::class),
            $this->debug(),
            // Built lazily: the view (theme, database for blocks and settings) is only touched for an HTML error.
            static fn(int $status, string $message, ServerRequestInterface $request): ?string => $c->get(ErrorPages::class)->render($status, $message, $request),
        ));

        $c->set(Cache::class, fn(): Cache => new Cache($this->cacheDir() . '/data'));

        $c->set(ThemeRegistry::class, fn(): ThemeRegistry => new ThemeRegistry(
            array_values(array_map(fn(mixed $p): string => $this->path((string) $p), (array) $config->get('themes.paths', ['themes']))),
            (string) $config->get('app.theme', 'phoenix'),
        ));

        $c->set(Translator::class, function (Container $c) use ($config): Translator {
            $dirs = [];
            foreach ($c->get(ModuleRegistry::class)->enabled() as $manifest) {
                $dirs[] = $manifest->path . '/lang';
            }
            try {
                $themes = array_reverse($c->get(ThemeRegistry::class)->chain());
            } catch (ViewException) {
                $themes = [];
            }
            foreach ($themes as $theme) {
                $dirs[] = $theme->path . '/lang';
            }

            return new Translator((string) $config->get('app.locale', 'en'), $dirs);
        });

        $c->set(TemplateLocator::class, function (Container $c) use ($config): TemplateLocator {
            $engine = $config->get('view.engine');
            if ($engine !== null && $engine !== '' && !is_string($engine)) {
                throw new ViewException('view.engine must be "php", "twig" or empty');
            }

            return new TemplateLocator(
                $c->get(ThemeRegistry::class),
                $c->get(ModuleRegistry::class),
                $engine === null || $engine === '' ? null : $engine,
            );
        });

        $c->set(Access::class, fn(): Access => new GuestAccess());
        $c->set(TwigEngine::class, fn(Container $c): TwigEngine => new TwigEngine(
            $c->get(TemplateLocator::class),
            $this->cacheDir() . '/twig',
            $this->debug(),
        ));
        $c->set(View::class, fn(Container $c): View => new View(
            $c->get(TemplateLocator::class),
            new PhpEngine(),
            $c->get(TwigEngine::class),
            $c,
        ));

        $c->set(BlockTypes::class, function (Container $c): BlockTypes {
            $types = new BlockTypes($c, [
                'text' => TextBlock::class,
                'menu' => MenuBlock::class,
                'recent-items' => RecentItemsBlock::class,
            ]);
            foreach ($c->get(ModuleRegistry::class)->enabled() as $manifest) {
                try {
                    $blocks = $manifest->blocks();
                } catch (\Throwable $e) {
                    $c->get(LoggerInterface::class)->error("Ignoring the blocks of module '{$manifest->name}': {$e->getMessage()}", ['exception' => $e]);
                    continue;
                }
                foreach ($blocks as $type => $class) {
                    $types->register($type, $class);
                }
            }

            return $types;
        });
        $c->set(BlockRenderer::class, fn(Container $c): BlockRenderer => new BlockRenderer(
            $c->get(BlockRepository::class),
            $c->get(BlockTypes::class),
            $c->get(Access::class),
            $c->get(LoggerInterface::class),
            $this->debug(),
        ));

        $c->set(AssetPublisher::class, fn(Container $c): AssetPublisher => new AssetPublisher(
            $c->get(ModuleRegistry::class),
            $c->get(ThemeRegistry::class),
            $this->path((string) $config->get('assets.path', 'public/assets')),
        ));
        $this->bootModules();
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function safe(): bool
    {
        return $this->safe;
    }

    public function config(): Config
    {
        return $this->container->get(Config::class);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relative): string
    {
        return str_starts_with($relative, '/') ? $relative : $this->root . '/' . $relative;
    }

    public function debug(): bool
    {
        return $this->config()->get('app.debug', false) === true;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }
        $collector = new RouteCollector();
        foreach ($this->container->get(ModuleRegistry::class)->enabled() as $manifest) {
            $class = $manifest->routes();
            if ($class === null) {
                continue;
            }
            $provider = $this->container->make($class);
            if (!$provider instanceof RouteProvider) {
                throw new ModuleException("{$manifest->name}: routes class {$class} must implement RouteProvider");
            }
            $provider->routes($collector);
        }

        return $this->routes = $collector->routes();
    }

    public function pushMiddleware(string $class): void
    {
        $this->globalMiddleware[] = $class;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $final = new CallableHandler(function (ServerRequestInterface $request): ResponseInterface {
            $stack = [];
            foreach ($this->globalMiddleware as $class) {
                $middleware = $this->container->has($class) ? $this->container->get($class) : $this->container->make($class);
                if (!$middleware instanceof MiddlewareInterface) {
                    throw new LogicException("{$class} is not a MiddlewareInterface");
                }
                $stack[] = $middleware;
            }
            $route = new RouteHandler(
                $this->container->get(Router::class),
                $this->container->get(MiddlewareRegistry::class),
                $this->container,
            );
            // Match before running global middleware so it can see the RouteMatch; 404/405 go to ErrorHandler.
            $request = RouteHandler::attach($request, $route->match($request));

            return (new Pipeline($stack, $route))->handle($request);
        });

        return (new Pipeline([$this->container->get(ErrorHandler::class)], $final))->handle($request);
    }

    public function run(): void
    {
        $factory = new Psr17Factory();
        $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
        (new Emitter())->emit($this->handle($request), $request->getMethod() !== 'HEAD');
    }

    public function clearCache(): int
    {
        $removed = Cache::removeTree($this->cacheDir() . '/data') + Cache::removeTree($this->cacheDir() . '/twig');
        foreach ([...(glob($this->cacheDir() . '/*.php') ?: []), ...(glob($this->configCachePath()) ?: [])] as $file) {
            if (is_file($file) && unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function configCachePath(): string
    {
        return $this->root . '/var/cache/config.php';
    }

    private function cacheDir(): string
    {
        return (string) $this->config()->get('app.cache', $this->path('var/cache'));
    }

    private function bootModules(): void
    {
        $registry = $this->container->get(ModuleRegistry::class);
        $registry->registerAutoloader();
        if ($this->safe) {
            return;
        }
        $events = $this->container->get(EventDispatcher::class);
        foreach ($registry->enabled() as $manifest) {
            $providerClass = $manifest->provider();
            if ($providerClass !== null) {
                $provider = $this->container->make($providerClass);
                if (!$provider instanceof ServiceProvider) {
                    throw new ModuleException("{$manifest->name}: provider {$providerClass} must implement ServiceProvider");
                }
                $provider->register($this->container);
            }
            foreach ($manifest->subscribers() as $subscriber) {
                $events->listen($subscriber['event'], $subscriber['listener'], $subscriber['priority']);
            }
        }
    }
}
