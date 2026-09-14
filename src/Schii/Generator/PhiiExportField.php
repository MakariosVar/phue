<?php

declare(strict_types=1);

namespace Phue\Schii\Generator;

use Phue\Schii\Schema\FieldSchema;

/**
 * Readonly export field value object used by the Phii generator.
 *
 * @package Phue\Schii\Generator
 */
final readonly class PhiiExportField
{
    /**
     * PhiiExportField constructor.
     *
     * @param string                   $name       Field database/property column name.
     * @param string                   $type       Data type identifier (e.g., 'text', 'number', 'select', 'email', 'date').
     * @param string                   $label      Human-readable field label.
     * @param string                   $widget     UI Form widget key (e.g., 'input', 'select', 'checkbox', 'radio').
     * @param bool                     $column     Whether to render as a table listing column.
     * @param bool                     $required   Validation requirement indicator.
     * @param bool                     $searchable Search query compatibility.
     * @param bool                     $filterable Filter panel capability.
     * @param bool                     $sortable   Order-by capability.
     * @param array<string, mixed>     $options    UI or widget-specific component options.
     * @param array<int|string, mixed> $rules      Validation rules.
     * @param array<string, mixed>     $extra      Additional export metadata.
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public string $widget,
        public bool $column,
        public bool $required,
        public bool $searchable,
        public bool $filterable,
        public bool $sortable,
        public array $options = [],
        public array $rules = [],
        public array $extra = [],
    ) {}

    /**
     * Creates a PhiiExportField from a Schii FieldSchema instance.
     *
     * @param FieldSchema $field The source field schema object.
     *
     * @return self
     */
    public static function fromFieldSchema(FieldSchema $field): self
    {
        return new self(
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
            extra: [],
        );
    }

    /**
     * Reconstructs a PhiiExportField instance from an array representation.
     *
     * @param array<string, mixed> $data
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            type: $data['type'],
            label: $data['label'],
            widget: $data['widget'],
            column: (bool) ($data['column'] ?? false),
            required: (bool) ($data['required'] ?? false),
            searchable: (bool) ($data['searchable'] ?? false),
            filterable: (bool) ($data['filterable'] ?? false),
            sortable: (bool) ($data['sortable'] ?? false),
            options: $data['options'] ?? [],
            rules: $data['rules'] ?? [],
            extra: $data['extra'] ?? [],
        );
    }

    /**
     * Convert the export field into a serializable array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'widget' => $this->widget,
            'column' => $this->column,
            'required' => $this->required,
            'searchable' => $this->searchable,
            'filterable' => $this->filterable,
            'sortable' => $this->sortable,
            'options' => $this->options,
            'rules' => $this->rules,
            'extra' => $this->extra,
        ];
    }
}