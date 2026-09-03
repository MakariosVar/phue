<?php

namespace App\Models;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

class Product extends BaseActiveRecord
{
    protected string $table = 'products';

    protected array $fillable = [
        'name',
        'price',
    ];

}
