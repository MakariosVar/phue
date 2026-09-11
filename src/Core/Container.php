<?php

namespace Phue\Core;

use ReflectionClass;
use ReflectionNamedType;
use Phue\Core\Exceptions\ContainerException;
use Phue\Core\Exceptions\NotFoundException;
use Phue\Core\Exceptions\CircularDependencyException;

/**
 * Main application Container handling dependency injection,
 * scope management (Application vs Request), and Reflection autowiring.
 *
 * @package Phue\Core
 */
class Container
{
    /**
     * Registered service factories indexed by ID/Class name.
     *
     * @var array<string, callable>
     */
    private array $factories = [];

    /**
     * Permanent application-scoped cached instances.
     *
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * Request-scoped cached instances (flushed after each HTTP request).
     *
     * @var array<string, mixed>
     */
    private array $scopedInstances = [];

    /**
     * Tracked service keys explicitly defined as request-scoped.
     *
     * @var array<string, bool>
     */
    private array $scopedKeys = [];

    /**
     * Active dependency resolution stack used for circular reference detection.
     *
     * @var array<string, bool>
     */
    private array $resolving = [];

    /**
     * Register a general factory binding (compatibility wrapper for bind).
     *
     * @param string $id Unique service identifier or class/interface FQCN.
     * @param callable $factory Factory callback used to create the service instance.
     * @return void
     */
    public function set(string $id, callable $factory): void
    {
        $this->bind($id, $factory, isScoped: false);
    }

    /**
     * Register an Application-scoped singleton service.
     *
     * @param string $id Unique service identifier or class/interface FQCN.
     * @param callable|null $factory Optional factory callback. If null, class autowiring will be attempted.
     * @return void
     */
    public function singleton(string $id, ?callable $factory = null): void
    {
        $this->bind($id, $factory, isScoped: false);
    }

    /**
     * Register a Request-scoped service that is reset between individual HTTP request lifecycles.
     *
     * @param string $id Unique service identifier or class/interface FQCN.
     * @param callable|null $factory Optional factory callback. If null, class autowiring will be attempted.
     * @return void
     */
    public function scoped(string $id, ?callable $factory = null): void
    {
        $this->bind($id, $factory, isScoped: true);
    }

    /**
     * Bind a service factory and register its persistence scope.
     *
     * @param string $id Unique service identifier or class/interface FQCN.
     * @param callable|null $factory Optional factory callback.
     * @param bool $isScoped Whether the service should be request-scoped (true) or application-scoped (false).
     * @return void
     */
    public function bind(string $id, ?callable $factory = null, bool $isScoped = false): void
    {
        if ($factory !== null) {
            $this->factories[$id] = $factory;
        }

        if ($isScoped) {
            $this->scopedKeys[$id] = true;
            unset($this->instances[$id]);
        } else {
            unset($this->scopedKeys[$id]);
            unset($this->scopedInstances[$id]);
        }

        unset($this->instances[$id]);
    }

