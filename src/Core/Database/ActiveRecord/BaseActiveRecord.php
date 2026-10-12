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
use Phue\Core\Database\Attributes\SoftDelete;
use Phue\Core\Validation\RuleEngine;
use function Phue\Core\t;

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
     * Auto-casts cache from Field attributes.
     *
     * @var array<string, array<string, string>>
     */
    protected static array $fieldMetaCache = [];

    /**
     * Soft delete metadata cache: class => [hasSoftDelete, column, cascade].
     *
     * @var array<string, array{hasSoftDelete: bool, column: string, cascade: bool}>
     */
    protected static array $softDeleteMetaCache = [];

    /**
     * Cache of class::method => is that a relation accessor?
     *
     * Resolved once per pair: __get() reaches this on every attribute miss,
     * including from serialisers walking a model they know nothing about.
     *
     * @var array<string, bool>
     */
    private static array $relationMethodCache = [];

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
     * Attribute casting map: column => cast type (array, json, bool, int, float, string, datetime).
     *
     * @var array<string, string>
     */
    protected array $casts = [];

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
     * Attributes that should be hidden from array / JSON serialization.
     *
     * @var array<int, string>
     */
    protected array $hidden = [];

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
     * Optional resolver for obtaining the database connection.
     *
     * @var \Closure|null
     */
    private static ?\Closure $connectionResolver = null;

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
     * Sets the connection resolver for obtaining the database connection.
     *
     * @param \Closure $resolver The closure to resolve the database connection.
     * @return void
     */
    public static function setConnectionResolver(\Closure $resolver): void {
        static::$connectionResolver = $resolver;
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
        if (static::$connectionResolver !== null) {
            return (static::$connectionResolver)();
        }
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
     * Checks if the model instance has been persisted to the database.
     *
     * @return bool True if the model exists in the database (either inserted or loaded).
     */
    public function isPersisted(): bool
    {
        return $this->exists;
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
     * Get attributes in database-ready format (reverse cast).
     *
     * @return array<string, mixed>
     */
    protected function getDatabaseAttributes(): array
    {
        $attributes = $this->attributes;

        // Use reflection to get auto-casts
        $autoCasts = static::getAutoCasts();

        foreach ($attributes as $key => $value) {
            if (!isset($autoCasts[$key]) || $value === null) {
                continue;
            }

            $cast = $autoCasts[$key];
            $attributes[$key] = $this->reverseCast($value, $cast);
        }

        return $attributes;
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

        if ($this->isRelationMethod($key)) {
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
     * Whether a zero-argument method on this model is a relation accessor.
     *
     * Role, not existence. The previous check was method_exists(), which also
     * matched delete(), save(), forceDelete(), restore(), validate() and load()
     * — so a plain property read such as `$model->delete` in a template, a
     * debug dump or a serialiser actually executed the method before the return
     * value could be discarded. A read that can write is not a magic getter.
     *
     * The declared return type is the discriminator, because it is the one thing
     * a relation accessor cannot avoid writing down. Demanding a naming
     * convention instead would have made every existing model wrong.
     *
     * @param string $key Method name.
     * @return bool
     */
    protected function isRelationMethod(string $key): bool
    {
        if (!method_exists($this, $key)) {
            return false;
        }

        $cacheKey = static::class . '::' . $key;

        if (isset(self::$relationMethodCache[$cacheKey])) {
            return self::$relationMethodCache[$cacheKey];
        }

        try {
            $method = new \ReflectionMethod($this, $key);
        } catch (\ReflectionException) {
            return self::$relationMethodCache[$cacheKey] = false;
        }

        if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            return self::$relationMethodCache[$cacheKey] = false;
        }

        $returnType = $method->getReturnType();

        $isRelation = $returnType instanceof \ReflectionNamedType
            && !$returnType->isBuiltin()
            && is_a($returnType->getName(), Relation::class, true);

        return self::$relationMethodCache[$cacheKey] = $isRelation;
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
     * Handles withTrashed() and onlyTrashed() specially to return the query
     * instance for chaining, while forwarding all other methods to ActiveQuery.
     *
     * @param string $method Method name.
     * @param array<int, mixed> $arguments Method parameters.
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        $query = static::query();

        if ($method === 'withTrashed') {
            return $query->withTrashed();
        }

        if ($method === 'onlyTrashed') {
            return $query->onlyTrashed();
        }

        return call_user_func_array([$query, $method], $arguments);
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

            $value = $this->applyCast($key, $value);

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

        $value = $this->applyCast($key, $value);

        $property->setValue($this, $value);
    }

    /**
     * Apply attribute casting for a given key.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function applyCast(string $key, mixed $value): mixed
    {
        $cast = $this->getAutoCast($key);

        if ($value === null) {
            return $value;
        }

        return match ($cast) {
            'array', 'json' => is_string($value) ? json_decode($value, true) ?? [] : (is_array($value) ? $value : []),
            'bool', 'boolean' => (bool) $value,
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'string' => (string) $value,
            'datetime', 'timestamp' => $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value),
            default => $value,
        };
    }

    /**
     * Reverse a given cast
     * 
     * @param mixed $value
     * @param string $cast
     * @return mixed
     */
    protected function reverseCast(mixed $value, string $cast): mixed
    {
        return  match ($cast) {
            'json', 'array' => is_array($value) ? json_encode($value) : $value,
            'bool', 'boolean' => (bool) $value ? 1 : 0,
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'datetime', 'timestamp' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            default => $value,
        };
    }

    /**
     * Get auto-cast type from Field attribute.
     *
     * @param string $key
     * @return string|null
     */
    protected function getAutoCast(string $key): ?string
    {
        if (isset($this->casts[$key])) {
            return $this->casts[$key];
        }

        $autoCasts = static::getAutoCasts();
        return $autoCasts[$key] ?? null;
    }

    /**
     * Read every #[Field] on the class once: declared type, the cast it implies,
     * and the validation it asks for.
     *
     * One reflection walk for all three, because two walks that both read the
     * same attribute are two things that can disagree about it. getAutoCasts()
     * and validate() are both answers to "what did this property declare?".
     *
     * @return array<string, array{type: string, cast: ?string, rules: array<string>, required: bool}>
     */
    protected static function getFieldMeta(): array
    {
        $class = static::class;

        if (isset(self::$fieldMetaCache[$class])) {
            return self::$fieldMetaCache[$class];
        }

        $meta = [];
        $refClass = new ReflectionClass($class);

        foreach ($refClass->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $property) {
            $fieldAttrs = $property->getAttributes(\Phue\Core\Database\Attributes\Field::class);
            if (empty($fieldAttrs)) {
                continue;
            }

            /** @var \Phue\Core\Database\Attributes\Field $field */
            $field = $fieldAttrs[0]->newInstance();
            $type = strtolower($field->type);

            $meta[$property->getName()] = [
                'type' => $type,
                'cast' => match ($type) {
                    'json' => 'json',
                    'boolean', 'bool' => 'bool',
                    'integer', 'int' => 'int',
                    'float', 'number', 'decimal', 'numeric' => 'float',
                    'datetime', 'timestamp' => 'datetime',
                    default => null,
                },
                'rules' => $field->rules,
                'required' => $field->required,
            ];
        }

        return self::$fieldMetaCache[$class] = $meta;
    }

    /**
     * Retrieves soft delete metadata for the model class.
     *
     * Reads the #[SoftDelete] attribute once per class and caches the result.
     * Returns an array with keys: hasSoftDelete (bool), column (string), cascade (bool).
     *
     * @return array{hasSoftDelete: bool, column: string, cascade: bool}
     */
    private static function getSoftDeleteMeta(): array
    {
        $class = static::class;

        if (isset(self::$softDeleteMetaCache[$class])) {
            return self::$softDeleteMetaCache[$class];
        }

        $refClass = new ReflectionClass($class);
        $attrs = $refClass->getAttributes(SoftDelete::class);

        if (empty($attrs)) {
            return self::$softDeleteMetaCache[$class] = [
                'hasSoftDelete' => false,
                'column' => 'deleted_at',
                'cascade' => false,
            ];
        }

        /** @var SoftDelete $attr */
        $attr = $attrs[0]->newInstance();

        return self::$softDeleteMetaCache[$class] = [
            'hasSoftDelete' => true,
            'column' => $attr->column,
            'cascade' => $attr->cascade,
        ];
    }

    /**
     * Returns the soft delete column name if the model uses soft deletes.
     *
     * @return string|null The column name (e.g., 'deleted_at') or null if not a soft-delete model.
     */
    public function getSoftDeleteColumn(): ?string
    {
        $meta = static::getSoftDeleteMeta();
        return $meta['hasSoftDelete'] ? $meta['column'] : null;
    }

    /**
     * Checks whether the model uses soft deletes.
     *
     * @return bool True if the model has the #[SoftDelete] attribute.
     */
    public function hasSoftDelete(): bool
    {
        return static::getSoftDeleteMeta()['hasSoftDelete'];
    }

    /**
     * Build auto-casts map from Field attributes.
     *
     * Reads \Phue\Core\Database\Attributes\Field, which is a Core class on
     * purpose: the cast a property receives is a persistence decision, so it
     * must hold for an application that installs no admin module at all.
     *
     * @return array<string, string>
     */
    protected static function getAutoCasts(): array
    {
        $autoCasts = [];

        foreach (self::getFieldMeta() as $propertyName => $meta) {
            if ($meta['cast'] !== null) {
                $autoCasts[$propertyName] = $meta['cast'];
            }
        }

        return $autoCasts;
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
 * Check this model against the rules its #[Field] attributes declare.
 *
 * Fills getErrors() and returns whether the model is fit to persist. The
 * schema is the single declaration of what a model requires, so enforcing it
 * here — not only inside an HTTP endpoint — is what makes the rules mean the
 * same thing whether a record arrives from a controller, a queue worker, a CLI
 * command, or a seed script.
 *
 * Before this existed, getErrors() had no writer at all. Every caller that
 * read it was reading an array that could only ever be empty, and a caller
 * reacting to it had nothing to react to.
 *
 * `required` applies to INSERT only. On update the record was loaded whole, so
 * a missing attribute means the column is null and demanding it would make
 * every partial write fail.
 *
 * @return bool True when no rule was violated.
 */
public function validate(): bool
{
    $this->errors = [];

    foreach (self::getFieldMeta() as $propertyName => $meta) {
        $rules = $meta['rules'];

        if ($rules === [] && !$meta['required']) {
            continue;
        }

        $value = $this->attributes[$propertyName] ?? null;
        $isMissing = $value === null || $value === '';

        if (!$this->exists && ($meta['required'] || RuleEngine::isRequired($rules)) && $isMissing) {
            $this->errors[$propertyName] = t("Field '{propertyName}' is required", ['propertyName' => $propertyName]);
            continue;
        }

        if ($isMissing) {
            continue;
        }

        // A numeric column that received text is corrupt wherever it came from.
        if (RuleEngine::isNumericType($meta['type'])) {
            $rules[] = RuleEngine::RULE_NUMERIC;
        }

        $failure = RuleEngine::check($rules, $value, $meta['type'], $propertyName);

        if ($failure !== null) {
            $this->errors[$propertyName] = $failure;
        }
    }

    return $this->errors === [];
}

/**
 * Persists the current model state into the database (INSERT or UPDATE).
 *
 * Validation runs first. Returning false with getErrors() populated is how a
 * caller learns the model was refused; a false with NO errors means the write
 * itself failed, and the two must not be reported as the same thing.
 *
 * @return bool
 */
public function save(): bool
{
    if (!$this->validate()) {
        return false;
    }

    $builder = static::query();

        if ($this->exists) {
            $dirty = $this->getDirty();

            if (empty($dirty)) {
                return true;
            }

            // Convert dirty attributes to database format
            $dbDirty = [];
            foreach ($dirty as $key => $value) {
                $dbDirty[$key] = $value;
            }
            // Apply reverse cast to dirty attributes
            $autoCasts = static::getAutoCasts();
            foreach ($dbDirty as $key => $value) {
                if (isset($autoCasts[$key]) && $value !== null) {
                    $cast = $autoCasts[$key];
                    $dbDirty[$key] = $this->reverseCast($value, $cast);
                }
            }

            $affected = $builder
                ->where($this->getPrimaryKey(), '=', $this->attributes[$this->getPrimaryKey()])
                ->update($dbDirty);

            if ($affected > 0) {
                $this->original = $this->attributes;
                return true;
            }

            return false;
        }

        $dbAttributes = $this->getDatabaseAttributes();
        $inserted = $builder->insert($dbAttributes);

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
     * If the model uses soft deletes ([#SoftDelete] attribute), this performs
     * a soft delete by updating the deletion timestamp column instead of
     * removing the row. Use forceDelete() to permanently remove the record.
     *
     * @return bool True on success, false if the model doesn't exist or the delete failed.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $softDeleteColumn = $this->getSoftDeleteColumn();

        if ($softDeleteColumn !== null) {
            // Soft delete: UPDATE deleted_at = NOW()
            $builder = static::query();
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $affected = $builder
                ->where($this->getPrimaryKey(), '=', $this->attributes[$this->getPrimaryKey()])
                ->update([$softDeleteColumn => $now]);

            if ($affected > 0) {
                $this->setAttribute($softDeleteColumn, $now);
                $this->original[$softDeleteColumn] = $now;
                $this->exists = false;
                return true;
            }

            return false;
        }

        // Hard delete (default behavior for models without soft delete)
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
     * Permanently deletes the model from the database, bypassing soft delete.
     *
     * This method ignores the #[SoftDelete] attribute and executes a hard DELETE
     * statement. Use with caution — the record cannot be restored.
     *
     * Works on both active and soft-deleted models.
     *
     * @return bool True on success, false if the model doesn't exist or the delete failed.
     */
    public function forceDelete(): bool
    {
        $pk = $this->getPrimaryKey();
        $pkValue = $this->attributes[$pk] ?? null;

        if ($pkValue === null) {
            return false; // no primary key, can't delete
        }

        $affected = static::query()
            ->withTrashed()
            ->where($pk, '=', $pkValue)
            ->delete();

        if ($affected > 0) {
            $this->exists = false;
            return true;
        }

        return false;
    }

    /**
     * Restores a soft-deleted model.
     *
     * Sets the deletion timestamp column to NULL, marking the record as active again.
     * Only works on models that use soft deletes and are currently soft-deleted.
     *
     * @return bool True on success, false if the model doesn't exist, isn't soft-deleted,
     *              doesn't use soft deletes, or the update failed.
     */
    public function restore(): bool
    {
        if ($this->exists) {
            return false; // already alive
        }

        $softDeleteColumn = $this->getSoftDeleteColumn();
        if ($softDeleteColumn === null) {
            return false; // not a soft-delete model
        }

        $affected = static::query()
            ->withTrashed()
            ->where($this->getPrimaryKey(), '=', $this->attributes[$this->getPrimaryKey()])
            ->update([$softDeleteColumn => null]);

        if ($affected > 0) {
            $this->setAttribute($softDeleteColumn, null);
            $this->original[$softDeleteColumn] = null;
            $this->exists = true;
            return true;
        }

        return false;
    }

    /**
     * Checks if the model instance has been soft-deleted.
     *
     * @return bool True if the model uses soft deletes and the deletion timestamp is set.
     */
    public function isTrashed(): bool
    {
        $column = $this->getSoftDeleteColumn();
        return $column !== null && isset($this->attributes[$column]) && $this->attributes[$column] !== null;
    }

    /**
     * Converts model attributes and optional loaded relations to array format safely,
     * hiding declared hidden attributes and preventing infinite cyclic recursion.
     *
     * @param array<int, string> $visited Call stack tracker for visited FQCNs.
     * @return array<string, mixed>
     */
    public function toArray(array $visited = []): array
    {
        $class = static::class;
        if (in_array($class, $visited, true)) {
            return array_diff_key($this->attributes, array_flip($this->hidden));
        }

        $visited[] = $class;

        // Dynamic filtering per serialization pass
        $attributes = array_diff_key($this->attributes, array_flip($this->hidden));

        foreach ($this->relations as $key => $relation) {
            // Respect parent hidden declaration for relation properties
            if (in_array($key, $this->hidden, true)) {
                continue;
            }

            if ($relation instanceof self) {
                // Nested models execute their own toArray() using their OWN $hidden array
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