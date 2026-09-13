<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhuePartialRenderer;
use Phue\Auth\IdentityInterface;
use PHPUnit\Framework\TestCase;

/**
 * Integration contract for the real App/Frontend/AppLayout.phue layout.
 *
 * Locks:
 *  - topbar (default) emits the plain .app-layout wrapper and horizontal nav,
 *  - sidebar placement emits the d-flex wrapper, flex-column nav and padded content,
 *  - the active route is forwarded to the PhNavigation partial through the real
 *    layout pipeline (regression for the uri vs activeRoute wiring bug),
 *  - a provided identity flows into the visibility filtering without errors.
 *
 * @package Phue\Tests\App
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class AppLayoutTest extends TestCase
{
    private PhueComponentLoader $loader;
    private PhueComponentLoader $phuiLoader;
    private ComponentLoaderRegistry $registry;
    private PhuePartialRenderer $partialRenderer;

    protected function setUp(): void
    {
        parent::setUp();

        $root = realpath(__DIR__ . '/../..') ?: '..';

        $this->registry = new ComponentLoaderRegistry();
        $this->phuiLoader = new PhueComponentLoader($root . '/src/Core/UI/Phui');
        $this->registry->addLoader($this->phuiLoader);
        $this->partialRenderer = new PhuePartialRenderer($this->registry);
        $this->loader = new PhueComponentLoader($root . '/App/Frontend');
    }

    private function renderLayout(array $params): string
    {
        $uri = $params['uri'] ?? '/';
        $user = $params['user'] ?? null;
        $placement = $params['placement'] ?? 'topbar';
        $orientation = ($placement === 'sidebar') ? 'sidebar' : 'horizontal';

        if (!array_key_exists('navHtml', $params)) {
            $items = $params['items'] ?? (\App\Config\NavigationConfig::getAppItems($user));

            $ssrClient = new \Phue\Core\Ssr\UnixSsrClient('/tmp/phue-ssr.sock');
            $ssrEngine = new \Phue\Core\Ssr\BunSsrEngine($ssrClient);

            $navComponent = $this->phuiLoader->load('PhNavigation', [
                'items'       => $items,
                'activeRoute' => $uri,
                'orientation' => $orientation,
            ]);

            $context = ['name' => 'PhNavigation', 'uri' => $uri];
            $discoverer = new \Phue\Core\UI\ComponentDiscoverer($this->registry);
            $deps = $discoverer->resolveTransitiveDependencies('PhNavigation');
            $components = [];

            $navSource = $this->registry->buildForSsr('PhNavigation');
            if ($navSource !== null) {
                $components['PhNavigation'] = trim($navSource);
            }
            foreach ($deps as $depName => $depHash) {
                $source = $this->registry->buildForSsr($depName);
                if ($source !== null && $depName !== 'PhNavigation') {
                    $components[$depName] = trim($source);
                }
            }
            if (!empty($components)) {
                $context['components'] = $components;
            }

            $navState = [
                'items'       => $items,
                'activeRoute' => $uri,
                'orientation' => $orientation,
            ];

            $params['navHtml'] = $ssrEngine->render(
                $navComponent->view->getTemplate(),
                $navState,
                $context
            ) ?? '';
        }

        $params += [
            'content'         => '<p>page content</p>',
            'uri'             => '/',
            'user'            => null,
            'partialRenderer' => $this->partialRenderer,
        ];

        return $this->loader->load('AppLayout', $params)->view->getHtml();
    }

    public function testTopbarDefaultWrapsContentInPlainLayout(): void
    {
        $html = $this->renderLayout([]);

        $this->assertStringContainsString('<div class="app-layout">', $html);
        $this->assertStringContainsString('<ul class="navbar-nav me-auto mb-2 mb-lg-0">', $html);
        $this->assertStringNotContainsString('flex-column', $html);
        $this->assertStringContainsString('<p>page content</p>', $html);
    }

    public function testSidebarPlacementRendersSidebarLayout(): void
    {
        $html = $this->renderLayout(['placement' => 'sidebar']);

        $this->assertStringContainsString('<div class="app-layout d-flex align-items-start">', $html);
        $this->assertStringContainsString('<nav class="nav nav-pills flex-column gap-1">', $html);
        $this->assertStringContainsString(
            '<main class="app-content flex-grow-1 p-3">',
            $html
        );
        $this->assertStringContainsString('<p>page content</p>', $html);
    }

    public function testActiveRouteMarksCorrectItemThroughLayout(): void
    {
        $html = $this->renderLayout(['uri' => '/docs']);

        $this->assertStringContainsString(
            '<li><a href="/docs" class="dropdown-item active" aria-current="page">Docs</a></li>',
            $html
        );
        $this->assertStringContainsString(
            '<a href="#" class="nav-link dropdown-toggle active" role="button" data-bs-toggle="dropdown" aria-expanded="false">Explore</a>',
            $html
        );
        $this->assertMatchesRegularExpression('/<a href="\/" class="nav-link">Home<\/a>/', $html);
    }

    public function testUserIsForwardedIntoVisibilityFiltering(): void
    {
        $html = $this->renderLayout([
            'uri'  => '/',
            'user' => $this->identity('admin'),
        ]);

        $this->assertStringContainsString('<ul class="navbar-nav me-auto mb-2 mb-lg-0">', $html);
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('Docs', $html);
    }

    public function testTopbarStickyIsAppliedByDefault(): void
    {
        $html = $this->renderLayout([]);

        $this->assertStringContainsString(
            '<header class="app-topbar navbar navbar-expand-lg bg-body-tertiary border-bottom px-3 py-2" style="position: sticky; top: 0; z-index: 1020;">',
            $html
        );
    }

    public function testTopbarStickyCanBeDisabled(): void
    {
        $html = $this->renderLayout(['sticky' => false]);

        $this->assertMatchesRegularExpression(
            '/<header class="app-topbar navbar navbar-expand-lg bg-body-tertiary border-bottom px-3 py-2">/',
            $html
        );
        $this->assertStringNotContainsString('position: sticky', $html);
    }

    public function testSidebarStickyIsAppliedWithAlignSelf(): void
    {
        $html = $this->renderLayout(['placement' => 'sidebar']);

        $this->assertStringContainsString(
            '<aside class="app-sidebar align-self-start p-3 border-end flex-shrink-0" style="position: sticky; top: 0; z-index: 1020;">',
            $html
        );
    }

    public function testTopbarRendersBootstrapNavbarWithBrandAndThemeAction(): void
    {
        $html = $this->renderLayout([]);

        $this->assertStringContainsString('<header class="app-topbar navbar', $html);
        $this->assertMatchesRegularExpression(
            '/<a class="navbar-brand[^"]*" href="\/">Phue App<\/a>/',
            $html
        );
        $this->assertStringContainsString('<div class="ms-auto', $html);
        $this->assertStringContainsString('aria-label="Toggle theme"', $html);
    }

    public function testTopbarBrandValuesAreEscaped(): void
    {
        $html = $this->renderLayout([
            'brand' => ['label' => '<script>alert(1)</script>', 'href' => '/x" onclick="alert(1)'],
        ]);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&quot;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testSidebarRendersAsideWithBrandAndGroupHeadings(): void
    {
        $html = $this->renderLayout([
            'placement' => 'sidebar',
            'items'     => [
                ['label' => 'Docs', 'href' => '/docs', 'group' => 'Learn'],
                ['label' => 'Products', 'href' => '/products', 'group' => 'Shop'],
            ],
        ]);

        $this->assertStringContainsString('<aside class="app-sidebar', $html);
        $this->assertStringContainsString('<nav class="nav nav-pills flex-column gap-1">', $html);
        $this->assertStringContainsString('<h6 class="phue-nav-group', $html);
        $this->assertStringContainsString('>Learn<', $html);
        $this->assertStringContainsString('>Shop<', $html);
        $this->assertMatchesRegularExpression('/<a class="navbar-brand[^"]*"/', $html);
        $this->assertStringContainsString(
            '<main class="app-content flex-grow-1 p-3">',
            $html
        );
    }

    public function testSidebarBrandIsEscaped(): void
    {
        $html = $this->renderLayout([
            'placement' => 'sidebar',
            'brand'     => ['label' => '<script>x</script>', 'href' => '/x'],
        ]);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
    }

    public function testItemsWithVisibleClosuresDoNotBreakLayoutCache(): void
    {
        $html = $this->renderLayout([
            'uri'   => '/',
            'user'  => $this->identity('admin'),
            'items' => [
                ['label' => 'Home', 'href' => '/'],
                ['label' => 'Admin', 'href' => '/admin', 'visible' => fn(?IdentityInterface $u) => $u !== null],
            ],
        ]);

        $this->assertMatchesRegularExpression('/<a href="\/"[^>]*>Home<\/a>/', $html);
        $this->assertMatchesRegularExpression('/<a href="\/admin"[^>]*>Admin<\/a>/', $html);
    }

    private function identity(string $authKey): IdentityInterface
    {
        return new class($authKey) implements IdentityInterface {
            public function __construct(private readonly string $authKey) {}

            public function getId(): int|string
            {
                return 1;
            }

            public static function findIdentity(int|string $id): ?self
            {
                return null;
            }

            public function getAuthKey(): ?string
            {
                return $this->authKey;
            }
        };
    }
}