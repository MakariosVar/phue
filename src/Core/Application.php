<?php

namespace Phue\Core;

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
     * Initialize core components and modules.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->container = new Container();
        $this->router = new Router();
        $this->events = new EventDispatcher();
        $this->debug = (bool) ($config['debug'] ?? false);

        $this->loadModules($config['modules'] ?? []);
    }

    /**
     * Register a global HTTP middleware.
     *
     * @param callable|string $middleware
     * @return self
     */
    public function use(callable|string $middleware): self
    {
        $this->middlewareStack[] = $middleware;

        return $this;
    }

    /**
     * Handle incoming HTTP Request through global middleware pipeline and router.
     *
     * @param Request $request
     * @return Response
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
     * @param Request $request
     * @return Response
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
     * @param mixed $result
     * @return Response
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
     * Load application modules.
     *
     * @param array<int, string> $modules
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

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function getEvents(): EventDispatcher
    {
        return $this->events;
    }
}