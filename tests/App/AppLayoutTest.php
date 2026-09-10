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
    private PhuePartialRenderer $partialRenderer;

    protected function setUp(): void
    {
        parent::setUp();

        $root = realpath(__DIR__ . '/../..') ?: '..';

        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(new PhueComponentLoader($root . '/src/Core/UI/Phui'));
        $this->partialRenderer = new PhuePartialRenderer($registry);
        $this->loader = new PhueComponentLoader($root . '/App/Frontend');
    }

    private function renderLayout(array $params): string
    {
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
        $this->assertStringContainsString('<nav class="nav nav-pills">', $html);
        $this->assertStringNotContainsString('flex-column', $html);
        $this->assertStringContainsString('<p>page content</p>', $html);
    }

    public function testSidebarPlacementRendersSidebarLayout(): void
    {
        $html = $this->renderLayout(['placement' => 'sidebar']);

        $this->assertStringContainsString('<div class="app-layout d-flex">', $html);
        $this->assertStringContainsString('<nav class="nav nav-pills flex-column">', $html);
        $this->assertStringContainsString(
            '<main v-cloak class="app-content flex-grow-1 p-3">',
            $html
        );
        $this->assertStringContainsString('<p>page content</p>', $html);
    }

    public function testActiveRouteMarksCorrectItemThroughLayout(): void
    {
        $html = $this->renderLayout(['uri' => '/docs']);

        $this->assertStringContainsString(
            '<a href="/docs" class="nav-link active" aria-current="page">Docs</a>',
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

        $this->assertStringContainsString('<nav class="nav nav-pills">', $html);
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('Docs', $html);
    }

    public function testTopbarRendersBootstrapNavbarWithBrandAndActions(): void
    {
        $html = $this->renderLayout([]);

        $this->assertStringContainsString('<header class="app-topbar navbar', $html);
        $this->assertMatchesRegularExpression(
            '/<a class="navbar-brand[^"]*" href="\/">Phue App<\/a>/',
            $html
        );
        $this->assertStringContainsString('<div class="ms-auto', $html);
        $this->assertStringContainsString('Login', $html);
        $this->assertStringNotContainsString('Profile', $html);
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

    public function testTopbarActionsRespectIdentityVisibility(): void
    {
        $html = $this->renderLayout([
            'uri'  => '/',
            'user' => $this->identity('admin'),
        ]);

        $this->assertStringContainsString('Profile', $html);
        $this->assertStringNotContainsString('Login', $html);
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
        $this->assertStringContainsString('<nav class="nav nav-pills flex-column">', $html);
        $this->assertStringContainsString('<h6 class="phue-nav-group"', $html);
        $this->assertStringContainsString('>Learn<', $html);
        $this->assertStringContainsString('>Shop<', $html);
        $this->assertMatchesRegularExpression('/<a class="navbar-brand[^"]*"/', $html);
        $this->assertStringContainsString(
            '<main v-cloak class="app-content flex-grow-1 p-3">',
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