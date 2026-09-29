<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\ComponentDiscoverer;
use Phue\Core\UI\PhueComponentLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * ComponentDiscovererTest — locks the auto-discovery contract:
 *  - only KNOWN components (resolvable via the registry) are discovered,
 *  - native HTML + Vue builtin tags are ignored,
 *  - kebab-case tags normalize to PascalCase before the catalog lookup,
 *  - duplicate tags collapse to a single entry and the result is sorted,
 *  - transitive traversal across loaders terminates even on self-references.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('unit')]
final class ComponentDiscovererTest extends TestCase
{
    private string $viewsDir;
    private string $compsDir;
    private ComponentLoaderRegistry $registry;
    private ComponentDiscoverer $discoverer;
    private PhueComponentLoader $pages;
    private PhueComponentLoader $comps;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . '/phue_discoverer_' . uniqid('', true);
        $this->viewsDir = $base . '/pages';
        $this->compsDir = $base . '/comps';
        mkdir($this->viewsDir, 0777, true);
        mkdir($this->compsDir, 0777, true);

        $this->writeFixture($this->compsDir . '/PhButton.phue', <<<'PHUE'
<template>
<button>{{ label }}</button>
</template>

<script>
export default {
    props: { label: { type: String, default: '' } }
};
</script>
PHUE);

        $this->writeFixture($this->compsDir . '/MyWidget.phue', <<<'PHUE'
<template>
<span><slot/></span>
</template>
PHUE);

        $this->writeFixture($this->compsDir . '/PhBox.phue', <<<'PHUE'
<template>
<div><slot/></div>
</template>
PHUE);

        // Self-referencing component: must terminate once the cheap
        // existence check replaces the moduleUrl gate.
        $this->writeFixture($this->compsDir . '/SelfRef.phue', <<<'PHUE'
<template>
<div><self-ref/><slot/></div>
</template>
PHUE);

        // Indirect cycle PhNode -> PhLeaf -> PhNode: recovery from nested cycles.
        // (Single-letter names collide with native tags <a>/<b>, so PascalCase.)
        $this->writeFixture($this->compsDir . '/PhNode.phue', <<<'PHUE'
<template>
<div><ph-leaf/></div>
</template>
PHUE);

        $this->writeFixture($this->compsDir . '/PhLeaf.phue', <<<'PHUE'
<template>
<div><ph-node/></div>
</template>
PHUE);

        // Page that pulls a component from another loader directory.
        $this->writeFixture($this->viewsDir . '/Showcase.phue', <<<'PHUE'
<template>
<div><ph-button label="x"/><my-widget/></div>
</template>
PHUE);

        $this->registry = new ComponentLoaderRegistry();
        $this->pages = TestComponentLoader::make($this->viewsDir);
        $this->comps = TestComponentLoader::make($this->compsDir);
        $this->pages->setRegistry($this->registry);
        $this->comps->setRegistry($this->registry);
        $this->registry->addLoader($this->pages);
        $this->registry->addLoader($this->comps);

