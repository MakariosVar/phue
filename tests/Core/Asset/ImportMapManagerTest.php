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

        $this->assertSame(
            ['imports' => ['vue' => '/assets/vendor/vue3/vue.runtime.esm-browser.js']],
            $manager->getImportMap()
        );
    }

    public function testMergesInstalledPackagesWithVueCore(): void
    {
        $manager = $this->manager([
            'packages' => [
                'chart.js' => ['esm_file' => 'chart.js.esm.js'],
                'date-fns' => ['esm_file' => 'date-fns.esm.js'],
            ],
        ]);

        $imports = $manager->getImportMap()['imports'];

        $this->assertSame('/assets/vendor/vue3/vue.runtime.esm-browser.js', $imports['vue']);
        $this->assertSame('/assets/vendor/chart.js/chart.js.esm.js', $imports['chart.js']);
        $this->assertSame('/assets/vendor/date-fns/date-fns.esm.js', $imports['date-fns']);
    }

    public function testScopedPackageMapsToScaffoldedVendorPath(): void
    {
        $manager = $this->manager([
            'packages' => [
                '@vue/reactivity' => ['esm_file' => 'reactivity.esm.js'],
            ],
        ]);

        $imports = $manager->getImportMap()['imports'];

        $this->assertSame('/assets/vendor/@vue/reactivity/reactivity.esm.js', $imports['@vue/reactivity']);
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
                'chart.js' => ['esm_file' => 'chart.js.esm.js'],
            ],
        ]);

        $tag = $manager->renderImportMapTag();

        $this->assertStringStartsWith('<script type="importmap">', $tag);
        $this->assertStringEndsWith('</script>', $tag);
        $this->assertStringNotContainsString('\/', $tag);

        $json = substr($tag, strlen('<script type="importmap">'), -strlen('</script>'));
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('/assets/vendor/chart.js/chart.js.esm.js', $decoded['imports']['chart.js']);
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
            'packages' => ['chart.js' => ['esm_file' => 'chart.js.esm.js']],
            'css_files' => [],
        ]);

        $this->assertSame('', $manager->renderStylesheetLinks());
    }
}