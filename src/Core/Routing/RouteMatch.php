<?php

namespace Phue\Core\Routing;

/**
 * Represents a matched route containing its handler and resolved parameters.
 */
class RouteMatch
{
    /**
     * @param mixed $handler Target controller/callable to process the request
     * @param array<string, string> $params Key-value pairs extracted from URI placeholders
     */
    public function __construct(
        private mixed $handler,
        private array $params = []
    ) {}

    /**
     * Get the resolved route handler.
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
}