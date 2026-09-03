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
     * Register a general factory binding (compatibility with set()).
     * @param $id string | factory_id
     * @param $factory callable | factory callable
     */
    public function set(string $id, callable $factory): void
    {
        $this->bind($id, $factory, isScoped: false);
    }

    /**
     * Register an Application-scoped singleton service.
     */
    public function singleton(string $id, ?callable $factory = null): void
    {
        $this->bind($id, $factory, isScoped: false);
    }

    /**
     * Register a Request-scoped service.
     */
    public function scoped(string $id, ?callable $factory = null): void
    {
        $this->bind($id, $factory, isScoped: true);
    }

    /**
     * Bind a service factory and register its scope.
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
     * Resolve a service or class instance from the container based on scope.
     *
     * @param string $id Service identifier or target class name
     * @return mixed Resolved instance or object
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
     * Check whether a service factory exists or if a class/interface exists.
     */
    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || class_exists($id) || interface_exists($id);
    }

    /**
     * Clear all request-scoped instances and trigger ResettableInterface logic.
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

    private function missingFactory(string $id): never
    {
        throw new NotFoundException("Missing factory or class for service: '{$id}'");
    }
}