<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use Phue\Modules\Queue\ExecutionModel;
use Phue\Modules\Queue\JobEnvelope;
use Phue\Modules\Queue\JobStatus;
use Phue\Modules\Queue\QueueDriverInterface;

/**
 * A second, independent implementation of the driver contract.
 *
 * Written from the interface rather than derived from the shipped driver, because SyncQueueDriver
 * is final on purpose — the framework's own store is not meant to be extended by an application,
 * and weakening that to make a test easier would be the tail wagging the dog.
 *
 * It exists for two reasons. The first is that retry behaviour can only be tested on a driver that
 * defers, and doing that with the production driver would need a database. The second is that it
 * proves the interface is implementable by someone other than its author, which is the only real
 * test of whether a contract is a contract or a description.
 *
 * @package Phue\Tests\Queue
 */
final class DeferredSyncQueueDriver implements QueueDriverInterface
{
    /** @var array<string, JobEnvelope> */
    private array $entries = [];

    /** @var list<array{at: int, error: string, jobClass: string, queue: string}> */
    private array $deadLetters = [];

    private int $nextId = 1;

    public function name(): string
    {
        return 'deferred-sync';
    }

    public function executionModel(): ExecutionModel
    {
        return ExecutionModel::Deferred;
    }

    public function supportsBlocking(): bool
    {
        return false;
    }

    public function supportsDelayed(): bool
    {
        return true;
    }

    public function push(JobEnvelope $envelope): string
    {
        $id = (string) $this->nextId++;

        $this->entries[$id] = new JobEnvelope(
            id: $id,
            queue: $envelope->queue,
            jobClass: $envelope->jobClass,
            payload: $envelope->payload,
            status: $envelope->status,
            attempts: $envelope->attempts,
            availableAt: $envelope->availableAt,
            createdAt: $envelope->createdAt,
        );

        return $id;
    }

    public function reserve(?string $queue, string $workerId, int $leaseSeconds, ?int $now = null): ?JobEnvelope
    {
        $now ??= time();

        foreach ($this->claimable($queue, $now) as $id) {
            $claimed = $this->entries[$id]->reserved($workerId, $now, $now + $leaseSeconds);
            $this->entries[$id] = $claimed;

            return $claimed;
        }

        return null;
    }

    public function complete(JobEnvelope $envelope, string $workerId): bool
    {
        if (!$this->isOwnedBy($envelope, $workerId)) {
            return false;
        }

        $this->entries[$envelope->id] = $envelope->completedAt(time());

        return true;
    }

    public function release(JobEnvelope $envelope, string $workerId, int $delaySeconds, ?int $now = null): bool
    {
        if (!$this->isOwnedBy($envelope, $workerId)) {
            return false;
        }

        $now ??= time();
        $this->entries[$envelope->id] = $envelope->pendingFrom($now, $now + max(0, $delaySeconds));

        return true;
    }

    public function fail(JobEnvelope $envelope, string $workerId, \Throwable $error, bool $willRetry): bool
    {
        if (!$this->isOwnedBy($envelope, $workerId)) {
            return false;
        }

        unset($this->entries[$envelope->id]);
        $this->deadLetters[] = [
            'at' => time(),
            'error' => $error->getMessage(),
            'jobClass' => $envelope->jobClass,
            'queue' => $envelope->queue,
        ];

        return true;
    }

    public function stats(?string $queue = null): array
    {
        $counts = ['pending' => 0, 'reserved' => 0, 'completed' => 0];

        foreach ($this->entries as $entry) {
            if ($queue !== null && $entry->queue !== $queue) {
                continue;
            }

            $counts[$entry->status->value]++;
        }

        $failed = 0;

        foreach ($this->deadLetters as $dead) {
            if ($queue === null || $dead['queue'] === $queue) {
                $failed++;
            }
        }

        return $counts + ['failed' => $failed, 'total' => array_sum($counts) + $failed];
    }

    public function prune(int $completedOlderThanSeconds): int
    {
        $cutoff = time() - max(0, $completedOlderThanSeconds);
        $removed = 0;

        foreach ($this->entries as $id => $entry) {
            if ($entry->status === JobStatus::Completed && ($entry->completedAt ?? 0) <= $cutoff) {
                unset($this->entries[$id]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * What the manager sent to fail(), for assertions about the retry decision.
     *
     * @return list<array{at: int, error: string, jobClass: string, queue: string}>
     */
    public function deadLetters(): array
    {
        return $this->deadLetters;
    }

    /**
     * The oldest pending job, due or not, claimed for a worker.
     *
     * Deliberately not reserve(): a backoff measured in seconds would otherwise make the retry
     * assertions about wall clock instead of about attempt counts.
     */
    public function nextPending(): ?JobEnvelope
    {
        foreach ($this->entries as $id => $entry) {
            if ($entry->status !== JobStatus::Pending) {
                continue;
            }

            $claimed = $entry->reserved('worker-1', $entry->availableAt, $entry->availableAt + 3600);
            $this->entries[$id] = $claimed;

            return $claimed;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function claimable(?string $queue, int $now): array
    {
        $candidates = [];

        foreach ($this->entries as $id => $entry) {
            if ($queue !== null && $entry->queue !== $queue) {
                continue;
            }

            if ($entry->status === JobStatus::Pending && $entry->availableAt <= $now) {
                $candidates[$id] = [$entry->availableAt, (int) $id];

                continue;
            }

            if ($entry->status === JobStatus::Reserved && $entry->leaseExpired($now)) {
                $candidates[$id] = [$entry->availableAt, (int) $id];
            }
        }

        asort($candidates);

        return array_map(strval(...), array_keys($candidates));
    }

    private function isOwnedBy(JobEnvelope $envelope, string $workerId): bool
    {
        $stored = $this->entries[$envelope->id] ?? null;

        return $stored !== null
            && $stored->status === JobStatus::Reserved
            && $stored->reservedBy === $workerId;
    }
}