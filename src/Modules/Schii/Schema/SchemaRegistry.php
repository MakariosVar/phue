<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Schema;

use InvalidArgumentException;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Modules\Schii\Attribute\Field;
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
     */
    public function __construct(
        private readonly ?string $basePath = null,
        private readonly ?CacheInterface $cache = null,
        private readonly string $cachePrefix = 'schii_schema_'
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
                    // Slugify: User -> user, UserProfile -> user-profiles
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
     * FILTER: `is_subclass_of($class, BaseActiveRecord::class) && !$reflector->isAbstract()` —
     * same criteria as discoverEntities(). DataMapper Entities reside in the namespace
     * but must not pass the filter (not BaseActiveRecord).
     *
     * @param string $namespace e.g. 'App\\Models'
     *
     * @return array<class-string> e.g. ['App\\Models\\User', 'App\\Models\\Product']
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
     * Resolves the table name via (new $modelClass())->getTable().
     *
     * @param class-string $modelClass
     * @param string $module
     *
     * @return PhiiExportSchema
     *
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
     *
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
     * Maps a target namespace to its physical directory path using multiple root bases
     * (application basePath & process getcwd fallback).
     *
     * @param string $namespace
     * @return string|null
     */
    private function resolveNamespaceToPath(string $namespace): ?string
    {
        $cleanNamespace = trim($namespace, '\\');
        $relativePath = str_replace('\\', '/', $cleanNamespace);

        // 1. Collect candidate root directories (basePath + getcwd)
        $roots = array_filter(array_unique([
            $this->basePath ? rtrim($this->basePath, '/') : null,
            getcwd() ? rtrim(getcwd(), '/') : null,
        ]));

        // 2. Scan candidates across all root directories
        foreach ($roots as $root) {
            $possiblePaths = [
                // Direct path match (e.g. {root}/App/Models or {root}/Phue/Tests/Schii/Stubs)
                "{$root}/{$relativePath}",
                // App\ -> {root}/src/
                "{$root}/src/" . preg_replace('#^App/#', '', $relativePath),
                // Phue\Tests\ -> {root}/tests/
                "{$root}/tests/" . preg_replace('#^Phue/Tests/#', '', $relativePath),
                // Generic tests directory fallback
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
     * Parses the specified Model class and returns its ModelSchema representation.
     *
     * Resolves the schema sequentially via:
     * 1. In-memory static array cache (L1).
     * 2. Persistent storage cache with array-to-object rehydration (L2).
     * 3. Reflection-based parsing on cache miss.
     *
     * @param class-string $modelClass Fully qualified class name of the target model.
     *
     * @return ModelSchema Rehydrated or newly parsed schema instance.
     */
    public function get(string $modelClass): ModelSchema
    {
        // 1. In-Memory Static Cache Hit (L1)
        if (isset($this->memoryCache[$modelClass])) {
            return $this->memoryCache[$modelClass];
        }

        $cacheKey = $this->cachePrefix . md5($modelClass);

        // 2. Persistent Cache Hit (L2 - Redis/File)
        if ($this->cache?->has($cacheKey)) {
            $cachedData = $this->cache->get($cacheKey);

            if (is_array($cachedData)) {
                try {
                    $schema = ModelSchema::fromArray($cachedData);
                    $this->memoryCache[$modelClass] = $schema;

                    return $schema;
                } catch (\Throwable) {
                    // Cache is corrupt or schema version changed; fall back to parsing
                    $this->cache->delete($cacheKey);
                }
            } elseif ($cachedData instanceof ModelSchema) {
                $this->memoryCache[$modelClass] = $cachedData;

                return $cachedData;
            }
        }

        // 3. Cache Miss: Parsing via Reflection
        $schema = $this->parse($modelClass);

        // Store in caches (L1 object instance, L2 array representation or object)
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

        // 1. Parsing UI Attribute
        $uiAttr = $reflection->getAttributes(UI::class)[0] ?? null;
        /** @var UI|null $ui */
        $ui = $uiAttr?->newInstance();

        $label = $ui?->label !== '' && $ui?->label !== null 
            ? $ui->label 
            : $this->humanize($entityName);

        // 2. Parsing Properties & Field Attributes
        $fields = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $property) {
            $fieldAttrs = $property->getAttributes(Field::class);
            if (empty($fieldAttrs)) {
                continue;
            }

            /** @var Field $field */
            $field = $fieldAttrs[0]->newInstance();
            $propertyName = $property->getName();

            // Calculate Fallback Label
            $fieldLabel = $field->label !== '' 
                ? $field->label 
                : $this->humanize($propertyName);

            $fields[$propertyName] = new FieldSchema(
                name: $propertyName,
                type: $this->normalizeType($field->type),
                label: $fieldLabel,
                widget: $field->widget,
                column: $field->column,
                required: $field->required,
                searchable: $field->searchable,
                filterable: $field->filterable,
                sortable: $field->sortable,
                options: $field->options,
                rules: $field->rules,
            );
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
            'integer', 'int' => 'int',
            'number', 'float', 'decimal', 'numeric' => 'float',
            'email', 'select', 'string' => 'text',
            default => $type,
        };
    }

    /**
     * Converts camelCase or snake_case identifiers into human-readable strings.
     * e.g., 'email_verified_at' -> 'Email Verified At', 'firstName' -> 'First Name'
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
     * Clears the in-memory cache (e.g., between requests or during Swoole worker reset).
     */
    public function clearMemoryCache(): void
    {
        $this->memoryCache = [];
    }
}