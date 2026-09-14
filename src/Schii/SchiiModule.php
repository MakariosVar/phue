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
use Phue\Core\Routing\Router;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhuePartialRenderer;
use Phue\Core\Database\Connection;
use Phue\Schii\Config\SchiiConfig;
use Phue\Schii\Endpoint\AuthorizedModelEndpoint;
use Phue\Schii\Endpoint\ModelEndpoint;
use Phue\Schii\Endpoint\SchemaInspectorEndpoint;
use Phue\Schii\Endpoint\SchemaSyncEndpoint;
use Phue\Schii\Schema\SchemaInspector;
use Phue\Schii\Schema\SchemaSyncEngine;
use Phue\Schii\Schema\SchemaRegistry;
use Phue\Schii\Controller\ConsoleController;
use Phue\Schii\Controller\PhiiController;
use Phue\Schii\Generator\BuildGenerator;
use Phue\Schii\Generator\RuntimeGenerator;
use Phue\Core\Ssr\SsrEngine;
use Phue\Auth\Authenticator\BearerTokenAuthenticator;
use Phue\Auth\AuthManager;
use Phue\Auth\RbacManager;

/**
 * Class SchiiModule
 *
 * Serves as the bootstrapping module for the Schii admin panel, Phii code generator, and REST architecture.
 * Configures framework dependency injections, handles UI component loaders, wires up
 * dynamic authentication resolvers, and registers REST API and console routes.
 *
 * @package Phue\Schii
 */
