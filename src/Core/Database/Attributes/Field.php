<?php

declare(strict_types=1);

namespace Phue\Core\Database\Attributes;

use Attribute;

/**
 * Declares a model's persistence and validation contract for one property.
 *
 * This attribute lives in Core, not in a module, because it is not an admin
 * concern. `type` drives the auto-cast in BaseActiveRecord::getAutoCasts() —
 * a `#[Field(type: 'json')] public array $roles` becomes a json column whether
 * or not any admin module is installed. While this attribute was owned by a
 * module, Core's persistence layer had to name that module's class: an
 * application built without the admin module got no auto-casts at all, and
 * every typed property silently reached the database as a string.
 *
 * The UI-shaped parameters (label, searchable, sortable, widget, options, ...)
 * are declared here too rather than in a second attribute, so a property is
 * described in exactly one place and cannot disagree with itself. Modules read
 * what they need and ignore the rest; nothing in Core interprets them.
 *
 * @package Phue\Core\Database\Attributes
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final readonly class Field
{
    /**
     * @param string $type Persistence type, e.g. 'string', 'integer', 'float', 'json', 'boolean', 'datetime'
     * @param string $label Display label for admin forms and list columns
     * @param string $widget Admin form widget hint
     * @param bool $column Whether the property maps to a database column
     * @param bool $required Whether the value must be present on create
     * @param bool $searchable Whether the column participates in admin search
     * @param bool $filterable Whether the column is offered as an admin filter
     * @param bool $sortable Whether the column is offered for admin sorting
     * @param array<string, mixed> $options Options for select, radio, etc.
     * @param array<string> $rules Validation rules (e.g., ['required', 'email', 'min:3'])
     * @param bool $writable Whether client payloads may assign this property
     * @param bool $readonly Whether the value is read-only after creation
     * @param bool $primaryKey Whether the property is the model's primary key
     * @param bool $indexed Whether the generated migration adds an index
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
        public bool $indexed = false,
    ) {}
}