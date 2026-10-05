<?php

declare(strict_types=1);

namespace Phue\Modules\Websocket;

use Phue\Core\Websocket\SettablePublicationBus;
use Phue\Core\Websocket\TopicRegistry;
use Psr\Log\LoggerInterface;

/**
 * Reads published messages off the bus and hands them to this process's subscribers.
 *
 * One of these per endpoint per worker, running as a coroutine for as long as the worker lives. It
 * is the only reason a publish reaches a browser held by a different worker: that connection lives
 * in another process, and nothing can be written to it from here except by that process reading the
 * message and doing it itself.
 *
 * It only exists when the bus is shared. With one worker there is nowhere for a message to go that
 * this has not already been delivered to, so the pumping would be pure overhead — which is why
 * {@see needed()} is asked before one is started.
 *
 * @package Phue\Modules\Websocket
 */
final class PublicationPump
{
    private bool $stopped = false;

    /** How many messages this pump has delivered. */
    private int $delivered = 0;

    public function __construct(
        private readonly TopicRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly int $yieldMs = 25
    ) {
    }

    /**
     * Whether this process has anything to do on a bus.
     *
     * @return bool False for an in-process bus, where the publisher delivers to its own
     *         subscribers directly and there is no second process to deliver for.
     */
    public function needed(): bool
    {
        return $this->registry->bus()->isShared();
    }

    /**
     * Read and deliver until the worker stops.
     *
     * Reads without blocking and yields between attempts.
     *
     * A blocking Redis read is what this wants to do and cannot: ext-redis is not coroutine
     * aware, so a `BLOCK 1000` inside a worker holds the whole worker for a second and the server
     * stops answering HTTP entirely — every worker stuck in the same read. Measured, not
     * theorised: four workers, zero responses.
     *
     * So the read is non-blocking and the coroutine sleeps instead, which hands the event loop
     * back and lets the worker serve the requests that also arrive. The cost is one interval of
     * latency per message; the alternative is not being up at all.
     *
     * @return void
     */
    public function run(): void
    {
        $bus = $this->registry->bus();
        $origin = $bus->origin();

        // Place the offset past whatever was published before this worker existed. A worker
        // starting now holds no connections, so it has nobody to reach with the backlog — and an
        // offset that keeps asking "anything new?" without ever advancing would never see a
        // message published between two of its polls.
        if ($bus instanceof SettablePublicationBus) {
            $bus->settle();
        }

        while (!$this->stopped) {
            try {
                $message = $bus->next(0);
            } catch (\Throwable $e) {
                // A bus that has gone away in a way it does not recognise — a driver fault, a
                // coding error — must not take the worker with it, and must not spin either.
                //
                // Both halves of that are load-bearing, and the sleep is the half that is easy to
                // get wrong. `Timer::after(1000)` looks like a delay and is not one: it takes a
                // callback as its first argument, so calling it with just a duration is a fatal
                // ArgumentCountError that kills the worker from inside its own error handler.
                // `Coroutine::sleep` is the yield, and it is what keeps this loop from becoming a
                // tight loop of failing reads.
                $this->logger->warning('WebSocket publication bus unavailable; realtime delivery is paused.', [
                    'exception' => $e,
                ]);

                \Swoole\Coroutine::sleep(1.0);
                continue;
            }

            if ($message === null) {
                // The yield that lets the worker serve requests while this waits for the next
                // message. Without it the loop never returns to the event loop and the worker
                // serves nothing at all.
                \Swoole\Coroutine::sleep($this->yieldMs / 1000);
                continue;
            }

            // The copy that comes back around from a publish this process made. It was delivered
            // to these subscribers synchronously, and delivering it again would double every
            // message on a single-worker deployment — the one nobody tests.
            if ($message['origin'] === $origin) {
                continue;
            }

            $this->registry->deliverFromBus(
                $message['channel'],
                $message['payload'],
                $message['id'],
                $message['except']
            );

            $this->delivered++;
        }
    }

    /**
     * Ask the loop to stop, so the worker can shut down without waiting out a blocking read.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * How many messages this pump has delivered, for tests and for a diagnostic that says whether
     * the loop is running at all rather than merely present.
     *
     * @return int
     */
    public function delivered(): int
    {
        return $this->delivered;
    }
}