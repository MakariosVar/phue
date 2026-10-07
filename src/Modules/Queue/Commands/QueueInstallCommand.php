<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Commands;

use Phue\Modules\Queue\Exception\SchemaException;
use Phue\Modules\Queue\QueueConfig;
use Phue\Modules\Queue\Schema\QueueSchema;

/**
 * Create or upgrade the queue's tables.
 *
 * Separate from the application migrations on purpose. Application migrations roll back in
 * batches, and a queue table that rolls back while a worker is holding a job loses the row that
 * job is stored in — not "the queue is empty", but work discarded mid-deploy by the one component
 * nobody is watching at that moment.
 *
 * Runs with no arguments and does the right thing in every state, because it is the command
 * somebody reaches for when something is already wrong.
 *
 * @package Phue\Modules\Queue\Commands
 */
final class QueueInstallCommand extends AbstractQueueCommand
{
    /**
     * The console name this command is invoked by.
     */
    public function getName(): string
    {
        return 'queue:install';
    }

    /**
     * One line for the console's help output.
     */
    public function getDescription(): string
    {
        return 'Create or upgrade the queue tables. Safe to run repeatedly; existing jobs are kept.';
    }

    /**
     * The group this command is listed under.
     */
    public function getGroup(): string
    {
        return 'Queue';
    }

    /**
     * Create anything missing.
     *
     * @param array<int, string> $args Positional arguments, of which there are none.
     * @param resource          $outputStream Where normal output goes.
     * @param resource          $errorStream  Where failures go.
     *
     * @return int 0 when the schema is current, 1 when it could not be brought up to date.
     */
    public function execute(
        array $args = [],
        $outputStream = \STDOUT,
        $errorStream = \STDERR
    ): int {
        try {
            $config = QueueConfig::fromContainer($this->config());
        } catch (\InvalidArgumentException $e) {
            // A malformed queue config is reported rather than thrown: the console has no
            // exception handler of its own, and a stack trace here hides the one line that says
            // which key is wrong.
            fwrite($errorStream, 'queue: ' . $e->getMessage() . \PHP_EOL);

            return self::FAILURE;
        }

        try {
            $schema = new QueueSchema($this->connection(), $config->table);
            $installed = $schema->installedVersion();
            $applied = $schema->install();
        } catch (SchemaException $e) {
            fwrite($errorStream, 'queue:install: ' . $e->getMessage() . \PHP_EOL);

            return self::FAILURE;
        } catch (\Throwable $e) {
            fwrite($errorStream, sprintf(
                "queue:install: could not prepare the connection: %s\n"
                . 'Check database.default and its connection settings in config/database.php.' . \PHP_EOL,
                $e->getMessage()
            ));

            return self::FAILURE;
        }

        if ($applied === []) {
            fwrite($outputStream, sprintf(
                "Queue schema is already at version %d (table %s).\n",
                (int) $installed,
                $config->table
            ));

            return self::SUCCESS;
        }

        fwrite($outputStream, sprintf(
            "Queue schema %s. Version %d, table %s.\n",
            implode(', ', $applied),
            QueueSchema::VERSION,
            $config->table
        ));
        fwrite($outputStream, sprintf("Also created: %s, %s\n", $schema->failedTable(), $schema->metaTable()));

        return self::SUCCESS;
    }

    /**
     * A connection built from the project's own database settings.
     *
     * Assembled here rather than taken from the container because bin/phue builds commands with a
     * bare `new`, before any module is mounted. Reaching for the container would work on the
     * second call and fail on the first, which is the worst way for a command to misbehave.
     *
     * The connection's own array is passed through untouched, so host, port, credentials and
     * anything else the project has configured are honoured without this command having to know
     * the shape of a connection.
     *
     * @throws SchemaException When the project has no usable connection configured.
     */
}