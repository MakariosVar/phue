<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Drivers\Pdo\MysqlDriver;
use ReflectionClass;

/**
 * Unit tests for the MySQL driver's EXPLAIN dialect resolution.
 *
 * `EXPLAIN ANALYZE` is MySQL 8.0.18+ syntax; MariaDB rejects it with 1064 and
 * only understands `ANALYZE <statement>`. Hard-coding one spelling broke every
 * plan on MariaDB, so the driver probes SELECT VERSION() once per connection and
 * falls back to plain EXPLAIN when a spelling is not understood.
 *
 * These tests drive the driver with a fake PDO handle that speaks SQLite, which
 * keeps them free of a live server: the dialect is decided by the injected
 * version string, and the fallback chain is observable through the prepared SQL.
 *
 * @package Phue\Tests\Core\Database
 */
#[Group('unit')]
final class MysqlDriverExplainDialectTest extends TestCase
{
    private const MARIA_VERSION = '10.6.23-MariaDB-0ubuntu0.22.04.1';

    private const MYSQL_VERSION = '8.0.36';

    // ------------------------------------------------------------- dialect choice

    public function testMariaDbIsProbedWithAnalyzeFirst(): void
    {
        $handle = new ExplainingFakePdo(self::MARIA_VERSION);

        $this->driverWithHandle($handle)->explain('SELECT 1');

        $this->assertSame('ANALYZE SELECT 1', $handle->prepared[0]);
    }

    public function testMariaDbFallsBackToPlainExplain(): void
    {
        $handle = new ExplainingFakePdo(self::MARIA_VERSION);

        $this->driverWithHandle($handle)->explain('SELECT 1');

        $this->assertSame(
            ['ANALYZE SELECT 1', 'EXPLAIN SELECT 1'],
            $handle->prepared,
            'MariaDB must end up on the plain EXPLAIN this fake server accepts.'
        );
    }

    public function testMysqlIsProbedWithExplainAnalyzeFirst(): void
    {
        $handle = new ExplainingFakePdo(self::MYSQL_VERSION);

        $this->driverWithHandle($handle)->explain('SELECT 1');

        $this->assertSame('EXPLAIN ANALYZE SELECT 1', $handle->prepared[0]);
    }

    public function testMysqlFallsBackToPlainExplain(): void
    {
        $handle = new ExplainingFakePdo(self::MYSQL_VERSION);

        $this->driverWithHandle($handle)->explain('SELECT 1');

        $this->assertSame(['EXPLAIN ANALYZE SELECT 1', 'EXPLAIN SELECT 1'], $handle->prepared);
    }

    public function testPlanIsReturnedEvenWhenThePreferredSpellingIsRejected(): void
    {
        $handle = new ExplainingFakePdo(self::MARIA_VERSION);

        $rows = $this->driverWithHandle($handle)->explain('SELECT 1');

        $this->assertNotEmpty($rows, 'A rejected spelling must not swallow the plan.');
    }

