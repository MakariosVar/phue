<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Exception;

use Phue\Modules\Queue\QueueConfig;

/**
 * The queue's tables are missing, or cannot be created.
 *
 * Its own type rather than a generic RuntimeException so a caller can tell "the queue is not
 * usable right now" from "a job was rejected", and so nothing catches it by accident on the way
 * to somewhere else.
 *
 * @package Phue\Modules\Queue\Exception
 */
final class SchemaException extends \RuntimeException
{
    /**
     * The live table is not there.
     *
     * @param QueueConfig $config Validated settings, for the configured name.
     * @param string      $table  The table that was looked for.
     */
    public static function notInstalled(QueueConfig $config, string $table): self
    {
        return new self(sprintf(
            'The queue table "%s" does not exist. Run "php bin/phue queue:install" to create it, '
            . 'or point queue.driver at a driver that needs no table. The table name comes from '
            . 'queue.table in config/queue.php and is currently "%s".',
            $table,
            $config->table
        ));
    }

    /**
     * The schema is behind the release, so its statements cannot be trusted to match.
     *
     * @param int $installed The version found in the database.
     * @param int $expected  The version this release speaks.
     */
    public static function outOfDate(int $installed, int $expected): self
    {
        return new self(sprintf(
            'The queue schema is at version %d but this release expects %d. Run '
            . '"php bin/phue queue:install" to upgrade it. Existing jobs are left alone.',
            $installed,
            $expected
        ));
    }

    /**
     * The project has no usable database connection configured.
     */
    public static function noConnection(): self
    {
        return new self(
            'The database queue driver needs a connection, and none is configured. Set '
            . 'database.default in config/database.php, or set QUEUE_DRIVER=sync to run jobs '
            . 'inline instead.'
        );
    }
}