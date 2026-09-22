<?php

declare(strict_types=1);

namespace Phue\Modules\Auth\Persistence;

/**
 * Interface LoginStoreInterface
 * 
 * Centralizes session storage handling for the user ID,
 * isolating I/O and the session guard away from controllers and authenticators.
 * 
 * @package Src\Modules\Auth\Persistence
 */
interface LoginStoreInterface
{
    /**
     * Reads the user ID from the session (`$_SESSION['__user_id']`) 
     * protected by the session_start guard.
     * 
     * @return int|string|null The user ID or null if not present.
     */
    public function getId(): int|string|null;

    /**
     * Stores the user ID into the session with a session_start guard.
     * 
     * @param int|string $id The user ID to store.
     * @return void
     */
    public function store(int|string $id): void;

    /**
     * Clears the session (unset user id + session_regenerate_id(true)).
     * 
     * @return void
     */
    public function clear(): void;
}