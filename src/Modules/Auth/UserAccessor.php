<?php

declare(strict_types=1);

namespace Phue\Modules\Auth;

use Phue\Core\Security\CurrentUserInterface;
use Phue\Core\Security\IdentityInterface;
use Phue\Core\Request;
use Phue\Modules\Auth\Persistence\LoginStoreInterface;
use Phue\Modules\Auth\RbacManager;

/**
 * Class UserAccessor
 *
 * Acts as a scoped facade for authentication, session persistence, and authorization (RBAC).
 * Designed to be instantiated once per request via the container to avoid Swoole request leakage.
 *
 * @package Phue\Modules\Auth
 */
class UserAccessor implements CurrentUserInterface
{
    private ?IdentityInterface $identity = null;
    private bool $resolved = false;

    /**
     * UserAccessor constructor.
     *
     * @param AuthManager          $authManager
     * @param RbacManager          $rbacManager
     * @param LoginStoreInterface $loginStore
     * @param Request $request
     */
    public function __construct(
        private readonly AuthManager $authManager,
        private readonly RbacManager $rbacManager,
        private readonly LoginStoreInterface $loginStore,
        private readonly Request $request
    ) {
    }

    /**
     * Lazily authenticates the user ONCE per request using a memoization flag
     * to prevent redundant DB queries during view rendering or multiple checks.
     *
     * @return IdentityInterface|null
     */
    public function getIdentity(): ?IdentityInterface
    {
        if ($this->resolved) {
            return $this->identity;
        }

        $this->resolved = true;

        // Authenticate via AuthManager using the request
        $this->identity = $this->authManager->authenticate($this->request);

        return $this->identity;
    }

    /**
     * Alias for getIdentity().
     *
     * @return IdentityInterface|null
     */
    public function getUser(): ?IdentityInterface
    {
        return $this->getIdentity();
    }

    /**
     * Retrieves the current user ID from the session store.
     *
     * @return int|string|null
     */
    public function getId(): int|string|null
    {
        return $this->loginStore->getId();
    }

    /**
     * Checks whether the current user is a guest.
     *
     * @return bool
     */
    public function isGuest(): bool
    {
        return $this->getIdentity() === null;
    }

    /**
     * Checks if the current user has the specified permission via RbacManager.
     *
     * @param string $permission The permission to check.
     * @return bool
     */
    public function can(string $permission): bool
    {
        $identity = $this->getIdentity();

        if ($identity === null) {
            return false;
        }

        // Leveraging RbacManager::checkAccess(...) with identity ID and roles (RbacManager.php:101)
        return $this->rbacManager->checkAccess(
            $identity->getId(),
            $permission,
            method_exists($identity, 'getRoles') ? $identity->getRoles() : []
        );
    }

    /**
     * Logs in the given identity, updating both the session store and auth manager state.
     *
     * @param IdentityInterface $identity
     * @return void
     */
    public function login(IdentityInterface $identity): void
    {
        $this->loginStore->store($identity->getId());
        $this->authManager->login($identity);

        $this->identity = $identity;
        $this->resolved = true;
    }

    /**
     * Logs out the current user, clearing the session store and resetting the state.
     *
     * @return void
     */
    public function logout(): void
    {
        $this->loginStore->clear();
        $this->authManager->logout();

        $this->identity = null;
        $this->resolved = true;
    }
}