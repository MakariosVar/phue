<?php

declare(strict_types=1);

namespace Phue\Core\DevTool;

/**
 * Class RequestProfiler
 *
 * Tracks request lifecycle memory usage, peak memory, and execution duration.
 *
 * @package Phue\Core\DevTool
 */
class RequestProfiler
{
    /**
     * @var int Initial memory usage in bytes.
     */
    private int $startMemory = 0;

    public function __construct(
        private Stopwatch $stopwatch
    ) {}

    /**
     * Start profiling the request.
     *
     * @return void
     */
    public function start(): void
    {
        $this->startMemory = memory_get_usage();
        $this->stopwatch->start();
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
}