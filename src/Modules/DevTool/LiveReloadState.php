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

    /** @var bool Whether a tick is still owed for what is currently pending. */
    private bool $clearing = false;

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

        // The pending set is now owed one clear, on the next tick.
        $this->clearing = true;
    }

    /**
     * Forget what has been reported, called by the watcher on its next pass.
     *
     * This is why the endpoint peeks instead of draining. Draining on read means the first tab to
     * poll takes the change and every other tab gets nothing — so a developer with the page open
     * in two windows watches one of them never reload. Clearing on the following tick instead
     * gives every tab a full interval to notice, and is still early enough that a page which
     * reloaded on the change does not then see it again and reload in a loop.
     */
    public function clear(): void
    {
        $this->pending = [];
        $this->clearing = false;
    }

    /**
     * Whether a clear is owed.
     *
     * @internal Read by the watcher; true means the next tick clears.
     */
    public function owesClear(): bool
    {
        return $this->clearing;
    }

    /**
     * Changes a poller has not seen yet, without consuming them.
     *
     * @return list<string>
     */
    public function peek(): array
    {
        return $this->pending;
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }
}
