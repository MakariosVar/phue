<?php

declare(strict_types=1);

namespace Phue\Schii\Schema;

use Phue\Schii\Attribute\Field;
use Phue\Schii\Attribute\UI;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionProperty;

final class SchemaRegistry
{
    /**
     * In-memory cache για αποφυγή επανειλημμένης ανάγνωσης στο ίδιο worker process
     * @var array<string, ModelSchema>
     */
    private array $memoryCache = [];

    public function __construct(
        private readonly ?CacheInterface $cache = null,
        private readonly string $cachePrefix = 'schii_schema_'
    ) {}

    /**
     * Αναλύει το Model class και επιστρέφει το ModelSchema
     *
     * @param class-string $modelClass
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
            /** @var ModelSchema $schema */
            $schema = $this->cache->get($cacheKey);
            $this->memoryCache[$modelClass] = $schema;
            return $schema;
        }

        // 3. Cache Miss: Parsing via Reflection
        $schema = $this->parse($modelClass);

        // Store in caches
        $this->memoryCache[$modelClass] = $schema;
        $this->cache?->set($cacheKey, $schema);

        return $schema;
    }

    /**
     * Αναλύει την κλάση μέσω Reflection
     *
     * @param class-string $modelClass
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

            // Υπολογισμός Fallback Label
            $fieldLabel = $field->label !== '' 
                ? $field->label 
                : $this->humanize($propertyName);

            $fields[$propertyName] = new FieldSchema(
                name: $propertyName,
                type: $field->type,
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
     * Μετατρέπει camelCase ή snake_case σε Human Readable string
     * e.g., 'email_verified_at' -> 'Email Verified At', 'firstName' -> 'First Name'
     */
    private function humanize(string $input): string
    {
        $split = preg_replace('/(?<=[a-z])(?=[A-Z])|_/', ' ', $input);
        return ucwords($split ?? $input);
    }

    /**
     * Καθαρίζει το memory cache (π.χ. σε αλλαγή request ή στο reset του Swoole)
     */
    public function clearMemoryCache(): void
    {
        $this->memoryCache = [];
    }
}