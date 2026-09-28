<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Ssr;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * VersionLockTest — pins the SSR depth to the vendored client bundle version.
 *
 * Server-rendered markup and client-side hydration are only compatible when the
 * compiler and runtime agree on Vue's version; otherwise attribute/whitespace
 * normalization drifts and `hydrate()` fails. This test guards that invariant.
 *
 * @package Phue\Tests\Core\Ssr
 */
#[Group('component')]
final class VersionLockTest extends TestCase
{
    private const APP_ROOT = '/app';

    /** Installed location of the vendored client bundle, relative to APP_ROOT. */
    private const VENDOR_VUE_DIR = 'storage/assets/vendor/vue3';

    private const SIDE_PACKAGE = 'ssr/package.json';

    /**
     * The vendored Vue ESM bundle, discovered rather than hardcoded.
     *
     * The bundle's filename is a build artifact — it changed from
     * "vue.runtime.esm-browser.js" to "vue.runtime.esm-browser.prod.js" when the
     * production vendor was adopted, and this test failed on that rename even
     * though the version lock it exists to protect was untouched.
     *
     * Discovery is from the install directory, not from ImportMapManager, so
     * this stays an independent check rather than a tautology.
     */
    private static function vendoredClientBundle(): string
    {
        $dir = self::APP_ROOT . '/' . self::VENDOR_VUE_DIR;

        $bundles = glob($dir . '/*.js') ?: [];

        self::assertNotEmpty(
            $bundles,
            sprintf('No vendored Vue bundle found in %s. Run the npm install step.', $dir)
        );

        self::assertCount(
            1,
            $bundles,
            'Expected exactly one vendored Vue ESM bundle, found: ' . implode(', ', $bundles)
            . '. The SSR version lock reads this file; two bundles make the lock ambiguous.'
        );

        return $bundles[0];
    }

    /**
     * @return string The 3.x.y version declared by the vendored client bundle.
     */
    public function testVendoredBundleReportsAVueVersion(): string
    {
        $path = self::vendoredClientBundle();

        $content = file_get_contents($path);

        $this->assertIsString($content, sprintf('Client bundle not readable: %s', $path));

        $this->assertMatchesRegularExpression(
            '/3\.\d+\.\d+/',
            $content,
            'Client bundle does not advertise a Vue version banner.'
        );

        preg_match('/3\.\d+\.\d+/', $content, $matches);

        $bundleVersion = $matches[0];

        $this->assertSame(3, (int) $bundleVersion[0], 'Client bundle is not Vue 3.x.');

        return $bundleVersion;
    }

    /**
     * @depends testVendoredBundleReportsAVueVersion
     */
    public function testSidecarPinsSameVersionAsClientBundle(string $bundleVersion): void
    {
        $package = $this->readSidePackage();

        $this->assertSame(
            $bundleVersion,
            $package['dependencies']['vue'] ?? null,
            'ssr/package.json "vue" must match the vendored client bundle version.'
        );

        $this->assertSame(
            $bundleVersion,
            $package['dependencies']['@vue/server-renderer'] ?? null,
            'ssr/package.json "@vue/server-renderer" must match the vendored client bundle version.'
        );
    }

    /**
     * @depends testVendoredBundleReportsAVueVersion
     */
    public function testPinnedVersionsAreExactNotRanges(string $bundleVersion): void
    {
        $package = $this->readSidePackage();

        foreach (['vue', '@vue/server-renderer'] as $dependency) {
            $version = $package['dependencies'][$dependency] ?? null;

            $this->assertIsString($version, "Missing ssr dependency: {$dependency}");

            $this->assertSame(
                $bundleVersion,
                $version,
                "{$dependency} must be pinned exactly (no ^ / ~ prefixes)."
            );
        }
    }

    /**
     * @return array<string, mixed> Decoded ssr/package.json.
     */
    private function readSidePackage(): array
    {
        $raw = file_get_contents(self::APP_ROOT . '/' . self::SIDE_PACKAGE);

        $this->assertIsString($raw, sprintf('Sidecar package file missing: %s', self::SIDE_PACKAGE));

        $package = json_decode($raw, true);

        $this->assertIsArray($package, 'ssr/package.json is not valid JSON.');

        return $package;
    }
}