<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueComponentLoader;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the PhFormField form-wrapper component.
 *
 * Locks:
 *  - it is a pure client Vue SFC (props label/error/helperText + computed
 *    fieldClasses), NOT a PHP-laden SSR partial,
 *  - its template must never leak PHP short-tags (`<?=`, `<?php`) into the
 *    compiled ESM module — that was the original defect that made the
 *    component unresolvable via moduleFor()/moduleHash(),
 *  - it resolves as a module (template + script) so it can join the
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

        $this->loader = new PhueComponentLoader(self::PHUI_DIR);
    }

    private function module(): string
    {
        $hash = $this->loader->moduleHash('PhFormField');
        $this->assertIsString($hash, 'moduleHash must resolve PhFormField');

        $module = $this->loader->moduleFor('PhFormField', $hash);
        $this->assertIsString($module, 'moduleFor must serve PhFormField for its own hash');

        return $module;
    }

    private function templateFromModule(string $module): string
    {
        preg_match('/export const __phueTemplate = ("[^"]*");/s', $module, $match);
        $this->assertArrayHasKey(1, $match, 'module must expose a __phueTemplate const');

        $template = json_decode($match[1], true);
        $this->assertIsString($template, 'template const must contain a valid JSON string');

        return $template;
    }

    public function testModuleResolvesWithTemplateAndScript(): void
    {
        $module = $this->module();

        $this->assertStringStartsWith('export const __phueTemplate', $module);
        $this->assertStringContainsString("name: 'PhFormField'", $module);
        $this->assertStringContainsString('export default', $module);
    }

    public function testModuleLeaksNoPhpShortTags(): void
    {
        $module = $this->module();

        $this->assertStringNotContainsString('<?', $module, 'the served module must not contain any PHP markup');
        $this->assertStringNotContainsString('htmlspecialchars', $module);
    }

    public function testTemplateUsesVueDirectivesNotPhpConditionals(): void
    {
        $template = $this->templateFromModule($this->module());

        $this->assertStringContainsString(':class="fieldClasses"', $template);
        $this->assertStringContainsString('v-if="label"', $template);
        $this->assertStringContainsString('<slot>', $template);
        $this->assertStringContainsString('v-if="error"', $template);
        $this->assertStringContainsString('v-else-if="helperText"', $template);
        $this->assertStringContainsString('class="form-label"', $template);
        $this->assertStringContainsString('class="invalid-feedback d-block"', $template);
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