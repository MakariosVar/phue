<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Response;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\View;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ComponentRenderer: shell assembly, template inlining into the
 * mount container, initial state serialization and ESM script enqueuing.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class ComponentRendererTest extends TestCase
{
    private ComponentRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $sharedRegistry = new AssetRegistry();
        $this->renderer = new ComponentRenderer(
            new HtmlShellRenderer($sharedRegistry),
            $sharedRegistry
        );
    }

    public function testRendersShellWithInlinedTemplateTitleAndState(): void
    {
        $view = new View(
            '<div class="container"><h2>Hello</h2></div>',
            ['products' => [['id' => 1, 'name' => 'Widget']]],
            ['/assets/app.js']
        );

        $response = $this->renderer->render('Demo Page', $view);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeader('content-type'));

        $html = $response->getContent();
        $this->assertStringContainsString('<title>Demo Page</title>', $html);
        $this->assertStringContainsString(
            '<div id="app"><div class="container"><h2>Hello</h2></div></div>',
            $html
        );
        $this->assertStringContainsString(
            'window.__INITIAL_STATE__ = {"products":[{"id":1,"name":"Widget"}]}',
            $html
        );
        $this->assertStringContainsString(
            '<script type="module" src="/assets/app.js"></script>',
            $html
        );
    }

    public function testRenderingTwiceDoesNotDuplicateScripts(): void
    {
        $view = new View('<div>Hi</div>', [], ['/assets/app.js']);

        $htmlOne = $this->renderer->render('A', $view)->getContent();
        $htmlTwo = $this->renderer->render('B', $view)->getContent();

        $this->assertSame(1, substr_count($htmlOne, 'src="/assets/app.js"'));
        $this->assertSame(1, substr_count($htmlTwo, 'src="/assets/app.js"'));
        $this->assertSame(1, substr_count($htmlOne, '<div id="app">'));
        $this->assertSame(1, substr_count($htmlTwo, '<div id="app">'));
    }

    public function testStateScriptBreakoutIsEscaped(): void
    {
        $view = new View(
            '<div>Hi</div>',
            ['note' => '</script><script>alert(1)</script>']
        );

        $html = $this->renderer->render('XSS', $view)->getContent();

        $this->assertStringContainsString('\u003C', $html);
        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $html);
    }

    public function testTemplateMarkupPreservesAttributesAndDirectives(): void
    {
        $view = new View(
            '<input v-model="newProduct.name" placeholder="Name" required>',
            ['newProduct' => ['name' => '', 'price' => '']]
        );

        $html = $this->renderer->render('Form', $view)->getContent();

        $this->assertStringContainsString(
            '<input v-model="newProduct.name" placeholder="Name" required>',
            $html
        );
    }
}