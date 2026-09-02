<?php

namespace Phue\Core\Routing;

use Phue\Core\Routing\Exceptions\MethodNotAllowedException;
use Phue\Core\Routing\Exceptions\RouteNotFoundException;

/**
 * Handles route registration and matching incoming HTTP requests against patterns.
 */
class Router
{
    /**
     * Registered routes array.
     *
     * @var array<int, array{method: string, path: string, regex: string, handler: mixed}>
     */
    protected array $routes = [];

    /**
     * Register a new route.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $path Path pattern (e.g., '/users/{id}')
     * @param mixed $handler Controller callable or [Controller::class, 'method']
     */
    public function addRoute(string $method, string $path, mixed $handler): self
    {
        $normalizedPath = $this->normalizePath($path);

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $normalizedPath,
            'regex' => $this->compileRouteRegex($normalizedPath),
            'handler' => $handler,
        ];

        return $this;
    }

    /**
     * Helper shorthand to register a GET route.
     */
    public function get(string $path, mixed $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Helper shorthand to register a POST route.
     */
    public function post(string $path, mixed $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Helper shorthand to register a PUT route.
     */
    public function put(string $path, mixed $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Helper shorthand to register a DELETE route.
     */
    public function delete(string $path, mixed $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Dispatch the request matching algorithm against registered routes.
     *
     * @param string $method Incoming HTTP Method
     * @param string $uri Incoming Request URI
     * @return RouteMatch
     *
     * @throws RouteNotFoundException
     * @throws MethodNotAllowedException
     */
    public function dispatch(string $method, string $uri): RouteMatch
    {
        $path = $this->normalizePath(parse_url($uri, PHP_URL_PATH) ?? '/');
        $method = strtoupper($method);

        $allowedMethods = [];
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $pathMatched = true;
            $allowedMethods[] = $route['method'];

            if ($route['method'] === $method) {
                // Filter out integer keys from preg_match results to keep named parameter bindings
                $params = array_filter(
                    $matches,
                    fn ($key) => is_string($key),
                    ARRAY_FILTER_USE_KEY
                );

                return new RouteMatch($route['handler'], $params);
            }
        }

        if ($pathMatched) {
            throw new MethodNotAllowedException(array_unique($allowedMethods));
        }

        throw new RouteNotFoundException("No route found for URI [{$path}]");
    }

    /**
     * Normalize leading and trailing slashes for path consistency.
     */
    protected function normalizePath(string $path): string
    {
        return '/' . trim($path, '/');
    }

    /**
     * Convert standard route pattern into named-capture regular expression.
     * Example: '/users/{id}' -> '#^/users/(?P<id>[^/]+)$#s'
     */
    protected function compileRouteRegex(string $path): string
    {
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $path);

        return '#^' . $pattern . '$#s';
    }
}