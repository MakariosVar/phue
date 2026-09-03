<?php

declare(strict_types=1);

namespace Phue\Schii\Config;

/**
 * Schii Protocol Configuration Engine.
 *
 * Holds system-wide runtime settings, API prefixes, entity namespaces,
 * auto-migration flags, cache key prefixes, and middleware stack definitions
 * for Zero-Controller REST operations and Schema Generation.
 *
 * @package Phue\Schii\Config
 */
final readonly class SchiiConfig
{
    /**
     * Initializes a new immutable Schii Configuration instance.
     *
     * @param string $apiPrefix Base URI prefix for dynamically exposed Schii REST endpoints.
     * @param string $entityNamespace Fully qualified PHP namespace where Schii models reside.
     * @param bool $autoMigrate Whether automatic database schema migration is enabled during boot.
     * @param string $cachePrefix Cache key prefix for compiled model schema descriptors.
     * @param array<int, string|callable> $middleware Array of middleware pipeline callables/classes for Schii routes.
     */
    public function __construct(
        public string $apiPrefix = '/api/schii',
        public string $entityNamespace = 'App\\Models',
        public bool $autoMigrate = true,
        public string $cachePrefix = 'schii_schema_',
        public array $middleware = []
    ) {}

    /**
     * Creates a SchiiConfig instance from an associative options array,
     * overriding defaults only for keys that are explicitly supplied.
     *
     * @param array{
     *     apiPrefix?: string,
     *     entityNamespace?: string,
     *     autoMigrate?: bool,
     *     cachePrefix?: string,
     *     middleware?: array<int, string|callable>
     * } $options Associative array of configuration parameter overrides.
     * @return self Fresh SchiiConfig instance populated with merged options.
     */
    public static function fromArray(array $options): self
    {
        return new self(
            apiPrefix: $options['apiPrefix'] ?? '/api/schii',
            entityNamespace: $options['entityNamespace'] ?? 'App\\Models',
            autoMigrate: $options['autoMigrate'] ?? true,
            cachePrefix: $options['cachePrefix'] ?? 'schii_schema_',
            middleware: $options['middleware'] ?? []
        );
    }

    /**
     * Converts the current configuration object into an associative array representation.
     *
     * @return array{
     *     apiPrefix: string,
     *     entityNamespace: string,
     *     autoMigrate: bool,
     *     cachePrefix: string,
     *     middleware: array<int, string|callable>
     * } Associative array containing all configuration properties.
     */
    public function toArray(): array
    {
        return [
            'apiPrefix' => $this->apiPrefix,
            'entityNamespace' => $this->entityNamespace,
            'autoMigrate' => $this->autoMigrate,
            'cachePrefix' => $this->cachePrefix,
            'middleware' => $this->middleware,
        ];
    }
}