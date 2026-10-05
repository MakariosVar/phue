<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Hiit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Hiit\ChangeTracker;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Tests\Support\ArrayCache;
use Phue\Tests\Support\TestCompilerBridge;

/**
 * ChangeTracker answers one question: what moved since the last time somebody asked.
 *
 * The baseline is the entire contract. It moves as the answer is produced, so the same change
 * is reported once and then never again — without that, a poller reloads the page forever. And
 * it is primed at construction rather than discarded from the first answer, because a comparison
 * against an empty baseline calls every component on disk new and reports the whole project.
 *
 * @package Phue\Tests\Core\Hiit
 */
#[Group('unit')]
final class ChangeTrackerTest extends TestCase
{
    private string $root;
    private string $appDir;
    private string $libDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/phue_tracker_' . bin2hex(random_bytes(6));
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

    public function testNothingChangedIsNothingReported(): void
    {
        $this->write($this->appDir, 'Alpha');

        $tracker = $this->tracker([$this->appDir]);

        $this->assertSame([], $tracker->changed());
        $this->assertSame([], $tracker->changed());
    }

    public function testTheFirstLookDoesNotReportWhatWasAlreadyThere(): void
    {
        $this->write($this->appDir, 'Alpha');
        $this->write($this->appDir, 'Beta');

        // Against an empty baseline every component on disk is new, so an unprimed tracker
        // reports the entire project and whatever is polling acts on all of it.
        $this->assertSame([], $this->tracker([$this->appDir])->changed());
    }

    public function testAChangedComponentIsReportedOnce(): void
    {
        $this->write($this->appDir, 'Alpha');
        $tracker = $this->tracker([$this->appDir]);
        $tracker->changed();

        $this->write($this->appDir, 'Alpha', 'changed');

        $this->assertSame(['Alpha'], $tracker->changed());
        $this->assertSame([], $tracker->changed(), 'a change already reported must not repeat');
    }

    public function testAnAddedComponentIsReported(): void
    {
        $tracker = $this->tracker([$this->appDir]);
        $tracker->changed();

        $this->write($this->appDir, 'Fresh');

        $this->assertSame(['Fresh'], $tracker->changed());
    }

    public function testARemovedComponentIsReported(): void
    {
        $this->write($this->appDir, 'Doomed');
        $tracker = $this->tracker([$this->appDir]);
        $tracker->changed();

        unlink($this->appDir . '/Doomed.phue');

        // A memo entry for a file that no longer exists has nothing on disk to contradict it.
        $this->assertSame(['Doomed'], $tracker->changed());
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

        $tracker = $this->tracker([$this->appDir]);
        $tracker->changed();

        clearstatcache(true, $this->appDir . '/Alpha.phue');
        file_put_contents($this->appDir . '/Alpha.phue', $edited . "\n<script>export default {}</script>\n");

        $this->assertSame(['Alpha'], $tracker->changed());
    }

    public function testSeveralDirectoriesAreReportedTogether(): void
    {
        $tracker = $this->tracker([$this->appDir, $this->libDir]);
        $tracker->changed();

        $this->write($this->appDir, 'Page');
        $this->write($this->libDir, 'Widget');

        // One answer for one edit session. Two would mean two reloads, and the second is the
        // one a developer notices as jank.
        $this->assertSame(['Page', 'Widget'], $tracker->changed());
    }

    public function testTwoLoadersOnOneDirectoryWatchItOnce(): void
    {
        $this->write($this->appDir, 'Alpha');

        $tracker = $this->tracker([$this->appDir, $this->appDir]);
        $tracker->changed();

        $this->write($this->appDir, 'Alpha', 'changed');

        $this->assertCount(1, $tracker->directories());
        $this->assertSame(['Alpha'], $tracker->changed());
    }

    public function testNestedComponentsAreNamedByTheirPath(): void
    {
        mkdir($this->appDir . '/Components', 0777, true);

        $tracker = $this->tracker([$this->appDir]);
        $tracker->changed();

        $this->write($this->appDir . '/Components', 'PhuiOverview');

        $this->assertSame(['Components/PhuiOverview'], $tracker->changed());
    }

    public function testWithNoLoadersNothingIsWatched(): void
    {
        $tracker = new ChangeTracker([]);

        $this->assertSame([], $tracker->directories());
        $this->assertSame([], $tracker->changed(), 'no directories means nothing to report, not an error');
    }

    public function testADirectoryThatVanishedIsLeftOut(): void
    {
        $registry = new ComponentLoaderRegistry();
        $registry->addLoader($this->loader($this->appDir));

        // A loader cannot be built for a directory that is not there — the constructor refuses.
        // The reachable case is a directory deleted underneath a running process.
        rmdir($this->appDir);

        $this->assertSame([], (new ChangeTracker($registry->resolvedLoaders()))->directories());
    }

    // ----------------------------------------------------------------------- helpers

    /**
     * @param list<string> $directories
     */
    private function tracker(array $directories, ?\Psr\SimpleCache\CacheInterface $cache = null): ChangeTracker
    {
        $registry = new ComponentLoaderRegistry();

        foreach ($directories as $directory) {
            $registry->addLoader($this->loader($directory));
        }

        return new ChangeTracker($registry->resolvedLoaders(), $cache);
    }

    /**
     * The baseline has to outlive the object, or live reload cannot work on any runtime that ends
     * the process between checks.
     *
     * Under FPM and PHP's built-in server every request is a separate execution, so a tracker built
     * per request compares the tree against the tree and reports nothing — forever. Nothing about
     * that is visible from outside: the endpoint answers 200 with an empty list.
     */
    public function testAPersistedBaselineSpansSeparateTrackers(): void
    {
        $cache = new ArrayCache();
        $this->write($this->appDir, 'Alpha');

        $first = $this->tracker([$this->appDir], $cache);
        $this->assertSame([], $first->changed(), 'the first pass primes');

        $this->write($this->appDir, 'Beta', 'changed');

        // A brand new tracker, as a fresh request would build. Nothing is shared but the cache.
        $second = $this->tracker([$this->appDir], $cache);
        $this->assertSame(['Beta'], $second->changed(), 'the change survives the process boundary');

        // And exactly once, or every poll after the save would report it again.
        $third = $this->tracker([$this->appDir], $cache);
        $this->assertSame([], $third->changed(), 'and is reported once');
    }

    public function testAnInMemoryBaselineIsUnaffectedByAPersistedOne(): void
    {
        $cache = new ArrayCache();
        $this->write($this->appDir, 'Alpha');
        $this->tracker([$this->appDir], $cache)->changed();

        $this->write($this->appDir, 'Beta');

        // No cache: each tracker primes itself, which is the correct behaviour for a long-lived
        // process and the reason the persisted baseline is opt-in rather than mandatory.
        $this->assertSame([], $this->tracker([$this->appDir])->changed());
    }

    public function testADifferentComponentTreePrimesRatherThanComparingUnrelatedTrees(): void
    {
        $cache = new ArrayCache();
        $this->write($this->appDir, 'Alpha');
        $this->tracker([$this->appDir], $cache)->changed();

        $other = $this->root . '/other';
        mkdir($other, 0777, true);
        $this->write($other, 'Gamma');

        // Keyed by the directories, so a second tree is not compared against the first one's
        // baseline — which would report every file in both as new.
        $this->assertSame([], $this->tracker([$other], $cache)->changed());
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