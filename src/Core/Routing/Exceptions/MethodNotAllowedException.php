<?php

namespace Phue\Core\Routing\Exceptions;

use Exception;

/**
 * Thrown when the URI pattern matches, but the HTTP method is not allowed.
 */
class MethodNotAllowedException extends Exception
{
    /**
     * @param array<string> $allowedMethods List of HTTP methods supported by the matched route
     */
    public function __construct(
        private array $allowedMethods,
        string $message = 'HTTP Method Not Allowed'
    ) {
        parent::__construct($message, 405);
    }

    /**
     * Get allowed HTTP methods for the requested URI.
     *
     * @return array<string>
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}