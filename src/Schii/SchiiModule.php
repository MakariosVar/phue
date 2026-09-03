<?php

namespace Phue\Schii;

use Phue\Core\Container;
use Phue\Core\Routing\Router;
use Phue\Core\EventDispatcher;
use Phue\Schii\Config\SchiiConfig;
use Phue\Schii\Endpoint\ModelEndpoint;
use Phue\Schii\Schema\SchemaRegistry;
use Phue\Http\Middleware\CorsMiddleware;

/**
 * Class SchiiModule
 *
 * Bootstraps and registers the Schii REST engine within the Phue framework application lifecycle.
 * Handles dependency injection bindings and dynamic zero-controller route registrations.
 *
 * @package Phue\Schii
 */
class SchiiModule
{
    /**
     * Register singletons, bindings, and dynamic API endpoints for Schii.
     *
     * @param Router          $router          The application's router instance.
     * @param Container       $container       The application's Dependency Injection container.
     * @param EventDispatcher $eventDispatcher The application's event dispatcher instance.
     *
     * @return void
     */
    public static function register(Router $router, Container $container, EventDispatcher $eventDispatcher): void
    {
        // 1. Instantiate configuration using the factory method
        $configArray = $container->has('config') ? $container->get('config')->get('schii', []) : [];
        $schiiConfig = SchiiConfig::fromArray($configArray);
        
        $container->singleton(SchiiConfig::class, fn(): SchiiConfig => $schiiConfig);
        
        // 2. Register SchemaRegistry as a singleton
        $container->singleton(SchemaRegistry::class, function (Container $c) use ($schiiConfig): SchemaRegistry {
            $cache = $c->has('cache') ? $c->get('cache') : null;

            return new SchemaRegistry(
                cache: $cache,
                cachePrefix: $schiiConfig->cachePrefix
            );
        });

        // 3. Bind ModelEndpoint to container
        $container->bind(ModelEndpoint::class, function (Container $c): ModelEndpoint {
            $entityMapping = [
                'products' => \App\Models\Product::class,
                'product'  => \App\Models\Product::class, // Support both singular and plural slugs
            ];

            return new ModelEndpoint(
                $c->get(SchemaRegistry::class),
                $entityMapping
            );
        });

        // 4. Register Dynamic Zero-Controller Routes
        $prefix = $schiiConfig->apiPrefix;
        $middleware = !empty($schiiConfig->middleware) ? $schiiConfig->middleware : [CorsMiddleware::class];

        /**
         * @route GET /api/schii/schema/{entity} - Returns the JSON Schema of a target entity
         */
        $router->addRoute('GET', "{$prefix}/schema/{entity}", [ModelEndpoint::class, 'schema'], $middleware);

        /**
         * @route GET /api/schii/{entity} - List entity records (Index)
         */
        $router->addRoute('GET', "{$prefix}/{entity}", [ModelEndpoint::class, 'index'], $middleware);

        /**
         * @route POST /api/schii/{entity} - Create a new entity record (Store)
         */
        $router->addRoute('POST', "{$prefix}/{entity}", [ModelEndpoint::class, 'store'], $middleware);

        /**
         * @route GET /api/schii/{entity}/{id} - Retrieve a specific record (Show)
         */
        $router->addRoute('GET', "{$prefix}/{entity}/{id}", [ModelEndpoint::class, 'show'], $middleware);

        /**
         * @route PUT /api/schii/{entity}/{id} - Update an existing record (Update)
         */
        $router->addRoute('PUT', "{$prefix}/{entity}/{id}", [ModelEndpoint::class, 'update'], $middleware);

        /**
         * @route DELETE /api/schii/{entity}/{id} - Remove a record (Delete)
         */
        $router->addRoute('DELETE', "{$prefix}/{entity}/{id}", [ModelEndpoint::class, 'delete'], $middleware);
    }
}