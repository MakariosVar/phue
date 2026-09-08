<?php

declare(strict_types=1);

namespace Phue\Core;

use Phue\Core\Asset\AssetsController;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\AssetServer;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Cache\FileCache;
use Phue\Core\Database\Connection;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Routing\Exceptions\MethodNotAllowedException;
use Phue\Core\Routing\Exceptions\RouteNotFoundException;
use Phue\Core\Routing\Router;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\PhuePartialRenderer;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Class Application
 *
 * Main application entry point and dependency coordinator for the Phue framework.
 *
 * @package Phue\Core
 */
class Application
{
    /**
     * Absolute base directory path of the application.
     */
    private string $basePath;

    /**
     * Global application configuration manager.
     */
    private Config $config;

    /**
     * Dependency Injection container instance.
     */
    private Container $container;

    /**
     * HTTP router dispatcher instance.
     */
    private Router $router;

    /**
     * Event dispatcher instance.
     */
    private EventDispatcher $events;

    /**
     * Global HTTP middleware execution stack.
     *
     * @var array<int, callable|string>
     */
    private array $middlewareStack = [];

    /**
     * Loaded application module instances.
     *
     * @var array<int, object>
     */
    private array $modules = [];

    /**
     * Application debug mode state.
     */
    private bool $debug = false;

    /**
     * Initialize application with root directory path, load configs, and bootstrap infrastructure.
     *
     * @param string $basePath Application root directory path.
     */
    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');

        $this->container = new Container();
        $this->config = new Config($this->basePath . '/config');
        $this->router = new Router();
        $this->events = new EventDispatcher();
        $this->debug = (bool) $this->config->get('app.debug', false);

        // Register core framework singletons into DI container
        $this->registerCoreBindings();

        // Boot active database connection if defined in config
        $this->bootstrapDatabase();

