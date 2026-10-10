<?php

declare(strict_types=1);

namespace Phue\Core\Security;

/**
 * Interface IdentityInterface
 * 
 * Defines the security identity contract for user authentication models within the Core.
 * 
 * @package Phue\Core\Security
 */
interface IdentityInterface 
{
    /**
     * Returns the unique identifier for the identity.
     *
     * @return int|string The unique identifier (ID) of the identity.
     */
    public function getId(): int|string;

    /**
     * Finds an identity by its unique identifier.
     *
     * @param int|string $id The unique identifier to search for.
     * @return self|null The identity instance if found, or null otherwise.
     */
    public static function findIdentity(int|string $id): ?self;

    /**
     * Returns a key that can be used for checking session-based authentication validity.
     *
     * @return string|null The authentication key string, or null if not applicable.
     */
    public function getAuthKey(): ?string;

    /**
     * Finds an identity by username.
     *
     * @param string $username The username to search for.
     * @return self|null The identity instance if found, or null otherwise.
     */
    public static function findByUsername(string $username): ?self;
}