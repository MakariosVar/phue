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
use Phue\Core\Ssr\BunSsrEngine;
use Phue\Core\Ssr\NullSsrEngine;
use Phue\Core\Ssr\SsrEngine;
use Phue\Core\Ssr\UnixSsrClient;
use Phue\Core\Routing\Exceptions\MethodNotAllowedException;
use Phue\Core\Routing\Exceptions\RouteNotFoundException;
use Phue\Core\Routing\Router;
use Phue\Core\UI\ComponentDiscoverer;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\PhuePartialRenderer;
use Psr\Log\LoggerInterface;
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
     * Initialize application with root directory path, load .env file, load configs, and bootstrap infrastructure.
     *
     * @param string $basePath Application root directory path.
     */
    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');

        // Load environment variables before initializing configuration manager
        Env::load($this->basePath);

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
        $this->container->singleton(LoggerInterface::class, function () {
            $logPath = $this->basePath . '/storage/logs/app.log';
            return new \Phue\Core\Logger($logPath, \Psr\Log\LogLevel::DEBUG);
        });
        $this->container->alias('logger', LoggerInterface::class);

        $this->container->singleton(Config::class, fn (): Config => $this->config);
        $this->container->singleton(Container::class, fn (): Container => $this->container);
        $this->container->singleton(Router::class, fn (): Router => $this->router);
        $this->container->singleton(EventDispatcher::class, fn (): EventDispatcher => $this->events);
        $this->container->singleton(self::class, fn (): self => $this);

        // Register default-on global security middleware (Defense-in-depth)
        $this->use(\Phue\Core\Security\SecurityMiddleware::class);

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

        // App Components Subdirectory Loader Singleton (/App/Frontend/Components)
        $this->container->singleton('app.components.loader', function () use ($viewsCacheDir): PhueComponentLoader {
            return new PhueComponentLoader(
                componentsDir: $this->basePath . '/App/Frontend/Components',
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

        $this->container->singleton(SsrEngine::class, function (): SsrEngine {
            if (!$this->config->get('ssr.enabled', true)) {
                return new NullSsrEngine();
            }

            $client = new UnixSsrClient(
                socket: (string) $this->config->get('ssr.socket', "\0phue-ssr"),
                connectTimeoutMs: (int) $this->config->get('ssr.connect_timeout_ms', 200),
                timeoutMs: (int) $this->config->get('ssr.timeout_ms', 500),
                hostHeader: (string) $this->config->get('ssr.host_header', 'phue-ssr')
            );

            return new BunSsrEngine(
                client: $client,
                maxPayloadKb: (int) $this->config->get('ssr.max_payload_kb', 512)
            );
        });
        $this->container->alias('ssr.engine', SsrEngine::class);

        // Composite Component Loader Registry Singleton
        $this->container->singleton(ComponentLoaderRegistry::class, function (): ComponentLoaderRegistry {
            $registry = new ComponentLoaderRegistry();

            // Priority 1: Main Application Loader (/App/Frontend)
            $registry->addLoader(function () use ($registry): ?PhueComponentLoader {
                try {
                    /** @var PhueComponentLoader $loader */
                    $loader = $this->container->get(PhueComponentLoader::class);
                    $loader->setRegistry($registry);
                    return $loader;
                } catch (Throwable) {
                    return null;
                }
            });

            // Priority 2: App Components Subfolder Loader (/App/Frontend/Components)
            $registry->addLoader(function () use ($registry): ?PhueComponentLoader {
                try {
                    /** @var PhueComponentLoader $loader */
                    $loader = $this->container->get('app.components.loader');
                    $loader->setRegistry($registry);
                    return $loader;
                } catch (Throwable) {
                    return null;
                }
            });

            // Priority 3: PhUI Framework Core Library Loader (/src/Core/UI/Phui)
            $registry->addLoader(function () use ($registry): ?PhueComponentLoader {
                try {
                    /** @var PhueComponentLoader $loader */
                    $loader = $this->container->get('phui.loader');
                    $loader->setRegistry($registry);
                    return $loader;
                } catch (Throwable) {
                    return null;
                }
            });

            return $registry;
        });

        // Component Discoverer Singleton
        $this->container->singleton(ComponentDiscoverer::class, function ($c): ComponentDiscoverer {
            return new ComponentDiscoverer($c->get(ComponentLoaderRegistry::class));
        });

        // Phue Partial Renderer Singleton
        $this->container->singleton(PhuePartialRenderer::class, function ($c): PhuePartialRenderer {
            return new PhuePartialRenderer($c->get(ComponentLoaderRegistry::class));
        });

        $this->container->singleton(ComponentRenderer::class, function ($c) {
            $userResolver = function () use ($c) {
                if ($c->has(\Phue\Auth\AuthManager::class)) {
                    /** @var \Phue\Auth\AuthManager $auth */
                    $auth = $c->get(\Phue\Auth\AuthManager::class);
                    return $auth->getUser();
                }
                return null;
            };

            return new ComponentRenderer(
                shellRenderer: $c->get(HtmlShellRenderer::class),
                assetRegistry: $c->get(AssetRegistry::class),
                loader: $c->get(PhueComponentLoader::class),
                layoutName: 'AppLayout',
                uriResolver: fn() => $c->get(\Phue\Core\Request::class)->getUri(),
                requestResolver: fn() => $c->get(\Phue\Core\Request::class),
                partialRenderer: $c->get(PhuePartialRenderer::class),
                userResolver: $userResolver,
                ssrEngine: $c->get(SsrEngine::class),
                loaderRegistry: $c->get(ComponentLoaderRegistry::class)
            );
        });

        // Core Asset Infrastructure Singletons
        $this->container->singleton(AssetRegistry::class, function (): AssetRegistry {
            $registry = new AssetRegistry();

            // Fix Trap B: Register /assets/vendor static source globally for non-Swoole environments
            $vendorPath = __DIR__ . '/../../assets/vendor';
            if (is_dir($vendorPath)) {
                $registry->registerSource('/assets/vendor', $vendorPath);
            }

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

            // Register global default assets directly onto AssetRegistry
            $registry->addStyle('/assets/vendor/bootstrap/bootstrap.min.css');

            // Fix Trap A: Add phue-explorer.js as a global script so it survives beginRequest() reset
            $registry->addGlobalScript('/assets/js/phue-explorer.js', isModule: true);

            // Composite asset resolver for Phue JS Modules (/assets/phue/{Name}.{hash}.phue.js)
            $registry->addResolver('/assets/phue/', function (string $path): ?string {
                if (!preg_match('/^([A-Za-z0-9_\-\/]+)\.([a-f0-9]{8})\.phue\.js$/i', $path, $matches)) {
                    return null;
                }

                $name = $matches[1];
                $hash = $matches[2];

                /** @var ComponentLoaderRegistry $loaderRegistry */
                $loaderRegistry = $this->container->get(ComponentLoaderRegistry::class);

                return $loaderRegistry->moduleFor($name, $hash);
            });

            return $registry;
        });

        $this->container->singleton(AssetServer::class, fn (): AssetServer => new AssetServer(
            $this->container->get(AssetRegistry::class),
            (bool) $this->config->get('app.debug', false)
        ));

        $this->container->singleton(HtmlShellRenderer::class, function (): HtmlShellRenderer {
            return new HtmlShellRenderer($this->container->get(AssetRegistry::class));
        });

        // Auto-register AssetsController & HealthController to Router
        $this->router->registerController(AssetsController::class);
        $this->router->registerController(\Phue\Core\Health\HealthController::class);
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
        $this->container->scoped(\Phue\Core\Request::class, fn(): Request => $request);

        // Reset per-request script tracking so SPA responses only include current page scripts
        $this->container->get(\Phue\Core\Asset\AssetRegistry::class)->beginRequest();

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

        /** @var LoggerInterface $logger */
        $logger = $this->container->get(LoggerInterface::class);

        try {
            return $pipeline($request);
        } catch (\InvalidArgumentException $e) {
            $logger->warning('HTTP 400 Bad Request: ' . $e->getMessage(), ['exception' => $e]);
            $msg = $this->debug ? $e->getMessage() : 'Bad Request';
            $response = Response::json(['error' => $msg], 400);
        } catch (RouteNotFoundException $e) {
            // Keep normal 404 route matching silent to avoid log bloat on expected missing assets/routes
            $response = new Response('Not Found', 404);
        } catch (MethodNotAllowedException $e) {
            $logger->warning('HTTP 405 Method Not Allowed: ' . $request->getMethod() . ' ' . $request->getUri(), ['exception' => $e]);
            $response = (new Response('Method Not Allowed', 405))
                ->setHeader('Allow', implode(', ', $e->getAllowedMethods()));
        } catch (Throwable $e) {
            $logger->error('HTTP 500 Unhandled Exception: ' . $e->getMessage(), ['exception' => $e]);
            if ($this->debug) {
                $response = Response::json([
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ], 500);
            } else {
                $response = Response::json(['error' => 'Internal Server Error'], 500);
            }
        } finally {
            // Clean request-scoped services automatically at the end of the lifecycle
            $this->container->flushScope();
        }

        return \Phue\Core\Security\SecurityMiddleware::applyHeaders($response, $request);
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
                $resolvedHandler = [$this->container->make($class), $method];
            } elseif (is_array($resolvedHandler) && is_string($resolvedHandler[0])) {
                $resolvedHandler[0] = $this->container->make($resolvedHandler[0]);
            }

            $coercedParams = $this->resolveActionParameters($resolvedHandler, $params);

            $reflection = is_array($resolvedHandler) 
                ? new \ReflectionMethod($resolvedHandler[0], $resolvedHandler[1])
                : new \ReflectionFunction($resolvedHandler);

                $args = [];
                foreach ($reflection->getParameters() as $param) {
                    $paramName = $param->getName();
                    $type = $param->getType();
                    $typeNames = $this->extractTypeNames($type);
    
                    // Check for Request type (Request class or subclass/interface)
                    if (in_array(Request::class, $typeNames, true) || ($type instanceof \ReflectionNamedType && $type->getName() === Request::class)) {
                        $args[] = $req;
                    } 
                    // Matches $params array (e.g. array $params or array|string $params or untyped $params)
                    elseif ($paramName === 'params' && (empty($typeNames) || in_array('array', $typeNames, true))) {
                        $args[] = $coercedParams;
                    } 
                    // Matches path parameter name (e.g. $id)
                    elseif (array_key_exists($paramName, $coercedParams)) {
                        $args[] = $coercedParams[$paramName];
                    } 
                    // Fallback to default parameter value
                    elseif ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                    }
                }

            $result = call_user_func_array($resolvedHandler, $args);
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
     * Helper to safely extract type names as strings from ReflectionType (handles Named, Union, and Intersection types).
     *
     * @return array<int, string>
     */
    private function extractTypeNames(?\ReflectionType $type): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $subType) {
                if ($subType instanceof \ReflectionNamedType) {
                    $names[] = $subType->getName();
                }
            }
            return $names;
        }

        return [];
    }


    /**
     * Resolves and casts raw string route parameters to match action method typehints.
     *
     * @param callable|array<mixed> $handler
     * @param array<string, string> $rawParams
     * @return array<string, mixed>
     * @throws \InvalidArgumentException If parameter coercion fails for matched constraints.
     */
    protected function resolveActionParameters(mixed $handler, array $rawParams): array
    {
        if (empty($rawParams)) {
            return [];
        }

        try {
            if (is_array($handler)) {
                $reflection = new \ReflectionMethod($handler[0], $handler[1]);
            } elseif (is_object($handler) && !$handler instanceof \Closure) {
                $reflection = new \ReflectionMethod($handler, '__invoke');
            } else {
                $reflection = new \ReflectionFunction($handler);
            }
        } catch (\ReflectionException) {
            return $rawParams;
        }

        $resolvedParams = $rawParams;

        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();

            if (!array_key_exists($name, $rawParams)) {
                continue;
            }

            $rawValue = $rawParams[$name];
            $type = $param->getType();

            if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
                continue;
            }

            $typeName = $type->getName();

            $resolvedParams[$name] = match ($typeName) {
                'int' => is_numeric($rawValue) 
                    ? (int) $rawValue 
                    : throw new \InvalidArgumentException("Parameter '{$name}' must be an integer."),
                'float' => is_numeric($rawValue) 
                    ? (float) $rawValue 
                    : throw new \InvalidArgumentException("Parameter '{$name}' must be a float."),
                'bool' => filter_var($rawValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (
                    is_numeric($rawValue) ? (bool) (int) $rawValue : throw new \InvalidArgumentException("Parameter '{$name}' must be a boolean.")
                ),
                'string' => (string) $rawValue,
                default => $rawValue,
            };
        }

        return $resolvedParams;
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