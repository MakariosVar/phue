<?php

declare(strict_types=1);

namespace Phue\Schii\Schema;

/**
 * Readonly value object representing the metadata schema of an active record model.
 *
 * @package Phue\Schii\Schema
 */
final readonly class ModelSchema
{
    /**
     * ModelSchema constructor.
     *
     * @param string                     $entity      Entity short identifier.
     * @param string                     $className   Fully qualified class name (FQCN).
     * @param string                     $label       Human-readable label.
     * @param string                     $icon        UI icon key.
     * @param string                     $layout      UI layout structure.
     * @param string|null                $description Detailed schema description.
     * @param array<string, FieldSchema> $fields      Mapped schema field objects.
     * @param array<string, string>      $permissions Entity permission mappings.
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
     * Rehydrate a ModelSchema object instance from a raw array structure (e.g., L2 JSON cache).
     *
     * @param array<string, mixed> $data Serialized schema array.
     *
     * @return self Rehydrated schema object instance.
     */
    public static function fromArray(array $data): self
    {
        $fields = [];
        if (isset($data['fields']) && is_array($data['fields'])) {
            foreach ($data['fields'] as $key => $fieldData) {
                if ($fieldData instanceof FieldSchema) {
                    $fields[$key] = $fieldData;
                } elseif (is_array($fieldData)) {
                    $fieldObj = FieldSchema::fromArray($fieldData);
                    $fields[$fieldObj->name ?? (string) $key] = $fieldObj;
                }
            }
        }

        return new self(
            entity: (string) ($data['entity'] ?? ''),
            className: (string) ($data['className'] ?? ''),
            label: (string) ($data['label'] ?? ''),
            icon: (string) ($data['icon'] ?? ''),
            layout: (string) ($data['layout'] ?? 'default'),
            description: isset($data['description']) ? (string) $data['description'] : null,
            fields: $fields,
            permissions: (array) ($data['permissions'] ?? []),
        );
    }

    /**
     * Convert the model schema and nested field instances into a serializable array.
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