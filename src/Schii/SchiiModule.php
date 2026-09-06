<?php

declare(strict_types=1);

namespace Phue\Schii;

use Phue\Core\Config;
use Phue\Core\Container;
use Phue\Core\Routing\Router;
use Phue\Core\EventDispatcher;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\ComponentRenderer;
use Phue\Schii\Config\SchiiConfig;
use Phue\Schii\Endpoint\ModelEndpoint;
use Phue\Schii\Schema\SchemaRegistry;
use Phue\Schii\Controller\ConsoleController;
use Phue\Http\Middleware\CorsMiddleware;

/**
 * Class SchiiModule
 *
 * Bootstraps and registers the Schii REST engine within the Phue framework application lifecycle.
 *
 * @package Phue\Schii
 */
class SchiiModule
{
    /**
     * Register singletons, bindings, component renderers, and dynamic API endpoints for Schii.
     *
     * @param Router          $router          The application's router instance for route registration.
     * @param Container       $container       The application's Dependency Injection container instance.
     * @param EventDispatcher $eventDispatcher The application's event dispatcher instance.
     *
     * @return void
     */
    public static function register(
        Router $router,
        Container $container,
        EventDispatcher $eventDispatcher,
    ): void {
        // 1. Config Binding: Register SchiiConfig singleton factory in DI container
        $container->singleton(
            SchiiConfig::class,
            function () use ($container): SchiiConfig {
                /** @var array<string, mixed> $rawConfig */
                $rawConfig = [];

                if ($container->has(Config::class)) {
                    /** @var Config $configInstance */
                    $configInstance = $container->get(Config::class);
                    $rawConfig = $configInstance->get('schii', []);
                } elseif ($container->has('config')) {
                    /** @var Config|array<string, mixed> $configBinding */
                    $configBinding = $container->get('config');
                    $rawConfig = is_array($configBinding)
                        ? ($configBinding['schii'] ?? [])
                        : $configBinding->get('schii', []);
                }

                return SchiiConfig::fromArray($rawConfig);
            }
        );

        /** @var SchiiConfig $schiiConfig */
        $schiiConfig = $container->get(SchiiConfig::class);

        // 2. Register SchemaRegistry as a singleton
        $container->singleton(
            SchemaRegistry::class,
            function (Container $c) use ($schiiConfig): SchemaRegistry {
                /** @var mixed $cache */
                $cache = $c->has('cache') ? $c->get('cache') : null;

                return new SchemaRegistry(
                    cache: $cache,
                    cachePrefix: $schiiConfig->cachePrefix ?? 'schii_schema_',
                );
            }
        );

        // 3. Register isolated Schii Loader and append to ComponentLoaderRegistry if available
        $container->singleton(
            'schii.loader',
            fn(): PhueComponentLoader => new PhueComponentLoader(__DIR__ . '/Frontend')
        );

        if ($container->has(ComponentLoaderRegistry::class)) {
            /** @var ComponentLoaderRegistry $loaderRegistry */
            $loaderRegistry = $container->get(ComponentLoaderRegistry::class);
            $loaderRegistry->addLoader($container->get('schii.loader'));
        }

        // 4. Register Schii ComponentRenderer
        $container->singleton(
            'schii.renderer',
            fn(Container $c): ComponentRenderer => new ComponentRenderer(
                $c->get(HtmlShellRenderer::class),
                $c->get(AssetRegistry::class),
                $c->get('schii.loader')
            )
        );

        // 5. Bind ModelEndpoint with entityNamespace from SchiiConfig
        $container->bind(
            ModelEndpoint::class,
            function (Container $c): ModelEndpoint {
                /** @var SchiiConfig $config */
                $config = $c->get(SchiiConfig::class);

                return new ModelEndpoint(
                    $c->get(SchemaRegistry::class),
                    [],
                    $config->entityNamespace ?? 'App\\Models'
                );
            }
        );

        // 6. Dynamic Zero-Controller Route Registrations
        /** @var string $apiPrefix */
        $apiPrefix = '/' . trim($schiiConfig->apiPrefix ?? '/api/schii', '/');

        /** @var array<int, class-string> $middleware */
        $middleware = !empty($schiiConfig->middleware)
            ? $schiiConfig->middleware
            : [CorsMiddleware::class];

        $router->addRoute('GET', "{$apiPrefix}/schema/{entity}", [ModelEndpoint::class, 'schema'], $middleware);
        $router->addRoute('GET', "{$apiPrefix}/{entity}", [ModelEndpoint::class, 'index'], $middleware);
        $router->addRoute('POST', "{$apiPrefix}/{entity}", [ModelEndpoint::class, 'store'], $middleware);
        $router->addRoute('GET', "{$apiPrefix}/{entity}/{id}", [ModelEndpoint::class, 'show'], $middleware);
        $router->addRoute('PUT', "{$apiPrefix}/{entity}/{id}", [ModelEndpoint::class, 'update'], $middleware);
        $router->addRoute('DELETE', "{$apiPrefix}/{entity}/{id}", [ModelEndpoint::class, 'delete'], $middleware);

        // 7. Module Console UI Route
        /** @var string $consolePrefix */
        $consolePrefix = '/' . trim($schiiConfig->prefix ?? '/schii', '/');
        $router->addRoute('GET', $consolePrefix, [ConsoleController::class, 'index']);
    }
}