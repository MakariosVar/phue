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
 * End-to-end contract for the 'styles' metadata:
 *   1. A .phue component's <php> block may return 'styles' => [...urls].
 *   2. ComponentRenderer promotes them to the AssetRegistry request pool
 *      (addRequestStyle) and emits <link rel="stylesheet"> tags in the shell head.
 *   3. They are request-scoped — cleared by beginRequest(), never leaking
 *      across renders of a persistent Swoole process.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class ViewStylesTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phue_style_fixture_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
        parent::tearDown();
    }

    public function testPhpStylesMetadataFlowsIntoViewGetStyles(): void
    {
        file_put_contents($this->tmpDir . '/StylePage.phue', $this->buildPhueFile());

        $loader = TestComponentLoader::make($this->tmpDir);
        $component = $loader->load('StylePage');

        $this->assertContains('/assets/app/styles/main.css', $component->view->getStyles());
        $this->assertContains('/assets/app/styles/extra.css', $component->view->getStyles());
    }

    public function testRenderedStylesBecomeHeadLinkTags(): void
    {
        file_put_contents($this->tmpDir . '/StyledPage.phue', $this->buildPhueFile());

        $registry = new AssetRegistry();
        $loader = TestComponentLoader::make($this->tmpDir);
        $renderer = new ComponentRenderer(
            new HtmlShellRenderer($registry),
            $registry,
            loader: $loader
        );

        $html = $renderer->render('StyledPage', ['page' => 'home'])->getContent();

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/app/styles/main.css">', $html);
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/app/styles/extra.css">', $html);
    }

    public function testRequestStylesDoNotLeakToDifferentPageAfterBeginRequest(): void
    {
        file_put_contents($this->tmpDir . '/StyledPage.phue', $this->buildPhueFile());
        file_put_contents($this->tmpDir . '/PlainPage.phue', $this->buildPlainPhueFile());

        $registry = new AssetRegistry();
        $loader = TestComponentLoader::make($this->tmpDir);
        $renderer = new ComponentRenderer(
            new HtmlShellRenderer($registry),
            $registry,
            loader: $loader
        );

        // Request #1: page declares styles → shell carries them.
        $first = $renderer->render('StyledPage', [])->getContent();
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/app/styles/main.css">', $first);

        // Persistent-process boundary — request pool flushed.
        $registry->beginRequest();

        // Request #2: a DIFFERENT page (no styles metadata) → main.css is GONE,
        // proving request-scoped styles do not leak across unrelated renders.
        $second = $renderer->render('PlainPage', [])->getContent();
        $this->assertStringNotContainsString(
            '<link rel="stylesheet" href="/assets/app/styles/main.css">',
            $second,
            'Request-scoped styles from a prior render must not leak into unrelated pages'
        );
    }

    public function testRequestStylesReappearOnDemandWhenSamePageRendersAgain(): void
    {
        file_put_contents($this->tmpDir . '/StyledPage.phue', $this->buildPhueFile());

        $registry = new AssetRegistry();
        $loader = TestComponentLoader::make($this->tmpDir);
        $renderer = new ComponentRenderer(
            new HtmlShellRenderer($registry),
            $registry,
            loader: $loader
        );

        // Request #1: styles rendered (demand).
        $first = $renderer->render('StyledPage', [])->getContent();
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/app/styles/main.css">', $first);

        // Persistent-process boundary flushes the request pool.
        $registry->beginRequest();

        // Request #2: same page re-rendered → because the metadata says it
        // declares styles, the demand path re-registers and re-emits them.
        $second = $renderer->render('StyledPage', [])->getContent();
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/app/styles/main.css">', $second);
    }

    public function testViewStylesRenderedWithoutAnyGlobalStyles(): void
    {
        file_put_contents($this->tmpDir . '/StyledPage.phue', $this->buildPhueFile());

        $registry = new AssetRegistry();
        $loader = TestComponentLoader::make($this->tmpDir);
        $renderer = new ComponentRenderer(
            new HtmlShellRenderer($registry),
            $registry,
            loader: $loader
        );

        $html = $renderer->render('StyledPage', [])->getContent();

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/app/styles/main.css">', $html);
        $this->assertStringNotContainsString('<link rel="stylesheet" href="/assets/app/global.css">', $html);
    }

    private function buildPhueFile(): string
    {
        return <<<'PHUE'
<php>
return [
    'title' => 'Style Page',
    'state' => ['page' => 'styled'],
    'styles' => ['/assets/app/styles/main.css', '/assets/app/styles/extra.css'],
];
</php>
<template>
<div>Styled page content</div>
</template>
PHUE;
    }

    private function buildPlainPhueFile(): string
    {
        return <<<'PHUE'
<php>
return [
    'title' => 'Plain Page',
    'state' => ['page' => 'plain'],
];
</php>
<template>
<div>Plain page content</div>
</template>
PHUE;
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

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeTree($path);
            }
        }

        @rmdir($dir);
    }
}
