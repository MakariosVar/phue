<?php

declare(strict_types=1);

namespace Phue\Modules\Rest;

use Phue\Core\Application;
use Phue\Core\Config;
use Phue\Core\Container;
use Phue\Core\Module;
use Phue\Core\Routing\Router;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Generator\RuntimeGenerator;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\RbacManager;
use Phue\Modules\Rest\Attribute\Rest;
use Phue\Modules\Rest\Config\RestConfig;
use Phue\Modules\Rest\Endpoint\RestModelEndpoint;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Class RestModule
 *
 * Bootstraps public/selective REST endpoints using an attribute-driven approach (`#[Rest]`),
 * registering standard CRUD routes, schema endpoints, and custom domain actions (collection/member)
 * with slug-collision safeguards.
 *
 * @package Phue\Modules\Rest
 */
class RestModule extends Module
{
    /**
     * The ID of the module.
     *
     * @var string
     */
    public string $id = 'rest';

    /**
     * The modules required by this module.
     *
     * @var array<int, string>
     */
    public array $requires = ['auth'];

    /**
     * Initializes configuration bindings, discovers annotated models, and registers REST & custom action routes.
     *
     * @return void
     */
    public function init(): void
    {
        // 1. Config Binding
        $this->container->singleton(
            RestConfig::class,
            function (): RestConfig {
                /** @var array<string, mixed> $rawConfig */
                $rawConfig = [];

                if ($this->container->has(Config::class)) {
                    /** @var Config $configInstance */
                    $configInstance = $this->container->get(Config::class);
                    $rawConfig = $configInstance->get('rest', []);
                } elseif ($this->container->has('config')) {
                    /** @var Config|array<string, mixed> $configBinding */
                    $configBinding = $this->container->get('config');
                    $rawConfig = is_array($configBinding)
                        ? ($configBinding['rest'] ?? [])
                        : $configBinding->get('rest', []);
                }

                return RestConfig::fromArray($rawConfig);
            }
        );

        /** @var RestConfig $restConfig */
        $restConfig = $this->container->get(RestConfig::class);

        if (!$restConfig->enabled) {
            return;
        }

        // 2. Discover models and register routes via RestModelEndpoint wrappers
        /** @var SchemaRegistry $schemaRegistry */
        $schemaRegistry = $this->container->get(SchemaRegistry::class);
        
        $modelClasses = $schemaRegistry->discoverModelClasses($restConfig->entityNamespace);
        
        /** @var string $apiPrefix */
        $apiPrefix = '/' . trim($restConfig->prefix, '/');
        /** @var array<int, class-string> $middleware */
        $middleware = $restConfig->middleware;

        foreach ($modelClasses as $modelClass) {
            $reflection = new ReflectionClass($modelClass);
            $attributes = $reflection->getAttributes(Rest::class);

            if (empty($attributes)) {
                continue;
            }

            /** @var Rest $restAttr */
            $restAttr = $attributes[0]->newInstance();

            $shortName = $reflection->getShortName();
            $slug = $restAttr->slug ?? strtolower($shortName);

            // Create isolated ModelEndpoint instance to avoid collision with SchiiModule bindings
            $modelEndpointInstance = new ModelEndpoint(
                $schemaRegistry,
                [],
                $restConfig->entityNamespace
            );

            // Bind specific wrapped endpoint instance for this entity route mapping
            $restModelEndpoint = new RestModelEndpoint(
                inner: $modelEndpointInstance,
                schemaRegistry: $schemaRegistry,
                authManager: $this->container->get(AuthManager::class),
                rbacManager: $this->container->get(RbacManager::class),
                entityClass: $modelClass,
                hidden: $restAttr->hidden,
                public: $restAttr->public ?? false,
                operations: $restAttr->operations
            );

            /** @var Router $router */
            $router = $this->container->get(Router::class);

            // Register schema and CRUD routes securely using the wrapped endpoint
            // Register per-entity schema and CRUD routes securely using the wrapped endpoint
            $router->addRoute('GET', "{$apiPrefix}/{$slug}/schema", [$restModelEndpoint, 'schema'], middleware: $middleware);
            
            if (in_array('index', $restAttr->operations, true)) {
                $router->addRoute('GET', "{$apiPrefix}/{$slug}", [$restModelEndpoint, 'index'], middleware: $middleware);
            }
            if (in_array('store', $restAttr->operations, true)) {
                $router->addRoute('POST', "{$apiPrefix}/{$slug}", [$restModelEndpoint, 'store'], middleware: $middleware);
            }
            if (in_array('show', $restAttr->operations, true)) {
                $router->addRoute('GET', "{$apiPrefix}/{$slug}/{id}", [$restModelEndpoint, 'show'], middleware: $middleware);
            }
            if (in_array('update', $restAttr->operations, true)) {
                $router->addRoute('PUT', "{$apiPrefix}/{$slug}/{id}", [$restModelEndpoint, 'update'], middleware: $middleware);
            }
            if (in_array('delete', $restAttr->operations, true)) {
                $router->addRoute('DELETE', "{$apiPrefix}/{$slug}/{id}", [$restModelEndpoint, 'delete'], middleware: $middleware);
            }

            // 3. Discover custom method-level actions with slug-collision guard
            /** @var array<int, string> $registeredActionPaths */
            $registeredActionPaths = [];

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $modelClass) {
                    continue;
                }

                $methodAttributes = $method->getAttributes(Rest::class);
                if (empty($methodAttributes)) {
                    continue;
                }

                /** @var Rest $actionAttr */
                $actionAttr = $methodAttributes[0]->newInstance();
                $methodName = $method->getName();
                $actionSlug = $actionAttr->slug ?? strtolower($methodName);

                $isMember = false;
                foreach ($method->getParameters() as $parameter) {
                    if ($parameter->getName() === 'id') {
                        $isMember = true;
                        break;
                    }
                }

                $actionPath = $isMember 
                    ? "{$apiPrefix}/{$slug}/{id}/{$actionSlug}" 
                    : "{$apiPrefix}/{$slug}/{$actionSlug}";

                $reservedPaths = [
                    "{$apiPrefix}/{$slug}",
                    "{$apiPrefix}/{$slug}/{id}",
                    "{$apiPrefix}/{$slug}/schema"
                ];

                if (in_array($actionPath, $reservedPaths, true) || in_array($actionPath, $registeredActionPaths, true)) {
                    continue;
                }

                $registeredActionPaths[] = $actionPath;
                $httpMethod = strtoupper($actionAttr->method);
                $actionPublic = $actionAttr->public ?? ($restAttr->public ?? false);

                // Register route via closure handler with explicit Phue\Core\Request typehint
                $router->addRoute(
                    $httpMethod,
                    $actionPath,
                    function (\Phue\Core\Application|\Phue\Core\Request $request, array $params = []) use ($restModelEndpoint, $methodName, $actionPublic) {
                        if ($request instanceof \Phue\Core\Application && func_num_args() >= 2) {
                            $req = func_get_arg(1);
                            $pms = func_get_arg(2) ?? [];
                        } else {
                            $req = $request;
                            $pms = $params;
                        }
                        return $restModelEndpoint->action($req, $pms, $methodName, $actionPublic);
                    },
                    middleware: $middleware
                );
            }
        }
    }

    /**
     * Returns programmatic routes registered by RestModule for CLI route caching.
     *
     * @return array<int, array{method: string, path: string, regex: string, handler: mixed, paramCount: int, middleware: array<string>}>
     */
    public function getRoutesForCaching(): array
    {
        return $this->router->getCompiledRoutes();
    }
}