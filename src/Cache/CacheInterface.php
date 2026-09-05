<?php

declare(strict_types=1);

namespace Phue\Cache;

use Psr\SimpleCache\CacheInterface as PsrSimpleCacheInterface;

/**
 * Contract for Phue framework caching engines and edge storage drivers.
 * Extends PSR-16 Simple Cache interface.
 *
 * @package Phue\Cache
 */
interface CacheInterface extends PsrSimpleCacheInterface
{
    /**
     * @param iterable<string> $keys
     * @param mixed $default
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable;
}