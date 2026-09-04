<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Drivers\Pdo\MysqlDriver;
use Phue\Core\Database\Drivers\Pdo\PostgresDriver;

#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PdoDriverConfigTest extends TestCase
{
    public function testMysqlDriverGetName(): void
    {
        $driver = new MysqlDriver([]);
        $this->assertSame('mysql', $driver->getName());
    }

    public function testPostgresDriverGetName(): void
    {
        $driver = new PostgresDriver([]);
        $this->assertSame('pgsql', $driver->getName());
    }

    public function testMysqlDriverIsNotConnectedByDefault(): void
    {
        $driver = new MysqlDriver([]);
        $this->assertFalse($driver->isConnected());
    }

    public function testPostgresDriverIsNotConnectedByDefault(): void
    {
        $driver = new PostgresDriver([]);
        $this->assertFalse($driver->isConnected());
    }

    public function testMysqlDriverDisconnectSetsStateCorrectly(): void
    {
        $driver = new MysqlDriver([]);
        $driver->disconnect();
        $this->assertFalse($driver->isConnected());
    }

    public function testPostgresDriverDisconnectSetsStateCorrectly(): void
    {
        $driver = new PostgresDriver([]);
        $driver->disconnect();
        $this->assertFalse($driver->isConnected());
    }

    public function testMysqlDriverThrowsRuntimeExceptionOnConnectionFailure(): void
    {
        $driver = new MysqlDriver([
            'host' => '192.0.2.1',
            'port' => 19999,
            'database' => 'nonexistent',
            'username' => 'root',
            'password' => '',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MySQL Connection Error/');
        $driver->getPdo();
    }

    public function testPostgresDriverThrowsRuntimeExceptionOnConnectionFailure(): void
    {
        $driver = new PostgresDriver([
            'host' => '192.0.2.1',
            'port' => 19999,
            'database' => 'nonexistent',
            'username' => 'postgres',
            'password' => '',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PostgreSQL Connection Error/');
        $driver->getPdo();
    }

    public function testMysqlDriverDefaultPortAndHost(): void
    {
        $driver = new MysqlDriver([
            'database' => 'testdb',
        ]);

        $this->expectException(\RuntimeException::class);
        $driver->getPdo();
    }

    public function testPostgresDriverDefaultPortAndHost(): void
    {
        $driver = new PostgresDriver([
            'database' => 'testdb',
        ]);

        $this->expectException(\RuntimeException::class);
        $driver->getPdo();
    }

    public function testInTransactionReturnsFalseWhenNotConnected(): void
    {
        $mysqlDriver = new MysqlDriver([]);
        $this->assertFalse($mysqlDriver->inTransaction());

        $pgDriver = new PostgresDriver([]);
        $this->assertFalse($pgDriver->inTransaction());
    }
}
