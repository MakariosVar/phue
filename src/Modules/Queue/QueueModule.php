<?php

declare(strict_types=1);

namespace Phue\Modules\Queue;

use Phue\Core\Config;
use Phue\Core\Console\Application as ConsoleApplication;
use Phue\Core\Database\Connection;
use Phue\Core\Module;
use Phue\Modules\Queue\Commands\QueueFailedCommand;
use Phue\Modules\Queue\Commands\QueueInstallCommand;
use Phue\Modules\Queue\Commands\QueuePruneCommand;
use Phue\Modules\Queue\Commands\QueueStatusCommand;
use Phue\Modules\Queue\Commands\QueueWorkCommand;
use Phue\Modules\Queue\Driver\DatabaseQueueDriver;
use Phue\Modules\Queue\Driver\SyncQueueDriver;

/**
 * Background jobs for the application.
 *
 * A layer module: shipped with the framework, present in config/app.php, and removable by a
 * project that does not want it. Nothing here requires the database module — the queue needs a
 * store, not a framework module, and a queue with no driver configured falls back to running jobs
 * inline. That fallback is the point: a developer who has not configured a database still gets
 * their job executed, with its failure in front of them, rather than a silently dropped job.
 *
 * @package Phue\Modules\Queue
 */
class QueueModule extends Module
{
    /**
     * @var string Module ID, and the container key this is registered under as module.queue.
     */
    public string $id = 'queue';

    /**
     * @var string A framework layer rather than an application kit.
     */
    public string $type = 'layer';

    /**
     * @var array<int, string> No module is required, so nothing can make mounting this fail.
     */
    public array $requires = [];

    /**
     * Drivers shipped with the framework.
     *
     * A name that is not in this list and is not a class implementing QueueDriverInterface is
     * rejected while the module boots, which is earlier than the first push and much earlier than
     * the first worker noticing it has nothing to reserve.
     *
     * @var array<string, class-string<QueueDriverInterface>>
     */
    private const DRIVERS = [
        'sync' => SyncQueueDriver::class,
        'database' => DatabaseQueueDriver::class,
    ];

    /**
     * Resolve settings, then publish the bindings.
     *
     * @return void
     */
    public function init(): void
    {
        $this->config();
        $this->bindings();
    }

    /**
     * Publish the validated configuration.
     *
     * The whole `queue` section is read and validated here rather than lazily, so a typo in a
     * queue name is a boot failure instead of a surprise on the first retry.
     *
     * @return void
     */
    protected function config(): void
    {
        $this->container->singleton(
            QueueConfig::class,
            function (\Phue\Core\Container $container): QueueConfig {
                return QueueConfig::fromContainer($container->get(Config::class));
            }
        );
    }

    /**
     * Publish the driver, the manager and the codec.
     *
     * The driver is bound by closure rather than built now, so that an application which never
     * queues anything never constructs a store. Only the driver *name* is resolved eagerly,
     * because that is the one mistake worth failing on at boot.
     *
     * @return void
     */
    protected function bindings(): void
    {
        $driverClass = $this->driverClassFor($this->container->get(QueueConfig::class));

        // Checked here, while the module is mounting, rather than left to fail the first time a
        // job is pushed. bound() is the right question — it asks whether something published a
        // Connection, without opening one, so this costs nothing and does not make the queue a
        // reason to open a database during a boot that will never use it.
        if ($driverClass === DatabaseQueueDriver::class && !$this->container->bound(Connection::class)) {
            throw new \InvalidArgumentException(
                'queue.driver is "database" but no database connection is configured. Set '
                . 'database.default in config/database.php, or set QUEUE_DRIVER=sync to run jobs '
                . 'inline instead.'
            );
        }

        // Bound through make() rather than new so a driver's own constructor dependencies are
        // autowired. The database driver needs a Connection and the config, and hand-writing that
        // here would mean every new driver required a change in this file.
        $this->container->singleton(
            QueueDriverInterface::class,
            static fn(\Phue\Core\Container $container): QueueDriverInterface => $container->make($driverClass)
        );

        $this->container->singleton(PayloadCodec::class, static fn(): PayloadCodec => new PayloadCodec());

        $this->container->singleton(
            QueueManager::class,
            static function (\Phue\Core\Container $container): QueueManager {
                $driver = $container->get(QueueDriverInterface::class);

                return new QueueManager(
                    $driver,
                    $container->get(QueueConfig::class),
                    $container->get(PayloadCodec::class),
                    // Ends whatever a job left open before its outcome is recorded. See
                    // QueueManager::resetAfterExecution() for why that boundary is here and not in
                    // the worker.
                    $driver instanceof DatabaseQueueDriver && $container->has(Connection::class)
                        ? $container->get(Connection::class)
                        : null
                );
            }
        );
    }

    /**
     * The driver class the configuration asks for.
     *
     * An application may name a registered driver or hand over any class implementing the
     * interface, which is what makes the driver replaceable without editing this file.
     *
     * @param QueueConfig $config The validated settings.
     *
     * @return class-string<QueueDriverInterface>
     *
     * @throws \InvalidArgumentException When the configured driver cannot be used.
     */
    private function driverClassFor(QueueConfig $config): string
    {
        if (isset($config->extraDrivers[$config->driver])) {
            return $config->extraDrivers[$config->driver];
        }

        if (isset(self::DRIVERS[$config->driver])) {
            return self::DRIVERS[$config->driver];
        }

        if (class_exists($config->driver)) {
            if (!is_a($config->driver, QueueDriverInterface::class, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'queue.driver is "%s", which does not implement %s.',
                    $config->driver,
                    QueueDriverInterface::class
                ));
            }

            return $config->driver;
        }

        throw new \InvalidArgumentException(sprintf(
            'queue.driver is "%s", which is not a known driver (%s) and not a class. Register '
            . 'it under queue.drivers.%s to use your own.',
            $config->driver,
            implode(', ', array_keys(self::DRIVERS)),
            $config->driver
        ));
    }

    /**
     * Console commands for creating and inspecting the queue's tables.
     *
     * Registered through the module rather than discovered, because bin/phue builds these with a
     * bare `new` and no container — so a command must resolve its own connection and must not
     * assume anything the module bound.
     *
     * @param ConsoleApplication $console The console to register with.
     *
     * @return void
     */
    public function registerCommands(ConsoleApplication $console): void
    {
        $console->add(new QueueInstallCommand());
        $console->add(new QueueWorkCommand());
        $console->add(new QueueStatusCommand());
        $console->add(new QueueFailedCommand());
        $console->add(new QueuePruneCommand());
    }

    /**
     * Whether the configured driver needs a database connection.
     *
     * True only when the database driver is actually selected. The application reads this early
     * to decide whether to open a connection at all, and answering true for a project running
     * jobs inline would make every boot pay for a connection it will never use.
     *
     * @return bool
     */
    public function requiresDb(): bool
    {
        if (!$this->container->has(QueueConfig::class)) {
            return false;
        }

        $driver = $this->container->get(QueueConfig::class)->driver;

        if (is_a($driver, DatabaseQueueDriver::class, true)) {
            return true;
        }

        return isset(self::DRIVERS[$driver]) && self::DRIVERS[$driver] === DatabaseQueueDriver::class;
    }
}