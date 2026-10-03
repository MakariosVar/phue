<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

/**
 * Class Stopwatch
 *
 * Provides high-precision nanosecond timing using hrtime(true) and collects nested,
 * named spans for the DevTool debug bar.
 *
 * Spans are recorded in two complementary shapes, because each answers a different
 * question and neither alone is sufficient on a hot path:
 *
 *  - Per-label aggregates (count / total / max / avg) answer "which operation is hot".
 *    A recursive builder that runs 6000 times must not become 6000 toolbar rows.
 *  - The raw ordered span list answers "which exact call was slow". Aggregates throw
 *    away the call-specific metadata (component name, socket path) that makes a slow
 *    call actionable.
 *
 * Two hard caps keep a memory-resident worker (Swoole/RoadRunner) from growing without
 * bound. Both preserve nesting correctness: a frame that cannot be recorded still
 * propagates its elapsed time to its parent, so exclusive time stays truthful.
 *
 * @package Phue\Modules\DevTool
 */
class Stopwatch
{
    /**
     * Maximum nesting depth kept. Deeper frames are still timed, but not recorded.
     */
    public const MAX_SPAN_DEPTH = 12;

    /**
     * Maximum number of recorded spans per request. Beyond this, spans are dropped.
     */
    public const MAX_SPANS = 20000;

    /**
     * Maximum number of distinct values reported per categorical metadata key.
     *
     * The histogram itself is counted in full so `distinct` stays exact; only the reported
     * tail is capped, because a component-heavy request can carry hundreds of names under
     * one label and the table has no room for them.
     */
    public const META_VALUE_LIMIT = 5;

    /**
     * @var float|null Start timestamp in nanoseconds.
     */
    private ?float $startTime = null;

    /**
     * @var float|null Elapsed duration in milliseconds.
     */
    private ?float $durationMs = null;

    /**
     * Open span frames, innermost last.
     *
     * @var list<array{label: string, meta: array<string, mixed>, start: float, child_ms: float, record: bool}>
     */
    private array $stack = [];

    /**
     * Completed spans in completion order.
     *
     * @var list<array{label: string, meta: array<string, mixed>, depth: int, duration_ms: float, exclusive_ms: float}>
     */
    private array $spans = [];

    /**
     * Number of spans discarded because MAX_SPANS was reached.
     */
    private int $droppedSpans = 0;

    /**
     * Start the stopwatch timer.
     *
     * @return self
     */
    public function start(): self
    {
        $this->startTime = hrtime(true);
        $this->durationMs = null;
        return $this;
    }

    /**
     * Stop the stopwatch timer and calculate elapsed time.
     *
     * @return float Duration in milliseconds.
     */
    public function stop(): float
    {
        if ($this->startTime === null) {
            return 0.0;
        }

        $end = hrtime(true);
        $diffNs = $end - $this->startTime;
        $this->durationMs = $diffNs / 1_000_000;

        return $this->durationMs;
    }

    /**
     * Get the measured duration in milliseconds.
     *
     * @return float
     */
    public function getDurationMs(): float
    {
        return $this->durationMs ?? 0.0;
    }

    /**
     * Open a named span. Must be paired with endSpan().
     *
     * The frame is always pushed, even past the depth or count cap, so that endSpan()
     * stays balanced and exclusive time of the parent remains correct.
     *
     * @param string $label Operation name, e.g. 'loader.moduleHash'.
     * @param array<string, mixed> $meta Call-specific metadata surfaced in the toolbar.
     * @return void
     */
    public function beginSpan(string $label, array $meta = []): void
    {
        // A frame opened directly inside an identical label is not a second operation.
        // Resolution is instrumented on BOTH ComponentLoaderRegistry and
        // PhueComponentLoader on purpose — the renderer reaches the loader directly in
        // places, so a single-layer profiler has a blind spot on the hot path — and every
        // registry call therefore opens the same label again underneath itself. Recording
        // both counts one call twice and reports more time under the label than the
        // request took, which makes every figure derived from it unusable.
        //
        // The frame is still pushed, so endSpan() keeps the stack balanced and still hands
        // its elapsed time to the parent: the work is charged to the operation rather than
        // lost, exclusive time keeps partitioning the request, and the fold frees a slot in
        // the depth budget that the doubling was consuming.
        $parentLabel = $this->stack === []
            ? null
            : $this->stack[count($this->stack) - 1]['label'];

        $folded = $parentLabel !== null && $parentLabel === $label;

        $recordable = !$folded
            && count($this->stack) < self::MAX_SPAN_DEPTH
            && count($this->spans) < self::MAX_SPANS;

        if (!$recordable && !$folded && count($this->spans) >= self::MAX_SPANS) {
            $this->droppedSpans++;
        }

        $this->stack[] = [
            'label' => $label,
            'meta' => $meta,
            'start' => hrtime(true),
            'child_ms' => 0.0,
            'record' => $recordable,
            'fold' => $folded,
        ];
    }

