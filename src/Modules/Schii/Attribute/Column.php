<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Attribute;

use Attribute;

/**
 * How one property is presented in the generated admin console.
 *
 * This is the admin half of what #[Field] used to carry, split out so that a
 * model can be described without the admin module installed. Persistence and
 * validation live in \Phue\Core\Database\Attributes\Field because they must
 * hold everywhere; presentation is a Schii concern because a project with no
 * admin console has no use for it.
 *
 * A property may carry both attributes. When it does, this one decides how the
 * field looks — its label, widget, and whether it is offered for search,
 * filtering and sorting — while #[Field] decides whether the value exists, how
 * it is cast, and whether it may be written.
 *
 * #[Column] alone does NOT make a property writable. Mass assignment is granted
 * by #[Field(writable: ...)] and by nothing else, because a property that
 * declared only how it should look has said nothing about where its value is
 * allowed to come from.
 *
 * @package Phue\Modules\Schii\Attribute
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final readonly class Column
{
    /**
     * @param string $label Display label; defaults to a humanized property name
     * @param string $widget Form widget hint
     * @param bool $searchable Whether the column participates in admin search
     * @param bool $filterable Whether the column is offered as an admin filter
     * @param bool $sortable Whether the column is offered for admin sorting
     * @param array<string, mixed> $options Choices for select, radio, etc.
     */
    public function __construct(
        public string $label = '',
        public string $widget = 'input',
        public bool $searchable = false,
        public bool $filterable = false,
        public bool $sortable = false,
        public array $options = [],
    ) {}
}