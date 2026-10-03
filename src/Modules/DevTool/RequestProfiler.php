<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

/**
 * Class RequestProfiler
 *
 * Tracks request lifecycle memory usage, peak memory, execution duration, named spans,
 * and free-form request metadata for the DevTool debug bar.
 *
 * Lifetime contract: this object is effectively an application-lifetime singleton under
 * a memory-resident server, because Container::get() memoizes autowired instances into
 * the container. start() therefore resets both the memory baseline and the collected
 * spans, which is what keeps request N+1 from reporting request N's work.
 *
 * @package Phue\Modules\DevTool
 */
class RequestProfiler
{
    /**
     * @var int Initial memory usage in bytes.
     */
    private int $startMemory = 0;

    /**
     * Free-form request metadata rendered by the toolbar (method, uri, status, ...).
     *
     * @var array<string, mixed>
     */
    private array $context = [];

    /**
     * Module-level instrumentation notes, deliberately not cleared per request.
     *
     * An instrument that could not be installed is a fact about the process, not about a
     * request. Reporting it in the request context would erase it on the very next
     * request, which is exactly when a developer is still trying to understand why a
     * measurement is missing.
     *
     * @var list<array{message: string, context: array<string, mixed>}>
     */
    private array $diagnostics = [];

    /**
     * How many requests this profiler instance has served.
     *
     * > 1 means a reused instance under a memory-resident server, so the memos the
     * framework keeps are warm. The first request pays to populate them and is routinely
     * several times slower for the same page — a fact a report that does not state it is
     * actively misleading, because the numbers look like a regression that then vanishes.
     *
     * @var int
     */
    private int $served = 0;

    /**
     * @param Stopwatch $stopwatch Timing backend and span collector.
     */
    public function __construct(
        private Stopwatch $stopwatch
    ) {}

    /**
     * Start profiling the request.
     *
     * Resets previously collected spans and the memory baseline so a reused instance
     * (memory-resident workers) never mixes two requests into one report.
     *
     * @return void
     */
    public function start(): void
    {
        $this->context = [];
        $this->stopwatch->resetSpans();
        $this->startMemory = memory_get_usage();
        $this->stopwatch->start();
        $this->served++;
    }

    /**
     * Whether this is the first request served by this profiler instance.
     *
     * @return bool
     */
    public function isColdRequest(): bool
    {
        return $this->served <= 1;
    }

    /**
     * How many requests this profiler instance has served, including the current one.
     *
     * @return int
     */
    public function getServedRequests(): int
    {
        return $this->served;
    }

    /**
     * Stop profiling and return execution duration in ms.
     *
     * @return float
     */
    public function stop(): float
    {
        return $this->stopwatch->stop();
    }

    /**
     * Record a module-level instrumentation note.
     *
     * @param string $message Human readable reason.
     * @param array<string, mixed> $context Supporting detail.
     * @return void
     */
    public function addDiagnostic(string $message, array $context = []): void
    {
        $this->diagnostics[] = ['message' => $message, 'context' => $context];
    }

    /**
     * All module-level instrumentation notes recorded so far.
     *
     * @return list<array{message: string, context: array<string, mixed>}>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Discard all collected data and unwind open spans.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->context = [];
        $this->stopwatch->resetSpans();
        $this->startMemory = memory_get_usage();
    }

    /**
     * Get memory consumed during the request in bytes.
     *
     * @return int
     */
    public function getMemoryUsage(): int
    {
        return max(0, memory_get_usage() - $this->startMemory);
    }

    /**
     * Get peak memory usage in bytes.
     *
     * @return int
     */
    public function getPeakMemoryUsage(): int
    {
        return memory_get_peak_usage(true);
    }

    /**
     * Get duration in milliseconds.
     *
     * @return float
     */
    public function getDurationMs(): float
    {
        return $this->stopwatch->getDurationMs();
    }

    /**
     * Open a named span. Must be paired with endSpan().
     *
     * @param string $label Operation name.
     * @param array<string, mixed> $meta Call-specific metadata.
     * @return void
     */
    public function beginSpan(string $label, array $meta = []): void
    {
        $this->stopwatch->beginSpan($label, $meta);
    }

    /**
     * Close the innermost open span.
     *
     * @return array<string, mixed>|null The completed span, or null when unbalanced.
     */
    public function endSpan(): ?array
    {
        return $this->stopwatch->endSpan();
    }

    /**
     * Time a callback as a span, returning its result untouched.
     *
     * @template T
     * @param string $label Operation name.
     * @param callable(): T $callback Work to measure.
     * @param array<string, mixed> $meta Call-specific metadata known before the call.
     * @param null|callable(T): array<string, mixed> $describeResult Maps the result to extra metadata.
     * @return T
     */
    public function measure(
        string $label,
        callable $callback,
        array $meta = [],
        ?callable $describeResult = null
    ): mixed {
        return $this->stopwatch->measure($label, $callback, $meta, $describeResult);
    }

    /**
     * All completed spans in completion order.
     *
     * @return list<array<string, mixed>>
     */
    public function getSpans(): array
    {
        return $this->stopwatch->getSpans();
    }

    /**
     * Per-label rollup ordered by total time descending.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAggregates(): array
    {
        return $this->stopwatch->getAggregates();
    }

    /**
     * The slowest individual spans, duration descending.
     *
     * @param int $limit Maximum number of spans returned.
     * @return list<array<string, mixed>>
     */
    public function getTopSpans(int $limit = 25): array
    {
        return $this->stopwatch->getTopSpans($limit);
    }

    /**
     * The slowest individual spans by exclusive (self) time, descending.
     *
     * @param int $limit Maximum number of spans returned.
     * @return list<array<string, mixed>>
     */
    public function getTopExclusiveSpans(int $limit = 25): array
    {
        return $this->stopwatch->getTopExclusiveSpans($limit);
    }

    /**
     * Number of spans discarded after the collector cap was reached.
     *
     * @return int
     */
    public function getDroppedSpanCount(): int
    {
        return $this->stopwatch->getDroppedSpanCount();
    }

    /**
     * Number of currently open (unbalanced) spans.
     *
     * @return int
     */
    public function getOpenSpanCount(): int
    {
        return $this->stopwatch->getOpenSpanCount();
    }

    /**
     * Merge request metadata into the report.
     *
     * @param array<string, mixed> $context Metadata keyed by name.
     * @return void
     */
    public function addContext(array $context): void
    {
        $this->context = array_merge($this->context, $context);
    }

    /**
     * All request metadata collected so far.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Read a single request metadata value.
     *
     * @param string $key Metadata name.
     * @param mixed $default Value returned when the key is absent.
     * @return mixed
     */
    public function getContextValue(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }
}
