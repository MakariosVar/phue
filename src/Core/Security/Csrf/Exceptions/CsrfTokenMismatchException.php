<?php

declare(strict_types=1);

namespace Phue\Core\Security\Csrf\Exceptions;

use Phue\Core\Http\Exceptions\HttpException;

/**
 * Raised when an unsafe request does not carry a token this application issued.
 *
 * Extends HttpException so the error handler renders a well-formed 403 instead of a bare
 * 500: a rejected token is the client's mistake, and 403 says exactly that.
 *
 * The message is deliberately uniform. "Missing token", "malformed token" and "wrong
 * signature" all produce this same exception with this same text, because telling them
 * apart is an oracle — it answers an attacker question, namely whether a guessed value at
 * least had the right shape. The reason is carried separately and is meant for the log,
 * which the operator reads and the attacker does not.
 *
 * @package Phue\Core\Security\Csrf
 */
final class CsrfTokenMismatchException extends HttpException
{
    /**
     * @param string $message Uniform client-facing message
     * @param string $reason Diagnostic detail for the log; never rendered to the client
     * @param int $statusCode HTTP status to report
     */
    private function __construct(string $message, private readonly string $reason, int $statusCode)
    {
        parent::__construct($message, $statusCode);
    }

    /**
     * @param string $reason Diagnostic detail for the log. Never rendered to the client.
     * @return self
     */
    public static function because(string $reason): self
    {
        return new self('Invalid or missing CSRF token.', $reason, 403);
    }

    /**
     * Why the token was rejected, for logging. Deliberately absent from getMessage().
     *
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}