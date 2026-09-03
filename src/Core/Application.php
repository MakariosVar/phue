<?php

declare(strict_types=1);

namespace Phue\Core;

use Phue\Core\Database\Connection;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Routing\Exceptions\MethodNotAllowedException;
use Phue\Core\Routing\Exceptions\RouteNotFoundException;
use Phue\Core\Routing\Router;
use Throwable;

/**
 * Main application entry point and dependency coordinator.
 *
 * @package Phue\Core
 */
class Application
{
    private string $basePath;
    private Config $config;
    private Container $container;
    private Router $router;
    private EventDispatcher $events;

    /**
     * @var array<int, callable|string>
     */
    private array $middlewareStack = [];

    /**
     * @var array<int, object>
     */
    private array $modules = [];

    private bool $debug = false;

    /**
     * Initialize application with root directory path, load configs, and bootstrap infrastructure.
     *
     * @param string $basePath Application root directory.
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
        $this->loadModules($this->config->get('app.modules', []));
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
    }

    /**
     * Automatically bootstrap Active Record and Database Connection using config values.
     *
     * @return void
     */
    private function bootstrapDatabase(): void
    {
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
     * @return string
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Retrieve the application configuration instance.
     *
     * @return Config
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Register a global HTTP middleware callable or class name to the stack.
     *
     * @param callable|string $middleware Callable implementation or class FQCN to be resolved via DI container.
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
     * @param Request $request Incoming HTTP request object.
     * @return Response Standardized HTTP response object.
     */
    public function handle(Request $request): Response
    {
        // Build the global middleware pipeline
        $pipeline = array_reduce(
            array_reverse($this->middlewareStack),
            fn (callable $next, callable|string $middleware) => function (Request $req) use ($next, $middleware): Response {
                if (is_string($middleware)) {
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
            $message = $this->debug ? $e->getMessage() : 'Internal Server Error';
            return new Response($message, 500);
        } finally {
            // Clean request-scoped services automatically at the end of the lifecycle
            $this->container->flushScope();
        }
    }

    /**
     * Dispatch request to router, wrap route-level middlewares, and resolve handler execution.
     *
     * @param Request $request Request instance to match against registered routes.
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
     * @return void
     */
    protected function loadModules(array $modules): void
    {
        foreach ($modules as $moduleClass) {
            if (class_exists($moduleClass)) {
                $module = $this->container->get($moduleClass);

                if (method_exists($module, 'register')) {
                    $module->register($this->router, $this->container, $this->events);
                }

                $this->modules[] = $module;
            }
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