<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueComponentLoader;
use Phue\Tests\Core\Stubs\PhueComponentRuntime;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * PhFormTest — real behaviour of the shipped <ph-form> component.
 *
 * Every other PhForm-adjacent test in this suite reads source text. This one
 * executes the component's actual `methods`, `computed` and `data` — the code
 * that runs in the browser — and drives it against a faithful double of the
 * platform surface it uses. No Vue mount, no DOM library, no node_modules and
 * no network: the loader's compiled module has its single `vue` import
 * stripped, and the component's logic never calls the render function.
 *
 * That matters because every bug this component had was invisible to a string
 * assertion:
 *
 *  - `reset()` called `this.$el.reset()`, the form dispatched `reset`,
 *    `onReset` called `reset()` again — infinite recursion until the stack blew;
 *  - `onReset` also called `preventDefault()`, which cancelled the browser's
 *    own field clearing;
 *  - `validateOn` was declared and validated but nothing implemented it;
 *  - the `errors` prop reached the slot and was then ignored, so a server error
 *    could not block submit;
 *  - `applyServerErrors()` read the *merged* map, so a native failure was fed
 *    back as custom validity and became permanently sticky: fixing the field
 *    did not help;
 *  - `invalid` fired on successful passes too;
 *  - `required` was a declared prop on four controls that never bound it to
 *    the inner control, so there was nothing for validation to fail on.
 *
 * Requirements (skipped, never failed, when absent): `bun` on PATH, which is
 * how the client runtime is executed in this repo.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class PhFormTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    /**
     * Harness results keyed by assertion name.
     *
     * @var array<string, array{pass: bool, extra: string|null}>|null
     */
    private static ?array $results = null;

    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!PhueComponentRuntime::available()) {
            self::$skipReason = 'bun is unavailable; cannot execute the client runtime.';

            return;
        }

        $module = (TestComponentLoader::make(self::PHUI_DIR))->buildFor('PhForm');
        if ($module === null) {
            self::$skipReason = 'PhForm did not compile to a JS module.';

            return;
        }

        $decoded = PhueComponentRuntime::run(
            $module,
            PhFormHarness::source(),
            PhFormHarness::prelude()
        );

        $rows = $decoded['results'] ?? null;
        if (!is_array($rows) || $rows === []) {
            throw new \RuntimeException(
                'The component harness returned no assertions: ' . json_encode($decoded, JSON_UNESCAPED_SLASHES)
            );
        }

        $results = [];
        foreach ($rows as $row) {
            $results[(string) $row['name']] = [
                'pass' => (bool) $row['pass'],
                'extra' => $row['extra'] ?? null,
            ];
        }
        self::$results = $results;
    }

    private function requireRuntime(): void
    {
        if (self::$results === null) {
            self::markTestSkipped(self::$skipReason !== '' ? self::$skipReason : 'Runtime unavailable.');
        }
    }

    // ------------------------------------------------------ control discovery

    public function testItEnumeratesTheFormsOwnNamedControls(): void
    {
        $this->requireRuntime();

        $this->assertHarness('enumerates the form\'s own controls');
        $this->assertHarness('skips hidden and unnamed controls');
        $this->assertHarness('errorList is empty while clean');
    }

    // ------------------------------------------------- native constraint validation

    public function testAnEmptyRequiredFieldFailsValidation(): void
    {
        $this->requireRuntime();

        $this->assertHarness('empty required field is invalid');
        $this->assertHarness('submit() returns null when invalid');
    }

    public function testValidationFailureBlocksTheSubmitEmit(): void
    {
        $this->requireRuntime();

        $this->assertHarness('submit NOT emitted on invalid');
        $this->assertHarness('invalid emitted once');
        $this->assertHarness('invalid keyed by field name');
        $this->assertHarness('aria-invalid set on the field');
        $this->assertHarness('update:errors fired');
        $this->assertHarness('errorList surfaces the field');
    }

    public function testTheSummaryIsDerivedFromTheErrorList(): void
    {
        $this->requireRuntime();

        $this->assertHarness('all invalid fields reported');
        $this->assertHarness('valid field not reported');
        $this->assertHarness('errorList has one entry per failure');
    }

    // ------------------------------------------------------------------- payload

    public function testAValidFormEmitsSubmitWithTheFieldValues(): void
    {
        $this->requireRuntime();

        $this->assertHarness('submit emitted when valid');
        $this->assertHarness('payload has title');
        $this->assertHarness('payload has summary');
        $this->assertHarness('invalid NOT emitted on success');
        $this->assertHarness('errors map is empty');
    }

    public function testCollectShapesRepeatedAndBracketedNames(): void
    {
        $this->requireRuntime();

        $this->assertHarness('name[] collapses to array');
        $this->assertHarness('repeated name becomes array');
        $this->assertHarness('single value stays scalar');
        $this->assertHarness('unnamed field excluded');
    }

    // --------------------------------------------------------------------- reset

    /**
     * The regression guard for the recursion: one reset, one emit, no runaway.
     */
    public function testResetEmitsExactlyOnceAndDoesNotRecurse(): void
    {
        $this->requireRuntime();

        $this->assertHarness('reset() delegates to the form exactly once');
        $this->assertHarness('reset emitted EXACTLY once (no recursion)');
        $this->assertHarness('no runaway recursion');
        $this->assertHarness('a second reset adds exactly one more');
        $this->assertHarness('form.reset called twice, not four times');
    }

    public function testTheNativeResetEventIsNotCancelled(): void
    {
        $this->requireRuntime();

        // The old implementation called preventDefault(), which suppressed the
        // browser's own field clearing entirely.
        $this->assertHarness('reset event is not prevented');
        $this->assertHarness('a dispatched reset still emits once');
    }

    // -------------------------------------------------------------------- errors

    public function testTheErrorsPropBlocksSubmitViaSetCustomValidity(): void
    {
        $this->requireRuntime();

        $this->assertHarness('server error sets custom validity');
        $this->assertHarness('server error lands on the control');
        $this->assertHarness('server error blocks submit');
        $this->assertHarness('server error in errorList');
    }

    public function testClearingTheErrorsPropRestoresValidityAndSubmit(): void
    {
        $this->requireRuntime();

        $this->assertHarness('cleared errors restore validity');
        $this->assertHarness('only our own validity was cleared');
        $this->assertHarness('submit goes through after clearing');
    }

    public function testTheErrorsPropAcceptsStringArrayAndKeyedShapes(): void
    {
        $this->requireRuntime();

        $this->assertHarness('serverErrors normalizes an empty prop');
        $this->assertHarness('array errors joined');
        $this->assertHarness('keyed errors formatted');
        $this->assertHarness('empty error values dropped');
    }

    /**
     * `invalid` must never be an empty object on a failed pass, otherwise a
     * consumer that assigns the payload to its error state renders nothing
     * while the form is invalid.
     */
    public function testInvalidCarriesTheFullFailureSetIncludingServerErrors(): void
    {
        $this->requireRuntime();

        $this->assertHarness('server-only failure still reports in invalid');
        $this->assertHarness('server-only failure is not an empty invalid');
        $this->assertHarness('invalid reports server AND native failures');
    }

    public function testAnErrorKeyWithNoMatchingControlIsIgnored(): void
    {
        $this->requireRuntime();

        $this->assertHarness('unknown error key does not crash');
        $this->assertHarness('known error still applied');
        $this->assertHarness('unknown key not applied to another control');
    }

    // ------------------------------------------------------------------- loading

    public function testLoadingActsAsADoubleSubmitGuard(): void
    {
        $this->requireRuntime();

        $this->assertHarness('loading blocks submit');
        $this->assertHarness('busy reflects loading');
        $this->assertHarness('submit works once loading is false');
    }

    // ------------------------------------------------------------------ disabled

    public function testDisabledPropagatesWithoutClobberingAlreadyDisabledFields(): void
    {
        $this->requireRuntime();

        $this->assertHarness('disabled propagated to a field');
        $this->assertHarness('already-disabled field stays disabled');
        $this->assertHarness('field we disabled is re-enabled');
        $this->assertHarness('originally-disabled field STAYS disabled');
    }

    public function testDisabledFieldsAreNotValidated(): void
    {
        $this->requireRuntime();

        $this->assertHarness('disabled required field does not block submit');
        $this->assertHarness('disabled field reported no error');
    }

    // ----------------------------------------------------------------- validateOn

    public function testValidateOnSubmitIgnoresFieldEvents(): void
    {
        $this->requireRuntime();

        $this->assertHarness('validateOn=submit ignores blur');
    }

    public function testValidateOnBlurValidatesOnBlurAndIgnoresInput(): void
    {
        $this->requireRuntime();

        $this->assertHarness('validateOn=blur validates on blur');
        $this->assertHarness('validateOn=blur ignores input');
    }

    public function testValidateOnChangeValidatesWhileTyping(): void
    {
        $this->requireRuntime();

        $this->assertHarness('validateOn=change validates on input');
        $this->assertHarness('validateOn=change ignores blur');
    }

    // ---------------------------------------------------------- nativeValidation

    public function testNativeValidationCallsReportValidityAndStillEmitsInvalid(): void
    {
        $this->requireRuntime();

        $this->assertHarness('native mode calls reportValidity()');
        $this->assertHarness('native mode still emits invalid');

        // The summary is hidden in native mode by a `v-if` in the template.
        // This harness never mounts, so that is asserted where the template is:
        // see testTheTemplateHidesTheSummaryInNativeMode().
    }

    // ------------------------------------------------------------- emit contract

    public function testInvalidFiresOnlyOnFailureWhileUpdateErrorsAlwaysFires(): void
    {
        $this->requireRuntime();

        $this->assertHarness('update:errors fired on the failing pass');
        $this->assertHarness('update:errors fired on the passing pass too');
        $this->assertHarness('invalid did NOT fire on the passing pass');
    }

    /**
     * The sticky-validity regression: a native failure must not be fed back as
     * custom validity, or fixing the field could never make the form pass.
     */
    public function testFixingANativeErrorClearsItInsteadOfBecomingSticky(): void
    {
        $this->requireRuntime();

        $this->assertHarness('first pass fails');
        $this->assertHarness('no custom validity was set by a native error');
        $this->assertHarness('field valid after fixing');
        $this->assertHarness('fixing the field clears the error (not sticky)');
        $this->assertHarness('errors map is empty again');
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * The one behaviour that lives in the template rather than the methods: the
     * summary is suppressed in native mode so the browser's bubble is not
     * duplicated by our own list.
     */
    public function testTheTemplateHidesTheSummaryInNativeMode(): void
    {
        $module = (TestComponentLoader::make(self::PHUI_DIR))->buildFor('PhForm');
        self::assertIsString($module);

        // The compiled condition, verbatim from the real template.
        self::assertStringContainsString(
            '_ctx.showSummary && !_ctx.nativeValidation && _ctx.errorList.length',
            (string) $module,
            'The error summary must not render while nativeValidation is on.'
        );
    }

    /**
     * The reason native validation could not fail at all: `required` and `name`
     * were declared props that never reached the inner control, so a
     * `<ph-input required>` rendered a `*` marker and nothing else, and
     * `<ph-form>` could not see the field.
     *
     * This is a template contract, not a behaviour assertion — the attribute has
     * to be bound in the template for either to reach the DOM. The behavioural
     * consequence is covered above against a real control double.
     */
    public function testFormControlsForwardNameAndRequiredToTheirInnerControl(): void
    {
        $loader = TestComponentLoader::make(self::PHUI_DIR);

        // name: every control the form has to be able to address. The compiled
        // render function emits it as a prop descriptor (`name: _ctx.name`), so
        // that is what the assertion looks for — not the template syntax.
        foreach ([
            'PhInput', 'PhTextarea', 'PhSelect', 'PhNumberInput', 'PhRange',
            'PhCheckbox', 'PhSwitch', 'PhFileInput', 'PhSearchInput',
        ] as $component) {
            $module = (string) $loader->buildFor($component);
            self::assertMatchesRegularExpression(
                '/\bname:\s*_ctx\.name/',
                $module,
                "[{$component}] must bind the name prop to its inner control."
            );
        }

        // required: must become the real attribute, not just the `*` marker.
        // PhFileInput is deliberately excluded — its real input is `d-none`, so a
        // required file input would block submit on an invisible element. PhRange
        // and PhCheckbox have no `required` prop at all.
        foreach (['PhInput', 'PhTextarea', 'PhSelect', 'PhNumberInput', 'PhSwitch', 'PhSearchInput'] as $component) {
            $module = (string) $loader->buildFor($component);
            self::assertMatchesRegularExpression(
                '/\brequired:\s*_ctx\.required/',
                $module,
                "[{$component}] must bind the required prop to its inner control."
            );
        }
    }

    public function testEveryHarnessAssertionPassed(): void
    {
        $this->requireRuntime();

        self::assertIsArray(self::$results);
        self::assertNotEmpty(self::$results);

        $failed = [];
        foreach (self::$results as $name => $row) {
            if (!$row['pass']) {
                $failed[] = $name . ($row['extra'] !== null ? ' [' . $row['extra'] . ']' : '');
            }
        }

        self::assertSame([], $failed, "Component harness failures:\n  " . implode("\n  ", $failed));
    }

    public function testTheRuntimeImportIsTheOnlyVueDependency(): void
    {
        // Guards the hermetic setup: the harness only works because the compiled
        // module has no external imports left, so a future compiler change that
        // introduces one is caught here rather than as a mystery resolution error.
        $module = (TestComponentLoader::make(self::PHUI_DIR))->buildFor('PhForm');
        self::assertIsString($module);

        $stripped = PhueComponentRuntime::stripRuntimeImport((string) $module);

        self::assertStringNotContainsString('from "vue"', $stripped);
        self::assertStringNotContainsString("from 'vue'", $stripped);
        self::assertDoesNotMatchRegularExpression('/^\s*import\s/m', $stripped);
    }

    private function assertHarness(string $name): void
    {
        self::assertArrayHasKey($name, self::$results ?? [], "The harness never reported [{$name}].");
        $row = self::$results[$name];
        self::assertTrue(
            $row['pass'],
            "Harness assertion [{$name}] failed" . ($row['extra'] !== null ? ': ' . $row['extra'] : '.')
        );
    }
}
