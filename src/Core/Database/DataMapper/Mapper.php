<?php

declare(strict_types=1);

namespace Phue\Core\Database\DataMapper;

use ReflectionClass;
use ReflectionProperty;

/**
 * Class Mapper
 *
 * Handles the mapping between database associative arrays (snake_case)
 * and object domain entities (camelCase properties), managing hydration
 * and dehydration processes.
 *
 * @package Phue\Core\Database\DataMapper
 */
class Mapper
{
    /**
     * Hydrates a database row array into an Entity instance,
     * populating all accessible properties and registering the entity
     * with the Unit of Work Identity Map.
     *
     * @template T of Entity
     * @param array<string, mixed> $row Associative array representing a database row.
     * @param class-string<T> $entityClass Fully qualified class name of the target Entity.
     * @param UnitOfWork $uow Active Unit of Work instance for identity mapping.
     * @return T The hydrated domain entity instance.
     * @throws \ReflectionException If reflection fails on the entity class.
     */
    public function hydrate(array $row, string $entityClass, UnitOfWork $uow): object
    {
        $reflection = new ReflectionClass($entityClass);
        $entity = $reflection->newInstanceWithoutConstructor();

        $pkCol = $entityClass::primaryKey();
        $pkVal = $row[$pkCol] ?? null;

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE) as $property) {
            $propName = $property->getName();
            $snakeName = $this->toSnakeCase($propName);

            if (array_key_exists($snakeName, $row)) {
                $property->setValue($entity, $row[$snakeName]);
            }
        }

        if ($pkVal !== null) {
            $uow->registerManaged($entity, $pkVal, $this->dehydrate($entity));
        }

        return $entity;
    }

    /**
     * Dehydrates an Entity instance into an associative array
     * with snake_case keys ready for database persistence.
     *
     * @param object $entity The domain entity instance to dehydrate.
     * @return array<string, mixed> Associative array of column names and values.
     * @throws \ReflectionException If reflection fails on the entity object.
     */
    public function dehydrate(object $entity): array
    {
        $reflection = new ReflectionClass($entity);
        $data = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE) as $property) {
            if (!$property->isInitialized($entity)) {
                continue;
            }
            $propName = $property->getName();
            $snakeName = $this->toSnakeCase($propName);
            $data[$snakeName] = $property->getValue($entity);
        }

        return $data;
    }

    /**
     * Converts a camelCase string into snake_case.
     *
     * @param string $value The camelCase string to convert.
     * @return string The resulting snake_case string.
     */
    public function toSnakeCase(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    /**
     * Converts a snake_case string into camelCase.
     *
     * @param string $value The snake_case string to convert.
     * @return string The resulting camelCase string.
     */
    public function toCamelCase(string $value): string
    {
        return lcfirst((string) preg_replace_callback('/_([a-z])/', fn ($match) => strtoupper($match[1]), $value));
    }
}