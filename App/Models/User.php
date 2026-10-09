<?php

declare(strict_types=1);

namespace App\Models;

use Phue\Core\Database\Attributes\Field;
use Phue\Modules\Auth\BaseUser;
use Phue\Modules\Schii\Attribute\Column;
use Phue\Modules\Schii\Attribute\UI;

/**
 * Class User
 *
 * App User model targeting the database table.
 *
 * @package App\Models
 */
#[UI(
    label: 'Users',
    icon: 'people',
    layout: 'grid',
    description: 'Manage application users and their roles.'
)]
class User extends BaseUser
{
    /**
     * The attributes that should be hidden for serialization.
     * @var array<int, string>
     */
    protected array $hidden = ['password_hash', 'auth_key'];

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    #[Column(sortable: true)]
    public int $id;

    #[Field(type: 'string', required: true, indexed: true, rules: ['required', 'min:3', 'max:50'])]
    #[Column(label: 'Username', searchable: true, filterable: true, sortable: true)]
    public string $username;

    #[Field(type: 'string', writable: false, readonly: true, column: false)]
    #[Column(label: 'Password Hash')]
    public string $password_hash;

    #[Field(type: 'string', writable: false, readonly: true, column: false)]
    #[Column(label: 'Auth Key')]
    public ?string $auth_key = null;

    #[Field(type: 'json', writable: false)]
    #[Column(label: 'Roles', searchable: false, filterable: false, sortable: false)]
    public array $roles = [];
}