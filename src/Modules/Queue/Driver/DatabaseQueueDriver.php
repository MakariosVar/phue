<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Driver;

use Phue\Core\Database\Connection;
use Phue\Core\Database\SqlIdentifier;
use Phue\Modules\Queue\Exception\SchemaException;
use Phue\Modules\Queue\ExecutionModel;
use Phue\Modules\Queue\JobEnvelope;
use Phue\Modules\Queue\JobStatus;
use Phue\Modules\Queue\QueueConfig;
use Phue\Modules\Queue\QueueDriverInterface;
use Phue\Modules\Queue\Schema\QueueSchema;

/**
 * Jobs in a database table, claimed with a single conditional update.
 *
 * No transaction is held across a claim, and that is the load-bearing decision rather than a
 * simplification. Holding one open while the job runs would be the textbook design and it has two
 * fatal flaws here: the edge database drivers have no transactions at all, so a queue built on
 * them would be unavailable on exactly the runtimes the framework is supposed to support; and a
 * long-running transaction holds locks and a connection for as long as the job takes, which
 * turns one slow job into a stalled queue.
 *
 * What replaces it is a compare-and-set. A claim is one UPDATE whose WHERE clause both selects the
 * next job and asserts that nothing changed underneath it, so the statement is atomic on its own
 * and no transaction is needed. Two workers running it at the same instant get different rows,
 * because the first one's UPDATE is what makes the second one's WHERE clause false.
 *
 * The same shape guards every finishing operation, and that is what stops a worker whose lease
 * expired mid-job from acknowledging a job another worker has since taken. It is the one
 * property this whole design is built around, so it is asserted in every statement below rather
 * than assumed.
 *
 * @package Phue\Modules\Queue\Driver
 */
final class DatabaseQueueDriver implements QueueDriverInterface
{
    /**
     * Claims examined before giving up on finding a free row.
     *
     * Without this, two workers racing for the oldest job would both attempt it, and the loser
     * would return null while jobs behind it sat untouched — turning a contended queue into an
     * empty one. Bounded so a queue where every job is already taken does not spin.
     */
    private const CLAIM_ATTEMPTS = 5;

    /**
     * How much longer a failure is kept than a completed job, as a multiple of the prune age.
     */
    private const FAILED_RETENTION_FACTOR = 10;

    /**
     * The shortest retention a failure ever gets, whatever the prune age says. A day.
     */
    private const MIN_FAILED_RETENTION_SECONDS = 86400;

    /** The tables this driver reads and writes, derived from the configured table name. */
    private QueueSchema $schema;

    /**
     * @param Connection   $connection Where the jobs are stored.
     * @param QueueConfig  $config     Validated settings, for the table name and the job
     *                                  namespace boundary.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly QueueConfig $config,
    ) {
        $this->schema = new QueueSchema($connection, $config->table);
    }

    /**
     * The name this driver is configured under.
     */
    public function name(): string
    {
        return 'database';
    }

    /**
     * Deferred: pushing a job stores it and returns, leaving it for a worker.
     */
    public function executionModel(): ExecutionModel
    {
        return ExecutionModel::Deferred;
    }

    /**
     * False. There is no portable blocking read across the drivers this framework supports, so the
     * worker polls. Polling is not free but it is honest, and a driver that could block would
     * answer true so the worker could stop doing it.
     */
    public function supportsBlocking(): bool
    {
        return false;
    }

    /**
     * True: available_at is a column, so a job can be stored with a future due time.
     */
    public function supportsDelayed(): bool
    {
        return true;
    }

    /**
     * Store a job and return its id.
     *
     * @param JobEnvelope $envelope The job, with no id and no reservation yet.
     *
     * @return string The inserted id.
     *
     * @throws SchemaException When the table is not installed.
     */
    public function push(JobEnvelope $envelope): string
    {
        $this->assertInstalled();

        $payload = $this->encodePayload($envelope->payload);

        $this->run(
            sprintf(
                'INSERT INTO %1$s (%2$s, %3$s, %4$s, %5$s, %6$s, %7$s, %8$s) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
                $this->q($this->schema->jobsTable()),
                $this->q('queue'),
                $this->q('job_class'),
                $this->q('payload'),
                $this->q('status'),
                $this->q('attempts'),
                $this->q('available_at'),
                $this->q('created_at')
            ),
            [
                $envelope->queue,
                $envelope->jobClass,
                $payload,
                JobStatus::Pending->value,
                $envelope->attempts,
                $envelope->availableAt,
                $envelope->createdAt ?? time(),
            ]
        );

        return (string) $this->connection->lastInsertId();
    }

