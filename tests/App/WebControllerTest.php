<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * End-to-end contract tests for WebController routes.
 *
 * WebController renders Phue .phue components via ComponentRenderer
 * at /, /docs, and /ui. Each page is served as a self-contained
 * HTML shell with window.__INITIAL_STATE__ and extracted ESM modules.
 *
 * @package Phue\Tests\App
 */
final class WebControllerTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application('/app');
        $this->app->getRouter()->scanControllers('/app/App/Controllers', 'App\\Controllers');
        $registry = $this->app->getContainer()->get(AssetRegistry::class);
        $registry->registerSource('/assets', '/app/App/Frontend');
        $registry->registerSource('/assets/vendor', '/app/storage/assets/vendor');
    }

    // ── Root route (WebController::index → Home.phue) ──

    public function testRootRouteReturnsHtmlShell(): void
    {
        $response = $this->app->handle(new Request('GET', '/'));
        $content = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('<div id="app">', $content);
        $this->assertStringContainsString('window.__INITIAL_STATE__', $content);
        $this->assertStringContainsString('<script type="module"', $content);
    }

    public function testRootRouteEmbedsHomeModuleScriptUrl(): void
    {
        $content = $this->app->handle(new Request('GET', '/'))->getContent();

        $this->assertMatchesRegularExpression(
            '/<script type="module" src="\/assets\/phue\/Pages\/Home\.[a-f0-9]{8}\.phue\.js"><\/script>/',
            $content
        );
        $this->assertStringNotContainsString('<script type="module">', $content);
        $this->assertStringNotContainsString('src="/assets/app.js"', $content);
    }

    public function testRootRouteEmbedsHomeState(): void
    {
        $content = $this->app->handle(new Request('GET', '/'))->getContent();

        $this->assertStringContainsString('window.__INITIAL_STATE__', $content);

        preg_match('/window\.__INITIAL_STATE__ = (\{.*?\});/s', $content, $state);
        $this->assertArrayHasKey(1, $state);

        $decoded = json_decode($state[1], true);
        $this->assertIsArray($decoded);
        $this->assertSame('HomePage', $decoded['testValue'] ?? null);
    }

    public function testRootRouteServesHomeModuleScript(): void
    {
        $page = $this->app->handle(new Request('GET', '/'))->getContent();
        preg_match('/src="(\/assets\/phue\/Pages\/Home\.[a-f0-9]{8}\.phue\.js)"/', $page, $module);
        $this->assertArrayHasKey(1, $module);

        $response = $this->app->handle(new Request('GET', $module[1]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('createSSRApp', $response->getContent());
        $this->assertStringContainsString(".mount('#app')", $response->getContent());
    }

    public function testRootRouteTitleContainsPhue(): void
    {
        $response = $this->app->handle(new Request('GET', '/'));
        $content = $response->getContent();

        preg_match('/<title>(.*?)<\/title>/', $content, $title);
        $this->assertNotNull($title[1] ?? null);
        $this->assertStringContainsString('Phue', $title[1]);
    }

    // ── Docs route (WebController::docs → Docs.phue) ──

    public function testDocsRouteReturnsHtml(): void
    {
        $response = $this->app->handle(new Request('GET', '/docs'));
        $content = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('<div id="app">', $content);
        $this->assertStringContainsString('window.__INITIAL_STATE__', $content);
    }

    public function testDocsRouteTitleContainsDocs(): void
    {
        $response = $this->app->handle(new Request('GET', '/docs'));
        $content = $response->getContent();

        preg_match('/<title>(.*?)<\/title>/', $content, $title);
        $this->assertNotNull($title[1] ?? null);
        $this->assertStringContainsString('Docs', $title[1]);
    }

    public function testDocsRouteEmbedsDocsState(): void
    {
        $content = $this->app->handle(new Request('GET', '/docs'))->getContent();

        preg_match('/window\.__INITIAL_STATE__ = (\{.*?\});/s', $content, $state);
        $this->assertArrayHasKey(1, $state);

        $decoded = json_decode($state[1], true);
        $this->assertIsArray($decoded);
        // Clean contract: the controller resolves ?doc= (default: getting-started)
        $this->assertSame('getting-started', $decoded['docKey'] ?? null);
        $this->assertNotEmpty($decoded['content'] ?? '', 'Parsed guide HTML must ship in state');
        $this->assertArrayHasKey('sidebarItems', $decoded);
    }

    public function testDocsRouteShipsGlobalSearchIndexWithHeadingAnchors(): void
    {
        $content = $this->app->handle(new Request('GET', '/docs', ['doc' => 'phui']))->getContent();
        $state = $this->extractState($content);

        $this->assertIsArray($state['searchIndex'] ?? null, 'Global docs search requires a searchIndex in state.');
        $this->assertNotEmpty($state['searchIndex'], 'The search index must not be empty.');

        $seenAnchor = false;

        foreach ($state['searchIndex'] as $entry) {
            $this->assertIsArray($entry);
            foreach (['doc', 'docKey', 'heading', 'href', 'text'] as $key) {
                $this->assertArrayHasKey($key, $entry, "Search entry is missing '{$key}'.");
            }

            $this->assertStringStartsWith('/docs?doc=', $entry['href']);

            if (str_contains($entry['href'], '#')) {
                $seenAnchor = true;
            }
        }

        $this->assertTrue($seenAnchor, 'Search entries must deep-link into heading anchors (…/docs?doc=x#slug).');
    }

    public function testEverySearchAnchorResolvesToAHeadingInParsedContent(): void
    {
        $keys = ['getting-started', 'core', 'modules', 'database', 'auth', 'schii', 'rest', 'events', 'server', 'cli', 'phui'];

        foreach ($keys as $key) {
            $state = $this->extractState(
                $this->app->handle(new Request('GET', '/docs', ['doc' => $key]))->getContent()
            );

            $content = (string) ($state['content'] ?? '');

            foreach ($state['searchIndex'] ?? [] as $entry) {
                if (($entry['docKey'] ?? null) !== $key) {
                    continue;
                }

                $href = (string) ($entry['href'] ?? '');
                if (!str_contains($href, '#')) {
                    continue;
                }

                $anchor = substr($href, strpos($href, '#') + 1);

                $this->assertStringContainsString(
                    'id="' . $anchor . '"',
                    $content,
                    "Search result for [{$key}] links to #{$anchor}, but the parsed content has no heading with that id "
                    . '(Docs.php slugify vs MarkdownParser::slugify drift) — clicking it would fail to focus the section.'
                );
            }
        }
    }

    public function testDocsRouteShipsPrevNextNavigationState(): void
    {
        $content = $this->app->handle(new Request('GET', '/docs', ['doc' => 'core']))->getContent();
        $state = $this->extractState($content);

        $this->assertSame('core', $state['docKey']);
        $this->assertSame('/docs?doc=getting-started', $state['prev']['href'] ?? null);
        $this->assertSame('/docs?doc=modules', $state['next']['href'] ?? null);
    }

    public function testDocsRouteFirstGuideHasNoPrevAndLastHasNoNext(): void
    {
        $first = $this->extractState(
            $this->app->handle(new Request('GET', '/docs', ['doc' => 'getting-started']))->getContent()
        );
        $last = $this->extractState(
            $this->app->handle(new Request('GET', '/docs', ['doc' => 'phui']))->getContent()
        );

        $this->assertNull($first['prev']);
        $this->assertNotNull($first['next']);
        $this->assertNotNull($last['prev']);
        $this->assertNull($last['next']);
    }

    public function testUnknownDocFallsBackToGettingStarted(): void
    {
        $state = $this->extractState(
            $this->app->handle(new Request('GET', '/docs', ['doc' => '../../etc/passwd']))->getContent()
        );

        $this->assertSame('getting-started', $state['docKey'], 'Only whitelisted guide keys may be rendered.');
    }

    /**
     * Extracts `window.__INITIAL_STATE__` with a string-aware brace scan so that
     * `};` sequences inside string values (code samples in docs) cannot truncate
     * the JSON the way a non-greedy regex would.
     *
     * @return array<string, mixed>
     */
    private function extractState(string $content): array
    {
        $marker = 'window.__INITIAL_STATE__ = ';
        $start = strpos($content, $marker);

        $this->assertNotFalse($start, 'Page must embed window.__INITIAL_STATE__.');

        $start += strlen($marker);
        $length = strlen($content);
        $depth = 0;
        $inString = false;
        $escaped = false;
        $end = $start;

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i + 1;
                    break;
                }
            }
        }

        $decoded = json_decode(substr($content, $start, $end - $start), true);

        $this->assertIsArray($decoded, 'window.__INITIAL_STATE__ must be valid JSON.');

        return $decoded;
    }

    public function testDocsModuleReappliesHashScrollAfterSpaCommit(): void
    {
        $content = $this->app->handle(new Request('GET', '/docs'))->getContent();
        preg_match('/src="(\/assets\/phue\/Pages\/Docs\.[a-f0-9]{8}\.phue\.js)"/', $content, $module);
        $this->assertArrayHasKey(1, $module);

        $script = $this->app->handle(new Request('GET', $module[1]))->getContent();

        // phue-explorer commits SPA navigations with history.pushState AFTER
        // mounting the page, so a single scroll pass at mount observes the
        // previous URL's hash. The module must re-run the scroll once the URL
        // is committed (and be robust to slow mounts).
        $this->assertStringContainsString('setTimeout(scrollToSection', $script);
        $this->assertStringContainsString('scrollToSection(attempt + 1)', $script);
        $this->assertStringContainsString('scrollPaddingTop', $script);
        $this->assertStringContainsString('pageshow', $script);
    }

    // ── Docs route (WebController::docs → Docs.phue) ──

    public function testDocsRouteMarksDocsNavLinkActive(): void
    {
        $content = $this->app->handle(new Request('GET', '/docs'))->getContent();

        $this->assertStringContainsString(
            '<a href="/docs" class="nav-link active" aria-current="page">Docs</a>',
            $content
        );
        $this->assertStringContainsString(
            '<a href="#" class="nav-link dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-expanded="false">Modules</a>',
            $content
        );
        $this->assertMatchesRegularExpression('/<a href="\/" class="nav-link">Home<\/a>/', $content);
    }

    public function testDocsRouteNavUsesHorizontalDefault(): void
    {
        $content = $this->app->handle(new Request('GET', '/docs'))->getContent();

        $this->assertStringContainsString('<ul class="navbar-nav me-auto mb-2 mb-lg-0">', $content);
        $this->assertStringContainsString('nav-item dropdown', $content);
        $this->assertStringNotContainsString('<nav class="nav nav-pills flex-column', $content);
    }

    public function testEveryFullPageEmbedsThemeBootScriptInHead(): void
    {
        foreach (['/', '/docs', '/phui'] as $route) {
            $content = $this->app->handle(new Request('GET', $route))->getContent();

            $this->assertMatchesRegularExpression(
                '/<head>.*?data-bs-theme.*?<\/head>/s',
                $content,
                "Route {$route} must embed the anti-FOUC theme boot script in <head>."
            );
            $this->assertStringContainsString("localStorage.getItem('phue-theme')", $content);
        }
    }

    public function testThemeBootScriptPrecedesStylesheetsInHead(): void
    {
        $content = $this->app->handle(new Request('GET', '/'))->getContent();

        $themePos  = strpos($content, 'data-bs-theme');
        $stylePos  = strpos($content, 'bootstrap.min.css');

        $this->assertNotFalse($themePos, 'Theme boot script is present.');
        $this->assertNotFalse($stylePos, 'Bootstrap stylesheet is present.');
        $this->assertLessThan($stylePos, $themePos, 'Theme boot must run before CSS boot to avoid FOUC.');
    }

    public function testSpaResponseDoesNotCarryThemeBootScript(): void
    {
        $response = $this->app->handle(
            new Request('GET', '/', [], [], ['X-Phue-SPA' => 'true'])
        );

        $payload = json_decode($response->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertStringNotContainsString('data-bs-theme', json_encode($payload));
    }

    // ── UI route (WebController::ui → UI.phue) ──

    public function testUiRouteReturnsHtml(): void
    {
        $response = $this->app->handle(new Request('GET', '/phui'));
        $content = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('<div id="app">', $content);
        $this->assertStringContainsString('window.__INITIAL_STATE__', $content);
    }

    public function testUiRouteTitleContainsUI(): void
    {
        $response = $this->app->handle(new Request('GET', '/phui'));
        $content = $response->getContent();

        preg_match('/<title>(.*?)<\/title>/', $content, $title);
        $this->assertNotNull($title[1] ?? null);
        $this->assertStringContainsString('UI', $title[1]);
    }

    public function testUiRouteEmbedsInitialState(): void
    {
        $content = $this->app->handle(new Request('GET', '/phui'))->getContent();

        preg_match('/window\.__INITIAL_STATE__ = (\{.*?\});/s', $content, $state);
        $this->assertArrayHasKey(1, $state);

        $decoded = json_decode($state[1], true);
        $this->assertIsArray($decoded);

        // The state array is the page's ONLY server-side binding scope: ssr/server.ts
        // builds the page app as createSSRApp({ template, data }), so anything the
        // template binds during SSR has to be a key here.
        $this->assertArrayHasKey('activeSection', $decoded);
        $this->assertSame('index', $decoded['activeSection']);
        $this->assertArrayHasKey('shellHeight', $decoded);
        $this->assertNotSame('', $decoded['shellHeight']);

        $this->assertArrayHasKey('navItems', $decoded);
        $this->assertIsArray($decoded['navItems']);
        $this->assertNotEmpty($decoded['navItems'], 'the sidebar must be driven by shipped state');
    }

    public function testUiRouteTitleContainsPhui(): void
    {
        $response = $this->app->handle(new Request('GET', '/phui'));
        $content = $response->getContent();

        preg_match('/<title>(.*?)<\/title>/', $content, $title);
        $this->assertNotNull($title[1] ?? null);
        $this->assertStringContainsString('PhUI', $title[1]);
    }
}
