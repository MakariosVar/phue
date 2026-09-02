<?php

declare(strict_types=1);

namespace Phue\Auth;

/**
 * Interface representing an authenticated user identity.
 *
 * @package Phue\Auth
 */
interface IdentityInterface
{
    /**
     * Get the unique identifier for the user identity.
     *
     * @return int|string
     */
    public function getId(): int|string;

    /**
     * Find an identity by its unique identifier.
     *
     * @param int|string $id
     * @return self|null
     */
    public static function findIdentity(int|string $id): ?self;

    /**
     * Retrieve access token string if identity supports token authentication.
     *
     * @return string|null
     */
    public function getAuthKey(): ?string;
}