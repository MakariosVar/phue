<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Config;

/**
 * Schii Protocol Configuration Engine.
 *
 * Holds system-wide runtime settings, API prefixes, entity namespaces,
 * auto-migration flags, cache key prefixes, and middleware stack definitions
 * for Zero-Controller REST operations and Schema Generation.
 *
 * @package Phue\Modules\Schii\Config
 */
final readonly class SchiiConfig
{
    /**
     * Initializes a new immutable Schii Configuration instance.
     *
     * @param bool                        $enabled         Whether Schii is enabled.
     * @param string                      $apiPrefix       Base URI prefix for dynamically exposed Schii REST endpoints.
     * @param string                      $prefix          Base URI Schii Admin console endpoints.
     * @param string                      $entityNamespace Fully qualified PHP namespace where Schii models reside.
     * @param bool                        $requireAuth     Whether automatic database UI and REST endpoints require authorized access.
     * @param bool                        $autoMigrate     Whether automatic database schema migration is enabled during boot.
     * @param string                      $cachePrefix     Cache key prefix for compiled model schema descriptors.
     * @param array<int, string|callable> $middleware      Array of middleware pipeline callables/classes for Schii routes.
     * @param callable|array{0: class-string, 1: string}|null $authResolver Custom identity lookup callable.
     * @param string                      $exportDir       Export directory path.
     * @param string                      $phiiNamespace   Phii namespace.
     * @param string                      $phiiPrefix      Phii prefix.
     * @param array{enabled: bool, limit: int} $audit      Audit log configurations.
     * @param array{maxUpload: string, allowedMime: array<int, string>} $import Import limits and allowed mime types.
     * @param array{allowInProduction: bool} $phii         Phii production guards.
     * @param array{autoInfer: bool}      $relations       Relations auto-inference setting.
     */
    public function __construct(
        public bool $enabled = true,
        public string $apiPrefix = '/api/schii',
        public string $prefix = '/schii',
        public string $entityNamespace = 'App\\Models',
        public bool $requireAuth = false,
        public bool $autoMigrate = true,
        public string $cachePrefix = 'schii_schema_',
        public array $middleware = [],
        public mixed $authResolver = null,
        public string $exportDir = 'storage/generated',
        public string $phiiNamespace = 'App\\Models',
        public string $phiiPrefix = '/phii',
        public array $audit = ['enabled' => true, 'limit' => 200],
        public array $import = ['maxUpload' => '10M', 'allowedMime' => ['text/csv', 'application/json']],
        public array $phii = ['allowInProduction' => false],
        public array $relations = ['autoInfer' => false],
        public string $outputRoot = ''
    ) {}

    /**
     * Creates a SchiiConfig instance from an associative options array,
     * overriding defaults only for keys that are explicitly supplied.
     *
     * @param array<string, mixed> $options Associative array of configuration parameter overrides.
     * @return self Fresh SchiiConfig instance populated with merged options.
     */
    public static function fromArray(array $options): self
    {
        return new self(
            enabled: $options['enabled'] ?? true,
            apiPrefix: $options['apiPrefix'] ?? '/api/schii',
            prefix: $options['prefix'] ?? '/schii',
            entityNamespace: $options['entityNamespace'] ?? 'App\\Models',
            requireAuth: $options['requireAuth'] ?? false,
            autoMigrate: $options['autoMigrate'] ?? true,
            cachePrefix: $options['cachePrefix'] ?? 'schii_schema_',
            middleware: $options['middleware'] ?? [],
            authResolver: $options['authResolver'] ?? null,
            exportDir: $options['exportDir'] ?? 'storage/generated',
            phiiNamespace: $options['phiiNamespace'] ?? 'App\\Models',
            phiiPrefix: $options['phiiPrefix'] ?? '/phii',
            audit: $options['audit'] ?? ['enabled' => true, 'limit' => 200],
            import: $options['import'] ?? ['maxUpload' => '10M', 'allowedMime' => ['text/csv', 'application/json']],
            phii: $options['phii'] ?? ['allowInProduction' => false],
            relations: $options['relations'] ?? ['autoInfer' => false],
            outputRoot: $options['outputRoot'] ?? ''
        );
    }

    /**
     * Converts the current configuration object into an associative array representation.
     *
     * @return array<string, mixed> Associative array containing all configuration properties.
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'apiPrefix' => $this->apiPrefix,
            'prefix' => $this->prefix,
            'entityNamespace' => $this->entityNamespace,
            'requireAuth' => $this->requireAuth,
            'autoMigrate' => $this->autoMigrate,
            'cachePrefix' => $this->cachePrefix,
            'middleware' => $this->middleware,
            'authResolver' => $this->authResolver,
            'exportDir' => $this->exportDir,
            'phiiNamespace' => $this->phiiNamespace,
            'phiiPrefix' => $this->phiiPrefix,
            'audit' => $this->audit,
            'import' => $this->import,
            'phii' => $this->phii,
            'relations' => $this->relations,
            'outputRoot' => $this->outputRoot,
        ];
    }
}