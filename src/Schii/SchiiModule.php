<?php

declare(strict_types=1);

namespace Phue\Schii;

use Phue\Core\Application;
use Phue\Core\Config;
use Phue\Core\Container;
use Phue\Core\Module;
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
class SchiiModule extends Module
{
    /**
     * Bootstraps singletons, bindings, component renderers, and dynamic API endpoints for Schii.
     *
     * @return void
     */
    public function init(): void
    {
        // 1. Config Binding: Register SchiiConfig singleton factory in DI container
        $this->container->singleton(
            SchiiConfig::class,
            function (): SchiiConfig {
                /** @var array<string, mixed> $rawConfig */
                $rawConfig = [];

                if ($this->container->has(Config::class)) {
                    /** @var Config $configInstance */
                    $configInstance = $this->container->get(Config::class);
                    $rawConfig = $configInstance->get('schii', []);
                } elseif ($this->container->has('config')) {
                    /** @var Config|array<string, mixed> $configBinding */
                    $configBinding = $this->container->get('config');
                    $rawConfig = is_array($configBinding)
                        ? ($configBinding['schii'] ?? [])
                        : $configBinding->get('schii', []);
                }

                return SchiiConfig::fromArray($rawConfig);
            }
        );

        /** @var SchiiConfig $schiiConfig */
        $schiiConfig = $this->container->get(SchiiConfig::class);

        // 2. Register SchemaRegistry as a singleton
        $this->container->singleton(
            SchemaRegistry::class,
            function (Container $c) use ($schiiConfig): SchemaRegistry {
                /** @var mixed $cache */
                $cache = $c->has('cache') ? $c->get('cache') : null;

                return new SchemaRegistry(
                    basePath: $c->get(Application::class)->getBasePath(),
                    cache: $cache,
                    cachePrefix: $schiiConfig->cachePrefix ?? 'schii_schema_',
                );
            }
        );

        // 3. Register isolated Schii Loader and append to ComponentLoaderRegistry if available
        $this->container->singleton(
            'schii.loader',
            fn(): PhueComponentLoader => new PhueComponentLoader(__DIR__ . '/Frontend')
        );

        if ($this->container->has(ComponentLoaderRegistry::class)) {
            /** @var ComponentLoaderRegistry $loaderRegistry */
            $loaderRegistry = $this->container->get(ComponentLoaderRegistry::class);
            $loaderRegistry->addLoader($this->container->get('schii.loader'));
        }

        // 4. Register Schii ComponentRenderer
        $this->container->singleton(
            'schii.renderer',
            fn(Container $c): ComponentRenderer => new ComponentRenderer(
                $c->get(HtmlShellRenderer::class),
                $c->get(AssetRegistry::class),
                $c->get('schii.loader')
            )
        );

        // 5. Bind ModelEndpoint with entityNamespace from SchiiConfig
        $this->container->bind(
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

        $this->router->addRoute('GET', "{$apiPrefix}/schema", [ModelEndpoint::class, 'listSchemas'], $middleware);
        $this->router->addRoute('GET', "{$apiPrefix}/schema/{entity}", [ModelEndpoint::class, 'schema'], $middleware);
        $this->router->addRoute('GET', "{$apiPrefix}/{entity}", [ModelEndpoint::class, 'index'], $middleware);
        $this->router->addRoute('POST', "{$apiPrefix}/{entity}", [ModelEndpoint::class, 'store'], $middleware);
        $this->router->addRoute('GET', "{$apiPrefix}/{entity}/{id}", [ModelEndpoint::class, 'show'], $middleware);
        $this->router->addRoute('PUT', "{$apiPrefix}/{entity}/{id}", [ModelEndpoint::class, 'update'], $middleware);
        $this->router->addRoute('DELETE', "{$apiPrefix}/{entity}/{id}", [ModelEndpoint::class, 'delete'], $middleware);

        // 7. Module Console UI Route
        /** @var string $consolePrefix */
        $consolePrefix = '/' . trim($schiiConfig->prefix ?? '/schii', '/');
        $this->router->addRoute('GET', $consolePrefix, [ConsoleController::class, 'index']);
    }
}