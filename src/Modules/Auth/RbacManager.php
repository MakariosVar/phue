<?php

declare(strict_types=1);

namespace Phue\Modules\Auth;

/**
 * Class RbacManager
 *
 * Role-Based Access Control (RBAC) Manager supporting permission inheritance trees,
 * static seed configuration, and cycle protection.
 *
 * @package Phue\Modules\Auth
 */
final class RbacManager
{
    /** @var array<string, array<int, string>> */
    private array $roles = [];

    /** @var array<string, array<int, string>> */
    private array $parents = [];

    /** @var array<int|string, array<int, string>> */
    private array $assignments = [];

    /**
     * RbacManager constructor.
     *
     * @param string|null $configPath Optional custom path to the RBAC seed configuration file.
     */
    public function __construct(?string $configPath = null)
    {
        $path = $configPath ?? __DIR__ . '/../../config/rbac_seeding.php';
        if (file_exists($path)) {
            /** @var array{
             *     roles?: array<string, array<int, string>>,
             *     assignments?: array<int|string, array<int, string>>
             * } $seedData */
            $seedData = require $path;

            if (!empty($seedData['roles'])) {
                foreach ($seedData['roles'] as $role => $permissions) {
                    $this->addRole($role, $permissions);
                }
            }

            if (!empty($seedData['assignments'])) {
                foreach ($seedData['assignments'] as $userId => $userRoles) {
                    foreach ((array)$userRoles as $role) {
                        $this->assign($userId, $role);
                    }
                }
            }
        }
    }

    /**
     * Registers a role with its granted permissions.
     *
     * @param string $role Unique role name.
     * @param array<int, string> $permissions Granted permission identifiers.
     * @return void
     */
    public function addRole(string $role, array $permissions = []): void
    {
        $this->roles[$role] = $permissions;
    }

    /**
     * Establishes parent-child inheritance between roles.
     *
     * @param string $parentRole Parent role inheriting child permissions.
     * @param string $childRole Target child role.
     * @return void
     */
    public function addChild(string $parentRole, string $childRole): void
    {
        $this->parents[$parentRole][] = $childRole;
    }

    /**
     * Assigns a role to a user ID statically.
     *
     * @param int|string $userId Unique user identifier.
     * @param string $role Name of the role to assign.
     * @return void
     */
    public function assign(int|string $userId, string $role): void
    {
        $this->assignments[$userId][] = $role;
    }

    /**
     * Evaluates permission access for a given user identifier or explicit role collection.
     *
     * @param int|string $userContext User ID identifier.
     * @param string $permission Target permission identifier to check.
     * @param array<int, string> $explicitRoles Optional explicit roles array passed directly from identity.
     * @return bool True if access is granted, false otherwise.
     */
    public function checkAccess(int|string $userContext, string $permission, array $explicitRoles = []): bool
    {
        $userRoles = !empty($explicitRoles)
            ? $explicitRoles
            : ($this->assignments[$userContext] ?? []);

        foreach ($userRoles as $role) {
            $visited = [];
            if ($this->hasPermission($role, $permission, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively traverses inheritance trees with cycle protection.
     *
     * @param string $role Target role name.
     * @param string $permission Target permission to check.
     * @param array<string, bool> $visited Roles visited tracker.
     * @return bool True if permitted, false otherwise.
     */
    private function hasPermission(string $role, string $permission, array &$visited): bool
    {
        if (isset($visited[$role])) {
            return false;
        }

        $visited[$role] = true;

        $directPermissions = $this->roles[$role] ?? [];
        if (in_array($permission, $directPermissions, true) || in_array('*', $directPermissions, true)) {
            return true;
        }

        $inheritedRoles = $this->parents[$role] ?? [];
        foreach ($inheritedRoles as $childRole) {
            if ($this->hasPermission($childRole, $permission, $visited)) {
                return true;
            }
        }

        return false;
    }
}
