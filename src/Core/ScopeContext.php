<?php

declare(strict_types=1);

namespace Phue\Core;

/**
 * Value object representing an isolated execution scope identifier.
 * Used for partitioning request-scoped instances across concurrent coroutines.
 *
 * @package Phue\Core
 */
final class ScopeContext
{
    /**
     * @param string $id Unique scope identifier.
     */
    public function __construct(
        public readonly string $id
    ) {}
}