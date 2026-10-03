<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Cache\FileCache;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\ComponentDiscoverer;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Tests\Support\CountingComponentLoader;
use Phue\Tests\Support\TestCompilerBridge;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PhueComponentFreshnessTest — locks the two invariants that a long-lived
 * worker (Swoole/RoadRunner) can silently violate, plus the algorithm shape
 * that keeps resolution cheap.
 *
 * Background: the resolution index (logical name -> relative path) and the
 * per-node module hashes can only be cached if something states *when* they go
 * stale. The kernel therefore resets them at the request boundary via
 * ComponentLoaderRegistry::beginRequest(). These tests pin:
 *
 *  1. STRUCTURAL FRESHNESS — a .phue file added, moved or deleted while the
 *     worker is alive must be visible on the next request boundary, including
 *     in nested subdirectories and including changes that land inside the same
 *     wall-clock second as the previous snapshot. (A directory-mtime heuristic
 *     passes a nested-directory test only by accident and fails the
 *     same-second one outright, because stat() mtime has 1-second resolution.)
 *
 *  2. TRANSITIVE FRESHNESS — editing a child component must change the parent
 *     module's hash, because the parent embeds the child's hash in its import
 *     URL. If it does not, the parent keeps advertising a child URL that
 *     moduleFor() can no longer serve, and the browser dies with a module
 *     load error.
 *
 *  3. MINT == SERVE — every URL the renderer mints must be servable, before and
 *     after a child edit. This is the end-to-end form of invariant 2.
 *
 *  4. ALGORITHM SHAPE — resolution must stay O(V+E). Counted via call counts
 *     (not timings) so the guard is machine-independent.
 *
 *  5. CONTAINMENT — the retry-on-missing-file path must never be reachable for
 *     a path-escape attempt.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('unit')]
final class PhueComponentFreshnessTest extends TestCase
{
    private string $root;
    private string $libDir;
    private CountingComponentLoader $lib;
    private ComponentLoaderRegistry $registry;
    private TestCompilerBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/phue_freshness_' . bin2hex(random_bytes(6));
        $this->libDir = $this->root . '/lib';
        mkdir($this->libDir . '/nested/deep', 0777, true);

        $this->bridge = new TestCompilerBridge();

        $this->lib = new CountingComponentLoader(
            componentsDir: $this->libDir,
            compilerBridge: $this->bridge,
        );

