<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Worker;

/**
 * A stop that a test, or any caller, raises by hand.
 *
 * @package Phue\Modules\Queue\Worker
 */
final class ManualStopSignal implements StopSignalInterface
{
    /** Set by requestStop() and read until the worker acts on it. */
    private bool $stopRequested = false;

    /**
     * Ask the worker to stop after the job in hand.
     */
    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    /**
     * Whether a stop has been requested.
     */
    public function stopRequested(): bool
    {
        return $this->stopRequested;
    }
}
