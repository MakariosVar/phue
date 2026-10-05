<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

use Phue\Core\Response;

/**
 * Tells a browser which components changed since it last asked.
 *
 * The endpoint answers with JSON and returns. That is the whole design, and it is the reason
 * it is safe: every request costs a worker for the length of one directory scan, and then the
 * worker is free. A tab left open over lunch holds nothing.
 *
 * It exists because the obvious alternative does not work here. A Server-Sent Events stream is
 * a request that never finishes, so it occupies its worker for as long as the tab is open — and
 * Swoole offers no way to notice the tab closing: not write(), not isWritable(), not exists(),
 * not connection_aborted(), because the chunks are small enough that the kernel send buffer
 * never reports a broken pipe. Four open tabs took the whole dev server down. Four requests a
 * second do not.
 *
 * The handler holds no state. It is registered as a class-string so the route stays cacheable,
 * and the router builds a class-string handler with Container::make() on every request — which
 * calls the factory each time and skips the singleton cache. The answer to "changed since when"
 * therefore lives in a ChangeTracker singleton this handler merely resolves.
 *
 * @package Phue\Modules\DevTool
 */
final class LiveReloadRoute
{
    /**
     * Where the endpoint lives.
     *
     * Owned here rather than repeated in the module that registers it and the toolbar that polls
     * it, because two spellings of one path is a 404 that only shows up in development.
     */
    public const ENDPOINT = '/_phue/live-reload';

    /**
     * The topic component changes are announced on.
     *
     * Named after the endpoint so there is one obvious place to look when a page is not
     * reloading, and no second spelling of the name between the publisher and the client.
     */
    public const ENDPOINT_TOPIC = '_phue:components';

    public function __construct(
        private readonly LiveReloadState $state,
        private readonly ?LiveReloadWatcher $watcher = null,
    ) {
    }

    /**
     * @return Response
     */
    public function __invoke(): Response
    {
        // The endpoint reports; it does not look. Two detectors would compete for one baseline,
        // and ChangeTracker moves its baseline as it answers — so whichever asked first would take
        // the change and the other would see nothing. This handler therefore delegates the looking
        // to the one watcher, and only reports.
        //
        // Without the cache header below, a browser or an intermediary is free to serve the answer
        // to the next poll from its own cache, and the page stops noticing changes that never
        // happened.
        //
        // Both halves are needed, and neither alone works. The state carries a change from a
        // background tick, which is how a Swoole server reports it to every open tab. This
        // request's own tick is how a server with no timer reports it at all: there, nothing
        // survives between requests, so a change recorded by this tick is gone before the next
        // poll could read it — and the tab would sit and poll an empty list forever.
        //
        // Reading before ticking, and the tick reporting what it found, is also what makes the
        // change appear exactly once. The once-only guarantee belongs to ChangeTracker's
        // advancing baseline, not to the state: whatever request performs the comparison moves it,
        // so the next one finds nothing to report.
        $changed = $this->state->peek();
        $found = $this->watcher?->tick() ?? [];

        if ($found !== []) {
            $changed = $changed === []
                ? $found
                : array_values(array_unique([...$changed, ...$found]));
        }

        return Response::json(['changed' => $changed])
            ->setHeader('Cache-Control', 'no-store, must-revalidate');
    }
}