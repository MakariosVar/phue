<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\ComponentDiscoverer;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhueTemplateParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * ComponentDiscovererTest — locks the auto-discovery contract:
 *  - only KNOWN components (resolvable via the registry) are discovered,
 *  - native HTML + Vue builtin tags are ignored,
 *  - kebab-case tags normalize to PascalCase before the catalog lookup,
 *  - duplicate tags collapse to a single entry and the result is sorted,
 *  - a self-reference is dropped from a component's own children, so the
 *    component builds without importing itself; genuine cross-component cycles
 *    (A -> B -> A) are still rejected loudly, because those have no valid module.
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

        // Self-referencing component: a legitimate shape (a tree node rendering
        // its own tag), so it must build — and must not import itself.
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
     * A self-referencing component is COMPILABLE, and the one invariant that
     * makes it so is that it must not import itself.
     *
     * The component is already in the module being built, so its own tag is not
     * a dependency. Listing it as a child used to make buildFor() recurse into
     * itself and throw — which is right for a genuine cycle but wrong here: a
     * recursive component (a tree node rendering its own tag, a menu rendering
     * its own submenu) is a legitimate shape, and rejecting it left the whole
     * component unusable.
     *
     * The failure this guards against is silent and specific: if the self-child
     * were merely skipped at build time but still emitted as an import, the
     * module would import itself — a real ESM cycle, and one that renders fine
     * until the browser resolves it. So the assertion is on the emitted module
     * text, not on an exception.
     *
     * Genuine cycles — A -> B -> A across two components — are still rejected
     * loudly; see testIndirectCycleIsRejectedLoudlyAcrossComponents(). There is
     * no valid module for those, so no such narrowing is available.
     *
     * These run inline because the build-path guard sits upstream of the
     * recursion. If that guard is ever moved back behind the resolution pass,
     * this file will SIGSEGV and kill the run; the crash itself is guarded by
     * ComponentCompilationCostTest::testACircularComponentReferenceIsReportedInsteadOfCrashing,
     * which runs the same shape in a subprocess.
     */
    public function testSelfReferenceIsExcludedFromItsOwnChildrenRatherThanImportedIntoItself(): void
    {
        $template = (new PhueTemplateParser())->parseStructure($this->compsDir . '/SelfRef.phue')['template'];

        // Without the owner name the self-tag is still found — it is a real tag in
        // the template. Discovery only drops it when it knows who is asking.
        $this->assertSame(
            ['SelfRef'],
            $this->discoverer->scanTemplate($template),
            'Scan must stay honest: the self-tag is in the template until the owner is named.'
        );

        $this->assertSame(
            [],
            $this->discoverer->scanTemplate($template, 'SelfRef'),
            'A component must not be reported as its own child; there is nothing to import.'
        );

        $module = $this->comps->buildFor('SelfRef');
        $this->assertIsString($module, 'A self-referencing component must still produce a module.');

        $this->assertDoesNotMatchRegularExpression(
            '/(?:import|from)[^;\n]*SelfRef/',
            $module,
            'The module must not import itself. This is the whole invariant: a self-import is a real ESM '
            . 'cycle, and it is the one failure mode that still renders a plausible page.'
        );

        $url = $this->registry->moduleUrl('SelfRef');
        $this->assertIsString($url, 'A compilable component must be servable.');
        $this->assertStringEndsWith('.phue.js', $url);

        $hash = $this->registry->moduleHash('SelfRef');
        $this->assertIsString($hash);

        $this->assertIsString(
            $this->registry->moduleFor('SelfRef', $hash),
            'A compilable component must be servable, not merely buildable.'
        );

        // The hash is a cache key, not a formality: the wrong one must not serve.
        $this->assertNull(
            $this->registry->moduleFor('SelfRef', str_repeat('0', 8)),
            'A stale or wrong hash must not resolve to a module.'
        );

        // The graph walk must terminate rather than recurse forever, and the
        // component belongs in its own dependency graph exactly once.
        $graph = $this->discoverer->resolveTransitiveDependencies('SelfRef');
        $this->assertSame(['SelfRef'], array_keys($graph));
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