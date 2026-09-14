<?php

declare(strict_types=1);

namespace Phue\Schii\Generator;

use Phue\Core\Routing\Router;
use Phue\Schii\Endpoint\ModelEndpoint;
use Phue\Schii\Endpoint\AuthorizedModelEndpoint;
use Phue\Schii\Schema\SchemaRegistry;
use ReflectionClass;

/**
 * Runtime generator that registers REST routes dynamically in-memory via the Router.
 *
 * @package Phue\Schii\Generator
 */
final class RuntimeGenerator implements GeneratorInterface
{
    private SchemaRegistry $schemaRegistry;

    /**
     * RuntimeGenerator constructor.
     *
     * @param Router                                    $router         Router instance.
     * @param ModelEndpoint|AuthorizedModelEndpoint     $endpoint       Target model endpoint handler.
     * @param SchemaRegistry|null                       $schemaRegistry Optional schema registry for discovery.
     */
    public function __construct(
        private readonly Router $router,
        private readonly ModelEndpoint|AuthorizedModelEndpoint $endpoint,
        ?SchemaRegistry $schemaRegistry = null,
    ) {
        $this->schemaRegistry = $schemaRegistry ?? new SchemaRegistry();
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'runtime';
    }

    /**
     * Registers REST endpoints for a specific entity model.
     *
     * @param string                       $apiPrefix  API route prefix (e.g., '/api/schii').
     * @param string                       $entitySlug Entity slug identifier (e.g., 'user').
     * @param class-string                 $modelClass FQCN of the ActiveRecord model.
     * @param bool                         $requireAuth Whether to enforce authorization.
     * @param array<int, string|callable>  $middleware Additional route middleware.
     *
     * @return void
     */
    public function registerEntity(
        string $apiPrefix,
        string $entitySlug,
        string $modelClass,
        bool $requireAuth = true,
        array $middleware = [],
    ): void {
        $handler = $this->resolveEndpoint($requireAuth);

        $routes = $this->getRoutePatterns($apiPrefix, $entitySlug);

        foreach ($routes as $routeKey => $actionMethod) {
            [$method, $path] = explode(' ', $routeKey, 2);

            $this->router->addRoute(
                method: $method,
                path: $path,
                handler: [$handler, $actionMethod],
                middleware: $middleware,
            );
        }
    }

    /**
     * Registers REST endpoints for all entities in a given namespace.
     *
     * @param string                       $apiPrefix       API route prefix (e.g., '/api/schii').
     * @param string                       $entityNamespace Model namespace (e.g., 'App\\Models').
     * @param bool                         $requireAuth     Whether to enforce authorization.
     * @param array<int, string|callable>  $middleware      Additional route middleware.
     *
     * @return array<int, string> List of registered route patterns.
     */
    public function registerAll(
        string $apiPrefix,
        string $entityNamespace,
        bool $requireAuth = true,
        array $middleware = [],
    ): array {
        $registeredPatterns = [];
        $classes = $this->schemaRegistry->discoverModelClasses($entityNamespace);

        foreach ($classes as $className) {
            if (!class_exists($className)) {
                continue;
            }

            $refClass = new ReflectionClass($className);
            if ($refClass->isAbstract() || $refClass->isInterface()) {
                continue;
            }

            $shortName = $refClass->getShortName();
            $entitySlug = strtolower($shortName);

            $this->registerEntity($apiPrefix, $entitySlug, $className, $requireAuth, $middleware);

            foreach (array_keys($this->getRoutePatterns($apiPrefix, $entitySlug)) as $pattern) {
                $registeredPatterns[] = $pattern;
            }
        }

        return $registeredPatterns;
    }

    /**
     * Returns a map of HTTP route patterns to handler method names for a given entity.
     *
     * @param string $apiPrefix  API route prefix.
     * @param string $entitySlug Entity slug identifier.
     *
     * @return array<string, string> Map of "GET /api/schii/{entity}" -> handler method.
     */
    public function getRoutePatterns(string $apiPrefix, string $entitySlug): array
    {
        $basePath = rtrim($apiPrefix, '/') . '/' . trim($entitySlug, '/');

        return [
            sprintf('GET %s', $basePath) => 'index',
            sprintf('POST %s', $basePath) => 'store',
            sprintf('GET %s/{id}', $basePath) => 'show',
            sprintf('PUT %s/{id}', $basePath) => 'update',
            sprintf('DELETE %s/{id}', $basePath) => 'destroy',
        ];
    }

    /**
     * Resolves the appropriate endpoint instance based on authentication requirements.
     *
     * @param bool $requireAuth
     *
     * @return ModelEndpoint|AuthorizedModelEndpoint
     */
    private function resolveEndpoint(bool $requireAuth): ModelEndpoint|AuthorizedModelEndpoint
    {
        if ($requireAuth && $this->endpoint instanceof AuthorizedModelEndpoint) {
            return $this->endpoint;
        }

        if (!$requireAuth && $this->endpoint instanceof AuthorizedModelEndpoint) {
            return new ModelEndpoint($this->schemaRegistry);
        }

        return $this->endpoint;
    }
}