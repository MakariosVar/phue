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
     * Key the active scope is stored under inside coroutine-local storage.
     */
    private const COROUTINE_SCOPE_KEY = 'phue.container.scope';

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
     * Returns the coroutine-local storage bag, or null outside a coroutine.
     *
     * Why this exists
     * ---------------
     * The scope *key* is already coroutine-specific — createScope() derives it
     * from Swoole\Coroutine::getCid(). But the pointer saying "which scope is
     * active" was a single instance property, read by getEffectiveScopeKey().
     * Under `enable_coroutine => true` (the SwooleAdapter default) two requests
     * interleave at every I/O point, so the second one to call setScope() while
     * the first is yielded overwrote the pointer, and the first resolved its
     * services against the second's bucket.
     *
     * Swoole\Coroutine::getContext() is the per-coroutine bag that survives a
     * yield. Storing the pointer there makes setScope/getScope/getEffectiveScopeKey
     * observe the same execution unit as createScope() does.
     *
     * Outside a coroutine (FPM, RoadRunner, CLI, the test suite) there is no
     * bag to use and the plain property remains the single source of truth.
     *
     * @return \ArrayAccess|null Coroutine context bag, or null when not in a coroutine.
     */
    private function coroutineScopeStore(): ?\ArrayAccess
    {
        if (!class_exists('\Swoole\Coroutine')) {
            return null;
        }

        if (\Swoole\Coroutine::getCid() <= 0) {
            return null;
        }

        $context = \Swoole\Coroutine::getContext();

        return $context instanceof \ArrayAccess ? $context : null;
    }

    /**
     * Set active scope context for the container.
     *
     * @param ScopeContext|null $scope
     * @return void
     */
    public function setScope(?ScopeContext $scope): void
    {
        $store = $this->coroutineScopeStore();

        if ($store !== null) {
            if ($scope === null) {
                unset($store[self::COROUTINE_SCOPE_KEY]);
            } else {
                $store[self::COROUTINE_SCOPE_KEY] = $scope;
            }

            return;
        }

        $this->currentScope = $scope;
    }

    /**
     * Get current active scope context.
     *
     * @return ScopeContext|null
     */
    public function getScope(): ?ScopeContext
    {
        $store = $this->coroutineScopeStore();

        if ($store !== null) {
            $scope = $store[self::COROUTINE_SCOPE_KEY] ?? null;

            return $scope instanceof ScopeContext ? $scope : null;
        }

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
     *
     * Reads through getScope() rather than the raw property so that every
     * consumer — get(), has(), bound(), bind() — partitions by the scope the
     * *current execution unit* owns, not by whichever coroutine wrote last.
     */
    private function getEffectiveScopeKey(?ScopeContext $scope = null): string
    {
        $targetScope = $scope ?? $this->getScope();
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
     * Place an already-constructed instance into a scope bucket.
     *
     * Why this exists
     * ---------------
     * The kernel has to tell the container "the Request for *this* scope is
     * this object". scoped()/bind() cannot express that: bind() writes
     * $this->factories[$id], and the factory table is shared by every scope on
     * the container. Under concurrency the last coroutine to bind decides what
     * all the others resolve — one request receiving another's headers, body,
     * cookies and IP address.
     *
     * Writing the instance straight into $scopedInstances keeps the value
     * partitioned by scope id, which is the same boundary get() already trusts.
     *
     * @param string $id Service identifier or class/interface FQCN.
     * @param mixed $instance The instance this scope resolves the id to.
     * @param ScopeContext|null $scope Target scope, or null for the active one.
     * @return void
     */
    public function scopedInstance(string $id, mixed $instance, ?ScopeContext $scope = null): void
    {
        $targetId = $this->resolveAliasTarget($id);
        $scopeKey = $this->getEffectiveScopeKey($scope);

        $this->scopedKeys[$targetId] = true;
        $this->scopedInstances[$scopeKey][$targetId] = $instance;
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
     * Singletons live in $instances and a scope flush never removes them, so the
     * scoped walk below cannot reach them. A singleton that carries per-request
     * state — an open transaction, a per-response dedup map — implements
     * ResettableInterface precisely to be told "this request is over". Until now
     * that declaration was inert for singletons: only Connection::reset() ran,
     * and only because Application::handle() and QueueWorker each remembered to
     * call it by hand. Any other long-lived worker following the documented
     * pattern got no reset at all.
     *
     * Only for a concrete ScopeContext: the fallback bucket is not a request
     * boundary, and Application::handle() flushes both buckets, so counting it
     * here would reset every singleton twice per request.
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

        if ($scope === null) {
            return;
        }

        foreach ($this->instances as $instance) {
            if ($instance instanceof ResettableInterface) {
                $instance->reset();
            }
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