<?php

namespace Phue\Core\Database\ActiveRecord;

use Phue\Core\Database\Connection;
use Phue\Core\Database\QueryBuilder;
use Phue\Core\Database\ActiveRecord\Relations\Relation;

/**
 * Model-aware Query Builder returning hydrated Active Record instances and supporting eager loading.
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
     * Executes query and converts raw array rows into hydrated Model instances.
     * Eagerly loads defined `with()` relations.
     *
     * @return array<int, BaseActiveRecord>
     */
    public function get(): array
    {
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
     * @return BaseActiveRecord|null
     */
    public function first(): ?BaseActiveRecord
    {
        $this->limit(1);
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