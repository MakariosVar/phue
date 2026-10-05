<?php

declare(strict_types=1);

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Hiit\ChangeTracker;
use Phue\Modules\DevTool\LiveReloadState;
use Phue\Modules\DevTool\LiveReloadWatcher;
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

    public function testWhatItReportsIsWhatTheWatcherRecorded(): void
    {
        $state = new LiveReloadState();
        $watcher = $this->watcher($state);
        $handler = new LiveReloadRoute($state);

        $this->assertSame(['changed' => []], $this->body($this->poll($handler)));

        $this->write('Alpha', 'changed');
        $watcher->tick();

        $this->assertSame(
            ['changed' => ['Alpha']],
            $this->body($this->poll($handler)),
            'the endpoint reports what the watcher found; it does not look for changes itself'
        );
    }

    /**
     * The split that made live reload possible: the endpoint cannot detect a change, because it
     * only runs when somebody asks it to, and with a realtime channel nobody is asking.
     */
    public function testTheEndpointCannotNoticeAChangeOnItsOwn(): void
    {
        $state = new LiveReloadState();
        $handler = new LiveReloadRoute($state);

        $this->write('Alpha', 'changed');

        $this->assertSame(
            ['changed' => []],
            $this->body($this->poll($handler)),
            'a change nobody announced is not visible to a poller, and that is the point'
        );
    }

    /**
     * The answer advances because the shared state advanced, not because a handler remembered it.
     *
     * Both handlers see the change, and then neither does — which is the pair of properties the
     * endpoint needs at once. Reporting to only the first poller meant a developer with the page
     * open in two windows watched one of them never reload; never clearing meant a page that
     * reloaded on the change would reload again on the next poll, forever.
     */
    public function testTwoPollersBothSeeAChangeAndThenNeitherDoes(): void
    {
        $state = new LiveReloadState();
        // Built before the write: the tracker primes its baseline when it is constructed, so a
        // watcher built afterwards has already seen the change and would report nothing.
        $watcher = $this->watcher($state);
        $this->write('Alpha', 'changed');
        $watcher->tick();

        $first = new LiveReloadRoute($state);
        $second = new LiveReloadRoute($state);

        $this->assertSame(['Alpha'], $this->body($this->poll($first))['changed']);
        $this->assertSame(
            ['Alpha'],
            $this->body($this->poll($second))['changed'],
            'the second tab must see the change too, or it never reloads'
        );

        $watcher->tick();

        $this->assertSame([], $this->body($this->poll($first))['changed'], 'reported once, not forever');
        $this->assertSame([], $this->body($this->poll($second))['changed']);
    }

    public function testTheWatcherKeepsThePollingFallbackWorkingWhenNobodyIsListening(): void
    {
        $state = new LiveReloadState();
        $watcher = $this->watcher($state);

        $this->write('Alpha', 'changed');
        $watcher->tick();

        // No broadcaster at all: the watcher constructed without one is the shape of an
        // application that has switched the realtime module off, and its pages must still reload.
        $this->assertSame(['Alpha'], $state->peek());
        $this->assertTrue($state->owesClear(), 'the pending set is released on the next tick');

        $watcher->tick();
        $this->assertSame([], $state->peek(), 'a page that reloaded does not reload again');
    }

    public function testTwoPollersBothSeeTheSameChange(): void
    {
        $state = new LiveReloadState();
        $watcher = $this->watcher($state);
        $this->write('Alpha', 'changed');
        $watcher->tick();

        // The regression that made this a real bug: a developer with the page open in two
        // windows watched one of them never reload, because the first poll emptied the answer.
        $this->assertSame(['Alpha'], $this->body($this->poll(new LiveReloadRoute($state)))['changed']);
        $this->assertSame(['Alpha'], $this->body($this->poll(new LiveReloadRoute($state)))['changed']);
    }

    public function testTheWatcherReleasesAChangeEvenWhenTheNextPassIsQuiet(): void
    {
        $state = new LiveReloadState();
        $watcher = $this->watcher($state);
        $this->write('Alpha', 'changed');
        $watcher->tick();

        $this->assertSame(['Alpha'], $state->peek());

        // No new change, and the clear is still owed. Skipping it here would leave every later
        // poll answering with this same change forever.
        $watcher->tick();
        $this->assertSame([], $state->peek());
    }

    private function watcher(LiveReloadState $state): LiveReloadWatcher
    {
        return new LiveReloadWatcher(
            new ChangeTracker([$this->loader()]),
            $state,
            null
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
        return new LiveReloadRoute(new LiveReloadState());
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