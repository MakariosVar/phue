<?php

declare(strict_types=1);

namespace {{ NAMESPACE }};

use Phue\Core\Database\DataMapper\Entity;

/**
 * Data Mapper entity for {{ MODEL_NAME }}.
 * Note: Data Mapper uses static tableName(), unlike ActiveRecord's instance getTable().
 *
 * @generated Phii Code Generator
 */
class {{ MODEL_NAME }} extends Entity
{
    public static function tableName(): string
    {
        return '{{ TABLE_NAME }}';
    }

{{ PROPERTIES }}
}