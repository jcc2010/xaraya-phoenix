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
use Xaraya\Kernel\Assets\AssetUrls;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Kernel\Auth\Http\AuthRoutes;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Kernel\Auth\Throttle;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Blocks\BlockRenderer;
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Kernel\Blocks\BlockTypes;
use Xaraya\Kernel\Blocks\LoginBlock;
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
use Xaraya\Kernel\Http\ClientIp;
use Xaraya\Kernel\Http\Emitter;
use Xaraya\Kernel\Http\HomeController;
use Xaraya\Kernel\Http\Middleware\Authenticate;
use Xaraya\Kernel\Http\Middleware\ConditionalGet;
use Xaraya\Kernel\Http\Middleware\Cors;
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\Middleware\RequireAuth;
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\Middleware\SessionMiddleware;
use Xaraya\Kernel\Http\Middleware\ThrottleRequests;
use Xaraya\Kernel\Http\Middleware\VerifyCsrf;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\Pipeline;
use Xaraya\Kernel\Http\RouteHandler;
use Xaraya\Kernel\Log\FileLogger;
use Xaraya\Kernel\Mail\LogMailer;
use Xaraya\Kernel\Mail\Mailer;
use Xaraya\Kernel\Mail\MailFunctionMailer;
use Xaraya\Kernel\Mail\MemoryMailer;
use Xaraya\Kernel\Mail\SmtpMailer;
use Xaraya\Kernel\Module\Manifest;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\Route;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Kernel\Routing\Router;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Support\HtmlSanitizer;
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

    /** @var list<string> after ErrorHandler, before the router (kernel spec §3.3); pushMiddleware() appends */
    private array $globalMiddleware = [SessionMiddleware::class, Authenticate::class, VerifyCsrf::class];

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
                $c->get(Grants::class)->seed($manifest);
                if ($firstInstall) {
                    $c->get(BlockRepository::class)->seed($manifest);
                }
            });

            return $registry;
        });
        $c->set(UserRepository::class, fn(Container $c): UserRepository => new UserRepository(
            $c->get(Connection::class),
            (array) $config->get('auth.password_options', []),
        ));
        $c->set(Throttle::class, static fn(Container $c): Throttle => new Throttle($c->get(Connection::class)));
        $c->set(ClientIp::class, fn(): ClientIp => new ClientIp(array_values(array_filter((array) $config->get('http.trusted_proxies', []), 'is_string'))));
        $c->set(Mailer::class, function () use ($config): Mailer {
            $from = $config->get('mail.from');
            if (!is_string($from) || $from === '') {
                $host = parse_url((string) $config->get('app.url', ''), PHP_URL_HOST);
                $from = 'phoenix@example.invalid';
                if (is_string($host) && str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false
                    && filter_var('phoenix@' . $host, FILTER_VALIDATE_EMAIL) !== false) {
                    $from = 'phoenix@' . $host;
                }
            }

            return match ($config->get('mail.driver', 'log')) {
                'log' => new LogMailer($this->path((string) $config->get('mail.path', 'var/mail')), $from),
                'mail' => new MailFunctionMailer($from),
                'memory' => new MemoryMailer(),
                'smtp' => $this->smtpMailer($config, $from),
                default => throw new LogicException('mail.driver must be "log", "mail", "smtp" or "memory"'),
            };
        });
        $c->set(EventDispatcher::class, fn(Container $c): EventDispatcher => new EventDispatcher($c));
        $c->set(MiddlewareRegistry::class, function (Container $c): MiddlewareRegistry {
            $registry = new MiddlewareRegistry($c);
            $registry->register('cors', Cors::class);
            $registry->register('conditional', ConditionalGet::class);
            $registry->register('secureHtml', SecureHtml::class);
            $registry->register('auth', RequireAuth::class);
            $registry->register('throttle', ThrottleRequests::class);
            $registry->registerMarker('csrf');
            foreach ($c->get(ModuleRegistry::class)->enabled() as $manifest) {
                try {
                    $aliases = $manifest->middleware();
                } catch (\Throwable $e) {
                    $c->get(LoggerInterface::class)->error("Ignoring the middleware of module '{$manifest->name}': {$e->getMessage()}", ['exception' => $e]);
                    continue;
                }
                foreach ($aliases as $alias => $class) {
                    if ($registry->has($alias)) {
                        $c->get(LoggerInterface::class)->error("Module '{$manifest->name}' cannot register middleware '{$alias}': the alias is taken");
                        continue;
                    }
                    $registry->register($alias, $class);
                }
            }

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
            // One user's error page (a session cookie, or an identity a session or token set) is never shared.
            static fn(ServerRequestInterface $request): bool => isset($request->getCookieParams()[SessionMiddleware::cookieName($config)])
                || ($c->resolved(Auth::class) && $c->get(Auth::class)->check()),
        ));

        $c->set(Cache::class, fn(): Cache => new Cache($this->cacheDir() . '/data'));
        $c->set(HtmlSanitizer::class, static function () use ($config): HtmlSanitizer {
            $host = parse_url((string) $config->get('app.url', ''), PHP_URL_HOST);

            return new HtmlSanitizer(is_string($host) && $host !== '' ? $host : null);
        });

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

        $c->set(Access::class, fn(Container $c): Access => $c->get(Auth::class));
        $c->set(SessionStore::class, static fn(Container $c): SessionStore => new SessionStore(
            $c->get(Connection::class),
            max(60, (int) $config->get('session.max_lifetime', 86400)),
        ));
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
                'login' => LoginBlock::class,
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

        $c->set(AssetUrls::class, fn(): AssetUrls => new AssetUrls((string) $config->get('assets.url', '/assets')));
        $c->set(AssetPublisher::class, fn(Container $c): AssetPublisher => new AssetPublisher(
            $c->get(ModuleRegistry::class),
            $c->get(ThemeRegistry::class),
            $this->path((string) $config->get('assets.path', 'public/assets')),
            null,
            $c->get(LoggerInterface::class),
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

    /** The "smtp" driver from mail.smtp.*: the port defaults by encryption (tls 587, ssl 465, none 25). */
    private function smtpMailer(Config $config, string $from): SmtpMailer
    {
        $host = (string) $config->get('mail.smtp.host', '');
        if ($host === '') {
            throw new LogicException('mail.driver "smtp" needs mail.smtp.host (MAIL_HOST)');
        }
        $encryption = (string) $config->get('mail.smtp.encryption', 'tls');
        $port = (string) $config->get('mail.smtp.port', '');
        if ($port !== '' && !ctype_digit($port)) {
            throw new LogicException('mail.smtp.port (MAIL_PORT) must be a number');
        }
        $appHost = parse_url((string) $config->get('app.url', ''), PHP_URL_HOST);
        $helo = match (true) {
            !is_string($appHost) || $appHost === '' => 'localhost',
            filter_var($appHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => "[{$appHost}]",
            filter_var(trim($appHost, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => '[IPv6:' . trim($appHost, '[]') . ']',
            preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $appHost) === 1 => $appHost,
            default => 'localhost',
        };
        $user = (string) $config->get('mail.smtp.username', '');
        $password = (string) $config->get('mail.smtp.password', '');

        return new SmtpMailer(
            $host,
            $port !== '' ? (int) $port : match ($encryption) {
                'ssl' => 465, 'none' => 25, default => 587,
            },
            $encryption,
            $from,
            $user !== '' ? $user : null,
            $password !== '' ? $password : null,
            $helo,
            (float) $config->get('mail.smtp.timeout', 10),
        );
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
        (new AuthRoutes())->routes($collector);

        $claimed = false;
        foreach ($collector->routes() as $route) {
            $claimed = $claimed || $route->name === 'home' || ($route->path === '/' && in_array('GET', $route->methods, true));
        }
        if (!$claimed) {
            $collector->get('/', HomeController::class, 'home')->middleware('secureHtml', 'conditional');
        }

        return $this->routes = $collector->routes();
    }

    public function pushMiddleware(string $class): void
    {
        $this->globalMiddleware[] = $class;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Every request starts as a guest, including those (404, 405) that never reach the Authenticate middleware.
        if ($this->container->resolved(Auth::class)) {
            $this->container->get(Auth::class)->actAs(null);
        }
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
