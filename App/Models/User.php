<?php

declare(strict_types=1);

namespace App\Models;

use Phue\Modules\Auth\BaseUser;
use Phue\Modules\Schii\Attribute\Field;

/**
 * Class User
 *
 * App User model targeting the database table.
 *
 * @package App\Models
 */
class User extends BaseUser
{
    #[Field(type: 'integer')]
    public int $id;

    #[Field(type: 'text')]
    public string $username;

    #[Field(type: 'text')]
    public string $password_hash;

    #[Field(type: 'text')]
    public ?string $auth_key = null;

    #[Field(type: 'text')]
    public mixed $roles = '[]';
}