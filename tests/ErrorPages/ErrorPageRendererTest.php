<?php

namespace Phue\Tests\ErrorPages;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Phue\Core\ErrorPages\ErrorPageRenderer;

#[\PHPUnit\Framework\Attributes\Group('unit')]
class ErrorPageRendererTest extends TestCase
{
    private ErrorPageRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new ErrorPageRenderer();
    }

    public function testDebugModeRendersStackTrace(): void
    {
        $e = new RuntimeException('Test error');
        $html = $this->renderer->render($e, debug: true);

        $this->assertIsString($html);
        $this->assertStringContainsString('Test error', $html);
        $this->assertStringContainsString('class="trace"', $html);
        $this->assertStringContainsString('RuntimeException', $html);
    }

    public function testDebugModeShowsExceptionClass(): void
    {
        $e = new \InvalidArgumentException('Bad argument');
        $html = $this->renderer->render($e, debug: true);

        $this->assertStringContainsString('Bad argument', $html);
        $this->assertStringContainsString('InvalidArgumentException', $html);
    }

    public function testDebugModeShowsFileAndLine(): void
    {
        $e = new RuntimeException('Error at line');
        $html = $this->renderer->render($e, debug: true);

        $this->assertStringContainsString('File:', $html);
        $this->assertStringContainsString('line', $html);
        // File should be a real path
        $this->assertStringContainsString(__FILE__, $html);
    }

    public function testDebugModeIsStyled(): void
    {
        $e = new RuntimeException('Test');
        $html = $this->renderer->render($e, debug: true);

        $this->assertStringContainsString('style', $html);
        $this->assertStringContainsString('monospace', $html);
        $this->assertStringContainsString('#0f172a', $html);
    }

    public function testProductionModeRendersCleanPage(): void
    {
        $e = new RuntimeException('Secret error details');
        $html = $this->renderer->render($e, debug: false);

        $this->assertIsString($html);
        // Should NOT show exception details in production
        $this->assertStringNotContainsString('Secret error details', $html);
        $this->assertStringNotContainsString('RuntimeException', $html);
        $this->assertStringNotContainsString('Stack trace', $html);
        $this->assertStringNotContainsString(__FILE__, $html);
    }

    public function testProductionModeShowsGenericMessage(): void
    {
        $e = new RuntimeException('Whatever');
        $html = $this->renderer->render($e, debug: false);

        $this->assertStringContainsString('Something went wrong', $html);
        $this->assertStringContainsString('internal server', $html);
    }

    public function testProductionModeHasGoHomeButton(): void
    {
        $e = new RuntimeException('Error');
        $html = $this->renderer->render($e, debug: false);

        $this->assertStringContainsString('Go Home', $html);
        $this->assertStringContainsString('/" class="btn"', $html);
    }

    public function testProductionModeIsStyled(): void
    {
        $e = new RuntimeException('Error');
        $html = $this->renderer->render($e, debug: false);

        $this->assertStringContainsString('system-ui', $html);
        $this->assertStringContainsString('#f8fafc', $html);
        $this->assertStringContainsString('btn', $html);
    }

    public function testRenderedHtmlIsCompleteDocument(): void
    {
        $e = new RuntimeException('Test');

        $debugHtml = $this->renderer->render($e, debug: true);
        $prodHtml = $this->renderer->render($e, debug: false);

        foreach ([$debugHtml, $prodHtml] as $html) {
            $this->assertStringContainsString('<!DOCTYPE html>', $html);
            $this->assertStringContainsString('<html', $html);
            $this->assertStringContainsString('</html>', $html);
            $this->assertStringContainsString('<head>', $html);
            $this->assertStringContainsString('</head>', $html);
            $this->assertStringContainsString('<body>', $html);
            $this->assertStringContainsString('</body>', $html);
        }
    }

    public function testDifferentExceptionTypesRenderSuccessfully(): void
    {
        $exceptions = [
            new RuntimeException('Runtime error'),
            new \InvalidArgumentException('Invalid argument'),
            new \TypeError('Type error'),
            new \Error('Fatal error'),
        ];

        foreach ($exceptions as $e) {
            $debugHtml = $this->renderer->render($e, debug: true);
            $prodHtml = $this->renderer->render($e, debug: false);

            $this->assertIsString($debugHtml, "Debug render failed for " . get_class($e));
            $this->assertIsString($prodHtml, "Production render failed for " . get_class($e));
        }
    }
}