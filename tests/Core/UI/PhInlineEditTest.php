<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Tests\Core\Stubs\PhueComponentRuntime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * PhInlineEditTest — the commit/cancel state machine of <ph-inline-edit>.
 *
 * A click-to-edit control looks trivial and is not. Three things break it in
 * practice, and all three are asserted here:
 *
 *  - **The blur race.** Clicking "Save" blurs the field first, so an unguarded
 *    `saveOnBlur` commits the value the user was about to CANCEL, and clicking
 *    "Cancel" saves it. The commit path has to be guarded and the blur has to
 *    ignore focus moves that land inside the component.
 *  - **A refusal loses the value.** A validation failure must keep the field open
 *    with the user's text in it; closing and showing a message somewhere else
 *    throws the work away.
 *  - **The trigger has to be reachable.** The idle state is a <button>, styled back
 *    into looking like text — a `<div @click>` renders identically and is
 *    invisible to a keyboard and to a screen reader.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('integration')]
final class PhInlineEditTest extends TestCase
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

        $module = (TestComponentLoader::make(self::PHUI))->buildFor('PhInlineEdit');
        if ($module === null) {
            self::$skipReason = 'PhInlineEdit did not compile to a JS module.';

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

    public function testEditingOpensWithTheCurrentValue(): void
    {
        $this->requireRuntime();

        $this->assertHarness('edit_seeds_the_draft_from_the_value');
        $this->assertHarness('disabled_never_opens_the_editor');
    }

    public function testEnterCommitsAndEscapeCancels(): void
    {
        $this->requireRuntime();

        $this->assertHarness('enter_commits');
        $this->assertHarness('escape_cancels_and_restores_the_value');
    }

    public function testARefusedCommitKeepsTheFieldOpenAndTheText(): void
    {
        $this->requireRuntime();

        $this->assertHarness('a_refusal_keeps_the_editor_open_with_its_text');
        $this->assertHarness('typing_clears_a_stale_refusal');
        $this->assertHarness('a_refusal_emits_no_update');
    }

    public function testCommitOnlyEmitsAChangeWhenTheValueActuallyChanged(): void
    {
        $this->requireRuntime();

        $this->assertHarness('an_unchanged_value_emits_no_update_but_still_reports_save');
    }

    public function testTheBlurRaceIsGuarded(): void
    {
        $this->requireRuntime();

        $this->assertHarness('blur_inside_the_component_does_not_commit');
        $this->assertHarness('blur_outside_the_component_commits');
        $this->assertHarness('save_on_blur_can_be_turned_off');
    }

    public function testTheIdleTriggerIsARealButtonWithAnAccessibleName(): void
    {
        $source = (string) file_get_contents(self::PHUI . '/PhInlineEdit.phue');

        // Not `<div @click>`: that renders the same and is unreachable by keyboard.
        self::assertMatchesRegularExpression(
            '/<button\b[^>]*v-if="!editing"/s',
            $source,
            'the idle state must be a real <button>'
        );
        self::assertMatchesRegularExpression(
            '/<button\b[^>]*:aria-label="triggerAriaLabel"/s',
            $source,
            'the trigger has no text of its own, so its name must be bound'
        );
        self::assertStringContainsString('font: \'inherit\'', $source);

        // Escape is handled on the field, not on a document listener: a
        // document-level handler would also catch the Escape of a dialog opened
        // from inside the slot.
        self::assertStringNotContainsString("document.addEventListener('keydown'", $source);
    }

    public function testSaveAndCancelAreNamedControls(): void
    {
        $source = (string) file_get_contents(self::PHUI . '/PhInlineEdit.phue');

        // A bare glyph is not a control with a name. Both buttons carry
        // aria-label, so a screen reader announces "Save button".
        self::assertStringContainsString('aria-label="Save"', $source);
        self::assertStringContainsString('aria-label="Cancel"', $source);
    }

    private static function scenarios(): string
    {
        return <<<'JS'
const keyEvent = (key) => ({
  key,
  preventDefault: () => {},
  stopPropagation: () => {},
});

scenario('edit_seeds_the_draft_from_the_value', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: 'Ada' } });

  vm.edit();

  assertTrue(vm.editing);
  assertEqual(vm.draft, 'Ada');
  assertTrue(vm.__emitted.some((row) => row.event === 'edit'));
});

scenario('disabled_never_opens_the_editor', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: 'Ada', disabled: true } });

  vm.edit();

  assertFalse(vm.editing);
  assertEqual(vm.__emitted.length, 0);
});

scenario('enter_commits', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: 'Ada' } });
  vm.edit();
  vm.onInput({ target: { value: 'Grace' } });

  vm.onKeydown(keyEvent('Enter'));

  assertFalse(vm.editing);
  const updates = vm.__emitted.filter((row) => row.event === 'update:modelValue');
  assertEqual(updates.length, 1);
  assertEqual(updates[0].args[0], 'Grace');
});

