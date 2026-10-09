<?php

declare(strict_types=1);

namespace Phue\Core\Validation;

use InvalidArgumentException;

/**
 * The one place a #[Field(rules: [...])] array is given meaning.
 *
 * Why this exists in Core
 * -----------------------
 * `rules` is declared by \Phue\Core\Database\Attributes\Field, which is a Core
 * attribute so that a model can describe itself in a project that installs no
 * admin module. A vocabulary that only the admin module understood would put
 * the declaration and the enforcement in different places — a model would say
 * `min:3` and, without that module installed, nothing would enforce it. Same
 * failure as the auto-cast, one level down.
 *
 * Why a vocabulary and not a bag of callbacks
 * ------------------------------------------
 * A rule is a string in an attribute, and it travels to places that cannot run
 * PHP: the admin form, the generated JSON schema, the client bundle. Keeping
 * the vocabulary small and closed is what makes those safe to render and
 * impossible to drift. An unknown rule is therefore an error at schema-build
 * time, not something to skip quietly:
 *
 *     #[Field(type: 'string', rules: ['min:3', 'in:a,b'])]
 *                                                          ^^^^^^^^
 *
 * used to be accepted and enforced as `min:3`. The author believed `in:a,b`
 * was protecting the field. Nothing was wrong, nothing failed, and the
 * constraint was absent — the most expensive kind of bug, because the test that
 * should have caught it also passes. It now raises, naming the model and the
 * rule.
 *
 * min / max follow the DECLARED type, not the shape of the incoming value.
 * On a numeric field they bound the value; on a string field they bound the
 * length. The old implementation asked `is_numeric($value)` instead, so a text
 * field receiving "123" was compared as a number while one receiving "12" was
 * compared as a string — the same field, two contracts, decided by the data
 * rather than by the declaration. A field that declares a type is entitled to
 * a rule that means the same thing every time.
 *
 * @package Phue\Core\Validation
 */
final class RuleEngine
{
    /** Presence is decided by the field's own flags, never by the value. */
    public const RULE_REQUIRED = 'required';

    public const RULE_NUMERIC = 'numeric';
    public const RULE_INTEGER = 'integer';
    public const RULE_EMAIL = 'email';

    /** Rules that carry a numeric parameter after a colon: min:N, max:N. */
    private const PARAMETERISED = ['min', 'max'];

    /** Types whose min/max bound the value rather than its length. */
    private const NUMERIC_TYPES = ['int', 'integer', 'float', 'number', 'decimal', 'numeric'];

    /** Types that cannot hold a numeric bound at all. */
    private const NON_NUMERIC_TYPES = ['bool', 'boolean'];

    /**
     * Rule names that may appear bare, with no parameter.
     */
    private static function knownBareRules(): array
    {
        return [
            self::RULE_REQUIRED,
            self::RULE_NUMERIC,
            self::RULE_INTEGER,
            self::RULE_EMAIL,
        ];
    }

    /**
     * Whether a declared type compares numerically for min / max.
     *
     * @param string $type Declared field type.
     */
    public static function isNumericType(string $type): bool
    {
        $normalised = strtolower(trim($type));

        if (in_array($normalised, self::NON_NUMERIC_TYPES, true)) {
            return false;
        }

        return in_array($normalised, self::NUMERIC_TYPES, true);
    }