        // Load configured module classes
        $this->loadModules((array) $this->config->get('app.modules', []));
    }

    /**
     * Bind core infrastructure instances into the dependency injection container.
     *
     * @return void
     */
    private function registerCoreBindings(): void
    {
        $this->container->singleton(Config::class, fn (): Config => $this->config);
        $this->container->singleton(Container::class, fn (): Container => $this->container);
        $this->container->singleton(Router::class, fn (): Router => $this->router);
        $this->container->singleton(EventDispatcher::class, fn (): EventDispatcher => $this->events);
        $this->container->singleton(self::class, fn (): self => $this);

        // Directory where compiled component PHP blocks are written for OPCache execution
        $viewsCacheDir = $this->basePath . '/storage/cache/views';

        // Register L2 PSR-16 Cache Service Singletons
        $this->container->singleton(CacheInterface::class, function (): CacheInterface {
            return new FileCache($this->basePath . '/storage/cache');
        });
        $this->container->alias('cache', CacheInterface::class);

        // Primary App Component Loader Singleton (/App/Frontend)
        $this->container->singleton(PhueComponentLoader::class, function () use ($viewsCacheDir): PhueComponentLoader {
            return new PhueComponentLoader(
                componentsDir: $this->basePath . '/App/Frontend',
                parser: null,
                persistentCache: $this->container->get(CacheInterface::class),
                viewsCacheDir: $viewsCacheDir
            );
        });

        // PhUI Core Component Library Loader Singleton (/src/Core/UI/Phui)
        $this->container->singleton('phui.loader', function () use ($viewsCacheDir): PhueComponentLoader {
            return new PhueComponentLoader(
                componentsDir: $this->basePath . '/src/Core/UI/Phui',
                parser: null,
                persistentCache: $this->container->get(CacheInterface::class),
                viewsCacheDir: $viewsCacheDir
            );
        });

        // Composite Component Loader Registry Singleton
        $this->container->singleton(ComponentLoaderRegistry::class, function (): ComponentLoaderRegistry {
            $registry = new ComponentLoaderRegistry();

            // Priority 1: Main Application Loader (/App/Frontend)
            $registry->addLoader(function (): ?PhueComponentLoader {
                try {
                    return $this->container->get(PhueComponentLoader::class);
                } catch (Throwable) {
                    return null;
                }
            });

            // Priority 2: PhUI Framework Core Library Loader (/src/Core/UI/Phui)
            $registry->addLoader(function (): ?PhueComponentLoader {
                try {
                    return $this->container->get('phui.loader');
                } catch (Throwable) {
                    return null;
                }
            });

            return $registry;
        });

        // Phue Partial Renderer Singleton
        $this->container->singleton(PhuePartialRenderer::class, function ($c): PhuePartialRenderer {
            return new PhuePartialRenderer($c->get(ComponentLoaderRegistry::class));
        });

        $this->container->singleton(ComponentRenderer::class, function ($c) {
            return new ComponentRenderer(
                shellRenderer: $c->get(HtmlShellRenderer::class),
                assetRegistry: $c->get(AssetRegistry::class),
                loader: $c->get(PhueComponentLoader::class),
                layoutName: 'AppLayout',
                uriResolver: fn() => \Phue\Core\Request::capture()->getUri(),
                partialRenderer: $c->get(PhuePartialRenderer::class)
            );
        });

        // Core Asset Infrastructure Singletons
        $this->container->singleton(AssetRegistry::class, function (): AssetRegistry {
            $registry = new AssetRegistry();

            // Register default framework static styles path if directory exists
            $stylesPath = $this->basePath . '/storage/assets/styles';
            if (is_dir($stylesPath)) {
                $registry->registerSource('/assets/styles', $stylesPath);
            }

            // Register default framework static js path if directory exists
            $jsPath = $this->basePath . '/storage/assets/js';
            if (is_dir($jsPath)) {
                $registry->registerSource('/assets/js', $jsPath);
            }

            // Composite asset resolver for Phue JS Modules (/assets/phue/{Name}.{hash}.phue.js)
            $registry->addResolver('/assets/phue/', function (string $path): ?string {
                if (!preg_match('/^([A-Za-z0-9_\-\/]+)\.([a-f0-9]{8})\.phue\.js$/i', $path, $matches)) {
                    return null;
                }

                $name = $matches[1];
                $hash = $matches[2];

                /** @var ComponentLoaderRegistry $loaderRegistry */
                $loaderRegistry = $this->container->get(ComponentLoaderRegistry::class);

                return $loaderRegistry->scriptFor($name, $hash);
            });

            return $registry;
        });

        $this->container->singleton(AssetServer::class, fn (): AssetServer => new AssetServer(
            $this->container->get(AssetRegistry::class),
            (bool) $this->config->get('app.debug', false)
        ));

        $this->container->singleton(HtmlShellRenderer::class, function (): HtmlShellRenderer {
            $renderer = new HtmlShellRenderer($this->container->get(AssetRegistry::class));

            // Automatically inject default phue.css into shell head
            $renderer->addStyle('/assets/styles/phue.css');

            // Explicitly register navigation interceptor script
            $renderer->addScript('/assets/js/phue-explorer.js', true);

            return $renderer;
        });

        // Auto-register AssetsController to Router
        $this->router->registerController(AssetsController::class);
    }

    /**
     * Automatically bootstrap Active Record and Database Connection using config values.
     *
     * @return void
     */
    private function bootstrapDatabase(): void
    {
        /** @var string|null $defaultConnection */
        $defaultConnection = $this->config->get('database.default');

        if (!$defaultConnection) {
            return;
        }

        $dbConfig = $this->config->get("database.connections.{$defaultConnection}");

        if (is_array($dbConfig)) {
            $connection = new Connection($dbConfig);
            $this->container->singleton(Connection::class, fn (): Connection => $connection);
            BaseActiveRecord::setConnection($connection);
        }
    }

    /**
     * Retrieve the root application directory path.
     *
     * @return string Absolute base path string.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Retrieve the application configuration instance.
     *
     * @return Config Configuration manager instance.
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Register a global HTTP middleware callable or class name to the stack.
     *
     * @param callable|string $middleware Callable implementation or class FQCN to be resolved via DI container.
     *
     * @return self Fluent instance reference.
     */
    public function use(callable|string $middleware): self
    {
        $this->middlewareStack[] = $middleware;

        return $this;
    }

    /**
     * Handle incoming HTTP Request through global middleware pipeline and router.
     *
     * @param Request|null $request Incoming HTTP request object. If null, automatically captures request from globals.
     *
     * @return Response Standardized HTTP response object.
     */
    public function handle(?Request $request = null): Response
    {
        $request ??= Request::capture();

        // Build the global middleware pipeline
        $pipeline = array_reduce(
            array_reverse($this->middlewareStack),
            fn (callable $next, callable|string $middleware) => function (Request $req) use ($next, $middleware): Response {
                if (is_string($middleware)) {
                    /** @var callable $middleware */
                    $middleware = $this->container->get($middleware);
                }

                return $middleware($req, $next);
            },
            fn (Request $req): Response => $this->dispatchToRouter($req)
        );

        try {
            return $pipeline($request);
        } catch (RouteNotFoundException) {
            return new Response('Not Found', 404);
        } catch (MethodNotAllowedException $e) {
            return (new Response('Method Not Allowed', 405))
                ->setHeader('Allow', implode(', ', $e->getAllowedMethods()));
        } catch (Throwable $e) {
            if ($this->debug) {
                return Response::json([
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ], 500);
            }

            return Response::json(['error' => 'Internal Server Error'], 500);
        } finally {
            // Clean request-scoped services automatically at the end of the lifecycle
            $this->container->flushScope();
        }
    }

    /**
     * Dispatch request to router, wrap route-level middlewares, and resolve handler execution.
     *
     * @param Request $request Request instance to match against registered routes.
     *
     * @return Response Evaluated controller or middleware response.
     */
    protected function dispatchToRouter(Request $request): Response
    {
        $routeMatch = $this->router->dispatch(
            $request->getMethod(),
            $request->getUri()
        );

        $handler = $routeMatch->getHandler();
        $params = $routeMatch->getParams();
        $routeMiddlewares = $routeMatch->getMiddleware();

        // Core controller execution (Innermost layer of the onion)
        $coreHandler = function (Request $req) use ($handler, $params): Response {
            $resolvedHandler = $handler;

            if (is_string($resolvedHandler) && str_contains($resolvedHandler, '@')) {
                [$class, $method] = explode('@', $resolvedHandler, 2);
                $resolvedHandler = [$this->container->get($class), $method];
            } elseif (is_array($resolvedHandler) && is_string($resolvedHandler[0])) {
                $resolvedHandler[0] = $this->container->get($resolvedHandler[0]);
            }

            /** @var mixed $result */
            $result = call_user_func($resolvedHandler, $req, $params);

            return $this->normalizeResponse($result);
        };

        // If no route-specific middlewares exist, invoke core handler directly
        if (empty($routeMiddlewares)) {
            return $coreHandler($request);
        }

        // Build route-specific middleware pipeline (Class-level -> Method-level)
        $routePipeline = array_reduce(
            array_reverse($routeMiddlewares),
            fn (callable $next, callable|string $middleware) => function (Request $req) use ($next, $middleware): Response {
                if (is_string($middleware)) {
                    /** @var callable $middleware */
                    $middleware = $this->container->get($middleware);
                }

                return $middleware($req, $next);
            },
            $coreHandler
        );

        return $routePipeline($request);
    }

    /**
     * Convert handler return value into a valid Response object.
     *
     * @param mixed $result Raw output returned from a route action or controller.
     *
     * @return Response Formatted response instance.
     */
    protected function normalizeResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        return new Response((string) $result, 200);
    }

    /**
     * Instantiate and register application module extensions.
     *
     * @param array<int, string> $modules List of FQCNs representing application modules.
     *
     * @return void
     */
    protected function loadModules(array $modules): void
    {
        foreach ($modules as $moduleClass) {
            if (!class_exists($moduleClass)) {
                continue;
            }

            /** @var object $module */
            $module = $this->container->get($moduleClass);

            if ($module instanceof Module) {
                $module->id = $moduleClass;
                $module->basePath = $this->basePath;
                $module->setDependencies($this->router, $this->container, $this->events);
                $module->init();
            } elseif (method_exists($module, 'register')) {
                // Fallback support for legacy non-Module classes with register()
                $module->register($this->router, $this->container, $this->events);
            }

            $this->modules[] = $module;
        }
    }

    /**
     * Retrieve the primary dependency injection container.
     *
     * @return Container Application DI container instance.
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Retrieve the primary HTTP request router instance.
     *
     * @return Router Application routing dispatcher instance.
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Retrieve the core event dispatcher instance.
     *
     * @return EventDispatcher Central event publisher and listener manager.
     */
    public function getEvents(): EventDispatcher
    {
        return $this->events;
    }
}