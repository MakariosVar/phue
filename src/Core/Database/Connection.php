<?php

namespace Phue\Core\Database;

use InvalidArgumentException;
use Phue\Core\Database\Drivers\Abstract\DriverInterface;
use Phue\Core\Database\Drivers\Pdo\MysqlDriver;
use Phue\Core\Database\Drivers\Pdo\PostgresDriver;
use Phue\Core\Database\Drivers\Pdo\SqliteDriver;

/**
 * Connection Manager acting as a unified entry point and factory for database drivers.
 *
 * @package Phue\Core\Database
 */
class Connection
{
    protected DriverInterface $driver;

    /**
     * @param array<string, mixed> $config Database configuration options.
     */
    public function __construct(array $config)
    {
        $this->driver = $this->createDriver($config);
    }

    /**
     * Factory method resolving driver implementation based on configuration.
     *
     * @param array<string, mixed> $config
     * @return DriverInterface
     */
    protected function createDriver(array $config): DriverInterface
    {
        $driverName = $config['driver'] ?? 'sqlite';

        return match ($driverName) {
            'sqlite' => new SqliteDriver($config),
            'mysql'  => new MysqlDriver($config),
            'pgsql', 'postgres' => new PostgresDriver($config),
            default  => throw new InvalidArgumentException("Unsupported database driver [{$driverName}]."),
        };
    }

    /**
     * Returns the underlying concrete driver instance.
     */
    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }

    /**
     * Proxies calls directly to the active database driver.
     */
    public function __call(string $method, array $arguments): mixed
    {
        return call_user_func_array([$this->driver, $method], $arguments);
    }

    /**
     * Begins a fluent query builder instance against a specific database table.
     *
     * @param string $table The name of the table to query.
     * @return QueryBuilder
     */
    public function table(string $table): QueryBuilder
    {
        return (new QueryBuilder($this))->table($table);
    }
}