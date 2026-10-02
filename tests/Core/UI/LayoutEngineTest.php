<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\PhueComponentLoader;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * Contract tests for the Layout Engine seam in ComponentRenderer.
 *
 * Locks:
 *  - content wrapping: a configured html-first layout receives the page markup
 *    isolated inside the `#app` container as "$content" and the current URI as
 *    "$uri", and its resolved HTML becomes the body-level chrome that wraps
 *    AROUND the mount container (layout stays OUTSIDE #app),
 *  - page mount scripts stay independent of the wrap (still emitted as module),
 *  - backwards compatibility: without layoutName the render path is unchanged,
 *  - uriResolver default fallback to '/'.
 *
 * These tests exercise the seam directly (renderer + loader + fixtures) and are
 * independent of any application-level wiring.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class LayoutEngineTest extends TestCase
{
    private string $baseDir;
    private string $viewsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/phue_layout_' . uniqid('', true);
        $this->viewsDir = $this->baseDir . '/views';
        mkdir($this->viewsDir, 0777, true);
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

    private function makeRenderer(string $layoutName, ?callable $uriResolver = null): ComponentRenderer
    {
        $registry = new AssetRegistry();

        return new ComponentRenderer(
            new HtmlShellRenderer($registry),
            $registry,
            TestComponentLoader::make($this->viewsDir),
            $layoutName,
            $uriResolver,
        );
    }

    public function testLayoutWrapsPageMarkupAndReceivesUri(): void
    {
        $this->writeComponent('AppLayout', <<<'PHUE'
<php>
$content = $content ?? '';
$uri = is_string($uri ?? null) ? $uri : '/';
$safeUri = htmlspecialchars($uri, ENT_QUOTES, 'UTF-8');

return [
    'html' => sprintf(
        '<div class="wrap" data-uri="%s"><header>NAV</header><main>%s</main></div>',
        $safeUri,
        $content
    ),
];
</php>

<template>
    this literal template is ignored
</template>
PHUE);

        $this->writeComponent('Page', <<<'PHUE'
<php>
return ['title' => 'The Page', 'state' => ['v' => 1]];
</php>

<template>
    <h1>PageContent</h1>
</template>
PHUE);

        $renderer = $this->makeRenderer('AppLayout', fn () => '/products/42');
        $html = $renderer->render('Page')->getContent();

        // The page is a `.phue` with no SSR engine, so its mount container is
        // empty — the client renders it. What this test is about is the LAYOUT:
        // the wrapper, the header, the uri, and that the page's own template was
        // not used as the layout's.
        $this->assertStringContainsString(
            '<div class="wrap" data-uri="/products/42"><header>NAV</header><main><div id="app"></div></main></div>',
            $html,
        );
        $this->assertStringNotContainsString('this literal template is ignored', $html);
        $this->assertStringContainsString('<title>The Page</title>', $html);
    }

    public function testLayoutKeepsPageMountScriptAsModule(): void
    {
        $this->writeComponent('AppLayout', <<<'PHUE'
<php>
$content = $content ?? '';
return ['html' => '<div class="wrap"><main>' . $content . '</main></div>'];
</php>

<template>
    ignored
</template>
PHUE);

        $this->writeComponent('Page', <<<'PHUE'
<php>
return ['title' => 'P'];
</php>

<template>
    <div class="page">{{ data }}</div>
</template>

<script>
import { createApp } from '/assets/vendor/vue3/vue.esm-browser.js';
createApp({}).mount('#app');
</script>
PHUE);

        $renderer = $this->makeRenderer('AppLayout');
        $html = $renderer->render('Page')->getContent();

        $this->assertStringContainsString('<main><div id="app"><div class="page">{{ data }}</div></div></main>', $html);
        $this->assertMatchesRegularExpression(
            '/<script type="module" src="\/assets\/phue\/Page\.[a-f0-9]{8}\.phue\.js"><\/script>/',
            $html,
        );
    }

    public function testUriResolverDefaultsToRootWhenAbsent(): void
    {
        $this->writeComponent('AppLayout', <<<'PHUE'
<php>
$uri = is_string($uri ?? null) ? $uri : '/default/';
return ['html' => '<div class="wrap" data-uri="' . htmlspecialchars($uri, ENT_QUOTES, 'UTF-8') . '">' . ($content ?? '') . '</div>'];
</php>

<template>
    ignored
</template>
PHUE);

        $this->writeComponent('Page', <<<'PHUE'
<php>
return ['title' => 'P'];
</php>

<template>
    <p>yes</p>
</template>
PHUE);

        $renderer = $this->makeRenderer('AppLayout');
        $html = $renderer->render('Page')->getContent();

        $this->assertStringContainsString('data-uri="/"', $html);
        $this->assertStringNotContainsString('/default/', $html);
    }

    public function testWithoutLayoutNameRenderPathIsUnchanged(): void
    {
        $this->writeComponent('Page', <<<'PHUE'
<php>
return ['title' => 'P'];
</php>

<template>
    <div class="page">{{ data }}</div>
</template>
PHUE);

        $registry = new AssetRegistry();
        $renderer = new ComponentRenderer(
            new HtmlShellRenderer($registry),
            $registry,
            TestComponentLoader::make($this->viewsDir),
        );

        $html = $renderer->render('Page')->getContent();

        // No SSR engine is configured, and a `.phue` is a Vue template: the
        // container is empty and the client renders it. What this test is about is
        // the LAYOUT — that no wrapper was applied — so it asserts the container
        // exists rather than what is inside it.
        $this->assertStringContainsString('id="app"', $html);
        $this->assertStringNotContainsString('{{ data }}', $html);
        $this->assertStringNotContainsString('wrap', $html);
    }

    public function testLayoutAlsoWrapsWhenPageIsHtmlFirst(): void
    {
        $this->writeComponent('AppLayout', <<<'PHUE'
<php>
$content = $content ?? '';
return ['html' => '<div class="wrap"><main>' . $content . '</main></div>'];
</php>

<template>
    ignored
</template>
PHUE);

        $this->writeComponent('Page', <<<'PHUE'
<php>
return ['title' => 'Html Page', 'html' => '<section>server</section>'];
</php>

<template>
    ignored
</template>
PHUE);

        $renderer = $this->makeRenderer('AppLayout');
        $html = $renderer->render('Page')->getContent();

        $this->assertStringContainsString(
            '<div class="wrap"><main><div id="app"><section>server</section></div></main></div>',
            $html,
        );
    }

    public function testLayoutMissingReturnedHtmlFallsBackToItsTemplate(): void
    {
        $this->writeComponent('FallbackLayout', <<<'PHUE'
<template>
    <div class="fallback">no-php</div>
</template>
PHUE);

        $this->writeComponent('Page', <<<'PHUE'
<php>
return ['title' => 'P'];
</php>

<template>
    <p>yes</p>
</template>
PHUE);

        $renderer = $this->makeRenderer('FallbackLayout');
        $html = $renderer->render('Page')->getContent();

        $this->assertStringNotContainsString('<p>yes</p>', $html);
        $this->assertStringContainsString(
            '<div class="fallback">no-php</div>',
            $html,
        );
        $this->assertStringNotContainsString('<div id="app">', $html);
    }

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