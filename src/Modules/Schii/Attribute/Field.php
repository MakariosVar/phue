<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final readonly class Field
{
    /**
     * @param string $type
     * @param string $label
     * @param string $widget
     * @param bool $column
     * @param bool $required
     * @param bool $searchable
     * @param bool $filterable
     * @param bool $sortable
     * @param bool $writable
     * @param bool $readonly
     * @param bool $primaryKey
     * @param array<string, mixed> $options Options for select, radio, etc.
     * @param array<string> $rules Validation rules (e.g., ['required', 'email', 'min:3'])
     */
    public function __construct(
        public string $type = 'text',
        public string $label = '',
        public string $widget = 'input',
        public bool $column = true,
        public bool $required = false,
        public bool $searchable = false,
        public bool $filterable = false,
        public bool $sortable = false,
        public array $options = [],
        public array $rules = [],
        public bool $writable = true,
        public bool $readonly = false,
        public bool $primaryKey = false,
    ) {}
}
