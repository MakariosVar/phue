<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Response;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\View;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * Contract tests for the HTML-first SSR primitive:
 *  - A <php> block may return an 'html' key whose string becomes the #app
 *    content, fully resolved server-side (no Vue template compilation).
 *  - When 'html' is absent, the classic Vue in-DOM template path stays intact
 *    (strict backwards compatibility, unchanged shell behavior).
 *  - 'html' takes precedence over an existing static <template> when both are
 *    declared (server-rendered output must win).
 *  - The html payload survives the module-path rebuild in renderComponentAsModule.
 *  - State + title metadata flow through unchanged in HTML-first mode.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class HtmlFirstPrimitiveTest extends TestCase
{
    private string $baseDir;
    private string $viewsDir;
    private ComponentRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/phue_html_first_' . uniqid('', true);
        $this->viewsDir = $this->baseDir . '/views';
        mkdir($this->viewsDir, 0777, true);

        $registry = new AssetRegistry();
        $this->renderer = new ComponentRenderer(
            new HtmlShellRenderer($registry),
            $registry,
            TestComponentLoader::make($this->viewsDir)
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->baseDir);
        parent::tearDown();
    }

    private function writeComponent(string $name, string $content): void
    {
        file_put_contents($this->viewsDir . '/' . $name . '.phue', $content);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Loader plumbing: meta['html']  ->  View::hasHtml()/getHtml()
    // ───────────────────────────────────────────────────────────────────────

    public function testLoaderSurfacesHtmlPayloadOnView(): void
    {
        $this->writeComponent('HtmlFirst', <<<'PHUE'
<php>
return [
    'title' => 'Html Page',
    'html'  => '<header>Nav</header><main>Body</main>',
];
</php>

<template>
    <div>this template must be ignored</div>
</template>
PHUE);

        $loader = TestComponentLoader::make($this->viewsDir);
        $component = $loader->load('HtmlFirst');

        $this->assertTrue($component->view->hasHtml());
        $this->assertSame('<header>Nav</header><main>Body</main>', $component->view->getHtml());
    }

    public function testLoaderLeavesViewInTemplateModeWithoutHtmlKey(): void
    {
        $this->writeComponent('Plain', <<<'PHUE'
<php>
return ['title' => 'Plain', 'state' => ['n' => 1]];
</php>

<template>
    <div class="vanilla">{{ n }}</div>
</template>
PHUE);

        $loader = TestComponentLoader::make($this->viewsDir);
        $component = $loader->load('Plain');

        $this->assertFalse($component->view->hasHtml());
        $this->assertSame('<div class="vanilla">{{ n }}</div>', $component->view->getTemplate());
    }

    // ───────────────────────────────────────────────────────────────────────
    // Renderer: content selection + shell assembly
    // ───────────────────────────────────────────────────────────────────────

    public function testHtmlFirstPageRendersHtmlIntoAppContainer(): void
    {
        $this->writeComponent('HtmlFirst', <<<'PHUE'
<php>
return [
    'title' => 'Html Page',
    'html'  => '<header>Nav</header><main>Body</main>',
];
</php>

<template>
    <div>this template must be ignored</div>
</template>
PHUE);

        $response = $this->renderer->render('HtmlFirst');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $html = $response->getContent();
        $this->assertStringContainsString('<title>Html Page</title>', $html);
        $this->assertStringContainsString(
            '<div id="app"><header>Nav</header><main>Body</main></div>',
            $html,
        );
        $this->assertStringNotContainsString('must be ignored', $html);
    }

    public function testTemplateModeRenderPathIsUnchanged(): void
    {
        $this->writeComponent('Plain', <<<'PHUE'
<php>
return ['title' => 'Plain', 'state' => ['n' => 42]];
</php>

<template>
    <div class="vanilla">{{ n }}</div>
</template>
PHUE);

        $html = $this->renderer->render('Plain')->getContent();

        $this->assertStringContainsString(
            '<div id="app"><div class="vanilla">{{ n }}</div></div>',
            $html,
        );
        $this->assertStringContainsString('window.__INITIAL_STATE__', $html);
    }

    public function testHtmlTakesPrecedenceOverDeclaredTemplate(): void
    {
        $view = new View(
            '<div class="client-template">tpl</div>',
            [],
            [],
            null,
            '<section>server-rendered</section>',
        );

        $html = $this->renderer->render('Both', $view)->getContent();

        $this->assertStringContainsString('<div id="app"><section>server-rendered</section></div>', $html);
        $this->assertStringNotContainsString('client-template', $html);
    }

    public function testHtmlFirstViaDirectViewBcPath(): void
    {
        $view = new View('<div>ignored</div>', [], [], null, '<p>direct</p>');

        $html = $this->renderer->render('Title Only', $view)->getContent();

        $this->assertStringContainsString('<div id="app"><p>direct</p></div>', $html);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Edge: hybrid page (html + mount script) through the module path
    // ───────────────────────────────────────────────────────────────────────

    public function testHtmlSurvivesModulePathRebuildWithInlineScript(): void
    {
        $this->writeComponent('Hybrid', <<<'PHUE'
<php>
return [
    'title' => 'Hybrid',
    'html'  => '<nav><a href="/">Home</a></nav>',
];
</php>

<template>
    <div>{{ clientOnly }}</div>
</template>

<script>
import { createApp } from '/assets/vendor/vue3/vue.esm-browser.js';
createApp({}).mount('#app');
</script>
PHUE);

        $html = $this->renderer->render('Hybrid')->getContent();

        $this->assertStringContainsString('<div id="app"><nav><a href="/">Home</a></nav></div>', $html);
        $this->assertStringNotContainsString('clientOnly', $html);
        $this->assertMatchesRegularExpression(
            '/<script type="module" src="\/assets\/phue\/Hybrid\.[a-f0-9]{8}\.phue\.js"><\/script>/',
            $html,
        );
    }

    public function testHtmlOnlyPageEmitsNoModuleScript(): void
    {
        $this->writeComponent('PureHtml', <<<'PHUE'
<php>
return ['title' => 'Pure', 'html' => '<aside>nav</aside><article>body</article>'];
</php>

<template>
    <div>ignored</div>
</template>
PHUE);

        $html = $this->renderer->render('PureHtml')->getContent();

        $this->assertStringContainsString('<div id="app"><aside>nav</aside><article>body</article></div>', $html);
        $this->assertStringNotContainsString('.phue.js', $html);
    }

    // ───────────────────────────────────────────────────────────────────────
    // State & escaping still apply in HTML-first mode
    // ───────────────────────────────────────────────────────────────────────

    public function testStateAndTitleFlowThroughHtmlFirstPage(): void
    {
        $this->writeComponent('Stateful', <<<'PHUE'
<php>
return [
    'title' => 'Stateful',
    'state' => ['user' => 'ada'],
    'html'  => '<p>Hi</p>',
];
</php>

<template>
    <div>ignored</div>
</template>
PHUE);

        $html = $this->renderer->render('Stateful')->getContent();

        $this->assertStringContainsString('<title>Stateful</title>', $html);
        $this->assertStringContainsString('window.__INITIAL_STATE__ = {"user":"ada"}', $html);
    }

    public function testHtmlPayloadIsPassedThroughVerbatim(): void
    {
        $markup = '</div><script>bad()</script><p>ok</p>';
        $view = new View('<div>ignored</div>', [], [], null, $markup);

        $html = $this->renderer->render('Passthrough', $view)->getContent();

        $this->assertStringContainsString('<div id="app">' . $markup . '</div>', $html,);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────────────────────────────

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