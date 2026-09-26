<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhueTemplateParser;
use Phue\Tests\Core\Stubs\AssertsPhueJsModuleContract;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the PhSearchInput component.
 *
 * Locks the eight defects the rewrite had to close:
 *
 *  1. `this.$refs.input` was referenced but no `ref="input"` existed, so `focus()`
 *     and the post-clear refocus were silent no-ops behind an optional chain.
 *  2. `type="search"` with no `appearance: none` lets the browser inject its own
 *     clear affordance, producing a second X next to the component's.
 *  3. The `size` prop was declared and validated but never rendered.
 *  4. `is-invalid` lived only on the input, so the group's border never joined the
 *     field's error state.
 *  5. `required` painted an asterisk but set no `required`/`aria-required`.
 *  6. The magnifier was a `<span>` with a click handler: no affordance, no cursor.
 *  7. `search` was debounced off the `modelValue` watcher, so programmatic parent
 *     updates fired searches, `clear()` left a pending timer that could fire
 *     afterwards, and there was no Enter to flush the debounce.
 *  8. `debounce` had no validator and `debounce: 0` still went through a
 *     `setTimeout(0)`, so `search` was never synchronous.
 *
 * Plus the structural contract: the field is a PhInputShell, so the clear button
 * lives *inside* the border box and the focus ring covers the whole control.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhSearchInputTest extends TestCase
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
        $module = $this->module('PhSearchInput');

        $this->assertStringStartsWith('import ', $module);
        $this->assertStringContainsString('export const __phueRender', $module);
        $this->assertStringContainsString("name: 'PhSearchInput'", $module);
        $this->assertStringNotContainsString('<?', $module, 'the served module must not contain PHP markup');

        AssertsPhueJsModuleContract::assertEmittedModuleIsLoadableJs($this, $module, 'PhSearchInput module');
    }

    public function testItLoadsInTemplateModeNotHtmlFirst(): void
    {
        $component = $this->loader->load('PhSearchInput');

        $this->assertFalse(
            $component->view->hasHtml(),
            'PhSearchInput must be a client component, not an SSR partial',
        );
    }

    public function testFieldIsBuiltOnTheInputShellPrimitive(): void
    {
        $template = (new PhueTemplateParser())->parseStructure(self::PHUI_DIR . '/PhSearchInput.phue')['template'];

        $this->assertStringContainsString('<ph-input-shell', $template);
        $this->assertStringContainsString(':invalid="!!error"', $template);
        $this->assertStringContainsString(':size="size"', $template);
        $this->assertStringContainsString('ref="shell"', $template);

        $this->assertStringNotContainsString(
            'input-group',
            $template,
            'input-group is exactly the structure that cannot render a unified focus ring',
        );
    }

    public function testClearButtonRendersInsideTheShellSuffixSlot(): void
    {
        $template = (new PhueTemplateParser())->parseStructure(self::PHUI_DIR . '/PhSearchInput.phue')['template'];

        $this->assertStringContainsString('<template #suffix>', $template);
        $this->assertStringContainsString('aria-label="Clear search"', $template);
        $this->assertStringContainsString('@click="clear"', $template);

        $suffixPosition = strpos($template, '<template #suffix>');
        $shellClose = strrpos($template, '</ph-input-shell>');

        $this->assertIsInt($suffixPosition);
        $this->assertIsInt($shellClose);
        $this->assertLessThan(
            $shellClose,
            $suffixPosition,
            'the clear button must be slotted into the shell, i.e. inside the border box',
        );
    }

    public function testInputRefActuallyExistsSoFocusIsNotDeadCode(): void
    {
        $all = $this->source('PhSearchInput');

        $this->assertStringContainsString(
            'ref="input"',
            $all,
            'without ref="input" every $refs.input call is an optional-chained no-op',
        );
        $this->assertStringContainsString('this.$refs.input.focus();', $all);
        $this->assertStringContainsString('$refs.shell?.focus()', $all, 'the shell must remain the fallback');
    }

    public function testNativeSearchClearAffordanceIsSuppressed(): void
    {
        $all = $this->source('PhSearchInput');

        $this->assertStringContainsString('type="search"', $all);
        $this->assertStringContainsString(
            "appearance: 'none'",
            $this->source('PhInputShell'),
            'the slotted control must reset appearance, or the browser draws a second clear X',
        );
        $this->assertStringContainsString('WebkitAppearance', $this->source('PhInputShell'));
    }

    public function testSizePropIsActuallyRendered(): void
    {
        $all = $this->source('PhSearchInput');

        $this->assertStringContainsString(':size="size"', $all);
        $this->assertStringContainsString("validator: (v) => ['sm', 'md', 'lg'].includes(v)", $all);
    }

    public function testInvalidStateReachesTheShellNotOnlyTheInput(): void
    {
        $template = (new PhueTemplateParser())->parseStructure(self::PHUI_DIR . '/PhSearchInput.phue')['template'];

        $this->assertStringContainsString(':invalid="!!error"', $template);
        $this->assertStringContainsString(':aria-invalid="error ? \'true\' : null"', $template);
        $this->assertStringContainsString('role="alert"', $template);
    }

    public function testRequiredIsReflectedOnTheInputNotOnlyTheAsterisk(): void
    {
        $template = (new PhueTemplateParser())->parseStructure(self::PHUI_DIR . '/PhSearchInput.phue')['template'];

        $this->assertStringContainsString(':required="required"', $template);
        $this->assertStringContainsString(':aria-required="required ? \'true\' : null"', $template);
        $this->assertStringContainsString('aria-hidden="true">*<', $template, 'the decorative asterisk must be hidden from AT');
    }

    public function testDescribedByPointsAtTheRenderedMessage(): void
    {
        $all = $this->source('PhSearchInput');

        $this->assertStringContainsString('describedBy() {', $all);
        $this->assertStringContainsString('return `${this.inputId}-error`;', $all);
        $this->assertStringContainsString('return `${this.inputId}-help`;', $all);
        $this->assertStringContainsString('return undefined;', $all);
        $this->assertStringContainsString(':aria-describedby="describedBy"', $all);
    }

    public function testMagnifierIsARealAffordanceButNotATabStop(): void
    {
        $all = $this->source('PhSearchInput');

        $this->assertStringContainsString('@click="focus"', $all);
        $this->assertStringContainsString("cursor: this.disabled ? 'default' : 'pointer'", $all);
        $this->assertStringContainsString('aria-hidden="true"', $all);

        $template = (new PhueTemplateParser())->parseStructure(self::PHUI_DIR . '/PhSearchInput.phue')['template'];
        $this->assertStringNotContainsString(
            'tabindex="0"',
            $template,
            'a keyboard stop whose only action is focusing the adjacent input is noise; the input is the control',
        );
    }

    public function testFocusIsGuardedAgainstDisabled(): void
    {
        $all = $this->source('PhSearchInput');

        $this->assertStringContainsString('focus() {', $all);
        $this->assertMatchesRegularExpression(
            '/focus\(\) \{\s*if \(this\.disabled\) return;/',
            $all,
            'a disabled search must not be focusable via the affix or the clear button',
        );
    }

    public function testSearchIsDebouncedFromTheInputEventNotTheModelWatcher(): void
    {
        $all = $this->source('PhSearchInput');

        $this->assertStringContainsString('scheduleSearch(value)', $all);

        $this->assertDoesNotMatchRegularExpression(
            '/watch:\s*\{[\s\S]*?modelValue/',
            $all,
            'debouncing off a modelValue watcher makes programmatic parent updates fire '
            . 'searches the user never asked for',
        );
    }

    public function testPendingSearchIsCancellableAndClearedOnClearAndUnmount(): void
    {
        $all = $this->source('PhSearchInput');

        $this->assertStringContainsString('cancelPendingSearch() {', $all);
        $this->assertStringContainsString('clearTimeout(this.searchTimer)', $all);

        $this->assertMatchesRegularExpression(
            '/clear\(\) \{\s*this\.cancelPendingSearch\(\);/',
            $all,
            'clear() must drop the pending debounce, otherwise a stale search fires after the clear',
        );
        $this->assertMatchesRegularExpression(
            '/beforeUnmount\(\) \{\s*this\.cancelPendingSearch\(\);/',
            $all,
            'the timer must not outlive the component',
        );
    }

    public function testEnterFlushesTheDebounce(): void
    {
        $all = $this->source('PhSearchInput');

        $this->assertStringContainsString('@keydown.enter.prevent="submit"', $all);
        $this->assertMatchesRegularExpression(
            '/submit\(\) \{\s*this\.cancelPendingSearch\(\);\s*this\.emitSearch\(this\.modelValue\);/',
            $all,
            'Enter must fire search immediately instead of waiting out the debounce',
        );
    }

    public function testEscapeClearsAndReturnsFocus(): void
    {
        $all = $this->source('PhSearchInput');

        $this->assertStringContainsString('@keydown.esc="clear"', $all);
        $this->assertStringContainsString('$nextTick(() => this.focus())', $all);
    }

    public function testDebounceIsValidatedAndZeroIsSynchronous(): void
    {
        $all = $this->source('PhSearchInput');

        $this->assertStringContainsString('validator: (v) => Number.isFinite(v) && v >= 0', $all);
        $this->assertStringContainsString('if (this.debounce <= 0) {', $all);
        $this->assertStringContainsString(
            '$emit(\'search\', value)',
            $all,
            'debounce: 0 must emit synchronously rather than through setTimeout(0)',
        );
    }

    public function testClearButtonHoverIsReflectedForFeedback(): void
    {
        $all = $this->source('PhSearchInput');

        $this->assertStringContainsString('@mouseenter="clearHovered = true"', $all);
        $this->assertStringContainsString('@mouseleave="clearHovered = false"', $all);
        $this->assertStringContainsString('clearButtonStyle() {', $all);
    }

    public function testInputTypeSearchCarriesTheExpectedKeyboardHints(): void
    {
        $template = (new PhueTemplateParser())->parseStructure(self::PHUI_DIR . '/PhSearchInput.phue')['template'];

        $this->assertStringContainsString('enterkeyhint="search"', $template);
        $this->assertStringContainsString('autocomplete="off"', $template);
        $this->assertStringContainsString('spellcheck="false"', $template);
    }

    public function testNoStyleBlockIsShipped(): void
    {
        $structure = (new PhueTemplateParser())->parseStructure(self::PHUI_DIR . '/PhSearchInput.phue');

        $this->assertArrayNotHasKey('style', $structure);
        $this->assertDoesNotMatchRegularExpression(
            '/<style[\s>]/',
            $structure['template'],
            'all styling must flow through bound style objects and Bootstrap utility classes',
        );
    }

    public function testHashChangesWhenComponentChanges(): void
    {
        $first = $this->loader->moduleHash('PhSearchInput');
        $this->assertIsString($first);

        $path = self::PHUI_DIR . '/PhSearchInput.phue';
        $original = file_get_contents($path);
        $this->assertIsString($original);

        try {
            file_put_contents($path, str_replace('data-phui="search-input"', 'data-phui="search-input-v2"', $original));
            $second = $this->loader->moduleHash('PhSearchInput');

            $this->assertIsString($second);
            $this->assertNotSame($first, $second, 'touching the template must change the module hash');
        } finally {
            file_put_contents($path, $original);
        }
    }
}
