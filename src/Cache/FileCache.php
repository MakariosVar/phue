<?php

declare(strict_types=1);

namespace Phue\Cache;

use DateInterval;
use DateTime;
use Psr\SimpleCache\CacheInterface;

/**
 * File-based PSR-16 Simple Cache Implementation.
 *
 * Stores cached items as JSON files in the designated storage directory.
 *
 * @package Phue\Cache
 */
class FileCache implements CacheInterface
{
    /**
     * FileCache constructor.
     *
     * @param string $storagePath Absolute filesystem path to cache storage directory.
     */
    public function __construct(private readonly string $storagePath)
    {
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0775, true);
        }
    }

    /**
     * Fetches a value from the cache.
     *
     * @param string $key     The unique key of this item in the cache.
     * @param mixed  $default Default value to return if the key doesn't exist.
     *
     * @return mixed The value of the item from the cache, or $default in case of cache miss.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return $default;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return $default;
        }

        /** @var array{value: mixed, expires_at: int|null}|null $data */
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return $default;
        }

        if (isset($data['expires_at']) && $data['expires_at'] !== null && time() > $data['expires_at']) {
            $this->delete($key);
            return $default;
        }

        return $data['value'] ?? $default;
    }

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional TTL expiration time.
     *
     * @param string                 $key   The key of the item to store.
     * @param mixed                  $value The value of the item to store, must be serializable.
     * @param null|int|DateInterval  $ttl   Optional. The TTL value of this item.
     *
     * @return bool True on success and false on failure.
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $expiresAt = null;

        if ($ttl instanceof DateInterval) {
            $expiresAt = (new DateTime())->add($ttl)->getTimestamp();
        } elseif (is_int($ttl)) {
            $expiresAt = time() + $ttl;
        }

        $data = [
            'value' => $value,
            'expires_at' => $expiresAt,
        ];

        $encoded = json_encode($data);
        if ($encoded === false) {
            return false;
        }

        return file_put_contents($this->getFilePath($key), $encoded, LOCK_EX) !== false;
    }

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key The unique cache key of the item to delete.
     *
     * @return bool True if the item was successfully removed or did not exist.
     */
    public function delete(string $key): bool
    {
        $filePath = $this->getFilePath($key);

        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return true;
    }

    /**
     * Wipes clean the entire cache directory.
     *
     * @return bool True on success and false on failure.
     */
    public function clear(): bool
    {
        $files = glob($this->storagePath . '/*.cache');
        if ($files === false) {
            return false;
        }

        $success = true;
        foreach ($files as $file) {
            if (is_file($file) && !unlink($file)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param iterable<string> $keys    A list of keys that can be obtained in a single operation.
     * @param mixed            $default Default value to return for keys that do not exist.
     *
     * @return iterable<string, mixed> A map of key to value pairs.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * Persists a set of key-value pairs in the cache.
     *
     * @param iterable<string, mixed> $values A list of key => value pairs for a multiple-set operation.
     * @param null|int|DateInterval   $ttl    Optional. The TTL value of this item.
     *
     * @return bool True on success and false on failure.
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable<string> $keys A list of string-based keys to be deleted.
     *
     * @return bool True if all items were successfully removed.
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete((string) $key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Determines whether an item is present in the cache.
     *
     * @param string $key The cache item key.
     *
     * @return bool True if item exists and is not expired.
     */
    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    /**
     * Generate target storage path for a given cache key.
     *
     * @param string $key Raw cache key string.
     *
     * @return string Absolute file path for cache file.
     */
    private function getFilePath(string $key): string
    {
        return $this->storagePath . '/' . md5($key) . '.cache';
    }
}