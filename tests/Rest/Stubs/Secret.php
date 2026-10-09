<?php

declare(strict_types=1);

namespace Phue\Tests\Rest\Stubs;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Modules\Rest\Attribute\Rest;
use Phue\Modules\Schii\Attribute\UI;

#[UI(label: 'Secret', icon: 'key')]
#[Rest()]
class Secret extends BaseActiveRecord
{
    protected string $table = 'secrets';

    #[Field(type: 'text')]
    public string $name;

    #[Field(type: 'text')]
    public ?string $secret_value = null;

    #[Rest(method: 'POST', slug: 'login', public: true)]
    public function login(array $params): array
    {
        return ['token' => 'demo-token'];
    }

    #[Rest(method: 'GET', slug: 'state')]
    public function state(array $params): array
    {
        return ['state' => 'ok'];
    }

    #[Rest(method: 'GET', slug: 'explode', public: true)]
    public function explode(): never
    {
        throw new \RuntimeException('internal boom');
    }
}