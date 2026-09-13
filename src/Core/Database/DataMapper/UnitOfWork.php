<?php

declare(strict_types=1);

namespace Phue\Core\Database\DataMapper;

use Phue\Core\Database\Connection;
use Phue\Core\Database\QueryBuilder;

/**
 * Class UnitOfWork
 *
 * Manages the Identity Map, change/dirty tracking, and atomic persistence
 * of entities within a single database transaction context.
 *
 * @package Phue\Core\Database\DataMapper
 */
class UnitOfWork
{
    /**
     * Identity map tracking active domain instances keyed by "{table}:{pk}".
     *
     * @var array<string, object>
     */
    protected array $identityMap = [];

    /**
     * Snapshot of original entity states keyed by spl_object_id.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $originalData = [];

    /**
     * New entities scheduled for INSERT operations.
     *
     * @var array<int, object>
     */
    protected array $newEntities = [];

    /**
     * Removed entities scheduled for DELETE operations.
     *
     * @var array<int, object>
     */
    protected array $removedEntities = [];

    /**
     * UnitOfWork constructor.
     *
     * @param Connection $connection Database connection manager.
     * @param Mapper $mapper Entity-to-row mapper instance.
     */
    public function __construct(
        protected Connection $connection,
        protected Mapper $mapper
    ) {}

    /**
     * Registers an entity as managed in the Identity Map and stores its initial state snapshot.
     *
     * @param object $entity The entity instance to manage.
     * @param mixed $pkValue The primary key value of the entity.
     * @param array<string, mixed> $snapshot The initial attribute values of the entity.
     * @return void
     */
    public function registerManaged(object $entity, mixed $pkValue, array $snapshot): void
    {
        $table = $entity::tableName();
        $key = "{$table}:{$pkValue}";

        $this->identityMap[$key] = $entity;
        $this->originalData[spl_object_id($entity)] = $snapshot;
    }

    /**
     * Retrieves an entity from the Identity Map if it has already been loaded.
     *
     * @param string $table The database table name.
     * @param mixed $pkValue The primary key value to look up.
     * @return object|null The cached entity instance or null if not found.
     */
    public function getFromIdentityMap(string $table, mixed $pkValue): ?object
    {
        $key = "{$table}:{$pkValue}";
        return $this->identityMap[$key] ?? null;
    }

    /**
     * Schedules a new transient entity for insertion upon commit.
     *
     * @param object $entity The new entity instance.
     * @return void
     */
    public function registerNew(object $entity): void
    {
        $this->newEntities[spl_object_id($entity)] = $entity;
    }

    /**
     * Schedules a managed entity for deletion upon commit.
     *
     * @param object $entity The entity instance to remove.
     * @return void
     */
    public function registerRemoved(object $entity): void
    {
        $id = spl_object_id($entity);
        if (isset($this->newEntities[$id])) {
            unset($this->newEntities[$id]);
            return;
        }

        $this->removedEntities[$id] = $entity;
    }

    /**
     * Commits all tracked changes (inserts, dirty updates, and deletes)
     * atomically within a single database transaction.
     *
     * @return void
     * @throws \Throwable If any query fails, triggering a transaction rollback.
     */
    public function commit(): void
    {
        $this->connection->beginTransaction();

        try {
            // 1. Process inserts
            foreach ($this->newEntities as $entity) {
                $table = $entity::tableName();
                $pkCol = $entity::primaryKey();
                $data = $this->mapper->dehydrate($entity);

                $qb = new QueryBuilder($this->connection);
                $qb->table($table)->insert($data);

                $pkCamel = $this->mapper->toCamelCase($pkCol);
                $pkVal = $data[$pkCol] ?? null;

                // Only fetch lastInsertId() if the PK column is missing or empty in dehydrated data
                if ($pkVal === null || $pkVal === '') {
                    $lastId = $this->connection->lastInsertId();
                    if ($lastId && property_exists($entity, $pkCamel)) {
                        $prop = (new \ReflectionClass($entity))->getProperty($pkCamel);
                        $prop->setValue($entity, is_numeric($lastId) ? (int)$lastId : $lastId);
                        $data = $this->mapper->dehydrate($entity);
                        $pkVal = $data[$pkCol] ?? null;
                    }
                }

                $this->registerManaged($entity, $pkVal, $data);
            }
            $this->newEntities = [];

            // 2. Process updates (dirty-tracking)
            foreach ($this->identityMap as $entity) {
                $objectId = spl_object_id($entity);
                if (isset($this->removedEntities[$objectId])) {
                    continue;
                }

                $currentData = $this->mapper->dehydrate($entity);
                $originalData = $this->originalData[$objectId] ?? [];

                $pkCol = $entity::primaryKey();
                $pkVal = $currentData[$pkCol] ?? null;
                $changes = array_diff_assoc($currentData, $originalData);

                if (!empty($changes) && $pkVal !== null) {
                    $qb = new QueryBuilder($this->connection);
                    $qb->table($entity::tableName())
                        ->where($pkCol, '=', $pkVal)
                        ->update($changes);

                    $this->originalData[$objectId] = $currentData;
                }
            }

            // 3. Process deletes
            foreach ($this->removedEntities as $entity) {
                $pkCol = $entity::primaryKey();
                $data = $this->mapper->dehydrate($entity);
                $pkVal = $data[$pkCol] ?? null;

                if ($pkVal !== null) {
                    $qb = new QueryBuilder($this->connection);
                    $qb->table($entity::tableName())
                        ->where($pkCol, '=', $pkVal)
                        ->delete();

                    $table = $entity::tableName();
                    unset($this->identityMap["{$table}:{$pkVal}"]);
                }
            }
            $this->removedEntities = [];

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}