<?php

declare(strict_types=1);

namespace Phue\Core\Security\Csrf\Exceptions;

use RuntimeException;

/**
 * Raised when the application secret is absent or cannot be used for signing.
 *
 * This is a misconfiguration, not a client error, so it deliberately does not extend
 * HttpException: it must not be rendered as a tidy 4xx, and it must not be conflated
 * with a rejected token. It exists as its own type so the caller can catch it, log it,
 * and act on it differently from a genuine security rejection.
 *
 * Every message names the command that fixes the problem. A message that only says
 * "missing application key" leaves the operator without a next step, and in production
 * the response body will not show it at all.
 *
 * @package Phue\Core\Security\Csrf
 */
final class MissingApplicationKeyException extends RuntimeException
{
    /**
     * The key is not set at all, or is set to something Env coerced to a null equivalent.
     *
     * @return self
     */
    public static function absent(): self
    {
        return new self(
            'APP_KEY is not configured. Run `./phue generate:key` to generate one. '
                . 'Requests that change state are refused until it exists.'
        );
    }

    /**
     * The key exists but cannot be used as a signing key.
     *
     * @param string $reason Why the value was rejected, without echoing the secret
     * @return self
     */
    public static function unusable(string $reason): self
    {
        return new self(
            'APP_KEY cannot be used for signing (' . $reason . '). '
                . 'Run `./phue generate:key` to replace it with a valid one. '
                . 'Rotating the key invalidates tokens already issued under the old value.'
        );
    }
}