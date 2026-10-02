<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Tests\Core\Stubs\PhueComponentRuntime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * PhOtpInputTest — the one-field code entry contract of <ph-otp-input>.
 *
 * The component renders N cells but owns ONE input. That decision is what makes
 * it usable rather than merely pretty, and the tests below pin the three
 * consequences that decide whether it works:
 *
 *  - **Paste.** The whole point of pasting a code is that it arrives formatted:
 *    `123 456`, `123-456`, from a different app. A `maxlength` input truncates
 *    AFTER the invalid characters are in, so the naive implementation lands "12"
 *    out of "12ab34" and silently drops the rest.
 *  - **`complete` fires once.** It is edge-triggered on the transition into a full
 *    value. A parent that echoes `update:modelValue` back would otherwise submit
 *    the same code twice.
 *  - **Leading zeros.** The value is a string precisely so `007` survives. A
 *    `Number` conversion anywhere in the chain turns it into `7`.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('integration')]
final class PhOtpInputTest extends TestCase
{
    private const PHUI = __DIR__ . '/../../../src/Core/UI/Phui';

    /** @var array<string, array{pass: bool, extra: string|null}>|null */
    private static ?array $results = null;

    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!PhueComponentRuntime::available()) {
            self::$skipReason = 'bun is unavailable; cannot execute the client runtime.';

            return;
        }

        $module = (TestComponentLoader::make(self::PHUI))->buildFor('PhOtpInput');
        if ($module === null) {
            self::$skipReason = 'PhOtpInput did not compile to a JS module.';

            return;
        }

        $decoded = PhueComponentRuntime::run($module, self::scenarios(), PhuiInstanceFactory::prelude());
        $results = [];
        foreach (($decoded['results'] ?? []) as $row) {
            $results[(string) $row['name']] = ['pass' => (bool) $row['pass'], 'extra' => $row['extra'] ?? null];
        }
        self::$results = $results;
    }

    private function requireRuntime(): void
    {
        if (self::$results === null) {
            self::markTestSkipped(self::$skipReason);
        }
    }

    private function assertHarness(string $name): void
    {
        self::assertArrayHasKey($name, self::$results ?? [], "The harness never reported [{$name}].");
        $row = self::$results[$name];

        self::assertTrue($row['pass'], "Harness scenario [{$name}] failed: " . (string) $row['extra']);
    }

    public function testOnlyTheAcceptedCharactersSurvive(): void
    {
        $this->requireRuntime();

        $this->assertHarness('numeric_strips_letters_and_separators');
        $this->assertHarness('alphanumeric_accepts_letters');
        $this->assertHarness('the_value_is_truncated_to_the_length');
    }

    public function testTypingAndPastingAgree(): void
    {
        $this->requireRuntime();

        $this->assertHarness('onInput_writes_the_cleaned_value_back_to_the_field');
        $this->assertHarness('a_pasted_formatted_code_fills_every_cell');
        $this->assertHarness('a_paste_of_pure_junk_is_refused');
    }

    public function testCompleteIsEdgeTriggered(): void
    {
        $this->requireRuntime();

        $this->assertHarness('complete_fires_once_when_the_value_fills_up');
        $this->assertHarness('complete_does_not_fire_again_for_the_same_code');
        $this->assertHarness('editing_the_code_back_into_a_full_one_announces_again');
    }

    public function testTheCellsAreThePointerTarget(): void
    {
        $this->requireRuntime();

        // The real field is a 1px box at the origin, so a click on a cell used to hit
        // nothing: the control looked like an input, swallowed the click and never
        // focused, and the only way in was Tab.
        $this->assertHarness('clicking_the_cells_focuses_the_real_field');
        $this->assertHarness('the_caret_lands_at_the_end');
        $this->assertHarness('the_cells_advertise_a_text_cursor');
        $this->assertHarness('a_disabled_field_refuses_focus');

        $source = (string) file_get_contents(self::PHUI . '/PhOtpInput.phue');
        self::assertMatchesRegularExpression(
            '/aria-hidden="true"[^>]*@click="focus"/s',
            $source,
            'the cells must carry the click that focuses the hidden field'
        );
    }

    public function testTheCaretIsVisibleEvenThoughTheFieldIsNot(): void
    {
        $this->requireRuntime();

        $this->assertHarness('the_cell_after_the_caret_is_highlighted_while_focused');
        $this->assertHarness('masked_cells_render_dots');
    }

    public function testLeadingZerosSurvive(): void
    {
        $this->requireRuntime();

        $this->assertHarness('leading_zeros_are_preserved_as_a_string');
    }

    public function testTheControlIsOneLabelledTextbox(): void
    {
        $source = (string) file_get_contents(self::PHUI . '/PhOtpInput.phue');

        // Six real inputs would be six anonymous single-character textboxes to a
        // screen reader, and the caret/cell-navigation state machine that comes
        // with them. Asserted structurally so the decision cannot be undone by
        // someone "fixing" the cells into real fields.
        self::assertSame(1, substr_count($source, 'ref="field"'));
        self::assertStringContainsString('class="visually-hidden"', $source);
        self::assertStringContainsString('aria-hidden="true"', $source);

        // Platform autofill is the reason the field is real.
        self::assertStringContainsString('one-time-code', $source);

        // `name` has to reach the real input or `<ph-form>` cannot submit it.
        self::assertMatchesRegularExpression('/<input\b[^>]*:name="name"/s', $source);
    }

    public function testTheNameIsForwardedToTheRealInput(): void
    {
        // A wrapper that swallows `name` is invisible to `<ph-form>`: it does not
        // validate and it does not appear in the payload.
        $this->requireRuntime();

        $this->assertHarness('name_is_a_forwarded_prop');
    }

    private static function scenarios(): string
    {
        return <<<'JS'
const field = (value, caret) => ({
  value: value === undefined ? '' : value,
  selectionStart: caret === undefined ? null : caret,
  focus: () => {},
});

scenario('numeric_strips_letters_and_separators', () => {
  const vm = globalThis.mountPhui({});

  // Separators are DROPPED, not rejected: that is what makes a code pasted as
  // "123 456" land in the right cells.
  assertEqual(vm.sanitize('123 456'), '123456');
  assertEqual(vm.sanitize('123-456'), '123456');
  assertEqual(vm.sanitize('12ab34'), '1234');
  assertEqual(vm.sanitize('a'), '');
  assertEqual(vm.sanitize('  '), '');
});

scenario('alphanumeric_accepts_letters', () => {
  const vm = globalThis.mountPhui({ props: { type: 'alphanumeric' } });

  assertEqual(vm.sanitize('a1B2 c3'), 'a1B2c3');
  assertEqual(vm.sanitize('a-b_c'), 'abc');
});

scenario('the_value_is_truncated_to_the_length', () => {
  const vm = globalThis.mountPhui({ props: { length: 4 } });
  assertEqual(vm.sanitize('123456789'), '1234');
});

scenario('onInput_writes_the_cleaned_value_back_to_the_field', () => {
  // Without the write-back the DOM keeps the letters while the model has the
  // digits: the field would show something the value does not contain.
  const input = field('12ab34');
  const vm = globalThis.mountPhui({ props: { modelValue: '' }, refs: { field: input } });

  vm.onInput({ target: input });

  assertEqual(input.value, '1234');
  assertEqual(vm.__emitted[0].event, 'update:modelValue');
  assertEqual(vm.__emitted[0].args[0], '1234');
});

scenario('a_pasted_formatted_code_fills_every_cell', () => {
  const input = field('12', 2);
  const vm = globalThis.mountPhui({ props: { modelValue: '12' }, refs: { field: input } });

  let prevented = false;
  vm.onPaste({
    clipboardData: { getData: () => '34 56-78' },
    preventDefault: () => { prevented = true; },
  });

  assertTrue(prevented, 'the native paste must be stopped, or the browser truncates after the junk');
  assertEqual(input.value, '123456');
  assertEqual(vm.__emitted[0].args[0], '123456');
});

scenario('a_paste_of_pure_junk_is_refused', () => {
  const input = field('', 0);
  const vm = globalThis.mountPhui({ props: { modelValue: '' }, refs: { field: input } });

  vm.onPaste({
    clipboardData: { getData: () => 'no digits here' },
    preventDefault: () => {},
  });

  // Reported, not silently dropped: a paste that does nothing looks like a broken
  // field, and the caller can explain it.
  assertTrue(vm.__emitted.some((row) => row.event === 'paste-rejected'));
  assertEqual(vm.__emitted.filter((row) => row.event === 'update:modelValue').length, 0);
});

scenario('complete_fires_once_when_the_value_fills_up', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: '' } });

  globalThis.runPhuiWatcher(vm, 'modelValue', '123456');

  const completes = vm.__emitted.filter((row) => row.event === 'complete');
  assertEqual(completes.length, 1);
  assertEqual(completes[0].args[0], '123456');
});

