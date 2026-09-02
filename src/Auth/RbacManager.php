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

    /**
     * Recursively checks role permissions through the inheritance chain with cycle detection.
     *
     * @param string $role Current role evaluated.
     * @param string $permission Required permission name.
     * @param array<string, bool> $visited Map of visited roles in current execution path.
     * @return bool
     */
    private function hasPermission(string $role, string $permission, array &$visited): bool
    {
        if (isset($visited[$role])) {
            return false; // Prevents infinite recursion on cycles
        }

        $visited[$role] = true;

        $directPermissions = $this->roles[$role] ?? [];
        if (in_array($permission, $directPermissions, true)) {
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