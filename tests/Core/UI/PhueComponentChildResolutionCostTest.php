<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\ComponentDiscoverer;
use Phue\Tests\Support\CountingComponentLoader;
use Phue\Tests\Support\TestCompilerBridge;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * PhueComponentChildResolutionCostTest — locks the cost SHAPE of child discovery.
 *
 * Child discovery is a regex scan of a component's template plus one existence
 * check per candidate tag (ComponentDiscoverer::scanTemplate). buildFor() runs
 * it once per invocation, and buildFor() is invoked once per node per level of
 * the dependency graph, so an un-memoised traversal re-scans the same templates
 * once per queried node: on a 45-node page that was 893 scans and ~2,200
 * existence checks per request.
 *
 * The loader therefore memoises the child list per file, keyed by the file's
 * CONTENT HASH. That key is what makes the memo safe rather than a blind cache:
 * the template can only change when the content changes, and the content hash is
 * re-read from the per-request structure entry, so a memoised child list cannot
 * outlive the content it describes.
 *
 * These tests assert call COUNTS, not milliseconds — wall-clock assertions flake
 * across machines, whereas the algorithmic shape does not.
 *
 * Pure stubs: temporary .phue fixtures, TestCompilerBridge, no \App and no Phui.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('unit')]
final class PhueComponentChildResolutionCostTest extends TestCase
{
    private string $root;
    private string $dir;
    private CountingComponentLoader $loader;
    private ComponentLoaderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/phue_childcost_' . bin2hex(random_bytes(6));
        $this->dir = $this->root . '/lib';
        mkdir($this->dir, 0777, true);

        $this->loader = new CountingComponentLoader(
            componentsDir: $this->dir,
            compilerBridge: new TestCompilerBridge(),
        );

