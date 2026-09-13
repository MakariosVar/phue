<?php

declare(strict_types=1);

namespace Phue\Core\DevTool;

/**
 * Class Stopwatch
 *
 * Provides high-precision nanosecond timing using hrtime(true).
 *
 * @package Phue\Core\DevTool
 */
class Stopwatch
{
    /**
     * @var float|null Start timestamp in nanoseconds.
     */
    private ?float $startTime = null;

    /**
     * @var float|null Elapsed duration in milliseconds.
     */
    private ?float $durationMs = null;

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
}