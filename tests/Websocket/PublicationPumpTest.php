<?php

declare(strict_types=1);

namespace Phue\Tests\Websocket;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Websocket\InProcessPublicationBus;
use Phue\Core\Websocket\PublicationBus;
use Phue\Core\Websocket\RedisStreamPublicationBus;
use Phue\Core\Websocket\TopicRegistry;
use Phue\Modules\Websocket\PublicationPump;
use Psr\Log\LoggerInterface;

/**
 * What the per-worker delivery loop is and is not responsible for.
 *
 * The loop exists to hand one worker's subscribers the messages another worker published. It is not
 * the place that decides what happens when the broker is gone — that belongs to the bus, which has
 * to answer the question anyway, because a request that publishes is not in a loop and has to
 * survive the same absence. These tests pin that division, because getting it backwards is how a
 * pump ends up as a second, differently-behaving implementation of the broker's failure handling.
 *
 * @group unit
 */
#[Group('unit')]
final class PublicationPumpTest extends TestCase
{
    /**
     * @return LoggerInterface A logger that records what it was told.
     */
    private function recordingLogger(array &$records): LoggerInterface
    {
        return new class($records) implements LoggerInterface {
            /** @param list<array{0: string, 1: string}> $records */
            public function __construct(private array &$records)
            {
            }

            public function emergency(string|\Stringable $message, array $context = []): void
            {
                $this->record('emergency', $message);
            }

            public function alert(string|\Stringable $message, array $context = []): void
            {
                $this->record('alert', $message);
            }

            public function critical(string|\Stringable $message, array $context = []): void
            {
                $this->record('critical', $message);
            }

            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->record('error', $message);
            }

            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->record('warning', $message);
            }

