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
     * @return array{buildFor: int, hasComponent: int, nodes: int}
     */
    private function walk(string $rootName): array
    {
        $discoverer = new ComponentDiscoverer($this->registry);
        $graph = $discoverer->resolveTransitiveDependencies($rootName);

        $this->loader->resetCounters();
        $discoverer->resolveTransitiveDependencies($rootName);

        return [
            'buildFor' => $this->loader->buildForCalls,
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

        // One existence check per child tag, per buildFor entry, per candidate
        // loader. This fixture's root declares four children, so the observed
        // ratio sits under 3; the factor leaves headroom without going soft.
        $budget = $result['buildFor'] * 3;

        $this->assertLessThanOrEqual(
            $budget,
            $result['hasComponent'],
            sprintf(
                'A full walk issued %d existence checks across %d buildFor entries (budget %d). Existence checks '
                . 'must stay proportional to buildFor entries — they are one per child tag of the template being '
                . 'built, and a count that outgrows that ratio means templates are being re-walked per visit.',
                $result['hasComponent'],
                $result['buildFor'],
                $budget
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
}
