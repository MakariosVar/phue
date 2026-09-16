<?php

declare(strict_types=1);

namespace Phue\Tests\Rest\Stubs;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Modules\Rest\Attribute\Rest;
use Phue\Modules\Schii\Attribute\Field;
use Phue\Modules\Schii\Attribute\UI;

#[UI(label: 'User', icon: 'user')]
#[Rest(public: true, hidden: ['password_hash'])]
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

    #[Rest(method: 'GET', slug: 'active-users')]
    public function activeUsers(array $params): array
    {
        $rows = [];
        foreach (self::query()->get() as $user) {
            $rows[] = $user->toArray();
        }

        return $rows;
    }

    #[Rest(method: 'POST')]
    public function activate(array $params, int $id): array
    {
        return ['ok' => true, 'userId' => $id];
    }

    #[Rest(method: 'GET', slug: 'profile')]
    public function profile(array $params, int $id)
    {
        return self::find($id);
    }
}