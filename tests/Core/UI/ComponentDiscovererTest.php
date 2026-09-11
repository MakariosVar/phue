<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\ComponentDiscoverer;
use Phue\Core\UI\PhueComponentLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

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
        $this->pages = new PhueComponentLoader($this->viewsDir);
        $this->comps = new PhueComponentLoader($this->compsDir);
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
     * A component that references itself (directly or indirectly) must not
     * blow the stack: the discovered graph terminates and the module builder
     * drops the cyclic self-child from the imports (build-path guard).
     */
    public function testSelfReferenceTerminates(): void
    {
        $graph = $this->discoverer->resolveTransitiveDependencies('SelfRef');
        $this->assertSame(['SelfRef'], array_keys($graph));

        $module = $this->comps->buildFor('SelfRef');
        $this->assertNotNull($module, 'Self-referential module must still build.');
        $this->assertStringNotContainsString(
            '/assets/phue/SelfRef.',
            $module,
            'The cyclic self-child must be dropped from the module imports (build guard terminates).'
        );

        $this->assertNotNull($this->registry->moduleUrl('SelfRef'));
    }

    public function testIndirectCycleTerminatesAcrossComponents(): void
    {
        $graph = $this->discoverer->resolveTransitiveDependencies('PhNode');
        $this->assertSame(['PhNode', 'PhLeaf'], array_keys($graph));

        $this->assertNotNull($this->registry->moduleUrl('PhNode'));
        $this->assertNotNull($this->registry->moduleUrl('PhLeaf'));
        $this->assertNotNull($this->comps->buildFor('PhNode'));
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