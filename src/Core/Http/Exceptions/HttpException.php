<?php

declare(strict_types=1);

namespace Phue\Core\Http\Exceptions;

use RuntimeException;

/**
 * Class HttpException
 *
 * Represents an HTTP-specific exception carrying an explicit status code
 * for proper client-facing error responses instead of defaulting to 500.
 *
 * @package Phue\Core\Http
 */
class HttpException extends RuntimeException
{
    /**
     * HttpException constructor.
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code (e.g. 400, 401, 403, 404, 405)
     */
    public function __construct(string $message, private readonly int $statusCode = 400)
    {
        parent::__construct($message, $statusCode);
    }

    /**
     * Get the explicit HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}