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
 *  - orientation contract: horizontal by default, 'sidebar'|'vertical' emit flex-column,
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

    private function render(array $items, string $activeRoute, ?string $orientation = null): string
    {
        $params = [
            'items'       => $items,
            'activeRoute' => $activeRoute,
        ];
        if ($orientation !== null) {
            $params['orientation'] = $orientation;
        }

        return $this->loader->load('PhNavigation', $params)->view->getHtml();
    }

    public function testRendersNavWrapperWithAllItems(): void
    {
        $html = $this->render($this->items(), '/');

        $this->assertStringContainsString('<nav class="nav nav-pills">', $html);
        foreach (['Home', 'Docs', 'Products'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function testDefaultOrientationIsHorizontalWithoutFlexColumn(): void
    {
        $html = $this->render($this->items(), '/docs');

        $this->assertStringContainsString('<nav class="nav nav-pills">', $html);
        $this->assertStringNotContainsString('flex-column', $html);
    }

    public function testSidebarOrientationEmitsFlexColumn(): void
    {
        $html = $this->render($this->items(), '/docs', 'sidebar');

        $this->assertStringContainsString('<nav class="nav nav-pills flex-column">', $html);
    }

    public function testVerticalOrientationIsAcceptedAliasForSidebar(): void
    {
        $html = $this->render($this->items(), '/docs', 'vertical');

        $this->assertStringContainsString('<nav class="nav nav-pills flex-column">', $html);
    }

    public function testSidebarRendersGroupHeadingOnceBeforeGroupedItems(): void
    {
        $items = [
            ['label' => 'Docs', 'href' => '/docs', 'group' => 'Learn'],
            ['label' => 'Tutorials', 'href' => '/tutorials', 'group' => 'Learn'],
            ['label' => 'Home', 'href' => '/'],
        ];
        $html = $this->render($items, '/products', 'sidebar');

        $this->assertSame(1, substr_count($html, '>Learn<'));
        $this->assertMatchesRegularExpression(
            '/<h6 class="phue-nav-group"[^>]*>Learn<\/h6>\s*<a href="\/docs"/',
            $html
        );
        $this->assertStringContainsString('<a href="/" class="nav-link">Home</a>', $html);
    }

    public function testSidebarGroupHeadingSeparatesDistinctGroups(): void
    {
        $items = [
            ['label' => 'Docs', 'href' => '/docs', 'group' => 'Learn'],
            ['label' => 'Products', 'href' => '/products', 'group' => 'Shop'],
        ];
        $html = $this->render($items, '/', 'sidebar');

        $this->assertMatchesRegularExpression(
            '/<h6 class="phue-nav-group"[^>]*>Learn<\/h6>\s*<a href="\/docs".*<h6 class="phue-nav-group"[^>]*>Shop<\/h6>\s*<a href="\/products"/s',
            $html
        );
    }

    public function testSidebarGroupHeadingLabelIsEscaped(): void
    {
        $items = [
            ['label' => 'X', 'href' => '/x', 'group' => '<script>alert(1)</script>'],
        ];
        $html = $this->render($items, '/', 'sidebar');

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testHorizontalOrientationIgnoresGroupHeadings(): void
    {
        $items = [
            ['label' => 'Docs', 'href' => '/docs', 'group' => 'Learn'],
        ];
        $html = $this->render($items, '/docs', 'horizontal');

        $this->assertStringNotContainsString('phue-nav-group', $html);
        $this->assertStringNotContainsString('>Learn<', $html);
        $this->assertStringContainsString('<a href="/docs" class="nav-link active"', $html);
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

        $this->assertSame('<nav class="nav nav-pills"></nav>', $html);
    }
}
