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
     * The contract is therefore fail-loud - no module, no URL.
     *
     * These run inline because the build-path guard is now upstream of the
     * recursion. If that guard is ever moved back behind the resolution pass,
     * this file will SIGSEGV and kill the run; the crash itself is guarded by
     * ComponentCompilationCostTest::testACircularComponentReferenceIsReportedInsteadOfCrashing,
     * which runs the same shape in a subprocess.
     */
    public function testSelfReferenceIsRejectedLoudlyInsteadOfSilentlyTruncated(): void
    {
        $this->assertNull(
            $this->comps->buildFor('SelfRef'),
            'A self-referential component has no valid module.'
        );
        $this->assertNull(
            $this->registry->moduleUrl('SelfRef'),
            'Fail-loud: no URL may be minted for a component that cannot be built.'
        );
        $this->assertNull(
            $this->registry->moduleFor('SelfRef', str_repeat('0', 8)),
            'Nothing may be served for a component that could not be built.'
        );
        $this->assertSame(
            [],
            $this->discoverer->resolveTransitiveDependencies('SelfRef'),
            'A graph rooted at a cycle carries no trustworthy hashes, so it is empty.'
        );
    }

    public function testIndirectCycleIsRejectedLoudlyAcrossComponents(): void
    {
        $this->assertNull($this->registry->moduleUrl('PhNode'));
        $this->assertNull($this->registry->moduleUrl('PhLeaf'));
        $this->assertNull($this->comps->buildFor('PhNode'));
        $this->assertNull($this->comps->buildFor('PhLeaf'));
        $this->assertSame([], $this->discoverer->resolveTransitiveDependencies('PhNode'));
    }

    public function testACycleDoesNotContaminateUnrelatedComponents(): void
    {
        // Fail-loud must stay contained: a broken subtree may not poison the
        // components that have nothing to do with it.
        $this->registry->moduleUrl('PhNode');

        $this->assertNotNull(
            $this->registry->moduleUrl('Showcase'),
            'A page that never touches the cycle must still build.'
        );
        $this->assertNotNull($this->comps->buildFor('PhButton'));
        $this->assertNotNull($this->comps->buildFor('MyWidget'));
        $this->assertNotNull($this->comps->buildFor('PhBox'));
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