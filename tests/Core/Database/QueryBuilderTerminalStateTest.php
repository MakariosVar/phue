<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database;

use Phue\Core\Database\QueryBuilder;
use Phue\Tests\Support\UsesIsolatedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The state of a QueryBuilder after a terminal method runs.
 *
 * A builder is an accumulator. Every non-terminal call adds to it and returns
 * `$this`, which is the whole reason chaining reads well. Terminal calls —
 * first(), count(), get(), update(), delete() — are supposed to *consume* the
 * accumulator without changing what the next caller sees.
 *
 * Three of them do not, and the builder is reusable, so the damage is silent
 * and delayed:
 *
 *   - `first()` sets LIMIT 1 and never puts it back. The next `get()` on the
 *     same builder silently returns one row. `count()` right above it does
 *     save and restore columns/limit/offset, so the correct pattern is
 *     already in the file, twenty lines away.
 *
 *   - The WHERE assembly loop is written out three times (toSql, update,
 *     delete), and each copy has its own `if ($index === 0)` prefix decision.
 *
 *   - addWhereClause() has an arity switch with no type test. `where()` takes
 *     (column, operator, value) but detects "two-argument form" by testing
 *     `$value === null`. That test cannot tell "the caller passed two
 *     arguments" from "the caller passed three and wanted a NULL comparison",
 *     so the three-argument form is unreachable and silently corrupts.
 *
 * @package Phue\Tests\Core\Database
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class QueryBuilderTerminalStateTest extends TestCase
{
    use UsesIsolatedDatabase;

    private QueryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->isolatedDatabase();

        $db->execute('
            CREATE TABLE query_state_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                email TEXT,
                nickname TEXT,
                deleted_at DATETIME NULL
            )
        ');

        $db->execute("INSERT INTO query_state_users (name, email, nickname) VALUES
            ('ada', 'ada@example.com', 'ada'),
            ('bob', 'bob@example.com', 'bobby'),
            ('cleo', 'cleo@example.com', 'cleo')");

        $this->builder = (new QueryBuilder($db))->table('query_state_users');
    }

    public function testFirstDoesNotLeakItsLimitIntoTheNextQuery(): void
    {
        $this->builder->first();

        $sql = $this->builder->toSql();

        $this->assertStringNotContainsString(
            'LIMIT',
            strtoupper($sql),
            'first() left LIMIT 1 on the builder. A reused builder now returns one row where the '
            . 'caller asked for the full set, with no error and no warning.'
        );
    }

    public function testFirstStillLimitsTheQueryItActuallyRuns(): void
    {
        $first = $this->builder->first();

        $this->assertIsArray($first);
        $this->assertSame('ada', $first['name'], 'first() must still return exactly one row.');
    }

    public function testAnExplicitLimitSurvivesFirst(): void
    {
        // The distinction that matters: first() must not *add* a limit, and must
        // not discard one the caller asked for either.
        $this->builder->limit(2);

        $this->builder->first();

        $this->assertStringContainsString(
            'LIMIT 2',
            strtoupper($this->builder->toSql()),
            'first() overwrote a limit the caller set explicitly.'
        );
    }

    public function testCountRestoresTheBuilderStateItChanged(): void
    {
        // Control for the test above: count() already does this correctly, and
        // first() sitting three methods away is the only reason it is surprising.
        $this->builder->count();

        $this->assertStringNotContainsString('LIMIT', strtoupper($this->builder->toSql()));
        $this->assertStringNotContainsString('COUNT(*)', $this->builder->toSql());
    }

    public function testWhereWithAnExplicitNullValueCompilesToIsNull(): void
    {
        $this->builder->where('nickname', '=', null);

        $this->assertStringContainsString(
            'IS NULL',
            $this->builder->toSql(),
            'where(column, "=", null) must compare against NULL, not bind the operator string.'
        );

        $this->assertSame(
            [],
            $this->builder->getBindings(),
            'IS NULL takes no placeholder. A bound null would never match, which reads as a broken filter.'
        );
    }

    public function testWhereNotEqualToNullCompilesToIsNotNull(): void
    {
        $this->builder->where('nickname', '!=', null);

        $this->assertStringContainsString('IS NOT NULL', $this->builder->toSql());
        $this->assertSame([], $this->builder->getBindings());
    }

    public function testTwoArgumentFormWithANullValueAlsoCompilesToIsNull(): void
    {
        // where('col', $nullableValue) where the value happens to be null.
        // Before, this produced `col = ?` bound to null — a clause that can
        // never be true, so the query returned nothing for no visible reason.
        $this->builder->where('nickname', null);

        $this->assertStringContainsString('IS NULL', $this->builder->toSql());
        $this->assertSame([], $this->builder->getBindings());
    }

    public function testWhereWithTwoArgumentsStillBindsTheValue(): void
    {
        $this->builder->where('name', 'ada');

        $this->assertSame(['ada'], $this->builder->getBindings());
    }

    public function testWhereComparingAgainstNullUsesIsNull(): void
    {
        $this->builder->where('nickname', 'IS', null);

        $sql = $this->builder->toSql();

        $this->assertStringContainsString('IS NULL', $sql, 'A NULL comparison must compile to IS NULL.');
        $this->assertSame([], $this->builder->getBindings(), 'IS NULL takes no placeholder.');
    }

    /**
     * The two-argument form is safe: the second argument becomes a bound value,
     * never SQL. This pins that, because it is the reason the arity bug above
     * is a data-corruption bug rather than an injection bug.
     */
    public function testTwoArgumentFormCannotInjectSql(): void
    {
        $this->builder->where('name', '= 1; DROP TABLE query_state_users; --');

        $this->assertSame(['= 1; DROP TABLE query_state_users; --'], $this->builder->getBindings());
        $this->assertStringNotContainsString('DROP TABLE', $this->builder->toSql());
    }

    public function testThreeArgumentFormCannotInjectAnOperator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder->where('name', '= 1; DROP TABLE query_state_users; --', 'x');
    }
}