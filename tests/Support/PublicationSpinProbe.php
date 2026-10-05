<?php

declare(strict_types=1);

/**
 * Measures how hard the publication pump spins after an unexpected failure.
 *
 * Run as its own process because the answer is a loop that does not end: the pump reads until it is
 * stopped, and a Swoole coroutine running it keeps the process from exiting on its own. A test that
 * measured this in-process would hang the suite rather than report a number, and "hangs forever" is
 * a much worse thing to hand someone than "asked 4 times in half a second".
 *
 * Prints one integer: how many reads were attempted in the window. The window is fixed here so the
 * caller asserts on a rate rather than on a timing of its own.
 *
 * @internal Run by {@see \Phue\Tests\Modules\Websocket\PublicationPumpTest}. Not a test itself.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Phue\Core\Websocket\Connection;
use Phue\Core\Websocket\PublicationBus;
use Phue\Core\Websocket\TopicRegistry;
use Phue\Modules\Websocket\PublicationPump;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

$windowSeconds = (float) ($argv[1] ?? 0.5);

/** A bus that raises something the degradation does not recognise. */
$bus = new class implements PublicationBus {
    public int $calls = 0;

    public function isShared(): bool
    {
        return true;
    }

    public function origin(): string
    {
        return 'worker-spin-probe';
    }

    public function publish(string $channel, string $event, mixed $data, array $except = []): array
    {
        return ['id' => 1, 'payload' => '{}'];
    }

    public function next(): ?array
    {
        $this->calls++;

        throw new \LogicException('something entirely unexpected');
    }

    public function missed(string $channel, int $since): iterable
    {
        return [];
    }

    public function oldest(string $channel): ?int
    {
        return null;
    }

    public function cursor(): ?int
    {
        return null;
    }

    public function retain(string $channel, int $messages): void
    {
    }
};

/** Counts what it was told, so the probe can also report whether anything was said at all. */
$logger = new class extends AbstractLogger {
    public int $warnings = 0;

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (strtolower((string) $level) === LogLevel::WARNING) {
            $this->warnings++;
        }
    }
};

$pump = new PublicationPump(
    registry: new TopicRegistry(static fn(Connection $c, string $p): bool => true, bus: $bus),
    logger: $logger,
    yieldMs: 25
);

// run(), at the top level of a script, is the way to get a coroutine context. The same call from
// inside a coroutine is a fatal — which is why the pump itself never makes it.
\Swoole\Coroutine\run(static function () use ($pump, $bus, $logger, $windowSeconds): void {
    \Swoole\Coroutine::create(static function () use ($pump): void {
        $pump->run();
    });

    \Swoole\Coroutine::sleep($windowSeconds);
    $pump->stop();

    echo $bus->calls, ' ', $logger->warnings, PHP_EOL;
});

// Deliberately abrupt: a coroutine sleeping out its back-off would otherwise hold the process open
// until the test runner's timeout, which is the failure this file exists to prevent.
exit(0);