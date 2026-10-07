<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Container;
use Phue\Core\Database\Connection;
use Phue\Core\Database\Drivers\Abstract\DriverInterface;
use Phue\Core\Database\Drivers\Abstract\StatementInterface;
use Phue\Core\Database\QueryBuilder;
use Phue\Core\Debug\AsyncDumper;
use Phue\Tests\Support\SqliteFactory;

/**
 * Unit tests for QueryBuilder::dd() — the query debugging report.
 *
 * dd() runs inside a persistent runtime (Swoole, RoadRunner) where terminating
 * the process is not an option: exit() becomes Swoole\ExitException and unwinds
 * the whole pipeline into a 500. These tests pin the contract that replaced it —
 * a full report, handed to the request-scoped dumper, with execution continuing.
 *
 * @package Phue\Tests\Core\Database
 */
#[Group('unit')]
final class QueryBuilderDdTest extends TestCase
{
    private ?Container $previousContainer = null;

    private Container $container;

    private AsyncDumper $dumper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->container          = new Container();
        $this->dumper             = new AsyncDumper();

        $dumper = $this->dumper;

        $this->container->set(AsyncDumper::class, static fn(): AsyncDumper => $dumper);
        Container::setInstance($this->container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    // ------------------------------------------------------------- report shape

    public function testReportExposesTheDocumentedKeys(): void
    {
        $report = SqliteFactory::memory()->table('users')->dd();

        $this->assertSame(
            ['sql', 'bindings', 'compiled', 'explain', 'explain_error', 'driver', 'time', 'text'],
            array_keys($report)
        );
    }

    public function testReportNamesTheDriver(): void
    {
        $report = SqliteFactory::memory()->table('users')->dd();

        $this->assertSame('sqlite', $report['driver']);
    }

    public function testReportCarriesAFloatTimestamp(): void
    {
        $before = microtime(true);

        $report = SqliteFactory::memory()->table('users')->dd();

        $this->assertIsFloat($report['time']);
        $this->assertGreaterThanOrEqual($before, $report['time']);
        $this->assertLessThanOrEqual(microtime(true), $report['time']);
    }

    // ------------------------------------------------------------- process safety

    public function testExecutionContinuesAfterDd(): void
    {
        $reached = false;

        SqliteFactory::memory()->table('users')->dd();
        $reached = true;

        $this->assertTrue($reached, 'dd() must not terminate the current request.');
    }

    public function testDdReturnsAnArrayInsteadOfNever(): void
    {
        $this->assertIsArray(SqliteFactory::memory()->table('users')->dd());
    }

    public function testDdWritesNothingToTheOutputBuffer(): void
    {
        ob_start();

        try {
            SqliteFactory::memory()->table('users')->dd();
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame('', $output, 'dd() must not write to the process output.');
    }

    public function testDdNeverTouchesTheDatabaseWhenTheContainerIsAbsent(): void
    {
        Container::setInstance(null);

        $report = SqliteFactory::memory()->table('users')->dd();

        $this->assertIsArray($report);
        $this->assertSame('SELECT * FROM `users`', $report['sql']);
    }

    // ------------------------------------------------------------- dumper hand-off

    public function testReportIsRecordedInTheRequestScopedDumper(): void
    {
        SqliteFactory::memory()->table('users')->where('id', '=', 1)->dd();

        $dumps = $this->dumper->getDumps();

        $this->assertCount(1, $dumps);
        $this->assertSame('query', $dumps[0]['label']);
        $this->assertSame('query', $dumps[0]['group']);
        $this->assertSame($this->dumper->getRequestId(), $dumps[0]['request_id']);
    }

    public function testRecordedDumpCarriesTheSameReportThatWasReturned(): void
    {
        $report = SqliteFactory::memory()->table('users')->where('id', '=', 1)->dd();

        $this->assertEquals($report, $this->dumper->getDumps()[0]['value']);
    }

    public function testEveryCallRecordsItsOwnDump(): void
    {
        $connection = SqliteFactory::memory();

        $connection->table('users')->where('id', '=', 1)->dd();
        $connection->table('posts')->where('id', '=', 2)->dd();

        $dumps = $this->dumper->getDumps();

        $this->assertCount(2, $dumps);
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ?', $dumps[0]['value']['sql']);
        $this->assertSame('SELECT * FROM `posts` WHERE `id` = ?', $dumps[1]['value']['sql']);
    }

    public function testGlobalHelperReturnsTheSameReportAsTheMethod(): void
    {
        $connection = SqliteFactory::memory();

        $viaHelper = \dd($connection->table('users')->where('id', '=', 1));
        $viaMethod = $connection->table('users')->where('id', '=', 1)->dd();

        $this->assertSame($viaMethod['sql'], $viaHelper['sql']);
        $this->assertSame($viaMethod['text'], $viaHelper['text']);
        $this->assertCount(2, $this->dumper->getDumps());
    }

    public function testDumpTracePointsAtTheCallingLine(): void
    {
        SqliteFactory::memory()->table('users')->dd();

        $sources = array_column($this->dumper->getDumps()[0]['trace'], 'file');

        $this->assertContains(
            __FILE__,
            $sources,
            'A dump that cannot name its caller is useless in a framework with this much indirection.'
        );
    }

    public function testDumpTraceLeadsWithTheFramesOfTheHelperItself(): void
    {
        SqliteFactory::memory()->table('users')->dd();

        $frames = $this->dumper->getDumps()[0]['trace'];

        $this->assertStringEndsWith(
            'QueryBuilder.php',
            $frames[0]['file'],
            'The trace starts inside the debug helper and walks outwards to the caller.'
        );
        $this->assertSame(__FILE__, $frames[count($frames) - 1]['file']);
    }

    public function testReportPrintsBindingValuesVerbatim(): void
    {
        $report = SqliteFactory::memory()
            ->table('users')
            ->where('password', '=', 'hunter2')
            ->dd();

        $this->assertSame(
            ['hunter2'],
            $report['bindings'],
            'A query report must show the literal parameters; the redacted dump() keys never apply to a binding list.'
        );
        $this->assertStringContainsString("`password` = 'hunter2'", $report['compiled']);
        $this->assertStringContainsString("`password` = 'hunter2'", $report['text']);
    }

    // ------------------------------------------------------------- SQL rendering

    public function testSqlKeepsPlaceholdersWhileCompiledInlinesBindings(): void
    {
        $report = SqliteFactory::memory()
            ->table('users')
            ->where('id', '=', 1)
            ->where('name', '=', "O'Brien")
            ->dd();

        $this->assertSame('SELECT * FROM `users` WHERE `id` = ? AND `name` = ?', $report['sql']);
        $this->assertSame([1, "O'Brien"], $report['bindings']);
        $this->assertSame("SELECT * FROM `users` WHERE `id` = 1 AND `name` = 'O\\'Brien'", $report['compiled']);
    }

    public function testCompiledSqlIsForDisplayOnlyAndIsNeverExecuted(): void
    {
        $connection = SqliteFactory::memory();
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->execute("INSERT INTO users (id, name) VALUES (1, 'kept')");

        $connection->table('users')->where('name', '=', 'kept')->dd();

        $rows = $connection->query("SELECT name FROM users WHERE id = 1")->fetch();

        $this->assertSame(['name' => 'kept'], $rows);
    }

    public function testTextLabelsBindingsByTheirIndex(): void
    {
        $report = SqliteFactory::memory()
            ->table('users')
            ->where('id', '=', 7)
            ->where('name', '=', 'Makarios')
            ->dd();

        $this->assertStringContainsString('[0] (int) 7', $report['text']);
        $this->assertStringContainsString('[1] (string) \'Makarios\'', $report['text']);
        $this->assertStringNotContainsString('[$i]', $report['text']);
    }

    public function testTextReportsWhenThereAreNoBindings(): void
    {
        $report = SqliteFactory::memory()->table('users')->dd();

        $this->assertStringContainsString('Bindings: (none)', $report['text']);
    }

    public function testTextContainsTheCompiledSql(): void
    {
        $report = SqliteFactory::memory()->table('users')->where('id', '=', 3)->dd();

        $this->assertStringContainsString('=== Compiled SQL (with bindings inline) ===', $report['text']);
        $this->assertStringContainsString('SELECT * FROM `users` WHERE `id` = 3', $report['text']);
    }

    public function testReportIsJsonEncodable(): void
    {
        $connection = SqliteFactory::memory();
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        $report = $connection->table('users')->where('id', '=', 1)->dd();

        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($report['sql'], $decoded['sql']);
        $this->assertSame($report['driver'], $decoded['driver']);
        $this->assertIsArray($decoded['explain']);
    }

    public function testReportNeverLeaksTheConnectionOrDriver(): void
    {
        $report = SqliteFactory::memory()->table('users')->dd();

        $this->assertSame([], $this->collectObjects($report));
    }

    // ------------------------------------------------------------- plans

    public function testPlanIsFetchedForReadStatements(): void
    {
        $connection = SqliteFactory::memory();
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->execute("INSERT INTO users (id, name) VALUES (1, 'Makarios')");

        $report = $connection->table('users')->where('id', '=', 1)->dd();

        $this->assertNull($report['explain_error']);
        $this->assertNotEmpty($report['explain']);
        $this->assertStringContainsString('=== EXPLAIN ===', $report['text']);
    }

    public function testPlanIsFetchedForQueriesWithoutBindings(): void
    {
        $connection = SqliteFactory::memory();
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        $report = $connection->table('users')->dd();

        $this->assertNull($report['explain_error']);
        $this->assertNotEmpty($report['explain']);
    }

    public function testPlanIsNeverFetchedForWriteStatements(): void
    {
        $connection = SqliteFactory::memory();
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->execute("INSERT INTO users (id, name) VALUES (1, 'Makarios')");

        $builder = new class ($connection) extends QueryBuilder {
            /**
             * {@inheritdoc}
             */
            public function toSql(): string
            {
                return "DELETE FROM `users` WHERE `name` = ?";
            }

            /**
             * {@inheritdoc}
             */
            public function getBindings(): array
            {
                return ['Makarios'];
            }
        };

        $report = $builder->dd();

        $this->assertNull($report['explain'], 'ANALYZE executes the statement it plans.');
        $this->assertStringContainsString('not read-only', (string) $report['explain_error']);
        $this->assertStringContainsString('EXPLAIN not available', $report['text']);

        $remaining = $connection->query('SELECT COUNT(*) AS aggregate FROM users')->fetch();

        $this->assertSame(
            1,
            (int) $remaining['aggregate'],
            'dd() must never apply the statement it refuses to plan.'
        );
    }

    public function testPlanFailureIsCapturedInsteadOfThrown(): void
    {
        $report = $this->connectionThatCannotExplain()->table('users')->where('id', '=', 1)->dd();

        $this->assertNull($report['explain']);
        $this->assertSame('explaining is not possible here', $report['explain_error']);
        $this->assertStringContainsString('=== EXPLAIN not available ===', $report['text']);
        $this->assertStringContainsString('explaining is not possible here', $report['text']);
    }

    public function testPlanFailureStillProducesTheFullSqlReport(): void
    {
        $report = $this->connectionThatCannotExplain()->table('users')->where('id', '=', 1)->dd();

        $this->assertSame('SELECT * FROM `users` WHERE `id` = ?', $report['sql']);
        $this->assertSame([1], $report['bindings']);
        $this->assertSame('SELECT * FROM `users` WHERE `id` = 1', $report['compiled']);
        $this->assertCount(1, $this->dumper->getDumps());
    }

    // ------------------------------------------------------------- helpers

    /**
     * Builds a connection whose driver refuses to produce a plan.
     *
     * @return Connection Connection with an explaining driver that always fails
     */
    private function connectionThatCannotExplain(): Connection
    {
        return new class ([]) extends Connection {
            /**
             * {@inheritdoc}
             */
            protected function createDriver(array $config): DriverInterface
            {
                return new class () implements DriverInterface {
                    public function connect(): void {}

                    public function disconnect(): void {}

                    public function isConnected(): bool
                    {
                        return true;
                    }

                    public function query(string $sql, array $params = []): StatementInterface
                    {
                        throw new \RuntimeException('querying is not possible here');
                    }

                    public function execute(string $sql, array $params = []): int
                    {
                        throw new \RuntimeException('executing is not possible here');
                    }

                    public function lastInsertId(?string $name = null): string|false
                    {
                        return false;
                    }

                    public function beginTransaction(): bool
                    {
                        return true;
                    }

                    public function commit(): bool
                    {
                        return true;
                    }

                    public function rollBack(): bool
                    {
                        return true;
                    }

                    public function inTransaction(): bool
                    {
                        return false;
                    }

                    public function getName(): string
                    {
                        return 'stub';
                    }

                    public function explain(string $sql, array $params = []): array
                    {
                        throw new \RuntimeException('explaining is not possible here');
                    }
                };
            }
        };
    }

    /**
     * Collects every object instance reachable from the given value.
     *
     * @param mixed $value Value to walk
     * @return array<int, object> Objects found
     */
    private function collectObjects(mixed $value): array
    {
        if (is_object($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $found = [];
        foreach ($value as $nested) {
            foreach ($this->collectObjects($nested) as $object) {
                $found[] = $object;
            }
        }

        return $found;
    }
}