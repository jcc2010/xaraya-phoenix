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
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Config\Env;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Container\ServiceProvider;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\ConnectionFactory;
use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Http\Emitter;
use Xaraya\Kernel\Http\Middleware\ConditionalGet;
use Xaraya\Kernel\Http\Middleware\Cors;
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\Pipeline;
use Xaraya\Kernel\Http\RouteHandler;
use Xaraya\Kernel\Log\FileLogger;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\Route;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Kernel\Routing\Router;
use Xaraya\Kernel\Routing\UrlGenerator;

final class App
{
    private readonly Container $container;

    /** @var list<Route>|null */
    private ?array $routes = null;

    /** @var list<string> */
    private array $globalMiddleware = [];

    /** @param array<string, mixed> $overrides */
    public static function boot(string $root, array $overrides = []): self
    {
        return new self(rtrim($root, '/'), $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function __construct(private readonly string $root, array $overrides)
    {
        Env::load($root . '/.env');
        $config = Config::load($root . '/config/app.php', $overrides === [] ? $root . '/var/cache/config.php' : null);
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
        $c->set(ModuleRegistry::class, fn(Container $c): ModuleRegistry => new ModuleRegistry(
            $c->get(Connection::class),
            $c->get(Migrator::class),
            array_values(array_map(fn(mixed $p): string => $this->path((string) $p), (array) $config->get('modules.paths', ['modules']))),
            __DIR__ . '/migrations',
        ));
        $c->set(EventDispatcher::class, fn(Container $c): EventDispatcher => new EventDispatcher($c));
        $c->set(MiddlewareRegistry::class, function (Container $c): MiddlewareRegistry {
            $registry = new MiddlewareRegistry($c);
            $registry->register('cors', Cors::class);
            $registry->register('conditional', ConditionalGet::class);

            return $registry;
        });
        $c->set(Router::class, fn(): Router => new Router($this->routes(), $this->debug() ? null : $this->cacheDir() . '/routes.php'));
        $c->set(UrlGenerator::class, fn(Container $c): UrlGenerator => new UrlGenerator(
            $c->get(Router::class)->routes(),
            (string) $config->get('app.url', ''),
        ));
        $c->set(ErrorHandler::class, fn(Container $c): ErrorHandler => new ErrorHandler($c->get(LoggerInterface::class), $this->debug()));

        $this->bootModules();
    }

    public function container(): Container
    {
        return $this->container;
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
        $stack = [$this->container->get(ErrorHandler::class)];
        foreach ($this->globalMiddleware as $class) {
            $middleware = $this->container->get($class);
            if (!$middleware instanceof MiddlewareInterface) {
                throw new LogicException("{$class} is not a MiddlewareInterface");
            }
            $stack[] = $middleware;
        }
        $final = new RouteHandler(
            $this->container->get(Router::class),
            $this->container->get(MiddlewareRegistry::class),
            $this->container,
        );

        return (new Pipeline($stack, $final))->handle($request);
    }

    public function run(): void
    {
        $factory = new Psr17Factory();
        $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
        (new Emitter())->emit($this->handle($request), $request->getMethod() !== 'HEAD');
    }

    public function clearCache(): int
    {
        $removed = 0;
        foreach ([...(glob($this->cacheDir() . '/*.php') ?: []), ...(glob($this->root . '/var/cache/config.php') ?: [])] as $file) {
            if (is_file($file) && unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function cacheDir(): string
    {
        return (string) $this->config()->get('app.cache', $this->path('var/cache'));
    }

    private function bootModules(): void
    {
        $registry = $this->container->get(ModuleRegistry::class);
        $registry->registerAutoloader();
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
