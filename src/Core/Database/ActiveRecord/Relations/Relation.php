<?php

declare(strict_types=1);

namespace Phue\Core\Database\ActiveRecord\Relations;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\ActiveRecord\ActiveQuery;

/**
 * Abstract Base Relation class connecting ActiveRecord models.
 *
 * @package Phue\Core\Database\ActiveRecord\Relations
 */
abstract class Relation
{
    /**
     * Parent model instance owning the relation.
     */
    protected BaseActiveRecord $parent;

    /**
     * Target ActiveRecord class FQCN.
     */
    protected string $relatedClass;

    /**
     * Database foreign key column name.
     */
    protected string $foreignKey;

    /**
     * Database local key column name.
     */
    protected string $localKey;

    /**
     * Lazy-instantiated ActiveQuery instance maintaining state.
     */
    protected ?ActiveQuery $query = null;

    /**
     * Indicates whether the query constraints were set up for eager loading.
     */
    protected bool $isEager = false;

    /**
     * Initialize relation definition properties.
     *
     * @param BaseActiveRecord $parent Parent model instance.
     * @param string $relatedClass Target model class FQCN.
     * @param string $foreignKey Foreign key column identifier.
     * @param string $localKey Local key column identifier.
     */
    public function __construct(BaseActiveRecord $parent, string $relatedClass, string $foreignKey, string $localKey)
    {
        $this->parent = $parent;
        $this->relatedClass = $relatedClass;
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;
    }

    /**
     * Get the target ActiveRecord class FQCN this relation points at.
     *
     * Exposed so consumers (schema tooling, inspectors) can describe a relation
     * without reaching into protected state or guessing from the method name.
     *
     * @return string
     */
    public function getRelatedClass(): string
    {
        return $this->relatedClass;
    }

    /**
     * Get the foreign key column name backing this relation.
     *
     * @return string
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the local key column name on the related model this relation joins against.
     *
     * @return string
     */
    public function getLocalKey(): string
    {
        return $this->localKey;
    }

    /**
     * Get or initialize the relation query instance with base parent constraints applied.
     *
     * @return ActiveQuery
     */
    public function getQuery(): ActiveQuery
    {
        if ($this->query === null) {
            $this->query = $this->newRelatedQuery();
            $this->applyBaseConstraints();
        }

        return $this->query;
    }

    /**
     * Create a new query instance for the target related model.
     *
     * @return ActiveQuery
     */
    protected function newRelatedQuery(): ActiveQuery
    {
        /** @var BaseActiveRecord $instance */
        $instance = new $this->relatedClass();
        return $instance::query();
    }

    /**
     * Apply default foreign key constraints for single model access.
     *
     * @return void
     */
    abstract protected function applyBaseConstraints(): void;

    /**
     * Prepare relation query for eager loading a batch of models without adding LIMIT constraints.
     *
     * @param array<int, BaseActiveRecord> $models List of parent models being loaded.
     * @return void
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Match eager-loaded results back to their parent models and set the relation attribute.
     *
     * @param array<int, BaseActiveRecord> $models Parent model instances.
     * @param array<int, BaseActiveRecord> $results Eager-loaded query results.
     * @param string $relation Name of the relation attribute to set.
     * @return array<int, BaseActiveRecord> Updated parent model instances.
     */
    abstract public function match(array $models, array $results, string $relation): array;

    /**
     * Execute relation query and get hydrated results (used during lazy loading).
     *
     * @return mixed
     */
    abstract public function getResults(): mixed;

    /**
     * Get array of foreign/local key values from parent models for batch querying.
     *
     * @param array<int, BaseActiveRecord> $models List of models.
     * @param string $key Property key name to retrieve.
     * @return array<int, mixed> Unique non-null key values.
     */
    protected function getKeys(array $models, string $key): array
    {
        return array_values(array_filter(array_unique(array_map(
            fn (BaseActiveRecord $model) => $model->getAttribute($key),
            $models
        )), fn ($value) => $value !== null));
    }

    /**
     * Forward dynamic method calls directly to the relation query to preserve state on chaining.
     *
     * @param string $method Method name to invoke.
     * @param array<int, mixed> $arguments Method parameters.
     * @return mixed
     */
    public function __call(string $method, array $arguments): mixed
    {
        $result = $this->getQuery()->$method(...$arguments);

        if ($result === $this->query) {
            return $this;
        }

        return $result;
    }
}