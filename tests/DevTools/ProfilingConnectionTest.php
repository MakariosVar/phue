<?php

declare(strict_types=1);

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Core\Database\Drivers\Abstract\DriverInterface;
use Phue\Core\Database\Drivers\Abstract\PdoDriverInterface;
use Phue\Modules\DevTool\ProfilingConnection;
use Phue\Modules\DevTool\ProfilingDriver;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;

/**
 * The decorator must not only report; the statements still have to execute and return the
 * right data. These run against a throwaway SQLite file so nothing is mocked.
 */
#[Group('integration')]
class ProfilingConnectionTest extends TestCase
{
    private string $database;
    private RequestProfiler $profiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = sys_get_temp_dir() . '/phue_profiling_db_' . uniqid() . '.sqlite';
        $this->profiler = new RequestProfiler(new Stopwatch());
        $this->profiler->start();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink($this->database);
    }

    private function connection(bool $redact = true): Connection
    {
        return new ProfilingConnection(
            ['driver' => 'sqlite', 'database' => $this->database],
            $this->profiler,
            $redact
        );
    }

    /**
     * Create the schema and a fixture row.
     */
    private function migrate(Connection $connection): void
    {
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, seen INTEGER DEFAULT 0)');
        $connection->execute("INSERT INTO users (email) VALUES ('fixture@example.com')");
        $this->profiler->start();
    }

    // ------------------------------------------------------------ identity

    public function testIsAConnection(): void
    {
        $this->assertInstanceOf(Connection::class, $this->connection());
    }

    public function testDriverIsReplacedWithTheProfilingDecorator(): void
    {
        $driver = $this->connection()->getDriver();

        $this->assertInstanceOf(ProfilingDriver::class, $driver);
    }

    public function testDriverStillIdentifiesItself(): void
    {
        $this->assertSame('sqlite', $this->connection()->getDriver()->getName());
    }

    public function testProxiedCallsReachTheDecorator(): void
    {
        // Connection::__call proxies to the driver; that path must be instrumented too.
        $this->connection()->query('SELECT 1');

        $this->assertContains('db.query', array_column($this->profiler->getSpans(), 'label'));
    }

    public function testReportsItsRedactionMode(): void
    {
        $this->assertTrue($this->connection()->isSqlRedacted());
        $this->assertFalse($this->connection(redact: false)->isSqlRedacted());
    }

    // ------------------------------------------------------------ execution

    public function testSchemaCanBeCreatedThroughTheDecoratedConnection(): void
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->execute("INSERT INTO t (name) VALUES ('a')");

        $rows = $connection->query('SELECT name FROM t')->fetchAll();

        $this->assertSame([['name' => 'a']], $rows);
    }

    public function testSelectReportsSqlBindingCountAndRows(): void
    {
        $connection = $this->connection();
        $this->migrate($connection);

        $statement = $connection->query('SELECT id, email FROM users WHERE email = ?', ['fixture@example.com']);
        $rows = $statement->fetchAll();

        $this->assertCount(1, $rows);

        $spans = $this->profiler->getSpans();
        $query = $spans[0];
        $fetch = $spans[1];

        $this->assertSame('db.query', $query['label']);
        $this->assertSame('SELECT id, email FROM users WHERE email = ?', $query['meta']['sql']);
        $this->assertSame(1, $query['meta']['bindings']);

        $this->assertSame('db.fetch', $fetch['label']);
        $this->assertSame(1, $fetch['meta']['rows']);
    }

    public function testBoundValueNeverAppearsInTheReport(): void
    {
        $connection = $this->connection();
        $this->migrate($connection);

        $connection->query('SELECT id FROM users WHERE email = ?', ['fixture@example.com'])->fetchAll();

        $serialised = json_encode($this->profiler->getSpans());

        $this->assertStringNotContainsString('fixture@example.com', (string) $serialised);
    }

    public function testInterpolatedLiteralIsRedactedEndToEnd(): void
    {
        $connection = $this->connection();
        $this->migrate($connection);

        $connection->query("SELECT id FROM users WHERE email = 'fixture@example.com'")->fetchAll();

        $sql = $this->profiler->getSpans()[0]['meta']['sql'];

        $this->assertSame('SELECT id FROM users WHERE email = ?', $sql);
        $this->assertTrue($this->profiler->getSpans()[0]['meta']['redacted']);
    }

    public function testUpdateReportsAffectedRows(): void
    {
        $connection = $this->connection();
        $this->migrate($connection);

        $affected = $connection->execute('UPDATE users SET seen = 1 WHERE id = ?', [1]);

        $this->assertSame(1, $affected);
        $this->assertSame(1, $this->profiler->getSpans()[0]['meta']['rows']);
    }

    public function testRowCountIsDelegatedToTheDriver(): void
    {
        $connection = $this->connection();
        $this->migrate($connection);

        // The exact value is the driver's business: PDO reports 0 for a SELECT on some
        // drivers until the rows are fetched. What the decorator owes is the pass-through.
        $this->assertIsInt($connection->query('SELECT id FROM users')->rowCount());

        $labels = array_column($this->profiler->getSpans(), 'label');

        $this->assertContains('db.rowCount', $labels);
    }

    public function testTransactionsAreDelegatedAndMeasured(): void
    {
        $connection = $this->connection();
        $this->migrate($connection);

        $connection->execute('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $this->profiler->start();

        $this->assertTrue($connection->getDriver()->beginTransaction());
        $connection->execute("INSERT INTO t (id) VALUES ('1')");
        $this->assertTrue($connection->getDriver()->commit());

        $labels = array_column($this->profiler->getSpans(), 'label');

        $this->assertContains('db.beginTransaction', $labels);
        $this->assertContains('db.commit', $labels);
    }

    public function testRolledBackWorkLeavesNoRows(): void
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $this->profiler->start();

        $driver = $connection->getDriver();
        $driver->beginTransaction();
        $connection->execute('INSERT INTO t (id) VALUES (1)');
        $driver->rollBack();

        $this->assertCount(0, $connection->query('SELECT id FROM t')->fetchAll());
    }

    public function testEveryStatementOfARequestIsVisible(): void
    {
        $connection = $this->connection();
        $this->migrate($connection);

        $connection->query('SELECT id FROM users')->fetchAll();
        $connection->query('SELECT email FROM users')->fetchAll();
        $connection->execute('UPDATE users SET seen = 2');

        $aggregates = $this->profiler->getAggregates();

        $this->assertSame(2, $aggregates['db.query']['count']);
        $this->assertSame(2, $aggregates['db.fetch']['count']);
        $this->assertSame(1, $aggregates['db.execute']['count']);
    }

    public function testConnectionSurvivesADisconnect(): void
    {
        $connection = $this->connection();
        $this->migrate($connection);

        $connection->getDriver()->disconnect();
        $this->assertFalse($connection->getDriver()->isConnected());

        // The next statement must transparently reconnect, as it would unprofiled.
        $this->assertCount(1, $connection->query('SELECT id FROM users')->fetchAll());
    }

    public function testInvalidStatementRaisesAndLeavesNoOpenSpans(): void
    {
        $connection = $this->connection();
        $this->migrate($connection);

        try {
            $connection->query('SELECT * FROM table_that_does_not_exist');
            $this->fail('Expected a database error');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(0, $this->profiler->getOpenSpanCount());
        $this->assertCount(1, $this->profiler->getSpans());
    }

    // ---------------------------------------------------------- capabilities
    //
    // Connection::__call forwards unknown methods to the driver, so anything the real
    // driver can do, a caller may do through the connection. That promise only holds if
    // the decorator keeps the driver's optional capabilities: erase one and every caller
    // of that capability breaks for as long as profiling is switched on — with the error
    // pointing at the decorator, far from the setting that caused it.

    public function testProxiedPdoRequestReachesTheInnerDriver(): void
    {
        $this->assertInstanceOf(\PDO::class, $this->connection()->getPdo());
    }

    public function testDecoratedDriverKeepsItsPdoCapability(): void
    {
        // The health check branches on this interface before reaching for a handle, so a
        // decorator that drops it does not fail loudly — the database check quietly stops
        // running in every developer environment.
        $this->assertInstanceOf(PdoDriverInterface::class, $this->connection()->getDriver());
    }

    public function testProxiedPdoIsTheSameHandleStatementsRunOn(): void
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->execute("INSERT INTO widgets (name) VALUES ('a')");

        $seen = $connection->getPdo()->query('SELECT name FROM widgets')->fetchAll();

        $this->assertSame([['name' => 'a']], $seen);
    }

    public function testProxiedPdoSharesAnOpenTransaction(): void
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');

        $connection->getDriver()->beginTransaction();
        $connection->getPdo()->exec("INSERT INTO widgets (name) VALUES ('doomed')");
        $connection->getDriver()->rollBack();

        $this->assertSame([], $connection->query('SELECT name FROM widgets')->fetchAll());
    }

    public function testIntrospectionSeesWhatTheConnectionCreated(): void
    {
        $connection = $this->connection();
        $this->migrate($connection);

        // What SchemaInspector does first, and what a "diff" is made of: ask the raw
        // handle what the database actually looks like, not what the ORM believes.
        $tables = $connection->getPdo()
            ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains('users', $tables);
    }

    public function testKeepingTheCapabilityKeepsTheProfiling(): void
    {
        // The obvious way to make this error go away is to stop decorating. Asserting
        // that both hold at once is what keeps the fix on the decorator's contract rather
        // than on switching the profiler off.
        $connection = $this->connection();
        $this->migrate($connection);

        $connection->getPdo();
        $connection->query('SELECT id FROM users')->fetchAll();

        $this->assertInstanceOf(ProfilingDriver::class, $connection->getDriver());
        $this->assertContains('db.query', array_column($this->profiler->getSpans(), 'label'));
    }
}