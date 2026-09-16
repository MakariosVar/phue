<?php

declare(strict_types=1);

namespace Phue\Modules\Rest\Config;

/**
 * Class RestConfig
 *
 * Value object representing configuration properties for the RestModule.
 *
 * @package Phue\Modules\Rest\Config
 */
class RestConfig
{
    /**
     * RestConfig constructor.
     *
     * @param string $prefix URL prefix for public REST endpoints
     * @param string $entityNamespace Namespace where models are located
     * @param bool $enabled Whether the RestModule is active
     * @param array<int, class-string> $middleware Middleware classes applied to REST routes
     */
    public function __construct(
        public readonly string $prefix = '/api',
        public readonly string $entityNamespace = 'App\\Models',
        public readonly bool $enabled = true,
        public readonly array $middleware = []
    ) {
    }

    /**
     * Creates a RestConfig instance from a raw array of configuration values.
     *
     * @param array<string, mixed> $data Raw configuration array
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            prefix: (string) ($data['prefix'] ?? '/api'),
            entityNamespace: (string) ($data['entity_namespace'] ?? $data['entityNamespace'] ?? 'App\\Models'),
            enabled: (bool) ($data['enabled'] ?? true),
            middleware: (array) ($data['middleware'] ?? [])
        );
    }
}