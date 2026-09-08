<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\PhueComponentLoader;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the Layout Engine seam in ComponentRenderer.
 *
 * Locks:
 *  - content wrapping: a configured html-first layout receives the page markup
 *    as "$content" and the current URI as "$uri", and its resolved HTML becomes
 *    the #app content,
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
            new PhueComponentLoader($this->viewsDir),
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

        $this->assertStringContainsString(
            '<div id="app"><div class="wrap" data-uri="/products/42"><header>NAV</header><main><h1>PageContent</h1></main></div></div>',
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

        $this->assertStringContainsString('<main><div class="page">{{ data }}</div></main>', $html);
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
            new PhueComponentLoader($this->viewsDir),
        );

        $html = $renderer->render('Page')->getContent();

        $this->assertStringContainsString('<div id="app"><div class="page">{{ data }}</div></div>', $html);
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
            '<div id="app"><div class="wrap"><main><section>server</section></main></div></div>',
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

        $this->assertStringContainsString(
            '<div id="app"><div class="fallback">no-php</div></div>',
            $html,
        );
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