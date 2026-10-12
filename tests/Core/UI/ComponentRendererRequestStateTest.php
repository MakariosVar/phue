<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Container;
use Phue\Core\UI\ComponentRenderer;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * Verification gate: is per-request render state confined to one request?
 *
 * Background
 * ----------
 * ComponentRenderer is registered as a container **singleton**
 * (`src/Core/Application.php`, `$this->container->singleton(ComponentRenderer::class, ...)`),
 * so one instance serves every request a Swoole/RoadRunner worker handles.
 *
 * It keeps `$renderedStyles`, a map of component name => true, used to emit a
 * component's inline `<style>` block exactly once per response. The class does
 * not implement ResettableInterface and nothing anywhere clears that map, so
 * the "once" is "once per worker process".
 *
 * The observable consequence: a worker that serves request #1 renders the
 * component's CSS, and every request after that ships the page with no CSS at
 * all — while `AssetRegistry::beginRequest()` happily clears its own request
 * pool, making the omission invisible in the logs.
 *
 * The parallel asymmetry is the point. `$view->getStyles()` (external URL
 * list) is request-scoped and re-emitted on every render — ViewStylesTest proves
 * that. `$view->getStyle()` (inline `<style>` block) is memoised for the life
 * of the process. Same component, same page, two different lifetimes.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class ComponentRendererRequestStateTest extends TestCase
{
    private string $componentsDir;

    private AssetRegistry $registry;

    private ComponentRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->componentsDir = sys_get_temp_dir() . '/phue_renderer_state_' . bin2hex(random_bytes(6));
        mkdir($this->componentsDir, 0775, true);

        file_put_contents($this->componentsDir . '/ScopedCss.phue', $this->componentSource());

        $this->registry = new AssetRegistry();

        $this->renderer = new ComponentRenderer(
            new HtmlShellRenderer($this->registry),
            $this->registry,
            loader: TestComponentLoader::make($this->componentsDir)
        );
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->componentsDir . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        if (is_dir($this->componentsDir)) {
            @rmdir($this->componentsDir);
        }

        parent::tearDown();
    }

    public function testInlineStyleBlockIsEmittedAgainAfterTheScopeFlush(): void
    {
        // Production shape: the renderer is a container singleton, and the
        // request boundary is flushScope(), which is what Application::handle()
        // runs in its finally block.
        $container = new Container();
        $container->singleton(ComponentRenderer::class, fn(): ComponentRenderer => $this->renderer);

        $scope = $container->createScope();
        $container->setScope($scope);

        $first = $container->get(ComponentRenderer::class)->render('ScopedCss', [])->getContent();

        $this->assertStringContainsString(
            'data-phue-scope="ScopedCss"',
            $first,
            'Request #1 should carry the component inline <style> block.'
        );

        $container->flushScope($scope);

        $second = $container->get(ComponentRenderer::class)->render('ScopedCss', [])->getContent();

        $this->assertStringContainsString(
            'data-phue-scope="ScopedCss"',
            $second,
            'Request #2 lost the component inline <style> block. $renderedStyles is memoised on '
            . 'a singleton and is never cleared, so the CSS is emitted once per worker process '
            . 'instead of once per response.'
        );
    }

    public function testResetClearsTheRenderedStyleBookkeeping(): void
    {
        $this->renderer->render('ScopedCss', []);

        $this->renderer->reset();

        $this->assertStringContainsString(
            'data-phue-scope="ScopedCss"',
            $this->renderer->render('ScopedCss', [])->getContent(),
            'reset() must empty the dedup map so the next render re-emits the block.'
        );
    }

    public function testTheFallbackBucketFlushDoesNotResetTheRenderer(): void
    {
        // Application::handle() flushes the concrete scope and then the fallback
        // bucket. Singletons are reset by the first only; counting the second
        // would clear the map mid-response on the next request boundary.
        $container = new Container();
        $container->singleton(ComponentRenderer::class, fn(): ComponentRenderer => $this->renderer);

        $scope = $container->createScope();
        $container->setScope($scope);
        $container->get(ComponentRenderer::class)->render('ScopedCss', []);

        $container->flushScope($scope);
        $container->flushScope(null);

        $this->assertSame(
            1,
            substr_count(
                $container->get(ComponentRenderer::class)->render('ScopedCss', [])->getContent(),
                'data-phue-scope="ScopedCss"'
            ),
            'Deduplication within one response must survive a double flush.'
        );
    }

    public function testInlineStyleBlockIsStillDeduplicatedWithinASingleResponse(): void
    {
        // Deduplication is the feature's actual job: rendering the same component
        // twice in one page must not ship the same <style> twice.
        $html = $this->renderer->render('ScopedCss', [])->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'data-phue-scope="ScopedCss"'),
            'The inline <style> block must appear exactly once per response.'
        );
    }

    private function componentSource(): string
    {
        return <<<'PHUE'
        <php>
        return [
            'title' => 'Scoped Css Page',
            'state' => ['ready' => true],
        ];
        </php>
        <template>
        <div>Scoped css content</div>
        </template>
        <style>
        .scoped-css { color: rebeccapurple; }
        </style>
        PHUE;
    }
}