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

    /**
     * The on-disk path a public vendor URL maps to, for content assertions.
     *
     * A string comparison cannot tell "the map names the right file" from "the
     * map names a file that does not exist" — the second is a blank page. And
     * it cannot tell the two Vue bundles apart without hardcoding a build
     * artifact's name, which is what broke these tests when the dev build was
     * added. Reading the file and asking what it *does* answers both.
     */
    private static function vendorPath(string $url): string
    {
        $relative = ltrim(substr($url, strlen(self::VENDOR_URL_PREFIX)), '/');

        return self::vendorRoot() . '/' . $relative;
    }

    private const VENDOR_URL_PREFIX = '/assets/vendor';

    private static function vendorRoot(): string
    {
        return dirname(__DIR__, 3) . '/storage/assets/vendor';
    }

    /**
     * The directory the public vendor URLs resolve against.
     *
     * ImportMapManager's third constructor argument is the root a "/assets/..."
     * URL hangs off, not the vendor directory itself — the same relationship
     * AssetRegistry encodes when it registers "/assets/vendor" against the vendor
     * directory. So this is two levels above vendorRoot(), and passing
     * vendorRoot() here would look for "/app/storage/assets/vendor/assets/vendor/..."
     * and silently resolve nothing.
     */
    private static function urlRoot(): string
    {
        return dirname(self::vendorRoot(), 2);
    }

    /**
     * A manager over a temp manifest dir, pointed at the real vendor tree.
     *
     * The manifest fixture lives in a temp dir on purpose — the real
     * phue-packages.json is the app's, not the test's to edit. But the Vue entry
     * is resolved from the vendor tree, so the vendor root has to be passed
     * explicitly. Leaving it to default to "<basePath>/storage" would point the
     * lookup at a temp dir that has no vendor tree in it, and the vue entry
     * would silently vanish — which is the whole class of bug these tests
     * exist to catch, so it must not be how they are set up.
     */
    private function manager(array $manifest, bool $debug = false): ImportMapManager
    {
        if (file_put_contents(
            $this->dir . '/phue-packages.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) === false) {
            throw new RuntimeException('Failed to write manifest fixture.');
        }

        return new ImportMapManager($this->dir, $debug, self::urlRoot());
    }

    public function testAbsentManifestFallsBackToTheCoreSpecifiers(): void
    {
        $manager = new ImportMapManager($this->dir, vendorRoot: self::urlRoot());

        $imports = $manager->getImportMap()['imports'];

        // `vue` and `phue` are framework-reserved; every built .phue module
        // imports `phue`, so an entry missing there breaks the whole app.
        $this->assertSame(['vue', 'phue'], array_keys($imports));
        $this->assertAssetResolves($imports['vue']);
    }

    /**
     * A Vue entry that resolves to a build which is not installed is worse than
     * no entry at all: the browser gets a 404 for an import-map key, which is
     * an unresolvable bare specifier, not a missing script — the page dies blank
     * with nothing in the console to act on. So with neither build present the
     * map must carry no vue entry rather than a broken one.
     */
    public function testNoVueEntryWhenNeitherBuildIsInstalled(): void
    {
        $base = $this->vendorTreeWith([]);

        $imports = (new ImportMapManager($base, vendorRoot: $base . '/storage'))->getImportMap()['imports'];

        $this->assertArrayNotHasKey(
            'vue',
            $imports,
            'With no Vue build on disk the map must omit vue rather than name a file that 404s.'
        );

        $this->removeTree($base);
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
        $imports = $this->manager([])->getImportMap()['imports'];
        $this->assertArrayHasKey('vue', $imports, 'The import map must always carry a vue entry.');
        $url = $imports['vue'];

        $this->assertDirectoryExists(self::vendorRoot() . '/vue3', 'The vendored vue3 package is not installed.');
        $this->assertAssetResolves($url);

        // "Exactly one vendored bundle" stopped being the invariant the moment a
        // development build shipped beside the production one — there are two
        // now, on purpose. The invariant that still matters is which *semantics*
        // the default map picks, and that is decided by content rather than by
        // filename: only the development build carries the per-node hydration
        // warnings, and the production build is the one that must be served by
        // default.
        $this->assertStringNotContainsString(
            'Hydration node mismatch',
            (string) file_get_contents(self::vendorPath($url)),
            'The default import map must point at the production Vue build: the development build '
            . 'reports hydration mismatches per node, which is not what a default install should serve.'
        );
    }

    public function testDebugModeSelectsTheDevelopmentBundle(): void
    {
        $imports = $this->manager([], debug: true)->getImportMap()['imports'];
        $this->assertArrayHasKey('vue', $imports, 'The import map must always carry a vue entry.');
        $url = $imports['vue'];

        $this->assertAssetResolves($url);
        $this->assertStringContainsString(
            'Hydration node mismatch',
            (string) file_get_contents(self::vendorPath($url)),
            'Debug mode must serve the development Vue build. Without it a hydration mismatch is '
            . 'reported as a single useless line — no element, no attribute, no cause.'
        );
    }

    public function testTheManifestCannotOverrideTheVueBuildDecision(): void
    {
        // A `vue` key in phue-packages.json used to win, because the manifest was
        // merged after the hardcoded default. It must not: the build is chosen by
        // the debug flag, and a manifest entry deciding it would swap the build
        // for every environment at once — including production.
        $manager = $this->manager([
            'packages' => [
                'vue' => ['main' => '/assets/vendor/vue3/vue.runtime.esm-browser.prod.js'],
            ],
        ]);

        $this->assertStringNotContainsString(
            'Hydration node mismatch',
            (string) file_get_contents(self::vendorPath($manager->getImportMap()['imports']['vue'])),
            'Without debug the map must still name the production build, whatever the manifest says.'
        );

        $debugImports = (new ImportMapManager(
            $this->dir,
            debug: true,
            vendorRoot: self::urlRoot()
        ))->getImportMap()['imports'];
        $this->assertArrayHasKey('vue', $debugImports, 'The import map must always carry a vue entry.');
        $this->assertStringContainsString(
            'Hydration node mismatch',
            (string) file_get_contents(self::vendorPath($debugImports['vue'])),
            'With debug the map must name the development build, whatever the manifest says.'
        );
    }

    public function testDebugFallsBackToProductionWhenTheDevelopmentBundleIsMissing(): void
    {
        // The dev build is not shipped by every install: it is a single
        // `curl` into storage/assets/vendor/vue3/. A debug flag on a host that
        // never ran it must fall back to production, not hand the browser a URL
        // that 404s — which, for an import map entry, is not a missing script
        // but an unresolvable bare specifier and a blank page.
        $base = $this->vendorTreeWith(['vue.runtime.esm-browser.prod.js']);
        file_put_contents(
            $base . '/phue-packages.json',
            json_encode(['packages' => new \stdClass()], JSON_PRETTY_PRINT)
        );

        $imports = (new ImportMapManager($base, debug: true, vendorRoot: $base . '/storage'))
            ->getImportMap()['imports'];
        $this->assertArrayHasKey('vue', $imports, 'The import map must always carry a vue entry.');

        $this->assertSame(
            '/assets/vendor/vue3/vue.runtime.esm-browser.prod.js',
            $imports['vue'],
            'Debug mode with no development build on disk must fall back to the production build.'
        );

        $this->removeTree($base);
    }

    /**
     * A throwaway base path holding only the listed Vue bundles, so a test can
     * describe a vendor tree that is missing one of them.
     *
     * @param array<int, string> $bundles
     */
    private function vendorTreeWith(array $bundles): string
    {
        $base = sys_get_temp_dir() . '/importmap_vendor_' . bin2hex(random_bytes(6));
        $dir = $base . '/storage/assets/vendor/vue3';
        mkdir($dir, 0777, true);

        foreach ($bundles as $bundle) {
            file_put_contents($dir . '/' . $bundle, '/* ' . $bundle . ' */');
        }

        return $base;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
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