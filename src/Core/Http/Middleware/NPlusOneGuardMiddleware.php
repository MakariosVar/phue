<?php

declare(strict_types=1);

namespace Phue\Core\Http\Middleware;

use Phue\Core\Database\NPlusOneDetector;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Routing\Router;

/**
 * Middleware that enables N+1 query detection for requests
 * where the controller action has the #[NPlusOneGuard] attribute.
 *
 * @package Phue\Core\Http\Middleware
 */
final class NPlusOneGuardMiddleware
{
    public function __construct(
        private NPlusOneDetector $detector,
        private Router $router
    ) {}

    /**
     * Handles the request, enabling N+1 detection if the route has the attribute.
     *
     * @param Request $request
     * @param callable $next
     * @return Response
     */
    public function __invoke(Request $request, callable $next): Response
    {
        // Check if the matched route has the NPlusOneGuard attribute
        $guard = $this->getGuardFromRoute($request);

        if ($guard === null) {
            return $next($request);
        }

        // Configure detector from attribute
        $this->detector->reset();

        try {
            $response = $next($request);
        } finally {
            // Check for N+1 patterns after request completes
            $this->detector->check();
        }

        return $response;
    }

    /**
     * Extracts NPlusOneGuard attribute from the matched route handler.
     */
    private function getGuardFromRoute(Request $request): ?\Phue\Core\Database\Attributes\NPlusOneGuard
    {
        try {
            $routeMatch = $this->router->dispatch($request->getMethod(), $request->getUri());
        } catch (\Phue\Core\Routing\Exceptions\RouteNotFoundException|\Phue\Core\Routing\Exceptions\MethodNotAllowedException) {
            return null;
        }

        $handler = $routeMatch->getHandler();

        // Handle different handler formats
        $method = null;
        $class = null;

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
        } elseif (is_array($handler) && is_string($handler[0])) {
            $class = $handler[0];
            $method = $handler[1];
        } elseif (is_object($handler)) {
            // Closure or invokable object
            if ($handler instanceof \Closure) {
                // Closures don't have attributes in a reliable way, skip
                return null;
            }
            $class = get_class($handler);
            $method = '__invoke';
        }

        if ($class === null || $method === null) {
            return null;
        }

        if (!method_exists($class, $method)) {
            return null;
        }

        $reflection = new \ReflectionMethod($class, $method);
        $attributes = $reflection->getAttributes(\Phue\Core\Database\Attributes\NPlusOneGuard::class);

        if (empty($attributes)) {
            return null;
        }

        return $attributes[0]->newInstance();
    }
}