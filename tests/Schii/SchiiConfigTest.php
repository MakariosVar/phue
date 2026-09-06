<?php

declare(strict_types=1);

namespace Phue\Tests\Schii;

use PHPUnit\Framework\TestCase;
use Phue\Schii\Config\SchiiConfig;

/**
 * Test suite verifying SchiiConfig default values, custom overrides, and array transformation.
 *
 * @package Phue\Tests\Schii
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class SchiiConfigTest extends TestCase
{
    /**
     * Verifies that initializing SchiiConfig with no arguments applies default options correctly.
     *
     * @return void
     */
    public function testDefaultValuesAppliedCorrectly(): void
    {
        $config = new SchiiConfig();

        $this->assertSame('/api/schii', $config->apiPrefix);
        $this->assertSame('App\\Models', $config->entityNamespace);
        $this->assertTrue($config->autoMigrate);
        $this->assertSame('schii_schema_', $config->cachePrefix);
        $this->assertSame([], $config->middleware);
    }

    /**
     * Verifies that SchiiConfig::fromArray() correctly overrides specified options while retaining defaults for unspecified keys.
     *
     * @return void
     */
    public function testFromArrayOverridesValues(): void
    {
        $customOptions = [
            'apiPrefix' => '/v1/admin',
            'autoMigrate' => false,
            'middleware' => ['AuthMiddleware'],
        ];

        $config = SchiiConfig::fromArray($customOptions);

        $this->assertSame('/v1/admin', $config->apiPrefix);
        $this->assertSame('App\\Models', $config->entityNamespace); // Retained default
        $this->assertFalse($config->autoMigrate);
        $this->assertSame('schii_schema_', $config->cachePrefix);   // Retained default
        $this->assertSame(['AuthMiddleware'], $config->middleware);
    }

    /**
     * Verifies that toArray() returns an associative array matching object state.
     *
     * @return void
     */
    public function testToArrayExport(): void
    {
        $config = new SchiiConfig(
            apiPrefix: '/api/v2',
            entityNamespace: 'Domain\\Entities',
            autoMigrate: false,
            cachePrefix: 'custom_cache_',
            middleware: ['SecurityMiddleware']
        );

        $exported = $config->toArray();

        $this->assertSame('/api/v2', $exported['apiPrefix']);
        $this->assertSame('Domain\\Entities', $exported['entityNamespace']);
        $this->assertFalse($exported['autoMigrate']);
        $this->assertSame('custom_cache_', $exported['cachePrefix']);
        $this->assertSame(['SecurityMiddleware'], $exported['middleware']);
        // `prefix` (console UI mount) round-trips through toArray
        $this->assertSame('/schii', $exported['prefix']);
    }

    public function testPrefixRoundTrip(): void
    {
        $this->assertSame('/schii', (new SchiiConfig())->prefix);

        $config = SchiiConfig::fromArray(['prefix' => '/admin', 'apiPrefix' => '/api/admin']);
        $this->assertSame('/admin', $config->prefix);
        $this->assertSame('/api/admin', $config->apiPrefix);

        $exported = $config->toArray();
        $this->assertSame('/admin', $exported['prefix']);
        $this->assertSame('/api/admin', $exported['apiPrefix']);
    }
}