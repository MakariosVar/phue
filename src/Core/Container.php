<?php

namespace Phue\Core;

use Exception;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Main application Container handling dependency injection,
 * singleton instance caching, and Reflection-based autowiring.
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
     * Cached resolved service instances (singletons).
     * 
     * @var array<string, mixed> 
     */
    private array $instances = [];

    /**
     * Bind a service factory closure to the container.
     *
     * @param string $id Unique identifier or FQCN (Fully Qualified Class Name)
     * @param callable $factory Closure returning the resolved instance
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        
        // Bust any previously cached instance if a factory is redefined
        unset($this->instances[$id]); 
    }

    /**
     * Resolve a service or class instance from the container.
     *
     * @param string $id Service identifier or target class name
     * @return mixed Resolved instance or object
     * @throws Exception If service cannot be resolved or autowired
     */
    public function get(string $id): mixed
    {
        // 1. Return cached instance if already instantiated (Singleton pattern)
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // 2. Resolve instance via explicitly bound factory closure
        if (isset($this->factories[$id])) {
            $this->instances[$id] = $this->factories[$id]($this);
            return $this->instances[$id];
        }

        // 3. Fallback: Attempt zero-config resolution via Reflection Autowiring
        if (class_exists($id)) {
            $this->instances[$id] = $this->autowire($id);
            return $this->instances[$id];
        }

        // 4. Trigger failure handler if resolution path yields nothing
        $this->missingFactory($id);
    }

    /**
     * Check whether a service factory exists or if a class can be autowired.
     *
     * @param string $id Service identifier or class name
     */
    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || class_exists($id);
    }

    /**
     * Instantiate a target class using PHP Reflection to resolve its constructor arguments.
     *
     * @param string $id FQCN of the target class
     * @return object Instantiated class instance
     * @throws Exception If class cannot be instantiated or dependencies are unresolvable
     */
    private function autowire(string $id): object
    {
        $reflection = new ReflectionClass($id);

        // Interfaces, Abstract Classes, and private constructors cannot be instantiated directly
        if (!$reflection->isInstantiable()) {
            throw new Exception("Class '{$id}' is not instantiable (interface, abstract class, or private constructor).");
        }

        $constructor = $reflection->getConstructor();

        // If no __construct method exists, instantiate directly without arguments
        if ($constructor === null) {
            return new $id();
        }

        $dependencies = [];

        // Reflect over constructor parameters to resolve each argument recursively
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            // Handle non-class parameters (scalars like int/string or untyped parameters)
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                // Fall back to default argument value if defined in constructor
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }

                throw new Exception(
                    "Cannot autowire parameter '{$parameter->getName()}' in '{$id}': Missing class type hint or default value."
                );
            }

            // Recursively resolve object dependencies out of the container
            $dependencies[] = $this->get($type->getName());
        }

        // Create new instance passing the resolved dependency array to the constructor
        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * Exception helper thrown when a requested service ID is unresolvable.
     *
     * @param string $id Requested service ID
     * @throws Exception
     */
    private function missingFactory(string $id): never
    {
        throw new Exception("Missing factory or class for service: '{$id}'");
    }
}