scenario('complete_does_not_fire_again_for_the_same_code', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: '' } });
  globalThis.runPhuiWatcher(vm, 'modelValue', '123456');

  // A parent echoing `update:modelValue` back must not submit the same code twice.
  globalThis.runPhuiWatcher(vm, 'modelValue', '123456');

  assertEqual(vm.__emitted.filter((row) => row.event === 'complete').length, 1);
});

scenario('editing_the_code_back_into_a_full_one_announces_again', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: '123456' } });
  globalThis.runPhuiWatcher(vm, 'modelValue', '12345');
  globalThis.runPhuiWatcher(vm, 'modelValue', '654321');

  assertEqual(vm.__emitted.filter((row) => row.event === 'complete').length, 1);
  assertEqual(vm.__emitted[vm.__emitted.length - 1].args[0], '654321');
});

scenario('the_cell_after_the_caret_is_highlighted_while_focused', () => {
  // The real input is visually hidden, so without a drawn caret a keyboard user is
  // typing into something they cannot see.
  const vm = globalThis.mountPhui({ props: { modelValue: '12' } });
  vm.onFocus({});

  const caret = vm.cellStyle(3);
  assertEqual(caret.borderColor, 'var(--bs-primary)');
  assertEqual(caret.borderWidth, '2px');

  const filled = vm.cellStyle(2);
  assertContains(filled.border, 'var(--bs-border-color)');
});