scenario('escape_cancels_and_restores_the_value', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: 'Ada' } });
  vm.edit();
  vm.onInput({ target: { value: 'Grace' } });

  vm.onKeydown(keyEvent('Escape'));

  assertFalse(vm.editing);
  assertEqual(vm.draft, 'Ada', 'the draft must be put back, or the next edit starts wrong');
  assertEqual(vm.__emitted.filter((row) => row.event === 'update:modelValue').length, 0);
  assertTrue(vm.__emitted.some((row) => row.event === 'cancel'));
});

scenario('a_refusal_keeps_the_editor_open_with_its_text', () => {
  const vm = globalThis.mountPhui({
    props: { modelValue: '', validate: (value) => (value.length < 3 ? 'Too short.' : '') },
  });
  vm.edit();
  vm.onInput({ target: { value: 'ab' } });

  vm.commit();

  assertTrue(vm.editing, 'closing on a refusal throws the work away');
  assertEqual(vm.draft, 'ab', 'the typed text must survive the refusal');
  assertEqual(vm.validationMessage, 'Too short.');
});

scenario('typing_clears_a_stale_refusal', () => {
  const vm = globalThis.mountPhui({
    props: { modelValue: '', validate: (value) => (value.length < 3 ? 'Too short.' : '') },
  });
  vm.edit();
  vm.onInput({ target: { value: 'ab' } });
  vm.commit();

  vm.onInput({ target: { value: 'abc' } });

  // The message described a value the field no longer holds.
  assertEqual(vm.validationMessage, '');
});

scenario('a_refusal_emits_no_update', () => {
  const vm = globalThis.mountPhui({
    props: { modelValue: 'Ada', validate: () => 'No.' },
  });
  vm.edit();

  vm.commit();

  assertEqual(vm.__emitted.filter((row) => row.event === 'update:modelValue').length, 0);
  assertEqual(vm.__emitted.filter((row) => row.event === 'save').length, 0);
});

scenario('an_unchanged_value_emits_no_update_but_still_reports_save', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: 'Ada' } });
  vm.edit();

  vm.commit();

  assertEqual(vm.__emitted.filter((row) => row.event === 'update:modelValue').length, 0);
  assertEqual(vm.__emitted.filter((row) => row.event === 'save').length, 1);
});

scenario('blur_inside_the_component_does_not_commit', () => {
  // The Save and Cancel buttons are inside the component: clicking one blurs the
  // field first, and committing on that blur saves the value the user cancelled.
  const inside = { tagName: 'BUTTON' };
  const vm = globalThis.mountPhui({
    props: { modelValue: 'Ada' },
    el: { contains: (node) => node === inside },
  });
  vm.edit();
  vm.onInput({ target: { value: 'Grace' } });

  vm.onBlur({ relatedTarget: inside });

  assertTrue(vm.editing);
  assertEqual(vm.__emitted.filter((row) => row.event === 'update:modelValue').length, 0);
});

scenario('blur_outside_the_component_commits', () => {
  const outside = { tagName: 'INPUT' };
  const vm = globalThis.mountPhui({
    props: { modelValue: 'Ada' },
    el: { contains: () => false },
  });
  vm.edit();
  vm.onInput({ target: { value: 'Grace' } });

  vm.onBlur({ relatedTarget: outside });

  assertFalse(vm.editing);
  assertEqual(vm.__emitted.filter((row) => row.event === 'update:modelValue')[0].args[0], 'Grace');
});

scenario('save_on_blur_can_be_turned_off', () => {
  const vm = globalThis.mountPhui({
    props: { modelValue: 'Ada', saveOnBlur: false },
    el: { contains: () => false },
  });
  vm.edit();
  vm.onInput({ target: { value: 'Grace' } });

  vm.onBlur({ relatedTarget: null });

  assertTrue(vm.editing);
});

scenario('the_trigger_is_named_with_the_value_it_would_edit', () => {
  const withValue = globalThis.mountPhui({ props: { modelValue: 'Ada' } });
  assertEqual(withValue.triggerAriaLabel, 'Edit: Ada');

  // Down a column of rows, a bare "Edit" repeated is useless.
  const withoutValue = globalThis.mountPhui({ props: { modelValue: '' } });
  assertEqual(withoutValue.triggerAriaLabel, 'Edit');
});

scenario('the_trigger_inherits_the_font_it_sits_in', () => {
  // A <button> does not inherit font-family or size from its parent; without this
  // the "inline" control renders in the UA font and is visibly the wrong size.
  const vm = globalThis.mountPhui({ props: { modelValue: 'Ada' } });
  assertEqual(vm.triggerStyle.font, 'inherit');
});
JS . "\nglobalThis.__emitResults();\n";
    }
}