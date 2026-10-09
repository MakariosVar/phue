<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Stubs;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Modules\Schii\Attribute\UI;

#[UI(label: 'Profiles', icon: 'card', layout: 'cards', description: 'User profile cards')]
class Profile extends BaseActiveRecord
{
    protected string $table = 'profiles';

    #[Field(type: 'integer', label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text', label: 'Full Name', required: true, searchable: true, sortable: true)]
    public string $full_name = '';

    #[Field(type: 'email', label: 'Email Address', required: true, searchable: true)]
    public string $email = '';

    #[Field(type: 'boolean', label: 'Active', widget: 'checkbox', filterable: true)]
    public bool $active = false;
}