<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\ComponentDiscoverer;
use Phue\Core\UI\PhueComponentLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PhueComponentDependencyTest — locks the auto-import module contract:
 *  - children are discovered in the page template and imported by the
 *    registry-resolved RAW hash, so children may live in another loader,
 *  - each import wraps the raw default export + raw template export into a
 *    merged const that browsers can register directly,
 *  - the proxy merge keeps `components` LAST so discovery never overrides
 *    the author's explicit component options,
 *  - minting == serving: the module minted for a URL is what that URL returns,
 *    and a child content change propagates to the parent full-module hash
 *    while the parent raw hash stays stable,
 *  - template-only components get a functioning default export,
 *  - unknown components / wrong hashes are rejected (null).
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('unit')]
final class PhueComponentDependencyTest extends TestCase
{
    private string $baseDir;
    private string $viewsDir;
    private string $compsDir;
    private ComponentLoaderRegistry $registry;
    private PhueComponentLoader $pages;
    private PhueComponentLoader $comps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/phue_deps_' . uniqid('', true);
        $this->viewsDir = $this->baseDir . '/pages';
        $this->compsDir = $this->baseDir . '/comps';
        mkdir($this->viewsDir, 0777, true);
        mkdir($this->compsDir, 0777, true);

        $this->writeFixture($this->viewsDir . '/Home.phue', <<<'PHUE'
<template>
<div><ph-button label="Go"/><bare></bare></div>
</template>

<script>
import { createSSRApp } from 'vue';
const mountApp = async (el, opts = {}) => {
    const app = createSSRApp({ setup: () => ({}) }, opts);
    app.mount(el);
};
export { createSSRApp, mountApp };
</script>
PHUE);

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

        $this->writeFixture($this->compsDir . '/Bare.phue', <<<'PHUE'
<template>
<span><slot/></span>
</template>
PHUE);

        $this->registry = new ComponentLoaderRegistry();
        $this->pages = new PhueComponentLoader($this->viewsDir);
        $this->comps = new PhueComponentLoader($this->compsDir);
        $this->pages->setRegistry($this->registry);
        $this->comps->setRegistry($this->registry);
        $this->registry->addLoader($this->pages);
        $this->registry->addLoader($this->comps);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->baseDir);
    }

    public function testCrossLoaderChildrenAreImportedWithRegistryRawHash(): void
    {
        $module = $this->pages->buildFor('Home');
        $this->assertNotNull($module);

        foreach (['PhButton', 'Bare'] as $child) {
            preg_match(
                "/__phueRaw_{$child}, \{ __phueTemplate as __phueTpl_{$child} \} from '([^']+)'/",
                $module,
                $matches
            );

            $this->assertArrayHasKey(1, $matches, "Missing import line for {$child}.");

            $rawHash = $this->registry->rawModuleHash($child);
            $this->assertNotNull($rawHash, "Registry must resolve the raw hash for {$child} across loaders.");
            $this->assertSame("/assets/phue/{$child}.{$rawHash}.phue.js", $matches[1]);

            $this->assertStringContainsString(
                "const {$child} = { ...(__phueRaw_{$child} || {}), template: __phueTpl_{$child} };",
                $module
            );
        }
    }

    public function testServingUrlMatchesMintedLeafModule(): void
    {
        $moduleUrl = $this->registry->moduleUrl('PhButton');
        $this->assertNotNull($moduleUrl);

        preg_match('/\.([a-f0-9]{8})\.phue\.js$/', $moduleUrl, $matches);

        $served = $this->registry->moduleFor('PhButton', $matches[1]);
        $this->assertNotNull($served);
        $this->assertSame($this->comps->buildFor('PhButton'), $served);
    }

    public function testProxyMergeKeepsComponentsLast(): void
    {
        $module = $this->pages->buildFor('Home');
        $this->assertNotNull($module);

        $this->assertStringContainsString(
            'template: __phueTemplate, ...opts, components: { ...(opts.components || {}), Bare, PhButton }',
            $module,
            'Component options from discovery must merge strictly after ...opts.'
        );

        $this->assertStringStartsWith("import __phueRaw_Bare", $module);
        $this->assertStringContainsString('export const __phueTemplate = ', $module);
        $this->assertStringContainsString('const mountApp = async', $module);
    }

    public function testChildContentChangePropagatesToParentFullHashOnly(): void
    {
        $homeUrlBefore = $this->registry->moduleUrl('Home');
        $homeRawBefore = $this->registry->rawModuleHash('Home');
        $this->assertNotNull($homeUrlBefore);
        $this->assertNotNull($homeRawBefore);

        $this->assertStringContainsString(
            "/assets/phue/PhButton.{$this->registry->rawModuleHash('PhButton')}.phue.js",
            $this->pages->buildFor('Home')
        );

        // Larger file size guarantees the loader's mtime+size cache misses.
        $this->writeFixture($this->compsDir . '/PhButton.phue', <<<'PHUE'
<template>
<button class="primary branded">{{ label }}</button>
</template>

<script>
export default {
    props: { label: { type: String, default: 'Go' } }
};
</script>
PHUE);

        $homeUrlAfter = $this->registry->moduleUrl('Home');

        $this->assertNotNull($homeUrlAfter);
        $this->assertNotSame($homeUrlBefore, $homeUrlAfter, 'Parent module URL must react to child content.');
        $this->assertSame($homeRawBefore, $this->registry->rawModuleHash('Home'), 'Parent raw hash must stay leaf-only.');

        $this->assertStringContainsString(
            "/assets/phue/PhButton.{$this->registry->rawModuleHash('PhButton')}.phue.js",
            $this->pages->buildFor('Home')
        );
    }

    public function testTemplateOnlyComponentShipsDefaultExport(): void
    {
        $module = $this->comps->buildFor('Bare');
        $this->assertNotNull($module);

        $this->assertStringStartsWith('export const __phueTemplate = ', $module);
        $this->assertStringContainsString('export default { template: __phueTemplate };', $module);
    }

    public function testUnknownComponentAndWrongHashAreRejected(): void
    {
        $this->assertNull($this->pages->buildFor('Ghost'));
        $this->assertNull($this->registry->moduleUrl('Ghost'));
        $this->assertNull($this->registry->rawModuleHash('Ghost'));

        $url = $this->registry->moduleUrl('PhButton');
        $this->assertNotNull($url);
        $this->assertMatchesRegularExpression('/\.([a-f0-9]{8})\.phue\.js$/', $url);

        preg_match('/\.([a-f0-9]{8})\.phue\.js$/', $url, $matches);

        $this->assertNull($this->registry->moduleFor('PhButton', '00000000'));
        $this->assertNotNull($this->registry->moduleFor('PhButton', $matches[1]));
    }

    public function testDiscovererAndModuleAgreeOnChildSet(): void
    {
        $discoverer = new ComponentDiscoverer($this->registry);

        $template = $this->pages->getStructure('Home');
        $this->assertNotNull($template);

        $this->assertSame($discoverer->scanTemplate($template['template']), ['Bare', 'PhButton']);
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