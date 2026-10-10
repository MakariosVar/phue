<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueComponentLoader;
use Phue\Tests\Core\Stubs\PhueComponentRuntime;
use Phue\Tests\Core\Stubs\AssertsPhueJsModuleContract;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * Contract tests for the PhRow/PhCol Bootstrap 12-column grid components.
 *
 * Locks, from the consumer's side, the exact CSS class strings the two
 * components are allowed to emit. It drives the real rendering path: the
 * loader-emitted ESM modules are written to disk next to a `vue` resolver
 * and rendered with `createSSRApp` + `renderToString` under Bun, so a
 * regression in the template OR the script logic surfaces as a class
 * mismatch, not just a syntax problem.
 *
 * The two hard-won contracts baked in here:
 *  1. span + breakpoints are CUMULATIVE  — `<ph-col span="12" md="6">`
 *     must emit `col-12 col-md-6`, never drop the base span,
 *  2. bare attributes become auto/base  — `<ph-col md>` -> `col-md`,
 *     `<ph-col span>` -> `col`.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhGridTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    private PhueComponentLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = TestComponentLoader::make(self::PHUI_DIR);
    }

    /**
     * @param string $name Logical component name (PhRow/PhCol).
     * @return string Emitted ESM module source.
     */
    private function module(string $name): string
    {
        $hash = $this->loader->moduleHash($name);
        $this->assertIsString($hash, "moduleHash must resolve {$name}");

        $module = $this->loader->moduleFor($name, $hash);
        $this->assertIsString($module, "moduleFor must serve {$name} for its own hash");

        // The Bun harness resolves no packages, so the runtime imports and the
        // app-factory proxies that call them come off.
        return PhueComponentRuntime::stripRuntimeImport($module, keepVue: true);
    }

    // ── Module contract ────────────────────────────────────────────────

    public function testRowModuleIsValidEsmWithPrecompiledRender(): void
    {
        $module = $this->module('PhRow');

        $this->assertStringStartsWith('import ', $module);
        $this->assertStringContainsString('export const __phueRender', $module);
        $this->assertStringContainsString("name: 'PhRow'", $module);
        $this->assertStringContainsString('export default', $module);

        AssertsPhueJsModuleContract::assertEmittedModuleIsLoadableJs($this, $module, 'PhRow module');
    }

    public function testColModuleIsValidEsmWithPrecompiledRender(): void
    {
        $module = $this->module('PhCol');

        $this->assertStringStartsWith('import ', $module);
        $this->assertStringContainsString('export const __phueRender', $module);
        $this->assertStringContainsString("name: 'PhCol'", $module);
        $this->assertStringContainsString('export default', $module);

        AssertsPhueJsModuleContract::assertEmittedModuleIsLoadableJs($this, $module, 'PhCol module');
    }

    public function testRenderFunctionsDynamicallyResolveTag(): void
    {
        foreach (['PhRow', 'PhCol'] as $name) {
            $module = $this->module($name);

            $this->assertStringContainsString('_resolveDynamicComponent', $module, "{$name} must render a dynamic <component :is> tag");
            $this->assertStringContainsString('_renderSlot(_ctx.$slots, "default")', $module, "{$name} must forward the default slot");
        }
    }

    public function testGridModulesLeakNoPhpMarkup(): void
    {
        foreach (['PhRow', 'PhCol'] as $name) {
            $this->assertStringNotContainsString('<?', $this->module($name), "{$name} must not contain PHP markup");
        }
    }

    // ── Runtime class-output contract (real renderToString under Bun) ──

    /**
     * Renders grid templates with the emitted modules and returns the
     * server-rendered HTML per case.
     *
     * @param array<string, string> $cases Label => template.
     * @return array<string, string> Label => rendered HTML.
     */
    private function renderGrid(array $cases): array
    {
        $bin = trim((string) shell_exec('command -v bun'));
        if ($bin === '') {
            $this->markTestSkipped('bun is unavailable; cannot render PhGrid coverage.');
        }

        $dir = sys_get_temp_dir() . '/phgrid_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            $rowModule = $this->module('PhRow');
            $colModule = $this->module('PhCol');

            file_put_contents($dir . '/PhRow.mjs', $rowModule);
            file_put_contents($dir . '/PhCol.mjs', $colModule);

            // Give data-URL-free file imports a resolvable `vue` (can't rely on /app/ssr CWD at test time).
            symlink(__DIR__ . '/../../../ssr/node_modules', $dir . '/node_modules');

            $runner = <<<'JS'
import { createSSRApp } from "vue";
import { renderToString } from "vue/server-renderer";
import RowDefault, { __phueRender as rowRender } from "./PhRow.mjs";
import ColDefault, { __phueRender as colRender } from "./PhCol.mjs";

const PhRow = { ...(RowDefault || {}), render: rowRender ?? RowDefault?.render };
const PhCol = { ...(ColDefault || {}), render: colRender ?? ColDefault?.render };

const cases = __PHGRID_CASES__;

const out = {};
for (const [label, template] of Object.entries(cases)) {
    const app = createSSRApp({ components: { PhRow, PhCol }, template });
    try {
        out[label] = await renderToString(app);
    } catch (err) {
        out[label] = "__RENDER_ERROR__::" + String(err?.message ?? err);
    }
}
console.log(JSON.stringify(out));
JS;

            // Embed the cases object literally so per-case templates are visible at render time.
            $jsonCases = json_encode((object) $cases, JSON_UNESCAPED_SLASHES);
            $runnerWithCases = str_replace('__PHGRID_CASES__', $jsonCases, $runner);

            file_put_contents($dir . '/runner.mjs', $runnerWithCases);

            $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($dir . '/runner.mjs');
            $output = [];
            $exit = -1;
            exec($cmd . ' 2>&1', $output, $exit);

            $this->assertSame(0, $exit, 'bun render runner failed: ' . implode("\n", $output));

            $json = implode("\n", $output);
            $decoded = json_decode($json, true);
            $this->assertIsArray($decoded, "runner output was not valid JSON: {$json}");

            return $decoded;
        } finally {
            if (is_dir($dir)) {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    }

    public function testBareRowAndBareColEmitBaseClasses(): void
    {
        $html = $this->renderGrid([
            'bare' => '<ph-row><ph-col>A</ph-col><ph-col>B</ph-col></ph-row>',
        ])['bare'];

        $this->assertStringContainsString('class="row"', $html);
        $this->assertStringContainsString('class="col"', $html);
        $this->assertSame(2, substr_count($html, 'class="col"'), 'bare <ph-col> must emit exactly `col` once per element');
    }

    public function testSpanMapsToBootstrapColClasses(): void
    {
        $html = $this->renderGrid([
            'span' => '<ph-row><ph-col span="6">A</ph-col><ph-col span="auto">B</ph-col></ph-row>',
        ])['span'];

        $this->assertStringContainsString('class="col-6"', $html);
        $this->assertStringContainsString('class="col-auto"', $html);
    }

    public function testResponsiveBreakpointPropsEmitColBreakpointClasses(): void
    {
        $html = $this->renderGrid([
            'responsive' => '<ph-row><ph-col sm="12" md="6" lg="4">A</ph-col></ph-row>',
        ])['responsive'];

        $this->assertStringContainsString('class="col-sm-12 col-md-6 col-lg-4"', $html);
    }

    /**
     * The hard-won cumulative contract: span must survive next to breakpoints.
     */
    public function testSpanIsCumulativeWithBreakpoints(): void
    {
        $html = $this->renderGrid([
            'cum1' => '<ph-col span="12" md="6">A</ph-col>',
            'cum2' => '<ph-col span="6" md="4" lg="3">A</ph-col>',
        ]);

        $this->assertStringContainsString('class="col-12 col-md-6"', $html['cum1']);
        $this->assertStringContainsString('class="col-6 col-md-4 col-lg-3"', $html['cum2']);
    }

    public function testColsObjectEmitsColBreakpointClasses(): void
    {
        $html = $this->renderGrid([
            'cols' => '<ph-col :cols="{ xs: 12, md: 6 }">A</ph-col>',
        ])['cols'];

        $this->assertStringContainsString('class="col-12 col-md-6"', $html);
    }

    public function testRowGuttersAlignJustify(): void
    {
        $html = $this->renderGrid([
            'row' => '<ph-row gutter="3" gutter-x="2" gutter-y="4"
                          align="center" justify="between"><ph-col>A</ph-col></ph-row>',
        ])['row'];

        $this->assertStringContainsString(
            'class="row g-3 gx-2 gy-4 align-items-center justify-content-between"',
            $html
        );
    }

    public function testRowColsScalarAndResponsive(): void
    {
        $html = $this->renderGrid([
            'scalar'     => '<ph-row cols="3"><ph-col>A</ph-col><ph-col>B</ph-col></ph-row>',
            'responsive' => '<ph-row :cols="{ cols: 2, md: 3 }"><ph-col>A</ph-col></ph-row>',
        ]);

        $this->assertStringContainsString('class="row row-cols-3"', $html['scalar']);
        $this->assertStringContainsString('class="row row-cols-2 row-cols-md-3"', $html['responsive']);
    }

    public function testRowColsObjectSupportsBaseKey(): void
    {
        $html = $this->renderGrid([
            'base' => '<ph-row :cols="{ cols: 2 }"><ph-col>A</ph-col></ph-row>',
        ])['base'];

        $this->assertStringContainsString('class="row row-cols-2"', $html);
    }

    public function testOffsetScalarAndResponsiveObject(): void
    {
        $html = $this->renderGrid([
            'scalar' => '<ph-row><ph-col md="6" offset="3">A</ph-col></ph-row>',
            'obj'    => '<ph-row><ph-col :offset="{ md: 3 }">A</ph-col></ph-row>',
        ]);

        $this->assertStringContainsString('class="col-md-6 offset-3"', $html['scalar']);
        $this->assertStringContainsString('class="col offset-md-3"', $html['obj']);
    }

    public function testOrderScalarAndResponsiveObject(): void
    {
        $html = $this->renderGrid([
            'scalar' => '<ph-row><ph-col order="2">A</ph-col></ph-row>',
            'obj'    => '<ph-row><ph-col :order="{ md: \'first\' }">A</ph-col></ph-row>',
        ]);

        $this->assertStringContainsString('class="col order-2"', $html['scalar']);
        $this->assertStringContainsString('class="col order-md-first"', $html['obj']);
    }

    public function testTagPropControlsHostElement(): void
    {
        $html = $this->renderGrid([
            'tag' => '<ph-row tag="section" gutter="2"><ph-col tag="article">A</ph-col></ph-row>',
        ])['tag'];

        $this->assertStringContainsString('<section', $html);
        $this->assertStringContainsString('<article', $html);
        $this->assertStringContainsString('class="row g-2"', $html);
        $this->assertStringContainsString('class="col"', $html);
    }

    /**
     * Bare-attribute forms resolve to auto/base (empty string handled).
     */
    public function testBareAttributesResolveToAutoAndBase(): void
    {
        $html = $this->renderGrid([
            'md'    => '<ph-col md>A</ph-col>',
            'span'  => '<ph-col span>A</ph-col>',
            'mdauto' => '<ph-col md="auto">A</ph-col>',
        ]);

        $this->assertStringContainsString('class="col-md"', $html['md'], 'bare <ph-col md> must become col-md (auto)');
        $this->assertStringContainsString('class="col"', $html['span'], 'bare <ph-col span> must become col (base)');
        $this->assertStringContainsString('class="col-md-auto"', $html['mdauto']);
    }
}