<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhuePartialRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the PhMarkdown SSR component (hybrid partial pattern).
 *
 * The component's <php> block parses raw markdown ($markdown) or reads a
 * base-relative file ($src) server-side via Phue\Core\Utils\MarkdownParser
 * and returns the rendered HTML as the static html payload — consumed by
 * PhuePartialRenderer in Direct Static HTML mode.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhMarkdownTest extends TestCase
{
    private PhueComponentLoader $loader;

    private ?string $tmpFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Load the real PhMarkdown component from the PhUI kit directory
        $basePath = dirname(__DIR__, 3);
        $this->loader = new PhueComponentLoader($basePath . '/src/Core/UI/Phui');

        $this->tmpFile = null;
    }

    protected function tearDown(): void
    {
        if ($this->tmpFile !== null && is_file($this->tmpFile)) {
            @unlink($this->tmpFile);
        }
        $this->tmpFile = null;

        parent::tearDown();
    }

    // ------------------------------------------------------------- tests

    public function testParsesRawMarkdownParameter(): void
    {
        $component = $this->loader->load('PhMarkdown', ['markdown' => "## Title\n\nA paragraph."]);

        $view = $component->view;
        $this->assertTrue($view->hasHtml(), 'PhMarkdown must return static html (SSR mode)');
        $this->assertStringContainsString('<h2>Title</h2>', $view->getHtml());
        $this->assertStringContainsString('<p>A paragraph.</p>', $view->getHtml());
    }

    public function testReadsBaseRelativeSrcFile(): void
    {
        // storage/logs is gitignored and PathResolver resolves cwd upward —
        // tests run with cwd at the app root, so a relative $src lands here.
        $this->tmpFile = getcwd() . '/storage/logs/phue-markdown-test-' . uniqid() . '.md';
        @mkdir(dirname($this->tmpFile), 0777, true);
        file_put_contents($this->tmpFile, "**bold** content\n");

        $relative = str_replace(getcwd() . '/', '', $this->tmpFile);
        $component = $this->loader->load('PhMarkdown', ['src' => $relative]);

        $this->assertTrue($component->view->hasHtml());
        $this->assertStringContainsString('<strong>bold</strong> content', $component->view->getHtml());
    }

    public function testMissingSrcFileYieldsEmptyHtml(): void
    {
        $component = $this->loader->load('PhMarkdown', ['src' => 'definitely/missing.md']);

        $this->assertTrue($component->view->hasHtml());
        $this->assertSame('', $component->view->getHtml());
    }

    public function testNoParametersYieldsEmptyHtml(): void
    {
        $component = $this->loader->load('PhMarkdown');

        $this->assertTrue($component->view->hasHtml());
        $this->assertSame('', $component->view->getHtml());
    }

    public function testXssContentIsEscaped(): void
    {
        $component = $this->loader->load('PhMarkdown', ['markdown' => '<script>alert(1)</script>']);

        $html = $component->view->getHtml();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testRendersThroughPartialRenderer(): void
    {
        $registry = new ComponentLoaderRegistry();
        $registry->addLoader($this->loader);

        $partialRenderer = new PhuePartialRenderer($registry);

        $html = $partialRenderer->renderPartial('PhMarkdown', ['markdown' => '# Hello SSR']);

        $this->assertStringContainsString('<h1>Hello SSR</h1>', $html);
        $this->assertStringNotContainsString('Partial rendering failed', $html);
    }

    public function testComponentHasNoClientScript(): void
    {
        // SSR-first contract: PhMarkdown must ship zero client-side JS
        $this->assertNull($this->loader->contentFor('PhMarkdown'));
    }
}
