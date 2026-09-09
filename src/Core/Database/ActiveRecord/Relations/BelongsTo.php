<?php

declare(strict_types=1);

namespace Phue\Core\Database\ActiveRecord\Relations;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

/**
 * Inverse One-to-One / One-to-Many relation implementation.
 *
 * @package Phue\Core\Database\ActiveRecord\Relations
 */
class BelongsTo extends Relation
{
    /**
     * Apply base local key equality constraints for a child parent.
     *
     * @return void
     */
    protected function applyBaseConstraints(): void
    {
        $foreignKeyValue = $this->parent->getAttribute($this->foreignKey);
        $this->query->where($this->localKey, '=', $foreignKeyValue);
    }

    /**
     * Apply batch foreign key constraints using WHERE IN for eager loading without applying LIMIT 1.
     *
     * @param array<int, BaseActiveRecord> $models Array of parent model instances.
     * @return void
     */
    public function addEagerConstraints(array $models): void
    {
        $this->isEager = true;
        $this->query = $this->newRelatedQuery();
        $keys = $this->getKeys($models, $this->foreignKey);
        $this->query->whereIn($this->localKey, $keys);
    }

    /**
     * Match eager-loaded results back to child models using the parent model's local key.
     *
     * @param array<int, BaseActiveRecord> $models Child model instances.
     * @param array<int, BaseActiveRecord> $results Eager-loaded query results.
     * @param string $relation Name of relation to assign.
     * @return array<int, BaseActiveRecord>
     */
    public function match(array $models, array $results, string $relation): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $dictionary[$result->getAttribute($this->localKey)] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->foreignKey);
            $model->setRelation($relation, $dictionary[$key] ?? null);
        }

        return $models;
    }

    /**
     * Execute relation query.
     *
     * During lazy loading, it applies LIMIT 1. During eager loading, get() is used to fetch all candidates.
     *
     * @return BaseActiveRecord|array<int, BaseActiveRecord>|null
     */
    public function getResults(): mixed
    {
        if ($this->isEager) {
            return $this->getQuery()->get();
        }

        return $this->getQuery()->first();
    }
}