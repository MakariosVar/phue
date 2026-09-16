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

    #[Field(type: 'text')]
    public string $username;

    #[Field(type: 'text')]
    public string $password_hash;

    #[Field(type: 'text')]
    public ?string $auth_key = null;

    #[Field(type: 'text')]
    public mixed $roles = '[]';
}