<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Commands;

use Phue\Modules\Queue\QueueConfig;

/**
 * Show what is waiting, what is running and what has died.
 *
 * The command an operator reaches for when the queue is not draining, so it prints the numbers
 * that explain why rather than just a count. A queue with 4,000 pending jobs is a different
 * problem from a queue with 4,000 dead-lettered ones, and both look like "the queue is stuck".
 *
 * @package Phue\Modules\Queue\Commands
 */
final class QueueStatusCommand extends AbstractQueueCommand
{
    /**
     * The console name this command is invoked by.
     */
    public function getName(): string
    {
        return 'queue:status';
    }

    /**
     * One line for the console's help output.
     */
    public function getDescription(): string
    {
        return 'Show pending, reserved and failed job counts, per queue or in total.';
    }

    /**
     * The group this command is listed under.
     */
    public function getGroup(): string
    {
        return 'Queue';
    }

    /**
     * Print the counts.
     *
     * @param array<int, string> $args         Everything after the command name.
     * @param resource           $outputStream Where the table goes.
     * @param resource           $errorStream  Where failures go.
     *
     * @return int 0 on success, 1 on a configuration or connection failure.
     */
    public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        try {
            $manager = $this->manager();
            $queue = $this->option($args, 'queue');
        } catch (\Throwable $e) {
            return $this->fail($errorStream, 'queue:status', $e);
        }

        try {
            $stats = $manager->stats($queue);
        } catch (\Throwable $e) {
            return $this->fail($errorStream, 'queue:status', $e);
        }

        fwrite($outputStream, sprintf(
            "Driver: %s%s\n\n",
            $manager->driver()->name(),
            $queue === null ? '' : sprintf('  (queue: %s)', $queue)
        ));

        $rows = [
            ['pending', $stats['pending'], 'waiting to be claimed'],
            ['reserved', $stats['reserved'], 'claimed by a worker'],
            ['failed', $stats['failed'], 'dead-lettered, needs a look'],
            ['completed', $stats['completed'], 'done, awaiting prune'],
        ];

        fwrite($outputStream, sprintf("%-11s %9s  %s\n", 'STATUS', 'COUNT', ''));
        fwrite($outputStream, str_repeat('-', 46) . "\n");

        foreach ($rows as [$label, $count, $note]) {
            fwrite($outputStream, sprintf("%-11s %9d  %s\n", $label, $count, $note));
        }

        if ($stats['failed'] > 0) {
            fwrite($outputStream, sprintf(
                "\n%d failed job(s). Run \"php bin/phue queue:failed\" to see why.\n",
                $stats['failed']
            ));
        }

        if ($stats['pending'] === 0 && $stats['reserved'] === 0) {
            fwrite($outputStream, "\nNothing is waiting. A queue that is not draining needs a "
                . "worker: \"php bin/phue queue:work\".\n");
        }

        return self::SUCCESS;
    }
}
