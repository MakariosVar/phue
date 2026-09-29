<?php

declare(strict_types=1);

namespace Phue\Core;

use Phue\Core\Asset\AssetsController;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\AssetServer;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Cache\FileCache;
use Phue\Core\Database\Connection;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Security\CurrentUserInterface;
use Phue\Core\Security\SecurityMiddleware;
use Phue\Core\Ssr\BunSsrEngine;
use Phue\Core\Ssr\NullSsrEngine;
use Phue\Core\Ssr\SsrEngine;
use Phue\Core\Ssr\UnixSsrClient;
use Phue\Core\UI\TemplateCompilerBridge;
use Phue\Core\Routing\Exceptions\MethodNotAllowedException;
use Phue\Core\Routing\Exceptions\RouteNotFoundException;
use Phue\Core\Routing\Router;
use Phue\Core\Routing\Loader\AttributeRouteLoader;
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
    private bool $debug;

    /**
     * Initialize application with root directory path, load .env file, load configs, and bootstrap infrastructure.
     *
     * @param string $basePath Application root directory path.
     * @param ?bool $debug Debug mode flag.
     */
    public function __construct(string $basePath, ?bool $debug = null)
    {
        $this->basePath = rtrim($basePath, '/\\');

        // Load environment variables before initializing configuration manager
        Env::load($this->basePath);

        $this->container = new Container();
        $this->config = new Config($this->basePath . '/config');
        $this->router = new Router();
        $this->events = new EventDispatcher();

        // Explicit argument takes priority over config
        $this->debug = $debug ?? (bool) $this->config->get('app.debug', false);

        // Register core framework singletons into DI container
        $this->registerCoreBindings();

        // Boot active database connection if defined in config
        $this->bootstrapDatabase();

        // Load configured module classes
        $this->loadModules((array) $this->config->get('app.modules', []));

        // Cache application routes
        $this->cacheRoutes();
    }

    /**
     * Retrieve the current debug state.
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Cache the application routes.
     *
     * @return void
     */
    private function cacheRoutes(): void
    {
        $routeCacheFile = $this->basePath . '/storage/cache/routes.php';
        $shouldCacheRoutes = (bool) $this->config->get('app.routeCache', false);
        $modules = (array) $this->config->get('app.modules', []);

        $loader = new AttributeRouteLoader();

        // The routes the framework must always expose, derived from the
        // controllers themselves rather than written down as string literals.
        //
        // This matters: an earlier version hardcoded '/health', but the framework
        // actually registers /health/live and /health/ready. The literal matched
        // nothing, so every valid cache was rejected and route caching was
        // silently disabled everywhere — invisibly, because a rejected cache
        // falls back to a scan, which is correct and merely slow.
        //
        // Deriving the paths means a renamed route attribute cannot desync the
        // validator from reality.
        $requiredPaths = [];

        foreach ([AssetsController::class, \Phue\Core\Health\HealthController::class] as $coreController) {
            foreach ($loader->load($coreController) as $coreRoute) {
                if (isset($coreRoute['path'])) {
                    $requiredPaths[] = $coreRoute['path'];
                }
            }
        }

        if (!$shouldCacheRoutes) {
            $this->registerCoreControllers();

            return;
        }

        $fingerprint = $loader->fingerprint($this->basePath, $modules);
        $cachedRoutes = $loader->loadCached($routeCacheFile, $fingerprint, $requiredPaths);

        if ($cachedRoutes !== null) {
            // Cached routes are a complete replacement: registerCachedRoutes()
            // assigns the whole table. The core controllers must therefore be
            // part of the cached set, which is what requiredPaths above
            // guarantees, and they are not registered again here.
            $this->router->registerCachedRoutes($cachedRoutes);

            return;
        }

        $this->registerCoreControllers();
        $loader->saveCache($routeCacheFile, $this->router->getCompiledRoutes(), $fingerprint);
    }

    /**
     * Registers the framework's own HTTP surface.
     */
    private function registerCoreControllers(): void
    {
        $this->router->registerController(AssetsController::class);
        $this->router->registerController(\Phue\Core\Health\HealthController::class);
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

        $this->container->singleton(Config::class, fn(): Config => $this->config);
        $this->container->singleton(Container::class, fn(): Container => $this->container);
        $this->container->singleton(Router::class, fn(): Router => $this->router);
        $this->container->singleton(EventDispatcher::class, fn(): EventDispatcher => $this->events);
        $this->container->singleton(self::class, fn(): self => $this);

        // Explicitly register SecurityMiddleware singleton with trusted proxies configuration
        $this->container->singleton(SecurityMiddleware::class, function (): SecurityMiddleware {
            return new SecurityMiddleware((array) $this->config->get('app.trustedProxies', []));
        });

        // Register default-on global security middleware (Defense-in-depth)
        $this->use(SecurityMiddleware::class);

        // Directory where compiled component PHP blocks are written for OPCache execution
        $viewsCacheDir = $this->basePath . '/storage/cache/views';

        // Register L2 PSR-16 Cache Service Singletons
        $this->container->singleton(CacheInterface::class, function (): CacheInterface {
            return new FileCache($this->basePath . '/storage/cache');
        });
        $this->container->alias('cache', CacheInterface::class);

        // Register ErrorPageRenderer and ErrorHandlerMiddleware
        $this->container->singleton(\Phue\Core\ErrorPages\ErrorPageRenderer::class, function (): \Phue\Core\ErrorPages\ErrorPageRenderer {
            return new \Phue\Core\ErrorPages\ErrorPageRenderer($this->basePath);
        });

        $this->container->singleton(\Phue\Core\ErrorPages\ErrorHandlerMiddleware::class, function ($c): \Phue\Core\ErrorPages\ErrorHandlerMiddleware {
            return new \Phue\Core\ErrorPages\ErrorHandlerMiddleware(
                renderer: $c->get(\Phue\Core\ErrorPages\ErrorPageRenderer::class),
                logger: $c->get(LoggerInterface::class),
                debug: $this->debug
            );
        });

        // Enable ErrorHandlerMiddleware at global middleware stack
        $this->use(\Phue\Core\ErrorPages\ErrorHandlerMiddleware::class);

        // Register TemplateCompilerBridge Singleton for SSR/Client Template Compilations
        $this->container->singleton(TemplateCompilerBridge::class, function ($c): TemplateCompilerBridge {
            return new TemplateCompilerBridge(
                socketPath: (string) $this->config->get('ssr.socket', "/tmp/phue-ssr.sock"),
                cache: $c->get(CacheInterface::class)
            );
        });
        $this->container->alias('ssr.compiler', TemplateCompilerBridge::class);

        // Primary App Component Loader Singleton (/App/Frontend)
        // Note: Automatically covers both root /App/Frontend and subfolders like /App/Frontend/Components
        $this->container->singleton(PhueComponentLoader::class, function ($c) use ($viewsCacheDir): PhueComponentLoader {
            return new PhueComponentLoader(
                componentsDir: $this->basePath . '/App/Frontend',
                parser: null,
                persistentCache: $c->get(CacheInterface::class),
                viewsCacheDir: $viewsCacheDir,
                compilerBridge: $c->get(TemplateCompilerBridge::class)
            );
        });

        // PhUI Core Component Library Loader Singleton (/src/Core/UI/Phui)
        $this->container->singleton('phui.loader', function ($c) use ($viewsCacheDir): PhueComponentLoader {
            return new PhueComponentLoader(
                componentsDir: $this->basePath . '/src/Core/UI/Phui',
                parser: null,
                persistentCache: $c->get(CacheInterface::class),
                viewsCacheDir: $viewsCacheDir,
                compilerBridge: $c->get(TemplateCompilerBridge::class)
            );
        });

        // Register UnixSsrClient singleton for transport layer socket access
        $this->container->singleton(UnixSsrClient::class, function (): UnixSsrClient {
            return new UnixSsrClient(
                socket: (string) $this->config->get('ssr.socket', "/tmp/phue-ssr.sock"),
                connectTimeoutMs: (int) $this->config->get('ssr.connect_timeout_ms', 200),
                timeoutMs: (int) $this->config->get('ssr.timeout_ms', 500),
                hostHeader: (string) $this->config->get('ssr.host_header', 'phue-ssr')
            );
        });

        $this->container->singleton(SsrEngine::class, function ($c): SsrEngine {
            if (!$this->config->get('ssr.enabled', true)) {
                return new NullSsrEngine();
            }

            return new BunSsrEngine(
                client: $c->get(UnixSsrClient::class),
                maxPayloadKb: (int) $this->config->get('ssr.max_payload_kb', 512),
                cache: $c->get(CacheInterface::class)
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

            // Priority 2: PhUI Framework Core Library Loader (/src/Core/UI/Phui)
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
                if ($c->has(\Phue\Modules\Auth\AuthManager::class)) {
                    /** @var \Phue\Modules\Auth\AuthManager $auth */
                    $auth = $c->get(\Phue\Modules\Auth\AuthManager::class);

                    try {
                        /** @var \Phue\Core\Request $request */
                        $request = $c->get(\Phue\Core\Request::class);
                        return $auth->authenticate($request);
                    } catch (Throwable) {
                        return null;
                    }
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
                loaderRegistry: $c->get(ComponentLoaderRegistry::class),
                themeConfig: $this->config->get('app.themeToggle', []),
                metaConfig: $this->config->get('app.seo', [])
            );
        });

        // Core Asset Infrastructure Singletons
        $this->container->singleton(AssetRegistry::class, function (): AssetRegistry {
            $registry = new AssetRegistry();

            // Register /assets/vendor static source globally if present
            $vendorPath = $this->basePath . '/storage/assets/vendor';
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
            $registry->addStyle('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css');
            $registry->addFont('/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2?e34853135f9e39acf64315236852cd5a');

            // Register Bootstrap JS bundle globally for interactive elements
            $registry->addGlobalScript('/assets/vendor/bootstrap/bootstrap.bundle.min.js', false, true);

            // Add phue-explorer.js as a global script so it survives beginRequest() reset
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

        $this->container->singleton(AssetServer::class, fn(): AssetServer => new AssetServer(
            $this->container->get(AssetRegistry::class),
            (bool) $this->config->get('app.debug', false)
        ));

        $this->container->singleton(HtmlShellRenderer::class, function (): HtmlShellRenderer {
            return new HtmlShellRenderer($this->container->get(AssetRegistry::class));
        });

    }

    /**
     * Automatically bootstrap Active Record and Database Connection using config values.
     *
     * Registers Connection as a process-level singleton instance for shared database operations.
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
            $this->container->singleton(Connection::class, fn(): Connection => new Connection($dbConfig));
            $this->container->alias('db', Connection::class);

            BaseActiveRecord::setConnectionResolver(fn(): Connection => $this->container->get(Connection::class));
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
     * Retrieve the current authenticated user accessor instance, if available.
     *
     * @return CurrentUserInterface|null User accessor instance or null if not bound.
     */
    public function user(): ?CurrentUserInterface
    {
        if ($this->container->has(CurrentUserInterface::class)) {
            return $this->container->get(CurrentUserInterface::class);
        }

        return null;
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

        // 1. Kernel Lifecycle Boundary: Resolve or adopt scope
        $existingScope = $this->container->getScope();
        $isLocallyCreatedScope = false;

        if ($existingScope === null) {
            $scope = $this->container->createScope();
            $this->container->setScope($scope);
            $isLocallyCreatedScope = true;
        } else {
            $scope = $existingScope;
        }

        $this->container->scoped(\Phue\Core\Request::class, fn(): Request => $request);

        // Build the global middleware pipeline
        $pipeline = array_reduce(
            array_reverse($this->middlewareStack),
            fn(callable $next, callable|string $middleware) => function (Request $req) use ($next, $middleware): Response {
                if (is_string($middleware)) {
                    /** @var callable $middleware */
                    $middleware = $this->container->get($middleware);
                }
                return $middleware($req, $next);
            },
            fn(Request $req): Response => $this->dispatchToRouter($req)
        );

        /** @var LoggerInterface $logger */
        $logger = $this->container->get(LoggerInterface::class);

        try {
            // Reset per-request component loader caches inside the try block (Defensive lifecycle)
            if ($this->container->has(ComponentLoaderRegistry::class)) {
                $this->container->get(ComponentLoaderRegistry::class)->beginRequest();
            }

            // Reset per-request script tracking so SPA responses only include current page scripts
            $this->container->get(AssetRegistry::class)->beginRequest();

            $response = $pipeline($request);
        } catch (\Phue\Core\Http\Exceptions\HttpException $e) {
            $statusCode = $e->getStatusCode();
            $logger->warning('HTTP ' . $statusCode . ' Client Error: ' . $e->getMessage(), ['exception' => $e]);
            $acceptsJson = str_contains($request->getHeader('Accept', ''), 'application/json')
                || $request->isXmlHttpRequest();
            $response = $acceptsJson
                ? Response::json(['error' => $e->getMessage()], $statusCode)
                : new Response($e->getMessage(), $statusCode);
        } catch (\InvalidArgumentException $e) {
            $logger->warning('HTTP 400 Bad Request: ' . $e->getMessage(), ['exception' => $e]);
            $msg = $this->debug ? $e->getMessage() : 'Bad Request';
            $acceptsJson = str_contains($request->getHeader('Accept', ''), 'application/json')
                || $request->isXmlHttpRequest();
            $response = $acceptsJson ? Response::json(['error' => $msg], 400) : new Response($msg, 400);
        } catch (RouteNotFoundException $e) {
            $response = new Response('Not Found', 404);
        } catch (MethodNotAllowedException $e) {
            $logger->warning('HTTP 405 Method Not Allowed: ' . $request->getMethod() . ' ' . $request->getUri(), ['exception' => $e]);
            $response = (new Response('Method Not Allowed', 405))->setHeader('Allow', implode(', ', $e->getAllowedMethods()));
        } catch (Throwable $e) {
            $response = $this->renderExceptionResponse($e, $request);
        } finally {
            // Rollback for open transactions
            if ($this->container->has(Connection::class)) {
                try {
                    /** @var Connection $conn */
                    $conn = $this->container->get(Connection::class);
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                } catch (Throwable) {
                    // Ignore connection errors during cleanup
                }
            }

            // Flush request-scoped instances for the active scope
            $this->container->flushScope($scope);

            // Clear the fallback bucket (__none__) if state was created outside an explicit scope context
            $this->container->flushScope(null);

            // Detach scope context ONLY if it was created locally by handle()
            if ($isLocallyCreatedScope) {
                $this->container->setScope(null);
            }
        }

        /** @var SecurityMiddleware $security */
        $security = $this->container->get(SecurityMiddleware::class);

        return $security->applyHeaders($response, $request);
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

            if (is_array($resolvedHandler)) {
                $reflection = new \ReflectionMethod($resolvedHandler[0], $resolvedHandler[1]);
            } elseif (is_object($resolvedHandler) && !($resolvedHandler instanceof \Closure)) {
                // Invokable object: Reflect over the __invoke method
                $reflection = new \ReflectionMethod($resolvedHandler, '__invoke');
            } else {
                // Closures and global/namespaced functions
                $reflection = new \ReflectionFunction($resolvedHandler);
            }

            $args = [];
            foreach ($reflection->getParameters() as $param) {
                $paramName = $param->getName();
                $type = $param->getType();
                $typeNames = $this->extractTypeNames($type);

                // 1. Check for Request type (Request class or subclass/interface)
                if (in_array(Request::class, $typeNames, true) || ($type instanceof \ReflectionNamedType && $type->getName() === Request::class)) {
                    $args[] = $req;
                }                 // 2. Direct route path parameter match by name (e.g.$id)
                elseif (array_key_exists($paramName, $coercedParams)) {
                    $args[] = $coercedParams[$paramName];
                }
                // 3. Any array-typed parameter receives the full route params map
                elseif (in_array('array', $typeNames, true)) {
                    $args[] = $coercedParams;
                }
                // 4. Fallback to default parameter value if available
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
            fn(callable $next, callable|string $middleware) => function (Request $req) use ($next, $middleware): Response {
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
     * @param \ReflectionType|null $type Reflection type instance to extract names from.
     * @return array<int, string> List of type names.
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
     * @param mixed $handler Target callable or controller action handler.
     * @param array<string, string> $rawParams Raw URI parameter key-value map.
     * @return array<string, mixed> Coerced parameters map.
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
     * Instantiate and register application module extensions with a two-pass load strategy.
     *
     * Pass 1: Validates existence, instantiates via DI, injects core infrastructure,
     * initializes, and registers each module into the container.
     * Pass 2: Iterates through all loaded modules to ensure all declared module
     * dependencies (`requires`) are fully satisfied.
     *
     * @param array<int, string> $modules List of FQCNs representing application modules.
     *
     * @return void
     * @throws \RuntimeException If a module class does not exist, fails type constraints, or lacks unmet requirements.
     */
    protected function loadModules(array $modules): void
    {
        /** @var array<string, object> $loaded Keyed by module id */
        $loaded = [];

        // Pass 1: Mount all modules first to avoid dependency ordering bugs
        foreach ($modules as $moduleClass) {
            if (!class_exists($moduleClass)) {
                throw new \RuntimeException(
                    "Module class {$moduleClass} not found. Check config/app.php."
                );
            }

            /** @var object $module */
            $module = $this->container->get($moduleClass);

            if (!($module instanceof Module)) {
                throw new \RuntimeException(
                    "{$moduleClass} must extend Phue\Core\Module."
                );
            }

            // Bind core infrastructure to the module
            $module->basePath = $this->basePath;
            $module->setDependencies($this->router, $this->container, $this->events);
            $module->init();

            // Register under "module.{id}" if a valid ID is present
            if ($module->id !== '') {
                $this->container->singleton("module.{$module->id}", fn() => $module);
                $loaded[$module->id] = $module;
            }

            $this->modules[] = $module;
        }

        // Pass 2: Verify that all module 'requires' declarations are satisfied
        foreach ($this->modules as $module) {
            foreach ($module->requires as $depId) {
                if (!isset($loaded[$depId])) {
                    throw new \RuntimeException(
                        "Module '{$module->id}' requires '{$depId}', which is not loaded. Add it to config/app.modules."
                    );
                }
            }
        }
    }

    /**
     * Renders and formats unhandled exceptions into a 500 Internal Server Error response.
     *
     * Logs the exception details using the configured logger. Detects if the client
     * expects a JSON response via the 'Accept' or 'X-Requested-With' headers.
     * If JSON is requested, it returns a detailed JSON payload (in debug mode) or a
     * generic error message. Otherwise, it renders a custom HTML error page.
     *
     * @param Throwable $e       The unhandled exception or error instance.
     * @param Request   $request The current HTTP request instance.
     *
     * @return Response A JSON or HTML HTTP response with a 500 status code.
     */
    private function renderExceptionResponse(Throwable $e, Request $request): Response
    {
        /** @var LoggerInterface $logger */
        $logger = $this->container->get(LoggerInterface::class);
        $logger->error('HTTP 500 Unhandled Exception: ' . $e->getMessage(), ['exception' => $e]);

        $acceptsJson = str_contains($request->getHeader('Accept', ''), 'application/json')
            || $request->getHeader('X-Requested-With', '') === 'XMLHttpRequest';

        if ($acceptsJson) {
            if ($this->debug) {
                return Response::json([
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ], 500);
            }
            return Response::json(['error' => 'Internal Server Error'], 500);
        }

        // HTML Error Page Rendering
        /** @var \Phue\Core\ErrorPages\ErrorPageRenderer $renderer */
        $renderer = $this->container->get(\Phue\Core\ErrorPages\ErrorPageRenderer::class);
        $html = $renderer->render($e, $this->debug, 500);

        return new Response($html, 500);
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
