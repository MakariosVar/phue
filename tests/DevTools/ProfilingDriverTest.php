<?php

declare(strict_types=1);

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Drivers\Abstract\DriverInterface;
use Phue\Core\Database\Drivers\Abstract\StatementInterface;
use Phue\Modules\DevTool\ProfilingDriver;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;
use RuntimeException;

/**
 * A scriptable driver double: records what it was asked to do and replays canned results,
 * so delegation and span shape can be asserted without a database.
 */
final class FakeDriver implements DriverInterface
{
    /** @var list<string> */
    public array $calls = [];

    public array $rows = [['id' => 1], ['id' => 2]];

    public int $affected = 3;

    public ?\Throwable $throw = null;

    public function connect(): void
    {
        $this->calls[] = 'connect';
    }

    public function disconnect(): void
    {
        $this->calls[] = 'disconnect';
    }

    public function isConnected(): bool
    {
        $this->calls[] = 'isConnected';

        return true;
    }

    public function query(string $sql, array $params = []): StatementInterface
    {
        $this->calls[] = 'query:' . $sql;
        $this->guard();

        return new FakeStatement($this->rows);
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->calls[] = 'execute:' . $sql;
        $this->guard();

        return $this->affected;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        $this->calls[] = 'lastInsertId';

        return '17';
    }

    public function beginTransaction(): bool
    {
        $this->calls[] = 'beginTransaction';
        $this->guard();

        return true;
    }

    public function commit(): bool
    {
        $this->calls[] = 'commit';
        $this->guard();

        return true;
    }

    public function rollBack(): bool
    {
        $this->calls[] = 'rollBack';
        $this->guard();

        return true;
    }

    public function inTransaction(): bool
    {
        $this->calls[] = 'inTransaction';

        return false;
    }

    public function getName(): string
    {
        $this->calls[] = 'getName';

        return 'fake';
    }

    public function explain(string $sql, array $params = []): array
    {
        $this->calls[] = 'explain:' . $sql;

        return [['plan' => 'fake plan']];
    }

    private function guard(): void
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
    }
}

final class FakeStatement implements StatementInterface
{
    private int $cursor = 0;

    public function __construct(private array $rows)
    {
    }

    public function fetch(): array|false
    {
        return $this->rows[$this->cursor++] ?? false;
    }

    public function fetchAll(): array
    {
        $rest = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);

        return $rest;
    }

    public function fetchColumn(int $columnIndex = 0): mixed
    {
        $row = $this->fetch();

        return $row === false ? null : (is_array($row) ? reset($row) : null);
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }
}

