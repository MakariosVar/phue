<?php

namespace Phue\Core\Database\ActiveRecord;

use Phue\Core\Database\Connection;
use Phue\Core\Database\QueryBuilder;

/**
 * Model-aware Query Builder returning hydrated Active Record instances.
 *
 * @package Phue\Core\Database\ActiveRecord
 */
class ActiveQuery extends QueryBuilder
{
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
     * Executes query and converts raw array rows into hydrated Model instances.
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

        return $models;
    }

    /**
     * Executes query and returns the first hydrated Model instance or null.
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
        return $modelClass::newFromBuilder($records[0]);
    }
}