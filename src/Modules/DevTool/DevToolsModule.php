<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

use Phue\Core\Module;
use Phue\Core\Config;

/**
 * Class DevToolsModule
 *
 * Serves as the bootstrapping module for the Schii admin panel, Phii code generator, and REST architecture.
 * Configures framework dependency injections, handles UI component loaders, wires up
 * dynamic authentication resolvers, and registers REST API and console routes.
 *
 * @package Phue\Modules\DevTool
 */
class DevToolsModule extends Module
{
    /**
     * Module identifier.
     */
    public string $id = 'devtools';

    /**
     * Required module dependencies.
     *
     * @var array<int, string>
     */
    public array $requires = [];

    /**
     * Initialize the module
     *
     * @return void
     */
    public function init(): void
    {
        $this->config();
        $this->bindings();
    }

    /**
     * Registers schema registries, inspectors, generators, endpoints, and renderers.
     *
     * @return void
     */
    protected function bindings(): void
    {
        $debug = false;
        $devTool = false;
        if ($this->container->has(Config::class)) {
            $debug = $this->container->get(Config::class)->get('app.debug', false);
            $devTool = $this->container->get(Config::class)->get('app.devTool', false);
        }

        // Enable debug middleware if in debug mode and it's enabled
        if ($debug && $devTool) {
            $this->container->get(\Phue\Core\Application::class)
                ->use(\Phue\Modules\DevTool\DevToolMiddleware::class);
        }
    }
}