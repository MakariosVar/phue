<?php

declare(strict_types=1);

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Hiit\ChangeTracker;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Modules\DevTool\LiveReloadRoute;
use Phue\Tests\Support\TestCompilerBridge;

/**
 * The handler is deliberately thin: JSON out, and nothing else.
 *
 * It holds no state on purpose. It is registered as a class-string so the route stays
 * cacheable, and the router builds a class-string handler with Container::make() on every
 * request — which calls the factory each time and skips the singleton cache. A stateful handler
 * therefore compared the filesystem against itself and answered "nothing changed", correctly
 * formatted, on every poll, forever. The state lives in a ChangeTracker singleton this handler
 * resolves.
 *
 * @package Phue\Tests\DevTools
 */
#[Group('unit')]
final class LiveReloadRouteTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/phue_livereload_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (!is_dir($this->root)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($this->root);
    }

    public function testItAnswersWithJsonAndFinishes(): void
    {
        $response = $this->poll($this->routeWithComponents());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->getHeader('Content-Type'));
        $this->assertSame(['changed' => []], $this->body($response));
    }

    public function testTheAnswerMustNotBeCacheable(): void
    {
        // Cached and this endpoint is broken: the browser stops seeing changes that did happen.
        $this->assertStringContainsString(
            'no-store',
            (string) $this->poll($this->routeWithComponents())->getHeader('Cache-Control')
        );
    }

    public function testWhatItReportsIsTheTrackersAnswer(): void
    {
        $tracker = new ChangeTracker([$this->loader()]);
        $handler = new LiveReloadRoute($tracker);

        $this->assertSame(['changed' => []], $this->body($this->poll($handler)));

        $this->write('Alpha', 'changed');

        $this->assertSame(
            ['changed' => ['Alpha']],
            $this->body($this->poll($handler)),
            'the handler must report the tracker, not a private copy of the answer'
        );
    }

    /**
     * The property that makes the split work: the answer advances because the tracker advanced,
     * not because a handler remembered it.
     */
    public function testTwoHandlersSharingATrackerDoNotEachKeepTheirOwnMemory(): void
    {
        $tracker = new ChangeTracker([$this->loader()]);
        $this->write('Alpha', 'changed');

        $first = new LiveReloadRoute($tracker);
        $second = new LiveReloadRoute($tracker);

        $this->assertSame(['Alpha'], $this->body($this->poll($first))['changed']);

        // A second handler, built from scratch, must not re-report it. If it did, the memory
        // would live in the handler — which the router rebuilds per request, so the endpoint
        // would either report everything forever or nothing at all.
        $this->assertSame(
            [],
            $this->body($this->poll($second))['changed'],
            'the state must live in the tracker, not in the handler'
        );
    }

    // ----------------------------------------------------------------------- helpers

    private function poll(LiveReloadRoute $handler): \Phue\Core\Response
    {
        return $handler();
    }

    /**
     * @return array<string, mixed>
     */
    private function body(\Phue\Core\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        $this->assertIsArray($decoded, 'the endpoint must answer with json, not html');

        return $decoded;
    }

    private function routeWithComponents(): LiveReloadRoute
    {
        return new LiveReloadRoute(new ChangeTracker([$this->loader()]));
    }

    private function loader(): PhueComponentLoader
    {
        return new PhueComponentLoader(
            componentsDir: $this->root,
            compilerBridge: new TestCompilerBridge(),
        );
    }

    private function write(string $name, string $variant = 'original'): void
    {
        file_put_contents(
            $this->root . '/' . $name . '.phue',
            "<template>\n  <div>{$name} {$variant}</div>\n</template>\n<script>export default {}</script>\n"
        );
    }
}