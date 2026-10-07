<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Commands;

/**
 * Forget completed jobs, and eventually the dead letters.
 *
 * Exists because nobody remembers to run a cleanup, and a queue table that only ever grows makes
 * every claim slower for reasons that have nothing to do with the work in it. The default is a
 * week, which is longer than anybody needs for bookkeeping and short enough that the table does
 * not grow without bound.
 *
 * @package Phue\Modules\Queue\Commands
 */
final class QueuePruneCommand extends AbstractQueueCommand
{
    /**
     * Age at which completed jobs are forgotten, when nothing else says.
     */
    public const DEFAULT_DAYS = 7;

    /**
     * The console name this command is invoked by.
     */
    public function getName(): string
    {
        return 'queue:prune';
    }

    /**
     * One line for the console's help output.
     */
    public function getDescription(): string
    {
        return 'Remove completed jobs older than the given age. Dead letters are kept far longer.';
    }

    /**
     * The group this command is listed under.
     */
    public function getGroup(): string
    {
        return 'Queue';
    }

    /**
     * Prune.
     *
     * @param array<int, string> $args         Everything after the command name.
     * @param resource           $outputStream Where the result goes.
     * @param resource           $errorStream  Where failures go.
     *
     * @return int 0 on success, 1 on a configuration or connection failure.
     */
    public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        try {
            $days = $this->intOption($args, 'days') ?? self::DEFAULT_DAYS;
        } catch (\InvalidArgumentException $e) {
            return $this->fail($errorStream, 'queue:prune', $e);
        }

        try {
            $removed = $this->manager()->prune($days * 86400);
        } catch (\Throwable $e) {
            return $this->fail($errorStream, 'queue:prune', $e);
        }

        fwrite($outputStream, sprintf(
            "Removed %d job(s) completed more than %d day(s) ago.\n",
            $removed,
            $days
        ));

        return self::SUCCESS;
    }
}
