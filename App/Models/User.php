<?php

declare(strict_types=1);

namespace App\Models;

use Phue\Modules\Auth\BaseUser;
use Phue\Modules\Schii\Attribute\Field;
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

    #[Field(
        type: 'integer',
        primaryKey: true,
        readonly: true,
        sortable: true,
    )]
    public int $id;

    #[Field(
        type: 'string',
        label: 'Username',
        required: true,
        searchable: true,
        filterable: true,
        sortable: true,
        indexed: true,
        rules: ['required', 'min:3', 'max:50'],
    )]
    public string $username;

    #[Field(
        type: 'string',
        label: 'Password Hash',
        writable: false,
        readonly: true,
        column: false,
    )]
    public string $password_hash;

    #[Field(
        type: 'string',
        label: 'Auth Key',
        writable: false,
        readonly: true,
        column: false,
    )]
    public ?string $auth_key = null;

    #[Field(
        type: 'json',
        label: 'Roles',
        writable: false,
        searchable: false,
        filterable: false,
        sortable: false,
    )]
    public array $roles = [];
}