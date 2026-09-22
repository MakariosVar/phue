<?php

declare(strict_types=1);

namespace Phue\Modules\Auth\Authenticator;

use Phue\Modules\Auth\IdentityInterface;
use Phue\Modules\Auth\Persistence\LoginStoreInterface;
use Phue\Core\Request;

/**
 * Session-based authenticator using active session data via LoginStore.
 *
 * @package Phue\Modules\Auth\Authenticator
 */
final class SessionAuthenticator implements AuthenticatorInterface
{
    /**
     * Session authenticator constructor.
     * 
     * @param string $identityClass The class name of the identity model.
     * @param LoginStoreInterface $loginStore The login store instance to retrieve session user ID.
     */
    public function __construct(
        private readonly string $identityClass,
        private readonly LoginStoreInterface $loginStore
    ) {}

    /**
     * Authenticates a user based on the session store data.
     * 
     * @param Request $request The request object.
     * @return IdentityInterface|null The authenticated user, or null if authentication fails.
     */
    public function authenticate(Request $request): ?IdentityInterface
    {
        $userId = $this->loginStore->getId();
        if ($userId === null) {
            return null;
        }

        return $this->identityClass::findIdentity($userId);
    }
}