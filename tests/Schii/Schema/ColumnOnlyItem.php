<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Schema;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Modules\Schii\Attribute\Column;

/**
 * Declares one property with #[Column] alone and one with both attributes.
 *
 * The contrast is the point: `display_only` says how it should look and
 * nothing about where its value comes from, while `real_column` declares its
 * persistence. Both are part of the entity; only one may be written.
 *
 * It lives in its own file because it is used by a unit test and an
 * integration test at once. PHPUnit runs the class whose name matches its
 * filename, so a model shared by two test classes cannot live in either of
 * them, and the two test classes cannot share a file.
 */
class ColumnOnlyItem extends BaseActiveRecord
{
    protected string $table = 'column_only_items';

    #[Column(label: 'Display Only', searchable: true)]
    public string $display_only = '';

    #[Field(type: 'text')]
    #[Column(label: 'Real Column')]
    public string $real_column = '';
}