        $this->registry = new ComponentLoaderRegistry();
        $this->registry->addLoader($this->loader);
        $this->loader->setRegistry($this->registry);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }

            @rmdir($this->root);
        }

        parent::tearDown();
    }

    private function write(string $name, string $template, string $title = 'T'): void
    {
        file_put_contents(
            $this->dir . '/' . $name . '.phue',
            "<template>\n{$template}\n</template>\n\n<php>\nreturn ['title' => '{$title}'];\n</php>\n"
        );
    }

    /**
     * Walks the graph rooted at $rootName and returns the loader's call counters.
     *
     * @return array{buildFor: int, moduleHash: int, hasComponent: int, nodes: int}
     */
    private function walk(string $rootName): array
    {
        $discoverer = new ComponentDiscoverer($this->registry);
        $graph = $discoverer->resolveTransitiveDependencies($rootName);

        $this->loader->resetCounters();
        $discoverer->resolveTransitiveDependencies($rootName);

        return [
            'buildFor' => $this->loader->buildForCalls,
            'moduleHash' => $this->loader->moduleHashCalls,
            'hasComponent' => $this->loader->hasComponentCalls,
            'nodes' => count($graph),
        ];
    }

    /**
     * The number of existence checks one full scan of every distinct template costs.
     *
     * This is the theoretical floor: a perfectly memoised traversal performs each
     * distinct template's scan exactly once, so it cannot issue fewer checks
     * than this. It is computed from the same public API the loader uses.
     */
    private function tagBudget(string $rootName): int
    {
        $discoverer = new ComponentDiscoverer($this->registry);
        $graph = $discoverer->resolveTransitiveDependencies($rootName);

        $budget = 0;
        foreach (array_keys($graph) as $name) {
            $structure = $this->loader->getStructure($name);
            if ($structure !== null) {
                $budget += count($discoverer->scanTemplate($structure['template']));
            }
        }

        return $budget;
    }

    // ------------------------------------------------------------- the guard

    /**
     * The invariant this file exists to protect: the cost of a full graph walk
     * must track the size of the graph, not the number of visits.
     *
     * ## Why the raw counter moved
     *
     * ComponentLoaderRegistry now short-circuits a candidate loader with
     * hasComponent() before asking it to build. That RAISES this counter
     * without touching the shape the test is guarding. Previously, a loader
     * that did not own a component was asked to build it anyway, threw "file
     * not found", and the registry caught the exception and moved on — one
     * exception construction, with a full stack trace, per (child, non-owning
     * loader) pair.
     *
     * Measured on the real PhUI graph (47 nodes, 27 of which live in the Phui
     * loader rather than the App loader), 30 full resolutions:
     *
     *     without the guard .... 41.04 ms per walk
     *     with the guard ....... 36.28 ms per walk
     *
     * The counter roughly doubled; the wall clock fell by ~12%. Asserting the
     * raw count against a tag-derived floor would therefore lock in a
     * regression, so the budget is expressed against the number of buildFor
     * entries instead — which is the quantity that actually grows when the
     * per-file child memo is removed.
     */
    public function testChildDiscoveryIsNotRepeatedForEveryBuildCall(): void
    {
        // A branching graph: 1 root -> 4 sections -> shared leaf.
        $this->write('Leaf', '<i>leaf</i>', 'Leaf');

        for ($s = 0; $s < 4; $s++) {
            $kids = '';
            for ($l = 0; $l < 4; $l++) {
                $kids .= '<Leaf />';
            }
            $this->write("Section{$s}", "<section>{$kids}</section>", "Section{$s}");
        }

        $rootKids = '';
        for ($s = 0; $s < 4; $s++) {
            $rootKids .= "<Section{$s} />";
        }
        $this->write('Root', "<main>{$rootKids}</main>", 'Root');

        $this->registry->beginRequest();
        $result = $this->walk('Root');

        $this->assertSame(6, $result['nodes'], 'fixture should resolve 6 components (root, 4 sections, shared leaf)');

        // A per-visit rescan shows up as a rising per-node ratio, which no single
        // absolute budget can distinguish from "merely expensive". Comparing two graph
        // sizes is the form that actually fails: quadratic work doubles the ratio when
        // the graph doubles, linear work leaves it flat.
        $small = $this->walkOnFreshGraph('Shallow', 3, 3);
        $large = $this->walkOnFreshGraph('Wider', 7, 7);

        $smallRatio = $small['hasComponent'] / max(1, $small['nodes']);
        $largeRatio = $large['hasComponent'] / max(1, $large['nodes']);

        $this->assertLessThanOrEqual(
            $smallRatio * 2,
            $largeRatio,
            sprintf(
                'Existence checks per node grew from %.1f (%d nodes) to %.1f (%d nodes) as the graph grew. '
                . 'A scan must happen once per distinct template; a count that climbs with graph size means '
                . 'templates are re-scanned on every visit.',
                $smallRatio,
                $small['nodes'],
                $largeRatio,
                $large['nodes']
            )
        );

        // The memo must actually be doing something: without it, cost scales with
        // the number of build calls, not with the number of distinct templates.
        $this->assertGreaterThan(
            1,
            $result['buildFor'],
            'sanity: the walk must revisit buildFor() more than once, otherwise this guard proves nothing'
        );
    }

    /**
     * The assertion that actually catches the regression, free of the
     * existence-check confounder above.
     *
     * buildFor() is entered once per referring edge plus once per node reached
     * through the resolver, so a linear traversal of a 3-node chain must not
     * produce more than 2 entries per node. Removing the per-file child memo
     * does not change that number, but removing any per-file memoisation does —
     * and, more importantly, a per-visit rescan would scale this with the
     * number of parents rather than the number of nodes.
     */
    public function testBuildCallsStayBoundedByGraphSizeNotByEdgeCount(): void
    {
        // Deep chain: every node has exactly one child, so edge count equals
        // node count and cannot itself expose a per-visit rescan.
        $this->write('L3', '<i>l3</i>', 'L3');
        $this->write('L2', '<p><L3 /></p>', 'L2');
        $this->write('L1', '<p><L2 /></p>', 'L1');

        $this->registry->beginRequest();
        $chain = $this->walk('L1');

        $this->assertSame(3, $chain['nodes'], 'the chain fixture should resolve exactly 3 components');

        $budget = $chain['nodes'] * 2;

        $this->assertLessThanOrEqual(
            $budget,
            $chain['buildFor'],
            sprintf(
                'A 3-node chain issued %d buildFor entries (budget %d = 2 per node). The child memo is keyed per '
                . 'file, so each distinct template should be built a bounded number of times regardless of how '
                . 'many parents reference it.',
                $chain['buildFor'],
                $budget
            )
        );
    }

    public function testRepeatWalksReuseTheMemoizedChildList(): void
    {
        $this->write('Alpha', '<div><Chip /><Badge /></div>', 'Alpha');
        $this->write('Chip', '<b>b</b>', 'Chip');
        $this->write('Badge', '<c>c</c>', 'Badge');

        $this->registry->beginRequest();
        $first = $this->walk('Alpha');

        // No beginRequest() in between: the child memo is process-lifetime BY
        // DESIGN (keyed by content hash), so a second walk must cost the same.
        $second = $this->walk('Alpha');

        $this->assertSame(
            $first['hasComponent'],
            $second['hasComponent'],
            'a second walk must reuse the memoized child list'
        );
    }

    public function testMemoSurvivesTheRequestBoundary(): void
    {
        $this->write('Alpha', '<div><Chip /></div>', 'Alpha');
        $this->write('Chip', '<b>b</b>', 'Chip');

        $this->registry->beginRequest();
        $before = $this->walk('Alpha');

        $this->registry->beginRequest();
        $after = $this->walk('Alpha');

        $this->assertSame(
            $before['hasComponent'],
            $after['hasComponent'],
            'the child memo must NOT be cleared by beginRequest(); doing so silently throws away the win'
        );
    }

    public function testClearMemoDropsTheChildMemo(): void
    {
        $this->write('Alpha', '<div><Chip /><Badge /></div>', 'Alpha');
        $this->write('Chip', '<b>b</b>', 'Chip');
        $this->write('Badge', '<c>c</c>', 'Badge');

        $this->registry->beginRequest();
        $this->walk('Alpha');

        $this->registry->clearMemo();
        $this->loader->resetCounters();
        (new ComponentDiscoverer($this->registry))->resolveTransitiveDependencies('Alpha');

        $this->assertGreaterThan(
            0,
            $this->loader->hasComponentCalls,
            'clearMemo() is the process-level flush and must drop the child memo too'
        );
    }


    // ------------------------------------------ child HASH resolution (distinct
    // ------------------------------------------------ from child discovery)

    /**
     * The guard this file was missing.
     *
     * Child DISCOVERY — the regex scan of a template — is memoised per file and
     * guarded above. Child HASH RESOLUTION is a different cost and is not guarded
     * anywhere in the suite: CountingComponentLoader has carried a moduleHashCalls
     * counter since it was written, and no test ever asserted on it.
     *
     * buildFor() resolves each child's hash through the registry BEFORE it reaches
     * its own memo check, because the memo's fingerprint is built from those child
     * hashes. So a visit to a node re-walks that node's whole subtree. The cost of
     * one walk is therefore the SUM of every subtree, which is quadratic in the
     * depth of the dependency graph — and it is invisible to the chain fixture
     * above, where nodes equal edges and quadratic is indistinguishable from linear.
     *
     * Measured on a branching graph (root -> N sections -> N leaves each), one
     * request each, counting the second walk:
     *
     *     nodes   buildFor   moduleHash   moduleHash per node
     *         5          15           15                    3.0
     *         7          28           28                    4.0
     *         9          45           45                    5.0
     *        13          91           91                    7.0
     *        17         153          153                    9.0
     *        21         231         231                   11.0
     *
     * buildFor() and moduleHash() move in lockstep because moduleHash() delegates to
     * buildFor(). The per-node ratio climbs by roughly one for every node added —
     * the signature of quadratic total work, since moduleHash settles at about
     * (n^2 + 2n) / 2. On the real PhUI graph the same shape produced 11,572 hash
     * resolutions and 3,882 builds for a single /phui request.
     */
    public function testChildHashResolutionIsNotRepeatedForEveryParentVisit(): void
    {
        $this->writeBranchingGraph(8, 8);

        $this->registry->beginRequest();
        $result = $this->walk('Root');

        $this->assertSame(17, $result['nodes'], 'fixture should resolve 17 components');

        // A memoised traversal enters each distinct node once: V entries from the
        // resolver plus at most E from the parents, and a tree has E = V - 1. Three per
        // node leaves room for both without giving the current behaviour room to pass:
        // 17 nodes currently issue 153.
        $budget = $result['nodes'] * 3;

        $this->assertLessThanOrEqual(
            $budget,
            $result['moduleHash'],
            sprintf(
                'A %d-node graph issued %d child hash resolutions (budget %d). Each distinct component must be '
                . 'hashed a bounded number of times per request; a count that scales with the depth of the graph '
                . 'means buildFor() re-walks each subtree on every visit.',
                $result['nodes'],
                $result['moduleHash'],
                $budget
            )
        );
    }

    /**
     * The shape assertion, which is the one that actually distinguishes a memoised
     * traversal from a re-walking one: the per-node ratio must not grow with the
     * graph. A single budget could be satisfied by an implementation that merely got
     * less bad; this cannot.
     */
    public function testHashResolutionPerNodeDoesNotGrowWithTheGraph(): void
    {
        $this->writeBranchingGraph(3, 3);
        $small = $this->walkOnFreshGraph('Small', 3, 3);

        $this->writeBranchingGraph(9, 9);
        $large = $this->walkOnFreshGraph('Large', 9, 9);

        $smallRatio = $small['moduleHash'] / max(1, $small['nodes']);
        $largeRatio = $large['moduleHash'] / max(1, $large['nodes']);

        $this->assertLessThanOrEqual(
            $smallRatio * 2,
            $largeRatio,
            sprintf(
                'Hash resolutions per node grew from %.1f (%d nodes) to %.1f (%d nodes) as the graph grew 3x. '
                . 'A per-node ratio that climbs with graph size is quadratic work.',
                $smallRatio,
                $small['nodes'],
                $largeRatio,
                $large['nodes']
            )
        );
    }

    /**
     * Sanity for the two guards above: a memoised traversal still has to DO the work,
     * so a fix that simply skips resolution would pass the budget and fail this.
     */
    public function testEveryNodeStillGetsItsHashResolved(): void
    {
        $this->writeBranchingGraph(4, 4);

        $this->registry->beginRequest();
        $before = $this->walk('Root');
        $after = $this->walk('Root');

        $this->assertGreaterThan(0, $before['moduleHash'], 'sanity: the walk must resolve child hashes at all');
        $this->assertSame(
            $before['moduleHash'],
            $after['moduleHash'],
            'a second walk in the same request must cost the same, not less'
        );
    }

    /**
     * A root referencing N sections, each referencing N leaves.
     */
    private function writeBranchingGraph(int $sections, int $leaves): void
    {
        for ($l = 0; $l < $leaves; $l++) {
            $this->write("Leaf{$l}", "<i>leaf {$l}</i>", "Leaf{$l}");
        }

        for ($s = 0; $s < $sections; $s++) {
            $kids = '';
            for ($l = 0; $l < $leaves; $l++) {
                $kids .= "<Leaf{$l} />";
            }
            $this->write("Section{$s}", "<section>{$kids}</section>", "Section{$s}");
        }

        $rootKids = '';
        for ($s = 0; $s < $sections; $s++) {
            $rootKids .= "<Section{$s} />";
        }
        $this->write('Root', "<main>{$rootKids}</main>", 'Root');
    }

    /**
     * Walk a graph under a distinct root name and return the loader's call counters.
     *
     * @return array{buildFor: int, moduleHash: int, hasComponent: int, nodes: int}
     */
    private function walkOnFreshGraph(string $root, int $sections, int $leaves): array
    {
        // Each root gets its own component names so the two measurements cannot share a
        // memo entry, which would make the second one look artificially cheap.
        $suffix = substr(sha1($root), 0, 6);

        for ($l = 0; $l < $leaves; $l++) {
            $this->write("{$root}Leaf{$suffix}{$l}", "<i>leaf</i>", "Leaf");
        }
        for ($s = 0; $s < $sections; $s++) {
            $kids = '';
            for ($l = 0; $l < $leaves; $l++) {
                $kids .= "<{$root}Leaf{$suffix}{$l} />";
            }
            $this->write("{$root}Section{$suffix}{$s}", "<section>{$kids}</section>", "Section");
        }
        $rootKids = '';
        for ($s = 0; $s < $sections; $s++) {
            $rootKids .= "<{$root}Section{$suffix}{$s} />";
        }
        $this->write($root, "<main>{$rootKids}</main>", 'Root');

        $this->registry->beginRequest();
        $discoverer = new ComponentDiscoverer($this->registry);
        $nodes = count($discoverer->resolveTransitiveDependencies($root));

        $this->loader->resetCounters();
        $discoverer->resolveTransitiveDependencies($root);

        return [
            'buildFor' => $this->loader->buildForCalls,
            'moduleHash' => $this->loader->moduleHashCalls,
            'hasComponent' => $this->loader->hasComponentCalls,
            'nodes' => $nodes,
        ];
    }

    // ------------------------------------------------- correctness of the memo

    public function testAddingAChildTagIsSeenImmediately(): void
    {
        $this->write('Alpha', '<div></div>', 'Alpha');
        $this->write('Chip', '<b>b</b>', 'Chip');

        $this->registry->beginRequest();
        $this->assertSame(['Alpha'], array_keys(
            (new ComponentDiscoverer($this->registry))->resolveTransitiveDependencies('Alpha')
        ));

        // Alpha is edited to reference Chip. Next request boundary.
        $this->write('Alpha', '<div><Chip /></div>', 'Alpha');
        $this->registry->beginRequest();

        $this->assertSame(
            ['Alpha', 'Chip'],
            array_keys((new ComponentDiscoverer($this->registry))->resolveTransitiveDependencies('Alpha')),
            'a memoized child list must be invalidated by a content change'
        );
    }

    public function testRemovingAChildTagIsSeenImmediately(): void
    {
        $this->write('Alpha', '<div><Chip /></div>', 'Alpha');
        $this->write('Chip', '<b>b</b>', 'Chip');

        $this->registry->beginRequest();
        $this->assertCount(2, (new ComponentDiscoverer($this->registry))->resolveTransitiveDependencies('Alpha'));

        $this->write('Alpha', '<div></div>', 'Alpha');
        $this->registry->beginRequest();

        $this->assertSame(
            ['Alpha'],
            array_keys((new ComponentDiscoverer($this->registry))->resolveTransitiveDependencies('Alpha')),
            'a removed child must disappear from the resolved graph'
        );
    }

    public function testEveryMintedUrlStaysServableAfterAContentChange(): void
    {
        $this->write('Kid', '<b>kid v1</b>', 'Kid');
        $this->write('Page', '<main><Kid /></main>', 'Page');

        $assertServable = function (string $context): void {
            $discoverer = new ComponentDiscoverer($this->registry);
            $graph = $discoverer->resolveTransitiveDependencies('Page');

            $this->assertNotEmpty($graph, "empty graph {$context}");

            foreach ($graph as $name => $hash) {
                $url = $this->registry->moduleUrl($name);
                $this->assertSame(
                    1,
                    preg_match('#^/assets/phue/(.+)\.([a-f0-9]{8})\.phue\.js$#', (string) $url, $m),
                    "bad URL for {$name} {$context}"
                );
                $this->assertSame($hash, $m[2], "advertised hash drifted for {$name} {$context}");
                $this->assertNotNull(
                    $this->registry->moduleFor($name, $hash),
                    "moduleFor() must serve what the page advertises for {$name} {$context}"
                );
            }
        };

        $this->registry->beginRequest();
        $assertServable('initial');

        $this->write('Kid', '<b>kid v2 changed</b>', 'Kid');
        $this->registry->beginRequest();
        $assertServable('after a child content change');
    }

    public function testTheChildMemoNeverStoresAnEmptyHash(): void
    {
        // The guard in buildFor() refuses to consult or populate the memo when
        // the content hash is empty, because an empty key "matches" itself: a
        // memo entry keyed by '' could never be invalidated and would pin a
        // stale child list for the life of the process.
        //
        // This asserts the observable invariant rather than simulating the
        // failure: sha1_file() returns '' only when the read fails, which is
        // practically unreachable here. What matters is that no entry can ever
        // be written with an empty key, so read the memo and check them all.
        $this->write('Alpha', '<div><Chip /><Badge /></div>', 'Alpha');
        $this->write('Chip', '<b>x</b>', 'Chip');
        $this->write('Badge', '<i>y</i>', 'Badge');

        $this->registry->beginRequest();
        (new ComponentDiscoverer($this->registry))->resolveTransitiveDependencies('Alpha');

        $prop = new ReflectionProperty(\Phue\Core\UI\PhueComponentLoader::class, 'templateChildrenMemo');
        $memo = $prop->getValue($this->loader);

        $this->assertNotEmpty($memo, 'sanity: the memo should have entries for this fixture');

        foreach ($memo as $path => $entry) {
            $this->assertNotSame(
                '',
                $entry['hash'],
                "memo entry for {$path} was written with an empty content hash"
            );
        }
    }

    public function testSingleLetterComponentNamesAreReservedForNativeHtmlTags(): void
    {
        // DESIGN DECISION, asserted so it stays visible and stays documented.
        //
        // ComponentDiscoverer::NATIVE_HTML_TAGS contains the single-letter HTML
        // elements, so a component named exactly A, B, I, P, Q, S, U or W is
        // classified as native markup and dropped from the dependency graph.
        //
        // This is deliberate. A .phue template IS HTML, so <b>, <i>, <p> and <a>
        // must mean the real elements with no opt-in flag and no escaping, and
        // the scanner has to case-fold the tag before comparing. The only
        // PascalCase names that can collide are the ones folding to a
        // single-letter HTML element — which is precisely why the kit prefixes
        // everything with `Ph`.
        //
        // If you ever change this behaviour, three things have to move together:
        // this test, docs/PhUI_Components.md section 1.1.1, and the naming
        // contract in .opencode/phui.md.
        $this->write('Bold', '<b>bold</b>', 'Bold');
        $this->write('B', '<span>a component literally named B</span>', 'B');

        $this->registry->beginRequest();

        $discoverer = new ComponentDiscoverer($this->registry);
        $graph = $discoverer->resolveTransitiveDependencies('Bold');

        $this->assertArrayNotHasKey(
            'B',
            $graph,
            'a component named "B" is indistinguishable from the <b> HTML tag and is not discovered'
        );
        $this->assertSame(['Bold'], array_keys($graph));
    }

    // ------------------------------------------------- the same invariant, two loaders

    /**
     * Every cost assertion in this file uses a single loader, which is why they pass.
     *
     * The application wires TWO: App/Frontend and the PhUI library, and components in
     * one routinely reference components in the other. PhueComponentLoader::componentIdentity()
     * answers null for a name outside its own index — correctly, because a loader cannot
     * re-hash a file it does not own — and branchUnchanged() treats that null as "changed".
     * So the child hash memo is never written for a cross-loader child, and every such edge
     * is a permanent miss for the whole request.
     *
     * Measured on the real /phui page: 1227 buildFor entries for 142 distinct components,
     * 957 of them reached by re-entering moduleHash from inside buildFor.
     */
    public function testChildHashResolutionStaysBoundedWhenChildrenLiveInAnotherLoader(): void
    {
        $graph = $this->writeCrossLoaderGraph(4, 4);

        $counters = $this->walkCrossLoader();

        $this->assertSame(17, $counters['nodes'], 'fixture should resolve 17 components');

        // The same budget the single-loader guard uses. It is a statement about the
        // algorithm, not about the wiring: a hash per distinct node plus one per edge is
        // the floor, and a graph that grows past three per node is being re-walked.
        $budget = $counters['nodes'] * 3;

        $this->assertLessThanOrEqual(
            $budget,
            $counters['moduleHash'],
            sprintf(
                'A %d-node graph spread over two loaders issued %d child hash resolutions (budget %d). A child held '
                . 'by the other loader must be resolved once per request, not once per referring edge.',
                $counters['nodes'],
                $counters['moduleHash'],
                $budget
            )
        );

        $this->assertGreaterThan(0, $graph['crossEdges'], 'fixture should contain cross-loader edges');
    }

    /**
     * A memo that hit is only worth having if it can still miss.
     *
     * Editing a component one level further down must change the root's module hash. This
     * is the guard on every fix for the test above: a cache that trusts a cross-loader
     * child without being able to confirm it would serve a stale subtree forever, and the
     * profiler would keep reporting the page as warm.
     */
    public function testAnEditInTheOtherLoaderStillReachesTheRootHash(): void
    {
        $graph = $this->writeCrossLoaderGraph(4, 4);
        $target = $graph['libNames'][0];

        $before = $this->buildRoot();
        file_put_contents(
            $this->libDir . '/' . $target . '.phue',
            "<template>\n<span>edited</span>\n</template>\n<script>export default {}</script>\n"
        );
        $this->registry->beginRequest();
        $after = $this->buildRoot();

        $this->assertNotSame(
            $before,
            $after,
            'Editing a component in the other loader left the root hash untouched: a cross-loader memo is being trusted '
            . 'without confirming the file behind it.'
        );
    }

    /**
     * The guard that makes the registry-aware identity sound rather than merely faster.
     *
     * A parent's memo is trusted only after every recorded descendant still matches. Once a
     * cross-loader child can be confirmed, its subtree has to be walkable too — otherwise
     * the parent records an incomplete subtree, confirms it against itself, and serves a
     * stale hash for a grandchild that lives in the other loader.
     *
     * The chain here is App Root -> Lib Mid -> Lib Deep, so the edited file is two levels
     * below the root and one level below the child whose hash the root memoises.
     */
    public function testAGrandchildEditInTheOtherLoaderReachesTheRootHash(): void
    {
        $this->wireTwoLoaders();

        file_put_contents($this->dir . '/Root.phue', "<template>\n<mid />\n</template>\n<script>export default {}</script>\n");
        file_put_contents($this->libDir . '/Mid.phue', "<template>\n<deep />\n</template>\n<script>export default {}</script>\n");
        file_put_contents($this->libDir . '/Deep.phue', "<template>\n<span>v1</span>\n</template>\n<script>export default {}</script>\n");

        $before = $this->loader->buildFor('Root');

        file_put_contents($this->libDir . '/Deep.phue', "<template>\n<span>v2 changed</span>\n</template>\n<script>export default {}</script>\n");

        // No request boundary: the loader must see the change on the next resolution, in
        // the other loader, two levels down.
        $after = $this->loader->buildFor('Root');

        $this->assertNotSame(
            $before,
            $after,
            'A grandchild edit inside the other loader did not reach the root hash: the parent confirmed an incomplete subtree.'
        );
    }

    public function testASecondRequestSeesAnEditMadeBetweenRequests(): void
    {
        $graph = $this->writeCrossLoaderGraph(3, 3);
        $target = $graph['libNames'][0];

        $before = $this->buildRoot();
        file_put_contents(
            $this->libDir . '/' . $target . '.phue',
            "<template>\n<span>edited</span>\n</template>\n<script>export default {}</script>\n"
        );
        $this->registry->beginRequest();
        $after = $this->buildRoot();

        $this->assertNotSame($before, $after, 'An edit between requests was not observed.');
    }

    // ------------------------------------------------ cross-loader fixtures

    private CountingComponentLoader $libLoader;
    private string $libDir;

    /**
     * Two loaders, cross-linked through one registry, exactly as Application::bindings
     * wires App/Frontend and the PhUI library.
     */
    private function wireTwoLoaders(): void
    {
        $this->libDir = $this->root . '/lib2';
        if (!is_dir($this->libDir)) {
            mkdir($this->libDir, 0777, true);
        }

        $this->libLoader = new CountingComponentLoader(
            componentsDir: $this->libDir,
            compilerBridge: new TestCompilerBridge(),
        );

        $registry = new ComponentLoaderRegistry();
        $registry->addLoader($this->loader);
        $registry->addLoader($this->libLoader);
        $this->loader->setRegistry($registry);
        $this->libLoader->setRegistry($registry);

        $this->registry = $registry;
    }

    /**
     * Writes a tree whose components alternate between the two loaders, so that no edge
     * can be resolved without going through the registry.
     *
     * Nodes are N{level}{index}; the root is N00 and always lives in the app loader, so a
     * test can build it through the loader it registered. Ownership alternates on
     * (level + index), which keeps both directions represented at every level.
     *
     * @return array{nodes: int, crossEdges: int, libNames: list<string>}
     */
    private function writeCrossLoaderGraph(int $fanout, int $depth): array
    {
        $this->wireTwoLoaders();

        $known = ['N00' => true];
        $queue = [[0, 0]];
        $edges = 0;
        $libNames = [];

        while ($queue !== []) {
            [$level, $index] = array_shift($queue);
            $name = 'N' . $level . $index;
            $tags = [];

            for ($i = 0; $i < $fanout; $i++) {
                $child = 'N' . ($level + 1) . $i;

                if (!isset($known[$child])) {
                    $known[$child] = true;

                    // Queue every child so its file gets written; nodes at the deepest
                    // level are simply never expanded.
                    if ($level + 1 <= $depth) {
                        $queue[] = [$level + 1, $i];
                    }
                }

                $tags[] = '<' . strtolower($child) . ' />';
            }

            $edges += $fanout;

            $inLib = $name !== 'N00' && ($level + $index) % 2 === 0;
            $dir = $inLib ? $this->libDir : $this->dir;

            if ($inLib) {
                $libNames[] = $name;
            }

            file_put_contents(
                $dir . '/' . $name . '.phue',
                "<template>\n" . implode('', $tags) . "\n</template>\n<script>export default {}</script>\n"
            );
        }

        return ['nodes' => count($known), 'crossEdges' => $edges, 'libNames' => $libNames];
    }

    /**
     * @return array{moduleHash: int, buildFor: int, nodes: int}
     */
    private function walkCrossLoader(string $rootName = 'N00'): array
    {
        $this->registry->beginRequest();

        $discoverer = new ComponentDiscoverer($this->registry);
        $graph = $discoverer->resolveTransitiveDependencies($rootName);

        $this->loader->resetCounters();
        $this->libLoader->resetCounters();
        $discoverer->resolveTransitiveDependencies($rootName);

        return [
            'moduleHash' => $this->loader->moduleHashCalls + $this->libLoader->moduleHashCalls,
            'buildFor' => $this->loader->buildForCalls + $this->libLoader->buildForCalls,
            'nodes' => count($graph),
        ];
    }

    private function buildRoot(string $rootName = 'N00'): ?string
    {
        return $this->loader->buildFor($rootName);
    }
}
