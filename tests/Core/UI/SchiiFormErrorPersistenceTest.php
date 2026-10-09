<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Schii record form must not throw away what only the server could say.
 *
 * The defect
 * ----------
 * Rule MEANING moved into Core and is applied once, on the server. The browser
 * was left with exactly one check it can still make — "is this filled in" —
 * and the form kept a single `fieldErrors` map for both sources. On every blur
 * the client recomputed its own narrow check and ASSIGNED the result:
 *
 *     fieldErrors.value = errors;          // replace, not merge
 *
 * So a 422 saying `username: "must be at least 3 characters"` survived only
 * until the user touched the field, at which point the client recomputed an
 * empty object and the message vanished. What remained was the generic
 * "Validation failed" banner with no field marked and no reason given — the
 * user was asked to fix something they could no longer see.
 *
 * Why this is a source-level contract and not a rendered-DOM one
 * --------------------------------------------------------------
 * The regression was a race between two assignments in the same event loop,
 * not a wrong output. A rendered snapshot taken without typing reproduces
 * nothing: at rest the error IS displayed, and the loss happens one interaction
 * later. Asserting on the source is what makes the ordering load-bearing and
 * visible in review, which is the same trade this suite already makes in
 * KnownVulnerabilityRatchetTest and TestLayoutTest.
 */
#[Group('unit')]
final class SchiiFormErrorPersistenceTest extends TestCase
{
    private static function source(): string
    {
        $path = dirname(__DIR__, 3) . '/src/Modules/Schii/Frontend/Schii.phue';

        $source = (string) file_get_contents($path);

        if ($source === '') {
            self::fail('Could not read the Schii admin component; the guards below would pass vacuously.');
        }

        return $source;
    }

    /**
 * The component's <script> block, which is where the behaviour lives.
 */
    private static function script(): string
    {
        $matched = preg_match(
            '/<script[^>]*>(.*?)<\/script>/s',
            self::source(),
            $matches
        );

        return $matched === 1 ? $matches[1] : '';
    }

    private function bodyOf(string $source, string $signature): string
    {
        $position = strpos($source, $signature);

        $this->assertNotFalse($position, "Could not find {$signature} in the Schii admin component.");

        $open = strpos($source, '{', $position);
        $this->assertNotFalse($open);

        $depth = 0;
        $length = strlen($source);

        for ($index = $open; $index < $length; $index++) {
            if ($source[$index] === '{') {
                $depth++;
            } elseif ($source[$index] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $position, $index - $position + 1);
                }
            }
        }

        return substr($source, $position);
    }

    public function testServerErrorsHaveTheirOwnStore(): void
    {
        $this->assertMatchesRegularExpression(
            '/const serverFieldErrors = ref\(\{\}\)/',
            self::source(),
            'Server verdicts need a store of their own; sharing one map with the client check is what let the client overwrite them.',
        );
    }

    /**
     * The assignment that started it.
     */
    public function testClientValidationMergesInsteadOfOverwriting(): void
    {
        $body = $this->bodyOf(self::source(), 'const currentFieldsValidate');

        $this->assertStringNotContainsString(
            'fieldErrors.value = errors;',
            $body,
            'A bare assignment replaces the map, discarding server verdicts the client cannot recompute.',
        );

        $this->assertMatchesRegularExpression(
            '/fieldErrors\.value = \{\s*\.\.\.serverFieldErrors\.value/',
            $body,
            'The client check must be layered on top of the server verdicts, not replace them.',
        );
    }

    public function testServerVerdictsAreWrittenToTheirOwnStore(): void
    {
        $body = $this->bodyOf(self::source(), 'const submitForm');

        $this->assertMatchesRegularExpression(
            '/serverFieldErrors\.value = \{ \.\.\.serverFieldErrors\.value, \.\.\.mapped \}/',
            $body,
            'A 422 populates the server store. Writing to fieldErrors directly would be erased by the next revalidation.',
        );

        $this->assertStringNotContainsString(
            'fieldErrors.value = { ...fieldErrors.value, ...mapped }',
            $body,
            'The merged map is derived state; the source of truth is serverFieldErrors.',
        );
    }

    public function testEditingOneFieldDropsOnlyThatFieldsVerdict(): void
    {
        $body = $this->bodyOf(self::source(), 'const clearServerError');

        $this->assertMatchesRegularExpression(
            '/delete next\[name\]/',
            $body,
            'Editing a field is what retires its error.',
        );

        $this->assertStringNotContainsString(
            'fieldErrors.value = {}',
            $body,
            'Clearing every verdict when the user fixes one field would hide the errors on all the others.',
        );
    }

    public function testResettingTheFormClearsBothStores(): void
    {
        $body = $this->bodyOf(self::source(), 'const resetForm');

        $this->assertStringContainsString('fieldErrors.value = {}', $body);
        $this->assertStringContainsString(
            'serverFieldErrors.value = {}',
            $body,
            'Opening the modal for another record must not inherit the previous record\'s verdicts.',
        );
    }

    /**
     * Every control that renders a message must also be able to retire one.
     *
     * A control can show a server verdict but not clear it, which is the same
     * bug wearing a different hat: the message survives the correction it was
     * meant to prompt.
     */
    public function testEveryErrorBearingControlAlsoClearsOnEdit(): void
    {
        $source = self::source();
        $template = substr($source, 0, (int) strpos($source, '<script'));

        preg_match_all('/:(?:error|v-model)="fieldErrors\[field\.name\]"|<ph-(\w+)/', $template, $matches);

        $this->assertNotEmpty($matches[0], 'No field controls found in the template; the guard would pass vacuously.');

        foreach (['ph-input', 'ph-select', 'ph-textarea', 'ph-checkbox'] as $control) {
            $this->assertStringContainsString(
                $control,
                $template,
                "{$control} disappeared from the record form; if that was intentional, this guard needs revisiting.",
            );
        }

        $this->assertSame(
            5,
            preg_match_all('/@update:model-value="clearServerError\(field\.name\)"/', $template),
            'Every field control that receives an edit must retire that field\'s server verdict. Count is: input, select, textarea, radio, checkbox.',
        );
    }

    /**
     * The client must not have grown a second rule vocabulary.
     *
     * Pinned negatively, because the bug this file exists for was a silent one:
     * re-implementing the rules here is exactly how min:N came to mean "length"
     * in the browser and "value" on the server. The only client check allowed is
     * presence.
     */
    public function testClientDoesNotEvaluateRulesItCannotOwn(): void
    {
        $script = self::script();

        $this->assertNotSame('', $script, 'Could not isolate the <script> block; the guards below would pass vacuously.');

        $this->assertStringNotContainsString("rule === 'email'", $script);
        $this->assertStringNotContainsString("rule === 'numeric'", $script);
        $this->assertStringNotContainsString("startsWith('min:')", $script);
        $this->assertStringNotContainsString('startsWith(\'max:\')', $script);

        $this->assertMatchesRegularExpression(
            '/f\.required && isMissing/',
            $script,
            'Presence is the one check the client owns.',
        );
    }

    /**
     * The rules are still shown, even though the client no longer runs them.
     */
    public function testRulesRemainVisibleToTheUser(): void
    {
        $this->assertMatchesRegularExpression(
            '/Rules: \$\{f\.rules\.join\(/',
            self::source(),
            'Moving rule evaluation to the server must not remove the constraint from the user\'s view.',
        );
    }
}