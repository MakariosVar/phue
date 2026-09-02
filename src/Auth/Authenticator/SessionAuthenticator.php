<?php

declare(strict_types=1);

namespace Phue\Auth\Authenticator;

use Phue\Auth\IdentityInterface;
use Phue\Core\Request;

/**
 * Session-based authenticator using active session data.
 *
 * @package Phue\Auth\Authenticator
 */
final class SessionAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly string $identityClass,
        private readonly string $sessionKey = '__user_id'
    ) {}

    public function authenticate(Request $request): ?IdentityInterface
    {
        $userId = $_SESSION[$this->sessionKey] ?? null;
        if ($userId === null) {
            return null;
        }

        return $this->identityClass::findIdentity($userId);
    }
}