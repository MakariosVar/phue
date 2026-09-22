<?php

declare(strict_types=1);

namespace Phue\Modules\Auth\Persistence;

/**
 * Class SessionLoginStore
 * 
 * Implementation of LoginStoreInterface that directly manages 
 * the `$_SESSION` array while enforcing the session status guard.
 * 
 * @package Phue\Modules\Auth\Persistence
 */
class SessionLoginStore implements LoginStoreInterface
{
    private const SESSION_KEY = '__user_id';

    /**
     * {@inheritdoc}
     */
    public function getId(): int|string|null
    {
        $this->ensureSessionActive();

        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function store(int|string $id): void
    {
        $this->ensureSessionActive();

        $_SESSION[self::SESSION_KEY] =$id;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->ensureSessionActive();

        unset($_SESSION[self::SESSION_KEY]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Checks if the session is active and starts it if not 
     * (session_start guard, mirroring the SessionAuthenticator logic).
     * 
     * @return void
     */
    private function ensureSessionActive(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}