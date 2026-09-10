<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Asset;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\AssetServer;
use PHPUnit\Framework\TestCase;

/**
 * Contracts for the default Bootstrap stylesheet pipeline:
 * - AssetServer serves it with correct MIME + security headers.
 * - HtmlShellRenderer emits it as a <link> by default when registered.
 * - Token namespace (--bs-*) exists in the file.
 * - Application override seam allows replacing or appending stylesheets.
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhueDefaultStylesheetTest extends TestCase
{
    /**
     * The Bootstrap stylesheet shipped under storage/assets/vendor/bootstrap.
     */
    private const FRAMEWORK_CSS = __DIR__ . '/../../../storage/assets/vendor/bootstrap/bootstrap.min.css';

    private string $baseDir;
    private string $stylesDir;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/phue_css_' . uniqid('', true);
        $this->stylesDir = $this->baseDir . '/storage/assets/styles';
        mkdir($this->stylesDir, 0777, true);

        // Seed the temp styles dir with the real framework stylesheet so serving
        // tests exercise the actual shipped content.
        $bsTarget = $this->baseDir . '/storage/assets/vendor/bootstrap';
        mkdir($bsTarget, 0777, true);
        copy(self::FRAMEWORK_CSS, $bsTarget . '/bootstrap.min.css');

        // Also create a styles dir symlink so the old /assets/styles path works for tests
        copy(self::FRAMEWORK_CSS, $this->stylesDir . '/phue.css');

        $this->app = new Application($this->baseDir);

        $registry = $this->app->getContainer()->get(AssetRegistry::class);
        $registry->registerSource('/assets/styles', $this->stylesDir);
        $registry->registerSource('/assets/vendor/bootstrap', $bsTarget);
        $registry->addStyle('/assets/vendor/bootstrap/bootstrap.min.css');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->baseDir);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // HTTP Serving
    // -------------------------------------------------------------------------

    public function testStylesheetServedWithCssMimeAndNoSniff(): void
    {
        $server = new AssetServer(
            $this->app->getContainer()->get(AssetRegistry::class),
        );

        $response = $server->serve('/assets/vendor/bootstrap/bootstrap.min.css');

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/css', $response->getHeader('Content-Type'));
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    public function testStylesheetContentIsReturnedVerbatim(): void
    {
        $server = new AssetServer(
            $this->app->getContainer()->get(AssetRegistry::class),
        );

        $response = $server->serve('/assets/vendor/bootstrap/bootstrap.min.css');

        $this->assertSame(
            file_get_contents($this->baseDir . '/storage/assets/vendor/bootstrap/bootstrap.min.css'),
            $response->getContent(),
        );
    }

    public function testUnregisteredStylesPathReturns404(): void
    {
        $server = new AssetServer(
            $this->app->getContainer()->get(AssetRegistry::class),
        );

        $response = $server->serve('/assets/styles/missing.css');

        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('text/css', $response->getHeader('Content-Type'));
    }

    // -------------------------------------------------------------------------
    // Shell Renderer Emission (default <link> in <head>)
    // -------------------------------------------------------------------------

    public function testShellRendersDefaultStylesheetLink(): void
    {
        $renderer = $this->app->getContainer()->get(\Phue\Core\Asset\HtmlShellRenderer::class);

        $html = $renderer->render(title: 'Test');

        $this->assertStringContainsString(
            '<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">',
            $html,
        );
    }

    public function testShellStylesLinkAppearsInsideHeadElement(): void
    {
        $renderer = $this->app->getContainer()->get(\Phue\Core\Asset\HtmlShellRenderer::class);

        $html = $renderer->render(title: 'Test');
        [$head] = explode('</head>', $html, 2);

        $this->assertStringContainsString('/assets/vendor/bootstrap/bootstrap.min.css', $head);
    }

    // -------------------------------------------------------------------------
    // Override Seam (replace or append)
    // -------------------------------------------------------------------------

    public function testAppCanOverrideStylesheetUrl(): void
    {
        $registry = $this->app->getContainer()->get(AssetRegistry::class);

        // Remove default, register custom
        $registry->addStyle('https://cdn.example.com/custom-theme.css');

        $renderer = $this->app->getContainer()->get(\Phue\Core\Asset\HtmlShellRenderer::class);
        $html = $renderer->render(title: 'Test');

        $this->assertStringContainsString('https://cdn.example.com/custom-theme.css', $html);
        $this->assertStringContainsString('/assets/vendor/bootstrap/bootstrap.min.css', $html);
    }

    public function testMultipleStylesheetsPreserveInsertionOrder(): void
    {
        $registry = $this->app->getContainer()->get(AssetRegistry::class);
        $registry->addStyle('/assets/vendor/bootstrap/bootstrap.min.css');
        $registry->addStyle('/assets/styles/override.css');

        $renderer = $this->app->getContainer()->get(\Phue\Core\Asset\HtmlShellRenderer::class);
        $html = $renderer->render(title: 'Test');

        $defaultPos = strpos($html, '/assets/vendor/bootstrap/bootstrap.min.css');
        $overridePos = strpos($html, '/assets/styles/override.css');

        $this->assertNotFalse($defaultPos);
        $this->assertNotFalse($overridePos);
        $this->assertLessThan($overridePos, $defaultPos, 'Default must come before override (CSS cascade)');
    }

    // -------------------------------------------------------------------------
    // Bootstrap Token Namespace Convention (--bs-*)
    // -------------------------------------------------------------------------

    public function testStylesheetContainsTokenCustomProperties(): void
    {
        $content = file_get_contents($this->baseDir . '/storage/assets/vendor/bootstrap/bootstrap.min.css');

        $this->assertMatchesRegularExpression(
            '/--bs-[a-z]/',
            $content,
            'bootstrap.min.css must define CSS custom properties prefixed with --bs-',
        );
    }

    public function testStylesheetDefinesCoreDesignTokenCategories(): void
    {
        $content = file_get_contents($this->baseDir . '/storage/assets/vendor/bootstrap/bootstrap.min.css');

        $hasColorToken  = str_contains($content, '--bs-primary') || str_contains($content, '--bs-body-bg') || str_contains($content, '--bs-body-color');
        $hasSpacingToken = str_contains($content, '--bs-spacer') || str_contains($content, '--bs-border-radius');
        $hasFontToken   = str_contains($content, '--bs-font-sans-serif') || str_contains($content, '--bs-font-monospace');

        $this->assertTrue($hasColorToken,  'bootstrap.min.css must define color tokens (--bs-primary, --bs-body-bg, or --bs-body-color)');
        $this->assertTrue($hasSpacingToken, 'bootstrap.min.css must define spacing tokens (--bs-spacer or --bs-border-radius)');
        $this->assertTrue($hasFontToken,   'bootstrap.min.css must define typography tokens (--bs-font-sans-serif or --bs-font-monospace)');
    }

    // -------------------------------------------------------------------------
    // Light Utility Set
    // -------------------------------------------------------------------------

    public function testStylesheetContainsFlexboxUtilityClasses(): void
    {
        $content = file_get_contents($this->baseDir . '/storage/assets/vendor/bootstrap/bootstrap.min.css');

        $this->assertStringContainsString('.d-flex', $content, 'Must provide .d-flex utility class');
    }

    public function testStylesheetContainsDisplayUtilityClasses(): void
    {
        $content = file_get_contents($this->baseDir . '/storage/assets/vendor/bootstrap/bootstrap.min.css');

        $hasDisplay = str_contains($content, '.d-flex')
            || str_contains($content, '.d-none')
            || str_contains($content, '.d-block');

        $this->assertTrue($hasDisplay, 'Must provide display utilities (.d-flex, .d-none, or .d-block)');
    }

    public function testStylesheetContainsSpacingUtilityClasses(): void
    {
        $content = file_get_contents($this->baseDir . '/storage/assets/vendor/bootstrap/bootstrap.min.css');

        $hasSpacing = preg_match('/\.(p|m)-[a-z0-9]/', $content) > 0;

        $this->assertTrue($hasSpacing, 'Must provide spacing utilities (.p-* or .m-*)');
    }

    public function testStylesheetSizeIsReasonable(): void
    {
        $size = filesize($this->baseDir . '/storage/assets/vendor/bootstrap/bootstrap.min.css');

        $this->assertGreaterThan(0, $size, 'bootstrap.min.css must not be empty');
        $this->assertLessThanOrEqual(500 * 1024, $size, 'bootstrap.min.css must not exceed 500KB');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