        $this->discoverer = new ComponentDiscoverer($this->registry);
    }

    protected function tearDown(): void
    {
        $this->removeTree(dirname($this->viewsDir));
    }

    public function testNativeHtmlAndVueBuiltinTagsAreIgnored(): void
    {
        $template = '<div class="x"><button>B</button><input/><slot/><transition name="f"/><template v-if="y">t</template><span>s</span><h1>H</h1></div>';

        $this->assertSame([], $this->discoverer->scanTemplate($template));
    }

    public function testUnknownCustomTagsAreExcludedFromCatalog(): void
    {
        $template = '<not-a-component/><ph-unknown></ph-unknown>';

        $this->assertSame([], $this->discoverer->scanTemplate($template));
    }

    public function testKnownTagsAreNormalizedDeduplicatedAndSorted(): void
    {
        $template = '<ph-button/><my-widget/><ph-box/><ph-button/><ph-button/>';

        $this->assertSame(['MyWidget', 'PhBox', 'PhButton'], $this->discoverer->scanTemplate($template));
    }

    public function testKebabCaseTagResolvesToPascalCaseCatalogEntry(): void
    {
        $this->assertSame(['MyWidget'], $this->discoverer->scanTemplate('<my-widget/>'));
    }

    public function testTransitiveResolutionCrossesLoadersAndTerminates(): void
    {
        $graph = $this->discoverer->resolveTransitiveDependencies('Showcase');

        $this->assertArrayHasKey('Showcase', $graph);
        $this->assertArrayHasKey('PhButton', $graph);
        $this->assertArrayHasKey('MyWidget', $graph);
        $this->assertSame(3, count($graph));
    }

    /**
     * A component that references itself (directly or indirectly) is not
     * compilable: a module that dropped the cyclic self-child would import a
     * tag it never registered, and the page would render with a silent hole.
     *
     * The contract is fail-loud, and "loud" means an exception. This test used
     * to assert assertNull() on all four entry points, which is exactly the
     * failure mode the suite is supposed to prevent: a null from a cycle is
     * indistinguishable from a null from a component that simply does not
     * exist, so the assertions were green while the page rendered 200 with a
     * hole in it and nothing in the response to say why.
     *
     * ComponentLoaderRegistry::handleLoaderFailure() now re-throws
     * CircularComponentReferenceException from every fan-out method, and the
     * exception message carries the full A -> B -> A chain — the only part of
     * this failure a developer can act on.
     *
     * These run inline because the build-path guard sits upstream of the
     * recursion. If that guard is ever moved back behind the resolution pass,
     * this file will SIGSEGV and kill the run; the crash itself is guarded by
     * ComponentCompilationCostTest::testACircularComponentReferenceIsReportedInsteadOfCrashing,
     * which runs the same shape in a subprocess.
     */
    public function testSelfReferenceIsRejectedLoudlyInsteadOfSilentlyTruncated(): void
    {
        $this->assertThrowsCycle(
            fn() => $this->comps->buildFor('SelfRef'),
            'SelfRef',
            'A self-referential component has no valid module, and saying so must not look like a missing one.'
        );

        $this->assertThrowsCycle(
            fn() => $this->registry->moduleUrl('SelfRef'),
            'SelfRef',
            'Fail-loud: no URL may be minted — and none may be merely withheld — for a component that cannot be '
            . 'built. Returning null here is what made a cycle indistinguishable from a missing component.'
        );

        $this->assertThrowsCycle(
            fn() => $this->registry->moduleFor('SelfRef', str_repeat('0', 8)),
            'SelfRef',
            'Nothing may be served for a component that could not be built.'
        );

        // The resolver used to be the odd one out: it returned an empty graph
        // and let the page-level build fail later. It calls moduleUrl() while
        // walking, so it now propagates like everything else — and that is the
        // better outcome, because the exception already carries the chain. An
        // empty graph would have deferred the same failure to a point where
        // the message no longer had the component names in it.
        $this->assertThrowsCycle(
            fn() => $this->discoverer->resolveTransitiveDependencies('SelfRef'),
            'SelfRef',
            'A cycle must not be reported as an empty graph. An empty graph defers the failure to a caller that no '
            . 'longer has the chain in hand, which is how a cycle ends up as a nameless 500.'
        );
    }

    public function testIndirectCycleIsRejectedLoudlyAcrossComponents(): void
    {
        $this->assertThrowsCycle(
            fn() => $this->registry->moduleUrl('PhNode'),
            'PhNode',
            'The cycle must surface from the registry, not be swallowed into a null by its catch block.'
        );

        $this->assertThrowsCycle(
            fn() => $this->registry->moduleUrl('PhLeaf'),
            'PhLeaf',
            'The same from the far side of the cycle.'
        );

        $this->assertThrowsCycle(
            fn() => $this->comps->buildFor('PhNode'),
            'PhNode',
            'And from the loader directly, so a caller bypassing the registry still learns about it.'
        );

        $this->assertThrowsCycle(
            fn() => $this->discoverer->resolveTransitiveDependencies('PhNode'),
            'PhNode',
            'The dependency walk propagates too, carrying the chain.'
        );
    }

    /**
     * Asserts that a closure raises a CircularComponentReferenceException whose
     * message names the given component.
     *
     * The message assertion is not decoration. A cycle exception without the
     * chain tells a developer only that something is wrong somewhere; the
     * chain is the entire diagnostic value, and losing it turns a two-minute
     * fix into a hunt through the whole component tree.
     *
     * @param callable(): mixed $operation
     */
    private function assertThrowsCycle(callable $operation, string $expectedComponent, string $message): void
    {
        try {
            $operation();
        } catch (\Phue\Core\UI\Exceptions\CircularComponentReferenceException $e) {
            $this->assertStringContainsString(
                $expectedComponent,
                $e->getMessage(),
                'The cycle message must name the components in the chain. An unnamed cycle exception is not a '
                . 'diagnosis, it is a notification.'
            );

            return;
        }

        $this->fail($message);
    }

    public function testACycleDoesNotContaminateUnrelatedComponents(): void
    {
        // Fail-loud must stay contained: a broken subtree may not poison the
        // components that have nothing to do with it. The cycle is triggered
        // through a throwing call now, so the containment it used to assert
        // for free — "the registry returned null and carried on" — has to be
        // asked for explicitly: the throw must unwind out of moduleUrl() and
        // the walk must still be usable afterwards.
        $this->assertThrowsCycle(
            fn() => $this->registry->moduleUrl('PhNode'),
            'PhNode',
            'Triggering the cycle must not leave the registry in a state where the next lookup is affected.'
        );

        $this->assertNotNull(
            $this->registry->moduleUrl('Showcase'),
            'A page that never touches the cycle must still build. The throw unwound cleanly, so the registry is '
            . 'still usable and unrelated components resolve normally.'
        );
        $this->assertNotNull($this->comps->buildFor('PhButton'));
        $this->assertNotNull($this->comps->buildFor('MyWidget'));
        $this->assertNotNull($this->comps->buildFor('PhBox'));

        // And the registry still reports the cycle on a second attempt rather
        // than having cached the failure as "does not exist".
        $this->assertThrowsCycle(
            fn() => $this->registry->moduleUrl('PhNode'),
            'PhNode',
            'A cycle must stay a cycle across repeated lookups. If this returns null the second time, the throw is '
            . 'being memoised as a miss and the original defect is back with better manners.'
        );
    }

    public function testComponentResolutionSurvivesACycleInTheRegistry(): void
    {
        // load() parses and evaluates the <php> block only; it never builds a
        // module. So the cycle must not break the PHP side of a component -
        // the failure is confined to the module/asset stage, where it can be
        // reported against the component that actually caused it.
        $component = $this->comps->load('SelfRef');

        $this->assertSame('Phue App', $component->title);
    }

    public function testUnknownRootYieldsEmptyGraph(): void
    {
        $this->assertSame([], $this->discoverer->resolveTransitiveDependencies('Ghost'));
    }

    private function writeFixture(string $path, string $content): void
    {
        file_put_contents($path, $content);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}