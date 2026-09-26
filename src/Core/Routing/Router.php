<?php

namespace Phue\Core\Routing;

use Phue\Core\Routing\Exceptions\MethodNotAllowedException;
use Phue\Core\Routing\Exceptions\RouteNotFoundException;
use Phue\Core\Routing\Loader\AttributeRouteLoader;

/**
 * Handles route registration, attribute compilation, and matching HTTP requests against patterns.
 *
 * @package Phue\Core\Routing
 */
class Router
{
    /**
     * Preset regex mapping for inline parameter types.
     */
    protected const INLINE_TYPES = [
        'int'   => '\d+',
        'alpha' => '[a-zA-Z]+',
        'slug'  => '[a-zA-Z0-9_-]+',
        'uuid'  => '[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}',
    ];

    /**
     * Registered routes array.
     *
     * @var array<int, array{
     *     method: string,
     *     path: string,
     *     regex: string,
     *     handler: mixed,
     *     paramCount: int,
     *     middleware: array<string>
     * }>
     */
    protected array $routes = [];

    /**
     * Flag indicating whether routes have been sorted by priority.
     */
    protected bool $isSorted = false;

    /**
     * Direct injection of pre-compiled routes from route cache file.
     *
     * @param array<int, array{
     *     method: string,
     *     path: string,
     *     regex: string,
     *     handler: mixed,
     *     paramCount: int,
     *     middleware: array<string>
     * }> $routes
     * @return self
     */
    public function registerCachedRoutes(array $routes): self
    {
        $this->routes = $routes;
        $this->isSorted = true;

        return $this;
    }

    /**
     * Returns the internal list of compiled routes for caching.
     *
     * @return array<int, array{
     *     method: string,
     *     path: string,
     *     regex: string,
     *     handler: mixed,
     *     paramCount: int,
     *     middleware: array<string>
     * }>
     */
    public function getCompiledRoutes(): array
    {
        if (!$this->isSorted) {
            $this->sortRoutes();
        }

        return $this->routes;
    }

    /**
     * Register a new route manually.
     *
     * @param string                $method     HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string                $path       Path pattern (e.g., '/users/{id:int}')
     * @param mixed                 $handler    Controller callable or [Controller::class, 'method']
     * @param array<string, string> $where      Custom parameter regex constraints
     * @param array<string>         $middleware Optional route-specific middleware list
     * @return $this
     */
    public function addRoute(
        string $method,
        string $path,
        mixed $handler,
        array $where = [],
        array $middleware = []
    ): self {
        $normalizedPath = $this->normalizePath($path);
        
        // Extract inline types and compile final route regex
        [$cleanPath, $mergedWhere] = $this->parseInlineTypes($normalizedPath, $where);
        $regex = $this->compileRouteRegex($cleanPath, $mergedWhere);

        $this->routes[] = [
            'method'     => strtoupper($method),
            'path'       => $normalizedPath,
            'regex'      => $regex,
            'handler'    => $handler,
            'paramCount' => substr_count($cleanPath, '{'),
            'middleware' => $middleware,
        ];

        $this->isSorted = false;

        return $this;
    }

    /**
     * Registers all routes from a single controller using AttributeRouteLoader.
     *
     * @param class-string $controllerClass
     * @return $this
     */
    public function registerController(string $controllerClass): self
    {
        $loader = new AttributeRouteLoader();
        $routes = $loader->load($controllerClass);

        $this->registerLoadedRoutes($routes);

        return $this;
    }

    /**
     * Automatically scans a directory for controllers and registers all discovered routes.
     *
     * @param string $directory       Absolute path to controllers directory.
     * @param string $namespacePrefix Root namespace corresponding to the directory.
     * @return $this
     */
    public function scanControllers(string $directory, string $namespacePrefix): self
    {
        $loader = new AttributeRouteLoader();
        $routes = $loader->scanDirectory($directory, $namespacePrefix);

        $this->registerLoadedRoutes($routes);

        return $this;
    }

    /**
     * Helper shorthand to register a GET route.
     */
    public function get(string $path, mixed $handler, array $where = [], array $middleware = []): self
    {
        return $this->addRoute('GET', $path, $handler, $where, $middleware);
    }

    /**
     * Helper shorthand to register a POST route.
     */
    public function post(string $path, mixed $handler, array $where = [], array $middleware = []): self
    {
        return $this->addRoute('POST', $path, $handler, $where, $middleware);
    }

