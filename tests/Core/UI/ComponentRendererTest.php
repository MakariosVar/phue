<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;


use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\View;
use Phue\Modules\Auth\IdentityInterface;
use PHPUnit\Framework\TestCase;

/**
 * A navigation provider the test owns, standing in for the one an application ships.
 *
 * The renderer resolves `App\Config\NavigationConfig` by convention (ComponentRenderer
 * ::NAVIGATION_CONVENTION) — an optional hook the framework deliberately does not
 * implement itself. That hook is what makes this file dangerous to write naively: a test
 * that constructs a renderer and expects a "Login" action in the payload is asserting
 * whatever application happens to sit next to this checkout, and in a framework repository
 * there is none. It fails as a missing label, and it would equally pass for an app whose
 * nav says something else entirely.
 *
 * So the provider is injected, and it records the identity it was handed. The assertions
 * that matter are then about the renderer: that it resolves the user once, passes that
 * user to the provider, and puts the provider's answer in the SPA payload.
 *
 * @package Phue\Tests\Core\UI
 */
final class RecordingNavigationConfig
{
    public static ?IdentityInterface $seenUser = null;

    public static int $calls = 0;

    /** @return list<array<string, mixed>> */
    public static function getAppItems(?IdentityInterface $user): array
    {
        self::$seenUser = $user;
        self::$calls++;

        return [];
    }

    /** @return list<array<string, mixed>> */
    public static function getAppActions(?IdentityInterface $user): array
    {
        self::$seenUser = $user;
        self::$calls++;

        return $user === null
            ? [['label' => 'Sign in']]
            : [['label' => 'Sign out']];
    }

    public static function getAppPlacement(): string
    {
        return 'horizontal';
    }
}

