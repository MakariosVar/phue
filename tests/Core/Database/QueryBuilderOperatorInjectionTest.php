<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Core\Database\QueryBuilder;

/**
 * Regression gate for the comparison-operator injection hole in
 * QueryBuilder::addWhereClause().
 *
 * Background
 * ----------
 * addWhereClause() computes a normalised operator ($cleanOperator) and then
 * ignores it, interpolating the RAW $operator into the SQL string:
 *
 *     $cleanOperator = strtoupper(trim((string)$operator));
 *     $sqlClause     = "{$quotedColumn} {$operator} ?";
 *
 * The normalised value is only consulted to decide whether to append the
 * LIKE ... ESCAPE clause. So the identifier is quoted and the value is bound,
 * but the operator itself is fully attacker-controlled SQL text.
 *
 * This class encodes the security invariant in a design-independent way (the
 * "must not leak" tests) plus the specific chosen contract (the "rejects"
 * tests). If the operator whitelist is implemented by normalising instead of
 * throwing, only the testContract* methods need to change — the security
 * tests must keep passing either way.
 *
 * @package Phue\Tests\Core\Database
 */
#[Group('integration')]
#[Group('security')]
final class QueryBuilderOperatorInjectionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new Connection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->connection->execute(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, age INTEGER, status TEXT)'
        );

        $this->connection->execute(
            "INSERT INTO users (name, email, age, status) VALUES
             ('alice', 'alice@example.com', 30, 'active'),
             ('bob',   'bob@example.com',   17, 'active'),
             ('carol', 'carol@example.com', 45, 'pending')"
        );
    }

    // ---------------------------------------------------------------------
    // Security invariants — these hold under ANY accepted fix.
    // ---------------------------------------------------------------------

    /**
     * The headline exploit: a tautology smuggled through the operator slot
     * must not return the whole table.
     *
     * Exactly two acceptable outcomes, and only two:
     *   - the payload is rejected outright (the whitelist throws), or
     *   - the payload is neutralised and the query stays filtered.
     * Returning the whole table is the sole failure mode.
     */
    #[Group('p0')]
    public function testTautologySmuggledThroughOperatorSlotDoesNotReturnEveryRow(): void
    {
        try {
            $rows = $this->connection->table('users')
                ->where('id', '= 1 OR 1=1 OR id =', 1)
                ->get();
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertLessThan(
            3,
            count($rows),
            'Operator injection returned the entire table — the WHERE clause was bypassed.'
        );
    }

    /**
     * A subquery in the operator slot must never be executed.
     *
     * The builder always appends its own `?` right after the operator, so
     * the payload ends on a dangling comparison that the placeholder legally
     * completes. Unfiltered, the generated SQL is valid and returns every row.
     */
    #[Group('p0')]
    public function testSubquerySmuggledThroughOperatorSlotIsNotExecuted(): void
    {
        try {
            $rows = $this->connection->table('users')
                ->where('id', '= 1 OR id IN (SELECT id FROM users) OR id =', 1)
                ->get();
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertLessThan(
            3,
            count($rows),
            'A subquery reached the generated SQL through the operator slot and was executed.'
        );
    }

    /**
     * A stacked statement must not be smuggled through, and the table must
     * survive the attempt regardless of how the driver reacts.
     */
    #[Group('p0')]
    public function testStackedStatementSmuggledThroughOperatorSlotCannotMutateTheTable(): void
    {
        $before = $this->connection->table('users')->count();

        try {
            $this->connection->table('users')
                ->where('id', '= 1; DELETE FROM users WHERE 1=1; --', 1)
                ->get();
        } catch (\Throwable) {
            // Any driver-level rejection is an acceptable outcome here; a
            // mutated table is not.
        }

        $this->assertSame(
            $before,
            $this->connection->table('users')->count(),
            'Stacked statement smuggled through the operator slot modified the table.'
        );
    }

    /**
     * The generated SQL must contain exactly one comparison operator for the
     * clause — no extra SQL keywords may appear after the quoted identifier.
     */
    #[Group('p0')]
    public function testGeneratedSqlContainsNoExtraKeywordsAfterTheQuotedColumn(): void
    {
        try {
            $sql = $this->connection->table('users')
                ->where('name', '= 1 OR 1=1 OR name =', 'alice')
                ->toSql();
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertStringNotContainsString(
            'OR',
            $sql,
            "Raw operator text leaked into the compiled SQL:\n" . $sql
        );
    }

    // ---------------------------------------------------------------------
    // Chosen contract: unknown operators are rejected.
    //
    // If the implementation instead normalises unknown operators to a safe
    // default, these four tests are the ones to revisit.
    // ---------------------------------------------------------------------

    #[Group('p0')]
    public function testContractRejectsUnknownOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->connection->table('users')->where('age', 'BETWEEN 1 AND 999', 30)->toSql();
    }

    #[Group('p0')]
    public function testContractRejectsUnknownOperatorOnOrWhere(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->connection->table('users')->orWhere('age', 'UNION SELECT 1', 30)->toSql();
    }

    #[Group('p0')]
    public function testContractRejectsOperatorCarryingSqlKeywords(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->connection->table('users')->where('id', '= 1 OR 1=1 OR id =', 1)->toSql();
    }

    /**
     * orderBy() already throws InvalidArgumentException for a bad direction.
     * where() must behave the same way for a bad operator — this test exists
     * so the two stay symmetric if someone regresses one of them.
     */
    #[Group('p0')]
    public function testContractOperatorValidationIsSymmetricWithOrderByDirection(): void
    {
        try {
            $this->connection->table('users')->orderBy('name', 'ASC; DROP TABLE users')->toSql();
            $directionRejected = false;
        } catch (InvalidArgumentException) {
            $directionRejected = true;
        }

        try {
            $this->connection->table('users')->where('name', 'LIKE; DROP TABLE users', '%a%')->toSql();
            $operatorRejected = false;
        } catch (InvalidArgumentException) {
            $operatorRejected = true;
        }

        $this->assertTrue(
            $directionRejected,
            'orderBy() no longer rejects an invalid direction.'
        );
        $this->assertTrue(
            $operatorRejected,
            'where() does not reject an invalid operator, while orderBy() rejects an invalid direction.'
        );
    }

    // ---------------------------------------------------------------------
    // The whitelist must not break legitimate queries.
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function validOperatorProvider(): array
    {
        return [
            'equals'                => ['='],
            'not equals (bang)'     => ['!='],
            'not equals (angle)'    => ['<>'],
            'less than'             => ['<'],
            'less or equal'         => ['<='],
            'greater than'          => ['>'],
            'greater or equal'      => ['>='],
            'like'                  => ['LIKE'],
            'not like'              => ['NOT LIKE'],
            'is'                    => ['IS'],
            'is not'                => ['IS NOT'],
        ];
    }

    /**
     * Every operator the framework documents must survive the whitelist.
     *
     * @param string $operator
     */
    #[DataProvider('validOperatorProvider')]
    #[Group('p0')]
    public function testDocumentedOperatorsSurviveTheWhitelist(string $operator): void
    {
        $sql = $this->connection->table('users')
            ->where('age', $operator, 30)
            ->toSql();

        $this->assertStringContainsString(
            $operator,
            $sql,
            "Legitimate operator [{$operator}] was mangled or rejected:\n" . $sql
        );
    }

    /**
     * Operators are case-insensitive, exactly like orderBy()'s direction.
     */
    #[Group('p0')]
    public function testOperatorMatchingIsCaseInsensitive(): void
    {
        $sql = $this->connection->table('users')
            ->where('name', 'like', 'a%')
            ->toSql();

        $this->assertMatchesRegularExpression(
            '/name`?\s+like\s+\?/i',
            $sql,
            "Lowercase operator did not compile:\n" . $sql
        );
    }

    /**
     * The LIKE ... ESCAPE clause is the one piece of real logic that depends
     * on the normalised operator. A whitelist must not silently drop it, or
     * every LIKE query silently changes matching semantics.
     */
    #[Group('p0')]
    public function testLikeStillEmitsEscapeClauseAfterNormalisation(): void
    {
        foreach (['LIKE', 'like', 'NOT LIKE', 'not like'] as $operator) {
            $sql = $this->connection->table('users')
                ->where('name', $operator, '%a%')
                ->toSql();

            $this->assertStringContainsString(
                "ESCAPE '" . QueryBuilder::LIKE_ESCAPE_CHAR . "'",
                $sql,
                "ESCAPE clause lost for operator [{$operator}]:\n" . $sql
            );
        }
    }

    /**
     * The escape character must not be a backslash.
     *
     * MySQL and MariaDB treat a backslash as an escape character inside a
     * string literal, so `ESCAPE '\'` is an unterminated literal to them and the
     * whole query dies with a syntax error on the text that follows it. SQLite
     * accepts it, which is exactly why a SQLite-only suite cannot catch this.
     * The clause has to be valid for every driver the framework claims to support.
     */
    #[Group('p0')]
    public function testEscapeCharacterIsDriverIndependent(): void
    {
        self::assertNotSame(
            '\\',
            QueryBuilder::LIKE_ESCAPE_CHAR,
            'A backslash escape character produces invalid SQL on MySQL/MariaDB.'
        );

        $sql = $this->connection->table('users')
            ->where('name', 'LIKE', '%a%')
            ->toSql();

        self::assertStringNotContainsString("ESCAPE '\\'", $sql);
    }

    /**
     * escapeLikePattern() and the ESCAPE clause have to agree on the character,
     * or a pattern escaped for one is reinterpreted as a wildcard by the other.
     */
    #[Group('p0')]
    public function testEscapeHelperAgreesWithTheEscapeClause(): void
    {
        self::assertSame('!%', QueryBuilder::escapeLikePattern('%'), 'a wildcard must be escaped');
        self::assertSame('!_', QueryBuilder::escapeLikePattern('_'), 'the single-char wildcard must be escaped');
        self::assertSame('!!', QueryBuilder::escapeLikePattern('!'), 'the escape char must escape itself');
        self::assertSame('abc', QueryBuilder::escapeLikePattern('abc'), 'plain text must be untouched');

        // A single pass, not sequential replacement: escaping '!' first and '%'
        // second would turn the '!' just inserted into '!!' and corrupt the pattern.
        self::assertSame('!!!%', QueryBuilder::escapeLikePattern('!%'));
        self::assertSame('!%!_!!', QueryBuilder::escapeLikePattern('%_!'));

        // The point of the whole change: no backslash may reach the pattern, or it
        // would be a literal character under a clause that declares '!'.
        self::assertStringNotContainsString('\\', QueryBuilder::escapeLikePattern('a%b_c\\d'));
    }

    /**
     * Non-LIKE operators must not gain an ESCAPE clause.
     */
    #[Group('p0')]
    public function testNonLikeOperatorsDoNotEmitEscapeClause(): void
    {
        $sql = $this->connection->table('users')
            ->where('age', '>=', 18)
            ->toSql();

        $this->assertStringNotContainsString('ESCAPE', $sql);
    }

    /**
     * The two-argument form where('status', 'active') must keep working —
     * it is used on every hot path in the framework.
     */
    #[Group('p0')]
    public function testTwoArgumentFormStillCompilesAsEquals(): void
    {
        $builder = $this->connection->table('users')->where('status', 'active');

        $this->assertMatchesRegularExpression(
            '/status`?\s*=\s*\?/',
            $builder->toSql()
        );
        $this->assertSame(['active'], $builder->getBindings());
    }

    /**
     * End-to-end: a filtered query must still filter after the whitelist lands.
     */
    #[Group('p0')]
    public function testFilteredQueryStillFiltersAfterTheWhitelist(): void
    {
        $rows = $this->connection->table('users')
            ->where('age', '>=', 18)
            ->where('status', '=', 'active')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('alice', $rows[0]['name'] ?? null);
    }

    /**
     * A binding that looks like an operator must stay a value.
     *
     * This is the second injection shape flagged in the review: where() cannot
     * distinguish "two arguments" from "three arguments with an explicit null
     * value", so where('id', '>', null) degrades to `= ?` with ">" as the
     * bound value. Whatever the operator whitelist does, a value must never be
     * promoted into the SQL text.
     */
    #[Group('p0')]
    public function testBoundValueIsNeverPromotedIntoSqlText(): void
    {
        $builder = $this->connection->table('users')->where('name', '=', 'alice OR 1=1');

        $this->assertStringNotContainsString('1=1', $builder->toSql());
        $this->assertSame(['alice OR 1=1'], $builder->getBindings());
    }
}
