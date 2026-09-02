<?php

namespace Phue\Core\Routing\Loader;

use Phue\Core\Routing\Attribute\Middleware;
use Phue\Core\Routing\Attribute\Route;
use ReflectionClass;
use ReflectionMethod;

/**
 * Parses controller classes using PHP Reflection to extract routes and assigned middleware.
 *
 * @package Phue\Core\Routing\Loader
 */
class AttributeRouteLoader
{
    /**
     * Loads and resolves all route and middleware attributes defined in a given controller class.
     *
     * @param class-string $controllerClass The fully qualified class name of the controller.
     * @return array<int, array{
     *     path: string,
     *     methods: array<string>,
     *     handler: array{class-string, string},
     *     name: string|null,
     *     where: array<string, string>,
     *     middleware: array<string>
     * }>
     *
     * @throws \ReflectionException If the controller class does not exist.
     */
    public function load(string $controllerClass): array
    {
        $routes = [];
        $reflectionClass = new ReflectionClass($controllerClass);

        // 1. Resolve Class-Level Attributes
        $classAttributes = $reflectionClass->getAttributes(Route::class);
        $classPrefix = '';
        $classNamePrefix = '';

        if (!empty($classAttributes)) {
            /** @var Route $classRoute */
            $classRoute = $classAttributes[0]->newInstance();
            $classPrefix = $classRoute->path;
            $classNamePrefix = $classRoute->name ?? '';
        }

        // Extract class-level middleware attributes
        $classMiddlewares = $this->extractMiddlewares($reflectionClass->getAttributes(Middleware::class));

        // 2. Resolve Method-Level Attributes
        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodAttributes = $method->getAttributes(Route::class);

            if (empty($methodAttributes)) {
                continue;
            }

            // Extract method-level middleware attributes
            $methodMiddlewares = $this->extractMiddlewares($method->getAttributes(Middleware::class));
            
            // Class-level middleware runs BEFORE method-level middleware
            $mergedMiddleware = array_merge($classMiddlewares, $methodMiddlewares);

            foreach ($methodAttributes as $attribute) {
                /** @var Route $routeAttr */
                $routeAttr = $attribute->newInstance();

                $finalPath = $this->combinePaths($classPrefix, $routeAttr->path);
                $finalName = $classNamePrefix . ($routeAttr->name ?? '');

                $routes[] = [
                    'path'       => $finalPath,
                    'methods'    => $routeAttr->methods,
                    'handler'    => [$controllerClass, $method->getName()],
                    'name'       => $finalName !== '' ? $finalName : null,
                    'where'      => $routeAttr->where,
                    'middleware' => $mergedMiddleware,
                ];
            }
        }

        return $routes;
    }

    /**
     * Extracts and flattens middleware class names from ReflectionAttributes.
     *
     * @param array<\ReflectionAttribute> $attributes
     * @return array<string>
     */
    private function extractMiddlewares(array $attributes): array
    {
        $middlewares = [];

        foreach ($attributes as $attribute) {
            /** @var Middleware $instance */
            $instance = $attribute->newInstance();
            foreach ($instance->middlewares as $mw) {
                $middlewares[] = $mw;
            }
        }

        return $middlewares;
    }

    /**
     * Concatenates class-level path prefix with method-level path safely.
     *
     * @param string $prefix Base URI prefix from class attribute.
     * @param string $path   Specific URI path from method attribute.
     * @return string Formatted full URI path.
     */
    private function combinePaths(string $prefix, string $path): string
    {
        $combined = rtrim($prefix, '/') . '/' . ltrim($path, '/');
        return $combined === '/' ? '/' : rtrim($combined, '/');
    }
}