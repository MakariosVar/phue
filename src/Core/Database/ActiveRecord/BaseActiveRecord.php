<?php

declare(strict_types=1);

namespace Phue\Core\Database\ActiveRecord;

use JsonSerializable;
use ReflectionClass;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;
use Phue\Core\Database\Connection;
use Phue\Core\Database\ActiveRecord\ActiveQuery;
use Phue\Core\Database\ActiveRecord\Relations\Relation;
use Phue\Core\Database\ActiveRecord\Relations\HasMany;
use Phue\Core\Database\ActiveRecord\Relations\HasOne;
use Phue\Core\Database\ActiveRecord\Relations\BelongsTo;

/**
 * Abstract Base Active Record Model providing ORM, persistence, relationship logic,
 * and automatic declared typed property hydration.
 *
 * @package Phue\Core\Database\ActiveRecord
 */
abstract class BaseActiveRecord implements JsonSerializable
{
    /**
     * Database connection manager instance.
     *
     * @var Connection|null
     */
    protected static ?Connection $dbConnection = null;

    /**
     * Internal reflection cache for class declared properties.
     *
     * @var array<string, array<string, ReflectionProperty>>
     */
    protected static array $reflectionPropertyCache = [];

    /**
     * Database table associated with the model.
     *
     * @var string
     */
    protected string $table = '';

    /**
     * Primary key column name.
     *
     * @var string
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
     * Validation errors store.
     *
     * @var array<string, mixed>
     */
    protected array $errors = [];

    /**
     * Cached loaded relations for the instance.
     *
     * @var array<string, mixed>
     */
    protected array $relations = [];

    /**
     * Indicates if the model instance exists in the database.
     *
     * @var bool
     */
    protected bool $exists = false;

    /**
     * BaseActiveRecord constructor.
     *
     * @param array<string, mixed> $attributes Initial model attributes.
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * Sets the global database connection for Active Record models.
     *
     * @param Connection $connection Database connection instance.
     * @return void
     */
    public static function setConnection(Connection $connection): void
    {
        static::$dbConnection = $connection;
    }

    /**
     * Retrieves the active database connection manager.
     *
     * @throws RuntimeException If no connection has been set.
     * @return Connection
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
     *
     * @return string
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
     *
     * @return string
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * Fills the model with an array of attributes and hydrates declared properties.
     *
     * @param array<string, mixed> $attributes Key-value list of attributes.
     * @return static
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * Retrieve a specific attribute value from the model.
     *
     * @param string $key Attribute column key.
     * @param mixed $default Fallback value if key is not set.
     * @return mixed
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    /**
     * Set a specific attribute value on the model and hydrate declared typed property if present.
     *
     * @param string $key Attribute column key.
     * @param mixed $value Value to assign.
     * @return static
     */
    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;
        $this->hydrateDeclaredProperty($key, $value);

