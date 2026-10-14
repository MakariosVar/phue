<?php

namespace Phue\Tests\Core\Database;

use BadMethodCallException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Core\Database\Drivers\Pdo\MysqlDriver;
use Phue\Core\Database\Drivers\Pdo\PostgresDriver;
use Phue\Core\Database\Drivers\Pdo\SqliteDriver;
use Phue\Core\Database\Drivers\Abstract\StatementInterface;

/**
 * Unit tests for the Connection Manager and driver factory resolution.
 *
 * @package Phue\Tests\Core\Database
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class ConnectionTest extends TestCase
{
    /**
     * Tests that Connection factory instantiates correct driver classes.
     */
    public function testResolvesCorrectDriverInstance(): void
    {
        $sqliteConn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->assertInstanceOf(SqliteDriver::class, $sqliteConn->getDriver());

        $mysqlConn = new Connection(['driver' => 'mysql', 'host' => 'localhost']);
        $this->assertInstanceOf(MysqlDriver::class, $mysqlConn->getDriver());

        $postgresConn = new Connection(['driver' => 'pgsql', 'host' => 'localhost']);
        $this->assertInstanceOf(PostgresDriver::class, $postgresConn->getDriver());
    }

    /**
     * Tests throwing an exception when an unsupported driver is provided.
     */
    public function testThrowsExceptionForUnsupportedDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver [oracle].');

        new Connection(['driver' => 'oracle']);
    }

    /**
     * Tests proxying method calls from Connection directly to the underlying driver.
     */
    public function testProxiesDriverMethodsSuccessfully(): void
    {
        $connection = new Connection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        // 1. Create table via proxy call
        $connection->execute('CREATE TABLE test_table (id INTEGER PRIMARY KEY, title TEXT)');

        // 2. Insert record via proxy call
        $connection->execute('INSERT INTO test_table (title) VALUES (?)', ['Phue Framework']);

        // 3. Query record via proxy call
        /** @var StatementInterface $stmt */
        $stmt = $connection->query('SELECT * FROM test_table WHERE id = ?', [1]);
        $row = $stmt->fetch();

        $this->assertIsArray($row);
        $this->assertSame('Phue Framework', $row['title']);
    }

    /**
     * Tests that a method the driver does not have fails naming the driver, not the proxy.
     */
    public function testUnsupportedMethodNamesTheDriverAndTheMethod(): void
    {
        $connection = new Connection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        try {
            /** @phpstan-ignore-next-line intentional contract violation */
            $connection->getPdoThatDoesNotExist();
            $this->fail('Expected the proxy to refuse a method the driver does not have');
        } catch (BadMethodCallException $e) {
            // The driver identity is what makes the message diagnosable; the internal
            // callback name that used to surface here identifies nothing.
            $this->assertSame(
                'Driver [sqlite] does not implement [getPdoThatDoesNotExist()].',
                $e->getMessage()
            );
        }
    }

    /**
     * Tests that driver-specific capabilities still proxy on an undecorated connection.
     */
    public function testProxiesDriverCapabilitiesOutsideTheBaseContract(): void
    {
        $connection = new Connection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        // getPdo() is not part of DriverInterface, which is exactly why it can go missing
        // behind a decorator without anything in the base contract noticing.
        $this->assertInstanceOf(\PDO::class, $connection->getPdo());
    }
}