    /**
     * Claim the oldest due job on a queue, atomically.
     *
     * @param string|null $queue        One queue, or null for any.
     * @param string      $workerId     Who is claiming; recorded as the owner.
     * @param int         $leaseSeconds How long the claim holds.
     * @param int|null    $now          Current time, injectable so tests need not sleep.
     *
     * @return JobEnvelope|null The claim, with attempts already counted, or null if nothing was
     *                          available.
     */
    public function reserve(?string $queue, string $workerId, int $leaseSeconds, ?int $now = null): ?JobEnvelope
    {
        $this->assertInstalled();

        $now ??= time();

        for ($attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++) {
            $claimed = $this->claimOne($queue, $workerId, $leaseSeconds, $now);

            if ($claimed !== null) {
                return $claimed;
            }
        }

        return null;
    }

    /**
     * One conditional update. Returns null when the row it picked was taken in between.
     *
     * The subquery selects the candidate and the outer WHERE re-asserts that it is still
     * claimable, which is what makes the pair atomic: between the two halves another worker may
     * have claimed it, and then the outer condition is false and this statement changes nothing.
     * Retrying is what turns that loss into the next job rather than into a false "queue empty".
     */
    private function claimOne(
        ?string $queue,
        string $workerId,
        int $leaseSeconds,
        int $now
    ): ?JobEnvelope {
        // Numbered placeholders, because this statement quotes six different identifiers and two
        // status values and a miscounted argument would produce SQL that is still valid and still
        // wrong. Positional %s in a statement this size fails silently the first time it happens.
        $table = $this->q($this->schema->jobsTable());
        $status = $this->q('status');
        $availableAt = $this->q('available_at');
        $queueClause = $queue === null ? '' : sprintf(' AND %s = ?', $this->q('queue'));

        $pending = JobStatus::Pending->value;
        $reserved = JobStatus::Reserved->value;

        // attempts goes up inside the claim, not in PHP afterwards. The counter has to move in
        // the same atomic step that hands the job out, or two workers can both decide this is the
        // first attempt of a job that has already failed twice — and nothing would ever reach its
        // attempt limit, so nothing would ever be dead-lettered.
        //
        // The claimable predicate is "pending, or reserved by somebody whose lease has run out".
        // The second half is the whole reason the lease exists: nobody observes a worker dying, so
        // an expired reservation is the only signal that a job is unattended. A claim that only
        // looked at pending rows would leave every job held by a killed worker stranded forever.
        //
        // The predicate appears twice on purpose — once to pick the row, once to re-assert it — and
        // both copies are the claimable test, so a row that changed state in between is not taken.
        $pending = JobStatus::Pending->value;
        $reserved = JobStatus::Reserved->value;
        $claimable = sprintf(
            '(%1$s = ? OR (%1$s = ? AND %2$s <= ?))',
            $status,
            $this->q('reserved_until')
        );

        $sql = sprintf(
            'UPDATE %1$s SET %2$s = ?, %3$s = ?, %4$s = ?, %5$s = ?, %9$s = %9$s + 1 '
            . 'WHERE %6$s = ('
            . 'SELECT %6$s FROM %1$s WHERE %7$s <= ?%8$s AND %10$s '
            . 'ORDER BY %7$s ASC, %6$s ASC LIMIT 1'
            . ') AND %10$s',
            $table,
            $status,
            $this->q('reserved_at'),
            $this->q('reserved_until'),
            $this->q('reserved_by'),
            $this->q('id'),
            $availableAt,
            $queueClause,
            $this->q('attempts'),
            $claimable
        );

        $affected = $this->run($sql, [
            $reserved,
            $now,
            $now + $leaseSeconds,
            $workerId,
            $now,
            ...($queue === null ? [] : [$queue]),
            $pending,
            $reserved,
            $now,
            $pending,
            $reserved,
            $now,
        ]);

        if ($affected < 1) {
            return null;
        }

        // An UPDATE does not return the row it touched on every driver, and lastInsertId() means
        // nothing here. The claim is identified instead by its ownership, which this statement
        // has just made unique: no other row in this table is reserved by this worker at this
        // instant with a later lease.
        $row = $this->findReservedBy($workerId, $now);

        return $row === null ? null : $this->toEnvelope($row);
    }

