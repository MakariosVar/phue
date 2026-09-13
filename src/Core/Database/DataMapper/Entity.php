<?php

declare(strict_types=1);

namespace Phue\Core\Database\DataMapper;

/**
 * Class Entity
 *
 * Base abstract class for domain entities in the Data Mapper layer.
 * Concrete entities must implement tableName() and may override primaryKey().
 *
 * @package Phue\Core\Database\DataMapper
 */
abstract class Entity
{
    /**
     * Returns the database table name associated with this entity.
     *
     * @return string The database table name.
     */
    abstract public static function tableName(): string;

    /**
     * Returns the primary key column name for this entity.
     * Defaults to 'id'.
     *
     * @return string The primary key column name.
     */
    public static function primaryKey(): string
    {
        return 'id';
    }
}