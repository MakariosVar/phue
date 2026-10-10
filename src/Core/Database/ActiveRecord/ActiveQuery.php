<?php

declare(strict_types=1);

namespace Phue\Core\Database\ActiveRecord;

use Phue\Core\Database\Connection;
use Phue\Core\Database\QueryBuilder;
use Phue\Core\Database\ActiveRecord\Relations\Relation;

/**
 * Model-aware Query Builder returning hydrated Active Record instances and supporting eager loading.
 *
 * Automatically applies a global scope to exclude soft-deleted records for models
 * that use the #[SoftDelete] attribute. Use withTrashed() to include them, or
 * onlyTrashed() to retrieve only soft-deleted records.
 *
 * @package Phue\Core\Database\ActiveRecord
 */
class ActiveQuery extends QueryBuilder
{
    /**
     * Relations specified for eager loading.
     *
     * @var array<int, string>
     */
    protected array $with = [];

    /**
     * Whether to include soft-deleted records in results.
     *
     * @var bool
     */
    protected bool $withTrashed = false;

    /**
     * Whether to retrieve only soft-deleted records.
     *
     * @var bool
     */
    protected bool $onlyTrashed = false;

    /**
     * The soft delete column name for the model, or null if not a soft-delete model.
     *
     * @var string|null
     */
    protected ?string $softDeleteColumn = null;

    /**
     * @param Connection $connection
     * @param class-string<BaseActiveRecord> $modelClass
     */
    public function __construct(
        Connection $connection,
        protected string $modelClass
    ) {
        parent::__construct($connection);

        /** @var BaseActiveRecord $instance */
        $instance = new $this->modelClass();
        $this->table($instance->getTable());

        // Cache soft delete column for scope application
        if ($instance->hasSoftDelete()) {
            $this->softDeleteColumn = $instance->getSoftDeleteColumn();
            $this->applySoftDeleteScope();
        }
    }

    /**
     * Applies the soft delete global scope to the query.
     *
     * Adds WHERE deleted_at IS NULL unless withTrashed() or onlyTrashed() is active.
     *
     * @return void
     */
    private function applySoftDeleteScope(): void
    {
        if ($this->softDeleteColumn === null) {
            return;
        }

        $quotedColumn = $this->quoteIdentifier($this->softDeleteColumn);

        if ($this->withTrashed) {
            // No scope - include all records
            return;
        }

        if ($this->onlyTrashed) {
            // Only soft-deleted records
            $this->whereClauses[] = [
                'boolean' => 'AND',
                'sql'     => "{$quotedColumn} IS NOT NULL",
            ];
            return;
        }

        // Default: exclude soft-deleted records
        $this->whereClauses[] = [
            'boolean' => 'AND',
            'sql'     => "{$quotedColumn} IS NULL",
        ];
    }

    /**
     * Removes any existing soft delete WHERE clause from the query.
     *
     * @return void
     */
    private function removeSoftDeleteWhereClause(): void
    {
        if ($this->softDeleteColumn === null) {
            return;
        }

        $quotedColumn = $this->quoteIdentifier($this->softDeleteColumn);

        $this->whereClauses = array_filter($this->whereClauses, function ($clause) use ($quotedColumn) {
            return !str_contains($clause['sql'], "{$quotedColumn} IS NULL")
                && !str_contains($clause['sql'], "{$quotedColumn} IS NOT NULL");
        });

        $this->whereClauses = array_values($this->whereClauses);
    }

    /**
     * Sets relations to eager load.
     *
     * @param string ...$relations
     * @return $this
     */
    public function with(string ...$relations): static
    {
        $this->with = array_merge($this->with, $relations);
        return $this;
    }

    /**
     * Includes soft-deleted records in the query results.
     *
     * Removes the global scope that excludes soft-deleted records.
     *
     * @return $this
     */
    public function withTrashed(): static
    {
        $this->withTrashed = true;
        $this->onlyTrashed = false;

        $this->removeSoftDeleteWhereClause();
        return $this;
    }

    /**
     * Retrieves only soft-deleted records.
     *
     * Modifies the global scope to only return records where the deletion
     * timestamp column is not null.
     *
     * @return $this
     */
    public function onlyTrashed(): static
    {
        $this->withTrashed = false;
        $this->onlyTrashed = true;

        $this->removeSoftDeleteWhereClause();
        $this->applySoftDeleteScope();
        return $this;
    }

    /**
     * Executes query and converts raw array rows into hydrated Model instances.
     * Eagerly loads defined `with()` relations.
     *
     * Re-applies the soft delete scope at execution time to handle chained calls.
     *
     * @return array<int, BaseActiveRecord>
     */
    public function get(): array
    {
        $this->applySoftDeleteScope();
        $records = parent::get();
        $models = [];

        foreach ($records as $record) {
            if (is_array($record)) {
                /** @var BaseActiveRecord $modelClass */
                $modelClass = $this->modelClass;
                $models[] = $modelClass::newFromBuilder($record);
            }
        }

        if (!empty($models) && !empty($this->with)) {
            $models = $this->eagerLoadRelations($models);
        }

        return $models;
    }

    /**
     * Executes query and returns the first hydrated Model instance or null.
     * Eagerly loads defined `with()` relations.
     *
     * Re-applies the soft delete scope at execution time to handle chained calls.
     *
     * @return BaseActiveRecord|null
     */
    public function first(): ?BaseActiveRecord
    {
        $this->limit(1);
        $this->applySoftDeleteScope();
        $records = parent::get();

        if (empty($records) || !is_array($records[0])) {
            return null;
        }

        /** @var BaseActiveRecord $modelClass */
        $modelClass = $this->modelClass;
        $model = $modelClass::newFromBuilder($records[0]);

        if (!empty($this->with)) {
            $models = $this->eagerLoadRelations([$model]);
            return $models[0];
        }

        return $model;
    }

    /**
     * Returns all records matching the query.
     *
     * Alias for get() for API compatibility with BaseActiveRecord::all().
     *
     * @return array<int, BaseActiveRecord>
     */
    public function all(): array
    {
        return $this->get();
    }

    /**
     * Finds a single model record by primary key value.
     *
     * Re-applies the soft delete scope at execution time to handle chained calls.
     *
     * @param mixed $id Primary key value.
     * @return BaseActiveRecord|null
     */
    public function find(mixed $id): ?BaseActiveRecord
    {
        $instance = new $this->modelClass();
        $this->where($instance->getPrimaryKey(), '=', $id);
        return $this->first();
    }

    /**
     * Performs eager loading logic across a collection of hydrated models.
     *
     * @param array<int, BaseActiveRecord> $models
     * @return array<int, BaseActiveRecord>
     */
    protected function eagerLoadRelations(array $models): array
    {
        foreach ($this->with as $name) {
            $dummyModel = new $this->modelClass();

            if (!method_exists($dummyModel, $name)) {
                continue;
            }

            /** @var Relation $relation */
            $relation = $dummyModel->$name();

            $relation->addEagerConstraints($models);
            $results = $relation->getResults();

            $relation->match($models, $results, $name);
        }

        return $models;
    }
}