    /**
     * Whether the rule array asks for the value to be present.
     *
     * Meaningful for callers deciding between create and update semantics;
     * the rule itself performs no check, because a missing value has no type
     * to validate against.
     *
     * @param array<string> $rules Declared rules.
     */
    public static function isRequired(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (trim($rule) === self::RULE_REQUIRED) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reject any rule the engine cannot enforce.
     *
     * Called while a schema is being built, so an author learns at development
     * time rather than from a 500 in production. The subject is used verbatim
     * in the message: a bare "unknown rule 'in:a,b'" in a project with dozens
     * of models is a worse report than one that names the model.
     *
     * @param array<string> $rules   Declared rules.
     * @param string        $subject Model or entity the rules belong to.
     *
     * @throws InvalidArgumentException When a rule is unknown or malformed.
     */
    public static function assertKnownRules(array $rules, string $subject): void
    {
        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                throw new InvalidArgumentException(sprintf(
                    'Rule must be a string, %s given, on %s.',
                    get_debug_type($rule),
                    $subject,
                ));
            }

            $trimmed = trim($rule);

            if ($trimmed === '') {
                throw new InvalidArgumentException(sprintf(
                    'Empty rule on %s. Remove it rather than leaving a blank entry.',
                    $subject,
                ));
            }

            if (in_array($trimmed, self::knownBareRules(), true)) {
                continue;
            }

            if (str_contains($trimmed, ':')) {
                [$name, $parameter] = explode(':', $trimmed, 2);

                if (in_array(trim($name), self::PARAMETERISED, true)) {
                    if (!is_numeric(trim($parameter))) {
                        throw new InvalidArgumentException(sprintf(
                            "Rule '%s' on %s needs a number, '%s' given. Write '%s:<number>'.",
                            $trimmed,
                            $subject,
                            trim($parameter),
                            trim($name),
                        ));
                    }

                    continue;
                }
            }

            throw new InvalidArgumentException(sprintf(
                "Unknown rule '%s' on %s. Supported: %s.",
                $trimmed,
                $subject,
                implode(', ', self::supportedRuleList()),
            ));
        }
    }

    /**
     * Human-readable list of everything the engine will enforce.
     *
     * @return list<string>
     */
    public static function supportedRuleList(): array
    {
        return [
            self::RULE_REQUIRED,
            self::RULE_NUMERIC,
            self::RULE_INTEGER,
            self::RULE_EMAIL,
            'min:<number>',
            'max:<number>',
        ];
    }

    /**
     * Check one value against one field's rules.
     *
     * Returns at most one message: the first rule that fails. Several rules
     * can be broken at once and the client is shown one string per field, so
     * reporting the first is both the shape the response contract allows and
     * the one that keeps the message deterministic — a loop that overwrote on
     * each failure would report whichever rule happened to be declared last.
     *
     * `required` is intentionally not evaluated here. Callers that enforce it
     * do so before calling this, because "absent" and "invalid" are different
     * questions and answering both produces two messages where the contract
     * has room for one.
     *
     * @param array<string> $rules Declared rules for the field.
     * @param mixed         $value Value under test.
     * @param string        $type  Declared field type.
     * @param string        $field Field name, used in the message.
     *
     * @return string|null The failure message, or null when the value passes.
     */
    public static function check(array $rules, mixed $value, string $type, string $field): ?string
    {
        $numericType = self::isNumericType($type);

        foreach ($rules as $rule) {
            $trimmed = trim((string) $rule);

            if ($trimmed === self::RULE_REQUIRED) {
                continue;
            }

            if ($trimmed === self::RULE_NUMERIC && !is_numeric($value)) {
                return sprintf("Field '%s' must be numeric", $field);
            }

            if (
                $trimmed === self::RULE_INTEGER
                && filter_var($value, FILTER_VALIDATE_INT) === false
            ) {
                return sprintf("Field '%s' must be an integer", $field);
            }

            if (
                $trimmed === self::RULE_EMAIL
                && filter_var($value, FILTER_VALIDATE_EMAIL) === false
            ) {
                return sprintf("Field '%s' must be a valid email", $field);
            }

            if (!str_contains($trimmed, ':')) {
                continue;
            }

            [$name, $parameter] = explode(':', $trimmed, 2);
            $name = trim($name);
            $bound = (float) trim($parameter);

            if ($name === 'min') {
                $failed = $numericType
                    ? is_numeric($value) && (float) $value < $bound
                    : mb_strlen((string) $value) < $bound;
            } elseif ($name === 'max') {
                $failed = $numericType
                    ? is_numeric($value) && (float) $value > $bound
                    : mb_strlen((string) $value) > $bound;
            } else {
                continue;
            }

            if (!$failed) {
                continue;
            }

            return $numericType
                ? sprintf("Field '%s' must be at least %s", $field, self::formatBound($bound))
                : sprintf(
                    "Field '%s' must be at least %s characters",
                    $field,
                    self::formatBound($bound),
                );
        }

        return null;
    }

    /**
     * Render a bound without a trailing ".0" for whole numbers.
     */
    private static function formatBound(float $bound): string
    {
        if ($bound === floor($bound) && abs($bound) < PHP_INT_MAX) {
            return (string) (int) $bound;
        }

        return rtrim(rtrim(sprintf('%.4F', $bound), '0'), '.');
    }
}