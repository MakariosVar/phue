<?php

declare(strict_types=1);

namespace Phue\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * In-Memory & Key-Value Cache Driver for Read-Only Edge Filesystems.
 *
 * Provides thread-safe, memory-backed fallback caching for SchemaRegistry and metadata.
 *
 * @package Phue\Cache
 */
final class EdgeKvCache implements CacheInterface
{
    /** @var array<string, mixed> In-memory cache key-value repository. */
    private array $memoryStorage = [];

    /**
     * Retrieves an item from the cache.
     *
     * @param string $key Cache key identifier.
     * @param mixed $default Default value if key does not exist.
     * @return mixed Cached item value or default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->memoryStorage[$key] ?? $default;
    }

    /**
     * Stores an item in the cache.
     *
     * @param string $key Cache key identifier.
     * @param mixed $value Value to store.
     * @param null|int|\DateInterval $ttl Optional TTL time to live.
     * @return bool True on success.
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->memoryStorage[$key] = $value;
        return true;
    }

    /**
     * Removes an item from the cache.
     *
     * @param string $key Cache key identifier.
     * @return bool True if deleted or missing.
     */
    public function delete(string $key): bool
    {
        unset($this->memoryStorage[$key]);
        return true;
    }

    /**
     * Flushes all stored cache items.
     *
     * @return bool True on success.
     */
    public function clear(): bool
    {
        $this->memoryStorage = [];
        return true;
    }

    /**
     * Checks whether an item exists in the cache.
     *
     * @param string $key Cache key identifier.
     * @return bool True if key exists.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->memoryStorage);
    }

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param iterable<string> $keys A list of keys that can be obtained in a single operation.
     * @param mixed $default Default value to return for keys that do not exist.
     * @return iterable<string, mixed> Key-value list of results.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get((string) $key, $default);
        }
        return $result;
    }

    /**
     * Persists a set of key-value pairs in the cache.
     *
     * @param iterable<string, mixed> $values A list of key-value pairs to store.
     * @param null|int|\DateInterval $ttl Optional TTL time to live.
     * @return bool True on success.
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }
        return true;
    }

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable<string> $keys A list of string-based keys to be deleted.
     * @return bool True on success.
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }
        return true;
    }
}