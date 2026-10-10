<?php

declare(strict_types=1);

namespace Phue\Core\Database\Attributes;

use Attribute;

/**
 * Marks an Active Record model as using soft deletes.
 *
 * When applied, the model's `delete()` method will update a timestamp column
 * instead of removing the row. Queries automatically exclude soft-deleted
 * records unless `withTrashed()` or `onlyTrashed()` is used.
 *
 * This attribute lives in Core so that the persistence layer (BaseActiveRecord
 * and ActiveQuery) can enforce the behavior without depending on any admin
 * module. A model may carry this attribute regardless of which modules are
 * installed.
 *
 * @package Phue\Core\Database\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SoftDelete
{
    /**
     * @param string $column Database column name that stores the deletion timestamp.
     *                       Defaults to 'deleted_at'. Must be a nullable datetime/timestamp column.
     * @param bool $cascade  Reserved for future relation cascade support. Currently unused.
     *                       When enabled, deleting the parent would soft-delete related models.
     */
    public function __construct(
        public string $column = 'deleted_at',
        public bool $cascade = false,
    ) {}
}