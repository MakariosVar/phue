<?php

declare(strict_types=1);

namespace Phue\Schii\Schema;

/**
 * Readonly value object representing individual model field attributes and validation metadata.
 *
 * @package Phue\Schii\Schema
 */
final readonly class FieldSchema
{
    /**
     * FieldSchema constructor.
     *
     * @param string               $name       Field database/property column name.
     * @param string               $type       Data type identifier (e.g. 'string', 'integer').
     * @param string               $label      Human-readable field label.
     * @param string               $widget     UI Form widget key.
     * @param bool                 $column     Whether to render as a table listing column.
     * @param bool                 $required   Validation requirement indicator.
     * @param bool                 $searchable Search query compatibility.
     * @param bool                 $filterable Filter panel capability.
     * @param bool                 $sortable   Order-by capability.
     * @param array<string, mixed> $options    UI or widget-specific component options.
     * @param array<string>        $rules      Validation rules.
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
    ) {}

    /**
     * Rehydrate a FieldSchema instance from a raw array structure (1:1 with constructor properties).
     *
     * @param array<string, mixed> $data Serialized field metadata array.
     *
     * @return self Rehydrated FieldSchema instance.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            type: (string) ($data['type'] ?? 'string'),
            label: (string) ($data['label'] ?? ''),
            widget: (string) ($data['widget'] ?? 'text'),
            column: (bool) ($data['column'] ?? false),
            required: (bool) ($data['required'] ?? false),
            searchable: (bool) ($data['searchable'] ?? false),
            filterable: (bool) ($data['filterable'] ?? false),
            sortable: (bool) ($data['sortable'] ?? false),
            options: (array) ($data['options'] ?? []),
            rules: (array) ($data['rules'] ?? []),
        );
    }

    /**
     * Convert the field schema into a serializable array (1:1 with constructor properties).
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
        ];
    }
}