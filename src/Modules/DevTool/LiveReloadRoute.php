<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

use Phue\Core\Hiit\ChangeTracker;
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
        private readonly ChangeTracker $tracker,
    ) {
    }

    /**
     * @return Response
     */
    public function __invoke(): Response
    {
        // Without this a browser or an intermediary is free to serve the answer to the next poll
        // from its own cache, and the page stops noticing changes that never happened.
        return Response::json(['changed' => $this->tracker->changed()])
            ->setHeader('Cache-Control', 'no-store, must-revalidate');
    }
}