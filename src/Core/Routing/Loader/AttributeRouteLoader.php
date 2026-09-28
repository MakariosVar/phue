<?php

declare(strict_types=1);

namespace Phue\Core\Routing\Loader;

use Closure;
use InvalidArgumentException;
use Phue\Core\Routing\Attribute\Middleware;
use Phue\Core\Routing\Attribute\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use RegexIterator;
use Throwable;

/**
 * Parses controller classes using PHP Reflection to extract routes and assigned middleware.
 *
 * @package Phue\Core\Routing\Loader
 */
class AttributeRouteLoader
{
    /**
     * Loads a compiled route cache, but only if it is provably usable.
     *
     * A cache is a speed-up, never a source of truth. This method therefore
     * returns null — meaning "scan normally" — in every case where trusting the
     * file would change the application's behaviour:
     *
     *  - the file is missing, unreadable, or is not a route array;
     *  - requiring it throws (a truncated file from a crash mid-write produces a
     *    ParseError, which is a Throwable, not a fatal);
     *  - its fingerprint no longer matches the current sources, meaning a
     *    controller or module changed since it was written;
     *  - it is missing any of the routes the caller declared required.
     *
     * The required-paths list is supplied by the caller rather than hardcoded
     * here. This class is the generic reflection loader used for any controller;
     * it has no business knowing which routes the framework happens to need. The
     * caller already knows, because it is the code that registered them.
     *
     * @param string   $cacheFile        Absolute path to the cached routes file.
     * @param string   $fingerprint      Expected source fingerprint; pass '' to skip the check.
     * @param string[] $requiredPaths    Route paths that must be present, e.g. ['/health/live'].
     *
     * @return array<int, array<string, mixed>>|null Compiled routes, or null to fall back to scanning.
     */
    public function loadCached(
        string $cacheFile,
        string $fingerprint = '',
        array $requiredPaths = []
    ): ?array {
        if (!is_file($cacheFile) || !is_readable($cacheFile)) {
            return null;
        }

        try {
            /** @var mixed $data */
            $data = require $cacheFile;
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data) || !isset($data['routes']) || !is_array($data['routes'])) {
            return null;
        }

        $routes = $data['routes'];

        if ($routes === []) {
            return null;
        }

        if ($fingerprint !== '' && ($data['fingerprint'] ?? '') !== $fingerprint) {
            return null;
        }

        if ($requiredPaths !== []) {
            $present = [];

            foreach ($routes as $route) {
                if (isset($route['path']) && is_string($route['path'])) {
                    $present[$route['path']] = true;
                }
            }

            foreach ($requiredPaths as $required) {
                if (!isset($present[$required])) {
                    return null;
                }
            }
        }