    /**
     * Close the innermost open span and return the recorded span.
     *
     * @return array{label: string, meta: array<string, mixed>, depth: int, duration_ms: float, exclusive_ms: float}|null
     *         The completed span, or null when no span was open (unbalanced call).
     */
    public function endSpan(): ?array
    {
        $frame = array_pop($this->stack);

        if ($frame === null) {
            return null;
        }

        $durationMs = (hrtime(true) - $frame['start']) / 1_000_000;

        // The parent's exclusive time is derived from total minus children, so an
        // unrecorded (capped) frame must still contribute its duration to the parent.
        //
        // A FOLDED frame is different: it is the same operation observed from another
        // layer, and its own elapsed time is already inside the parent's duration. Only
        // what its children consumed may be charged to the parent — charging the full
        // duration would erase the folded frame's own slice of work from the request
        // partition, and "Share of self" would stop adding up to 100% without anything in
        // the report saying so. What the folded frame did itself becomes the parent's own.
        if ($this->stack !== []) {
            $parent = count($this->stack) - 1;
            $this->stack[$parent]['child_ms'] += $frame['fold'] ? $frame['child_ms'] : $durationMs;
        }

        if (!$frame['record']) {
            return null;
        }

        $span = [
            'label' => $frame['label'],
            'meta' => $frame['meta'],
            'depth' => count($this->stack) + 1,
            'duration_ms' => $durationMs,
            'exclusive_ms' => max(0.0, $durationMs - $frame['child_ms']),
        ];

        $this->spans[] = $span;

        return $span;
    }

    /**
     * Time a callback as a span and return its result.
     *
     * The span is closed even when the callback throws, so a failure cannot leave the
     * stack unbalanced and corrupt every exclusive time after it.
     *
     * @template T
     * @param string $label Operation name.
     * @param callable(): T $callback Work to measure.
     * @param array<string, mixed> $meta Call-specific metadata known before the call.
     * @param null|callable(T): array<string, mixed> $describeResult Maps the result to extra
     *        metadata. Needed whenever the interesting number is only knowable afterwards —
     *        returned rows, bytes written, an HTTP status.
     * @return T The callback's return value, passed through untouched.
     */
    public function measure(
        string $label,
        callable $callback,
        array $meta = [],
        ?callable $describeResult = null
    ): mixed {
        $this->beginSpan($label, $meta);

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $this->endSpan();

            throw $e;
        }

        $span = $this->endSpan();

        if ($span !== null && $describeResult !== null) {
            $extra = $describeResult($result);

            if (is_array($extra) && $extra !== []) {
                $index = array_key_last($this->spans);

                if ($index !== null) {
                    $this->spans[$index]['meta'] = array_merge($this->spans[$index]['meta'], $extra);
                }
            }
        }

