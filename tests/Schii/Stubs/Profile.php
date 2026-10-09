<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Stubs;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Modules\Schii\Attribute\Column;
use Phue\Modules\Schii\Attribute\UI;

#[UI(label: 'Profiles', icon: 'card', layout: 'cards', description: 'User profile cards')]
class Profile extends BaseActiveRecord
{
    protected string $table = 'profiles';

    #[Field(type: 'integer')]
    #[Column(label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text', required: true)]
    #[Column(label: 'Full Name', searchable: true, sortable: true)]
    public string $full_name = '';

    #[Field(type: 'email', required: true)]
    #[Column(label: 'Email Address', searchable: true)]
    public string $email = '';

    #[Field(type: 'boolean')]
    #[Column(label: 'Active', widget: 'checkbox', filterable: true)]
    public bool $active = false;
}