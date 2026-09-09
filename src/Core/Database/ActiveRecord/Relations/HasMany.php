<?php

declare(strict_types=1);

namespace Phue\Core\Database\ActiveRecord\Relations;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

/**
 * One-to-Many relation implementation.
 *
 * @package Phue\Core\Database\ActiveRecord\Relations
 */
class HasMany extends Relation
{
    /**
     * Apply base foreign key equality constraints for a single parent.
     *
     * @return void
     */
    protected function applyBaseConstraints(): void
    {
        $parentKeyValue = $this->parent->getAttribute($this->localKey);
        $this->query->where($this->foreignKey, '=', $parentKeyValue);
    }

    /**
     * Apply batch foreign key constraints using WHERE IN for eager loading.
     *
     * @param array<int, BaseActiveRecord> $models Array of parent model instances.
     * @return void
     */
    public function addEagerConstraints(array $models): void
    {
        $this->isEager = true;
        $this->query = $this->newRelatedQuery();
        $keys = $this->getKeys($models, $this->localKey);
        $this->query->whereIn($this->foreignKey, $keys);
    }

    /**
     * Match eager-loaded results back to parent models as array collections.
     *
     * @param array<int, BaseActiveRecord> $models Parent model instances.
     * @param array<int, BaseActiveRecord> $results Eager-loaded query results.
     * @param string $relation Name of relation to assign.
     * @return array<int, BaseActiveRecord>
     */
    public function match(array $models, array $results, string $relation): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $dictionary[$result->getAttribute($this->foreignKey)][] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);
            $model->setRelation($relation, $dictionary[$key] ?? []);
        }

        return $models;
    }

    /**
     * Execute relation query and fetch all matching records.
     *
     * @return array<int, BaseActiveRecord>
     */
    public function getResults(): mixed
    {
        return $this->getQuery()->get();
    }
}