<?php

declare(strict_types=1);

namespace Phue\Modules\HttpSigning;

use Phue\Core\Module;
use Phue\Core\Container;
use Phue\Core\Config;
use Phue\Modules\HttpSigning\Middleware\ResponseSigningMiddleware;

/**
 * HTTP Response Signing Module.
 *
 * Adds a global middleware that cryptographically signs HTTP responses
 * to provide integrity and authenticity guarantees.
 *
 * The module registers:
 * - SigningKeyManager: handles key management and signing operations
 * - ResponseSigningMiddleware: global middleware that signs responses
 *
 * Configuration is loaded from config/http-signing.php
 *
 * @package Phue\Modules\HttpSigning
 */
class HttpSigningModule extends Module
{
    /**
     * Module identifier.
     *
     * @var string
     */
    public string $id = 'http-signing';

    /**
     * Module dependencies.
     *
     * @var array<int, string>
     */
    public array $requires = [];

    /**
     * Module type: 'layer' (horizontal concern) or 'kit' (feature package).
     *
     * @var string
     */
    public string $type = 'layer';

    /**
     * Check if module requires database connection.
     *
     * @return bool False - signing module does not require database
     */
    public function requiresDb(): bool
    {
        return false;
    }

    /**
     * Initialize module.
     *
     * Called automatically by Application after dependencies are injected.
     *
     * @return void
     */
    public function init(): void
    {
        $this->bindings();
    }

    /**
     * Register dependency injection bindings.
     *
     * Registers SigningKeyManager and ResponseSigningMiddleware as singletons,
     * and adds the middleware to the global middleware stack as the OUTERMOST layer
     * (last registered = first to execute = signs everything including other middleware headers).
     *
     * @return void
     */
    protected function bindings(): void
    {
        $this->container->singleton(SigningKeyManager::class, function (Container $c): SigningKeyManager {
            /** @var Config $config */
            $config = $c->get(Config::class);
            return SigningKeyManager::fromConfig($config);
        });

        $this->container->singleton(ResponseSigningMiddleware::class, function (Container $c): ResponseSigningMiddleware {
            /** @var Config $config */
            $config = $c->get(Config::class);
            /** @var SigningKeyManager $keys */
            $keys = $c->get(SigningKeyManager::class);

            $signingConfig = $config->get('http-signing', []);

            return new ResponseSigningMiddleware(
                keys: $keys,
                config: $config,
                excludePaths: $signingConfig['exclude_paths'] ?? [],
                signedHeaders: $signingConfig['signed_headers'] ?? []
            );
        });

        // Register as GLOBAL middleware - must be OUTERMOST (last in stack = first to execute)
        // We use a high priority by registering it last in the module init order
        // The Application will use() it, and since middleware stack is reversed,
        // last registered = outermost = signs everything including other middleware headers
        $this->container->get(\Phue\Core\Application::class)->use(ResponseSigningMiddleware::class);
    }

    /**
     * Merge default config values with config/http-signing.php.
     *
     * Config is loaded automatically from config/http-signing.php by Application,
     * so this method intentionally does nothing.
     *
     * @return void
     */
    protected function config(): void
    {
        // Config is loaded automatically from config/http-signing.php by Application
    }
}