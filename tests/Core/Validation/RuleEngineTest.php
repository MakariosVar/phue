<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Validation\RuleEngine;

/**
 * The vocabulary of #[Field(rules: [...])], and the promise that a rule either
 * means something or is refused.
 *
 * The defect this pins
 * --------------------
 * Rules used to be evaluated by two hand-written loops — one in PHP in the
 * Schii endpoint, one in JavaScript in the admin console — with no shared
 * definition of what a rule means and no check that a rule existed.
 *
 * Two things followed, and both were silent.
 *
 * The copies disagreed. min:N compared the value on the server when the value
 * was numeric and its length otherwise; the browser always compared length.
 * So #[Field(type: 'float', rules: ['min:1000'])] rejected 1200 in the form
 * ("must be at least 1000 characters") and accepted it on save. Nothing was
 * logged and nothing failed; the user was told their input was wrong.
 *
 * A rule nobody implemented was accepted. `in:a,b`, `confirmed`, `regex:/…/`
 * were not recognised by either loop, and neither loop had an else branch, so
 * the constraint was dropped and the model stored a row that violated it. The
 * test written to catch that also passed, because the test exercised the same
 * silent skip. That is the failure this file exists to make impossible: an
 * unimplemented rule now raises, naming the model and the rule, at the moment
 * the schema is built.
 */
#[Group('unit')]
final class RuleEngineTest extends TestCase
{
    // ------------------------------------------------------- the vocabulary

    /**
     * @return array<string, array{0: array<string>}>
     */
    public static function knownRuleProvider(): array
    {
        return [
            'empty' => [[]],
            'required' => [['required']],
            'numeric' => [['numeric']],
            'integer' => [['integer']],
            'email' => [['email']],
            'min' => [['min:3']],
            'max' => [['max:40']],
            'combined' => [['required', 'email', 'min:3', 'max:50']],
            'zero bound' => [['min:0']],
            'decimal bound' => [['min:2.5']],
            'surrounding space tolerated' => [[' min:3 ', ' email ']],
        ];
    }

    #[DataProvider('knownRuleProvider')]
    public function testKnownRulesAreAccepted(array $rules): void
    {
        RuleEngine::assertKnownRules($rules, 'Stub::$field');

        $this->addToAssertionCount(1);
    }

    /**
     * Rule names are exact, case included.
     *
     * Surrounding whitespace is forgiven because attribute arguments get
     * formatted and a trailing space is not a different intent. Case is not:
     * a closed vocabulary is only useful if a typo is refused, and 'Min:3'
     * passing would mean 'MIN:3' should too, and then nothing about the spelling
     * is enforced at all.
     */
    public function testRuleNamesAreCaseSensitive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Unknown rule 'EMAIL'/");