#[Group('unit')]
class ProfilingDriverTest extends TestCase
{
    private FakeDriver $inner;
    private RequestProfiler $profiler;
    private ProfilingDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inner = new FakeDriver();
        $this->profiler = new RequestProfiler(new Stopwatch());
        $this->profiler->start();
        $this->driver = new ProfilingDriver($this->inner, $this->profiler);
    }

    private function labels(): array
    {
        return array_column($this->profiler->getSpans(), 'label');
    }

    // ----------------------------------------------------------- delegation

    public function testConnectionMethodsAreDelegatedUnchanged(): void
    {
        $this->driver->connect();
        $this->driver->disconnect();
        $this->assertTrue($this->driver->isConnected());
        $this->assertSame('17', $this->driver->lastInsertId());
        $this->assertSame('fake', $this->driver->getName());
        $this->assertFalse($this->driver->inTransaction());

        $this->assertSame(
            ['connect', 'disconnect', 'isConnected', 'lastInsertId', 'getName', 'inTransaction'],
            $this->inner->calls
        );
    }

    public function testConnectionMethodsAreNotMeasured(): void
    {
        // Timing isConnected() or getName() thousands of times would bury the report.
        $this->driver->isConnected();
        $this->driver->getName();

        $this->assertSame([], $this->profiler->getSpans());
    }

    public function testQueryReachesTheInnerDriverUnchanged(): void
    {
        $this->driver->query('SELECT 1', [7]);

        $this->assertSame('query:SELECT 1', $this->inner->calls[0]);
    }

    public function testExecuteReturnsTheInnerResult(): void
    {
        $this->assertSame(3, $this->driver->execute('DELETE FROM t WHERE id = ?', [1]));
    }

    public function testReturnedStatementIsUsable(): void
    {
        $statement = $this->driver->query('SELECT * FROM users');

        $this->assertInstanceOf(StatementInterface::class, $statement);
        $this->assertSame([['id' => 1], ['id' => 2]], $statement->fetchAll());
    }

    public function testStatementPassesResultsThrough(): void
    {
        $statement = $this->driver->query('SELECT * FROM users');

        $this->assertSame(['id' => 1], $statement->fetch());
        $this->assertSame(['id' => 2], $statement->fetch());
        $this->assertFalse($statement->fetch());
        $this->assertSame([], $statement->fetchAll());
        $this->assertSame(2, $statement->rowCount());
    }

    public function testFetchColumnPassesTheIndexThrough(): void
    {
        $statement = $this->driver->query('SELECT id FROM users');

        $this->assertSame(1, $statement->fetchColumn(0));
    }

    // -------------------------------------------------------------- spans

    public function testQueryIsRecordedWithSqlAndBindingCount(): void
    {
        $this->driver->query('SELECT * FROM users WHERE id = ?', [1, 2]);

        $span = $this->profiler->getSpans()[0];

        $this->assertSame('db.query', $span['label']);
        $this->assertSame('SELECT * FROM users WHERE id = ?', $span['meta']['sql']);
        $this->assertSame(2, $span['meta']['bindings']);
    }

    public function testExecuteIsRecordedWithAffectedRows(): void
    {
        $this->driver->execute('UPDATE users SET seen_at = NOW()');

        $span = $this->profiler->getSpans()[0];

        $this->assertSame('db.execute', $span['label']);
        $this->assertSame(3, $span['meta']['rows']);
    }

    public function testFetchIsRecordedSeparatelyFromQuery(): void
    {
        // A driver reports "executed" when the statement is prepared. The cost a developer
        // feels is the fetch, so collapsing them points at the wrong line.
        $statement = $this->driver->query('SELECT * FROM users');
        $statement->fetchAll();

        $this->assertSame(['db.query', 'db.fetch'], $this->labels());
    }

    public function testFetchCarriesTheStatementAndRowCount(): void
    {
        $statement = $this->driver->query('SELECT * FROM users WHERE id = ?', [5]);
        $statement->fetchAll();

        $fetch = $this->profiler->getSpans()[1];

        $this->assertSame('SELECT * FROM users WHERE id = ?', $fetch['meta']['sql']);
        $this->assertSame(2, $fetch['meta']['rows']);
    }

    public function testRowTotalIsReportedOnlyOnce(): void
    {
        $statement = $this->driver->query('SELECT * FROM users');
        $statement->fetchAll();
        $statement->fetchAll();

        $fetches = array_values(array_filter(
            $this->profiler->getSpans(),
            static fn(array $s): bool => $s['label'] === 'db.fetch'
        ));

        $this->assertCount(2, $fetches);
        $this->assertSame(2, $fetches[0]['meta']['rows']);
        $this->assertArrayNotHasKey('rows', $fetches[1]['meta'], 'A second fetch must not report 0 rows');
    }

    public function testRowCountIsRecorded(): void
    {
        $statement = $this->driver->query('SELECT * FROM users');
        $statement->rowCount();

        $span = $this->profiler->getSpans()[1];

        $this->assertSame('db.rowCount', $span['label']);
        $this->assertSame(2, $span['meta']['rows']);
    }

    public function testTransactionControlIsRecorded(): void
    {
        $this->driver->beginTransaction();
        $this->driver->commit();
        $this->driver->rollBack();

        // A slow commit is otherwise charged to whichever statement ran last.
        $this->assertSame(['db.beginTransaction', 'db.commit', 'db.rollBack'], $this->labels());
    }

    public function testEveryStatementOfARequestIsCounted(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->driver->query('SELECT ' . $i);
        }

        $this->assertSame(12, $this->profiler->getAggregates()['db.query']['count']);
    }

    public function testSpansAreAggregatedUnderTheDatabasePrefix(): void
    {
        $this->driver->query('SELECT 1');
        $this->driver->execute('DELETE FROM t');

        foreach (array_keys($this->profiler->getAggregates()) as $label) {
            $this->assertStringStartsWith('db.', $label);
        }
    }

    // ------------------------------------------------------------ failures

    public function testSpanIsClosedWhenTheDriverThrows(): void
    {
        $this->inner->throw = new RuntimeException('deadlock');

        try {
            $this->driver->query('SELECT 1');
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('deadlock', $e->getMessage());
        }

        $this->assertSame(0, $this->profiler->getOpenSpanCount());
        $this->assertCount(1, $this->profiler->getSpans());
    }

    public function testSpanIsClosedWhenExecuteThrows(): void
    {
        $this->inner->throw = new RuntimeException('constraint violation');

        try {
            $this->driver->execute('INSERT INTO t VALUES (1)');
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, $this->profiler->getOpenSpanCount());
    }

    public function testEmptyResultIsReportedAsZeroRows(): void
    {
        $this->inner->rows = [];

        $statement = $this->driver->query('SELECT * FROM users WHERE id = ?', [999]);
        $statement->fetchAll();

        $fetch = array_values(array_filter(
            $this->profiler->getSpans(),
            static fn(array $s): bool => $s['label'] === 'db.fetch'
        ))[0];

        $this->assertSame(0, $fetch['meta']['rows']);
    }

    // ------------------------------------------------------------ redaction

    public function testInterpolatedLiteralsAreRedactedByDefault(): void
    {
        // The only path that can carry a real row of user data into a rendered page.
        $this->driver->query("SELECT * FROM users WHERE email = 'bob@example.com'");

        $span = $this->profiler->getSpans()[0];

        $this->assertSame('SELECT * FROM users WHERE email = ?', $span['meta']['sql']);
        $this->assertTrue($span['meta']['redacted']);
        $this->assertStringNotContainsString('bob@example.com', json_encode($this->profiler->getSpans()));
    }

    public function testDoubleQuotedLiteralsAreRedacted(): void
    {
        $this->driver->query('SELECT * FROM t WHERE name = "secret"');

        $this->assertStringNotContainsString('secret', $this->profiler->getSpans()[0]['meta']['sql']);
    }

    public function testCommentsAreRedacted(): void
    {
        $this->driver->query("SELECT 1 -- password: hunter2");

        $sql = $this->profiler->getSpans()[0]['meta']['sql'];

        $this->assertStringNotContainsString('hunter2', $sql);
        $this->assertStringContainsString('--', $sql);
    }

    public function testBlockCommentsAreRedacted(): void
    {
        $this->driver->query("SELECT /* internal note */ 1");

        $this->assertStringNotContainsString('internal note', $this->profiler->getSpans()[0]['meta']['sql']);
    }

    public function testRedactionPreservesStructure(): void
    {
        // The point of redacting rather than hiding: the query stays diagnosable.
        $this->driver->query("SELECT id, name FROM users WHERE tenant = 'acme' AND status = 'active' LIMIT 10");

        $sql = $this->profiler->getSpans()[0]['meta']['sql'];

        $this->assertStringContainsString('SELECT id, name FROM users', $sql);
        $this->assertStringContainsString('tenant = ?', $sql);
        $this->assertStringContainsString('status = ?', $sql);
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function testRedactionIsOptional(): void
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        $driver = new ProfilingDriver($this->inner, $profiler, redactLiterals: false);

        $driver->query("SELECT * FROM users WHERE email = 'bob@example.com'");

        $span = $profiler->getSpans()[0];

        $this->assertStringContainsString('bob@example.com', $span['meta']['sql']);
        $this->assertFalse($span['meta']['redacted']);
    }

    public function testRedactionIsIdempotent(): void
    {
        $once = ProfilingDriver::redact("WHERE a = 'x' AND b = 'y'");

        $this->assertSame($once, ProfilingDriver::redact($once));
    }

    public function testRedactionHandlesEmptyAndPlainInput(): void
    {
        $this->assertSame('', ProfilingDriver::redact(''));
        $this->assertSame('SELECT 1', ProfilingDriver::redact('SELECT 1'));
    }

    public function testRedactionHandlesEscapedQuotes(): void
    {
        $sql = ProfilingDriver::redact("WHERE name = 'O\\'Brien'");

        $this->assertStringNotContainsString('Brien', $sql);
    }

    public function testPlaceholdersAreLeftAlone(): void
    {
        $this->assertSame(
            'SELECT * FROM users WHERE id = ?',
            ProfilingDriver::redact('SELECT * FROM users WHERE id = ?')
        );
    }
}