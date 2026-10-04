<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Hiit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Hiit\Hiit;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Tests\Support\CountingComponentLoader;
use Phue\Tests\Support\TestCompilerBridge;

/**
 * Hiit answers one question: which components no longer match what the caller recorded?
 *
 * The loader used to find out by throwing every memo away on each request and rebuilding
 * the graph from the files — about 90ms of a 112ms request, paid whether or not anything
 * had changed. It can keep its memos instead if something tells it what moved, and that
 * something has to be a CONTENT hash: an edit landing in the same second that happens to
 * keep the byte count is invisible to an mtime, and PhueComponentFreshnessTest pins that a
 * change like it must still be served.
 *
 * So `changed()` is deliberately a pure function of what the caller recorded and what is on
 * disk. No internal state to keep coherent, no watcher to supervise, and no answer that can
 * be stale by the time somebody reads it.
 *
 * @package Phue\Tests\Core\Hiit
 */
#[Group('unit')]
final class HiitTest extends TestCase
{
    private string $root;
    private string $componentsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/phue_hiit_' . bin2hex(random_bytes(6));
        $this->componentsDir = $this->root . '/components';

        mkdir($this->componentsDir, 0777, true);
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

    // -------------------------------------------------------------- the pure function

    public function testNothingChangedIsAnEmptyAnswer(): void
    {
        $this->write('Alpha', '<div>a</div>');
        $this->write('Beta', '<div>b</div>');

        $hiit = new Hiit($this->componentsDir);

        $this->assertSame([], $hiit->changed($this->record()));
    }

    public function testAComponentWhoseContentChangedIsReported(): void
    {
        $this->write('Alpha', '<div>a</div>');
        $this->write('Beta', '<div>b</div>');

        $hiit = new Hiit($this->componentsDir);
        $known = $this->record();

        $this->write('Beta', '<div>b changed</div>');

        $this->assertSame(['Beta'], $hiit->changed($known));
    }

    public function testAComponentThatWasAddedIsReported(): void
    {
        $this->write('Alpha', '<div>a</div>');

        $hiit = new Hiit($this->componentsDir);
        $known = $this->record();

        $this->write('Beta', '<div>b</div>');

        $this->assertSame(['Beta'], $hiit->changed($known));
    }

    public function testAComponentThatWasDeletedIsReported(): void
    {
        $this->write('Alpha', '<div>a</div>');
        $this->write('Beta', '<div>b</div>');

        $hiit = new Hiit($this->componentsDir);
        $known = $this->record();

        unlink($this->componentsDir . '/Beta.phue');

        $this->assertSame(['Beta'], $hiit->changed($known));
    }

    public function testComponentsInNestedDirectoriesAreSeen(): void
    {
        mkdir($this->componentsDir . '/Pages', 0777, true);
        $this->write('Pages/Deep', '<div>d</div>');

        $hiit = new Hiit($this->componentsDir);
        $known = $this->record();

        $this->write('Pages/Deep', '<div>d changed</div>');

        $this->assertSame(['Pages/Deep'], $hiit->changed($known));
    }

    /**
     * The case that rules out an mtime-based implementation.
     *
     * Same byte count, same second, different content. A stat-based check sees nothing;
     * this must still report the change, because that is the edit a developer makes when
     * they retype a word in the template and refresh without thinking about mtime.
     */
    public function testASameSecondSameSizeEditIsStillReported(): void
    {
        $original = "<template>\n  <div>alpha</div>\n</template>\n";
        $edited = "<template>\n  <div>bravo</div>\n</template>\n";

        $this->assertSame(
            strlen($original),
            strlen($edited),
            'the two revisions must be the same length for this test to mean anything'
        );

        file_put_contents($this->componentsDir . '/Alpha.phue', $original . "\n<script>export default {}</script>\n");

        $hiit = new Hiit($this->componentsDir);
        $known = $this->record();
        clearstatcache(true, $this->componentsDir . '/Alpha.phue');

        file_put_contents($this->componentsDir . '/Alpha.phue', $edited . "\n<script>export default {}</script>\n");

        $this->assertSame(['Alpha'], $hiit->changed($known), 'a same-second, same-size edit must not slip through');
    }

    public function testTheAnswerIsStableAcrossRepeatedCalls(): void
    {
        $this->write('Alpha', '<div>a</div>');

        $hiit = new Hiit($this->componentsDir);
        $known = $this->record();

        $this->write('Beta', '<div>b</div>');

        // A watcher polling on a timer must not report the same edit forever, and must not
        // report it once and then forget it either.
        $this->assertSame(['Beta'], $hiit->changed($known));
        $this->assertSame(['Beta'], $hiit->changed($known), 'the same recorded state must give the same answer');

        $this->assertSame([], $hiit->changed($this->record()), 'once the caller records the new state, the change is settled');
    }

    public function testADirectoryThatDoesNotExistIsNotAnError(): void
    {
        $hiit = new Hiit($this->root . '/absent');

        // Nothing on disk, so a recorded component is a removed one. Reporting nothing here
        // would leave its memo entries behind with nothing to contradict them.
        $this->assertSame(['Alpha'], $hiit->changed(['Alpha' => 'whatever']));
        $this->assertSame([], $hiit->changed([]));
    }

    // ------------------------------------------------------------ what the loader does