        return $this;
    }

    /**
     * Check if a specific attribute exists on the model.
     *
     * @param string $key Attribute column key.
     * @return bool
     */
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Magic setter for model attributes.
     *
     * @param string $name Attribute name.
     * @param mixed $value Value to assign.
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    /**
     * Magic getter to resolve attributes and dynamic model relation properties.
     *
     * @param string $key Property key name.
     * @return mixed
     */
    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        if (method_exists($this, $key)) {
            $relation = $this->$key();

            if ($relation instanceof Relation) {
                $result = $relation->getResults();
                $this->relations[$key] = $result;
                return $result;
            }
        }

        return null;
    }

    /**
     * Magic isset check for model attributes or loaded relations.
     *
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) || isset($this->relations[$name]);
    }

    /**
     * Magic unset for model attributes or cached relations.
     *
     * @param string $name
     * @return void
     */
    public function __unset(string $name): void
    {
        unset($this->attributes[$name], $this->relations[$name]);
    }

    /**
     * Defines a HasMany relationship.
     *
     * @param class-string<BaseActiveRecord> $related Target model class FQCN.
     * @param string|null $foreignKey Foreign key on target table.
     * @param string|null $localKey Local key on parent table.
     * @return HasMany
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey = $localKey ?? $this->getPrimaryKey();

        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * Defines a HasOne relationship.
     *
     * @param class-string<BaseActiveRecord> $related Target model class FQCN.
     * @param string|null $foreignKey Foreign key on target table.
     * @param string|null $localKey Local key on parent table.
     * @return HasOne
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey = $localKey ?? $this->getPrimaryKey();

        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * Defines a BelongsTo relationship.
     *
     * @param class-string<BaseActiveRecord> $related Target model class FQCN.
     * @param string|null $foreignKey Foreign key on child model.
     * @param string|null $ownerKey Primary key on parent model.
     * @return BelongsTo
     */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        /** @var BaseActiveRecord $relatedInstance */
        $relatedInstance = new $related();
        $foreignKey = $foreignKey ?? $relatedInstance->getForeignKey();
        $ownerKey = $ownerKey ?? $relatedInstance->getPrimaryKey();

        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * Returns the default foreign key name for the model.
     *
     * @return string
     */
    public function getForeignKey(): string
    {
        $shortName = (new ReflectionClass(static::class))->getShortName();
        return strtolower($shortName) . '_id';
    }

    /**
     * Retrieves a cached relation result by name.
     *
     * @param string $name Relation name.
     * @param mixed $default Fallback value if relation is not loaded.
     * @return mixed
     */
    public function getRelation(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->relations) ? $this->relations[$name] : $default;
    }

    /**
     * Sets a relation result manually in the local relation cache.
     *
     * @param string $name Relation name.
     * @param mixed $value Loaded relation model(s) or null.
     * @return static
     */
    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;
        return $this;
    }

    /**
     * Check if a specific relation has been eager or lazy loaded.
     *
     * @param string $relation Relation name.
     * @return bool
     */
    public function relationLoaded(string $relation): bool
    {
        return array_key_exists($relation, $this->relations);
    }

    /**
     * Eagerly loads specified relations onto the model instance.
     *
     * @param string ...$relations Names of relations to load.
     * @return static
     */
    public function load(string ...$relations): static
    {
        foreach ($relations as $relationName) {
            if (method_exists($this, $relationName)) {
                $relation = $this->$relationName();
                if ($relation instanceof Relation) {
                    $this->setRelation($relationName, $relation->getResults());
                }
            }
        }

        return $this;
    }

    /**
     * Returns validation errors array.
     *
     * @return array<string, mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Sets validation errors manually if needed.
     *
     * @param array<string, mixed> $errors
     * @return void
     */
    public function setErrors(array $errors): void
    {
        $this->errors = $errors;
    }

    /**
     * Creates a new ActiveQuery instance initialized for the current model class.
     *
     * @return ActiveQuery
     */
    public static function query(): ActiveQuery
    {
        return new ActiveQuery(static::getConnection(), static::class);
    }

    /**
     * Forward static calls to ActiveQuery.
     *
     * @param string $method Method name.
     * @param array<int, mixed> $arguments Method parameters.
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return call_user_func_array([static::query(), $method], $arguments);
    }

    /**
     * Finds a single model record by primary key value.
     *
     * @param mixed $id Primary key value.
     * @return static|null
     */
    public static function find(mixed $id): ?static
    {
        $instance = new static();

        /** @var static|null $result */
        $result = static::query()
            ->where($instance->getPrimaryKey(), '=', $id)
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
     * @param array<string, mixed> $attributes Attributes to assign.
     * @return static
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
     * @param array<string, mixed> $attributes Raw column database values.
     * @return static
     */
    public static function newFromBuilder(array $attributes): static
    {
        $model = new static();
        $model->attributes = $attributes;
        $model->original = $attributes;
        $model->exists = true;

        $model->hydrateDeclaredProperties($attributes);

        return $model;
    }

    /**
     * Hydrates explicit declared typed properties from attributes.
     *
     * @param array<string, mixed> $attributes
     * @return void
     */
    protected function hydrateDeclaredProperties(array $attributes): void
    {
        $declaredProps = static::getDeclaredProperties();

        foreach ($attributes as $key => $value) {
            if (!isset($declaredProps[$key])) {
                continue;
            }

            $property = $declaredProps[$key];

            if ($value === null && $property->hasType() && !$property->getType()?->allowsNull()) {
                continue;
            }

            $property->setValue($this, $value);
        }
    }

    /**
     * Hydrates a single declared typed property if it exists on the class.
     *
     * @param string $key Property name.
     * @param mixed $value Value to assign.
     * @return void
     */
    protected function hydrateDeclaredProperty(string $key, mixed $value): void
    {
        $declaredProps = static::getDeclaredProperties();

        if (!isset($declaredProps[$key])) {
            return;
        }

        $property = $declaredProps[$key];

        if ($value === null && $property->hasType() && !$property->getType()?->allowsNull()) {
            return;
        }

        $property->setValue($this, $value);
    }

    /**
     * Returns cached reflection property map for the concrete model class.
     *
     * @return array<string, ReflectionProperty>
     */
    protected static function getDeclaredProperties(): array
    {
        $class = static::class;

        if (isset(self::$reflectionPropertyCache[$class])) {
            return self::$reflectionPropertyCache[$class];
        }

        $refClass = new ReflectionClass($class);
        $properties = [];

        foreach ($refClass->getProperties() as $property) {
            if ($property->isStatic() || $property->getDeclaringClass()->getName() === self::class) {
                continue;
            }

            $properties[$property->getName()] = $property;
        }

        return self::$reflectionPropertyCache[$class] = $properties;
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
     *
     * @return bool
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
                ->where($this->getPrimaryKey(), '=', $this->attributes[$this->getPrimaryKey()])
                ->update($dirty);

            if ($affected > 0) {
                $this->original = $this->attributes;
                return true;
            }

            return false;
        }

        $inserted = $builder->insert($this->attributes);

        if ($inserted) {
            $id = static::getConnection()->lastInsertId();
            if ($id !== false && $id !== '0') {
                $primaryKeyValue = is_numeric($id) ? (int) $id : $id;
                $this->setAttribute($this->getPrimaryKey(), $primaryKeyValue);
            }

            $this->exists = true;
            $this->original = $this->attributes;
            return true;
        }

        return false;
    }

    /**
     * Deletes the model from the database.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $affected = static::query()
            ->where($this->getPrimaryKey(), '=', $this->attributes[$this->getPrimaryKey()])
            ->delete();

        if ($affected > 0) {
            $this->exists = false;
            return true;
        }

        return false;
    }

    /**
     * Converts model attributes and optional loaded relations to array format safely preventing infinite cyclic recursion.
     *
     * @param array<int, string> $visited Call stack tracker for visited FQCNs.
     * @return array<string, mixed>
     */
    public function toArray(array $visited = []): array
    {
        $class = static::class;
        if (in_array($class, $visited, true)) {
            return $this->attributes;
        }

        $visited[] = $class;
        $attributes = $this->attributes;

        foreach ($this->relations as $key => $relation) {
            if ($relation instanceof self) {
                $attributes[$key] = $relation->toArray($visited);
            } elseif (is_array($relation)) {
                $attributes[$key] = array_map(
                    fn ($item) => $item instanceof self ? $item->toArray($visited) : $item,
                    $relation
                );
            } else {
                $attributes[$key] = $relation;
            }
        }

        return $attributes;
    }

    /**
     * {@inheritdoc}
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}