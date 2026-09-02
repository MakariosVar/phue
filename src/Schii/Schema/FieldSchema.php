<?php

declare(strict_types=1);

namespace Phue\Schii\Schema;

final readonly class FieldSchema
{
    /**
     * @param array<string, mixed> $options
     * @param array<string> $rules
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