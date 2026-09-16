<?php

declare(strict_types=1);

namespace Phue\Modules\Rest\Attribute;

use Attribute;

/**
 * Class Rest
 *
 * Attribute used on model classes or methods to declare public or authenticated exposure 
 * via the RestModule, specifying hidden fields, allowed CRUD operations, custom slugs, 
 * HTTP methods, and method-level visibility overrides for custom actions.
 *
 * @package Phue\Modules\Rest\Attribute
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Rest
{
    /**
     * Rest constructor.
     *
     * @param array<int, string> $hidden Fields hidden from outputs and blocked from inputs
     * @param bool|null $public Whether public (anonymous) access is allowed (null means inherit from class)
     * @param array<int, string> $operations Allowed operations (e.g. ['index', 'show', 'store', 'update', 'delete'])
     * @param string|null $slug Custom URL slug override instead of lowercase short name or method name
     * @param string $method HTTP method for custom action routes (e.g. 'POST', 'GET')
     */
    public function __construct(
        public readonly array $hidden = [],
        public readonly ?bool $public = null,
        public readonly array $operations = ['index', 'show', 'store', 'update', 'delete'],
        public readonly ?string $slug = null,
        public readonly string $method = 'POST'
    ) {
    }
}