    /**
     * Helper shorthand to register a PUT route.
     */
    public function put(string $path, mixed $handler, array $where = [], array $middleware = []): self
    {
        return $this->addRoute('PUT', $path, $handler, $where, $middleware);
    }

    /**
     * Helper shorthand to register a DELETE route.
     */
    public function delete(string $path, mixed $handler, array $where = [], array $middleware = []): self
    {
        return $this->addRoute('DELETE', $path, $handler, $where, $middleware);
    }

    /**
     * Dispatch the request matching algorithm against registered routes.
     *
     * @param string $method Incoming HTTP Method
     * @param string $uri    Incoming Request URI
     * @return RouteMatch
     *
     * @throws RouteNotFoundException
     * @throws MethodNotAllowedException
     */
    public function dispatch(string $method, string $uri): RouteMatch
    {
        if (!$this->isSorted) {
            $this->sortRoutes();
        }

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
                // Filter out integer keys from preg_match results
                $params = array_filter(
                    $matches,
                    fn ($key) => is_string($key),
                    ARRAY_FILTER_USE_KEY
                );

                return new RouteMatch($route['handler'], $params, $route['middleware']);
            }
        }

        if ($pathMatched) {
            throw new MethodNotAllowedException(array_unique($allowedMethods));
        }

        throw new RouteNotFoundException("No route found for URI [{$path}]");
    }

    /**
     * Registers an array of normalized route arrays loaded from AttributeRouteLoader.
     *
     * @param array<int, array{
     *     path: string,
     *     methods: array<string>,
     *     handler: array{class-string, string},
     *     name: string|null,
     *     where: array<string, string>,
     *     middleware: array<string>
     * }> $routes
     * @return void
     */
    protected function registerLoadedRoutes(array $routes): void
    {
        foreach ($routes as $route) {
            foreach ($route['methods'] as $method) {
                $this->addRoute(
                    $method,
                    $route['path'],
                    $route['handler'],
                    $route['where'],
                    $route['middleware']
                );
            }
        }
    }

    /**
     * Normalize leading and trailing slashes for path consistency.
     */
    protected function normalizePath(string $path): string
    {
        return '/' . trim($path, '/');
    }

    /**
     * Parses inline param constraints (e.g. {id:int}) and merges them into the $where constraints array.
     *
     * @param string                $path  Raw path pattern.
     * @param array<string, string> $where Existing parameter constraints.
     * @return array{0: string, 1: array<string, string>}
     */
    protected function parseInlineTypes(string $path, array $where): array
    {
        $cleanPath = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*):([a-zA-Z0-9_]+)\}/',
            function ($matches) use (&$where) {
                $param = $matches[1];
                $type = $matches[2];

                if (isset(self::INLINE_TYPES[$type]) && !isset($where[$param])) {
                    $where[$param] = self::INLINE_TYPES[$type];
                }

                return '{' . $param . '}';
            },
            $path
        );

        return [$cleanPath, $where];
    }

    /**
     * Convert route pattern into named-capture regular expression with constraint support.
     *
     * @param string                $path  Clean path pattern with simple {param} placeholders.
     * @param array<string, string> $where Regex rules per parameter.
     * @return string Compiled PCRE Regex pattern.
     */
    protected function compileRouteRegex(string $path, array $where): string
    {
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function ($matches) use ($where) {
                $param = $matches[1];
                $constraint = $where[$param] ?? '[^/]+';

                // Escape unescaped '#' characters to prevent breaking the PCRE '#' delimiter
                $escapedConstraint = preg_replace('/(?<!\\\\)#/', '\#', $constraint);

                return '(?P<' . $param . '>' . $escapedConstraint . ')';
            },
            $path
        );

        return '#^' . $pattern . '$#s';
    }

    /**
     * Sorts routes so static routes are matched before dynamic routes.
     */
    protected function sortRoutes(): void
    {
        usort($this->routes, function ($a, $b) {
            if ($a['paramCount'] !== $b['paramCount']) {
                return $a['paramCount'] <=> $b['paramCount'];
            }

            $posA = strpos($a['path'], '{');
            $posB = strpos($b['path'], '{');

            $prefixLengthA = ($posA !== false) ? $posA : strlen($a['path']);
            $prefixLengthB = ($posB !== false) ? $posB : strlen($b['path']);

            return $prefixLengthB <=> $prefixLengthA;
        });

        $this->isSorted = true;
    }
}