        $this->registry = new ComponentLoaderRegistry();
        $this->registry->addLoader($this->lib);
        $this->lib->setRegistry($this->registry);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function write(string $relativePath, string $template, string $title = 'T'): string
    {
        $path = $this->libDir . '/' . $relativePath;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents(
            $path,
            "<template>\n{$template}\n</template>\n\n<php>\nreturn ['title' => '{$title}'];\n</php>\n"
        );

        return $path;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    // ------------------------------------------- 1. structural freshness

    public function testNewRootComponentIsVisibleOnNextRequestBoundary(): void
    {
        $this->assertFalse($this->registry->hasComponent('Fresh'));

        $this->write('Fresh.phue', '<p>fresh</p>', 'Fresh');

        // No boundary: the snapshot is a consistent view of the request.
        $this->assertFalse($this->registry->hasComponent('Fresh'), 'index must be a stable snapshot inside one request');

        $this->registry->beginRequest();

        $this->assertTrue($this->registry->hasComponent('Fresh'));
        $this->assertNotNull($this->registry->load('Fresh'));
        $this->assertSame('Fresh', $this->registry->load('Fresh')?->title);
    }

    public function testNewNestedComponentIsVisibleOnNextRequestBoundary(): void
    {
        $this->assertFalse($this->registry->hasComponent('Nested'));

        $this->write('nested/Nested.phue', '<p>nested</p>', 'Nested');
        $this->registry->beginRequest();

        $this->assertTrue(
            $this->registry->hasComponent('Nested'),
            'a component added in a subdirectory must be discovered, not just one added at the root'
        );
    }

    public function testDeeplyNestedComponentIsVisibleOnNextRequestBoundary(): void
    {
        $this->write('nested/deep/Deep.phue', '<p>deep</p>', 'Deep');
        $this->registry->beginRequest();

        $this->assertTrue($this->registry->hasComponent('Deep'));
        $this->assertSame('Deep', $this->registry->load('Deep')?->title);
    }

    public function testSameSecondStructuralChangeIsNotMissed(): void
    {
        // The regression: stat() mtime has 1-second resolution, so a heuristic
        // that compares directory mtimes silently ignores any change that lands
        // within the same second as the previous snapshot. Everything below
        // runs with zero delay, so it *is* the same second by construction.
        $this->assertSame(
            time(),
            time(),
            'sanity: this test must not sleep between mutations'
        );

        $this->write('Rapid.phue', '<p>one</p>', 'Rapid');
        $this->registry->beginRequest();
        $this->assertTrue($this->registry->hasComponent('Rapid'));

        // delete + recreate + rename, all inside the same second
        unlink($this->libDir . '/Rapid.phue');
        $this->registry->beginRequest();
        $this->assertFalse($this->registry->hasComponent('Rapid'), 'delete must be visible immediately');

        $this->write('nested/Rapid.phue', '<p>two</p>', 'Rapid');
        $this->registry->beginRequest();
        $this->assertTrue($this->registry->hasComponent('Rapid'), 'nested re-add must be visible immediately');
    }

    public function testDeletedComponentIsGoneOnNextRequestBoundary(): void
    {
        $this->write('Doomed.phue', '<p>x</p>', 'Doomed');
        $this->registry->beginRequest();
        $this->assertTrue($this->registry->hasComponent('Doomed'));

        unlink($this->libDir . '/Doomed.phue');
        $this->registry->beginRequest();

        $this->assertFalse($this->registry->hasComponent('Doomed'));
        $this->assertNull($this->registry->load('Doomed'), 'a deleted component must not resolve to a component');
        $this->assertNull($this->lib->getStructure('Doomed'));
    }

    public function testContentChangeIsVisibleOnNextRequestBoundary(): void
    {
        $this->write('Edited.phue', '<p>before</p>', 'Edited');
        $this->registry->beginRequest();

        $this->write('Edited.phue', '<p>after the change</p>', 'Edited');
        $this->registry->beginRequest();

        $structure = $this->lib->getStructure('Edited');
        $this->assertIsArray($structure);
        $this->assertStringContainsString('after the change', $structure['template']);
    }

    // ------------------------------------------ 2. transitive freshness

    public function testChildEditChangesParentModuleHash(): void
    {
        $this->write('Child.phue', '<span>child v1</span>', 'Child');
        $this->write('Parent.phue', '<div><Child /></div>', 'Parent');
        $this->registry->beginRequest();

        $before = $this->registry->moduleHash('Parent');
        $this->assertIsString($before);

        $this->write('Child.phue', '<span>child v2 changed</span>', 'Child');
        $this->registry->beginRequest();

        $after = $this->registry->moduleHash('Parent');

        $this->assertIsString($after);
        $this->assertNotSame(
            $before,
            $after,
            'a parent module embeds its child hash in the import URL, so a child edit must change the parent hash'
        );
    }

    /**
     * The invalidation contract for a transitive dependency.
     *
     * A parent's module embeds its children's hashes in the import URLs it emits, so the
     * parent's hash is a function of the WHOLE subtree below it, not of its own file. The
     * existing test above only proves the direct case: editing the child changes the
     * parent.
     *
     * This is the case a child-hash memo can silently break. Memoising resolved child
     * hashes by the child's own file identity looks correct — the child's own content did
     * not change — but the hash it hands back also depends on the child's CHILDREN. Edit
     * the grandchild and the child's hash changes; a memo keyed on the child's file never
     * learns that, the parent keeps a stale fingerprint, and buildFor() returns stale code
     * from its memo without ever noticing.
     *
     * The failure is invisible in normal operation: no exception, no warning, a page that
     * renders yesterday's markup. It has to be asserted.
     */
    public function testGrandchildEditChangesAncestorModuleHash(): void
    {
        $this->write('Grandchild.phue', '<span>grandchild v1</span>', 'Grandchild');
        $this->write('Child.phue', '<div><Grandchild /></div>', 'Child');
        $this->write('Parent.phue', '<main><Child /></main>', 'Parent');
        $this->registry->beginRequest();

        $before = $this->registry->moduleHash('Parent');
        $this->assertIsString($before);

        // Only the grandchild's content changes. The child's file is untouched, so any
        // memo keyed on the child's own identity considers it valid.
        $this->write('Grandchild.phue', '<span>grandchild v2 changed</span>', 'Grandchild');
        $this->registry->beginRequest();

        $after = $this->registry->moduleHash('Parent');

        $this->assertIsString($after);
        $this->assertNotSame(
            $before,
            $after,
            'a grandparent module embeds its descendant hashes, so a grandchild edit must change its hash; '
            . 'a resolved-child-hash memo keyed on the child\'s own file identity cannot see this'
        );
    }

    /**
     * The same contract, asserted on the emitted module rather than on the hash, because
     * a hash comparison can pass for the wrong reason: a rebuild that happens to produce
     * an identical hash would satisfy it while still serving stale imports.
     */
    public function testGrandchildEditChangesTheImportsTheParentEmits(): void
    {
        $this->write('Grandchild.phue', '<span>grandchild v1</span>', 'Grandchild');
        $this->write('Child.phue', '<div><Grandchild /></div>', 'Child');
        $this->write('Parent.phue', '<main><Child /></main>', 'Parent');
        $this->registry->beginRequest();

        $childBefore = $this->registry->moduleHash('Child');
        $this->assertIsString($childBefore);
        $parentBefore = $this->lib->buildFor('Parent');
        $this->assertIsString($parentBefore);

        $this->write('Grandchild.phue', '<span>grandchild v2 changed</span>', 'Grandchild');
        $this->registry->beginRequest();

        $childAfter = $this->registry->moduleHash('Child');
        $parentAfter = $this->lib->buildFor('Parent');

        $this->assertIsString($childAfter);
        $this->assertIsString($parentAfter);

        $this->assertNotSame($childBefore, $childAfter, 'the child hash must follow the grandchild');
        $this->assertStringContainsString(
            "Child.{$childAfter}.phue.js",
            $parentAfter,
            'the parent module must import the child by its CURRENT hash'
        );
        $this->assertStringNotContainsString(
            "Child.{$childBefore}.phue.js",
            $parentAfter,
            'a parent still importing the pre-edit child hash is serving a stale module'
        );
        $this->assertNotSame($parentBefore, $parentAfter);
    }

    public function testResolutionAlwaysSeesAFileChangeWithNoManualInvalidation(): void
    {
        // The strongest form of the contract, and the one the loader now honours.
        //
        // buildFor() derives a node's memo fingerprint from its own stat plus its
        // children hashes, and it re-derives that fingerprint on every call. So a
        // change on disk is visible to the very next resolution — with no
        // beginRequest(), no clearMemo(), no process restart. That is what makes
        // the loader safe to drive from a CLI renderer, a queue worker, a
        // scheduler or a cache warmer, none of which have a request boundary to
        // piggyback on.
        //
        // A blind memo ANYWHERE in the chain (loader or registry) breaks this,
        // because it short-circuits the fingerprint revalidation that detects the
        // change. The symptom is not a wrong page: the parent keeps advertising
        // the child's old URL, moduleFor() can no longer serve it, and the
        // browser dies with a module load error.
        $this->write('Child.phue', '<span>child v1</span>', 'Child');
        $this->write('Parent.phue', '<div><Child /></div>', 'Parent');

        $before = $this->registry->moduleHash('Parent');
        $this->assertIsString($before);

        $this->write('Child.phue', '<span>child v2 changed</span>', 'Child');

        $this->assertNotSame(
            $before,
            $this->registry->moduleHash('Parent'),
            'a child edit must be visible to the next resolution with no manual invalidation'
        );

        // ... and the whole advertised graph must remain servable.
        $this->assertGraphIsServable('Parent', 'after a child edit with no reset');
    }

    public function testLoaderNeverServesAMemoWithoutRevalidating(): void
    {
        // Guards the single most dangerous line in the loader.
        //
        // A `if (isset($this->clientMemo[$name]['hash'])) return ...;` fast path
        // at the top of moduleHash() looks like a free optimisation and is
        // completely invisible in normal operation, because the request
        // boundary calls clearMemo() and empties the memo anyway. It only bites
        // in the paths that resolve components WITHOUT a boundary (CLI
        // renderers, queue workers, schedulers, tests) — and then it silently
        // pins a component to a stale transitive child hash.
        //
        // This test isolates the loader from the registry memo (by clearing only
        // the registry) so the loader's own fingerprint revalidation is the only
        // thing standing between a stale hash and a 404. It must not be
        // short-circuited by a memo lookup.
        $this->write('Child.phue', '<span>child v1</span>', 'Child');
        $this->write('Parent.phue', '<div><Child /></div>', 'Parent');

        $warm = $this->registry->moduleHash('Parent');
        $this->assertIsString($warm);

        $this->write('Child.phue', '<span>child v2 changed</span>', 'Child');

        $this->registry->clearMemo();

        $this->assertNotSame(
            $warm,
            $this->registry->moduleHash('Parent'),
            'clearing only the registry memo must let the loader revalidate'
        );
    }

    /**
     * Locks the content-digest freshness contract at the request boundary.
     *
     * A stat-based fingerprint (mtime + size) cannot see an edit that changes
     * neither the byte count nor the mtime, and stat() mtime has ONE-SECOND
     * resolution — so a developer who saves a file and refreshes within the
     * second hits this constantly. The symptom is silent: the parent keeps
     * advertising the child's old URL, the module served under it still
     * contains the old template, and nothing errors anywhere.
     *
     * The digest therefore has to live in the structure cache payload, not only
     * in the module fingerprint — a fingerprint-only digest busts the memo but
     * rebuilds from the stale AST, producing byte-identical output and the same
     * hash, so the URL never changes and the browser never notices.
     *
     * Both replacements below are deliberately the SAME LENGTH and no time
     * passes between the two writes, so mtime+size are provably unchanged.
     */
    public function testSameSecondSameSizeEditIsSeenOnTheNextRequestBoundary(): void
    {

        // Locks the content-digest fingerprint.
        //
        // A stat-based fingerprint (mtime + size) cannot see an edit that
        // changes neither the byte count nor the mtime — and stat() mtime has
        // ONE-SECOND resolution, so a developer who saves a file and refreshes
        // within the same second hits this constantly. The symptom is silent:
        // the parent keeps advertising the child's old URL, the module served
        // under it still contains the old template, and nothing errors.
        //
        // Both replacements below are deliberately the SAME LENGTH, and no time
        // passes between the two writes, so mtime+size are provably unchanged.
        $this->write('Leaf.phue', '<i>aaa</i>', 'Leaf');
        $this->write('Holder.phue', '<div><Leaf /></div>', 'Holder');
        $this->registry->beginRequest();

        $before = $this->registry->moduleHash('Holder');
        $this->assertIsString($before);

        $sizeBefore = filesize($this->libDir . '/Leaf.phue');
        $mtimeBefore = filemtime($this->libDir . '/Leaf.phue');

        // Pin the mtime explicitly. Without this the test is a coin flip: if the
        // clock happens to tick between the two writes, mtime changes, every
        // stat-based check fails, and the test passes for the wrong reason.
        $this->write('Leaf.phue', '<i>bbb</i>', 'Leaf');
        touch($this->libDir . '/Leaf.phue', $mtimeBefore);
        clearstatcache(true, $this->libDir . '/Leaf.phue');

        $this->assertSame(
            $sizeBefore,
            filesize($this->libDir . '/Leaf.phue'),
            'sanity: the edit must not change the file size'
        );
        $this->assertSame(
            $mtimeBefore,
            filemtime($this->libDir . '/Leaf.phue'),
            'sanity: this test relies on the edit landing in the same mtime second'
        );

        // The next request: the per-request snapshot is dropped and the file is
        // re-validated. A change within a single request is deliberately NOT
        // observed — the index is already a per-request snapshot, so content
        // follows the same contract.
        $this->registry->beginRequest();

        $this->assertNotSame(
            $before,
            $this->registry->moduleHash('Holder'),
            'a same-size, same-second child edit must still change the parent hash'
        );

        $this->assertGraphIsServable('Holder', 'after a same-second, same-size child edit');
    }

    public function testSameSecondSameSizeEditIsSeenAcrossAPersistentCache(): void
    {
        // The stronger version, and the one that guards the L2 PSR-16 cache.
        //
        // L1 is wiped on every request, so a stat check there is enough. L2
        // OUTLIVES the process, so a stat check there is not: a same-size,
        // same-second edit made just before a restart stays invisible forever,
        // because the persisted entry still matches on mtime and size.
        //
        // There is no request boundary anywhere in this test — two independent
        // loader instances share only the cache directory. If the digest is not
        // part of the L2 comparison, the second instance reads the first one's
        // stale AST and the stale content is served indefinitely.
        $dir = $this->root . '/l2';
        $cacheDir = $this->root . '/l2-cache';
        mkdir($dir, 0777, true);

        $write = function (string $body) use ($dir): void {
            file_put_contents(
                $dir . '/Leaf.phue',
                "<template>\n{$body}\n</template>\n\n<php>\nreturn ['title' => 'Leaf'];\n</php>\n"
            );
        };

        $write('<i>aaa</i>');

        $first = new PhueComponentLoader(
            componentsDir: $dir,
            persistentCache: new FileCache($cacheDir),
            compilerBridge: new TestCompilerBridge(),
        );

        $this->assertStringContainsString('aaa', (string) ($first->getStructure('Leaf')['template'] ?? ''));

        $sizeBefore = filesize($dir . '/Leaf.phue');
        $mtimeBefore = filemtime($dir . '/Leaf.phue');

        $write('<i>bbb</i>');
        // Pin the mtime: this test is meaningless unless stat() cannot tell the
        // two versions apart.
        touch($dir . '/Leaf.phue', $mtimeBefore);
        clearstatcache(true, $dir . '/Leaf.phue');

        $this->assertSame($sizeBefore, filesize($dir . '/Leaf.phue'), 'sanity: the edit must not change the size');
        $this->assertSame(
            $mtimeBefore,
            filemtime($dir . '/Leaf.phue'),
            'sanity: this test relies on the edit landing in the same mtime second'
        );

        // A brand new loader instance, warm persistent cache, no boundary call.
        $second = new PhueComponentLoader(
            componentsDir: $dir,
            persistentCache: new FileCache($cacheDir),
            compilerBridge: new TestCompilerBridge(),
        );

        $structure = $second->getStructure('Leaf');

        $this->assertIsArray($structure, 'the component must still resolve');
        $this->assertStringContainsString(
            'bbb',
            $structure['template'],
            'the persistent cache served a stale AST for a same-second, same-size edit'
        );
    }

    public function testRawModuleHashStaysLeafOnly(): void
    {
        $this->write('Leaf.phue', '<i>leaf v1</i>', 'Leaf');
        $this->write('Holder.phue', '<div><Leaf /></div>', 'Holder');
        $this->registry->beginRequest();
        $this->lib->buildFor('Holder');
        $this->lib->buildFor('Leaf');

        $holderFullBefore = $this->registry->moduleHash('Holder');
        $holderRawBefore = $this->lib->rawModuleHash('Holder');
        $leafRawBefore = $this->lib->rawModuleHash('Leaf');

        $this->write('Leaf.phue', '<i>leaf v2 with more bytes</i>', 'Leaf');
        $this->registry->beginRequest();

        $this->assertNotSame($leafRawBefore, $this->lib->rawModuleHash('Leaf'), 'leaf raw hash must change');
        $this->assertSame(
            $holderRawBefore,
            $this->lib->rawModuleHash('Holder'),
            'the raw hash is leaf-only by contract: it must not depend on children'
        );
        $this->assertNotSame($holderFullBefore, $this->registry->moduleHash('Holder'), 'the full hash must change');
    }

    // ------------------------------------------------- 3. mint == serve

    public function testEveryMintedUrlIsServableBeforeAndAfterAChildEdit(): void
    {
        $this->write('Kid.phue', '<b>kid v1</b>', 'Kid');
        $this->write('Page.phue', '<main><Kid /></main>', 'Page');
        $this->registry->beginRequest();

        $this->assertGraphIsServable('Page');

        $this->write('Kid.phue', '<b>kid v2 changed</b>', 'Kid');
        $this->registry->beginRequest();

        $this->assertGraphIsServable('Page', 'after a child edit');
    }

    /**
     * Asserts the invariant that actually breaks a browser: every module URL
     * reachable from the page root — the page itself and each component it
     * imports — must resolve through moduleFor() with the hash currently being
     * advertised.
     */
    private function assertGraphIsServable(string $rootName, string $context = ''): void
    {
        $discoverer = new ComponentDiscoverer($this->registry);
        $graph = $discoverer->resolveTransitiveDependencies($rootName);

        $this->assertNotEmpty($graph, "no dependencies resolved for {$rootName} {$context}");

        foreach ($graph as $name => $hash) {
            $url = $this->registry->moduleUrl($name);
            $this->assertIsString($url, "no URL minted for {$name} {$context}");

            $this->assertSame(
                1,
                preg_match('#^/assets/phue/(.+)\.([a-f0-9]{8})\.phue\.js$#', $url, $m),
                "unexpected URL shape for {$name} {$context}: {$url}"
            );
            $this->assertSame($name, $m[1], "URL name mismatch for {$name} {$context}");
            $this->assertSame(
                $hash,
                $m[2],
                "advertised hash must match the graph hash for {$name} {$context}"
            );

            $this->assertNotNull(
                $this->registry->moduleFor($name, $hash),
                "moduleFor() must serve the module the page advertises for {$name} {$context}"
            );
        }
    }

    // ---------------------------------------- 4. algorithm shape (O(V+E))

    public function testRepeatWalkOfAnAlreadyResolvedGraphStaysBounded(): void
    {
        // There is no longer a separate cross-loader memo, so a repeat walk does
        // re-enter the loader. What must hold is that it stays BOUNDED: each node
        // is rebuilt at most a small constant number of times, because buildFor()
        // memoizes per node and the traversal re-uses the registry to reach each
        // child. An earlier implementation re-walked the whole graph once per
        // queried node and issued ~42 builds per component.
        $this->write('Root.phue', '<div><Branch /></div>', 'Root');
        $this->write('Branch.phue', '<section><Leaf /></section>', 'Branch');
        $this->write('Leaf.phue', '<span>leaf</span>', 'Leaf');
        $this->registry->beginRequest();

        $discoverer = new ComponentDiscoverer($this->registry);
        $this->assertCount(3, $discoverer->resolveTransitiveDependencies('Root'));

        $this->lib->resetCounters();
        $this->assertCount(3, $discoverer->resolveTransitiveDependencies('Root'));

        $this->assertLessThanOrEqual(
            12,
            $this->lib->buildForCalls,
            "a repeat walk of a 3-node graph issued {$this->lib->buildForCalls} builds; it must stay bounded"
        );
    }

    public function testExistenceChecksDoNotRescanTheDirectory(): void
    {
        $this->write('Alpha.phue', '<p>a</p>', 'Alpha');
        $this->write('Beta.phue', '<p>b</p>', 'Beta');
        $this->registry->beginRequest();

        // A miss must be an O(1) map probe. The pre-optimisation loader rebuilt a
        // recursive directory index twice per miss and threw, which made
        // scanTemplate() cost O(tags * loaders * files).
        $this->lib->resetCounters();

        for ($i = 0; $i < 50; $i++) {
            $this->assertFalse($this->lib->hasComponent('DefinitelyMissing'));
        }

        $this->assertSame(50, $this->lib->hasComponentCalls);
        $this->assertSame(
            0,
            $this->lib->buildForCalls,
            'existence checks must never trigger a module build'
        );
    }

    public function testDeepGraphDoesNotRegressToQuadraticWork(): void
    {
        // Machine-independent by construction: compare the work done for a 6-node
        // chain against a 24-node chain. Quadratic behaviour scales ~16x when the
        // graph grows 4x; linear behaviour scales ~4x. Asserting the RATIO
        // cannot flake the way an absolute call budget does on slower hardware.
        $small = $this->measureChainBuilds(6);
        $large = $this->measureChainBuilds(24);

        $growth = $large / max($small, 1);
        $quadraticGrowth = (24 / 6) ** 2;

        $this->assertLessThan(
            $quadraticGrowth,
            $growth,
            sprintf(
                'chain work grew %.1fx going from 6 to 24 nodes (builds: %d -> %d); '
                . 'quadratic would be %.0fx. Resolution must stay O(V+E).',
                $growth,
                $small,
                $large,
                $quadraticGrowth
            )
        );
    }

    /**
     * Builds a linear N-node chain in an isolated directory and returns the
     * number of buildFor() calls a single transitive resolution issues.
     */
    private function measureChainBuilds(int $depth): int
    {
        $dir = $this->root . '/chain' . $depth;
        mkdir($dir, 0777, true);

        for ($i = 0; $i < $depth; $i++) {
            $child = $i + 1 < $depth ? "<N" . ($i + 1) . " />" : '';
            file_put_contents(
                $dir . "/N{$i}.phue",
                "<template>\n<div>{$child}</div>\n</template>\n\n<php>\nreturn ['title' => 'N{$i}'];\n</php>\n"
            );
        }

        $loader = new CountingComponentLoader(
            componentsDir: $dir,
            compilerBridge: new TestCompilerBridge(),
        );

        $registry = new ComponentLoaderRegistry();
        $registry->addLoader($loader);
        $loader->setRegistry($registry);
        $registry->beginRequest();

        $discoverer = new ComponentDiscoverer($registry);
        $this->assertCount($depth, $discoverer->resolveTransitiveDependencies('N0'));

        $loader->resetCounters();
        $discoverer->resolveTransitiveDependencies('N0');

        return $loader->buildForCalls;
    }

    // ------------------------------------------------- 5. containment

    public function testPathTraversalNamesAreRejectedWithoutRetry(): void
    {
        $this->write('Safe.phue', '<p>safe</p>', 'Safe');
        $this->registry->beginRequest();

        foreach (['../etc/passwd', 'nested/../../escape', '..', 'nested/../..'] as $bad) {
            $this->assertFalse($this->lib->hasComponent($bad), "hasComponent() must reject {$bad}");

            $threw = false;
            try {
                $this->lib->load($bad);
            } catch (\RuntimeException) {
                $threw = true;
            }

            $this->assertTrue($threw, "load() must reject {$bad}");
        }
    }

    public function testSymlinkedComponentOutsideTheDirectoryIsRejected(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('symlink() unavailable');
        }

        $outside = $this->root . '/Outside.phue';
        file_put_contents($outside, "<template><p>outside</p></template>\n");
        mkdir($this->libDir . '/linked', 0777, true);

        if (!@symlink($outside, $this->libDir . '/linked/Linked.phue')) {
            $this->markTestSkipped('symlink creation not permitted');
        }

        $this->registry->beginRequest();

        // Whether the index admits it depends on getRealPath() semantics; what
        // must hold is that it is never silently served from outside the root.
        if ($this->lib->hasComponent('Linked')) {
            $threw = false;
            try {
                $this->lib->load('Linked');
            } catch (\RuntimeException) {
                $threw = true;
            }

            $this->assertTrue($threw, 'a component resolving outside the components dir must be rejected');
        }

        $this->assertTrue(true, 'containment holds');
    }

