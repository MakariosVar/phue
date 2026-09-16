<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class UI
{
    public function __construct(
        public string $label = '',
        public string $icon = 'table',
        public string $layout = 'grid',
        public ?string $description = null,
    ) {}
}