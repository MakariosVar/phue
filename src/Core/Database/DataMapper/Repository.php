<?php

declare(strict_types=1);

namespace Phue\Core\Database\DataMapper;

use Phue\Core\Database\Connection;
use Phue\Core\Database\QueryBuilder;

/**
 * Class Repository
 *
 * Generic Data Mapper Repository providing query builder integration,
 * identity map lookup, hydration, and persistence queueing for entities.
 *
 * @template T of Entity
 * @package Phue\Core\Database\DataMapper
 */
class Repository
{
    /**
     * Repository constructor.
     *
     * @param Connection $connection Database connection wrapper.
     * @param UnitOfWork $uow Unit of Work tracking entity lifecycle and identity map.
     * @param Mapper $mapper Mapper handling entity hydration and dehydration.
     * @param class-string<T> $entityClass Fully qualified class name of the managed Entity.
     */
    public function __construct(
        protected Connection $connection,
        protected UnitOfWork $uow,
        protected Mapper $mapper,
        protected string $entityClass
    ) {}

    /**
     * Creates and returns a new QueryBuilder instance targeted at the entity's database table.
     *
     * @return QueryBuilder New query builder pre-configured with the table name.
     */
    public function query(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))
            ->table($this->entityClass::tableName());
    }

    /**
     * Finds an entity by its primary key, checking the Identity Map first before querying the database.
     *
     * @param int|string $id The primary key value to search for.
     * @return T|null The resolved entity instance or null if not found.
     * @throws \ReflectionException If reflection fails during hydration.
     */
    public function findById(int|string $id): ?object
    {
        $table = $this->entityClass::tableName();
        $pkCol = $this->entityClass::primaryKey();

        $cached = $this->uow->getFromIdentityMap($table, $id);
        if ($cached !== null) {
            /** @var T $cached */
            return $cached;
        }

        $row = $this->query()
            ->where($pkCol, '=', $id)
            ->first();

        if (!$row) {
            return null;
        }

        /** @var T */
        return $this->mapper->hydrate((array)$row, $this->entityClass, $this->uow);
    }

    /**
     * Retrieves all records for this entity from the database and hydrates them.
     *
     * @return array<int, T> Array of hydrated entity instances.
     * @throws \ReflectionException If reflection fails during hydration.
     */
    public function findAll(): array
    {
        $rows = $this->query()->get();
        $entities = [];

        foreach ($rows as $row) {
            $entities[] = $this->mapper->hydrate((array)$row, $this->entityClass, $this->uow);
        }

        return $entities;
    }

    /**
     * Registers a new entity with the Unit of Work to be persisted upon commit.
     *
     * @param Entity $entity The entity instance to persist.
     * @return void
     */
    public function persist(Entity $entity): void
    {
        $this->uow->registerNew($entity);
    }

    /**
     * Marks an entity with the Unit of Work to be removed from the database upon commit.
     *
     * @param Entity $entity The entity instance to remove.
     * @return void
     */
    public function remove(Entity $entity): void
    {
        $this->uow->registerRemoved($entity);
    }
}