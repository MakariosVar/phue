<?php

namespace Phue\Core\Routing\Attribute;

use Attribute;

/**
 * Attribute used to attach middleware to controller classes or methods.
 *
 * @package Phue\Core\Routing\Attribute
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Middleware
{
    /**
     * @var array<string> List of middleware class names.
     */
    public array $middlewares;

    /**
     * Middleware attribute constructor.
     *
     * @param string|array<string> $middleware Single middleware class or array of classes.
     */
    public function __construct(string|array $middleware)
    {
        $this->middlewares = is_string($middleware) ? [$middleware] : $middleware;
    }
}