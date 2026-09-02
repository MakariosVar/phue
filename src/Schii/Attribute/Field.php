<?php

declare(strict_types=1);

namespace Phue\Schii\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final readonly class Field
{
    /**
     * @param array<string, mixed> $options Επιλογές για select, radio, κλπ.
     * @param array<string> $rules Κανόνες validation (π.χ. ['required', 'email', 'min:3'])
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
    ) {}
}