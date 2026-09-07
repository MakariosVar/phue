<?php

declare(strict_types=1);

namespace Phue\Auth;

/**
 * Role-Based Access Control (RBAC) Manager supporting permission inheritance trees with cycle protection.
 *
 * @package Phue\Auth
 */
final class RbacManager
{
    /** @var array<string, array<string>> Roles and their directly attached permissions */
    private array $roles = [];

    /** @var array<string, array<string>> Child inheritance relations */
    private array $parents = [];

    /** @var array<int|string, array<string>> User assignments */
    private array $assignments = [];

    /**
     * RbacManager constructor. Ingests seed configuration if available.
     *
     * @param string|null $configPath Optional path to RBAC seed configuration.
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

    public function addRole(string $role, array $permissions = []): void
    {
        $this->roles[$role] = $permissions;
    }

    public function addChild(string $parentRole, string $childRole): void
    {
        $this->parents[$parentRole][] = $childRole;
    }

    public function assign(int|string $userId, string $role): void
    {
        $this->assignments[$userId][] = $role;
    }

    public function checkAccess(int|string $userId, string $permission): bool
    {
        $userRoles = $this->assignments[$userId] ?? [];

        foreach ($userRoles as $role) {
            $visited = [];
            if ($this->hasPermission($role, $permission, $visited)) {
                return true;
            }
        }

        return false;
    }

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