    public function testALoaderDetectsChangesWithoutBeingWiredUp(): void
    {
        $loader = $this->loader();

        $this->write('Alpha', '<div><beta /></div>');
        $this->write('Beta', '<div>b</div>');
        $registry = $this->wire($loader, null);

        // Nothing called setHiit(). If freshness detection depended on a wiring step someone
        // could forget, this assertion is where that would show up.
        $this->assertInstanceOf(Hiit::class, $loader->hiit());

        $registry->beginRequest();
        $before = $loader->buildFor('Alpha');

        $this->write('Beta', '<div>b changed</div>');

        $registry->beginRequest();

        $this->assertNotSame($before, $loader->buildFor('Alpha'));
    }

    public function testTheLoaderKeepsItsMemosWhenNothingChanged(): void
    {
        $loader = $this->loader();
        $hiit = new Hiit($this->componentsDir);

        $this->write('Alpha', '<div><beta /></div>');
        $this->write('Beta', '<div>b</div>');
        $registry = $this->wire($loader, $hiit);

        $registry->beginRequest();
        $first = $loader->buildFor('Alpha');

        $registry->beginRequest();
        $second = $loader->buildFor('Alpha');

        $this->assertSame($first, $second);
        $this->assertSame([], $hiit->lastChanged(), 'nothing was edited, so nothing should be reported');
    }

    public function testTheLoaderForgetsEverythingWhenAComponentChanged(): void
    {
        $loader = $this->loader();
        $hiit = new Hiit($this->componentsDir);

        $this->write('Alpha', '<div><beta /></div>');
        $this->write('Beta', '<div>b</div>');
        $registry = $this->wire($loader, $hiit);

        $registry->beginRequest();
        $before = $loader->buildFor('Alpha');

        $this->write('Beta', '<div>b changed</div>');

        $registry->beginRequest();

        $this->assertNotSame($before, $loader->buildFor('Alpha'), 'a changed dependency must reach the parent hash');
        $this->assertSame(['Beta'], $hiit->lastChanged());
    }

    public function testTheLoaderFallsBackToForgettingEverythingWithoutHiit(): void
    {
        $loader = $this->loader();
        $loader->setHiit(null);

        $this->write('Alpha', '<div><beta /></div>');
        $this->write('Beta', '<div>b</div>');
        $registry = $this->wire($loader, null);

        $registry->beginRequest();
        $before = $loader->buildFor('Alpha');

        $this->write('Beta', '<div>b changed</div>');

        $registry->beginRequest();

        // Not an optimisation, a guarantee: with nobody to ask, the only safe answer is the
        // one the loader already had before Hiit existed.
        $this->assertNotSame($before, $loader->buildFor('Alpha'));
    }

    public function testAChangeInOneLoaderReachesEveryLoader(): void
    {
        $app = $this->loader($this->componentsDir);
        $libDir = $this->root . '/lib';
        mkdir($libDir, 0777, true);
        $lib = $this->loader($libDir);

        $this->write('Root', '<div><lib-child /></div>', $this->componentsDir);
        file_put_contents($libDir . '/LibChild.phue', "<template>\n  <div>v1</div>\n</template>\n<script>export default {}</script>\n");

        $registry = new ComponentLoaderRegistry();
        $registry->addLoader($app);
        $registry->addLoader($lib);
        $app->setRegistry($registry);
        $lib->setRegistry($registry);

        $appHiit = new Hiit($this->componentsDir);
        $libHiit = new Hiit($libDir);
        $this->attachHiit($app, $appHiit);
        $this->attachHiit($lib, $libHiit);

        $registry->beginRequest();
        $before = $app->buildFor('Root');

        file_put_contents($libDir . '/LibChild.phue', "<template>\n  <div>v2 changed</div>\n</template>\n<script>export default {}</script>\n");

        $registry->beginRequest();

        // The parent lives in one loader and caches a hash minted by the other. Only the
        // library loader can see the edit, and the parent still has to hear about it.
        $this->assertSame(['LibChild'], $libHiit->lastChanged());
        $this->assertNotSame($before, $app->buildFor('Root'), 'a cross-loader edit must reach the parent hash');
    }

    // ----------------------------------------------------------------------- helpers

    /**
     * What a caller would have recorded: name => content hash.
     *
     * @return array<string, string>
     */
    private function record(?string $dir = null): array
    {
        $dir = $dir ?? $this->componentsDir;
        $known = [];

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($items as $item) {
            if ($item->isFile() && $item->getExtension() === 'phue') {
                $known[substr($item->getPathname(), strlen($dir) + 1, -5)] = (string) sha1_file($item->getPathname());
            }
        }

        return $known;
    }

    private function loader(?string $dir = null): PhueComponentLoader
    {
        return new CountingComponentLoader(
            componentsDir: $dir ?? $this->componentsDir,
            compilerBridge: new TestCompilerBridge(),
        );
    }

    private function wire(PhueComponentLoader $loader, ?Hiit $hiit): ComponentLoaderRegistry
    {
        $registry = new ComponentLoaderRegistry();
        $registry->addLoader($loader);
        $loader->setRegistry($registry);

        if ($hiit !== null) {
            $this->attachHiit($loader, $hiit);
        }

        return $registry;
    }

    private function attachHiit(PhueComponentLoader $loader, Hiit $hiit): void
    {
        $loader->setHiit($hiit);
    }

    private function write(string $name, string $template, ?string $dir = null): void
    {
        $dir = $dir ?? $this->componentsDir;

        if (!str_ends_with($name, '.phue')) {
            $name .= '.phue';
        }

        file_put_contents(
            $dir . '/' . $name,
            "<template>\n  {$template}\n</template>\n<script>export default {}</script>\n"
        );
    }
}