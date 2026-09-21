<?php

declare(strict_types=1);

namespace Phue\Modules\Auth\Authenticator;

use Phue\Modules\Auth\IdentityInterface;
use Phue\Core\Request;

/**
 * Session-based authenticator using active session data.
 *
 * @package Phue\Modules\Auth\Authenticator
 */
final class SessionAuthenticator implements AuthenticatorInterface
{
    /**
     * Session authenticator.
     * 
     * @param string $identityClass The class name of the identity model.
     * @param string $sessionKey The session key to use for storing and retrieving the user ID.
     */
    public function __construct(
        private readonly string $identityClass,
        private readonly string $sessionKey = '__user_id'
    ) {}

    /**
     * Authenticates a user based on the session data.
     * 
     * @param Request $request The request object containing the session data.
     * @return IdentityInterface|null The authenticated user, or null if authentication fails.
     */
    public function authenticate(Request $request): ?IdentityInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $_SESSION[$this->sessionKey] ?? null;
        if ($userId === null) {
            return null;
        }

        return $this->identityClass::findIdentity($userId);
    }
}
