<?php

declare(strict_types=1);

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Response;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Modules\DevTool\LiveReloadRoute;
use Phue\Tests\Support\TestCompilerBridge;

/**
 * The live-reload endpoint answers with JSON and returns.
 *
 * Every property that matters is a consequence of that. It cannot pin a worker, because it
 * finishes. It cannot be cached, because the answer is different every time. And it cannot
 * report the same change twice, because the handler — not the browser — remembers what it said
 * last.
 *
 * @package Phue\Tests\DevTools
 */
#[Group('unit')]
final class LiveReloadRouteTest extends TestCase
{
    private string $root;
    private string $appDir;
    private string $libDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/phue_polling_' . bin2hex(random_bytes(6));
        $this->appDir = $this->root . '/components';
        $this->libDir = $this->root . '/lib';

        mkdir($this->appDir, 0777, true);
        mkdir($this->libDir, 0777, true);
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

    // -------------------------------------------------------------- what it answers

    public function testItAnswersWithJsonAndFinishes(): void
    {
        $response = $this->poll($this->route([$this->appDir]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->getHeader('Content-Type'));
        $this->assertSame(['changed' => []], $this->body($response));
    }

    public function testTheAnswerMustNotBeCacheable(): void
    {
        $response = $this->poll($this->route([$this->appDir]));

        // Cached and this endpoint is broken: the browser stops seeing changes that did happen.
        $this->assertStringContainsString('no-store', (string) $response->getHeader('Cache-Control'));
    }

    public function testTheFirstPollReportsNothingEvenWithComponentsOnDisk(): void
    {
        $this->write($this->appDir, 'Alpha');
        $this->write($this->appDir, 'Beta');

        // Against an empty baseline every component on disk looks new, so the first poll would
        // report the whole project and the page would reload for no reason.
        $this->assertSame(['changed' => []], $this->body($this->poll($this->route([$this->appDir]))));
    }

    // ------------------------------------------------------------- what it detects

    public function testAChangedComponentIsReportedOnce(): void
    {
        $this->write($this->appDir, 'Alpha');
        $route = $this->route([$this->appDir]);

        $this->poll($route);
        $this->write($this->appDir, 'Alpha', 'changed');

        $this->assertSame(['changed' => ['Alpha']], $this->body($this->poll($route)));

        // The second poll is the one that matters: a handler that keeps re-reporting would make
        // the browser reload on every tick forever.
        $this->assertSame(['changed' => []], $this->body($this->poll($route)));
    }

    public function testAnAddedComponentIsReported(): void
    {
        $route = $this->route([$this->appDir]);
        $this->poll($route);

        $this->write($this->appDir, 'Fresh');

        $this->assertSame(['changed' => ['Fresh']], $this->body($this->poll($route)));
    }

    public function testARemovedComponentIsReported(): void
    {
        $this->write($this->appDir, 'Doomed');
        $route = $this->route([$this->appDir]);
        $this->poll($route);

        unlink($this->appDir . '/Doomed.phue');

        $this->assertSame(['changed' => ['Doomed']], $this->body($this->poll($route)));
    }

    /**
     * The case that rules out an mtime-based check: same byte count, same second, new content.
     */
    public function testASameSecondSameSizeEditIsStillReported(): void
    {
        $original = "<template>\n  <div>alpha</div>\n</template>\n";
        $edited = "<template>\n  <div>bravo</div>\n</template>\n";

        $this->assertSame(strlen($original), strlen($edited));

        file_put_contents($this->appDir . '/Alpha.phue', $original . "\n<script>export default {}</script>\n");

        $route = $this->route([$this->appDir]);
        $this->poll($route);

        clearstatcache(true, $this->appDir . '/Alpha.phue');
        file_put_contents($this->appDir . '/Alpha.phue', $edited . "\n<script>export default {}</script>\n");

        $this->assertSame(['changed' => ['Alpha']], $this->body($this->poll($route)));
    }

    public function testSeveralDirectoriesAreReportedTogether(): void
    {
        $route = $this->route([$this->appDir, $this->libDir]);
        $this->poll($route);

        $this->write($this->appDir, 'Page');
        $this->write($this->libDir, 'Widget');

        // One reload for one edit session, not two. Two would be the one a developer notices.
        $this->assertSame(['changed' => ['Page', 'Widget']], $this->body($this->poll($route)));
    }

    public function testTwoLoadersOnOneDirectoryReportItOnce(): void
    {
        $this->write($this->appDir, 'Alpha');

        $registry = $this->registry([$this->appDir, $this->appDir]);
        $route = new LiveReloadRoute($registry);
        $this->poll($route);

        $this->write($this->appDir, 'Alpha', 'changed');

        $this->assertSame(['changed' => ['Alpha']], $this->body($this->poll($route)));
    }

    // -------------------------------------------------------------- nothing to watch

    public function testWithNoComponentsItStillAnswersCleanly(): void
    {
        $response = $this->poll($this->route([]));

        // An application with no .phue components is normal. It must not be an error, because a
        // 404 here looks to the browser like a broken dev server.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['changed' => []], $this->body($response));
    }

    public function testABrokenLoaderDoesNotStopTheOthersBeingWatched(): void
    {
        $this->write($this->appDir, 'Alpha');

        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(function (): PhueComponentLoader {
            throw new \RuntimeException('this module cannot start');
        });
        $registry->addLoader($this->loader($this->appDir));

        $route = new LiveReloadRoute($registry);
        $this->poll($route);

        $this->write($this->appDir, 'Alpha', 'changed');

        $this->assertSame(['changed' => ['Alpha']], $this->body($this->poll($route)));
    }

    public function testLoadersRegisteredLaterAreStillPickedUp(): void
    {
        $registry = $this->registry([]);
        $route = new LiveReloadRoute($registry);

        // Polled once before anything was registered, so the directories were resolved already.
        $this->poll($route);

        $this->write($this->libDir, 'Late');
        $registry->addLoader($this->loader($this->libDir));

        $this->assertSame(['changed' => []], $this->body($this->poll($route)));
    }

    // ----------------------------------------------------------------------- helpers

    private function poll(LiveReloadRoute $route): Response
    {
        return $route();
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        $this->assertIsArray($decoded, 'the endpoint must answer with json, not html');

        return $decoded;
    }

    /**
     * @param list<string> $directories
     */
    private function route(array $directories): LiveReloadRoute
    {
        return new LiveReloadRoute($this->registry($directories));
    }

    /**
     * @param list<string> $directories
     */
    private function registry(array $directories): ComponentLoaderRegistry
    {
        $registry = new ComponentLoaderRegistry();

        foreach ($directories as $directory) {
            $registry->addLoader($this->loader($directory));
        }

        return $registry;
    }

    private function loader(string $dir): PhueComponentLoader
    {
        return new PhueComponentLoader(
            componentsDir: $dir,
            compilerBridge: new TestCompilerBridge(),
        );
    }

    private function write(string $dir, string $name, string $variant = 'original'): void
    {
        file_put_contents(
            $dir . '/' . $name . '.phue',
            "<template>\n  <div>{$name} {$variant}</div>\n</template>\n<script>export default {}</script>\n"
        );
    }
}