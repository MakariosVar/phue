<?php

namespace Phue\Core\Database;

use BadMethodCallException;
use InvalidArgumentException;
use Phue\Core\ResettableInterface;
use Phue\Core\Database\Drivers\Abstract\DriverInterface;
use Phue\Core\Database\Drivers\Pdo\MysqlDriver;
use Phue\Core\Database\Drivers\Pdo\PostgresDriver;
use Phue\Core\Database\Drivers\Pdo\SqliteDriver;

/**
 * Connection Manager acting as a unified entry point and factory for database drivers.
 *
 * @package Phue\Core\Database
 */
class Connection implements ResettableInterface
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
     *
     * Anything the driver contract defines can be called through the connection, which is
     * what makes `$connection->query(...)` and `$connection->getDriver()->query(...)` the
     * same statement.
     *
     * The guard exists because the proxy is where a wrong call used to surface as a
     * TypeError from call_user_func_array naming an internal callback instead of the
     * driver that could not serve it. A driver reached through a decorator may not carry
     * every method the undecorated one had, and the reader of the message needs the
     * driver's own identity for that to be diagnosable — getName() rather than the class,
     * because a decorator may be an anonymous class and "ProfilingDriver@anonymous
     * /app/src/Modules/DevTool/ProfilingDriver.php:82" tells nobody which database was
     * being asked.
     *
     * @param string $method Method requested on the driver.
     * @param array<int, mixed> $arguments Arguments to forward.
     * @return mixed
     *
     * @throws BadMethodCallException If the active driver does not implement the method.
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (!method_exists($this->driver, $method)) {
            throw new BadMethodCallException(sprintf(
                'Driver [%s] does not implement [%s()].',
                $this->driver->getName(),
                $method
            ));
        }

        return $this->driver->{$method}(...$arguments);
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

    /**
     * Resets driver state and rolls back open transactions upon scope cleanup.
     *
     * @return void
     */
    public function reset(): void
    {
        if (method_exists($this->driver, 'inTransaction') && $this->driver->inTransaction()) {
            $this->driver->rollBack();
        }
    }
}