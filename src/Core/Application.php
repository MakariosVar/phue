<?php

namespace Phue\Core;

use Phue\Core\Routing\Exceptions\MethodNotAllowedException;
use Phue\Core\Routing\Exceptions\RouteNotFoundException;
use Phue\Core\Routing\Router;
use Throwable;

/**
 * Main application entry point and dependency coordinator.
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
     * Handle incoming HTTP Request through middleware pipeline and router.
     *
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
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
        }
    }

    /**
     * Dispatch request to router and resolve handler execution.
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

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $handler = [$this->container->get($class), $method];
        } elseif (is_array($handler) && is_string($handler[0])) {
            $handler[0] = $this->container->get($handler[0]);
        }

        $result = call_user_func($handler, $request, $params);

        return $this->normalizeResponse($result);
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