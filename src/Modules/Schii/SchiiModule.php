<?php

declare(strict_types=1);

namespace Phue\Modules\Schii;

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
use Phue\Core\Ssr\NullSsrEngine;
use Phue\Core\Ssr\UnixSsrClient;
use Phue\Core\Ssr\BunSsrEngine;
use Phue\Modules\Schii\Config\SchiiConfig;
use Phue\Modules\Schii\Endpoint\AuthorizedModelEndpoint;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Endpoint\SchemaInspectorEndpoint;
use Phue\Modules\Schii\Endpoint\AuthorizedSchemaInspectorEndpoint;
use Phue\Modules\Schii\Endpoint\SchemaSyncEndpoint;
use Phue\Modules\Schii\Endpoint\AuthorizedSchemaSyncEndpoint;
use Phue\Modules\Schii\Endpoint\AuditEndpoint;
use Phue\Modules\Schii\Endpoint\AuthorizedAuditEndpoint;
use Phue\Modules\Schii\Schema\SchemaInspector;
use Phue\Modules\Schii\Schema\SchemaSyncEngine;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Modules\Schii\Controller\ConsoleController;
use Phue\Modules\Schii\Controller\PhiiController;
use Phue\Modules\Schii\Generator\BuildGenerator;
use Phue\Modules\Schii\Generator\RuntimeGenerator;
use Phue\Core\Ssr\SsrEngine;
use Phue\Modules\Auth\Authenticator\BearerTokenAuthenticator;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\RbacManager;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Class SchiiModule
 *
 * Serves as the bootstrapping module for the Schii admin panel, Phii code generator, and REST architecture.
 * Configures framework dependency injections, handles UI component loaders, wires up
 * dynamic authentication resolvers, and registers REST API and console routes.
 *
 * @package Phue\Modules\Schii
 */
class SchiiModule extends Module
{
    /**
     * Module identifier.
     */
    public string $id = 'schii';

    /**
     * Required module dependencies.
     *
     * @var array<int, string>
     */
    public array $requires = [];

    /**
     * Initializes module singletons, service bindings, component loaders, and REST routes.
     *
     * @return void
     */
    public function init(): void
    {
        $this->config();
        $this->bindings();
        $this->routes();

        /** @var SchiiConfig $schiiConfig */
        $schiiConfig = $this->container->get(SchiiConfig::class);

        if ($schiiConfig->requireAuth && $this->container->has('module.auth')) {
            $this->requires[] = 'auth';
        }
    }

    /**
     * Configures Schii configuration and core UI loaders.
     *
     * @return void
     */
    protected function config(): void
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

        // Directory where compiled component PHP blocks are written for OPCache execution
        $viewsCacheDir = $this->basePath . '/storage/cache/views';

