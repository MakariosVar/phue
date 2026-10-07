<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Commands;

use Phue\Core\Config;
use Phue\Core\Console\BaseCommandInterface;
use Phue\Core\Console\PathResolver;
use Phue\Core\Container;
use Phue\Core\Database\Connection;
use Phue\Modules\Queue\Exception\SchemaException;
use Phue\Modules\Queue\Queue;
use Phue\Modules\Queue\QueueConfig;
use Phue\Modules\Queue\QueueManager;
use Phue\Modules\Queue\Schema\QueueSchema;

/**
 * Shared setup for the queue's console commands.
 *
 * bin/phue builds every command with a bare `new`, before the application is mounted, so a command
 * cannot be handed the container or the manager. It has to assemble what it needs — and the three
 * commands that need it should assemble it the same way, or one of them will be subtly different
 * from the other two in a way nobody notices until it matters.
 *
 * What it builds is also published as the container's instance, because a job running inside this
 * process will reach for `Container::getInstance()` and for `Queue::push()`. Without this, a job
 * that enqueues another job fails in a worker and works everywhere else.
 *
 * @package Phue\Modules\Queue\Commands
 */
abstract class AbstractQueueCommand implements BaseCommandInterface
{
    /** Built once, so a command that asks twice reuses the same connection. */
    private ?Connection $connection = null;

    /** Built once, so the driver and its in-memory store survive for the whole command. */
    private ?QueueManager $manager = null;

    /** Published for the jobs this command runs, so they resolve the same queue it does. */
    private ?Container $container = null;

    /**
     * @param string|null $basePath Project root to read config from. Defaults to the running
     *                              application; a constructor argument rather than a static call so
     *                              a test can point the command at a temporary project instead of
     *                              the real one.
     */
    public function __construct(
        private readonly ?string $basePath = null
    ) {
    }

    /**
     * The project root this command reads from.
     */
    protected function root(): string
    {
        return $this->basePath ?? PathResolver::resolveBasePath();
    }

    /**
     * The project's configuration, read fresh so a command sees what is on disk now rather than
     * what was loaded when the process started.
     */
    protected function config(): Config
    {
        return new Config($this->root() . '/config');
    }

    /**
     * The validated queue settings.
     *
     * @throws \InvalidArgumentException When the queue config cannot mean anything.
     */
    protected function queueConfig(): QueueConfig
    {
        return QueueConfig::fromContainer($this->config());
    }

    /**
     * A connection built from the project's own database settings.
     *
     * The connection's array is passed through untouched, so host, port, credentials and anything
     * else the project has configured are honoured without this knowing the shape of a connection.
     *
     * @throws SchemaException When the project has no usable connection configured.
     */
    protected function connection(): Connection
    {
        if ($this->connection instanceof Connection) {
            return $this->connection;
        }

        $name = $this->config()->get('database.default');
        $name = is_string($name) && $name !== '' ? $name : 'sqlite';

        $settings = $this->config()->get('database.connections.' . $name);
        $settings = is_array($settings) ? $settings : [];

        if ($settings === [] || !isset($settings['driver']) || !is_string($settings['driver'])) {
            throw SchemaException::noConnection();
        }

        return $this->connection = new Connection($settings);
    }

    /**
     * The queue manager, built the same way for every command.
     *
     * @param Connection|null $connection Injected by tests; otherwise taken from config.
     *
     * @throws SchemaException When a connection is needed and there is not one.
     */
    protected function manager(?Connection $connection = null): QueueManager
    {
        if ($this->manager instanceof QueueManager) {
            return $this->manager;
        }

        $config = $this->queueConfig();
        $connection ??= $config->driver === 'sync' ? null : $this->connection();

        $driverClass = $this->driverClass($config);

        $this->container = new Container();
        $this->container->singleton(Config::class, fn(): Config => $this->config());
        $this->container->singleton(QueueConfig::class, static fn(): QueueConfig => $config);

        if ($connection instanceof Connection) {
            $this->container->singleton(Connection::class, static fn(): Connection => $connection);
        }

        $driver = $this->container->make($driverClass);

        $this->container->singleton(\Phue\Modules\Queue\QueueDriverInterface::class, static fn(): \Phue\Modules\Queue\QueueDriverInterface => $driver);
        $this->manager = new QueueManager(
            $driver,
            $config,
            null,
            $connection
        );

        // Published so that code running inside this process — a job, and anything it touches —
        // resolves the same queue this command is using rather than failing to find one.
        Container::setInstance($this->container);
        Queue::swap($this->manager);

        return $this->manager;
    }

