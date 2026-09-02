<?php

declare(strict_types=1);

namespace Phue\Cache;

/**
 * Contract for Phue framework caching engines and edge storage drivers.
 *
 * @package Phue\Cache
 */
interface CacheInterface
{
    /**
     * Retrieves an item from the cache by its key identifier.
     *
     * @param string $key Unique cache key identifier.
     * @param mixed $default Default value returned if the item does not exist.
     * @return mixed Cached item value or default.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Persists a key-value item in the cache with an optional TTL.
     *
     * @param string $key Unique cache key identifier.
     * @param mixed $value Value to store in cache.
     * @param int|null $ttl Lifetime in seconds (or null for infinite/driver default).
     * @return bool True on success, false on failure.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Removes a single item from the cache by its key identifier.
     *
     * @param string $key Unique cache key identifier.
     * @return bool True if deleted or missing, false on failure.
     */
    public function delete(string $key): bool;

    /**
     * Flushes all stored items from the cache repository.
     *
     * @return bool True on success, false on failure.
     */
    public function clear(): bool;

    /**
     * Determines whether an item exists in the cache for a given key.
     *
     * @param string $key Unique cache key identifier.
     * @return bool True if key exists, false otherwise.
     */
    public function has(string $key): bool;
}