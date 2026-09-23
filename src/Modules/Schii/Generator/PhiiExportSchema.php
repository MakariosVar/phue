<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Generator;

use InvalidArgumentException;
use ReflectionClass;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Modules\Schii\Schema\ModelSchema;

/**
 * Readonly export schema representing an entity in Phii format.
 *
 * @package Phue\Modules\Schii\Generator
 */
final readonly class PhiiExportSchema
{
    /**
     * PhiiExportSchema constructor.
     *
     * @param string                   $entity      Short entity name (e.g. 'User').
     * @param string                   $className   Fully qualified model class name (e.g. 'App\\Models\\User').
     * @param string                   $label       Human-readable entity label.
     * @param string                   $icon        UI icon key.
     * @param string                   $table       Database table name resolved from BaseActiveRecord::getTable().
     * @param string                   $module      Target module name (e.g. 'admin').
     * @param string                   $namespace   Base namespace for the model.
     * @param array<int, PhiiExportField> $fields   List of export fields.
     * @param array<string, string>    $permissions Permission rules map.
     * @param string                   $layout      UI layout style (default 'grid').
     * @param string|null              $description Optional description.
     */
    public function __construct(
        public string $entity,
        public string $className,
        public string $label,
        public string $icon,
        public string $table,
        public string $module,
        public string $namespace,
        public array $fields,
        public array $permissions,
        public string $layout = 'grid',
        public ?string $description = null,
    ) {}

    /**
     * Converts a Schii ModelSchema to PhiiExportSchema.
     *
     * @param ModelSchema $schema     The source model schema definition.
     * @param class-string $modelClass Fully qualified class name of the ActiveRecord model.
     * @param string       $module     Target module identifier.
     *
     * @return self
     *
     * @throws InvalidArgumentException If $modelClass is not a subclass of BaseActiveRecord.
     */
    public static function fromModelSchema(
        ModelSchema $schema,
        string $modelClass,
        string $module = 'admin',
    ): self {
        if (!is_subclass_of($modelClass, BaseActiveRecord::class)) {
            throw new InvalidArgumentException(
                sprintf('Model class "%s" must be a subclass of "%s".', $modelClass, BaseActiveRecord::class)
            );
        }

        $refClass = new ReflectionClass($modelClass);
        $entityName = $schema->entity ?? $refClass->getShortName();
        $namespace = $refClass->getNamespaceName();

        /** @var BaseActiveRecord $modelInstance */
        $modelInstance = new $modelClass();
        $table = $modelInstance->getTable();

        $exportFields = [];
        foreach ($schema->fields as $fieldSchema) {
            $exportFields[] = PhiiExportField::fromFieldSchema($fieldSchema);
        }

        return new self(
            entity: $entityName,
            className: $modelClass,
            label: $schema->label ?? $entityName,
            icon: $schema->icon ?? strtolower($entityName),
            table: $table,
            module: $module,
            namespace: $namespace,
            fields: $exportFields,
            permissions: $schema->permissions ?? [],
            layout: $schema->layout ?? 'grid',
            description: $schema->description ?? null,
        );
    }

    /**
     * Convert the schema into a serializable array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entity' => $this->entity,
            'className' => $this->className,
            'label' => $this->label,
            'icon' => $this->icon,
            'table' => $this->table,
            'module' => $this->module,
            'namespace' => $this->namespace,
            'fields' => array_map(fn (PhiiExportField $field): array => $field->toArray(), $this->fields),
            'permissions' => $this->permissions,
            'layout' => $this->layout,
            'description' => $this->description,
        ];
    }

    /**
     * Reconstructs a PhiiExportSchema instance from an array representation.
     *
     * @param array<string, mixed> $data
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $fields = array_map(
            fn (array $fieldData): PhiiExportField => PhiiExportField::fromArray($fieldData),
            $data['fields'] ?? []
        );

        return new self(
            entity: $data['entity'],
            className: $data['className'],
            label: $data['label'],
            icon: $data['icon'],
            table: $data['table'],
            module: $data['module'],
            namespace: $data['namespace'],
            fields: $fields,
            permissions: $data['permissions'] ?? [],
            layout: $data['layout'] ?? 'grid',
            description: $data['description'] ?? null,
        );
    }

    /**
     * Returns names of fields designated for forms.
     *
     * @return array<int, string>
     */
    public function getFormFieldNames(): array
    {
        $names = [];
        foreach ($this->fields as $field) {
            if (!$field->readonly && $field->writable) {
               $names[] = $field->name;
            }
        }

        return $names;
    }

    /**
     * Returns names of fields designated as table listing columns.
     *
     * @return array<int, string>
     */
    public function getColumnFieldNames(): array
    {
        $names = [];
        foreach ($this->fields as $field) {
            if ($field->column) {
                $names[] = $field->name;
            }
        }

        return $names;
    }
}