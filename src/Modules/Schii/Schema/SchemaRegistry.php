<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Schema;

use InvalidArgumentException;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\ActiveRecord\Relations\BelongsTo;
use Phue\Core\Database\ActiveRecord\Relations\HasMany;
use Phue\Core\Database\ActiveRecord\Relations\HasOne;
use Phue\Core\Database\ActiveRecord\Relations\Relation;
use Phue\Core\Database\Attributes\Field;
use Phue\Core\Database\Attributes\SoftDelete;
use Phue\Core\Validation\RuleEngine;
use Phue\Modules\Schii\Attribute\Column;
use Phue\Modules\Schii\Attribute\UI;
use Phue\Modules\Schii\Config\SchiiConfig;
use Phue\Modules\Schii\Generator\PhiiExportSchema;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

final class SchemaRegistry
{
    /**
     * In-memory cache to prevent repeated parsing within the same worker process.
     *
     * @var array<string, ModelSchema>
     */
    private array $memoryCache = [];

    /**
     * @param string|null $basePath The root directory path of the application (e.g., Application::getBasePath())
     * @param CacheInterface|null $cache Optional persistent PSR-16 cache implementation
     * @param string $cachePrefix Prefix key used for persistent caching
     * @param string $defaultModelNamespace Default namespace for entity discovery and resolution
     */
    public function __construct(
        private readonly ?string $basePath = null,
        private readonly ?CacheInterface $cache = null,
        private readonly string $cachePrefix = 'schii_schema_',
        private readonly string $defaultModelNamespace = 'App\\Models',
        private readonly ?SchiiConfig $config = null
    ) {}

    /**
     * Determine whether relation metadata should be inferred from the model's relation methods.
     *
     * The switch is read from the Schii configuration rather than hard-coded, so an
     * application that has not opted in keeps receiving exactly the schema it received
     * before this capability existed.
     *
     * @return bool
     */
    private function relationsAutoInfer(): bool
    {
        return (bool) ($this->config?->relations['autoInfer'] ?? false);
    }

