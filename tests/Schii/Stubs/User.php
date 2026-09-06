<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Stubs;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Schii\Attribute\Field;
use Phue\Schii\Attribute\UI;

#[UI(label: 'Users', icon: 'user')]
class User extends BaseActiveRecord
{
    protected string $table = 'users';

    #[Field(type: 'integer', label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text', required: true, searchable: true)]
    public string $name;
}