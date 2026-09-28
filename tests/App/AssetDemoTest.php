<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * End-to-end contract tests for the no-build Vue demo page: the WebController
 * renders self-contained inline-module .phue pages via ComponentRenderer, with
 * the storage/assets vendor source served through the auto-registered
 * AssetsController.
 *
 * @package Phue\Tests\App
 */
final class AssetDemoTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application('/app');
        $this->app->getRouter()->scanControllers('/app/App/Controllers', 'App\\Controllers');
        $registry = $this->app->getContainer()->get(AssetRegistry::class);
        $registry->registerSource('/assets', '/app/App/Frontend');
        $registry->registerSource('/assets/vendor', '/app/storage/assets/vendor');
    }

    public function testDemoPageReturnsHtmlShell(): void
    {
        $response = $this->app->handle(new Request('GET', '/'));
        $content = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeader('content-type'));

        preg_match('/<title>(.*?)<\/title>/', $content, $title);
        $this->assertNotNull($title[1] ?? null);
        $this->assertStringContainsString('Phue', $title[1]);
        $this->assertStringContainsString('<div id="app">', $content);
        $this->assertStringContainsString('window.__INITIAL_STATE__', $content);
    }

    public function testDemoPageEmbedsExtractedModuleScriptUrl(): void
    {
        $content = $this->app->handle(new Request('GET', '/'))->getContent();

        $this->assertMatchesRegularExpression(
            '/<script type="module" src="\/assets\/phue\/Pages\/Home\.[a-f0-9]{8}\.phue\.js"><\/script>/',
            $content
        );
        $this->assertStringNotContainsString('<script type="module">', $content);
        $this->assertStringNotContainsString('src="/assets/app.js"', $content);
        $this->assertStringNotContainsString('createSSRApp', $content);
    }

    public function testDemoPageServesExtractedModuleScript(): void
    {
        $page = $this->app->handle(new Request('GET', '/'))->getContent();
        preg_match('/src="(\/assets\/phue\/Pages\/Home\.[a-f0-9]{8}\.phue\.js)"/', $page, $module);
        $this->assertArrayHasKey(1, $module);

        $response = $this->app->handle(new Request('GET', $module[1]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('createSSRApp', $response->getContent());
        $this->assertStringContainsString(".mount('#app')", $response->getContent());
    }

    public function testUnknownModuleScriptUrlReturnsNonHtml404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/phue/DoesNotExist.00000000.phue.js'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('html', strtolower((string) $response->getHeader('content-type')));
    }

    public function testPhuiComponentModuleIsServedWithTemplateAndScript(): void
    {
        $registry = $this->app->getContainer()->get(ComponentLoaderRegistry::class);
        $url = $registry->moduleUrl('PhButton');

        $this->assertNotNull($url);

        $response = $this->app->handle(new Request('GET', $url));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('content-type'));

        $content = $response->getContent();
        $this->assertStringContainsString('__phueRender', $content);
        $this->assertStringContainsString("name: 'PhButton'", $content);
        $this->assertStringContainsString('spinner-border', $content);
    }

    public function testPhuiComponentModuleHashMatchesServedContent(): void
    {
        $registry = $this->app->getContainer()->get(ComponentLoaderRegistry::class);
        $url = $registry->moduleUrl('PhButton');
        $this->assertNotNull($url);

        $response = $this->app->handle(new Request('GET', $url));
        $content = $response->getContent();

        preg_match('/PhButton\.([a-f0-9]{8})\.phue\.js$/', $url, $m);
        $this->assertSame($m[1], substr(sha1($content), 0, 8));
    }

    public function testRemovedAppEntryReturns404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/app.js'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Not Found', $response->getContent());
    }

    public function testVendorVueModuleIsServed(): void
    {
        // The import map resolves 'vue' to the PRODUCTION build: the dev build
        // carries the __DEV__ branches, devtools hooks and HMR wiring, which is
        // 391 KB of source and 31.5 KiB of extra transfer on the critical path.
        $response = $this->app->handle(new Request('GET', '/assets/vendor/vue3/vue.runtime.esm-browser.prod.js'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringNotContainsString('Unable to read', $response->getContent());
    }

    /**
     * A font preload is keyed by its FULL url, query string included.
     *
     * bootstrap-icons.min.css asks for
     *   fonts/bootstrap-icons.woff2?e34853135f9e39acf64315236852cd5a
     * so preloading the bare path preloads a DIFFERENT cache key. The browser
     * then fetches the font twice — 134 KB instead of 67 KB — and reports
     * nothing wrong. The only symptom is a 2x entry in the network waterfall,
     * which no audit flags.
     *
     * That makes the registration silently wrong the moment a vendor upgrade
     * changes the cache-busting hash, so the invariant is asserted here rather
     * than left as a comment. The URL is derived from the stylesheet itself, so
     * this test fails the day the vendored CSS changes underneath us.
     */
    public function testPreloadedFontUrlMatchesTheOneTheStylesheetRequests(): void
    {
        $cssPath = '/app/storage/assets/vendor/bootstrap-icons/bootstrap-icons.min.css';
        $css = (string) file_get_contents($cssPath);

        $this->assertSame(
            1,
            preg_match('/url\("(fonts\/bootstrap-icons\.woff2[^"]*)"\)/', $css, $m),
            'could not read the woff2 url out of the vendored stylesheet'
        );

        $requested = '/assets/vendor/bootstrap-icons/' . $m[1];

        $registered = $this->app->getContainer()->get(AssetRegistry::class)->getFonts();

        $this->assertContains(
            $requested,
            $registered,
            'the preload href must be byte-identical to the url the stylesheet '
            . 'requests, otherwise the font is downloaded twice with no error'
        );
    }

    public function testPreloadedFontIsActuallyServed(): void
    {
        $fonts = $this->app->getContainer()->get(AssetRegistry::class)->getFonts();

        $this->assertNotEmpty($fonts, 'the icon font should be registered for preload');

        foreach ($fonts as $font) {
            $path = parse_url($font, PHP_URL_PATH);
            $this->assertIsString($path);

            $response = $this->app->handle(new Request('GET', (string) $path));

            $this->assertSame(
                200,
                $response->getStatusCode(),
                "preloaded font {$font} is not served — the preload would 404 and the browser would download it twice anyway"
            );
        }
    }

    public function testImportMapPointsVueAtTheProductionBuild(): void
    {
        // Guards the switch: a stale import map entry silently puts the browser
        // and the SSR sidecar on different Vue builds, which shows up as a
        // hydration mismatch rather than an error.
        $body = $this->app->handle(new Request('GET', '/'))->getContent();

        $this->assertMatchesRegularExpression(
            '#"vue"\s*:\s*"[^"]*vue3/vue\.runtime\.esm-browser\.prod\.js"#',
            $body,
            'the import map must map "vue" to the production build'
        );
    }

    public function testAppSourceIsNotReachableUnderVendorPrefix(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/vendor/js/app.js'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUnknownVendorFileReturns404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/vendor/vue3/vue.esm-browser.prod.js'));

        $this->assertSame(404, $response->getStatusCode());
    }
}