        RuleEngine::assertKnownRules(['EMAIL'], 'Stub::$field');
    }

    /**
     * @return array<string, array{0: array<string>, 1: string}>
     */
    public static function unknownRuleProvider(): array
    {
        return [
            'unimplemented membership' => [['in:a,b'], "Unknown rule 'in:a,b'"],
            'unimplemented confirmation' => [['confirmed'], "Unknown rule 'confirmed'"],
            'unimplemented regex' => [['regex:/^a/'], "Unknown rule 'regex:/^a/'"],
            'misspelling' => [['emial'], "Unknown rule 'emial'"],
            'min without a bound' => [['min'], "Unknown rule 'min'"],
            'unknown rule among known ones' => [
                ['min:3', 'unique', 'max:40'],
                "Unknown rule 'unique'",
            ],
        ];
    }

    #[DataProvider('unknownRuleProvider')]
    public function testUnknownRulesAreRefused(array $rules, string $expectedFragment): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedFragment, '/') . '/');

        RuleEngine::assertKnownRules($rules, 'Stub::$field');
    }

    public function testRefusalNamesTheModelAndTheProperty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/App\\\\Models\\\\User::\$username/');

        RuleEngine::assertKnownRules(['unique'], 'App\Models\User::$username');
    }

    public function testMalformedBoundIsReportedAsSuchRatherThanAsUnknown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Rule 'min:abc' .*needs a number/");

        RuleEngine::assertKnownRules(['min:abc'], 'Stub::$field');
    }

    public function testEmptyRuleIsRefusedRatherThanSkipped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Empty rule/');

        RuleEngine::assertKnownRules([''], 'Stub::$field');
    }

    public function testNonStringRuleIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Rule must be a string/');

        RuleEngine::assertKnownRules([['min' => 3]], 'Stub::$field');
    }

    // -------------------------------------------------- type-driven min / max

    /**
     * The divergence, stated as a test.
     *
     * Same rule, same value, two answers — the browser said "at least 1000
     * characters" for the string "1200" and refused it; the server compared the
     * number and accepted it. The declared type now decides, once.
     *
     * @return array<string, array{0: array<string>, 1: mixed, 2: string, 3: bool}>
     */
    public static function boundProvider(): array
    {
        return [
            // numeric type: the bound is on the value
            'float below bound fails' => [['min:1000'], 999, 'float', true],
            'float at bound passes' => [['min:1000'], 1000, 'float', false],
            'float above bound passes' => [['min:1000'], 1200, 'float', false],
            'int below bound fails' => [['min:10'], 9, 'int', true],
            'negative number compares numerically' => [['min:0'], -5, 'float', true],
            'number alias is numeric' => [['min:1000'], 1200, 'number', false],

            // string type: the bound is on the length
            'short string fails' => [['min:3'], 'ab', 'varchar', true],
            'exact length passes' => [['min:3'], 'abc', 'varchar', false],
            'long string passes' => [['min:3'], 'abcd', 'varchar', false],
            'numeric-looking text is length-bounded' => [
                ['min:3'],
                '1200',
                'text',
                false,
            ],
            'numeric-looking text under the length bound fails' => [
                ['min:6'],
                '1200',
                'text',
                true,
            ],

            // max is the mirror
            'float above max fails' => [['max:50'], 51, 'float', true],
            'float under max passes' => [['max:50'], 49.5, 'float', false],
            'string over max fails' => [['max:3'], 'abcd', 'varchar', true],

            // a boolean is not a number, whatever it looks like
            'boolean is length-bounded' => [['min:1'], '1', 'boolean', false],
        ];
    }

    #[DataProvider('boundProvider')]
    public function testMinMaxFollowTheDeclaredTypeNotTheValue(
        array $rules,
        mixed $value,
        string $type,
        bool $shouldFail,
    ): void {
        $result = RuleEngine::check($rules, $value, $type, 'field');

        if ($shouldFail) {
            $this->assertNotNull($result, "Expected a failure for {$type} value " . var_export($value, true));
            $this->assertStringContainsString("Field 'field'", $result);
            return;
        }

        $this->assertNull($result, "Expected a pass for {$type} value " . var_export($value, true));
    }

    public function testNumericBoundMessageCarriesNoUnit(): void
    {
        $this->assertSame(
            "Field 'price' must be at least 1000",
            RuleEngine::check(['min:1000'], 999, 'float', 'price'),
        );
    }

    public function testLengthBoundMessageSaysCharacters(): void
    {
        $this->assertSame(
            "Field 'name' must be at least 3 characters",
            RuleEngine::check(['min:3'], 'ab', 'varchar', 'name'),
        );
    }

    public function testWholeNumberBoundIsNotRenderedAsADecimal(): void
    {
        // 1000.0 in a message reads like a float leaked out of the parser.
        $this->assertStringNotContainsString(
            '.0',
            (string) RuleEngine::check(['min:1000'], 999, 'float', 'price'),
        );
    }

    public function testFractionalBoundKeepsItsFraction(): void
    {
        $this->assertSame(
            "Field 'ratio' must be at least 2.5",
            RuleEngine::check(['min:2.5'], 2.4, 'float', 'ratio'),
        );
    }

    // ---------------------------------------------------------- simple rules

    public function testNumericRuleRejectsNonNumericText(): void
    {
        $this->assertSame(
            "Field 'price' must be numeric",
            RuleEngine::check(['numeric'], 'abc', 'varchar', 'price'),
        );
    }

    public function testIntegerRuleRejectsAFraction(): void
    {
        $this->assertSame(
            "Field 'qty' must be an integer",
            RuleEngine::check(['integer'], '1.5', 'int', 'qty'),
        );
    }

    public function testEmailRuleAcceptsAValidAddress(): void
    {
        $this->assertNull(RuleEngine::check(['email'], 'a@example.com', 'varchar', 'email'));
    }

    public function testEmailRuleRejectsAMalformedAddress(): void
    {
        $this->assertSame(
            "Field 'email' must be a valid email",
            RuleEngine::check(['email'], 'nope', 'varchar', 'email'),
        );
    }

    /**
     * The first failing rule decides the message.
     *
     * The response carries one string per field. A loop that overwrote on each
     * failure would report whichever rule happened to be declared last, so the
     * same input with the rules reordered would produce a different message
     * for the same mistake.
     */
    public function testFirstFailingRuleWinsDeterministically(): void
    {
        $shortAndMalformed = RuleEngine::check(
            ['min:5', 'email'],
            'ab',
            'varchar',
            'name',
        );

        $this->assertSame("Field 'name' must be at least 5 characters", $shortAndMalformed);

        // Reordering the rules cannot change the verdict.
        $this->assertSame(
            "Field 'name' must be at least 5 characters",
            RuleEngine::check(['min:5', 'email'], 'ab', 'varchar', 'name'),
        );
    }

    // ------------------------------------------------------------- isRequired

    public function testIsRequiredReadsTheRuleList(): void
    {
        $this->assertTrue(RuleEngine::isRequired(['required', 'min:3']));
        $this->assertFalse(RuleEngine::isRequired(['min:3']));
        $this->assertFalse(RuleEngine::isRequired([]));
        $this->assertTrue(RuleEngine::isRequired([' required ']), 'Surrounding space is not a different rule.');
    }

    /**
     * `required` is presence, not a value check.
     *
     * Asserted so the boundary is on the record: a value that IS present is
     * left alone, because deciding whether an absent value is acceptable is the
     * caller's decision — it differs between create and update.
     */
    public function testRequiredRulePerformsNoCheckOnAPresentValue(): void
    {
        $this->assertNull(RuleEngine::check(['required'], 'anything at all', 'varchar', 'name'));
    }

    // ---------------------------------------------------------- isNumericType

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function numericTypeProvider(): array
    {
        return [
            'int' => ['int', true],
            'integer' => ['integer', true],
            'float' => ['float', true],
            'number' => ['number', true],
            'numeric' => ['numeric', true],
            'decimal' => ['decimal', true],
            'uppercase INT' => ['INT', true],
            'padded float' => [' float ', true],
            'varchar' => ['varchar', false],
            'text' => ['text', false],
            'json' => ['json', false],
            'boolean' => ['boolean', false],
            'bool' => ['bool', false],
            'email' => ['email', false],
            'date' => ['date', false],
        ];
    }

    #[DataProvider('numericTypeProvider')]
    public function testNumericTypeDetection(string $type, bool $expected): void
    {
        $this->assertSame($expected, RuleEngine::isNumericType($type));
    }
}