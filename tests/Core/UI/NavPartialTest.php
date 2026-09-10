<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueComponentLoader;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the PhNavigation SSR partial (HTML-first).
 *
 * Locks:
 *  - generic presenter contract: items + activeRoute in, resolved HTML out,
 *  - boundary-aware active-route matching performed server-side in PHP,
 *  - root path exact-match special case,
 *  - disabled-item handling (span + aria-disabled, never a link),
 *  - strict output hygiene: no script section, no leftover mustaches,
 *    labels and hrefs escaped.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class NavPartialTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    private PhueComponentLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = new PhueComponentLoader(self::PHUI_DIR);
    }

    private function items(): array
    {
        return [
            ['label' => 'Home', 'href' => '/'],
            ['label' => 'Docs', 'href' => '/docs'],
            ['label' => 'Products', 'href' => '/products'],
        ];
    }

    private function render(array $items, string $activeRoute): string
    {
        return $this->loader->load('PhNavigation', [
            'items'       => $items,
            'activeRoute' => $activeRoute,
        ])->view->getHtml();
    }

    public function testRendersNavWrapperWithAllItems(): void
    {
        $html = $this->render($this->items(), '/');

        $this->assertStringContainsString('<nav class="nav nav-pills flex-column">', $html);
        foreach (['Home', 'Docs', 'Products'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function testRootUsesExactMatchOnly(): void
    {
        $html = $this->render($this->items(), '/');

        $this->assertMatchesRegularExpression(
            '/<a href="\/" class="nav-link active" aria-current="page">Home<\/a>/',
            $html,
        );
        $this->assertMatchesRegularExpression('/<a href="\/docs" class="nav-link">Docs<\/a>/', $html);
    }

    public function testChildPrefixMarksParentAsActive(): void
    {
        $html = $this->render($this->items(), '/products/123');

        $this->assertMatchesRegularExpression(
            '/<a href="\/products" class="nav-link active" aria-current="page">Products<\/a>/',
            $html,
        );
        $this->assertMatchesRegularExpression('/<a href="\/" class="nav-link">Home<\/a>/', $html);
    }

    public function testBoundaryDoesNotActivateSiblingPathNames(): void
    {
        $html = $this->render($this->items(), '/productivity');

        $this->assertStringNotContainsString('class="nav-link active"', $html);
    }

    public function testTrailingSlashIsNormalized(): void
    {
        $html = $this->render($this->items(), '/products/');

        $this->assertMatchesRegularExpression(
            '/<a href="\/products" class="nav-link active"/',
            $html,
        );
    }

    public function testDisabledItemRendersAsSpanWithAriaDisabled(): void
    {
        $items = [
            ['label' => 'Work', 'href' => '/work', 'disabled' => true],
        ];
        $html = $this->render($items, '/');

        $this->assertStringContainsString(
            '<span class="nav-link disabled" aria-disabled="true">Work</span>',
            $html,
        );
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testLabelIsEscapedAgainstMarkupInjection(): void
    {
        $items = [
            ['label' => '<script>alert(1)</script>', 'href' => '/x'],
        ];
        $html = $this->render($items, '/x');

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testHrefIsEscaped(): void
    {
        $items = [
            ['label' => 'XSS', 'href' => '/x" onclick="alert(1)'],
        ];
        $html = $this->render($items, '/x');

        $this->assertStringContainsString('&quot;', $html);
        $this->assertStringNotContainsString('onclick="', $html);
    }

    public function testOutputContainsNoScriptSection(): void
    {
        $html = $this->render($this->items(), '/');

        $this->assertStringNotContainsString('<script', $html);
    }

    public function testOutputContainsNoLeftoverMustaches(): void
    {
        $html = $this->render($this->items(), '/');

        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('}}', $html);
    }

    public function testMissingOrEmptyItemsRegisterProducesEmptyNav(): void
    {
        $html = $this->render([], '/');

        $this->assertSame('<nav class="nav nav-pills flex-column"></nav>', $html);
    }
}