scenario('clicking_the_cells_focuses_the_real_field', () => {
  let focused = false;
  let ranged = null;
  const input = {
    value: '12',
    focus: () => { focused = true; },
    setSelectionRange: (from, to) => { ranged = [from, to]; },
  };
  const vm = globalThis.mountPhui({ props: { modelValue: '12' }, refs: { field: input } });

  vm.focus();

  assertTrue(focused, 'the hidden field never received focus');
  assertTrue(ranged !== null, 'the caret must be placed explicitly');
});

scenario('the_caret_lands_at_the_end', () => {
  let ranged = null;
  const input = {
    value: '12',
    focus: () => {},
    setSelectionRange: (from, to) => { ranged = [from, to]; },
  };
  const vm = globalThis.mountPhui({ props: { modelValue: '12' }, refs: { field: input } });

  vm.focus();

  // A caret at position 0 means the next keystroke edits the FIRST digit, which is
  // the opposite of what clicking a code field means everywhere else.
  assertEqual(ranged[0], 2);
  assertEqual(ranged[1], 2);
});

scenario('the_cells_advertise_a_text_cursor', () => {
  const on = globalThis.mountPhui({});
  assertEqual(on.groupStyle.cursor, 'text');

  const off = globalThis.mountPhui({ props: { disabled: true } });
  assertEqual(off.groupStyle.cursor, 'default');
});

scenario('a_disabled_field_refuses_focus', () => {
  let focused = false;
  const input = { value: '', focus: () => { focused = true; } };
  const vm = globalThis.mountPhui({ props: { disabled: true }, refs: { field: input } });

  vm.focus();

  assertFalse(focused);
});

scenario('masked_cells_render_dots', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: '123456', masked: true } });

  assertEqual(vm.charAt(1), '1');
  assertEqual(vm.maskFor(1), '•');
  assertEqual(vm.maskFor(9), '', 'an empty cell must stay empty, dots included');
});

scenario('leading_zeros_are_preserved_as_a_string', () => {
  // The value is a string precisely so "007" survives. A Number conversion anywhere
  // in the chain turns it into 7 and the code stops matching.
  const input = field('');
  const vm = globalThis.mountPhui({ props: { modelValue: '' }, refs: { field: input } });

  vm.onInput({ target: input });

  assertEqual(typeof vm.__emitted[0].args[0], 'string');

  const zeroed = globalThis.mountPhui({ props: { modelValue: '007' } });
  assertEqual(zeroed.charAt(2), '0');
});

scenario('name_is_a_forwarded_prop', () => {
  const vm = globalThis.mountPhui({ props: { name: 'otp' } });
  assertEqual(vm.name, 'otp');
});
JS . "\nglobalThis.__emitResults();\n";
    }
}