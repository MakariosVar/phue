<?php

declare(strict_types=1);

namespace Phue\Schii\Schema;

final readonly class ModelSchema
{
    /**
     * @param array<string, FieldSchema> $fields
     * @param array<string, string> $permissions
     */
    public function __construct(
        public string $entity,
        public string $className,
        public string $label,
        public string $icon,
        public string $layout,
        public ?string $description,
        public array $fields,
        public array $permissions = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entity' => $this->entity,
            'label' => $this->label,
            'icon' => $this->icon,
            'layout' => $this->layout,
            'description' => $this->description,
            'permissions' => $this->permissions,
            'fields' => array_map(
                static fn (FieldSchema $field): array => $field->toArray(),
                array_values($this->fields)
            ),
        ];
    }
}