        return $routes;
    }

    /**
     * Computes an identity for the inputs the route table is derived from.
     *
     * Without this the cache is permanent: a route is added or renamed, the cache
     * is not invalidated, and the change simply never appears. There is no error
     * and no log line — the application keeps serving the old table.
     *
     * The fingerprint covers the application controllers, the module list, and
     * the module source files, plus a format version so a change to how routes
     * are compiled cannot be loaded by an older cache.
     *
     * It is deliberately cheap. It stats and hashes paths; it does not reflect
     * over classes or compile regexes, which is the work the cache exists to
     * avoid.
     *
     * @param string   $basePath Application root.
     * @param string[] $modules  Configured module class names.
     */
    public function fingerprint(string $basePath, array $modules = []): string
    {
        $parts = ['format' => '2'];

        $controllersDir = rtrim($basePath, '/\\') . '/App/Controllers';
        $parts['controllers'] = $this->fingerprintDirectory($controllersDir);

        sort($modules);
        $parts['modules'] = $modules;

        $moduleFiles = [];

        foreach ($modules as $module) {
            if (!is_string($module) || !class_exists($module)) {
                continue;
            }

            $file = (new ReflectionClass($module))->getFileName();

            if ($file !== false) {
                $moduleFiles[$module] = @filemtime($file) ?: 0;
            }
        }

        ksort($moduleFiles);
        $parts['moduleFiles'] = $moduleFiles;

        return hash('sha256', json_encode($parts) ?: '');
    }

    /**
     * @return array<string, int> Relative path => modification time, sorted by path.
     */
    private function fingerprintDirectory(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $entries = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $relative = substr($path, strlen($dir) + 1);

            $entries[str_replace('\\', '/', $relative)] = @filemtime($path) ?: 0;
        }

        ksort($entries);

        return $entries;
    }

    /**
     * Exports and saves compiled route definitions to a PHP array cache file atomically.
     *
     * @param string                   $cacheFile   Absolute path to output cache file.
     * @param array<int, array<mixed>> $routes      Compiled route array structure.
     * @param string                   $fingerprint Source fingerprint, so a stale cache is detectable.
     *
     * @return void
     * @throws InvalidArgumentException If a route handler contains an unserializable Closure.
     * @throws \RuntimeException       If the file cannot be written or renamed into place.
     */
    public function saveCache(string $cacheFile, array $routes, string $fingerprint = ''): void
    {
        // Verify that no Closures are present that would break serialization / var_export
        foreach ($routes as $index => $route) {
            $handler = $route['handler'] ?? null;

            if ($handler instanceof Closure || (is_array($handler) && isset($handler[0]) && $handler[0] instanceof Closure)) {
                $path = $route['path'] ?? "#{$index}";
                throw new InvalidArgumentException(
                    "Cannot cache route '{$path}': Closures cannot be exported to route cache. Use a [Controller::class, 'method'] handler instead."
                );
            }
        }

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $export = var_export([
            'scannedAt'  => time(),
            'fingerprint' => $fingerprint,
            'routes'     => $routes,
        ], true);

        $content = "<?php\n\ndeclare(strict_types=1);\n\n// Auto-generated route cache file. Do not edit directly.\nreturn {$export};\n";

        // Atomic File Write: Write a temporary file in the same directory & execute rename()
        $tempFile = $cacheFile . '.tmp.' . uniqid((string) getmypid(), true);

        if (file_put_contents($tempFile, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write temporary route cache file: {$tempFile}");
        }

        if (!rename($tempFile, $cacheFile)) {
            @unlink($tempFile);
            throw new \RuntimeException("Failed to atomically replace route cache file: {$cacheFile}");
        }
    }

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

        // Skip non-instantiable classes (abstract classes, interfaces, traits)
        if (!$reflectionClass->isInstantiable()) {
            return [];
        }

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
     * Recursively scans a directory for PHP classes and extracts all defined routes.
     *
     * @param string $directory       Absolute path to controller directory.
     * @param string $namespacePrefix Root namespace corresponding to the scanned directory.
     * @return array<int, array{
     *     path: string,
     *     methods: array<string>,
     *     handler: array{class-string, string},
     *     name: string|null,
     *     where: array<string, string>,
     *     middleware: array<string>
     * }>
     */
    public function scanDirectory(string $directory, string $namespacePrefix): array
    {
        $routes = [];

        if (!is_dir($directory)) {
            return $routes;
        }

        $directoryIterator = new RecursiveDirectoryIterator($directory);
        $iterator = new RecursiveIteratorIterator($directoryIterator);
        $phpFiles = new RegexIterator($iterator, '/^.+\.php$/i', RegexIterator::GET_MATCH);

        $realDirectory = realpath($directory);
        $namespacePrefix = rtrim($namespacePrefix, '\\') . '\\';

        foreach ($phpFiles as $file) {
            $filePath = realpath($file[0]);

            // Extract path relative to scanned directory
            $relativePath = substr($filePath, strlen($realDirectory));
            $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);
            
            // Convert directory separators to namespace separators
            $classWithExtension = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
            $className = $namespacePrefix . substr($classWithExtension, 0, -4);

            if (!class_exists($className)) {
                continue;
            }

            try {
                $classRoutes = $this->load($className);
                $routes = array_merge($routes, $classRoutes);
            } catch (\ReflectionException) {
                continue;
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