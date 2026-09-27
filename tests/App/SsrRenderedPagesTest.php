<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Config;
use Phue\Core\Request;
use Phue\Core\Ssr\UnixSsrClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * SsrRenderedPagesTest — the Phase 4 top promise: the server sends fully
 * interpolated markup inside the mount container and no raw `{{ }}` template
 * reaches the client.
 *
 * State-driven text assertions target the rendered DOM inside `<div id="app">`
 * specifically — glancing at the whole document is misleading, because the
 * same values also exist (JSON-encoded) inside `window.__INITIAL_STATE__`.
 *
 * Before the Contract 3.4 binding fix (pages shipping state['value'] while
 * the template binds {{testValue}}) the sidecar interpolates an empty text
 * node, so the value the controller advertises NEVER reaches the markup —
 * these tests stay red until binding names equal state keys.
 *
 * Requires a live sidecar (same contract as the real runtime); self-skips
 * when ssr/server.ts is not reachable on the abstract socket.
 *
 * @package Phue\Tests\App
 */
#[Group('ssr-rendered-pages')]
#[Group('integration')]
final class SsrRenderedPagesTest extends TestCase
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

        if (!$this->ssrClient()->isAvailable()) {
            self::markTestSkipped('Bun SSR sidecar is not reachable.');
        }
    }

    private function ssrClient(): UnixSsrClient
    {
        $config = $this->app->getContainer()->get(Config::class);

        return new UnixSsrClient(
            socket: (string) $config->get('ssr.socket', "\0phue-ssr"),
        );
    }

    public function testHomeRendersControllerStateIntoMountContainer(): void
    {
        $response = $this->app->handle(new Request('GET', '/'));

        $this->assertSame(200, $response->getStatusCode());

        $container = $this->mountContainer($response->getContent());
        $this->assertNotNull($container, 'Home page must render a mount container.');

        $this->assertStringContainsString('HomePage', $container);
        $this->assertStringNotContainsString('{{', $container);
    }

    public function testDocsRendersControllerStateIntoMountContainer(): void
    {
        $response = $this->app->handle(new Request('GET', '/docs'));

        $this->assertSame(200, $response->getStatusCode());

        $container = $this->mountContainer($response->getContent());
        $this->assertNotNull($container, 'Docs page must render a mount container.');

        // Clean contract: controller resolves ?doc= (default: getting-started);
        // the parsed guide HTML ships in state and renders via v-html.
        $this->assertStringContainsString('Getting Started', $container);
        $this->assertStringNotContainsString('{{', $container);
    }

    public function testDocsEmitsItsDeclaredStylesAsHeadLinks(): void
    {
        // Docs.phue declares 'styles' => ['/assets/app/styles/main.css'].
        // That metadata must flow: View->getStyles() → addRequestStyle() →
        // HtmlShellRenderer emits a <link> tag in <head>.
        $response = $this->app->handle(new Request('GET', '/docs'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            '<link rel="stylesheet" href="/assets/app/styles/main.css">',
            $response->getContent(),
            'Docs page must emit its declared stylesheet in the shell <head>'
        );
    }

    public function testDocsRendersPrevNextAsPlainAnchors(): void
    {
        $response = $this->app->handle(new Request('GET', '/docs', ['doc' => 'core']));

        $this->assertSame(200, $response->getStatusCode());

        $container = $this->mountContainer($response->getContent());
        $this->assertNotNull($container, 'Docs page must render a mount container.');

        $this->assertStringContainsString('href="/docs?doc=getting-started"', $container);
        $this->assertStringContainsString('href="/docs?doc=modules"', $container);
        $this->assertStringNotContainsString(
            '<router-link',
            $container,
            'Relative internal links must render as <a> — the shell has no Vue Router, so <router-link> never mounts.'
        );
    }

    public function testHomeSpaNavigationReturnsInterpolatedMarkup(): void
    {
        $response = $this->app->handle(
            new Request('GET', '/', [], [], ['X-Phue-SPA' => 'true'])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $payload = json_decode($response->getContent(), true);

        $this->assertIsArray($payload);
        $this->assertStringContainsString('HomePage', $payload['html']);
        $this->assertStringNotContainsString('{{', $payload['html']);
        $this->assertSame('HomePage', $payload['state']['testValue']);
    }

    public function testUiMountContainerLeaksNoRawTemplate(): void
    {
        $response = $this->app->handle(new Request('GET', '/phui'));

        $this->assertSame(200, $response->getStatusCode());

        $container = $this->mountContainer($response->getContent());
        $this->assertNotNull($container, 'UI page must render a mount container.');

        $this->assertStringNotContainsString('{{', $container);
    }

    /**
     * Extracts the deep content of `<div id="app">…</div>` (layout shell is
     * nested inside the mount container, so a plain non-greedy match is NOT
     * enough — tag depth is tracked).
     */
    private function mountContainer(string $html, string $id = 'app'): ?string
    {
        $needle = sprintf('<div id="%s">', $id);
        $start = strpos($html, $needle);

        if ($start === false) {
            return null;
        }

        $start += strlen($needle);

        $depth = 1;
        $cursor = $start;

        while ($depth > 0) {
            $nextOpen = strpos($html, '<div', $cursor);
            $nextClose = strpos($html, '</div>', $cursor);

            if ($nextClose === false) {
                return null;
            }

            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $cursor = $nextOpen + 4;
            } else {
                $depth--;
                $cursor = $nextClose + 6;
            }
        }

        return substr($html, $start, $cursor - $start - 6);
    }
}