    public function testFailureOfEverySpellingIsReportedWithBothAttempts(): void
    {
        $handle = new ExplainingFakePdo(self::MARIA_VERSION, rejectEveryPlan: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ANALYZE');

        $this->driverWithHandle($handle)->explain('SELECT 1');
    }

    public function testFailureOfEverySpellingNeverReturnsAnEmptyPlanSilently(): void
    {
        $handle = new ExplainingFakePdo(self::MYSQL_VERSION, rejectEveryPlan: true);

        try {
            $rows = $this->driverWithHandle($handle)->explain('SELECT 1');
            $this->fail('Expected a RuntimeException, got ' . var_export($rows, true));
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('EXPLAIN ANALYZE', $e->getMessage());
            $this->assertStringContainsString('simulated rejection', $e->getMessage());
        }
    }

    // ------------------------------------------------------------- bindings

    public function testPlanKeepsPlaceholdersAndPassesBindingsSeparately(): void
    {
        $handle = new ExplainingFakePdo(self::MYSQL_VERSION);
        $handle->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        $this->driverWithHandle($handle)->explain('SELECT * FROM `users` WHERE `id` = ?', [7]);

        $this->assertStringContainsString(
            '`id` = ?',
            $handle->prepared[0],
            'Bindings must never be inlined into the planned statement.'
        );
    }

    public function testPlanWorksWithPlaceholdersAndBindings(): void
    {
        $handle = new ExplainingFakePdo(self::MYSQL_VERSION);
        $handle->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $handle->exec("INSERT INTO users (id, name) VALUES (7, 'Makarios')");

        $rows = $this->driverWithHandle($handle)->explain('SELECT * FROM `users` WHERE `id` = ?', [7]);

        $this->assertNotEmpty($rows);
    }

    // ------------------------------------------------------------- flavour cache

    public function testFlavourIsProbedOncePerConnection(): void
    {
        $handle = new ExplainingFakePdo(self::MARIA_VERSION);
        $driver = $this->driverWithHandle($handle);

        $driver->explain('SELECT 1');
        $driver->explain('SELECT 2');
        $driver->explain('SELECT 3');

        $this->assertSame(1, $handle->versionProbes);
    }

    public function testFlavourIsProbedOnceAcrossManyPlans(): void
    {
        $handle = new ExplainingFakePdo(self::MARIA_VERSION);
        $driver = $this->driverWithHandle($handle);

        for ($i = 0; $i < 5; $i++) {
            $driver->explain('SELECT ' . $i);
        }

        $this->assertSame(1, $handle->versionProbes);
    }

    public function testFlavourIsReprobedAfterTheHandleChanges(): void
    {
        $first  = new ExplainingFakePdo(self::MARIA_VERSION);
        $driver = $this->driverWithHandle($first);
        $driver->explain('SELECT 1');

        $second = new ExplainingFakePdo(self::MYSQL_VERSION);
        $driver = $this->driverWithHandle($second, replacing: true);
        $driver->explain('SELECT 2');

        $this->assertSame(1, $first->versionProbes);
        $this->assertSame(1, $second->versionProbes, 'A fresh handle must be probed, never trusted from the old one.');
        $this->assertSame('EXPLAIN ANALYZE SELECT 2', $second->prepared[0]);
    }

    // ------------------------------------------------------------- helper

    /**
     * Wires a fake PDO handle into a driver without opening a real connection.
     *
     * @param ExplainingFakePdo $handle Fake handle
     * @param bool $replacing Whether the driver already held another handle
     * @return MysqlDriver Driver bound to the fake handle
     */
    private function driverWithHandle(ExplainingFakePdo $handle, bool $replacing = false): MysqlDriver
    {
        $driver = new MysqlDriver([
            'host'     => '127.0.0.1',
            'database' => 'unused',
            'username' => 'unused',
            'password' => 'unused',
        ]);

        if ($replacing) {
            $driver->disconnect();
        }

        $pdo = (new ReflectionClass($driver))->getParentClass()->getProperty('pdo');
        $pdo->setValue($driver, $handle);

        return $driver;
    }
}

/**
 * A PDO handle that speaks SQLite but reports whatever server version it is told
 * to, records every prepared statement, and can refuse every plan.
 */
final class ExplainingFakePdo extends PDO
{
    /**
     * SQL text of every statement prepared through this handle.
     *
     * @var array<int, string>
     */
    public array $prepared = [];

    /**
     * How many times SELECT VERSION() was probed.
     */
    public int $versionProbes = 0;

    /**
     * @param string $version Version string to report
     * @param bool $rejectEveryPlan Whether every plan attempt must fail
     */
    public function __construct(
        private string $version,
        private bool $rejectEveryPlan = false
    ) {
        parent::__construct('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (!str_contains(strtoupper($query), 'VERSION()')) {
            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        }

        $this->versionProbes++;

        $statement = parent::prepare('SELECT ? AS reported_version');
        $statement->execute([$this->version]);

        return $statement;
    }

    /**
     * {@inheritdoc}
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'reported_version')) {
            return parent::prepare($query, $options);
        }

        $this->prepared[] = $query;

        if ($this->rejectEveryPlan) {
            throw new PDOException('simulated rejection of: ' . $query);
        }

        return parent::prepare($query, $options);
    }
}