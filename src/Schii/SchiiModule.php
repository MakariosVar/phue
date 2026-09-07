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
use Phue\Schii\Endpoint\AuthorizedModelEndpoint;
use Phue\Schii\Endpoint\ModelEndpoint;
use Phue\Schii\Schema\SchemaRegistry;
use Phue\Schii\Controller\ConsoleController;
use Phue\Auth\AuthManager;
use Phue\Auth\Authenticator\BearerTokenAuthenticator;
use Phue\Auth\RbacManager;

/**
 * Class SchiiModule
 *
 * Serves as the bootstrapping module for the Schii admin panel and REST architecture.
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

        // 2. AuthManager Singleton with Framework Zero-Config Default Resolver
        $this->container->singleton(
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

        // 5. Register Component Loader & Renderer Services
        $this->container->singleton(
            'schii.loader',
            fn(): PhueComponentLoader => new PhueComponentLoader(__DIR__ . '/Frontend')
        );

        if ($this->container->has(ComponentLoaderRegistry::class)) {
            /** @var ComponentLoaderRegistry $loaderRegistry */
            $loaderRegistry = $this->container->get(ComponentLoaderRegistry::class);
            $loaderRegistry->addLoader($this->container->get('schii.loader'));
        }

        $this->container->singleton(
            'schii.renderer',
            fn(Container $c): ComponentRenderer => new ComponentRenderer(
                $c->get(HtmlShellRenderer::class),
                $c->get(AssetRegistry::class),
                $c->get('schii.loader')
            )
        );

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

        // 7. Dynamic REST & Console Route Mappings
        /** @var string $apiPrefix */
        $apiPrefix = '/' . trim($schiiConfig->apiPrefix ?? '/api/schii', '/');

        /** @var array<int, class-string> $middleware */
        $middleware = $schiiConfig->middleware ?? [];

        /** @var bool $requireAuth */
        $requireAuth = $schiiConfig->requireAuth ?? true;

        /** @var class-string $crudHandler */
        $crudHandler = $requireAuth 
            ? AuthorizedModelEndpoint::class 
            : ModelEndpoint::class;

        // Public Schema Metadata Endpoints
        $this->router->addRoute('GET', "{$apiPrefix}/schema", [ModelEndpoint::class, 'listSchemas'], $middleware);
        $this->router->addRoute('GET', "{$apiPrefix}/schema/{entity}", [ModelEndpoint::class, 'schema'], $middleware);

        // Protected CRUD Operations Endpoints
        $this->router->addRoute('GET', "{$apiPrefix}/{entity}", [$crudHandler, 'index'], $middleware);
        $this->router->addRoute('POST', "{$apiPrefix}/{entity}", [$crudHandler, 'store'], $middleware);
        $this->router->addRoute('GET', "{$apiPrefix}/{entity}/{id}", [$crudHandler, 'show'], $middleware);
        $this->router->addRoute('PUT', "{$apiPrefix}/{entity}/{id}", [$crudHandler, 'update'], $middleware);
        $this->router->addRoute('DELETE', "{$apiPrefix}/{entity}/{id}", [$crudHandler, 'delete'], $middleware);

        // Admin Console View Route
        /** @var string $consolePrefix */
        $consolePrefix = '/' . trim($schiiConfig->prefix ?? '/schii', '/');
        $this->router->addRoute('GET', $consolePrefix, [ConsoleController::class, 'index']);
    }
}