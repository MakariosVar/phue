<?php

namespace Phue\Core;

use Exception;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The main app container with Autowiring support
 */
class Container
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]); 
    }

    public function get(string $id): mixed
    {
        // 1. Return cached instance if already resolved
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // 2. Resolve via registered factory closure
        if (isset($this->factories[$id])) {
            $this->instances[$id] = $this->factories[$id]($this);
            return $this->instances[$id];
        }

        // 3. Fallback: Try Autowiring via Reflection if class exists
        if (class_exists($id)) {
            $this->instances[$id] = $this->autowire($id);
            return $this->instances[$id];
        }

        $this->missingFactory($id);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || class_exists($id);
    }

    /**
     * Instantiates a class by recursively resolving its constructor dependencies.
     */
    private function autowire(string $id): object
    {
        $reflection = new ReflectionClass($id);

        if (!$reflection->isInstantiable()) {
            throw new Exception("Class '{$id}' is not instantiable (interface, abstract class, or private constructor).");
        }

        $constructor = $reflection->getConstructor();

        // If no constructor exists, simply instantiate it directly
        if ($constructor === null) {
            return new $id();
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            // Check if the parameter has a valid class/interface type hint
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }

                throw new Exception(
                    "Cannot autowire parameter '{$parameter->getName()}' in '{$id}': Missing class type hint or default value."
                );
            }

            // Recursively resolve the dependency out of the container
            $dependencies[] = $this->get($type->getName());
        }

        return $reflection->newInstanceArgs($dependencies);
    }

    private function missingFactory(string $id): never
    {
        throw new Exception("Missing factory or class for service: '{$id}'");
    }
}