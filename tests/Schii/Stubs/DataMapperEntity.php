<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Stubs;

use Phue\Core\Database\DataMapper\Entity;

/**
 * Non-ActiveRecord entity used to prove that SchemaRegistry / PhiiExportSchema
 * reject models that do not extend BaseActiveRecord.
 */
class DataMapperEntity extends Entity
{
    public static function tableName(): string
    {
        return 'data_mapper_entities';
    }
}