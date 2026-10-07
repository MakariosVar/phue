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
     * The current container instance for static access.
     */
    private static ?self $instance = null;

    /**
     * Reserved fallback scope key when no active ScopeContext is set.
     */
    private const FALLBACK_SCOPE = '__none__';

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
     * Request-scoped cached instances partitioned strictly by scope ID.
     * Format: [scope_id => [service_id => instance]]
     *
     * @var array<string, array<string, mixed>>
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
     * Map of alias keys to concrete abstract identifiers.
     *
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * Active scope context for the current execution unit.
     */
    private ?ScopeContext $currentScope = null;

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
        $targetId = $this->resolveAliasTarget($id);

        if ($factory !== null) {
            $this->factories[$targetId] = $factory;
        }

        if ($isScoped) {
            $this->scopedKeys[$targetId] = true;
            unset($this->instances[$targetId]);
        } else {
            unset($this->scopedKeys[$targetId]);
        }

        // Clean up existing instance across active and fallback scope storage
        $scopeKey = $this->getEffectiveScopeKey();
        unset($this->scopedInstances[$scopeKey][$targetId]);
        unset($this->scopedInstances[self::FALLBACK_SCOPE][$targetId]);
        unset($this->instances[$targetId]);
    }

    /**
     * Create a new execution scope context bound to the active coroutine or execution unit.
     *
     * @return ScopeContext
     */
    public function createScope(): ScopeContext
    {
        $coroutineId = class_exists('\Swoole\Coroutine') ? \Swoole\Coroutine::getCid() : -1;
        $scopeId = $coroutineId > 0 
            ? 'coroutine_' . $coroutineId 
            : uniqid('scope_', true);

        return new ScopeContext($scopeId);
    }

    /**
     * Set active scope context for the container.
     *
     * @param ScopeContext|null $scope
     * @return void
     */
    public function setScope(?ScopeContext $scope): void
    {
        $this->currentScope = $scope;
    }

    /**
     * Get current active scope context.
     *
     * @return ScopeContext|null
     */
    public function getScope(): ?ScopeContext
    {
        return $this->currentScope;
    }

    /**
     * Get the current container instance.
     *
     * @return self|null
     */
    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    /**
     * Set the current container instance for static access.
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Resolves current effective scope storage key.
     */
    private function getEffectiveScopeKey(?ScopeContext $scope = null): string
    {
        $targetScope = $scope ?? $this->currentScope;
        return $targetScope?->id ?? self::FALLBACK_SCOPE;
    }

    /**
     * Resolve an alias identifier to its concrete target ID with cycle protection.
     *
     * @param string $id Identifier or alias to resolve.
     * @return string Concrete target identifier.
     * @throws CircularDependencyException If an alias cycle is detected.
     */
    private function resolveAliasTarget(string $id): string
    {
        $targetId = $id;
        $visited = [];

        while (isset($this->aliases[$targetId])) {
            if (isset($visited[$targetId])) {
                $chain = implode(' -> ', array_keys($visited)) . " -> {$targetId}";
                throw new CircularDependencyException("Circular alias dependency detected: {$chain}");
            }

            $visited[$targetId] = true;
            $targetId = $this->aliases[$targetId];
        }

        return $targetId;
    }

    /**
     * Resolve a service or class instance from the container based on its scope.
     *
     * @param string $id Service identifier or target class name.
     * @return mixed Resolved instance or value.
     */
    public function get(string $id): mixed
    {
        $targetId = $this->resolveAliasTarget($id);

        $isScoped = isset($this->scopedKeys[$targetId]);
        $scopeKey = $this->getEffectiveScopeKey();

        // Single storage lookup
        if ($isScoped) {
            if (isset($this->scopedInstances[$scopeKey][$targetId])) {
                return $this->scopedInstances[$scopeKey][$targetId];
            }
        } elseif (isset($this->instances[$targetId])) {
            return $this->instances[$targetId];
        }

        // Resolve fresh instance using concrete target ID
        $instance = $this->make($targetId);

        // Store instance in single partitioned storage location
        if ($isScoped) {
            $this->scopedInstances[$scopeKey][$targetId] = $instance;
        } else {
            $this->instances[$targetId] = $instance;
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
        $targetId = $this->resolveAliasTarget($id);

        if (isset($this->resolving[$targetId])) {
            $chain = implode(' -> ', array_keys($this->resolving)) . " -> {$targetId}";
            throw new CircularDependencyException("Circular dependency detected: {$chain}");
        }

        $this->resolving[$targetId] = true;

        try {
            if (isset($this->factories[$targetId])) {
                return $this->factories[$targetId]($this);
            }

            if (class_exists($targetId) || interface_exists($targetId)) {
                return $this->autowire($targetId);
            }
        } finally {
            unset($this->resolving[$targetId]);
        }

        $this->missingFactory($targetId);
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
        $targetId = $this->resolveAliasTarget($id);
        $scopeKey = $this->getEffectiveScopeKey();

        if (
            isset($this->factories[$targetId]) || 
            isset($this->instances[$targetId]) || 
            isset($this->scopedInstances[$scopeKey][$targetId])
        ) {
            return true;
        }

        if (class_exists($targetId)) {
            try {
                $reflection = new ReflectionClass($targetId);
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
        $targetId = $this->resolveAliasTarget($id);
        $scopeKey = $this->getEffectiveScopeKey();

        return isset($this->factories[$targetId]) 
            || isset($this->instances[$targetId]) 
            || isset($this->scopedInstances[$scopeKey][$targetId]);
    }

    /**
     * Register an alias for an existing abstract binding or service.
     *
     * @param string $alias    Alias identifier (e.g. 'db').
     * @param string $abstract Target abstract class or interface FQCN.
     *
     * @return self Fluent instance reference for chaining.
     */
    public function alias(string $alias, string $abstract): self
    {
        $this->aliases[$alias] = $abstract;
        return $this;
    }

    /**
     * Clear all request-scoped instances for a given scope and trigger optional reset logic.
     *
     * @param ScopeContext|null $scope Target scope to flush, or null to flush the fallback bucket (__none__).
     * @return void
     */
    public function flushScope(?ScopeContext $scope = null): void
    {
        $scopeKey = $scope?->id ?? self::FALLBACK_SCOPE;

        if (isset($this->scopedInstances[$scopeKey])) {
            foreach ($this->scopedInstances[$scopeKey] as $instance) {
                if ($instance instanceof ResettableInterface) {
                    $instance->reset();
                }
            }
            unset($this->scopedInstances[$scopeKey]);
        }
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