    /**
     * @return array<string, mixed>|null The job this worker is currently holding, if any.
     */
    private function findReservedBy(string $workerId, int $now): ?array
    {
        $rows = $this->select(
            sprintf(
                'SELECT * FROM %1$s WHERE %2$s = ? AND %3$s = ? AND %4$s > ? ORDER BY %5$s ASC LIMIT 1',
                $this->q($this->schema->jobsTable()),
                $this->q('reserved_by'),
                $this->q('status'),
                $this->q('reserved_until'),
                $this->q('id')
            ),
            [$workerId, JobStatus::Reserved->value, $now]
        );

        return $rows[0] ?? null;
    }

    /**
     * Mark a job done, if this worker still owns it.
     *
     * @param JobEnvelope $envelope The reserved job.
     * @param string      $workerId Who claims ownership.
     *
     * @return bool False when the job is gone or belongs to someone else.
     */
    public function complete(JobEnvelope $envelope, string $workerId): bool
    {
        $this->assertInstalled();

        $now = time();

        return $this->run(
            sprintf(
                'UPDATE %1$s SET %2$s = ?, %3$s = ?, %4$s = NULL, %5$s = NULL '
                . 'WHERE %6$s = ? AND %2$s = ? AND %4$s = ?',
                $this->q($this->schema->jobsTable()),
                $this->q('status'),
                $this->q('completed_at'),
                $this->q('reserved_by'),
                $this->q('reserved_until'),
                $this->q('id')
            ),
            [JobStatus::Completed->value, $now, $envelope->id, JobStatus::Reserved->value, $workerId]
        ) > 0;
    }

    /**
     * Put a reserved job back on the pending list, invisible until the delay is up.
     *
     * The condition is the same one as complete(): ownership has to still be ours, or a worker
     * that lost its lease would put back a job another worker is running.
     *
     * @param JobEnvelope $envelope    The reserved job.
     * @param string      $workerId    Who claims ownership.
     * @param int         $delaySeconds Wait before it can be claimed again.
     * @param int|null    $now         Current time, injectable so tests need not sleep.
     *
     * @return bool False when the job is gone or belongs to someone else.
     */
    public function release(JobEnvelope $envelope, string $workerId, int $delaySeconds, ?int $now = null): bool
    {
        $this->assertInstalled();

        $now ??= time();

        return $this->run(
            sprintf(
                'UPDATE %1$s SET %2$s = ?, %3$s = ?, %4$s = NULL, %5$s = NULL, %6$s = NULL '
                . 'WHERE %7$s = ? AND %2$s = ? AND %6$s = ?',
                $this->q($this->schema->jobsTable()),
                $this->q('status'),
                $this->q('available_at'),
                $this->q('reserved_at'),
                $this->q('reserved_until'),
                $this->q('reserved_by'),
                $this->q('id')
            ),
            [
                JobStatus::Pending->value,
                $now + max(0, $delaySeconds),
                $envelope->id,
                JobStatus::Reserved->value,
                $workerId,
            ]
        ) > 0;
    }

    /**
     * Move a reserved job to the dead-letter table and remove it from the live one.
     *
     * Written as an insert-then-delete rather than a flag on the row, so the live table — which
     * is read on every claim — never carries failures, and so the record that explains a failure
     * outlives the job it describes.
     *
     * The delete is conditional on ownership and happens second on purpose. If it were first, a
     * worker that had lost its lease would destroy a job another worker is running and leave
     * nothing to run it again with.
     *
     * @param JobEnvelope  $envelope The reserved job.
     * @param string       $workerId Who claims ownership.
     * @param \Throwable   $error    What went wrong.
     * @param bool         $willRetry Whether another attempt was expected. Recorded, because
     *                                "this failed and we stopped" and "this failed and we will
     *                                try again" are different incidents.
     *
     * @return bool False when the job is gone or belongs to someone else.
     */
    public function fail(JobEnvelope $envelope, string $workerId, \Throwable $error, bool $willRetry): bool
    {
        $this->assertInstalled();

        $this->run(
            sprintf(
                'INSERT INTO %1$s (%2$s, %3$s, %4$s, %5$s, %6$s, %7$s) VALUES (?, ?, ?, ?, ?, ?)',
                $this->q($this->schema->failedTable()),
                $this->q('queue'),
                $this->q('job_class'),
                $this->q('payload'),
                $this->q('attempts'),
                $this->q('exception'),
                $this->q('failed_at')
            ),
            [
                $envelope->queue,
                $envelope->jobClass,
                $this->encodePayload($envelope->payload),
                $envelope->attempts,
                $this->describe($error, $willRetry),
                time(),
            ]
        );

        return $this->run(
            sprintf(
                'DELETE FROM %1$s WHERE %2$s = ? AND %3$s = ? AND %4$s = ?',
                $this->q($this->schema->jobsTable()),
                $this->q('id'),
                $this->q('status'),
                $this->q('reserved_by')
            ),
            [$envelope->id, JobStatus::Reserved->value, $workerId]
        ) > 0;
    }