class SchiiModule extends Module
{
    /**
     * Initializes module singletons, service bindings, component loaders, and REST routes.
     *
     * @return void
     */
    public function init(): void
    {
        // 1. Config Binding: Resolves and registers the SchiiConfig singleton
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

        // 2. AuthManager Scoped with Framework Zero-Config Default Resolver
        $this->container->scoped(
            AuthManager::class,
            function (Container $c) use ($schiiConfig): AuthManager {
                /** @var callable|null $resolver */
                $resolver = $schiiConfig->authResolver 
                    ?? [\Phue\Auth\BaseUser::class, 'findIdentityByAuthKey'];

                $bearerAuth = new BearerTokenAuthenticator(
                    tokenResolver: static function (string $token) use ($resolver): mixed {
                        if (is_callable($resolver)) {
                            return $resolver($token);
                        }
                        return null;
                    }
                );

                return new AuthManager([$bearerAuth]);
            }
        );

        // 3. RbacManager Singleton
        $this->container->singleton(
            RbacManager::class,
            fn(): RbacManager => new RbacManager()
        );

        // 4. SchemaRegistry Singleton
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

        // 4a. Phii & Inspector DI Bindings
        $this->container->bind(
            SchemaInspector::class,
            function (Container $c): SchemaInspector {
                $connection = $c->has(Connection::class) ? $c->get(Connection::class) : null;
                if (!$connection) {
                    throw new \RuntimeException('Database connection is not available for SchemaInspector.');
                }
                return new SchemaInspector($connection);
            }
        );

        $this->container->bind(
            SchemaSyncEngine::class,
            function (Container $c): SchemaSyncEngine {
                $connection = $c->has(Connection::class) ? $c->get(Connection::class) : null;
                if (!$connection) {
                    throw new \RuntimeException('Database connection is not available for SchemaSyncEngine.');
                }
                return new SchemaSyncEngine(
                    $c->get(SchemaRegistry::class),
                    $c->get(SchemaInspector::class),
                    $connection
                );
            }
        );

        $this->container->bind(
            SchemaInspectorEndpoint::class,
            function (Container $c): SchemaInspectorEndpoint {
                return new SchemaInspectorEndpoint(
                    $c->get(SchemaRegistry::class),
                    $c->has(Connection::class) ? $c->get(Connection::class) : null,
                    $schiiConfig->entityNamespace ?? 'App\\Models'
                );
            }
        );

        $this->container->bind(
            SchemaSyncEndpoint::class,
            function (Container $c): SchemaSyncEndpoint {
                return new SchemaSyncEndpoint(
                    $c->get(SchemaSyncEngine::class),
                    $schiiConfig->entityNamespace ?? 'App\\Models'
                );
            }
        );

        $this->container->bind(
            BuildGenerator::class,
            function (Container $c) use ($schiiConfig): BuildGenerator {
                return new BuildGenerator(
                    $c->get(SchemaRegistry::class),
                    $schiiConfig->exportDir ?? 'storage/generated'
                );
            }
        );

        // 5. Register Component Loader & Renderer Services (Schii)
        $this->container->singleton(
            'schii.loader',
            fn(): PhueComponentLoader => new PhueComponentLoader(__DIR__ . '/Frontend')
        );

        if ($this->container->has(ComponentLoaderRegistry::class)) {
            /** @var ComponentLoaderRegistry $loaderRegistry */
            $loaderRegistry = $this->container->get(ComponentLoaderRegistry::class);
            $loaderRegistry->addLoader($this->container->get('schii.loader'));
        }

        $this->container->singleton('schii.renderer', function ($c) {
            return new ComponentRenderer(
                shellRenderer: $c->get(HtmlShellRenderer::class),
                assetRegistry: $c->get(AssetRegistry::class),
                loader: $c->get('schii.loader'),
                layoutName: 'AppLayout',
                uriResolver: fn() => \Phue\Core\Request::capture()->getUri(),
                partialRenderer: $c->get(PhuePartialRenderer::class),
                ssrEngine: $c->get(SsrEngine::class),
                loaderRegistry: $c->get(ComponentLoaderRegistry::class)
            );
        });

        // Register Component Loader & Renderer Services (Phii)
        $this->container->singleton(
            'phii.loader',
            fn(): PhueComponentLoader => new PhueComponentLoader(__DIR__ . '/Frontend')
        );

        if ($this->container->has(ComponentLoaderRegistry::class)) {
            /** @var ComponentLoaderRegistry $loaderRegistry */
            $loaderRegistry = $this->container->get(ComponentLoaderRegistry::class);
            $loaderRegistry->addLoader($this->container->get('phii.loader'));
        }

        $this->container->singleton('phii.renderer', function ($c) {
            return new ComponentRenderer(
                shellRenderer: $c->get(HtmlShellRenderer::class),
                assetRegistry: $c->get(AssetRegistry::class),
                loader: $c->get('phii.loader'),
                layoutName: 'AppLayout',
                uriResolver: fn() => \Phue\Core\Request::capture()->getUri(),
                partialRenderer: $c->get(PhuePartialRenderer::class),
                ssrEngine: $c->get(SsrEngine::class),
                loaderRegistry: $c->get(ComponentLoaderRegistry::class)
            );
        });

        // 6. ModelEndpoint Dependency Bindings
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

        $this->container->bind(
            AuthorizedModelEndpoint::class,
            function (Container $c): AuthorizedModelEndpoint {
                return new AuthorizedModelEndpoint(
                    $c->get(ModelEndpoint::class),
                    $c->get(AuthManager::class),
                    $c->get(RbacManager::class),
                    $c->get(SchemaRegistry::class)
                );
            }
        );

        /** @var bool $requireAuth */
        $requireAuth = $schiiConfig->requireAuth;

        /** @var class-string $crudHandler */
        $crudHandler = $requireAuth 
            ? AuthorizedModelEndpoint::class 
            : ModelEndpoint::class;

        $this->container->bind(
            RuntimeGenerator::class,
            function (Container $c) use ($crudHandler): RuntimeGenerator {
                return new RuntimeGenerator(
                    $c->get(Router::class),
                    $c->get($crudHandler),
                    $c->get(SchemaRegistry::class)
                );
            }
        );

        $this->container->bind(
            PhiiController::class,
            function (Container $c): PhiiController {
                return new PhiiController(
                    $c->get('phii.renderer'),
                    $c->get(SchemaRegistry::class),
                    $c->get(SchiiConfig::class),
                    $c->get(Application::class)
                );
            }
        );

        // 7. Dynamic REST & Console Route Mappings
        /** @var string $apiPrefix */
        $apiPrefix = '/' . trim($schiiConfig->apiPrefix ?? '/api/schii', '/');

        /** @var array<int, class-string> $middleware */
        $middleware = $schiiConfig->middleware ?? [];

        if ($schiiConfig->enabled) {
            /** @var string $phiiPrefix */
            $phiiPrefix = '/' . trim($schiiConfig->phiiPrefix ?? '/phii', '/');

            // Inspector & Sync routes (placed before generic {entity} CRUD routes)
            $this->router->addRoute('GET', "{$apiPrefix}/schema/{entity}/structure", [SchemaInspectorEndpoint::class, 'structure'], middleware: $middleware);
            $this->router->addRoute('POST', "{$apiPrefix}/schema/{entity}/sync", [SchemaSyncEndpoint::class, 'sync'], middleware: $middleware);

            // Public Schema Metadata Endpoints
            $this->router->addRoute('GET', "{$apiPrefix}/schema", [ModelEndpoint::class, 'listSchemas'], middleware: $middleware);
            $this->router->addRoute('GET', "{$apiPrefix}/schema/{entity}", [ModelEndpoint::class, 'schema'], middleware: $middleware);

            // Protected CRUD Operations Endpoints
            $this->router->addRoute('GET', "{$apiPrefix}/{entity}", [$crudHandler, 'index'], middleware: $middleware);
            $this->router->addRoute('POST', "{$apiPrefix}/{entity}", [$crudHandler, 'store'], middleware: $middleware);
            $this->router->addRoute('GET', "{$apiPrefix}/{entity}/{id}", [$crudHandler, 'show'], middleware: $middleware);
            $this->router->addRoute('PUT', "{$apiPrefix}/{entity}/{id}", [$crudHandler, 'update'], middleware: $middleware);
            $this->router->addRoute('DELETE', "{$apiPrefix}/{entity}/{id}", [$crudHandler, 'delete'], middleware: $middleware);

            // Phii Panel & Generation Routes
            $this->router->addRoute('GET', $phiiPrefix, [PhiiController::class, 'index']);
            $this->router->addRoute('POST', "{$phiiPrefix}/generate", [PhiiController::class, 'generate']);

            // Admin Console View Route
            /** @var string $consolePrefix */
            $consolePrefix = '/' . trim($schiiConfig->prefix ?? '/schii', '/');
            $this->router->addRoute('GET', $consolePrefix, [ConsoleController::class, 'index']);
        }        
    }
}