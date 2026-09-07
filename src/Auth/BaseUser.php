<?php

declare(strict_types=1);

namespace Phue\Auth;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

/**
 * Class BaseUser
 *
 * Base ActiveRecord implementation of IdentityInterface.
 *
 * @package Phue\Auth
 */
abstract class BaseUser extends BaseActiveRecord implements IdentityInterface
{
    /**
     * Resolves user instance by primary key.
     *
     * @param int|string $id Primary key identifier.
     * @return static|null
     */
    public static function findIdentity(int|string $id): ?static
    {
        return static::find($id);
    }

    /**
     * Resolves user instance by Bearer token key (`auth_key`).
     *
     * @param string $key Bearer token string.
     * @return static|null
     */
    public static function findIdentityByAuthKey(string $key): ?static
    {
        return static::query()
            ->where('auth_key', '=', $key)
            ->first();
    }

    /**
     * Retrieves primary key value.
     *
     * @return int|string
     */
    public function getId(): int|string
    {
        $pk = $this->getPrimaryKey();
        return $this->attributes[$pk] ?? $this->$pk ?? 0;
    }

    /**
     * Retrieves authorization key.
     *
     * @return string|null
     */
    public function getAuthKey(): ?string
    {
        return $this->attributes['auth_key'] ?? null;
    }

    /**
     * Retrieves assigned RBAC roles array.
     *
     * @return array<int, string>
     */
    public function getRoles(): array
    {
        $roles = $this->attributes['roles'] ?? [];

        if (is_string($roles)) {
            /** @var array<int, string> $decoded */
            $decoded = json_decode($roles, true) ?? [];
            return $decoded;
        }

        return is_array($roles) ? $roles : [];
    }

    /**
     * Validates plain password against stored hash.
     *
     * @param string $plainPassword
     * @return bool
     */
    public function validatePassword(string $plainPassword): bool
    {
        $hash = $this->attributes['password_hash'] ?? '';
        return password_verify($plainPassword, $hash);
    }
}