        // PhUI Core Component Library Loader Singleton (/src/Core/UI/Phui)
        $this->container->singleton('phui.loader', function () use ($viewsCacheDir): PhueComponentLoader {
            return new PhueComponentLoader(
                componentsDir: $this->basePath . '/src/Core/UI/Phui',
                parser: null,
                persistentCache: $this->container->get(CacheInterface::class),
                viewsCacheDir: $viewsCacheDir
            );
        });
    }

    /**
     * Registers schema registries, inspectors, generators, endpoints, and renderers.
     *
     * @return void
     */
    protected function bindings(): void
    {
        /** @var SchiiConfig $schiiConfig */
        $schiiConfig = $this->container->get(SchiiConfig::class);

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
            function (Container $c) use ($schiiConfig): SchemaInspectorEndpoint {
                return new SchemaInspectorEndpoint(
                    $c->get(SchemaRegistry::class),
                    $c->has(SchemaInspector::class) ? $c->get(SchemaInspector::class) : null,
                    $c->has(Connection::class) ? $c->get(Connection::class) : null,
                    $schiiConfig->entityNamespace ?? 'App\\Models'
                );
            }
        );

        if ($this->container->has('module.auth')) {
            $this->container->bind(
                AuthorizedSchemaInspectorEndpoint::class,
                function (Container $c): AuthorizedSchemaInspectorEndpoint {
                    return new AuthorizedSchemaInspectorEndpoint(
                        $c->get(SchemaInspectorEndpoint::class),
                        $c->get(AuthManager::class),
                        $c->get(RbacManager::class)
                    );
                }
            );
        }

        $this->container->bind(
            SchemaSyncEndpoint::class,
            function (Container $c) use ($schiiConfig): SchemaSyncEndpoint {
                return new SchemaSyncEndpoint(
                    $c->get(SchemaSyncEngine::class),
                    $schiiConfig->entityNamespace ?? 'App\\Models'
                );
            }
        );

        if ($this->container->has('module.auth')) {
            $this->container->bind(
                AuthorizedSchemaSyncEndpoint::class,
                function (Container $c): AuthorizedSchemaSyncEndpoint {
                    return new AuthorizedSchemaSyncEndpoint(
                        $c->get(SchemaSyncEndpoint::class),
                        $c->get(AuthManager::class),
                        $c->get(RbacManager::class)
                    );
                }
            );
        }

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
            $loaderRegistry->addLoader(function() use ($loaderRegistry): PhueComponentLoader {
                /** @var PhueComponentLoader $loader */
                $loader = $this->container->get('schii.loader');
                $loader->setRegistry($loaderRegistry);
                return $loader;
            });
        }

        $this->container->singleton('schii.renderer', function ($c) {
            $userResolver = function () use ($c) {
                if ($c->has(\Phue\Modules\Auth\AuthManager::class)) {
                    /** @var \Phue\Modules\Auth\AuthManager $auth */
                    $auth = $c->get(\Phue\Modules\Auth\AuthManager::class);
                    
                    try {
                        /** @var \Phue\Core\Request $request */
                        $request = $c->get(\Phue\Core\Request::class);
                        return $auth->authenticate($request);
                    } catch (\Throwable) {
                        return null;
                    }
                }
                return null;
            };
            return new ComponentRenderer(
                shellRenderer: $c->get(HtmlShellRenderer::class),
                assetRegistry: $c->get(AssetRegistry::class),
                loader: $c->get('schii.loader'),
                layoutName: 'AppLayout',
                uriResolver: fn() => \Phue\Core\Request::capture()->getUri(),
                partialRenderer: $c->get(PhuePartialRenderer::class),
                ssrEngine: $c->get(NullSsrEngine::class),
                loaderRegistry: $c->get(ComponentLoaderRegistry::class),
                requestResolver: fn () => $c->get(\Phue\Core\Request::class),
                userResolver: $userResolver
                
            );
        });

        $this->container->singleton(
            'phii.loader',
            fn(): PhueComponentLoader => new PhueComponentLoader(__DIR__ . '/Frontend')
        );

        // Register Component Loader & Renderer Services (Phii)
        if ($this->container->has(ComponentLoaderRegistry::class)) {
            /** @var ComponentLoaderRegistry $loaderRegistry */
            $loaderRegistry = $this->container->get(ComponentLoaderRegistry::class);
            $loaderRegistry->addLoader(function() use ($loaderRegistry): ?PhueComponentLoader {
                try {
                    /** @var PhueComponentLoader $loader */
                    $loader = $this->container->get('phii.loader');
                    $loader->setRegistry($loaderRegistry);
                    return $loader;
                } catch (Throwable) {
                    return null;
                }
            });
        }

        $this->container->singleton('phii.renderer', function ($c) {
            return new ComponentRenderer(
                shellRenderer: $c->get(HtmlShellRenderer::class),
                assetRegistry: $c->get(AssetRegistry::class),
                loader: $c->get('phii.loader'),
                layoutName: 'AppLayout',
                uriResolver: fn() => \Phue\Core\Request::capture()->getUri(),
                partialRenderer: $c->get(PhuePartialRenderer::class),
                ssrEngine: $c->get(NullSsrEngine::class),
                loaderRegistry: $c->get(ComponentLoaderRegistry::class),
                requestResolver: fn () => $c->get(\Phue\Core\Request::class)
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
                    $config->entityNamespace ?? 'App\\Models',
                    $c->has('module.auth') ? $c->get(AuthManager::class) : null,
                );
            }
        );

        if ($this->container->has('module.auth')) {
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
        }

        // AuditEndpoint & AuthorizedAuditEndpoint Dependency Bindings
        $this->container->bind(
            AuditEndpoint::class,
            fn(Container $c): AuditEndpoint => new AuditEndpoint()
        );

        if ($this->container->has('module.auth')) {
            $this->container->bind(
                AuthorizedAuditEndpoint::class,
                function (Container $c): AuthorizedAuditEndpoint {
                    return new AuthorizedAuditEndpoint(
                        $c->get(AuditEndpoint::class),
                        $c->get(AuthManager::class),
                        $c->get(RbacManager::class)
                    );
                }
            );
        }

        /** @var bool $requireAuth */
        $requireAuth = $schiiConfig->requireAuth && $this->container->has('module.auth');

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
                    $c->get(Application::class),
                    $c->has('module.auth') ? $c->get(AuthManager::class) : null,
                    $c->has('module.auth') ? $c->get(RbacManager::class) : null
                );
            }
        );
    }

    /**
     * Register commands
     *
     * @param \Phue\Core\Console\Application $console
     * @return void
     */
    public function registerCommands(\Phue\Core\Console\Application $console): void
    {
        $console->add(new \Phue\Modules\Schii\Commands\SchiiMigrateCommand());
        $console->add(new \Phue\Modules\Schii\Commands\SchiiExportCommand());
        $console->add(new \Phue\Modules\Schii\Commands\SchiiServeCommand());
    }

    /**
     * Registers REST API and console routes.
     *
     * @return void
     */
    protected function routes(): void
    {
        /** @var SchiiConfig $schiiConfig */
        $schiiConfig = $this->container->get(SchiiConfig::class);

        /** @var bool $requireAuth */
        $requireAuth = $schiiConfig->requireAuth && $this->container->has('module.auth');

        /** @var class-string $crudHandler */
        $crudHandler = $requireAuth
            ? AuthorizedModelEndpoint::class
            : ModelEndpoint::class;

        /** @var class-string $auditHandler */
        $auditHandler = $requireAuth
            ? AuthorizedAuditEndpoint::class
            : AuditEndpoint::class;

        /** @var class-string $schemaInspectorHandler */
        $schemaInspectorHandler = $requireAuth
            ? AuthorizedSchemaInspectorEndpoint::class
            : SchemaInspectorEndpoint::class;

        /** @var class-string $schemaSyncHandler */
        $schemaSyncHandler = $requireAuth
            ? AuthorizedSchemaSyncEndpoint::class
            : SchemaSyncEndpoint::class;

        // 7. Dynamic REST & Console Route Mappings
        /** @var string $apiPrefix */
        $apiPrefix = '/' . trim($schiiConfig->apiPrefix ?? '/api/schii', '/');

        /** @var array<int, class-string> $middleware */
        $middleware = $schiiConfig->middleware ?? [];

        if ($schiiConfig->enabled) {
            /** @var string $phiiPrefix */
            $phiiPrefix = '/' . trim($schiiConfig->phiiPrefix ?? '/phii', '/');

            // Advanced Schii Operations Endpoints & Diff - MUST be registered BEFORE {id} routes
            $this->router->addRoute('POST', "{$apiPrefix}/{entity}/bulk", [$crudHandler, 'bulk'], middleware: $middleware);
            $this->router->addRoute('GET', "{$apiPrefix}/{entity}/export", [$crudHandler, 'export'], middleware: $middleware);
            $this->router->addRoute('POST', "{$apiPrefix}/{entity}/import", [$crudHandler, 'import'], middleware: $middleware);
            $this->router->addRoute('GET', "{$apiPrefix}/schema/{entity}/diff", [$schemaSyncHandler, 'diff'], middleware: $middleware);

            // Inspector & Sync routes (placed before generic {entity} CRUD routes)
            $this->router->addRoute('GET', "{$apiPrefix}/schema/{entity}/structure", [$schemaInspectorHandler, 'structure'], middleware: $middleware);
            $this->router->addRoute('POST', "{$apiPrefix}/schema/{entity}/sync", [$schemaSyncHandler, 'sync'], middleware: $middleware);

            // Public Schema Metadata Endpoints
            $this->router->addRoute('GET', "{$apiPrefix}/schema", [ModelEndpoint::class, 'listSchemas'], middleware: $middleware);
            $this->router->addRoute('GET', "{$apiPrefix}/schema/{entity}", [ModelEndpoint::class, 'schema'], middleware: $middleware);

            // Audit Log Routes
            $this->router->addRoute('GET', "{$apiPrefix}/audit", [$auditHandler, 'index'], middleware: $middleware);
            $this->router->addRoute('GET', "{$apiPrefix}/audit/{entity}", [$auditHandler, 'forEntity'], middleware: $middleware);

            // Protected CRUD Operations Endpoints
            $this->router->addRoute('GET', "{$apiPrefix}/{entity}", [$crudHandler, 'index'], middleware: $middleware);
            $this->router->addRoute('POST', "{$apiPrefix}/{entity}", [$crudHandler, 'store'], middleware: $middleware);
            $this->router->addRoute('GET', "{$apiPrefix}/{entity}/{id}", [$crudHandler, 'show'], middleware: $middleware);
            $this->router->addRoute('PUT', "{$apiPrefix}/{entity}/{id}", [$crudHandler, 'update'], middleware: $middleware);
            $this->router->addRoute('DELETE', "{$apiPrefix}/{entity}/{id}", [$crudHandler, 'delete'], middleware: $middleware);
            // Admin Console View Route
            /** @var string $consolePrefix */
            $consolePrefix = '/' . trim($schiiConfig->prefix ?? '/schii', '/');
            $this->router->addRoute('GET', $consolePrefix, [ConsoleController::class, 'index']);

            // Phii Panel & Generation / Preview / Apply Routes
            $this->router->addRoute('GET', $phiiPrefix, [PhiiController::class, 'index']);
            $this->router->addRoute('POST', "{$phiiPrefix}/generate", [PhiiController::class, 'generate'], middleware: $middleware);
            $this->router->addRoute('POST', "{$phiiPrefix}/preview", [PhiiController::class, 'preview'], middleware: $middleware);
            $this->router->addRoute('POST', "{$phiiPrefix}/apply", [PhiiController::class, 'apply'], middleware: $middleware);
        }
    }
}