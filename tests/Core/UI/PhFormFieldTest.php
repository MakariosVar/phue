<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueComponentLoader;
use Phue\Tests\Core\Stubs\AssertsPhueJsModuleContract;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * Contract tests for the PhFormField form-wrapper component.
 *
 * Locks:
 *  - it is a pure client Vue SFC (props label/error/helperText + computed
 *    fieldClasses), NOT a PHP-laden SSR partial,
 *  - its template must never leak PHP short-tags (`<?=`, `<?php`) into the
 *    compiled ESM module — that was the original defect that made the
 *    component unresolvable via moduleFor()/moduleHash(),
 *  - the shipped module carries a precompiled render function (`__phueRender`)
 *    whose body translates the template's Vue directives to imperative
 *    runtime helpers (ternaries, _renderSlot, _normalizeClass) instead of
 *    emitting a raw template payload,
 *  - it resolves as a module (render + script) so it can join the
 *    /ui showcase whitelist and be imported per-page.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhFormFieldTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    private PhueComponentLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = TestComponentLoader::make(self::PHUI_DIR);
    }

    private function module(): string
    {
        $hash = $this->loader->moduleHash('PhFormField');
        $this->assertIsString($hash, 'moduleHash must resolve PhFormField');

        $module = $this->loader->moduleFor('PhFormField', $hash);
        $this->assertIsString($module, 'moduleFor must serve PhFormField for its own hash');

        return $module;
    }

    public function testModuleResolvesWithRenderFunctionAndScript(): void
    {
        $module = $this->module();

        $this->assertStringStartsWith('import ', $module);
        $this->assertStringContainsString('export const __phueRender', $module);
        $this->assertStringContainsString("name: 'PhFormField'", $module);
        $this->assertStringContainsString('export default', $module);

        AssertsPhueJsModuleContract::assertEmittedModuleIsLoadableJs($this, $module, 'PhFormField module');
    }

    public function testModuleLeaksNoPhpShortTags(): void
    {
        $module = $this->module();

        $this->assertStringNotContainsString('<?', $module, 'the served module must not contain any PHP markup');
        $this->assertStringNotContainsString('htmlspecialchars', $module);
    }

    public function testTemplateCompilesToVueDirectivesNotPhpConditionals(): void
    {
        $module = $this->module();

        // The template's bindings/directives must surface as imperative render code.
        $this->assertStringContainsString('_normalizeClass(_ctx.fieldClasses)', $module);
        $this->assertStringContainsString('(_ctx.label)', $module);
        $this->assertStringContainsString('"form-label"', $module);
        $this->assertStringContainsString('_renderSlot(_ctx.$slots, "default")', $module);
        $this->assertStringContainsString('(_ctx.error)', $module);
        $this->assertStringContainsString('(_ctx.helperText)', $module);
        $this->assertStringContainsString('"invalid-feedback d-block"', $module);
        $this->assertStringContainsString('"form-text"', $module);
    }

    public function testScriptDeclaresWrapperContract(): void
    {
        $module = $this->module();

        $this->assertStringContainsString("label: {", $module);
        $this->assertStringContainsString("error: {", $module);
        $this->assertStringContainsString("helperText: {", $module);
        $this->assertStringContainsString('fieldClasses() {', $module);
        $this->assertStringContainsString("this.error ? 'was-validated' : ''", $module);
    }

    public function testLoadsInTemplateModeNotHtmlFirst(): void
    {
        $component = $this->loader->load('PhFormField');

        $this->assertFalse($component->view->hasHtml(), 'PhFormField must be a client component, not an SSR partial');
        $this->assertStringContainsString(':class="fieldClasses"', $component->view->getTemplate());
    }

    public function testHashChangesWhenComponentChanges(): void
    {
        $first = $this->loader->moduleHash('PhFormField');
        $this->assertIsString($first);

        $path = self::PHUI_DIR . '/PhFormField.phue';
        $original = file_get_contents($path);
        $this->assertIsString($original);

        try {
            file_put_contents($path, str_replace('class="form-label"', 'class="form-label fw-semibold"', $original));
            $second = $this->loader->moduleHash('PhFormField');

            $this->assertIsString($second);
            $this->assertNotSame($first, $second, 'touching the template must change the module hash');
        } finally {
            file_put_contents($path, $original);
        }
    }
}