        return $result;
    }

    /**
     * All completed spans, in completion order.
     *
     * @return list<array{label: string, meta: array<string, mixed>, depth: int, duration_ms: float, exclusive_ms: float}>
     */
    public function getSpans(): array
    {
        return $this->spans;
    }

    /**
     * Per-label rollup, ordered by SELF time descending.
     *
     * Keyed by label only: call-specific metadata would defeat the purpose, since a
     * single hot operation (e.g. a recursive builder) would fragment into one bucket
     * per component name. Use getSpans() to see which individual call was slow.
     *
     * Ordered and summed by exclusive time, not by total. Spans nest, so a parent's
     * total_ms contains every descendant: ranking by it credits a wrapper with all of
     * its children's work, and the exclusive totals are the only figures that partition
     * the request instead of overlapping. total_ms is still reported — it answers "how
     * much time sits under this label", which is a different and useful question.
     *
     * Metadata is ROLLED UP, never taken from whichever span happened to close last.
     * A last-wins meta on a label covering 1200 calls across 140 components reported a
     * single component name as if it described the whole row, which is worse than
     * reporting nothing: the toolbar invites you to filter by name, and the name it
     * showed described 3 calls out of 1227. Numeric keys (request_bytes, status) roll up
     * to min/max/last; categorical keys (component, path, method) to an exact distinct
     * count plus the most frequent values.
     *
     * @return array<string, array{count: int, total_ms: float, exclusive_ms: float, max_ms: float, min_ms: float, avg_ms: float, meta: array<string, mixed>}>
     */
    public function getAggregates(): array
    {
        $aggregates = [];

        foreach ($this->spans as $span) {
            $label = $span['label'];

            if (!isset($aggregates[$label])) {
                $aggregates[$label] = [
                    'count' => 0,
                    'total_ms' => 0.0,
                    'exclusive_ms' => 0.0,
                    'max_ms' => 0.0,
                    'min_ms' => $span['duration_ms'],
                    'avg_ms' => 0.0,
                    'meta' => [],
                    'metaRollup' => [],
                ];
            }

            $aggregates[$label]['count']++;
            $aggregates[$label]['total_ms'] += $span['duration_ms'];
            $aggregates[$label]['exclusive_ms'] += $span['exclusive_ms'];
            $aggregates[$label]['max_ms'] = max($aggregates[$label]['max_ms'], $span['duration_ms']);
            $aggregates[$label]['min_ms'] = min($aggregates[$label]['min_ms'], $span['duration_ms']);

            $this->accumulateMeta($aggregates[$label]['metaRollup'], $span['meta']);
        }

        foreach ($aggregates as $label => $aggregate) {
            $aggregates[$label]['avg_ms'] = $aggregate['total_ms'] / $aggregate['count'];
            $aggregates[$label]['meta'] = $this->finalizeMeta($aggregate['metaRollup']);
            unset($aggregates[$label]['metaRollup']);
        }

        uasort(
            $aggregates,
            static fn(array $a, array $b): int => $b['exclusive_ms'] <=> $a['exclusive_ms']
        );

        return $aggregates;
    }

    /**
     * Fold one span's metadata into a per-key accumulator.
     *
     * A key is treated as numeric the first time it is seen as one and keeps that
     * classification for the rest of the request: mixing the two models inside a single
     * key would make the reported shape depend on which span closed first.
     *
     * @param array<string, array<string, mixed>> $rollup Accumulator, keyed by metadata key.
     * @param array<string, mixed> $meta Metadata of the span being folded in.
     * @return void
     */
    private function accumulateMeta(array &$rollup, array $meta): void
    {
        foreach ($meta as $key => $value) {
            $numeric = is_int($value) || is_float($value);

            if (!isset($rollup[$key])) {
                $rollup[$key] = $numeric
                    ? ['numeric' => true, 'min' => $value, 'max' => $value, 'last' => $value, 'conflicts' => 0]
                    : ['numeric' => false, 'counts' => [], 'conflicts' => 0];

                // A metric is fully described by the value that created it; a histogram
                // still has to have that value put into it.
                if ($numeric) {
                    continue;
                }
            } elseif ($rollup[$key]['numeric'] !== $numeric) {
                // A key that changes type mid-request is counted but never coerced. min()/max()
                // on a mixed set compare as strings in PHP 8, so folding 'late-string' into a
                // numeric range silently produced max = 'late-string' — a report that looks
                // structured and is nonsense. The range stays over the values it can describe
                // and the report says how many calls it could not account for.
                $rollup[$key]['conflicts']++;

                continue;
            }

            if ($numeric) {
                $rollup[$key]['min'] = min($rollup[$key]['min'], $value);
                $rollup[$key]['max'] = max($rollup[$key]['max'], $value);
                $rollup[$key]['last'] = $value;

                continue;
            }

            // bool, null and array values are not usable as PHP array keys, so the
            // histogram is keyed by a type-tagged encoding and keeps the original value
            // alongside it for the report.
            $bucket = self::metaBucketKey($value);

            if (!isset($rollup[$key]['counts'][$bucket])) {
                $rollup[$key]['counts'][$bucket] = ['value' => $value, 'count' => 0];
            }

            $rollup[$key]['counts'][$bucket]['count']++;
        }
    }

    /**
     * Turn the per-key accumulator into the reported metadata shape.
     *
     * @param array<string, array<string, mixed>> $rollup Accumulator built by accumulateMeta().
     * @return array<string, mixed>
     */
    private function finalizeMeta(array $rollup): array
    {
        $meta = [];

        foreach ($rollup as $key => $bucket) {
            if ($bucket['numeric']) {
                $meta[$key] = [
                    'kind' => 'metric',
                    'min' => $bucket['min'],
                    'max' => $bucket['max'],
                    'last' => $bucket['last'],
                ];

                if ($bucket['conflicts'] > 0) {
                    $meta[$key]['conflicts'] = $bucket['conflicts'];
                }

                continue;
            }

            $counts = $bucket['counts'];
            $distinct = count($counts);

            // Insertion order is the tie-break, so a label that is called with the same
            // components in the same order always reports them in the same order.
            uasort(
                $counts,
                static fn(array $a, array $b): int => $b['count'] <=> $a['count']
            );

            $top = [];
            foreach ($counts as $entry) {
                if (count($top) >= self::META_VALUE_LIMIT) {
                    break;
                }
                $top[] = ['value' => $entry['value'], 'count' => $entry['count']];
            }

            $meta[$key] = [
                'kind' => 'values',
                'distinct' => $distinct,
                'truncated' => $distinct > count($top),
                'top' => $top,
            ];

            if ($bucket['conflicts'] > 0) {
                $meta[$key]['conflicts'] = $bucket['conflicts'];
            }
        }

        return $meta;
    }

    /**
     * A stable, collision-free array key for an arbitrary metadata value.
     *
     * @param mixed $value Value to encode.
     * @return string
     */
    private static function metaBucketKey(mixed $value): string
    {
        return get_debug_type($value) . "\0" . serialize($value);
    }

    /**
     * The slowest individual spans, ordered by duration descending.
     *
     * @param int $limit Maximum number of spans returned.
     * @return list<array{label: string, meta: array<string, mixed>, depth: int, duration_ms: float, exclusive_ms: float}>
     */
    public function getTopSpans(int $limit = 25): array
    {
        $sorted = $this->spans;

        usort(
            $sorted,
            static fn(array $a, array $b): int => $b['duration_ms'] <=> $a['duration_ms']
        );

        return $sorted === [] ? [] : array_slice($sorted, 0, max(0, $limit));
    }

    /**
     * The slowest individual spans by exclusive (self) time, duration descending.
     *
     * Exclusive time is the honest cost of a call: total time of a recursive parent
     * is dominated by its descendants, so ranking by total hides the actual work.
     *
     * @param int $limit Maximum number of spans returned.
     * @return list<array{label: string, meta: array<string, mixed>, depth: int, duration_ms: float, exclusive_ms: float}>
     */
    public function getTopExclusiveSpans(int $limit = 25): array
    {
        $sorted = $this->spans;

        usort(
            $sorted,
            static fn(array $a, array $b): int => $b['exclusive_ms'] <=> $a['exclusive_ms']
        );

        return $sorted === [] ? [] : array_slice($sorted, 0, max(0, $limit));
    }

    /**
     * Drop every recorded span and unwind any open frames.
     *
     * @return void
     */
    public function resetSpans(): void
    {
        $this->spans = [];
        $this->stack = [];
        $this->droppedSpans = 0;
    }

    /**
     * Number of spans discarded after MAX_SPANS was reached.
     *
     * @return int
     */
    public function getDroppedSpanCount(): int
    {
        return $this->droppedSpans;
    }

    /**
     * Number of currently open (unbalanced) spans.
     *
     * @return int
     */
    public function getOpenSpanCount(): int
    {
        return count($this->stack);
    }
}
