<?php

namespace Phue\Core\Database\ActiveRecord;

use JsonSerializable;
use ReflectionClass;
use RuntimeException;
use Phue\Core\Database\Connection;
use Phue\Core\Database\QueryBuilder;

/**
 * Abstract Base Active Record Model providing ORM and persistence logic.
 *
 * @package Phue\Core\Database\ActiveRecord
 */
abstract class BaseActiveRecord implements JsonSerializable
{
    /**
     * Database connection manager instance.
     */
    protected static ?Connection $dbConnection = null;

    /**
     * Database table associated with the model.
     */
    protected string $table = '';

    /**
     * Primary key column name.
     */
    protected string $primaryKey = 'id';

    /**
     * Current attributes array representing database columns.
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * Original attributes snapshot for dirty state tracking.
     *
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * Indicates if the model instance exists in the database.
     */
    protected bool $exists = false;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * Sets the global database connection for Active Record models.
     */
    public static function setConnection(Connection $connection): void
    {
        static::$dbConnection = $connection;
    }

    /**
     * Retrieves the active database connection manager.
     */
    public static function getConnection(): Connection
    {
        if (static::$dbConnection === null) {
            throw new RuntimeException('No database connection set for Active Record. Call BaseActiveRecord::setConnection() first.');
        }

        return static::$dbConnection;
    }

    /**
     * Resolves the table name for the model.
     */
    public function getTable(): string
    {
        if (!empty($this->table)) {
            return $this->table;
        }

        $shortName = (new ReflectionClass(static::class))->getShortName();
        return strtolower($shortName) . 's';
    }

    /**
     * Returns the primary key column name.
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * Fills the model with an array of attributes.
     *
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->__set($key, $value);
        }

        return $this;
    }

    /**
     * Magic setter for model attributes.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * Magic getter for model attributes.
     */
    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Magic isset check for model attributes.
     */
    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    /**
     * Magic unset for model attributes.
     */
    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
    }

    /**
     * Creates a new ActiveQuery instance initialized for the current model class.
     */
    public static function query(): ActiveQuery
    {
        return new ActiveQuery(static::getConnection(), static::class);
    }

    /**
     * Magic static call handler to forward dynamic queries directly to ActiveQuery.
     * Allows calls like User::where('status', 'active')->get()
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return call_user_func_array([static::query(), $method], $arguments);
    }

    /**
     * Finds a single model record by primary key value.
     */
    public static function find(mixed $id): ?static
    {
        $instance = new static();
        
        /** @var static|null $result */
        $result = static::query()
            ->where($instance->getPrimaryKey(), $id)
            ->first();

        return $result;
    }

    /**
     * Retrieves all records for the model.
     *
     * @return array<int, static>
     */
    public static function all(): array
    {
        /** @var array<int, static> $results */
        $results = static::query()->get();
        
        return $results;
    }

    /**
     * Creates and persists a new model instance directly.
     *
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /**
     * Instantiates a hydrated model instance directly from database row.
     *
     * @param array<string, mixed> $attributes
     */
    public static function newFromBuilder(array $attributes): static
    {
        $model = new static();
        $model->attributes = $attributes;
        $model->original = $attributes;
        $model->exists = true;

        return $model;
    }

    /**
     * Calculates array of changed/dirty attributes compared to original state.
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Persists the current model state into the database (INSERT or UPDATE).
     */
    public function save(): bool
    {
        $builder = static::query();

        if ($this->exists) {
            $dirty = $this->getDirty();

            if (empty($dirty)) {
                return true;
            }

            $affected = $builder
                ->where($this->getPrimaryKey(), $this->attributes[$this->getPrimaryKey()])
                ->update($dirty);

            if ($affected > 0) {
                $this->original = $this->attributes;
                return true;
            }

            return false;
        }

        // Handle INSERT
        $inserted = $builder->insert($this->attributes);

        if ($inserted) {
            $id = static::getConnection()->lastInsertId();
            if ($id !== false && $id !== '0') {
                $this->attributes[$this->getPrimaryKey()] = is_numeric($id) ? (int) $id : $id;
            }

            $this->exists = true;
            $this->original = $this->attributes;
            return true;
        }

        return false;
    }

    /**
     * Deletes the model from the database.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $affected = static::query()
            ->where($this->getPrimaryKey(), $this->attributes[$this->getPrimaryKey()])
            ->delete();

        if ($affected > 0) {
            $this->exists = false;
            return true;
        }

        return false;
    }

    /**
     * Converts model attributes to array format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * {@inheritdoc}
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}