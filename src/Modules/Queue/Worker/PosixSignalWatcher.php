<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Worker;

/**
 * Stops the worker on SIGTERM or SIGINT, when the platform can tell it.
 *
 * The signal handlers matter during a deploy. Without them a SIGTERM kills the process wherever it
 * happens to be, the job in hand is abandoned, and its lease has to run out before another worker
 * can take it — so every deploy costs one stuck job for the length of the lease. With them the
 * worker finishes what it has and exits cleanly.
 *
 * Degrades to never stopping when ext-pcntl is absent, rather than failing to construct. A worker
 * that cannot be started at all is a worse answer than a worker that does not stop politely, and
 * an operator can always stop it with a signal the runtime does handle.
 *
 * @package Phue\Modules\Queue\Worker
 */
final class PosixSignalWatcher implements StopSignalInterface
{
    /** Set by the signal handler, read by the worker between jobs. */
    private bool $stopRequested = false;

    /** Whether handlers were actually installed, as opposed to this being a permanent no. */
    private bool $installed = false;

    /**
     * @return bool Whether signals will actually be observed.
     */
    public function install(): bool
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return false;
        }

        foreach ([\SIGTERM, \SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stopRequested = true;
            });
        }

        // Without this the handlers only run between opcodes, which for a worker blocked on a
        // database call means never.
        pcntl_async_signals(true);

        $this->installed = true;

        return true;
    }

    /**
     * Whether a signal has arrived and not yet been acted on.
     *
     * Read rather than cleared, so a worker that checks it between two jobs sees the same answer
     * twice. The worker is the only thing that decides when to stop, and it decides once.
     */
    public function stopRequested(): bool
    {
        return $this->stopRequested;
    }

    /**
     * Whether real signals are being observed, as opposed to this being a permanent no.
     */
    public function isInstalled(): bool
    {
        return $this->installed;
    }

    /**
     * Run pending signal handlers.
     *
     * Called by the worker between jobs rather than relied on implicitly, because the tick only
     * happens where pcntl_async_signals is unavailable and the loop has to ask anyway.
     */
    public function dispatch(): void
    {
        if ($this->installed && function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}
