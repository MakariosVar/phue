<?php

declare(strict_types=1);

namespace Phue\Core\Database\ActiveRecord\Relations;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

/**
 * One-to-One relation implementation.
 *
 * @package Phue\Core\Database\ActiveRecord\Relations
 */
class HasOne extends Relation
{
    /**
     * Apply base foreign key equality constraints for a single parent model.
     *
     * @return void
     */
    protected function applyBaseConstraints(): void
    {
        $parentKeyValue = $this->parent->getAttribute($this->localKey);
        $this->query->where($this->foreignKey, '=', $parentKeyValue);
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
        $keys = $this->getKeys($models, $this->localKey);
        $this->query->whereIn($this->foreignKey, $keys);
    }

    /**
     * Match eager-loaded results back to parent models, taking the first match per parent key.
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
            $key = $result->getAttribute($this->foreignKey);
            if (!isset($dictionary[$key])) {
                $dictionary[$key] = $result;
            }
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);
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