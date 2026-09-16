<?php

declare(strict_types=1);

namespace Phue\Tests\Rest\Stubs;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Modules\Schii\Attribute\Field;
use Phue\Modules\Schii\Attribute\UI;

#[UI(label: 'Product', icon: 'box')]
class Product extends BaseActiveRecord
{
    protected string $table = 'products';

    #[Field(type: 'text')]
    public string $name;
}