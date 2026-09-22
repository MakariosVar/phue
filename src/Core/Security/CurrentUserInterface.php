<?php

declare(strict_types=1);

namespace Phue\Core\Security;

/**
 * Interface CurrentUserInterface
 *
 * Defines the contract for managing the current authenticated user's state and actions
 * within the Core, to be implemented by the Auth module.
 *
 * @package Phue\Core\Security
 */
interface CurrentUserInterface
{
    /**
     * Retrieves the current user's identity.
     *
     * @return IdentityInterface|null The identity instance, or null if the user is a guest.
     */
    public function getIdentity(): ?IdentityInterface;

    /**
     * Alias for getIdentity(). Retrieves the current user's identity.
     *
     * @return IdentityInterface|null The identity instance, or null if the user is a guest.
     */
    public function getUser(): ?IdentityInterface;

    /**
     * Retrieves the unique identifier of the current user.
     *
     * @return int|string|null The user ID, or null if not authenticated.
     */
    public function getId(): int|string|null;

    /**
     * Checks if the current user is a guest (not authenticated).
     *
     * @return bool True if the user is a guest, false otherwise.
     */
    public function isGuest(): bool;

    /**
     * Checks if the user has the specified permission.
     *
     * @param string $permission The name of the permission to check.
     * @return bool True if the user has the permission, false otherwise.
     */
    public function can(string $permission): bool;

    /**
     * Logs in a user using the provided identity.
     *
     * @param IdentityInterface $identity The identity instance to log in.
     * @return void
     */
    public function login(IdentityInterface $identity): void;

    /**
     * Logs out the current user.
     *
     * @return void
     */
    public function logout(): void;
}