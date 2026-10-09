<?php

declare(strict_types=1);

namespace Phue\Tests\Auth\Stubs;

use Phue\Core\Database\Attributes\Field;
use Phue\Modules\Auth\BaseUser;
use Phue\Modules\Schii\Attribute\UI;

#[UI(label: 'ExplorerAuthUser', icon: 'user')]
class ExplorerAuthUser extends BaseUser
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