    /**
     * Discover all valid ActiveRecord entity slugs within a target PHP namespace.
     *
     * @param string $namespace The root namespace to scan (e.g., "App\\Models" or "Phue\\Tests\\Schii\\Stubs")
     * @return array<string> List of slugified entity names
     */
    public function discoverEntities(string $namespace): array
    {
        $entities = [];
        $directory = $this->resolveNamespaceToPath($namespace);

        if (!$directory || !is_dir($directory)) {
            return [];
        }

        $files = scandir($directory) ?: [];

        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $className = pathinfo($file, PATHINFO_FILENAME);
            $fullClassName = rtrim($namespace, '\\') . '\\' . $className;

            if (class_exists($fullClassName)) {
                $reflector = new ReflectionClass($fullClassName);

                if ($reflector->isSubclassOf(BaseActiveRecord::class) && !$reflector->isAbstract()) {
                    // Slugify: User -> user, UserProfile -> user-profile (or user-profiles depending on convention)
                    $slug = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $className));
                    $entities[] = $slug;
                }
            }
        }

        return array_values(array_unique($entities));
    }

    /**
     * Discover FQCNs (not slugs) for all concrete ActiveRecord classes in a namespace.
     *
     * @param string $namespace e.g. 'App\\Models'
     * @return array<class-string> e.g. ['App\\Models\\User', 'App\\Models\\UserProfile']
     */
    public function discoverModelClasses(string $namespace): array
    {
        $classes = [];
        $directory = $this->resolveNamespaceToPath($namespace);

        if (!$directory || !is_dir($directory)) {
            return [];
        }

        $files = scandir($directory) ?: [];

        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $className = pathinfo($file, PATHINFO_FILENAME);
            $fullClassName = rtrim($namespace, '\\') . '\\' . $className;

            if (class_exists($fullClassName)) {
                $reflector = new ReflectionClass($fullClassName);

                if ($reflector->isSubclassOf(BaseActiveRecord::class) && !$reflector->isAbstract()) {
                    $classes[] = $fullClassName;
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * Converts a ModelSchema to a PhiiExportSchema.
     *
     * @param class-string $modelClass
     * @param string $module
     * @return PhiiExportSchema
     * @throws InvalidArgumentException If not BaseActiveRecord subclass
     */
    public function toPhiiExportSchema(string $modelClass, string $module = 'admin'): PhiiExportSchema
    {
        if (!is_subclass_of($modelClass, BaseActiveRecord::class)) {
            throw new InvalidArgumentException(
                sprintf('Model class "%s" must be a subclass of "%s".', $modelClass, BaseActiveRecord::class)
            );
        }

        $schema = $this->get($modelClass);

        return PhiiExportSchema::fromModelSchema($schema, $modelClass, $module);
    }

    /**
     * @param string $entityNamespace
     * @param string $module
     * @return array<class-string, PhiiExportSchema> Map of FQCN → export schema
     */
    public function getAllPhiiExportSchemas(
        string $entityNamespace,
        string $module = 'admin',
    ): array {
        $schemas = [];
        $classes = $this->discoverModelClasses($entityNamespace);

        foreach ($classes as $class) {
            $schemas[$class] = $this->toPhiiExportSchema($class, $module);
        }

        return $schemas;
    }

    /**
     * Maps a target namespace to its physical directory path using multiple root bases.
     *
     * @param string $namespace
     * @return string|null
     */
    private function resolveNamespaceToPath(string $namespace): ?string
    {
        $cleanNamespace = trim($namespace, '\\');
        $relativePath = str_replace('\\', '/', $cleanNamespace);

        $roots = array_filter(array_unique([
            $this->basePath ? rtrim($this->basePath, '/') : null,
            getcwd() ? rtrim(getcwd(), '/') : null,
        ]));

        foreach ($roots as $root) {
            $possiblePaths = [
                "{$root}/{$relativePath}",
                "{$root}/src/" . preg_replace('#^App/#', '', $relativePath),
                "{$root}/tests/" . preg_replace('#^Phue/Tests/#', '', $relativePath),
                "{$root}/tests/{$relativePath}",
            ];

            foreach ($possiblePaths as $path) {
                if (is_dir($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Retrieves a ModelSchema by its slug, performing reverse slugification for multi-word 
     * entities (e.g. 'user-profiles' -> 'UserProfile') and verifying class validity.
     *
     * @param string $slug Target entity slug (e.g., 'user-profiles', 'product').
     * @return ModelSchema|null Resolved model schema or null if not found.
     */
    public function getSchemaBySlug(string $slug): ?ModelSchema
    {
        $normalizedSlug = strtolower(trim($slug));
        
        // Convert slug to StudlyCase class name (e.g., user-profiles -> UserProfiles, user-profile -> UserProfile)
        $studlyName = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $normalizedSlug)));
        
        // Try singular/plural fallback variations if needed
        $candidateClasses = [
            rtrim($this->defaultModelNamespace, '\\') . '\\' . $studlyName,
            rtrim($this->defaultModelNamespace, '\\') . '\\' . rtrim($studlyName, 's'),
            rtrim($this->defaultModelNamespace, '\\') . '\\' . $studlyName . 's',
        ];

        foreach ($candidateClasses as $fqcn) {
            if (class_exists($fqcn) && is_subclass_of($fqcn, BaseActiveRecord::class)) {
                return $this->get($fqcn);
            }
        }

        // Fallback: check already cached or discovered schemas
        foreach ($this->memoryCache as $schema) {
            $schemaSlug = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $schema->entity));
            if ($schemaSlug === $normalizedSlug || strtolower($schema->entity) === $normalizedSlug) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * Parses the specified Model class and returns its ModelSchema representation.
     *
     * @param class-string $modelClass Fully qualified class name of the target model.
     * @return ModelSchema Rehydrated or newly parsed schema instance.
     */
    public function get(string $modelClass): ModelSchema
    {
        if (isset($this->memoryCache[$modelClass])) {
            return $this->memoryCache[$modelClass];
        }

        $cacheKey = $this->cachePrefix . md5($modelClass);

        if ($this->cache?->has($cacheKey)) {
            $cachedData = $this->cache->get($cacheKey);

            if (is_array($cachedData)) {
                try {
                    $schema = ModelSchema::fromArray($cachedData);
                    $this->memoryCache[$modelClass] = $schema;

                    return $schema;
                } catch (\Throwable) {
                    $this->cache->delete($cacheKey);
                }
            } elseif ($cachedData instanceof ModelSchema) {
                $this->memoryCache[$modelClass] = $cachedData;

                return $cachedData;
            }
        }

        $schema = $this->parse($modelClass);

        $this->memoryCache[$modelClass] = $schema;
        $this->cache?->set($cacheKey, $schema->toArray());

        return $schema;
    }

    /**
     * Parses a class via Reflection to build its schema structure.
     *
     * @param class-string $modelClass
     * @return ModelSchema
     */
    private function parse(string $modelClass): ModelSchema
    {
        $reflection = new ReflectionClass($modelClass);
        $entityName = $reflection->getShortName();

        $uiAttr = $reflection->getAttributes(UI::class)[0] ?? null;
        /** @var UI|null $ui */
        $ui = $uiAttr?->newInstance();

        $label = $ui?->label !== '' && $ui?->label !== null 
            ? $ui->label 
            : $this->humanize($entityName);

        $fields = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $property) {
            $fieldAttrs = $property->getAttributes(Field::class);
            $columnAttrs = $property->getAttributes(Column::class);

            // A property with neither attribute is not part of the entity.
            if (empty($fieldAttrs) && empty($columnAttrs)) {
                continue;
            }

            /** @var Field|null $field */
            $field = empty($fieldAttrs) ? null : $fieldAttrs[0]->newInstance();

            /** @var Column|null $column */
            $column = empty($columnAttrs) ? null : $columnAttrs[0]->newInstance();

            $propertyName = $property->getName();

            $fieldLabel = $column !== null && $column->label !== ''
                ? $column->label
                : $this->humanize($propertyName);

            // A property that declared only how it should look has said nothing
            // about where its value may come from, so it is exposed for display
            // and withheld from mass assignment. Only #[Field] grants that.
            $declaresPersistence = $field !== null;
            $writable = $declaresPersistence && $field->writable;

            $normalizedType = $this->normalizeType($field?->type ?? 'text');

            // Smart widget assignment: if widget is default ('input') and type is select, email, or date,
            // set widget to match the type unless explicitly overridden.
            $widget = $column?->widget ?? 'input';
            if ($widget === 'input' && in_array($normalizedType, ['select', 'email', 'date'], true)) {
                $widget = $normalizedType;
            }

            // An unknown rule is refused here rather than skipped by the
            // endpoint. This is the earliest point at which the declaration can
            // be attributed to a model, so it is the point at which an author
            // can be told which model and which rule. Deferring it means the
            // first sign is a rule that quietly does nothing.
            $rules = $declaresPersistence ? $field->rules : [];
            RuleEngine::assertKnownRules($rules, $modelClass . '::$' . $propertyName);

            $fields[$propertyName] = new FieldSchema(
                name: $propertyName,
                type: $normalizedType,
                label: $fieldLabel,
                widget: $widget,
                column: $declaresPersistence && $field->column,
                required: $declaresPersistence && $field->required,
                searchable: $column?->searchable ?? false,
                filterable: $column?->filterable ?? false,
                sortable: $column?->sortable ?? false,
                options: $column?->options ?? [],
                rules: $rules,
                writable: $writable,
                readonly: $declaresPersistence && $field->readonly,
                primaryKey: $declaresPersistence && $field->primaryKey,
                indexed: $declaresPersistence && $field->indexed,
                length: 0
            );
        }

        /**
         * Add soft delete column to schema if model has #[SoftDelete] attribute.
         *
         * This ensures the auto-migrator creates the column and the admin UI can manage it.
         */
        $softDeleteAttrs = $reflection->getAttributes(SoftDelete::class);
        if (!empty($softDeleteAttrs)) {
            /** @var SoftDelete $softDelete */
            $softDelete = $softDeleteAttrs[0]->newInstance();
            $columnName = $softDelete->column;

            // Only add if not already defined as a property
            if (!isset($fields[$columnName])) {
                $fields[$columnName] = new FieldSchema(
                    name: $columnName,
                    type: 'datetime',
                    label: $this->humanize($columnName),
                    widget: 'datetime',
                    column: true,
                    required: false,
                    searchable: false,
                    filterable: true,
                    sortable: true,
                    options: [],
                    rules: [],
                    writable: false,
                    readonly: true,
                    primaryKey: false,
                    indexed: false,
                    length: 0
                );
            }
        }

        if ($this->relationsAutoInfer()) {
            $fields = $this->applyRelationMetadata($modelClass, $reflection, $fields);
        }

        return new ModelSchema(
            entity: $entityName,
            className: $modelClass,
            label: $label,
            icon: $ui?->icon ?? 'table',
            layout: $ui?->layout ?? 'grid',
            description: $ui?->description,
            fields: $fields,
            permissions: [
                'create' => strtolower("admin.{$entityName}.create"),
                'read'   => strtolower("admin.{$entityName}.read"),
                'update' => strtolower("admin.{$entityName}.update"),
                'delete' => strtolower("admin.{$entityName}.delete"),
            ]
        );
    }

    /**
     * Annotate fields that are backed by a model relation with relation metadata.
     *
     * A relation is recognised exactly the way BaseActiveRecord recognises one at
     * runtime: a public, non-static, zero-argument method whose declared return type
     * is a Relation subclass. Invoking such a method here is safe - every relation
     * factory only records its keys, and the query is built lazily on first use.
     *
     * @param string $modelClass Fully qualified model class name.
     * @param ReflectionClass $reflection Model reflection.
     * @param array<string, FieldSchema> $fields Fields collected from #[Field]/#[Column].
     * @return array<string, FieldSchema>
     */
    private function applyRelationMetadata(string $modelClass, ReflectionClass $reflection, array $fields): array
    {
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $returnType = $method->getReturnType();
            if (!$returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
                continue;
            }

            $declaredType = $returnType->getName();
            if (!is_a($declaredType, Relation::class, true)) {
                continue;
            }

            $relation = $this->resolveRelation($modelClass, $method);
            if ($relation === null) {
                continue;
            }

            // Only BelongsTo puts its foreign key on the model being described.
            // HasOne/HasMany store theirs on the related table, so there is no
            // local column to hang an editor off.
            if (!is_a($declaredType, BelongsTo::class, true)) {
                continue;
            }

            $fieldName = $relation->getForeignKey();
            if (!isset($fields[$fieldName])) {
                continue;
            }

            $fields[$fieldName] = $this->withRelation(
                $fields[$fieldName],
                'belongsTo',
                $relation->getRelatedClass(),
                $this->relationLabelFor($relation->getRelatedClass())
            );
        }

        return $fields;
    }

    /**
     * Instantiate the model and invoke a relation method to read its definition.
     *
     * @param string $modelClass Fully qualified model class name.
     * @param ReflectionMethod $method Relation method.
     * @return Relation|null Null when the method cannot be invoked or does not return a relation.
     */
    private function resolveRelation(string $modelClass, ReflectionMethod $method): ?Relation
    {
        try {
            /** @var BaseActiveRecord $instance */
            $instance = new $modelClass();
            $relation = $method->invoke($instance);
        } catch (\Throwable) {
            return null;
        }

        return $relation instanceof Relation ? $relation : null;
    }

    /**
     * Choose the related model property used as the human-readable option label.
     *
     * Attributes are read directly rather than through parse() so that two models
     * referencing each other cannot drive this method into unbounded recursion.
     *
     * Public because the relation options endpoint has to resolve the very same
     * column the schema advertised - if the two disagreed, the dropdown would be
     * labelled with a different column than the one it filtered on.
     *
     * @param string $relatedClass Related model class FQCN.
     * @return string Property name, or an empty string when no suitable field exists.
     */
    public function relationLabelFor(string $relatedClass): string
    {
        if (!class_exists($relatedClass)) {
            return '';
        }

        $reflection = new ReflectionClass($relatedClass);
        $fallback = null;

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $property) {
            $fieldAttrs = $property->getAttributes(Field::class);
            $columnAttrs = $property->getAttributes(Column::class);

            // A property with neither attribute is not part of the entity.
            if (empty($fieldAttrs) && empty($columnAttrs)) {
                continue;
            }

            /** @var Field|null $field */
            $field = empty($fieldAttrs) ? null : $fieldAttrs[0]->newInstance();

            // The primary key identifies the row; it never describes it.
            if ($field !== null && $field->primaryKey) {
                continue;
            }

            if (!empty($columnAttrs) && $columnAttrs[0]->newInstance()->searchable) {
                return $property->getName();
            }

            if ($fallback === null && $field !== null
                && in_array($this->normalizeType($field->type), ['varchar', 'text'], true)) {
                $fallback = $property->getName();
            }
        }

        return $fallback ?? '';
    }

    /**
     * Clone a field schema carrying relation metadata.
     *
     * @param FieldSchema $field Original field schema.
     * @param string $type Relation kind.
     * @param string $model Related model class FQCN.
     * @param string $label Related model property used as the option label.
     * @return FieldSchema
     */
    private function withRelation(FieldSchema $field, string $type, string $model, string $label): FieldSchema
    {
        return new FieldSchema(
            name: $field->name,
            type: $field->type,
            label: $field->label,
            widget: $field->widget,
            column: $field->column,
            required: $field->required,
            searchable: $field->searchable,
            filterable: $field->filterable,
            sortable: $field->sortable,
            options: $field->options,
            rules: $field->rules,
            writable: $field->writable,
            readonly: $field->readonly,
            primaryKey: $field->primaryKey,
            indexed: $field->indexed,
            length: $field->length,
            relation: [
                'model' => $model,
                'label' => $label,
                'type' => $type,
            ],
        );
    }

    /**
     * Normalizes field type strings into canonical Schii types.
     *
     * @param string $type
     * @return string
     */
    private function normalizeType(string $type): string
    {
        return match (strtolower($type)) {
            'bool' => 'boolean',
            'string' => 'varchar',
            'integer', 'int' => 'int',
            'number', 'float', 'decimal', 'numeric' => 'float',
            default => strtolower($type),
        };
    }

    /**
     * Converts camelCase or snake_case identifiers into human-readable strings.
     *
     * @param string $input
     * @return string
     */
    private function humanize(string $input): string
    {
        $split = preg_replace('/(?<=[a-z])(?=[A-Z])|_/', ' ', $input);
        return ucwords($split ?? $input);
    }

    /**
     * Clears the in-memory cache.
     */
    public function clearMemoryCache(): void
    {
        $this->memoryCache = [];
    }
}