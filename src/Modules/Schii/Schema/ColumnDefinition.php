<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Schema;

/**
 * Readonly DTO representing a database table column definition.
 *
 * Normalized across database drivers (SQLite, MySQL, PostgreSQL) for schema diffing.
 *
 * @package Phue\Modules\Schii\Schema
 */
final readonly class ColumnDefinition
{
    /**
     * ColumnDefinition constructor.
     *
     * @param string    $name          Column database name.
     * @param string    $type          Normalized data type (e.g. 'varchar', 'int', 'text', 'boolean', 'date', 'datetime', 'float').
     * @param bool      $nullable      Whether the column allows NULL values.
     * @param mixed     $default       Default column value.
     * @param bool      $primaryKey    Whether the column is part of the primary key.
     * @param bool      $autoIncrement Whether the column is auto-incrementing.
     * @param bool      $indexed       Whether an index is defined on this column.
     * @param int       $length        Character or numeric length limit.
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = true,
        public mixed $default = null,
        public bool $primaryKey = false,
        public bool $autoIncrement = false,
        public bool $indexed = false,
        public int $length = 255,
    ) {}

    /**
     * Rehydrate a ColumnDefinition instance from an array.
     *
     * @param array<string, mixed> $data
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            type: (string) ($data['type'] ?? 'varchar'),
            nullable: (bool) ($data['nullable'] ?? true),
            default: $data['default'] ?? null,
            primaryKey: (bool) ($data['primaryKey'] ?? false),
            autoIncrement: (bool) ($data['autoIncrement'] ?? false),
            indexed: (bool) ($data['indexed'] ?? false),
            length: (int) ($data['length'] ?? 255),
        );
    }

    /**
     * Convert the column definition into a serializable array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'primaryKey' => $this->primaryKey,
            'autoIncrement' => $this->autoIncrement,
            'indexed' => $this->indexed,
            'length' => $this->length,
        ];
    }
}