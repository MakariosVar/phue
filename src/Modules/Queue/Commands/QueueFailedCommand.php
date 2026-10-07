<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Commands;

use Phue\Core\Database\Connection;

/**
 * List the jobs that died, and why.
 *
 * A queue that reports "3 failed" is not yet information. What an operator needs is which jobs,
 * what they were carrying and what the error was — which is exactly what the dead-letter store
 * keeps, and the reason it keeps the payload as well as the message.
 *
 * @package Phue\Modules\Queue\Commands
 */
final class QueueFailedCommand extends AbstractQueueCommand
{
    /**
     * How many dead letters to show when nothing else says.
     */
    public const DEFAULT_LIMIT = 20;

    /**
     * The console name this command is invoked by.
     */
    public function getName(): string
    {
        return 'queue:failed';
    }

    /**
     * One line for the console's help output.
     */
    public function getDescription(): string
    {
        return 'List dead-lettered jobs with the error that stopped them.';
    }

    /**
     * The group this command is listed under.
     */
    public function getGroup(): string
    {
        return 'Queue';
    }

    /**
     * Print the dead letters, newest first.
     *
     * @param array<int, string> $args         Everything after the command name.
     * @param resource           $outputStream Where the list goes.
     * @param resource           $errorStream  Where failures go.
     *
     * @return int 0 on success, 1 on a configuration or connection failure.
     */
    public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        try {
            $limit = $this->intOption($args, 'limit') ?? self::DEFAULT_LIMIT;
            $queue = $this->option($args, 'queue');
            $this->manager();
        } catch (\Throwable $e) {
            return $this->fail($errorStream, 'queue:failed', $e);
        }

        try {
            $rows = $this->deadLetters($limit, $queue);
        } catch (\Throwable $e) {
            return $this->fail($errorStream, 'queue:failed', $e);
        }

        if ($rows === []) {
            fwrite($outputStream, "No failed jobs.\n");

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $row = (array) $row;

            fwrite($outputStream, sprintf(
                "\n#%s  %s  %s  attempt %s  %s\n",
                (string) ($row['id'] ?? '?'),
                (string) ($row['queue'] ?? '?'),
                $this->when((int) ($row['failed_at'] ?? 0)),
                (string) ($row['attempts'] ?? '?'),
                (string) ($row['job_class'] ?? '?')
            ));

            $exception = (string) ($row['exception'] ?? '');
            $lines = explode("\n", $exception);

            // The recorded exception starts with the class and message; the trace after it is
            // noise unless somebody is actually debugging, so it is folded into one summary line
            // and the full text is not printed at all.
            fwrite($outputStream, '  ' . ($lines[0] ?? '') . "\n");

            if (isset($lines[1])) {
                fwrite($outputStream, '  ' . $lines[1] . "\n");
            }

            fwrite($outputStream, '  payload: ' . $this->truncate((string) ($row['payload'] ?? '')) . "\n");
        }

        fwrite($outputStream, sprintf("\n%d shown, newest first.\n", count($rows)));

        return self::SUCCESS;
    }

    /**
     * The dead letters, newest first.
     *
     * @param int         $limit How many to read.
     * @param string|null $queue One queue, or null for all of them.
     *
     * @return list<array<string, mixed>>
     */
    private function deadLetters(int $limit, ?string $queue): array
    {
        $config = $this->queueConfig();

        // Checked first, so an uncreated table produces the sentence telling the operator to create
        // it rather than whatever the database driver said about it.
        $table = $this->assertSchemaReady($config)->failedTable();

        $connection = $this->connection();
        $identifier = \Phue\Core\Database\SqlIdentifier::quote($table, $connection->getDriver()->getName());

        if ($queue !== null) {
            $queueColumn = \Phue\Core\Database\SqlIdentifier::quote('queue', $connection->getDriver()->getName());
            $sql = sprintf('SELECT * FROM %s WHERE %s = ? ORDER BY id DESC LIMIT %d', $identifier, $queueColumn, max(1, $limit));
            $params = [$queue];
        } else {
            $sql = sprintf('SELECT * FROM %s ORDER BY id DESC LIMIT %d', $identifier, max(1, $limit));
            $params = [];
        }

        $statement = $connection->query($sql, $params);
        $rows = is_object($statement) && method_exists($statement, 'fetchAll')
            ? $statement->fetchAll()
            : (array) $statement;

        return array_map(
            static fn ($row): array => (array) $row,
            array_values(array_filter(is_array($rows) ? $rows : [$rows], static fn ($r): bool => $r !== null))
        );
    }

    /**
     * A unix timestamp as something a person can read, or an empty string.
     */
    private function when(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '-';
    }

    /**
     * Shorten a payload for one line of console output.
     */
    private function truncate(string $payload, int $limit = 120): string
    {
        return strlen($payload) <= $limit ? $payload : substr($payload, 0, $limit) . '…';
    }
}
