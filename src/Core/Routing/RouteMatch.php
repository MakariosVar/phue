<?php

namespace Phue\Core\Routing;

/**
 * Represents a matched route containing its handler, resolved parameters, and middleware pipeline.
 *
 * @package Phue\Core\Routing
 */
class RouteMatch
{
    /**
     * RouteMatch constructor.
     *
     * @param mixed                 $handler    Target controller callable or [Controller::class, 'method'].
     * @param array<string, string> $params     Key-value pairs extracted from URI placeholders.
     * @param array<string>         $middleware List of route-specific middleware classes.
     */
    public function __construct(
        private mixed $handler,
        private array $params = [],
        private array $middleware = []
    ) {}

    /**
     * Get the resolved route handler.
     *
     * @return mixed
     */
    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /**
     * Get extracted URI path parameters.
     *
     * @return array<string, string>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Get the assigned route middleware list.
     *
     * @return array<string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}