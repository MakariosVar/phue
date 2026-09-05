<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Asset;

use PHPUnit\Framework\TestCase;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;

/**
 * Unit tests for the HtmlShellRenderer: document structure, module script
 * rendering and the full XSS / tag-breakout escaping surface.
 *
 * @package Phue\Tests\Core\Asset
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class HtmlShellRendererTest extends TestCase
{
    private AssetRegistry $registry;
    private HtmlShellRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new AssetRegistry();
        $this->renderer = new HtmlShellRenderer($this->registry);
    }

    public function testRendersValidHtml5Defaults(): void
    {
        $html = $this->renderer->render();

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('<meta charset="UTF-8">', $html);
        $this->assertStringContainsString('<title>Phue App</title>', $html);
        $this->assertStringContainsString('<div id="app"></div>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testRendersCustomTitleAndMountId(): void
    {
        $html = $this->renderer->render('Dashboard', 'root');

        $this->assertStringContainsString('<title>Dashboard</title>', $html);
        $this->assertStringContainsString('<div id="root"></div>', $html);
        $this->assertStringNotContainsString('<div id="app"></div>', $html);
    }

    public function testIncludesRegisteredStyleSheets(): void
    {
        $this->registry->addStyle('/assets/theme.css');

        $html = $this->renderer->render();

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/theme.css">', $html);
    }

    public function testIncludesRegisteredScriptsWithoutModuleType(): void
    {
        $this->registry->addScript('/assets/app.js');

        $html = $this->renderer->render();

        $this->assertStringContainsString('<script src="/assets/app.js"></script>', $html);
        $this->assertStringNotContainsString('type="module"', $html);
    }

    public function testIncludesRegisteredModuleScripts(): void
    {
        $this->registry->addScript('/assets/vendor/vue.js', true);

        $html = $this->renderer->render();

        $this->assertStringContainsString('<script type="module" src="/assets/vendor/vue.js"></script>', $html);
    }

    public function testPreservesScriptRegistrationOrder(): void
    {
        $this->registry
            ->addScript('/assets/first.js')
            ->addScript('/assets/second.js', true);

        $html = $this->renderer->render();

        $this->assertTrue(
            strpos($html, '/assets/first.js') < strpos($html, '/assets/second.js')
        );
    }

    public function testEscapesScriptTagInTitle(): void
    {
        $html = $this->renderer->render('<script>alert(1)</script>');

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function testEscapesQuotesInMountId(): void
    {
        $html = $this->renderer->render('T', 'app"><script>alert(1)</script>');

        $this->assertStringContainsString('id="app&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('id="app">', $html);
    }

    public function testEscapesScriptAndStyleUrls(): void
    {
        $this->registry
            ->addScript('/assets/app.js" onload="alert(1)')
            ->addStyle('/assets/x.css"><script>alert(1)</script>');

        $html = $this->renderer->render();

        $this->assertStringContainsString('src="/assets/app.js&quot; onload=&quot;alert(1)"', $html);
        $this->assertStringContainsString('href="/assets/x.css&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('onload="alert(1)">', $html);
        $this->assertStringNotContainsString('"><script>alert(1)', $html);
    }

    public function testInjectsInitialStateBeforeScripts(): void
    {
        $this->registry->addScript('/assets/app.js');

        $html = $this->renderer->render('T', 'app', ['user' => 'mak']);

        $this->assertStringContainsString('window.__INITIAL_STATE__ = {"user":"mak"};', $html);
        $this->assertTrue(
            strpos($html, '__INITIAL_STATE__') < strpos($html, '<script src')
        );
    }

    public function testStateEscapesClosingScriptTagBreakout(): void
    {
        $html = $this->renderer->render('T', 'app', ['note' => '</script><script>alert(1)</script>']);

        $this->assertStringContainsString('\u003C\/script\u003E', $html);
        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $html);
    }

    public function testOmitsStateScriptWhenNoInitialState(): void
    {
        $html = $this->renderer->render();

        $this->assertStringNotContainsString('__INITIAL_STATE__', $html);
    }

    public function testFallsBackToEmptyObjectOnJsonEncodeFailure(): void
    {
        $html = $this->renderer->render('T', 'app', ['bad' => NAN]);

        $this->assertStringContainsString('window.__INITIAL_STATE__ = {};', $html);
    }

    public function testRendersOnlyValidUtf8SubstitutionOnInvalidCharacters(): void
    {
        $html = $this->renderer->render("Ti\xC3\x28tle");

        $this->assertStringContainsString('Ti' . "\u{FFFD}" . '(tle', $html);
    }
}