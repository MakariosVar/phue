<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Asset;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Asset\ImportMapManager;
use RuntimeException;

/**
 * Unit tests for ImportMapManager: manifest read/merge, import map JSON
 * rendering, stylesheet link emission and corrupted-manifest handling.
 *
 * @package Phue\Tests\Core\Asset
 */
#[Group('unit')]
final class ImportMapManagerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/importmap_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $manifest = $this->dir . '/phue-packages.json';
        if (is_file($manifest)) {
            unlink($manifest);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * Asserts a public asset URL resolves to a real file in the vendor tree.
     *
     * These tests used to hardcode "vue.runtime.esm-browser.js", which meant a
     * rename of the vendored bundle broke three tests in two files while the
     * framework kept working. A string comparison cannot tell the difference
     * between "the map names the right file" and "the map names a file that
     * does not exist" — and the second one is a blank page in production.
     *
     * The mapping is the one the server registers: AssetRegistry holds
     * "/assets/vendor" as a static source prefix, and the directory behind it is
     * storage/assets/vendor (SwooleServeCommand spells the same path out).
     * Anything outside that prefix is not resolvable this way, so it is
     * rejected rather than silently mapped somewhere plausible.
     */
    private function assertAssetResolves(string $url): void
    {
        $this->assertStringStartsWith(
            self::VENDOR_URL_PREFIX . '/',
            $url,
            sprintf('Only paths under %s are served from the vendor tree.', self::VENDOR_URL_PREFIX)
        );

        $relative = ltrim(substr($url, strlen(self::VENDOR_URL_PREFIX)), '/');
        $onDisk = self::vendorRoot() . '/' . $relative;

        $this->assertFileExists(
            $onDisk,
            sprintf(
                'Import map points at %s, which does not exist at %s. Every entry must be a file '
                . 'the asset server can actually serve, or the browser gets a 404 and a blank page.',
                $url,
                $onDisk
            )
        );
    }

    private const VENDOR_URL_PREFIX = '/assets/vendor';

    private static function vendorRoot(): string
    {
        return dirname(__DIR__, 3) . '/storage/assets/vendor';
    }

    private function manager(array $manifest): ImportMapManager
    {
        if (file_put_contents(
            $this->dir . '/phue-packages.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) === false) {
            throw new RuntimeException('Failed to write manifest fixture.');
        }

        return new ImportMapManager($this->dir);
    }

    public function testAbsentManifestFallsBackToOnlyVue(): void
    {
        $manager = new ImportMapManager($this->dir);

        $imports = $manager->getImportMap()['imports'];

        $this->assertSame(['vue'], array_keys($imports), 'Vue is the only default import.');
        $this->assertAssetResolves($imports['vue']);
    }

    public function testMergesInstalledPackagesWithVueCore(): void
    {
        $manager = $this->manager([
            'packages' => [
                'chart.js' => ['main' => '/assets/vendor/chart.js/index.js'],
                'date-fns' => ['main' => '/assets/vendor/date-fns/index.js'],
            ],
        ]);

        $imports = $manager->getImportMap()['imports'];

        $this->assertSame('/assets/vendor/chart.js/index.js', $imports['chart.js']);
        $this->assertSame('/assets/vendor/date-fns/index.js', $imports['date-fns']);

        // The Vue core entry is merged in from the manifest path, so it must
        // survive the merge pointing at a real file.
        $this->assertArrayHasKey('vue', $imports);
        $this->assertAssetResolves($imports['vue']);
    }

    /**
     * Guards the specific regression that broke this file: the vendored Vue
     * bundle is renamed as part of a build, and the framework default follows
     * it. A hardcoded expectation would have caught nothing useful — it only
     * proves someone remembered to edit three strings.
     */
    public function testVueCoreEntryTracksTheVendoredBundleOnDisk(): void
    {
        $manager = new ImportMapManager($this->dir);
        $url = $manager->getImportMap()['imports']['vue'];

        $onDisk = self::vendorRoot() . '/vue3';

        $this->assertDirectoryExists($onDisk, 'The vendored vue3 package is not installed.');

        $bundles = glob($onDisk . '/*.js') ?: [];

        $this->assertCount(
            1,
            $bundles,
            'Expected exactly one vendored Vue bundle, found: ' . implode(', ', $bundles)
        );

        $this->assertSame(
            '/assets/vendor/vue3/' . basename($bundles[0]),
            $url,
            'The import map default must name the bundle that is actually installed.'
        );
    }

    public function testScopedPackageMapsToScaffoldedVendorPath(): void
    {
        $manager = $this->manager([
            'packages' => [
                '@vue/reactivity' => ['main' => '/assets/vendor/@vue/reactivity/index.js'],
            ],
        ]);

        $imports = $manager->getImportMap()['imports'];

        $this->assertSame('/assets/vendor/@vue/reactivity/index.js', $imports['@vue/reactivity']);
    }

    public function testManifestMainAndSubpathsEmitExactMappingWithoutArrays(): void
    {
        $manager = $this->manager([
            'packages' => [
                'dayjs' => [
                    'main' => '/assets/vendor/dayjs/index.js',
                    'subpaths' => [
                        'plugin/relativeTime' => '/assets/vendor/dayjs/plugin/relativeTime/index.js',
                        'locale/el' => '/assets/vendor/dayjs/locale/el.js',
                    ],
                ],
            ],
        ]);

        $imports = $manager->getImportMap()['imports'];

        $this->assertSame('/assets/vendor/dayjs/index.js', $imports['dayjs']);
        $this->assertSame(
            '/assets/vendor/dayjs/plugin/relativeTime/index.js',
            $imports['dayjs/plugin/relativeTime'],
            'Each subpath must be emitted as its own string mapping, never a boxed array.'
        );
        $this->assertSame('/assets/vendor/dayjs/locale/el.js', $imports['dayjs/locale/el']);
        $this->assertIsString($imports['dayjs'], 'main value must remain a plain string (no array-to-string corruption).');
    }

    public function testCorruptedManifestThrowsRuntimeException(): void
    {
        file_put_contents($this->dir . '/phue-packages.json', '{not valid json');

        $this->expectException(RuntimeException::class);
        (new ImportMapManager($this->dir))->getImportMap();
    }

    public function testRenderImportMapTagContainsValidUnescapedJson(): void
    {
        $manager = $this->manager([
            'packages' => [
                'chart.js' => ['main' => '/assets/vendor/chart.js/index.js'],
            ],
        ]);

        $tag = $manager->renderImportMapTag();

        $this->assertStringStartsWith('<script type="importmap">', $tag);
        $this->assertStringEndsWith('</script>', $tag);
        $this->assertStringNotContainsString('\/', $tag);

        $json = substr($tag, strlen('<script type="importmap">'), -strlen('</script>'));
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('/assets/vendor/chart.js/index.js', $decoded['imports']['chart.js']);
    }

    public function testRenderStylesheetLinksEmitsAllTopLevelCssFiles(): void
    {
        $manager = $this->manager([
            'packages' => [],
            'css_files' => [
                '/assets/vendor/chart.js/chart.js.css',
                '/assets/vendor/lodash-es/lodash-es.css',
            ],
        ]);

        $links = $manager->renderStylesheetLinks();

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/vendor/chart.js/chart.js.css">', $links);
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/vendor/lodash-es/lodash-es.css">', $links);
    }

    public function testRenderStylesheetLinksReturnsEmptyWithoutCssFiles(): void
    {
        $manager = $this->manager([
            'packages' => ['chart.js' => ['main' => '/assets/vendor/chart.js/index.js']],
            'css_files' => [],
        ]);

        $this->assertSame('', $manager->renderStylesheetLinks());
    }
}