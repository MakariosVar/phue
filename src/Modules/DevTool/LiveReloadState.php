<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

/**
 * What the watcher saw, for whoever asks next.
 *
 * The watcher is the only thing that detects a change. The polling endpoint reads the result
 * rather than looking for changes itself, because two detectors race for the same baseline: the
 * `ChangeTracker` moves its baseline as it answers, so whichever one asked first would consume
 * the change and the other would report nothing. A poller that loses that race reloads only when
 * a request happens to follow the file save, which is exactly the behaviour this replaced.
 *
 * @package Phue\Modules\DevTool
 */
final class LiveReloadState
{
    /** @var list<string> */
    private array $pending = [];

    /**
     * Record a change the watcher has seen.
     *
     * @param list<string> $names
     */
    public function record(array $names): void
    {
        if ($names === []) {
            return;
        }

        $this->pending = array_values(array_unique([...$this->pending, ...$names]));
    }

    /**
     * Changes not yet reported to a poller.
     *
     * Draining rather than reading is what makes the fallback poller behave like the realtime
     * channel: a change is announced once, and a tab that reconnects later does not reload in a
     * loop over a change it already handled.
     *
     * @return list<string>
     */
    public function drain(): array
    {
        $pending = $this->pending;
        $this->pending = [];

        return $pending;
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }
}
