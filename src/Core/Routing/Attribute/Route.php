<?php

namespace Phue\Core\Routing\Attribute;

use Attribute;

/**
 * Attribute used to declare routes on controller classes and methods.
 *
 * @package Phue\Core\Routing\Attribute
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Route
{
    /**
     * @var array<string> Normalized list of HTTP methods in uppercase.
     */
    public array $methods;

    /**
     * Route constructor.
     *
     * @param string                $path    The route URI pattern (e.g., '/users/{id}').
     * @param array<string>         $methods Allowed HTTP methods (e.g., ['GET', 'POST']).
     * @param string|null           $name    Optional route name for URL generation.
     * @param array<string, string> $where   Custom regex constraints for path parameters.
     */
    public function __construct(
        public string $path = '/',
        array $methods = ['GET'],
        public ?string $name = null,
        public array $where = []
    ) {
        $this->methods = array_map('strtoupper', $methods);
    }
}