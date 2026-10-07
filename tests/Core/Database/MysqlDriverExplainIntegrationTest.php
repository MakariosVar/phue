<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Drivers\Pdo\MysqlDriver;
use Throwable;

/**
 * Integration tests for EXPLAIN against the live MySQL-family server.
 *
 * This is the regression net for the bug that shipped a MySQL-8-only spelling:
 * on MariaDB every plan failed with `1064 ... near 'ANALYZE SELECT ...'` and the
 * query report showed "EXPLAIN not available" instead of a plan. Unit tests pin
 * the dialect logic; only a live server can prove the spelling really is spoken.
 *
 * Configuration comes from the DB_* environment variables, falling back to the
 * local development server. The test skips itself when no server answers.
 *
 * @package Phue\Tests\Core\Database
 */
#[Group('integration')]
final class MysqlDriverExplainIntegrationTest extends TestCase
{
    private ?MysqlDriver $driver = null;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->driver = new MysqlDriver([
                'host'     => getenv('DB_HOST') ?: '127.0.0.1',
                'port'     => (int) (getenv('DB_PORT') ?: 3306),
                'database' => getenv('DB_DATABASE') ?: 'phue_sql',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '123',
            ]);

            $this->driver->connect();
        } catch (Throwable $e) {
            $this->markTestSkipped('No MySQL-family server available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->driver?->disconnect();
        $this->driver = null;

        parent::tearDown();
    }

    public function testExplainReturnsAPlanForASimpleSelect(): void
    {
        $rows = $this->driver()->explain('SELECT * FROM `users` WHERE `id` = ?', [1]);

        $this->assertNotEmpty($rows, 'A supported server must return a plan.');
        $this->assertArrayHasKey(0, $rows);
        $this->assertIsArray($rows[0]);
    }

    public function testExplainSurvivesEveryDialectTheServerMayBe(): void
    {
        foreach (['SELECT 1', 'SELECT * FROM `users` LIMIT 1', 'SELECT `users`.`id` FROM `users` WHERE `id` = 1'] as $sql) {
            try {
                $rows = $this->driver()->explain($sql);
            } catch (Throwable $e) {
                $this->fail(sprintf('EXPLAIN failed for [%s]: %s', $sql, $e->getMessage()));
            }

            $this->assertNotEmpty($rows, sprintf('EXPLAIN returned nothing for [%s]', $sql));
        }
    }

    public function testExplainAcceptsBindings(): void
    {
        $rows = $this->driver()->explain('SELECT * FROM `users` WHERE `id` = ?', [1]);

        $this->assertNotEmpty($rows);
    }

    public function testExplainFailureIsReportedAsAnExceptionNotAsAnEmptyPlan(): void
    {
        $this->expectException(Throwable::class);

        $this->driver()->explain('THIS IS NOT SQL AT ALL');
    }

    public function testRepeatedPlansReuseTheSameConnection(): void
    {
        $before = $this->driver()->isConnected();

        $this->driver()->explain('SELECT 1');
        $this->driver()->explain('SELECT 2');

        $this->assertSame($before, $this->driver()->isConnected());
    }

    /**
     * Returns the connected driver.
     *
     * @return MysqlDriver Connected driver
     */
    private function driver(): MysqlDriver
    {
        $this->assertNotNull($this->driver, 'The driver should be connected by setUp().');

        return $this->driver;
    }
}