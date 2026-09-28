<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Request;
use Phue\Tests\Support\RestoresActiveRecordConnection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SpaModulePreloadContractTest — locks the contract between the two halves of
 * SPA navigation.
 *
 * A client-side navigation fetches the target page's HTML as JSON and then
 * dynamically imports the page module. The browser cannot preload that graph
 * on its own: the module URLs are not known until the response arrives, and an
 * ESM import discovers its children one at a time — the same serial chain that
 * put the /phui critical path at 1,933 ms before modulepreload existed.
 *
 * The server already computes the full graph (it walks it to render the page).
 * So the response carries the preloads, and the explorer turns them into
 * <link rel="modulepreload"> before it imports anything.
 *
 * That contract spans a PHP response body and a JavaScript property read. The
 * two sides can drift apart in silence: a mismatched key makes
 * `(payload.x || [])` iterate nothing, the navigation stays slow, and no error
 * is raised anywhere. These tests assert the join, so a rename breaks the suite
 * instead of the performance budget.
 *
 * @package Phue\Tests\App
 */
#[Group('unit')]
final class SpaModulePreloadContractTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private const EXPLORER = __DIR__ . '/../../storage/assets/js/phue-explorer.js';

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

    /**
     * The payload key the explorer reads its preload list from.
     *
     * Deliberately derived from the explorer source rather than hard-coded: the
     * contract is that BOTH sides agree, not that they agree on one particular
     * spelling. Hard-coding the name would only make the suite argue about
     * naming while the actual risk — a rename on one side only — stays open.
     *
     * @return string|null
     */
    private function explorerPreloadKey(): ?string
    {
        $source = (string) file_get_contents(self::EXPLORER);

        foreach (explode("\n", $source) as $line) {
            if (!str_contains($line, 'forEach') || !str_contains($line, 'modulePreload')) {
                continue;
            }

            if (preg_match('/payload\.([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function explorerPayloadKeys(): array
    {
        $source = (string) file_get_contents(self::EXPLORER);
        preg_match_all('/payload\.([A-Za-z_][A-Za-z0-9_]*)/', $source, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * @return array<string, mixed>
     */
    private function spaPayload(string $uri): array
    {
        $response = $this->app->handle(new Request('GET', $uri, headers: ['X-Phue-SPA' => 'true']));

        $this->assertSame(200, $response->getStatusCode());

        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded, 'the SPA response must be a JSON object');

        return $decoded;
    }

    public function testSpaPayloadCarriesModulePreloads(): void
    {
        $key = $this->explorerPreloadKey();
        $this->assertIsString($key, 'the explorer must read a preload list off the SPA payload');

        $payload = $this->spaPayload('/');

        $this->assertArrayHasKey(
            $key,
            $payload,
            "the SPA payload must advertise the target page module graph as '{$key}', otherwise "
            . 'the browser discovers it serially on every navigation'
        );

        $this->assertNotEmpty(
            $payload[$key],
            "the '{$key}' list must not be empty for a page that ships a module"
        );
    }

    /**
     * The join that fails silently.
     *
     * Reads the property name the explorer actually reads off the payload and
     * requires the server to send that exact key. A rename on either side — a
     * missing `s`, a plural flip — makes the explorer's `|| []` guard swallow
     * the mismatch with no error anywhere, and the navigation silently stays
     * slow. This is the assertion that has to exist for that failure mode.
     */
    public function testExplorerReadsAKeyTheServerActuallySends(): void
    {
        $source = (string) file_get_contents(self::EXPLORER);
        $this->assertNotSame('', $source, 'phue-explorer.js must be readable');

        $readKeys = $this->explorerPayloadKeys();
        $preloadKey = $this->explorerPreloadKey();

        $this->assertIsString(
            $preloadKey,
            'the explorer must read a preload list off the payload and turn it into modulepreload links'
        );

        foreach (['/', '/docs', '/phui'] as $uri) {
            $payload = $this->spaPayload($uri);

            foreach ($readKeys as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $payload,
                    "the explorer reads payload.{$key} but the /{$uri} SPA response does not send it — "
                    . 'the fallback guard hides the mismatch and navigation stays slow with no error'
                );
            }
        }
    }

    public function testEveryPreloadUrlIsActuallyServable(): void
    {
        $key = (string) $this->explorerPreloadKey();

        foreach (['/', '/docs', '/phui'] as $uri) {
            $payload = $this->spaPayload($uri);

            foreach ($payload[$key] as $url) {
                $this->assertIsString($url);
                $this->assertMatchesRegularExpression(
                    '#^/assets/phue/(?:[A-Za-z0-9_.\-]+/)*[A-Za-z0-9_.\-]+\.[a-f0-9]{8}\.phue\.js$#',
                    $url,
                    "preload url for {$uri} is not a content-hashed phue module: {$url}"
                );

                $this->assertSame(
                    200,
                    $this->app->handle(new Request('GET', $url))->getStatusCode(),
                    "preloaded url {$url} must be servable, otherwise the explorer downloads it twice"
                );
            }
        }
    }

    public function testPreloadsCoverThePageScripts(): void
    {
        foreach (['/', '/docs', '/phui'] as $uri) {
            $payload = $this->spaPayload($uri);

            $preloads = array_flip($payload[(string) $this->explorerPreloadKey()]);

            foreach ($payload['scripts'] as $script) {
                $this->assertArrayHasKey(
                    $script,
                    $preloads,
                    "the page script {$script} is imported by the explorer but is not in the "
                    . "preload list for {$uri}, so it is fetched cold while the rest of the graph "
                    . 'streams in'
                );
            }
        }
    }

    public function testSpaPayloadDoesNotDuplicatePreloads(): void
    {
        $payload = $this->spaPayload('/phui');
        $preloads = $payload[(string) $this->explorerPreloadKey()];

        $this->assertSame(
            array_values(array_unique($preloads)),
            $preloads,
            'a duplicated preload re-appends a <link> the browser already has; the explorer '
            . 'guards on existing links but the payload should still be clean'
        );
    }
}