/**
 * Unit tests for ComponentRenderer: shell assembly, template inlining into the
 * mount container, initial state serialization and ESM script enqueuing.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class ComponentRendererTest extends TestCase
{
    private ComponentRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $sharedRegistry = new AssetRegistry();
        $this->renderer = new ComponentRenderer(
            new HtmlShellRenderer($sharedRegistry),
            $sharedRegistry
        );
    }

    public function testRendersShellWithInlinedTemplateTitleAndState(): void
    {
        $view = new View(
            '<div class="container"><h2>Hello</h2></div>',
            ['products' => [['id' => 1, 'name' => 'Widget']]],
            ['/assets/app.js']
        );

        $response = $this->renderer->render('Demo Page', $view);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeader('content-type'));

        $html = $response->getContent();
        $this->assertStringContainsString('<title>Demo Page</title>', $html);
        $this->assertStringContainsString(
            '<div id="app"><div class="container"><h2>Hello</h2></div></div>',
            $html
        );
        $this->assertStringContainsString(
            'window.__INITIAL_STATE__ = {"products":[{"id":1,"name":"Widget"}]}',
            $html
        );
        $this->assertStringContainsString(
            '<script type="module" src="/assets/app.js"></script>',
            $html
        );
    }

    public function testRenderingTwiceDoesNotDuplicateScripts(): void
    {
        $view = new View('<div>Hi</div>', [], ['/assets/app.js']);

        $htmlOne = $this->renderer->render('A', $view)->getContent();
        $htmlTwo = $this->renderer->render('B', $view)->getContent();

        $this->assertSame(1, substr_count($htmlOne, 'src="/assets/app.js"'));
        $this->assertSame(1, substr_count($htmlTwo, 'src="/assets/app.js"'));
        $this->assertSame(1, substr_count($htmlOne, '<div id="app">'));
        $this->assertSame(1, substr_count($htmlTwo, '<div id="app">'));
    }

    public function testStateScriptBreakoutIsEscaped(): void
    {
        $view = new View(
            '<div>Hi</div>',
            ['note' => '</script><script>alert(1)</script>']
        );

        $html = $this->renderer->render('XSS', $view)->getContent();

        $this->assertStringContainsString('\u003C', $html);
        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $html);
    }

    public function testTemplateMarkupPreservesAttributesAndDirectives(): void
    {
        $view = new View(
            '<input v-model="newProduct.name" placeholder="Name" required>',
            ['newProduct' => ['name' => '', 'price' => '']]
        );

        $html = $this->renderer->render('Form', $view)->getContent();

        $this->assertStringContainsString(
            '<input v-model="newProduct.name" placeholder="Name" required>',
            $html
        );
    }

    private function spaRenderer(?AssetRegistry $registry = null): ComponentRenderer
    {
        $registry ??= new AssetRegistry();
        $request = new Request('GET', '/products', headers: ['X-Phue-SPA' => 'true']);

        return new ComponentRenderer(
            new HtmlShellRenderer($registry),
            $registry,
            requestResolver: fn (): Request => $request
        );
    }

    public function testSpaRequestReturnsJsonScriptsForCurrentPageOnly(): void
    {
        $registry = new AssetRegistry();
        $registry->addScript('/assets/phue/Products.abcd1234.phue.js', isModule: true);
        $registry->addGlobalScript('/assets/js/phue-explorer.js', true);

        $renderer = $this->spaRenderer($registry);
        $view = new View('<div>SPA</div>', ['page' => 'products']);

        $response = $renderer->render('Products', $view);

        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $urls = array_column($payload['scripts'], 'url');
        $this->assertContains('/assets/phue/Products.abcd1234.phue.js', $payload['scripts']);
        $this->assertNotContains(
            '/assets/js/phue-explorer.js',
            $payload['scripts'],
            'Global scripts must never be re-mount targets of SPA navigation'
        );
    }

    public function testSpaReVisitAfterBeginRequestStillMountsPageModule(): void
    {
        $registry = new AssetRegistry();
        $registry->addScript('/assets/phue/Products.abcd1234.phue.js', isModule: true);
        $renderer = $this->spaRenderer($registry);
        $view = new View('<div>SPA</div>');

        // Request #1
        $registry->beginRequest();
        $registry->addScript('/assets/phue/Products.abcd1234.phue.js', isModule: true);
        $first = json_decode($renderer->render('Products', $view)->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains(
            '/assets/phue/Products.abcd1234.phue.js',
            $first['scripts'],
        );

        // Request #2: re-visit of the same page in a persistent Swoole process.
        // Regression: the single-pool+offset logic returned an empty scripts
        // array here, so the explorer never called mod.default() and the page
        // stayed stuck in v-cloak forever.
        $registry->beginRequest();
        $registry->addScript('/assets/phue/Products.abcd1234.phue.js', isModule: true);
        $second = json_decode($renderer->render('Products', $view)->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotSame([], $second['scripts'], 'Re-visited SPA page must ship its module scripts');
        $this->assertContains('/assets/phue/Products.abcd1234.phue.js', $second['scripts']);
    }

    public function testSpaPayloadCarriesNavStateReflectingAuth(): void
    {
        $labels = static fn (array $actions): array => array_column($actions, 'label');

        RecordingNavigationConfig::$seenUser = null;
        RecordingNavigationConfig::$calls = 0;

        // Guest: SPA payload must carry navState so the client can refresh #nav.
        $guestRequest = new Request('GET', '/login', headers: ['X-Phue-SPA' => 'true']);
        $guestRenderer = new ComponentRenderer(
            new HtmlShellRenderer(new AssetRegistry()),
            new AssetRegistry(),
            requestResolver: fn (): Request => $guestRequest,
            userResolver: fn (): ?IdentityInterface => null,
            navigationClass: RecordingNavigationConfig::class
        );

        $guestPayload = json_decode(
            $guestRenderer->render('Login', new View('<div>Login</div>'))->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertArrayHasKey('navState', $guestPayload, 'SPA payload must expose navState for #nav refresh');
        $this->assertContains('Sign in', $labels($guestPayload['navState']['actions']));
        $this->assertNull(RecordingNavigationConfig::$seenUser, 'the provider must be asked as a guest');

        // Authenticated: same render must flip the right-slot actions.
        $userRequest = new Request('GET', '/schii', headers: ['X-Phue-SPA' => 'true']);
        $userRenderer = new ComponentRenderer(
            new HtmlShellRenderer(new AssetRegistry()),
            new AssetRegistry(),
            requestResolver: fn (): Request => $userRequest,
            userResolver: fn (): ?IdentityInterface => $this->identity('admin'),
            navigationClass: RecordingNavigationConfig::class
        );

        $userPayload = json_decode(
            $userRenderer->render('Schii', new View('<div>Schii</div>'))->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertArrayHasKey('navState', $userPayload);
        $this->assertContains('Sign out', $labels($userPayload['navState']['actions']));
        $this->assertNotContains('Sign in', $labels($userPayload['navState']['actions']));

        // The wiring, which is the part the framework owns: the resolved identity reaches
        // the provider, so a provider can render a different nav per visitor.
        $this->assertNotNull(RecordingNavigationConfig::$seenUser);
        $this->assertSame('admin', RecordingNavigationConfig::$seenUser->getAuthKey());
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

            public static function findByUsername(string $username): ?IdentityInterface
            {
                return new self(123);
            }
        };
    }
}