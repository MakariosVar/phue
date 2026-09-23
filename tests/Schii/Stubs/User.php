<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Stubs;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Modules\Schii\Attribute\Field;
use Phue\Modules\Schii\Attribute\UI;

#[UI(label: 'User', icon: 'user')]
class User extends BaseActiveRecord
{
    protected string $table = 'users';

    #[Field(type: 'text', rules: ['min:3', 'max:40'])]
    public string $username;

    #[Field(type: 'text', writable: false, readonly: true)]
    public string $password_hash;

    #[Field(type: 'text', column: false)]
    public ?string $auth_key = null;

    #[Field(type: 'text')]
    public mixed $roles = '[]';
}