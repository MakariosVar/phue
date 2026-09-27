<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PhuiAccessibilityContractTest — locks the semantics that Lighthouse/axe
 * cannot be trusted to re-check, because they are easy to reintroduce while
 * the visual output stays identical.
 *
 * Each test names the axe rule it satisfies and why the previous shape was
 * wrong. A change that breaks one of these is an accessibility regression even
 * if no screenshot moves.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('unit')]
final class PhuiAccessibilityContractTest extends TestCase
{
    private const PHUI = __DIR__ . '/../../../src/Core/UI/Phui';

    private function source(string $path): string
    {
        $real = realpath($path);
        $this->assertIsString($real, "missing component source: {$path}");

        return (string) file_get_contents($real);
    }

    private function phui(string $name): string
    {
        return $this->source(self::PHUI . '/' . $name . '.phue');
    }

    // ------------------------------------------- aria-* must match the role

    public function testSegmentedControlDoesNotPutToggleStateOnItsLabel(): void
    {
        // axe: "aria-* attributes do not match their roles".
        //
        // The option is a native <input type="radio"> inside a role="radiogroup";
        // the visible button is its <label>. A <label> has no role that accepts
        // aria-pressed, so the attribute was both invalid and a duplicate of the
        // radio's own checked state.
        $source = $this->phui('PhSegmentedControl');

        $this->assertStringNotContainsString(
            'aria-pressed',
            $source,
            'aria-pressed on a <label> matches no ARIA role'
        );
        $this->assertStringContainsString(
            'role="radiogroup"',
            $source,
            'the group must still expose the radiogroup role'
        );
        $this->assertStringContainsString(
            'type="radio"',
            $source,
            'the option must remain a real radio input, which is what carries state'
        );
    }

    // ------------------------------- custom controls need a real accessible name

    public function testSelect3NamesItsComboboxWithAriaLabelledby(): void
    {
        // axe: "ARIA input fields do not have accessible names".
        //
        // The visible label used :for="controlId", but controlId points at a
        // <div role="combobox">. <label for> only associates with LABELABLE
        // elements (input, select, textarea, button, ...), never a <div>, so the
        // association silently did nothing and the control had no name at all.
        // aria-labelledby works on any element, which is what a custom control
        // must use.
        $source = $this->phui('PhSelect3');

        $this->assertStringContainsString(
            ':aria-labelledby="label ? labelId : null"',
            $source,
            'the combobox must be named via aria-labelledby'
        );
        $this->assertStringContainsString(
            '<label v-if="label" :id="labelId"',
            $source,
            'the visible label needs the id that aria-labelledby points at'
        );
        $this->assertStringNotContainsString(
            '<label v-if="label" :for="controlId"',
            $source,
            'label/for against a <div> is not a valid association'
        );
        $this->assertStringContainsString(
            ':aria-label="label ? null : (placeholder || null)"',
            $source,
            'with no label the control still needs a name; fall back to the placeholder'
        );
    }

    public function testFileInputExposesAnAccessibleName(): void
    {
        // axe: "Form elements do not have associated labels".
        //
        // The file input is d-none and driven from a custom dropzone, so there
        // is no label element to point at. aria-label is the only mechanism
        // available, and without it the control is anonymous.
        $source = $this->phui('PhFileInput');

        $this->assertMatchesRegularExpression(
            '/<input\b[^>]*type="file"[^>]*:aria-label=/s',
            $source,
            'the file input must carry an aria-label'
        );
    }

    // --------------------------------------- native controls: real id/for pair

    public function testCheckboxAssociatesItsLabelWithTheInput(): void
    {
        // axe: "Form elements do not have associated labels".
        //
        // The input had no id and the label no for, so the visible text was
        // decorative: clicking it did nothing and a screen reader announced an
        // unnamed checkbox.
        $source = $this->phui('PhCheckbox');

        $this->assertMatchesRegularExpression(
            '/<input\b[^>]*:id="inputId"/s',
            $source,
            'the input needs the id the label targets'
        );
        $this->assertMatchesRegularExpression(
            '/<label\b[^>]*:for="inputId"/s',
            $source,
            'the label must point at that id'
        );
    }

    public function testRadioAssociatesItsLabelWithTheInput(): void
    {
        $source = $this->phui('PhRadio');

        $this->assertMatchesRegularExpression(
            '/<input\b[^>]*:id="inputId"/s',
            $source,
            'the input needs the id the label targets'
        );
        $this->assertMatchesRegularExpression(
            '/<label\b[^>]*:for="inputId"/s',
            $source,
            'the label must point at that id'
        );
    }

    public function testIdPropOverridesTheGeneratedIdOnCheckboxAndRadio(): void
    {
        // The id must be controllable from outside — the same pattern
        // PhSelect3 already used (this.id || generated) — so a page can wire an
        // external <label for> or a fragment link to the control, while the
        // useId() fallback keeps instances unique when several are on a page.
        foreach (['PhCheckbox', 'PhRadio'] as $component) {
            $source = $this->phui($component);

            $this->assertStringContainsString(
                "id: {\n      type: String,\n      default: ''\n    },",
                $source,
                "{$component} must expose an `id` prop"
            );
            $this->assertMatchesRegularExpression(
                '/inputId\(\)\s*\{\s*return this\.id \|\| `ph-[a-z0-9]+-\$\{this\.uid\}`;/',
                $source,
                "{$component} must prefer the explicit id and fall back to the generated one"
            );
            $this->assertStringContainsString(
                'useId',
                $source,
                "{$component} must derive its fallback id from useId() so instances stay unique"
            );
        }
    }
}
