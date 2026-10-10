<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Schema;

use InvalidArgumentException;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Core\Database\Attributes\SoftDelete;
use Phue\Core\Validation\RuleEngine;
use Phue\Modules\Schii\Attribute\Column;
use Phue\Modules\Schii\Attribute\UI;
use Phue\Modules\Schii\Generator\PhiiExportSchema;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
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
        private readonly string $defaultModelNamespace = 'App\\Models'
    ) {}

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