    /**
     * The container the command assembled, for jobs and services to share.
     *
     * @throws \RuntimeException When manager() has not been called.
     */
    protected function container(): Container
    {
        if (!$this->container instanceof Container) {
            throw new \RuntimeException(
                'The queue command has not been set up. Call manager() before container().'
            );
        }

        return $this->container;
    }

    /**
     * The schema the configured driver reads and writes.
     */
    protected function schema(QueueConfig $config): QueueSchema
    {
        return new QueueSchema($this->connection(), $config->table);
    }

    /**
     * Refuse to read a table that has not been created.
     *
     * For the commands that query storage directly rather than going through the driver. Without
     * this they report whatever the database driver happened to say — "no such table" — which tells
     * an operator nothing about the command they just ran.
     *
     * @throws SchemaException When the schema has never been installed.
     */
    protected function assertSchemaReady(QueueConfig $config): QueueSchema
    {
        $schema = $this->schema($config);

        if ($schema->installedVersion() === null) {
            throw SchemaException::notInstalled($config, $schema->jobsTable());
        }

        return $schema;
    }

    /**
     * The driver class the configuration asks for, by name or by class.
     *
     * @throws \InvalidArgumentException When the configured driver cannot be used.
     */
    protected function driverClass(QueueConfig $config): string
    {
        $driver = $config->driver;

        if (isset($config->extraDrivers[$driver])) {
            return $config->extraDrivers[$driver];
        }

        if ($driver === 'database') {
            return \Phue\Modules\Queue\Driver\DatabaseQueueDriver::class;
        }

        if ($driver === 'sync') {
            return \Phue\Modules\Queue\Driver\SyncQueueDriver::class;
        }

        if (class_exists($driver) && is_a($driver, \Phue\Modules\Queue\QueueDriverInterface::class, true)) {
            return $driver;
        }

        throw new \InvalidArgumentException(sprintf(
            'queue.driver is "%s", which is not a known driver (sync, database) and not a class. '
            . 'Register it under queue.drivers.%s to use your own.',
            $driver,
            $driver
        ));
    }

    /**
     * Report a failure as one sentence on stderr and return the failure code.
     *
     * @param resource $errorStream Where the message goes.
     * @param string   $context    What was being attempted.
     */
    protected function fail($errorStream, string $context, \Throwable $e): int
    {
        fwrite($errorStream, sprintf("%s: %s\n", $context, $e->getMessage()));

        return self::FAILURE;
    }

    /**
     * Read a --key=value argument, as the rest of the console spells them.
     *
     * @param array<int, string> $args  Everything the command was given.
     * @param string             $key   Option name without the leading dashes.
     * @param string|null        $value Default when the option is absent.
     */
    protected function option(array $args, string $key, ?string $value = null): ?string
    {
        $prefix = '--' . $key . '=';

        foreach ($args as $arg) {
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return $value;
    }

    /**
     * Whether a bare flag was given, with or without an equals sign.
     *
     * @param array<int, string> $args Everything the command was given.
     * @param string             $flag Flag name without the leading dashes.
     */
    protected function flag(array $args, string $flag): bool
    {
        foreach ($args as $arg) {
            if ($arg === '--' . $flag || str_starts_with($arg, '--' . $flag . '=')) {
                return true;
            }
        }

        return false;
    }

    /**
     * An integer option, or null when it is absent.
     *
     * @param array<int, string> $args Everything the command was given.
     * @param string             $key  Option name without the leading dashes.
     *
     * @throws \InvalidArgumentException When the value is present but not a whole number.
     */
    protected function intOption(array $args, string $key): ?int
    {
        $value = $this->option($args, $key);

        if ($value === null || $value === '') {
            return null;
        }

        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException(sprintf('--%s must be a whole number, got "%s".', $key, $value));
        }

        return (int) $value;
    }
}