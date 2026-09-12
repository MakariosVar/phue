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

    private const MANIFEST = 'storage/assets/vendor/vue3/vue.runtime.esm-browser.js';

    private const SIDE_PACKAGE = 'ssr/package.json';

    /**
     * @return string The 3.x.y version declared by the vendored client bundle.
     */
    public function testVendoredBundleReportsAVueVersion(): string
    {
        $content = file_get_contents(self::APP_ROOT . '/' . self::MANIFEST);

        $this->assertIsString($content, sprintf('Client bundle not found: %s', self::MANIFEST));

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