    /**
     * Put jobs whose lease ran out back on the pending list.
     *
     * In normal operation nothing is needed: a claim looks at pending jobs and ignores
     * reservations whose lease has expired, so an abandoned job is picked up by the next worker
     * with no help. This is for the case where that is not enough — a queue with no other worker
     * running, or an operator who wants the count to be zero rather than merely invisible.
     *
     * @param int      $graceSeconds Extra hold beyond the expiry, so a job that is merely slow
     *                               is not stolen from a worker still running it.
     * @param int|null $now          Current time, injectable so tests need not sleep.
     *
     * @return int How many jobs were returned to the queue.
     */
    public function reclaimExpiredLeases(int $graceSeconds = 0, ?int $now = null): int
    {
        $this->assertInstalled();

        $now ??= time();

        $table = $this->q($this->schema->jobsTable());
        $status = $this->q('status');
        $lease = $this->q('reserved_until');

        return $this->run(
            sprintf(
                'UPDATE %1$s SET %2$s = ?, %3$s = ?, %4$s = NULL, %5$s = NULL, %6$s = ? '
                . 'WHERE %2$s = ? AND %4$s IS NOT NULL AND %4$s <= ?',
                $table,
                $status,
                $this->q('reserved_at'),
                $lease,
                $this->q('reserved_by'),
                $this->q('available_at')
            ),
            [
                JobStatus::Pending->value,
                $now,
                $now,
                JobStatus::Reserved->value,
                $now - max(0, $graceSeconds),
            ]
        );
    }

    /**
     * Counts per status.
     *
     * @param string|null $queue One queue, or null for everything.
     *
     * @return array{pending: int, reserved: int, completed: int, failed: int, total: int}
     */
    public function stats(?string $queue = null): array
    {
        $this->assertInstalled();

        $counts = ['pending' => 0, 'reserved' => 0, 'completed' => 0];
        $jobs = $this->q($this->schema->jobsTable());

        foreach (JobStatus::cases() as $status) {
            $counts[$status->value] = $this->count(
                sprintf('SELECT COUNT(*) AS total FROM %s WHERE %s = ?%s', $jobs, $this->q('status'), $this->queueClause($queue)),
                $queue === null ? [$status->value] : [$status->value, $queue]
            );
        }

        $failed = $this->count(
            sprintf(
                'SELECT COUNT(*) AS total FROM %s%s',
                $this->q($this->schema->failedTable()),
                $this->whereClause($queue)
            ),
            $queue === null ? [] : [$queue]
        );

        return $counts + ['failed' => $failed, 'total' => array_sum($counts) + $failed];
    }

    /**
     * Forget completed jobs older than the given age, and the failures older than ten times that.
     *
     * Two ages rather than one because the two tables answer different questions. A completed job
     * is bookkeeping; a failed job is the only record of something that went wrong, so it is kept
     * long enough for somebody to read it and act on it.
     *
     * @param int $completedOlderThanSeconds Age at which a completed job may be discarded.
     *
     * @return int How many rows were removed from either table.
     */
    public function prune(int $completedOlderThanSeconds): int
    {
        $this->assertInstalled();

        $now = time();
        $cutoff = $now - max(0, $completedOlderThanSeconds);
        $jobs = $this->q($this->schema->jobsTable());
        $completed = JobStatus::Completed->value;

        $removed = $this->run(
            sprintf(
                'DELETE FROM %1$s WHERE %2$s = ? AND %3$s IS NOT NULL AND %3$s <= ?',
                $jobs,
                $this->q('status'),
                $this->q('completed_at')
            ),
            [$completed, $cutoff]
        );

        // A failure record is the only evidence that something went wrong, so it outlives the
        // completed jobs it sits beside by an order of magnitude. Pruning them on the same
        // schedule would mean a busy queue deletes its own error history weekly.
        // With a floor, so a short prune age cannot silently destroy the record of what went
        // wrong. An operator asking for the completed rows to go within a minute has not asked
        // for the last hour of failures to go with them.
        $failureCutoff = $now - max(
            $completedOlderThanSeconds * self::FAILED_RETENTION_FACTOR,
            self::MIN_FAILED_RETENTION_SECONDS
        );

        return $removed + $this->run(
            sprintf(
                'DELETE FROM %1$s WHERE %2$s <= ?',
                $this->q($this->schema->failedTable()),
                $this->q('failed_at')
            ),
            [$failureCutoff]
        );
    }

