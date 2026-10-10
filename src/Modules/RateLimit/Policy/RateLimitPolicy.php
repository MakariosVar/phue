<?php

declare(strict_types=1);

namespace Phue\Modules\RateLimit\Policy;

/**
 * Immutable rate limit policy configuration.
 *
 * @package Phue\Modules\RateLimit\Policy
 */
final readonly class RateLimitPolicy
{
    /**
     * @param string       $name         Policy identifier
     * @param int          $requests     Maximum requests allowed in the window
     * @param string       $window       Window size: 'second', 'minute', 'hour', 'day', or custom seconds (e.g., '90')
     * @param string       $algorithm    Algorithm: 'sliding_window' or 'token_bucket'
     * @param string       $keyResolver  Key resolver: 'ip', 'user', 'api_key', or callable string
     * @param string|null  $scope        Scope: 'route', 'controller', 'global', 'group'
     * @param array<string, mixed> $meta Additional metadata
     */
    public function __construct(
        public string $name,
        public int $requests,
        public string $window,
        public string $algorithm = 'sliding_window',
        public string $keyResolver = 'ip',
        public ?string $scope = null,
        public array $meta = []
    ) {}

    /**
     * Get window size in seconds.
     */
    public function getWindowSeconds(): int
    {
        return match ($this->window) {
            'second' => 1,
            'minute' => 60,
            'hour'   => 3600,
            'day'    => 86400,
            default  => (int) $this->window, // custom seconds
        };
    }

    /**
     * Create a policy from array config.
     */
    public static function fromArray(string $name, array $config): self
    {
        return new self(
            name: $name,
            requests: $config['requests'] ?? 100,
            window: $config['window'] ?? 'minute',
            algorithm: $config['algorithm'] ?? 'sliding_window',
            keyResolver: $config['key'] ?? 'ip',
            scope: $config['scope'] ?? null,
            meta: $config['meta'] ?? [],
        );
    }

    /**
     * Merge with another policy (other takes precedence).
     */
    public function merge(self $other): self
    {
        return new self(
            name: $other->name,
            requests: $other->requests,
            window: $other->window,
            algorithm: $other->algorithm,
            keyResolver: $other->keyResolver,
            scope: $other->scope ?? $this->scope,
            meta: array_merge($this->meta, $other->meta),
        );
    }

    /**
     * Create a policy from the #[RateLimit] attribute.
     */
    public static function fromAttribute(\Phue\Modules\RateLimit\Attribute\RateLimit $attr): self
    {
        return new self(
            name: $attr->policy ?? 'inline',
            requests: $attr->requests ?? 100,
            window: $attr->window ?? 'minute',
            algorithm: $attr->algorithm ?? 'sliding_window',
            keyResolver: $attr->key ?? 'ip',
            scope: $attr->scope,
            meta: $attr->meta ?? [],
        );
    }
}