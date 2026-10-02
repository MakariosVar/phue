<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Tests\Core\Stubs\PhueComponentRuntime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * PhCollapseTest — the open/close contract of <ph-collapse>.
 *
 * <ph-collapse> exists so that "Advanced options" does not have to be an
 * accordion with `multiple` hardcoded on and the open keys tracked by hand. That
 * makes its correctness requirement narrow and absolute: an unwired instance must
 * toggle on its own, a prop change must win over the internal state, and `lazy`
 * must genuinely not mount the panel until the first open — because the reason
 * somebody reaches for `lazy` is a form inside the section, and a panel that is
 * "lazy" only in the class name still runs it.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('integration')]
final class PhCollapseTest extends TestCase
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

        $module = (TestComponentLoader::make(self::PHUI))->buildFor('PhCollapse');
        if ($module === null) {
            self::$skipReason = 'PhCollapse did not compile to a JS module.';

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

    public function testUnwiredInstanceTogglesOnItsOwn(): void
    {
        $this->requireRuntime();

        $this->assertHarness('toggle_opens_and_announces_both_events');
        $this->assertHarness('toggling_back_closes');
    }

    public function testAPropChangeWinsOverTheInternalState(): void
    {
        $this->requireRuntime();

        $this->assertHarness('prop_change_drives_the_state');
        $this->assertHarness('prop_change_is_idempotent');
    }

    public function testDisabledIsInert(): void
    {
        $this->requireRuntime();

        $this->assertHarness('disabled_refuses_to_toggle');
    }

    public function testLazyDoesNotMountBeforeTheFirstOpen(): void
    {
        $this->requireRuntime();

        $this->assertHarness('lazy_leaves_the_panel_unmounted_until_opened');
        $this->assertHarness('lazy_stays_mounted_after_closing');
        $this->assertHarness('an_initially_open_panel_is_mounted_from_the_start');
    }

    public function testProgrammaticControlNeedsNoTemplateRef(): void
    {
        $this->requireRuntime();

        $this->assertHarness('open_and_close_methods_exist_for_callers');
    }

    public function testTheDurationPropOverridesBootstrapsHardCodedTransition(): void
    {
        $this->requireRuntime();

        $this->assertHarness('duration_is_bound_as_a_transition_duration');
    }

    public function testTheAnimationDrivesBootstrapsOwnCollapsingClass(): void
    {
        $source = (string) file_get_contents(self::PHUI . '/PhCollapse.phue');

        // There is no bootstrap.js in this kit, so the `collapsing` class — the
        // only thing that carries a CSS height transition — has to be added by the
        // component. Removing these hooks turns the panel into an instant jump
        // while every other test still passes.
        self::assertStringContainsString("el.classList.add('collapsing')", $source);
        self::assertStringContainsString("el.classList.remove('collapsing')", $source);

        // And the `collapse` class must stay off: `.collapse:not(.show)` sets
        // `display:none`, which fights `v-show` and flickers the panel on the first
        // toggle. Checked as the exact class attribute value, because
        // `accordion-collapse` legitimately ends in the same word.
        self::assertStringContainsString('class="accordion-collapse"', $source);
        self::assertStringNotContainsString('class="collapse"', $source);
        self::assertStringNotContainsString('class="collapse ', $source);
    }

    private static function scenarios(): string
    {
        return <<<'JS'
scenario('toggle_opens_and_announces_both_events', () => {
  const vm = globalThis.mountPhui({});
  assertFalse(vm.open);

  vm.toggle();

  assertTrue(vm.open);
  assertEqual(vm.hasOpened, true, 'the panel must be mounted once opened');

  const events = vm.__emitted.map((row) => row.event);
  assertContains(events.join(','), 'update:modelValue');
  assertContains(events.join(','), 'change');
  assertEqual(vm.__emitted[0].args[0], true, 'update:modelValue carries the new state');
});

scenario('toggling_back_closes', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: true } });
  assertTrue(vm.open);

  vm.toggle();

  assertFalse(vm.open);
  assertEqual(vm.__emitted[0].args[0], false);
});

scenario('prop_change_drives_the_state', () => {
  const vm = globalThis.mountPhui({});
  vm.toggle();
  assertTrue(vm.open);

  globalThis.runPhuiWatcher(vm, 'modelValue', false);

  assertFalse(vm.open);
});

scenario('prop_change_is_idempotent', () => {
  // A parent that echoes the value back must not produce an infinite loop, and
  // must not re-announce a change that did not happen.
  const vm = globalThis.mountPhui({ props: { modelValue: true } });
  globalThis.runPhuiWatcher(vm, 'modelValue', true);

  assertTrue(vm.open);
  assertEqual(vm.__emitted.length, 0);
});

scenario('disabled_refuses_to_toggle', () => {
  const vm = globalThis.mountPhui({ props: { disabled: true } });
  vm.toggle();

  assertFalse(vm.open);
  assertEqual(vm.__emitted.length, 0);
});

scenario('lazy_leaves_the_panel_unmounted_until_opened', () => {
  const vm = globalThis.mountPhui({ props: { lazy: true } });

  // The point of `lazy`: a form inside the section must not run before it is shown.
  assertFalse(vm.hasOpened);
  assertFalse(vm.open);
});

scenario('lazy_stays_mounted_after_closing', () => {
  const vm = globalThis.mountPhui({ props: { lazy: true, modelValue: true } });
  assertTrue(vm.hasOpened, 'an initially open panel is visible, so it must be mounted');

  vm.toggle();

  // Unmounting on close would throw away scroll position and every field the user
  // filled in; `lazy` is about the FIRST open, not about visibility.
  assertFalse(vm.open);
  assertTrue(vm.hasOpened);
});

scenario('an_initially_open_panel_is_mounted_from_the_start', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: true } });
  assertTrue(vm.hasOpened);
});

scenario('open_and_close_methods_exist_for_callers', () => {
  const vm = globalThis.mountPhui({});
  vm.openPanel();
  assertTrue(vm.open);

  vm.closePanel();
  assertFalse(vm.open);
});

scenario('duration_is_bound_as_a_transition_duration', () => {
  const vm = globalThis.mountPhui({ props: { duration: 120 } });
  assertEqual(vm.panelStyle.transitionDuration, '120ms');
});
JS . "\nglobalThis.__emitResults();\n";
    }
}