    /**
     * The schema this driver reads and writes.
     */
    public function schema(): QueueSchema
    {
        return $this->schema;
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * Refuse to work against a table that is not there, saying how to create it.
     *
     * Failing loudly rather than creating the table on the spot is deliberate. A queue that
     * silently issues DDL the first time a job is pushed would run a schema migration in
     * production, from inside a request, without anybody having chosen that.
     *
     * @throws SchemaException When the live table is missing or the version is behind.
     */
    private function assertInstalled(): void
    {
        $version = $this->schema->installedVersion();

        if ($version === null) {
            throw SchemaException::notInstalled($this->config, $this->schema->jobsTable());
        }

        if ($version < QueueSchema::VERSION) {
            throw SchemaException::outOfDate($version, QueueSchema::VERSION);
        }
    }

    /**
     * @param array<string, mixed> $row A row as the driver returned it.
     */
    private function toEnvelope(array $row): JobEnvelope
    {
        $payload = $row['payload'] ?? '{}';

        return new JobEnvelope(
            id: (string) ($row['id'] ?? ''),
            queue: (string) ($row['queue'] ?? $this->config->queue),
            jobClass: (string) ($row['job_class'] ?? ''),
            payload: is_string($payload) ? $this->decodePayload($payload) : (array) $payload,
            status: JobStatus::from((string) ($row['status'] ?? 'pending')),
            attempts: (int) ($row['attempts'] ?? 0),
            availableAt: (int) ($row['available_at'] ?? 0),
            reservedAt: isset($row['reserved_at']) ? (int) $row['reserved_at'] : null,
            reservedUntil: isset($row['reserved_until']) ? (int) $row['reserved_until'] : null,
            reservedBy: isset($row['reserved_by']) ? (string) $row['reserved_by'] : null,
            completedAt: isset($row['completed_at']) ? (int) $row['completed_at'] : null,
            createdAt: isset($row['created_at']) ? (int) $row['created_at'] : null,
        );
    }

    /**
     * @param array<string, mixed> $payload Values for the job constructor.
     */
    private function encodePayload(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $payload): array
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The dead-letter text: what failed, where, and whether it was expected to be retried.
     *
     * @param \Throwable $error    What went wrong.
     * @param bool       $willRetry Whether another attempt was expected.
     */
    private function describe(\Throwable $error, bool $willRetry): string
    {
        return sprintf(
            "%s: %s\nat %s:%d\n%s\n[%s]",
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
            $error->getTraceAsString(),
            $willRetry ? 'retry expected' : 'terminal'
        );
    }

    /**
     * A queue filter to append to a statement that already has a WHERE.
     */
    private function queueClause(?string $queue): string
    {
        return $queue === null ? '' : sprintf(' AND %s = ?', $this->q('queue'));
    }

    /**
     * A queue filter for a statement with no WHERE yet, so the AND has something to attach to.
     */
    private function whereClause(?string $queue): string
    {
        return $queue === null ? '' : sprintf(' WHERE %s = ?', $this->q('queue'));
    }

    /**
     * Run a statement and report how many rows it changed.
     *
     * The affected-row count is the whole basis of the ownership checks, so it is read from
     * execute() rather than inferred. Every driver reports it differently in the edges — some
     * count rows matched rather than rows changed — and the WHERE clauses here always change the
     * row, so the two agree on the only thing that matters.
     *
     * @param array<int|string, mixed> $params Bindings for the statement.
     */
    private function run(string $sql, array $params = []): int
    {
        return (int) $this->connection->execute($sql, $params);
    }

    /**
     * @param array<int|string, mixed> $params Bindings for the statement.
     *
     * @return list<array<string, mixed>>
     */
    private function select(string $sql, array $params = []): array
    {
        $statement = $this->connection->query($sql, $params);
        $rows = is_object($statement) && method_exists($statement, 'fetchAll')
            ? $statement->fetchAll()
            : (array) $statement;

        return array_map(
            static fn ($row): array => (array) $row,
            array_values(array_filter(is_array($rows) ? $rows : [$rows], static fn ($r): bool => $r !== null))
        );
    }

    /**
     * @param array<int|string, mixed> $params Bindings for the statement.
     */
    private function count(string $sql, array $params = []): int
    {
        $rows = $this->select($sql, $params);

        return (int) ($rows[0]['total'] ?? $rows[0]['COUNT(*)'] ?? 0);
    }

    /**
     * Quote an identifier for the driver in use.
     */
    private function q(string $identifier): string
    {
        return SqlIdentifier::quote($identifier, $this->connection->getDriver()->getName());
    }
}