    /**
     * Resolve a service or class instance from the container based on its scope.
     *
     * @param string $id Service identifier or target class name.
     * @return mixed Resolved instance or value.
     * @throws CircularDependencyException If a circular dependency is detected during instantiation.
     * @throws ContainerException If an uninstantiable class or unresolved parameter is encountered.
     * @throws NotFoundException If no factory or autowirable class exists for the requested ID.
     */
    public function get(string $id): mixed
    {
        $isScoped = isset($this->scopedKeys[$id]);

        // 1. Check existing cached instances based on scope
        if ($isScoped && isset($this->scopedInstances[$id])) {
            return $this->scopedInstances[$id];
        }

        if (!$isScoped && isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // 2. Resolve fresh instance
        $instance = $this->make($id);

        // 3. Store instance in appropriate scope cache
        if ($isScoped) {
            $this->scopedInstances[$id] = $instance;
        } else {
            $this->instances[$id] = $instance;
        }

        return $instance;
    }

    /**
     * Force resolution of a new, un-cached service instance.
     *
     * @param string $id Service identifier or target class name.
     * @return mixed Resolved fresh instance or object.
     * @throws CircularDependencyException If a circular dependency chain is encountered.
     * @throws NotFoundException If no factory or resolvable class exists for the given ID.
     */
    public function make(string $id): mixed
    {
        if (isset($this->resolving[$id])) {
            $chain = implode(' -> ', array_keys($this->resolving)) . " -> {$id}";
            throw new CircularDependencyException("Circular dependency detected: {$chain}");
        }

        $this->resolving[$id] = true;

        try {
            if (isset($this->factories[$id])) {
                return $this->factories[$id]($this);
            }

            if (class_exists($id) || interface_exists($id)) {
                return $this->autowire($id);
            }
        } finally {
            unset($this->resolving[$id]);
        }

        $this->missingFactory($id);
    }

    /**
     * Check whether a service factory is bound or if a concrete instantiable class exists.
     *
     * Adheres to PSR-11: Returns false for unmapped interfaces, abstract classes,
     * or non-instantiable types where get() would fail.
     *
     * @param string $id Service identifier or class/interface FQCN.
     * @return bool True if resolvable; false otherwise.
     */
    public function has(string $id): bool
    {
        if (isset($this->factories[$id]) || isset($this->instances[$id]) || isset($this->scopedInstances[$id])) {
            return true;
        }

        if (class_exists($id)) {
            try {
                $reflection = new ReflectionClass($id);
                return $reflection->isInstantiable();
            } catch (\ReflectionException) {
                return false;
            }
        }

        return false;
    }

    /**
     * Check if a service or class has been explicitly bound in the container.
     *
     * Unlike has(), this method ignores reflection/autowiring fallbacks and only
     * returns true if an explicit binding, instance, or factory was registered.
     *
     * @param string $id Service identifier or class name.
     * @return bool True if explicitly registered, false otherwise.
     */
    public function bound(string $id): bool
    {
        return isset($this->factories[$id]) 
            || isset($this->instances[$id]) 
            || isset($this->scopedInstances[$id]);
    }

    /**
     * Register an alias for an existing abstract binding or service.
     *
     * Maps an alias string directly to a factory closure that resolves
     * the target abstract FQCN via the container.
     *
     * @param string $alias    Alias identifier (e.g. 'cache').
     * @param string $abstract Target abstract class or interface FQCN.
     *
     * @return self Fluent instance reference for chaining.
     */
    public function alias(string $alias, string $abstract): self
    {
        $this->factories[$alias] = fn (): mixed => $this->get($abstract);

        return $this;
    }

    /**
     * Clear all request-scoped instances and trigger optional reset logic for resettable instances.
     *
     * @return void
     */
    public function flushScope(): void
    {
        foreach ($this->scopedInstances as $instance) {
            if ($instance instanceof ResettableInterface) {
                $instance->reset();
            }
        }

        $this->scopedInstances = [];
    }

    /**
     * Automatically instantiate a class using reflection and resolve constructor dependencies.
     *
     * @param string $id Fully Qualified Class Name (FQCN) to autowire.
     * @return object Instantiated class object.
     * @throws ContainerException If class cannot be instantiated or parameters cannot be resolved.
     */
    private function autowire(string $id): object
    {
        $reflection = new ReflectionClass($id);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(
                "Class '{$id}' is not instantiable (interface, abstract class, or private constructor).",
            );
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $id();
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            // 1. Handling Scalar/Built-in Types or Missing Type-hints
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }

                if ($parameter->allowsNull()) {
                    $dependencies[] = null;
                    continue;
                }

                throw new ContainerException(
                    "Cannot autowire parameter '{$parameter->getName()}' in '{$id}': Missing class type hint, default value, or nullable declaration.",
                );
            }

            // 2. Handling Class / Interface Type-hints
            try {
                $dependencies[] = $this->get($type->getName());
            } catch (ContainerException $e) {
                // Fallback to default value if available
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }

                // Fallback to null if the parameter explicitly allows null
                if ($parameter->allowsNull()) {
                    $dependencies[] = null;
                    continue;
                }

                // Re-throw if resolution failed and no fallback is possible
                throw $e;
            }
        }

        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * Throw an exception when a service or class cannot be resolved.
     *
     * @param string $id Unresolvable service or class key.
     * @return never
     * @throws NotFoundException Always throws exception.
     */
    private function missingFactory(string $id): never
    {
        throw new NotFoundException("Missing factory or class for service: '{$id}'");
    }
}