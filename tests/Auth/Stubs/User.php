<?php

declare(strict_types=1);

namespace Phue\Tests\Auth\Stubs;

use Phue\Core\Database\Attributes\Field;
use Phue\Modules\Auth\BaseUser;

/**
 * Minimal identity for the session-auth flow tests.
 *
 * Mirrors only what the flow actually exercises — the table shape the authenticator
 * hydrates from, and the column casts the assertions read back. Deliberately not a
 * copy of the application's user model: the suite must stay runnable without an
 * App/ tree, so the framework contract is pinned against a fixture instead.
 */
class User extends BaseUser
{
    protected string $table = 'users';

    /**
     * Never serialised, so an authorization endpoint cannot leak credentials.
     *
     * @var array<int, string>
     */
    protected array $hidden = ['password_hash', 'auth_key'];

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id;

    #[Field(type: 'string', required: true)]
    public string $username;

    #[Field(type: 'string', writable: false, readonly: true, column: false)]
    public string $password_hash;

    #[Field(type: 'string', writable: false, readonly: true, column: false)]
    public ?string $auth_key = null;

    #[Field(type: 'json', writable: false)]
    public array $roles = [];
}