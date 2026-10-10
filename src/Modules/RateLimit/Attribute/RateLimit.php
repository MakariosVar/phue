<?php

declare(strict_types=1);

namespace Phue\Modules\RateLimit\Attribute;

use Attribute;

/**
 * Rate limit attribute for routes and controllers.
 *
 * Usage examples:
 * - #[RateLimit(60, 'minute')]                           // 60 requests per minute
 * - #[RateLimit(requests: 100, window: 'hour')]          // Named params
 * - #[RateLimit(policy: 'api')]                          // Reference named policy
 * - #[RateLimit(10, 'minute', key: 'user')]              // Per-user limit
 * - #[RateLimit(100, 'minute', scope: 'controller')]     // Controller-level scope
 *
 * @package Phue\Modules\RateLimit\Attribute
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RateLimit
{
    /**
     * @param int|string|null     $requests   Max requests (or policy name if string)
     * @param string|null         $window     Window: second, minute, hour, day, or custom seconds
     * @param string|null         $key        Key resolver: ip, user, api_key, or callable
     * @param string|null         $algorithm  Algorithm: sliding_window, token_bucket
     * @param string|null         $policy     Named policy reference
     * @param string|null         $scope      Scope: route, controller, global, group
     * @param array<string, mixed> $meta       Additional metadata
     */
    public function __construct(
        public int|string|null $requests = null,
        public ?string $window = null,
        public ?string $key = null,
        public ?string $algorithm = null,
        public ?string $policy = null,
        public ?string $scope = null,
        public array $meta = []
    ) {
        // Support #[RateLimit('api')] shorthand for policy reference
        if (is_string($this->requests) && $this->policy === null) {
            $this->policy = $this->requests;
            $this->requests = null;
        }
    }

    /**
     * Create from named policy reference.
     */
    public static function policy(string $name): self
    {
        return new self(policy: $name);
    }

    /**
     * Create inline configuration.
     */
    public static function inline(
        int $requests,
        string $window = 'minute',
        string $key = 'ip',
        string $algorithm = 'sliding_window',
        ?string $scope = null,
        array $meta = []
    ): self {
        return new self(
            requests: $requests,
            window: $window,
            key: $key,
            algorithm: $algorithm,
            scope: $scope,
            meta: $meta,
        );
    }
}