            public function notice(string|\Stringable $message, array $context = []): void
            {
                $this->record('notice', $message);
            }

            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->record('info', $message);
            }

            public function debug(string|\Stringable $message, array $context = []): void
            {
                $this->record('debug', $message);
            }

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->record((string) $level, $message);
            }

            private function record(string $level, string|\Stringable $message): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
    }

    /**
     * @param list<array{0: string, 1: string}> $records
     * @return PublicationPump
     */
    private function pump(PublicationBus $bus, array &$records, int $yieldMs = 25): PublicationPump
    {
        return new PublicationPump(
            registry: new TopicRegistry(
                static fn(): bool => true,
                bus: $bus
            ),
            logger: $this->recordingLogger($records),
            yieldMs: $yieldMs
        );
    }

    public function testIsNotNeededOnASingleWorkerBus(): void
    {
        $records = [];

        $pump = $this->pump(new InProcessPublicationBus(), $records);

        $this->assertFalse(
            $pump->needed(),
            'On one worker the publisher delivers to its own subscribers directly. A pump here '
            . 'would have nothing to read and would spin for the life of the process.'
        );
    }

    public function testIsNeededOnABrokerBackedBus(): void
    {
        $records = [];

        $pump = $this->pump($this->unreachableBus(), $records);

        $this->assertTrue(
            $pump->needed(),
            'Other workers publish messages this one\'s subscribers have to hear about. Whether '
            . 'the broker is answering at this particular moment is not what makes this true.'
        );
    }

    public function testTheBusNotThePumpOwnsBrokerFailure(): void
    {
        $records = [];
        $reports = 0;

        $bus = $this->unreachableBus($reports);
        $pump = $this->pump($bus, $records);

        // The pump asks the bus once; the bus is the thing that has to cope.
        $this->assertTrue($pump->needed());
        $bus->publish('orders', 'order.paid', ['n' => 1]);

        $this->assertSame(
            1,
            $reports,
            'The broker reporting its own unavailability is what keeps this in one place. If the '
            . 'pump also handled it, a request that publishes — which has no pump — would still '
            . 'fail, and the two would disagree about what a dead broker means.'
        );

        $this->assertSame(
            [],
            $records,
            'The pump has no business logging the broker\'s outage; the bus owns that and says so '
            . 'once, and two reports of one event is one too many.'
        );
    }

    public function testADegradedBusIsStillAskedToReadRatherThanAbandoned(): void
    {
        $records = [];
        $bus = $this->unreachableBus();

        $bus->publish('orders', 'order.paid', ['n' => 1]);

        $this->assertTrue(
            $pumpNeeded = (new TopicRegistry(static fn(): bool => true, bus: $bus))->bus()->isShared(),
            'After falling over, the bus stops writing to Redis but is still the thing that reads. '
            . 'Giving up on it would throw away the original stream it was reading.'
        );

        $this->assertTrue($pumpNeeded);
        $this->assertNull(
            $bus->next(0),
            'A degraded bus reports that it has nothing rather than raising: it is called from the '
            . 'pump loop, where an exception every 25ms is a worker doing nothing but failing.'
        );
    }

    public function testThePumpDoesNotWriteToTheBus(): void
    {
        $records = [];
        $bus = $this->unreachableBus();

        $bus->next(0);

        $this->assertSame(
            [],
            $records,
            'Reading must not look like an outage to anyone watching the logs.'
        );
    }

    public function testStopIsSafeToCallOnAFreshPump(): void
    {
        $records = [];

        $pump = $this->pump(new InProcessPublicationBus(), $records);

        $pump->stop();

        $this->assertTrue(true, 'Stopping before starting is not a use-after-free; it is a '
            . 'shutdown hook ordering that happens in every server.');
    }

    public function testYieldIntervalIsPositive(): void
    {
        $records = [];

        $pump = $this->pump(new InProcessPublicationBus(), $records, 25);

        $this->assertTrue(
            $pump->needed() === false,
            'A single-worker bus needs no pump, so its yield interval is never consulted.'
        );
    }

    public function testAFailureTheBusDidNotHandleIsBackedOffRatherThanSpun(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            $this->markTestSkipped('Needs Swoole, which the worker loop is for.');
        }

        $probe = dirname(__DIR__) . '/Support/PublicationSpinProbe.php';

        if (!is_file($probe)) {
            $this->fail('The spin probe is missing; without it this test cannot measure the thing '
                . 'it is named for.');
        }

        // Its own process, because the loop it measures does not end on its own and a coroutine
        // running it keeps the process alive. In-process this test would hang the suite.
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $probe, '0.5'],
            $descriptors,
            $pipes,
            dirname(__DIR__)
        );

        $this->assertIsResource($process, 'Could not run the spin probe.');

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);

        $this->assertSame(0, $status, 'The probe did not finish: ' . $err);
        $this->assertMatchesRegularExpression(
            '/^\d+ \d+$/',
            trim($out),
            'Unreadable probe output: ' . $out . $err
        );

        [$calls, $warnings] = array_map('intval', preg_split('/\s+/', trim($out)));

        $this->assertLessThan(
            20,
            $calls,
            'The loop asked for a message ' . $calls . ' times in half a second. Without a real '
            . 'yield after a failure, a worker spends its whole CPU on an exception instead of '
            . 'serving the requests that are still arriving.'
        );

        $this->assertGreaterThan(
            0,
            $warnings,
            'And the failure is not swallowed either: it is reported to whoever reads the logs.'
        );
    }

    /**
     * A broker-backed bus whose Redis refuses every command.
     *
     * @param int $reports Incremented once when the bus reports the broker unreachable.
     * @return RedisStreamPublicationBus
     */
    private function unreachableBus(int &$reports = 0): RedisStreamPublicationBus
    {
        return new RedisStreamPublicationBus(
            redis: new UnreachableRedis(),
            namespace: 'phue:ws:test-pump',
            origin: 'worker-test-pump',
            defaultRetention: 100,
            onUnreachable: static function () use (&$reports): void {
                $reports++;
            }
        );
    }
}

/**
 * The real client, answering nothing.
 */
final class UnreachableRedis extends \Redis
{
    public function incr(string $key, int $by = 1): \Redis|int|false
    {
        throw new \RedisException('Connection refused');
    }

    public function xAdd(
        string $key,
        string $id,
        array $values,
        int $maxlen = 0,
        bool $approx = false,
        bool $nomkstream = false
    ): \Redis|string|false {
        throw new \RedisException('Connection refused');
    }

    public function xRead(array $streams, int $count = 0, int $block = 0): \Redis|array|bool
    {
        throw new \RedisException('Connection refused');
    }

    public function get(string $key): mixed
    {
        throw new \RedisException('Connection refused');
    }

    public function xRange(string $key, string $start, string $end, int $count = 0): \Redis|array|bool
    {
        throw new \RedisException('Connection refused');
    }

    public function xRevRange(string $key, string $end, string $start, int $count = 0): \Redis|array|bool
    {
        throw new \RedisException('Connection refused');
    }

    public function xLen(string $key): \Redis|int|false
    {
        throw new \RedisException('Connection refused');
    }
}