<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhueTemplateParser;
use Phue\Tests\Core\Stubs\AssertsPhueJsModuleContract;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the PhInputShell primitive.
 *
 * PhInputShell exists because Bootstrap 5.3.3 cannot express a unified focus ring
 * over a group of glued siblings: the ring is a box-shadow declared only on
 * `.form-control:focus`, and the framework ships no `.input-group:focus-within`
 * rule at all (verified against the bundled bootstrap.min.css). The only way to
 * get "prefix icon + field + clear button light up as one box" is to invert the
 * structure — one bordered shell owning border/radius/ring, with a borderless
 * transparent control slotted inside it.
 *
 * These lock the structural contract:
 *  - the shell, not the slotted control, owns the border (longhands only),
 *  - the control is neutralised via the `controlStyle` slot prop so consumers
 *    never redeclare it and never end up with two nested boxes,
 *  - `focusin`/`focusout` stand in for `:focus-within`, including the
 *    `relatedTarget` guard that keeps the ring lit while tabbing to the suffix,
 *  - the ring is expressed in Bootstrap's own token values,
 *  - the shell exposes `focus()` so consumers do not depend on template refs
 *    into slot content (the defect that made PhSearchInput's focus() dead code).
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhInputShellTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    private PhueComponentLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = new PhueComponentLoader(self::PHUI_DIR);
    }

    private function module(string $name): string
    {
        $hash = $this->loader->moduleHash($name);
        $this->assertIsString($hash, "moduleHash must resolve {$name}");

        $module = $this->loader->moduleFor($name, $hash);
        $this->assertIsString($module, "moduleFor must serve {$name} for its own hash");

        return $module;
    }

    private function source(string $name): string
    {
        $path = self::PHUI_DIR . "/{$name}.phue";
        self::assertFileExists($path, "{$name}.phue must exist");

        $structure = (new PhueTemplateParser())->parseStructure($path);

        return ($structure['template'] ?? '') . "\n" . ($structure['script'] ?? '');
    }

    public function testModuleResolvesAsLoadableClientComponent(): void
    {
        $module = $this->module('PhInputShell');

        $this->assertStringStartsWith('import ', $module);
        $this->assertStringContainsString('export const __phueRender', $module);
        $this->assertStringContainsString("name: 'PhInputShell'", $module);
        $this->assertStringNotContainsString('<?', $module, 'the served module must not contain PHP markup');

        AssertsPhueJsModuleContract::assertEmittedModuleIsLoadableJs($this, $module, 'PhInputShell module');
    }

    public function testItLoadsInTemplateModeNotHtmlFirst(): void
    {
        $component = $this->loader->load('PhInputShell');

        $this->assertFalse(
            $component->view->hasHtml(),
            'PhInputShell must be a client component, not an SSR partial',
        );
    }

    public function testShellOwnsTheBorderWithLonghandsOnly(): void
    {
        $all = $this->source('PhInputShell');

        $this->assertStringContainsString("borderWidth: 'var(--bs-border-width)'", $all);
        $this->assertStringContainsString("borderStyle: 'solid'", $all);
        $this->assertStringContainsString("borderColor: 'var(--bs-border-color)'", $all);

        $this->assertDoesNotMatchRegularExpression(
            '/border:\s*[\'"]/',
            $all,
            'the shell must not use the `border` shorthand: a later borderColor override '
            . 'would be dropped in the wrong order once Vue removes it on blur',
        );
    }

    public function testControlStyleNeutralisesTheFormControlBox(): void
    {
        $all = $this->source('PhInputShell');

        // The slotted control keeps the .form-control class (placeholder + disabled
        // colour come from it) but every box-drawing declaration must be overridden,
        // otherwise the field draws a second box inside the shell.
        foreach (
            [
                "padding: '0'",
                "borderWidth: '0'",
                "borderRadius: '0'",
                "background: 'transparent'",
                "boxShadow: 'none'",
                "outline: '0'",
                "appearance: 'none'",
                "WebkitAppearance: 'none'",
            ] as $declaration
        ) {
            $this->assertStringContainsString(
                $declaration,
                $all,
                "controlStyle must declare {$declaration}",
            );
        }
    }

    public function testControlStyleIsExposedThroughTheDefaultSlot(): void
    {
        $module = $this->module('PhInputShell');

        $this->assertStringContainsString(':control-style="controlStyle"', $this->source('PhInputShell'));
        $this->assertStringContainsString(
            '_renderSlot(_ctx.$slots, "default", {',
            $module,
            'the default slot must be rendered with its props, otherwise the slotted control never gets the neutralised box',
        );
        $this->assertStringContainsString('controlStyle: _ctx.controlStyle', $module);
    }

    public function testFocusRingUsesBootstrapsOwnTokenValues(): void
    {
        $all = $this->source('PhInputShell');

        // Must match .form-control:focus from the bundled bootstrap 5.3.3.
        $this->assertStringContainsString("const FOCUS_BORDER_COLOR = '#86b7fe'", $all);
        $this->assertStringContainsString(
            "const FOCUS_RING = '0 0 0 .25rem rgba(13, 110, 253, .25)'",
            $all,
        );
        $this->assertStringContainsString(
            "const INVALID_FOCUS_RING = '0 0 0 .25rem rgba(var(--bs-danger-rgb), .25)'",
            $all,
            'the invalid focus ring must reuse Bootstrap\'s --bs-danger-rgb token',
        );
    }

    public function testInvalidAndDisabledStatesOutrankFocus(): void
    {
        $all = $this->source('PhInputShell');

        $this->assertStringContainsString("style.borderColor = 'var(--bs-form-invalid-border-color)'", $all);
        $this->assertStringContainsString("style.background = 'var(--bs-secondary-bg)'", $all);
        $this->assertStringContainsString("style.cursor = 'not-allowed'", $all);

        $this->assertMatchesRegularExpression(
            '/if \(this\.disabled\) \{.*?\} else if \(this\.invalid\) \{.*?\} else if \(this\.focused\) \{/s',
            $all,
            'disabled must win over invalid, and invalid must win over focus',
        );
    }

    public function testFocusInOutStandInForFocusWithin(): void
    {
        $all = $this->source('PhInputShell');

        $this->assertStringContainsString('@focusin="onFocusIn"', $all);
        $this->assertStringContainsString('@focusout="onFocusOut"', $all);
        $this->assertStringContainsString('this.focused = true;', $all);

        $this->assertStringContainsString(
            '$el.contains(next)',
            $all,
            'focusout must ignore moves that stay inside the shell, otherwise tabbing '
            . 'from the field to the suffix button blinks the ring off and on',
        );
    }

    public function testClickingTheShellPaddingRedirectsFocusToTheControl(): void
    {
        $all = $this->source('PhInputShell');

        $this->assertStringContainsString('@mousedown="onShellMouseDown"', $all);
        $this->assertStringContainsString("event.target.closest('button, a, [role=\"button\"]')", $all);
        $this->assertStringContainsString('control.focus();', $all);
        $this->assertStringContainsString(
            'control === event.target',
            $all,
            'a direct hit on the control must fall through to the browser so the caret '
            . 'lands where the user clicked',
        );
    }

    public function testShellExposesFocusSoConsumersNeedNoSlotTemplateRef(): void
    {
        $all = $this->source('PhInputShell');

        $this->assertStringContainsString('controlElement()', $all);
        $this->assertStringContainsString('return this.$el ? this.$el.querySelector(FOCUSABLE) : null;', $all);
        $this->assertStringContainsString('focus() {', $all);
    }

    public function testSizeTokensMirrorBootstrapFormControlSizes(): void
    {
        $all = $this->source('PhInputShell');

        // Mirrors .form-control-sm / .form-control / .form-control-lg.
        $this->assertStringContainsString("sm: { py: '.25rem', px: '.5rem'", $all);
        $this->assertStringContainsString("md: { py: '.375rem', px: '.75rem'", $all);
        $this->assertStringContainsString("lg: { py: '.5rem', px: '1rem'", $all);
        $this->assertStringContainsString("'var(--bs-border-radius-sm)'", $all);
        $this->assertStringContainsString("'var(--bs-border-radius-lg)'", $all);

        $this->assertStringContainsString('gap: t.gap', $all, 'affixes must be spaced by the shell, not by per-slot padding');
    }

    public function testSizePropIsValidatedAgainstTheTokenTable(): void
    {
        $all = $this->source('PhInputShell');

        $this->assertStringContainsString(
            'validator: (v) => Object.prototype.hasOwnProperty.call(SIZE_TOKENS, v)',
            $all,
            'the validator must read the same token table the styles read, so they cannot drift',
        );
    }

    public function testNoStyleBlockIsShipped(): void
    {
        $structure = (new PhueTemplateParser())->parseStructure(self::PHUI_DIR . '/PhInputShell.phue');

        $this->assertArrayNotHasKey(
            'style',
            $structure,
            'the SFC parser exposes no style block, so a .phue component cannot ship one',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<style[\s>]/',
            $structure['template'],
            '.phue view files must not carry a style block; all styling goes through bound style objects',
        );
    }

    public function testHashChangesWhenComponentChanges(): void
    {
        $first = $this->loader->moduleHash('PhInputShell');
        $this->assertIsString($first);

        $path = self::PHUI_DIR . '/PhInputShell.phue';
        $original = file_get_contents($path);
        $this->assertIsString($original);

        try {
            file_put_contents($path, str_replace('data-phui="input-shell"', 'data-phui="input-shell-v2"', $original));
            $second = $this->loader->moduleHash('PhInputShell');

            $this->assertIsString($second);
            $this->assertNotSame($first, $second, 'touching the template must change the module hash');
        } finally {
            file_put_contents($path, $original);
        }
    }
}
