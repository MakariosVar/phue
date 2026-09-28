<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Asset;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * The /assets/vendor source prefix must be registered by the framework itself.
 *
 * This exists because of a real production bug that the rest of the suite could
 * not see. Application registered the prefix against
 *
 *     __DIR__ . '/../../assets/vendor'      ->  src/assets/vendor
 *
 * a directory that has never existed, so the is_dir() guard was always false and
 * registerSource() never ran. SwooleServeCommand registered the same prefix
 * against the correct path, so the application worked under Swoole and returned
 * 404 for every asset everywhere else.
 *
 * Two things hid it:
 *
 *  - AssetHttpTest registers its own /assets source in setUp, so it exercises
 *    the serving path without ever touching the framework's own registration.
 *  - Every other assertion about the vendor tree checks that a file exists on
 *    disk. That is a different question from "is the prefix wired to it".
 *
 * Nothing below registers a source by hand. If the framework stops wiring
 * /assets/vendor, these fail.
 *
 * @package Phue\Tests\Core\Asset
 */
#[Group('unit')]
final class VendorAssetRegistrationTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/phue_vendor_reg_' . uniqid();

        mkdir($this->baseDir . '/storage/assets/vendor/acme', 0755, true);
        file_put_contents(
            $this->baseDir . '/storage/assets/vendor/acme/acme.js',
            'export const acme = "acme";'
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (!is_dir($this->baseDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->baseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        @rmdir($this->baseDir);
    }

    /**
     * The whole point: no manual registration, just boot and request.
     */
    public function testFrameworkRegistersVendorSourcePrefixOnItsOwn(): void
    {
        $app = new Application($this->baseDir, debug: false);

        $response = $app->handle(new Request('GET', '/assets/vendor/acme/acme.js'));

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'The framework must register /assets/vendor against <basePath>/storage/assets/vendor. '
            . 'A 404 here means the import map points at a URL the asset server cannot serve, '
            . 'which is a blank page in production.'
        );
        $this->assertSame('export const acme = "acme";', $response->getContent());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('content-type'));
    }

    /**
     * Names the directory explicitly, so a future regression that swaps the
     * prefix for a different directory fails with a clear message instead of a
     * 404 that looks like a missing file.
     */
    public function testVendorPrefixResolvesInsideTheApplicationStorageTree(): void
    {
        $app = new Application($this->baseDir, debug: false);

        $registry = $app->getContainer()->get(AssetRegistry::class);

        $resolved = $registry->resolvePath('/assets/vendor/acme/acme.js');

        $this->assertNotNull(
            $resolved,
            '/assets/vendor must resolve to a real path. Check the prefix is registered against '
            . $this->baseDir . '/storage/assets/vendor.'
        );

        $this->assertStringStartsWith(
            $this->baseDir . '/storage/assets/vendor/',
            $resolved,
            'The vendor prefix must not escape the application storage tree.'
        );
    }

    /**
     * A missing vendor tree must stay a 404, not an exception.
     *
     * An earlier attempt fixed the wrong path by throwing from inside the
     * container binding, which killed the whole application at boot — including
     * CLI commands, health checks and JSON APIs that never touch an asset. A
     * framework must not refuse to start because an optional directory is
     * absent; the correct failure is at the point of use.
     */
    public function testAbsentVendorDirectoryDegradesTo404NotAnException(): void
    {
        $withoutVendor = sys_get_temp_dir() . '/phue_vendor_none_' . uniqid();
        mkdir($withoutVendor, 0755, true);

        try {
            $app = new Application($withoutVendor, debug: false);

            $this->assertTrue(
                $app->getContainer()->has(AssetRegistry::class),
                'The container must still be able to build an AssetRegistry without a vendor tree.'
            );

            $response = $app->handle(new Request('GET', '/assets/vendor/acme/acme.js'));

            $this->assertSame(404, $response->getStatusCode());
        } finally {
            @rmdir($withoutVendor);
        }
    }

    /**
     * SwooleServeCommand used to register /assets/vendor itself, duplicating the
     * Application binding. Two registrations of the same prefix means the second
     * silently wins, so a divergence between them is invisible until one of them
     * is edited. There must be exactly one source of truth.
     */
    public function testVendorPrefixIsRegisteredExactlyOnce(): void
    {
        $app = new Application($this->baseDir, debug: false);

        $sources = $app->getContainer()->get(AssetRegistry::class)->getSources();

        $vendorSources = array_filter(
            array_keys($sources),
            static fn(string $prefix): bool => str_starts_with($prefix, '/assets/vendor')
        );

        $this->assertCount(
            1,
            $vendorSources,
            '/assets/vendor must be registered by exactly one call site, found: '
            . implode(', ', $vendorSources)
        );
    }
}
