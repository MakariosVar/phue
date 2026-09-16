<?php

declare(strict_types=1);

namespace Phue\Core;

use Phue\Core\Routing\Router;

/**
 * Abstract Base Module class for framework extension packages and sub-applications.
 *
 * @package Phue\Core
 */
abstract class Module
{
    public string $id = '';
    public string $basePath = '';
    /** @var array<int, string> */
    public array $modules = [];
    public string $controllerNamespace = '';
    /** @var array<string, mixed> */
    public array $controllerMap = [];
    /** @var array<string, mixed> */
    public array $urlRules = [];
    public string $type = 'layer'; // 'layer' | 'kit'
    /** @var array<int, string> */
    public array $requires = [];

    protected Router $router;
    protected Container $container;
    protected EventDispatcher $events;

    /**
     * Initializes module components, routes, and dependency bindings.
     * Called automatically by Application after properties are injected.
     *
     * @return void
     */
    abstract public function init(): void;

    /**
     * Injects core infrastructure dependencies into the module.
     *
     * @param Router          $router
     * @param Container       $container
     * @param EventDispatcher $events
     * @return void
     */
    public function setDependencies(
        Router $router,
        Container $container,
        EventDispatcher $events
    ): void {
        $this->router = $router;
        $this->container = $container;
        $this->events = $events;
    }

    /**
     * Retrieves a nested child module by identifier if registered.
     *
     * @param string $id
     * @return self|null
     */
    public function getModule(string $id): ?self
    {
        if ($this->container->has("module.{$id}")) {
            /** @var self $module */
            $module = $this->container->get("module.{$id}");
            return $module;
        }

        return null;
    }

    /**
     * Resolves a controller instance for a given controller ID.
     *
     * @param string $id
     * @return object|null
     */
    public function getController(string $id): ?object
    {
        if (isset($this->controllerMap[$id])) {
            $class = $this->controllerMap[$id];
            return is_string($class) ? $this->container->get($class) : null;
        }

        if (!empty($this->controllerNamespace)) {
            $class = rtrim($this->controllerNamespace, '\\') . '\\' . ucfirst($id) . 'Controller';
            if (class_exists($class)) {
                return $this->container->get($class);
            }
        }

        return null;
    }

    /**
     * Checks if the module requires database connection.
     *
     * @return bool
     */
    public function requiresDb(): bool
    {
        return false;
    }

    /** Override to register DI bindings. */
    protected function bindings(): void {}

    /** Override to register application routes. */
    protected function routes(): void {}

    /** Override to register console commands. */
    public function registerCommands(\Phue\Core\Console\Application $console): void {}

    /** Override to provide navigation items for AppLayout. */
    protected function nav(): array { return []; }

    /** Override to provide RBAC permission seeds. */
    protected function permissions(): array { return []; }

    /** Override to merge default config values with config/<id>.php. */
    protected function config(): void {}

    /** Override to register CSS/JS assets */
    protected function assets(): array { return []; }
}