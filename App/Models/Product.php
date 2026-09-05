<?php

declare(strict_types=1);

namespace App\Models;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Schii\Attribute\UI;
use Phue\Schii\Attribute\Field;

#[UI(label: 'Products', icon: 'box', description: 'Product inventory management')]
class Product extends BaseActiveRecord
{
    protected string $table = 'products';

    #[Field(type: 'integer', label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Field(type: 'text', required: true, searchable: true, sortable: true)]
    public string $name;

    #[Field(type: 'number', required: true, filterable: true, sortable: true)]
    public float $price;

    #[Field(type: 'textarea', searchable: true)]
    public ?string $description = null;

    #[Field(type: 'datetime', sortable: true)]
    public ?string $created_at = null;

    #[Field(type: 'datetime')]
    public ?string $updated_at = null;
}