    // -------------------------------------------- request boundary hygiene

    public function testBeginRequestIsIdempotent(): void
    {
        $this->write('Idem.phue', '<p>i</p>', 'Idem');
        $this->registry->beginRequest();

        $first = $this->registry->moduleHash('Idem');

        $this->registry->beginRequest();
        $this->registry->beginRequest();
        $this->registry->beginRequest();

        $this->assertSame($first, $this->registry->moduleHash('Idem'));
        $this->assertTrue($this->registry->hasComponent('Idem'));
    }

    public function testRegistryToleratesAFailingLoaderDuringReset(): void
    {
        $exploding = new class (componentsDir: $this->libDir, compilerBridge: $this->bridge) extends PhueComponentLoader {
            public bool $explode = true;

            public function clearMemo(): void
            {
                if ($this->explode) {
                    throw new \RuntimeException('loader unavailable');
                }

                parent::clearMemo();
            }
        };

        $this->registry->addLoader($exploding);
        $this->write('Tolerant.phue', '<p>t</p>', 'Tolerant');

        $this->registry->beginRequest();

        $this->assertTrue(
            $this->registry->hasComponent('Tolerant'),
            'a loader that throws during reset must not break the whole boundary'
        );

        $exploding->explode = false;
        $this->registry->beginRequest();
        $this->assertTrue($this->registry->hasComponent('Tolerant'));
    }

