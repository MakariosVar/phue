<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\Ssr\UnixSsrClient;
use PHPUnit\Framework\Attributes\Group;
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
 * Self-skip: these tests require the SSR sidecar to render PhNavigation
 * server-side (PhNavigation is SSR-only after the module split). When the
 * sidecar is unreachable, the partial falls back to empty HTML.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('unit')]
final class NavPartialTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    private PhueComponentLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = new PhueComponentLoader(self::PHUI_DIR);

        if (!file_exists('/tmp/phue-ssr.sock')) {
            self::markTestSkipped(
                'NavPartialTest requires the SSR sidecar to render PhNavigation server-side '
                . '(PhNavigation is SSR-only after the module split). Skipped because the Bun SSR socket '
                . '(/tmp/phue-ssr.sock) is not active.'
            );
        }
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

        $source = $this->loader->buildForSsr('PhNavigation');
        $this->assertNotNull($source, 'PhNavigation must compile for SSR.');

        $ssrEngine = new \Phue\Core\Ssr\BunSsrEngine(new \Phue\Core\Ssr\UnixSsrClient('/tmp/phue-ssr.sock'));
        $html = $ssrEngine->render(
            '<ph-navigation></ph-navigation>',
            $params,
            ['components' => ['PhNavigation' => $source]]
        );

        $this->assertNotNull($html, 'SSR render of PhNavigation must return HTML.');

        return (string) $html;
    }

    public function testRendersNavWrapperWithAllItems(): void
    {
        $html = $this->render($this->items(), '/');

        $this->assertStringContainsString('<ul class="navbar-nav me-auto mb-2 mb-lg-0">', $html);
        foreach (['Home', 'Docs', 'Products'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function testDefaultOrientationIsHorizontalWithoutFlexColumn(): void
    {
        $html = $this->render($this->items(), '/docs');

        $this->assertStringContainsString('<ul class="navbar-nav me-auto mb-2 mb-lg-0">', $html);
        $this->assertStringNotContainsString('flex-column', $html);
    }

    public function testSidebarOrientationEmitsFlexColumn(): void
    {
        $html = $this->render($this->items(), '/docs', 'sidebar');

        $this->assertStringContainsString('<nav class="nav nav-pills flex-column gap-1">', $html);
    }

    public function testVerticalOrientationIsAcceptedAliasForSidebar(): void
    {
        $html = $this->render($this->items(), '/docs', 'vertical');

        $this->assertStringContainsString('<nav class="nav nav-pills flex-column gap-1">', $html);
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
        $this->assertStringContainsString(
            '<h6 class="phue-nav-group sidebar-heading px-3 mt-3 mb-1 text-muted text-uppercase small">Learn</h6>',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<\/h6><a href="\/docs" class="nav-link"/',
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
            '/>Learn<\/h6>.*>Shop<\/h6>/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<\/h6><a href="\/products" class="nav-link"/',
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

    public function testHorizontalRendersGroupAsDropdown(): void
    {
        $items = [
            ['label' => 'Docs', 'href' => '/docs', 'group' => 'Learn'],
        ];
        $html = $this->render($items, '/docs', 'horizontal');

        $this->assertStringNotContainsString('phue-nav-group', $html);
        $this->assertStringContainsString(
            '<li class="nav-item dropdown">',
            $html
        );
        $this->assertStringContainsString(
            '<a href="#" class="nav-link dropdown-toggle active" role="button" data-bs-toggle="dropdown" aria-expanded="false">Learn</a>',
            $html
        );
        $this->assertStringContainsString(
            '<li><a href="/docs" class="dropdown-item active" aria-current="page">Docs</a></li>',
            $html
        );
    }

    public function testHorizontalMergesConsecutiveItemsOfSameGroupIntoSingleDropdown(): void
    {
        $items = [
            ['label' => 'Docs', 'href' => '/docs', 'group' => 'Learn'],
            ['label' => 'Tutorials', 'href' => '/tutorials', 'group' => 'Learn'],
            ['label' => 'Home', 'href' => '/'],
        ];
        $html = $this->render($items, '/', 'horizontal');

        $this->assertSame(1, substr_count($html, 'nav-item dropdown'));
        $this->assertStringContainsString(
            '<li><a href="/docs" class="dropdown-item">Docs</a></li>',
            $html
        );
        $this->assertStringContainsString(
            '<li><a href="/tutorials" class="dropdown-item">Tutorials</a></li>',
            $html
        );
        $this->assertStringContainsString(
            '<li class="nav-item"><a href="/" class="nav-link active" aria-current="page">Home</a></li>',
            $html
        );
    }

    public function testHorizontalSplitsNonConsecutiveGroupsIntoSeparateDropdowns(): void
    {
        $items = [
            ['label' => 'Docs', 'href' => '/docs', 'group' => 'Learn'],
            ['label' => 'Home', 'href' => '/'],
            ['label' => 'Tutorials', 'href' => '/tutorials', 'group' => 'Learn'],
        ];
        $html = $this->render($items, '/', 'horizontal');

        $this->assertSame(2, substr_count($html, 'nav-item dropdown'));
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

        $this->assertSame(
            '<div class="d-flex align-items-center w-100"><ul class="navbar-nav me-auto mb-2 mb-lg-0"><!--[--><!--]--></ul>'
                . '<div class="ms-auto d-flex align-items-center gap-2"><!--[--><!----><!--[--><!--]--><!--]--></div></div>',
            $html
        );
    }

    public function testMissingOrEmptyItemsSidebarProducesEmptyNav(): void
    {
        $html = $this->render([], '/', 'sidebar');

        $this->assertSame(
            '<div class="d-flex align-items-center w-100"><nav class="nav nav-pills flex-column gap-1"><!--[--><!--]--></nav><!----></div>',
            $html
        );
    }

    public function testDisabledDropdownItemRendersAsSpan(): void
    {
        $items = [
            ['label' => 'Docs', 'href' => '/docs', 'group' => 'Learn'],
            ['label' => 'Locked', 'href' => '/locked', 'group' => 'Learn', 'disabled' => true],
        ];
        $html = $this->render($items, '/', 'horizontal');

        $this->assertStringContainsString(
            '<li><span class="dropdown-item disabled" aria-disabled="true">Locked</span></li>',
            $html
        );
        $this->assertStringNotContainsString('<a href="/locked"', $html);
    }

    public function testDropdownToggleIsActiveWhenAnyChildIsActive(): void
    {
        $items = [
            ['label' => 'Docs', 'href' => '/docs', 'group' => 'Learn'],
            ['label' => 'Tutorials', 'href' => '/tutorials', 'group' => 'Learn'],
        ];
        $html = $this->render($items, '/tutorials', 'horizontal');

        $this->assertStringContainsString(
            '<a href="#" class="nav-link dropdown-toggle active" role="button" data-bs-toggle="dropdown" aria-expanded="false">Learn</a>',
            $html
        );
        $this->assertStringContainsString(
            '<li><a href="/tutorials" class="dropdown-item active" aria-current="page">Tutorials</a></li>',
            $html
        );
    }

    public function testDropdownToggleIsInactiveWhenNoChildIsActive(): void
    {
        $items = [
            ['label' => 'Docs', 'href' => '/docs', 'group' => 'Learn'],
        ];
        $html = $this->render($items, '/', 'horizontal');

        $this->assertStringContainsString(
            '<a href="#" class="nav-link dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-expanded="false">Learn</a>',
            $html
        );
    }

    public function testHorizontalSingleItemOutsideGroupStaysPlainLink(): void
    {
        $items = [
            ['label' => 'Home', 'href' => '/'],
            ['label' => 'Docs', 'href' => '/docs', 'group' => 'Learn'],
        ];
        $html = $this->render($items, '/', 'horizontal');

        $this->assertStringContainsString(
            '<li class="nav-item"><a href="/" class="nav-link active" aria-current="page">Home</a></li>',
            $html
        );
        $this->assertStringContainsString(
            '<li class="nav-item dropdown">',
            $html
        );
    }
}
