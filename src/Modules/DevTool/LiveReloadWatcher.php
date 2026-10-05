<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

use Phue\Core\Hiit\ChangeTracker;
use Phue\Modules\Websocket\Broadcast;
use Phue\Modules\Websocket\WebsocketConfig;

/**
 * Watches the component directories and announces what changed, whether or not anyone is asking.
 *
 * The obvious place for this is inside a request — that is where the change was detected before,
 * and it is free. It stops working the moment detection is supposed to be request-independent: a
 * developer saves a file, the browser sits idle because the realtime channel means it has no
 * reason to poll, and nothing in the process ever looks at the filesystem. The reload then
 * depends on the developer happening to click something.
 *
 * So detection moves to a timer. The cost is one directory scan per interval per worker, which is
 * what every open tab used to pay on its own — the difference is one scan for the whole process
 * instead of one per tab, and the scan is not the expensive part. What is expensive is rebuilding
 * components, and that still happens lazily on the next request.
 *
 * One tracker per worker, not one per server: each worker has its own connections and its own
 * subscriptions, so each has to notice changes to push to them. A tracker in the master process
 * would notice them for nobody.
 *
 * @package Phue\Modules\DevTool
 */
final class LiveReloadWatcher
{
    public function __construct(
        private readonly ChangeTracker $tracker,
        private readonly LiveReloadState $state,
        private readonly ?Broadcast $broadcast,
    ) {
    }

    /**
     * One pass. Announced on the realtime channel, and recorded for the polling fallback.
     *
     * @return list<string> What changed, for a caller that wants to log or test it.
     */
    public function tick(): array
    {
        $changed = $this->tracker->changed();

        if ($changed === []) {
            return [];
        }

        $this->state->record($changed);

        // Best effort, and deliberately after the state is recorded: a broadcast failure must not
        // cost the polling fallback the change, because that fallback is the reason a broken
        // realtime channel degrades into slow reloads rather than into none.
        try {
            $this->broadcast?->for(WebsocketConfig::DEV_ENDPOINT)
                ->to(LiveReloadRoute::ENDPOINT_TOPIC, 'components.changed', ['changed' => $changed]);
        } catch (\Throwable) {
            // Nothing to do. The poller will still see it.
        }

        return $changed;
    }
}
