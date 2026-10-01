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
     * Every vendored Vue ESM bundle, discovered rather than hardcoded.
     *
     * The bundle filenames are build artifacts — they changed from
     * "vue.runtime.esm-browser.js" to "vue.runtime.esm-browser.prod.js" when the
     * production vendor was adopted, and a *second* one appeared when the
     * development build was added. An "exactly one bundle" assertion broke on
     * both events while the version lock it exists to protect was untouched.
     *
     * "Exactly one" was never the invariant that mattered. The invariant is that
     * the lock is *unambiguous*: whichever build the import map selects — the
     * production one in a normal install, the development one when debug is on —
     * the SSR sidecar must have been pinned to that same Vue version. So every
     * bundle on disk is discovered and they are all required to agree.
     *
     * Discovery is from the install directory, not from ImportMapManager, so
     * this stays an independent check rather than a tautology.
     *
     * @return array<int, string> Absolute paths, sorted for a stable message.
     */
    private static function vendoredClientBundles(): array
    {
        $bundles = glob(self::APP_ROOT . '/' . self::VENDOR_VUE_DIR . '/*.js') ?: [];

        self::assertNotEmpty(
            $bundles,
            sprintf(
                'No vendored Vue bundle found in %s. Run the npm install step.',
                self::APP_ROOT . '/' . self::VENDOR_VUE_DIR
            )
        );

        sort($bundles);

        return $bundles;
    }

    /**
     * @return string The 3.x.y version declared by a vendored client bundle.
     */
    private static function versionIn(string $path): string
    {
        $content = file_get_contents($path);

        self::assertIsString($content, sprintf('Client bundle not readable: %s', $path));

        self::assertMatchesRegularExpression(
            '/3\.\d+\.\d+/',
            $content,
            sprintf('Client bundle %s does not advertise a Vue version banner.', basename($path))
        );

        preg_match('/3\.\d+\.\d+/', $content, $matches);

        return $matches[0];
    }

    /**
     * @return string The 3.x.y version every vendored client bundle agrees on.
     */
    public function testVendoredBundleReportsAVueVersion(): string
    {
        $versions = [];

        foreach (self::vendoredClientBundles() as $path) {
            $version = self::versionIn($path);

            $this->assertSame(
                3,
                (int) $version[0],
                sprintf('Client bundle %s is not Vue 3.x.', basename($path))
            );

            $versions[basename($path)] = $version;
        }

        // The lock this file guards is "one Vue version for client and sidecar".
        // With two bundles on disk that is only meaningful if they agree — a dev
        // build a minor version behind the prod build would hydrate against a
        // different Vue than the sidecar rendered with, and the failure mode is a
        // hydration mismatch rather than an error.
        $this->assertSame(
            [array_values($versions)[0]],
            array_values(array_unique($versions)),
            'Vendored Vue bundles disagree on version: ' . json_encode($versions)
            . '. The SSR sidecar is pinned to a single version, so every client build'
            . ' must be that version too.'
        );

        return array_values($versions)[0];
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