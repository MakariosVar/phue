<?php

declare(strict_types=1);

namespace Phue\Tests\Rest\Stubs;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Rest\Attribute\Rest;
use Phue\Schii\Attribute\Field;
use Phue\Schii\Attribute\UI;

#[UI(label: 'Article', icon: 'file-text')]
#[Rest(public: true, operations: ['index', 'show'])]
class Article extends BaseActiveRecord
{
    protected string $table = 'articles';

    #[Field(type: 'text')]
    public string $title;

    #[Field(type: 'text')]
    public ?string $body = null;
}