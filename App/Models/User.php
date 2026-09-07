<?php

declare(strict_types=1);

namespace App\Models;

use Phue\Auth\IdentityInterface;

/**
 * Class User
 *
 * Domain model representing an authenticated user identity within the system.
 * Implements IdentityInterface to bridge application storage with the framework
 * authentication and RBAC authorization subsystems.
 *
 * @package App\Models
 */
class User implements IdentityInterface
{
    /**
     * User constructor.
     *
     * @param int|string $id Unique primary key or identifier of the user identity.
     * @param string $username Unique account credential or login handle.
     * @param string $passwordHash Bcrypt/Argon2 encrypted password payload.
     * @param array<int, string> $roles Collection of assigned RBAC role identifiers.
     * @param string|null $authKey Authentication persistence key or API Bearer token.
     */
    public function __construct(
        public int|string $id,
        public string $username,
        public string $passwordHash,
        public array $roles = [],
        public ?string $authKey = null
    ) {}

    /**
     * Locates and instantiates a User identity using a provided lookup token.
     * Evaluates matches against primary key (id), API authentication key (auth_key),
     * or handle (username).
     *
     * @param int|string $token Unique lookup token, Bearer key, or account primary identifier.
     * @return static|null Hydrated user model instance on successful match, null otherwise.
     */
    public static function findIdentity(int|string $token): ?self
    {
        $seedPath = __DIR__ . '/../../config/rbac_seeding.php';
        if (!file_exists($seedPath)) {
            return null;
        }

        /** @var array{seed_users?: array<int, array<string, mixed>>} $seedData */
        $seedData = require $seedPath;
        $users = $seedData['seed_users'] ?? [];

        $strToken = (string)$token;

        foreach ($users as $userData) {
            $matchesId = (string)($userData['id'] ?? '') === $strToken;
            $matchesAuthKey = (string)($userData['auth_key'] ?? '') === $strToken;
            $matchesUsername = (string)($userData['username'] ?? '') === $strToken;

            if ($matchesId || $matchesAuthKey || $matchesUsername) {
                return new self(
                    id: $userData['id'],
                    username: $userData['username'],
                    passwordHash: $userData['password_hash'],
                    roles: $userData['roles'] ?? [],
                    authKey: $userData['auth_key'] ?? null
                );
            }
        }

        return null;
    }

    /**
     * Retrieves the primary key identifier for this identity.
     *
     * @return int|string Primary user identifier.
     */
    public function getId(): int|string
    {
        return $this->id;
    }

    /**
     * Retrieves all assigned role keys associated with this identity.
     *
     * @return array<int, string> Array of RBAC role identifiers.
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * Retrieves the authorization token key used for identity validation.
     *
     * @return string|null The active authorization key, or null if unassigned.
     */
    public function getAuthKey(): ?string
    {
        return $this->authKey;
    }
}