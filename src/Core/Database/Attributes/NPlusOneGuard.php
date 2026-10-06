<?php

declare(strict_types=1);

namespace Phue\Core\Database\Attributes;

use Attribute;

/**
 * Marks a controller action to enable N+1 query detection.
 *
 * When applied, the framework tracks all database queries during the request
 * and throws an exception if it detects the same query pattern executed
 * repeatedly (indicating an N+1 problem).
 *
 * @package Phue\Core\Database\Attributes
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class NPlusOneGuard
{
    /**
     * @param int $threshold Minimum number of similar queries to trigger detection (default: 5)
     * @param bool $throw Whether to throw exception or just log warning (default: true)
     */
    public function __construct(
        public int $threshold = 5,
        public bool $throw = true
    ) {}
}