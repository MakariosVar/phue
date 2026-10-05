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
        // Reads what the watcher already found rather than looking for changes itself. Two
        // detectors would compete for one baseline: ChangeTracker moves its baseline as it
        // answers, so whichever asked first would take the change and the other would see nothing.
        //
        // Without the cache header a browser or an intermediary is free to serve the answer to the
        // next poll from its own cache, and the page stops noticing changes that never happened.
        // Read before ticking, and that order is load-bearing. The watcher releases what it
        // reported on its next pass, so a poll that ticked first would clear the answer it was
        // about to read and report nothing at all.
        //
        // The tick is what keeps this endpoint working where nothing else does: a server without a
        // timer — anything but Swoole — has nobody else looking, and would otherwise answer
        // "nothing changed" to every poll forever. With Swoole the change is already recorded, so
        // the scan finds nothing and costs one directory walk.
        $changed = $this->state->peek();

        $this->watcher?->tick();

        return Response::json(['changed' => $changed])
            ->setHeader('Cache-Control', 'no-store, must-revalidate');
    }
}