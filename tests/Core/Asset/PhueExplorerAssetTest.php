<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Asset;

use Phue\Core\Application;
use Phue\Core\Asset\AssetServer;
use Phue\Core\Asset\HtmlShellRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the phue-explorer navigation interceptor pipeline:
 *  - Exerciser script is shipped and served as /assets/js/phue-explorer.js
 *    with a strict JS MIME type + nosniff.
 *  - Framework bootstrap injects the interceptor into the HTML shell, exactly
 *    once, behind the default HtmlShellRenderer singleton.
 *  - Shell rendering stays pure: repeated renders never duplicate the script.
 *  - Source-level hard-locks for the safe SPA navigation contract
 *    (no node runtime available in the test container, so we lock source).
 *
 * @package Phue\Tests\Core\Asset
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhueExplorerAssetTest extends TestCase
{
    private const EXPLORER_JS = __DIR__ . '/../../../storage/assets/js/phue-explorer.js';

    private string $baseDir;
    private string $assetsJsDir;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertFileExists(self::EXPLORER_JS, 'phue-explorer.js must be shipped under storage/assets/js');

        $this->baseDir = sys_get_temp_dir() . '/phue_explorer_' . uniqid('', true);
        $this->assetsJsDir = $this->baseDir . '/storage/assets/js';
        mkdir($this->assetsJsDir, 0777, true);
        copy(self::EXPLORER_JS, $this->assetsJsDir . '/phue-explorer.js');

        $this->app = new Application($this->baseDir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->baseDir);
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTTP Serving
    // ─────────────────────────────────────────────────────────────────────────

    public function testExplorerServedWithJsMimeAndNoSniff(): void
    {
        $server = new AssetServer(
            $this->app->getContainer()->get(\Phue\Core\Asset\AssetRegistry::class),
        );

        $response = $server->serve('/assets/js/phue-explorer.js');

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/javascript', $response->getHeader('Content-Type'));
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    public function testExplorerServedContentIsVerbatim(): void
    {
        $server = new AssetServer(
            $this->app->getContainer()->get(\Phue\Core\Asset\AssetRegistry::class),
        );

        $response = $server->serve('/assets/js/phue-explorer.js');

        $this->assertSame(
            file_get_contents($this->assetsJsDir . '/phue-explorer.js'),
            $response->getContent(),
        );
    }

    public function testMissingJsAssetUnderSourcePrefixReturns404(): void
    {
        $server = new AssetServer(
            $this->app->getContainer()->get(\Phue\Core\Asset\AssetRegistry::class),
        );

        $response = $server->serve('/assets/js/nope.js');

        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('text/javascript', $response->getHeader('Content-Type'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shell Emission (bootstrap default, exactly once, pure render)
    // ─────────────────────────────────────────────────────────────────────────

    public function testBootstrapInjectsInterceptorAsModuleScript(): void
    {
        $renderer = $this->app->getContainer()->get(HtmlShellRenderer::class);

        $html = $renderer->render(title: 'Test');

        $this->assertStringContainsString(
            '<script type="module" src="/assets/js/phue-explorer.js"></script>',
            $html,
        );
    }

    public function testInterceptorScriptAppearsExactlyOnceAcrossRepeatedRenders(): void
    {
        $renderer = $this->app->getContainer()->get(HtmlShellRenderer::class);

        $once = $renderer->render(title: 'Test');
        $renderer->render(title: 'Again');
        $twice = $renderer->render(title: 'Test');

        $this->assertSame(1, substr_count($once, '/assets/js/phue-explorer.js'));
        $this->assertSame(1, substr_count($twice, '/assets/js/phue-explorer.js'));
    }

    public function testExplorerIsGlobalScriptNotRequestScript(): void
    {
        $registry = $this->app->getContainer()->get(\Phue\Core\Asset\AssetRegistry::class);
        $this->app->getContainer()->get(HtmlShellRenderer::class);

        $this->assertContains(
            '/assets/js/phue-explorer.js',
            array_column($registry->getGlobalScripts(), 'url'),
        );
        $this->assertNotContains(
            '/assets/js/phue-explorer.js',
            array_column($registry->getRequestScripts(), 'url'),
        );
    }

    public function testShellDoesNotAccumulatePageModulesAcrossRequests(): void
    {
        $registry = $this->app->getContainer()->get(\Phue\Core\Asset\AssetRegistry::class);
        $renderer = $this->app->getContainer()->get(HtmlShellRenderer::class);

        // Request #1 renders the Home page module into the shell
        $registry->beginRequest();
        $registry->addScript('/assets/phue/Home.abcd1234.phue.js', true);
        $homeShell = $renderer->render(title: 'Home');

        // Request #2 is a fresh Swoole request navigating to Products.
        // The previous page module must NOT leak into this shell.
        $registry->beginRequest();
        $registry->addScript('/assets/phue/Products.5678abcd.phue.js', true);
        $productsShell = $renderer->render(title: 'Products');

        $this->assertStringContainsString('/assets/phue/Home.abcd1234.phue.js', $homeShell);
        $this->assertStringContainsString('/assets/phue/Products.5678abcd.phue.js', $productsShell);
        $this->assertStringNotContainsString(
            '/assets/phue/Home.abcd1234.phue.js',
            $productsShell,
            'Shell of request #2 must not include modules registered during request #1',
        );
        $this->assertSame(
            1,
            substr_count($productsShell, '/assets/js/phue-explorer.js'),
        );
    }

    public function testRendererStaysPureWithoutContainerBootstrap(): void
    {
        $registry = new \Phue\Core\Asset\AssetRegistry();
        $renderer = new HtmlShellRenderer($registry);

        $html = $renderer->render(title: 'Test');

        $this->assertStringNotContainsString('phue-explorer', $html, 'Renderer must not inject the interceptor by itself');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Source-level contracts (no node runtime in container)
    // ─────────────────────────────────────────────────────────────────────────

    private function explorerSource(): string
    {
        $source = file_get_contents(self::EXPLORER_JS);
        $this->assertIsString($source);

        return $source;
    }

    public function testSourceIsIifeWithGlobalInitGuard(): void
    {
        $source = $this->explorerSource();

        $this->assertStringContainsString('__phueExplorerInitialized', $source);
        $this->assertStringContainsString('if (window.__phueExplorerInitialized) return;', $source);
    }

    public function testSourceIgnoresModifiedAndNonLeftClicks(): void
    {
        $source = $this->explorerSource();

        foreach (['e.metaKey', 'e.ctrlKey', 'e.shiftKey', 'e.altKey', 'e.button !== 0'] as $guard) {
            $this->assertStringContainsString($guard, $source, "Interceptor must guard {$guard}");
        }
    }

    public function testSourceSkipsExternalFragmentJavaScriptAndDownloadLinks(): void
    {
        $source = $this->explorerSource();

        foreach (["href.startsWith('#')", "href.startsWith('javascript:')", "target === '_blank'", "link.hasAttribute('download')", "!isSameOrigin"] as $skip) {
            $this->assertStringContainsString($skip, $source, "Interceptor must skip {$skip} anchors");
        }
    }

    public function testSourceFetchesWithSpaMarkerHeader(): void
    {
        $source = $this->explorerSource();

        $this->assertStringContainsString("'X-Phue-SPA': 'true'", $source);
    }

    public function testSourceFallsBackToFullReloadOnFailure(): void
    {
        $source = $this->explorerSource();

        $this->assertStringContainsString('window.location.href = url', $source);
    }

    public function testSourceReEvaluatesModuleScriptsWithDynamicImports(): void
    {
        $source = $this->explorerSource();

        $this->assertStringContainsString("for (const script of data.scripts)", $source);
        $this->assertStringContainsString('await mountModule(script.url)', $source);
        $this->assertStringContainsString('await import(url)', $source);
    }

    public function testSourceHandlesPopstateHistoryNavigation(): void
    {
        $source = $this->explorerSource();

        $this->assertStringContainsString("window.addEventListener('popstate'", $source);
        $this->assertStringContainsString('history.pushState', $source);
    }

    public function testSourceDeclaresStrictIIfeWithoutGlobalLeak(): void
    {
        $source = $this->explorerSource();

        $this->assertMatchesRegularExpression('/^\(\(\) => \{/m', $source, 'Interceptor must be an IIFE');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}