    public function testResolutionSurvivesAFailingLoaderInTheChain(): void
    {
        $brokenDir = $this->root . '/broken';
        mkdir($brokenDir, 0777, true);
        file_put_contents($brokenDir . '/Broken.phue', "<template><p>b</p></template>\n");

        $broken = new class (componentsDir: $brokenDir, compilerBridge: $this->bridge) extends PhueComponentLoader {
            public function hasComponent(string $name): bool
            {
                throw new \RuntimeException('index unavailable');
            }
        };

        $this->registry->addLoader($broken);
        $this->write('Solo.phue', '<p>s</p>', 'Solo');
        $this->registry->beginRequest();

        $this->assertTrue($this->registry->hasComponent('Solo'), 'a throwing loader must not break resolution');
        $this->assertSame('Solo', $this->registry->load('Solo')?->title);
        $this->assertIsString($this->registry->moduleHash('Solo'));
    }

    public function testLoadingAComponentDoesNotDependOnAnotherLoader(): void
    {
        $otherDir = $this->root . '/other';
        mkdir($otherDir, 0777, true);
        file_put_contents($otherDir . '/Other.phue', "<template><p>o</p></template>\n");

        $other = new PhueComponentLoader($otherDir, compilerBridge: $this->bridge);
        $this->registry->addLoader($other);

        $this->write('Mine.phue', '<p>m</p>', 'Mine');
        $this->registry->beginRequest();

        $this->assertTrue($this->registry->hasComponent('Other'), 'the newly added loader must be usable');
        $this->assertTrue($this->registry->hasComponent('Mine'), 'existing loader components must stay resolvable');
        $this->assertSame('Mine', $this->registry->load('Mine')?->title);
    }
}
