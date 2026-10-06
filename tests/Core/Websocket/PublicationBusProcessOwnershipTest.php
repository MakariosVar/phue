<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Websocket;

use PHPUnit\Framework\TestCase;
use Phue\Core\Websocket\Connection;
use Phue\Core\Websocket\RedisStreamPublicationBus;
use Phue\Core\Websocket\TopicRegistry;
use Phue\Modules\Websocket\PublicationPump;
use Phue\Tests\Support\RedisTestConnection;

/**
 * What a publication bus does when the process that built it is not the process using it.
 *
 * A real fork, not a second object in the same process. Swoole forks its workers *after*
 * `Server::start()`, from the memory image the master built — and the module that owns the bus is part
 * of that image. Two bus objects in one process, which is what the rest of this suite does, prove
 * nothing about process ownership: each of them is trivially the only one in its own process.
 *
 * Needs a Redis, like its siblings, and a Swoole to fork with.
 */
final class PublicationBusProcessOwnershipTest extends TestCase
{
    private ?RedisTestConnection $harness = null;

    /** @var list<RedisStreamPublicationBus> */
    private array $buses = [];

    /** How many times this process asked the factory for a connection. */
    private int $connections = 0;

    /** How many times the bus reported that it had stopped reaching anybody else. */
    private int $reports = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->harness = RedisTestConnection::available();

        if ($this->harness === null) {
            $this->markTestSkipped('No Redis available; set PHUE_TEST_REDIS_HOST to run this suite.');
        }

        if (!class_exists(\Swoole\Process::class)) {
            $this->markTestSkipped('Swoole\Process is what forks here; without it there is no second process.');
        }
    }

    protected function tearDown(): void
    {
        $this->harness?->cleanup();

        parent::tearDown();

        $this->harness = null;
        $this->buses = [];
        $this->connections = 0;
        $this->reports = 0;
    }

    /**
     * A bus built the way the module builds it.
     *
     * The origin string is the module's, verbatim, including the random suffix: a guarantee that only
     * holds when the caller hands over a good origin is not a guarantee, and this is what the shipped
     * wiring passes.
     *
     * `0-0` unless asked otherwise, so the bus reads what was published before it forked — which is the
     * case where being wrong about ownership shows.
     *
     * @param bool $withFactory Whether the bus is told how to reach the broker again, which is what
     *        {@see \Phue\Modules\Websocket\WebsocketModule} does and what a bus built by hand often
     *        does not.
     */
    private function moduleBus(bool $withFactory = true, string $offset = '0-0'): RedisStreamPublicationBus
    {
        $harness = $this->harness;

        return $this->buses[] = new RedisStreamPublicationBus(
            redis: $harness->redis,
            connectionFactory: $withFactory
                ? function () use ($harness): \Redis {
                    $this->connections++;

                    return $harness->client();
                }
                : null,
            namespace: $harness->namespace,
            origin: 'worker-' . getmypid() . '-' . bin2hex(random_bytes(4)),
            initialOffset: $offset,
            onUnreachable: function (\Throwable $e): void {
                $this->reports++;
            }
        );
    }

    /**
     * Run work in a second process that inherited $bus, and return what it reported.
     *
     * The closure is handed the inherited object rather than being told to build its own, because
     * "builds its own" is the case that works — this is the one where a process holds a bus that
     * belongs to somebody else.
     *
     * Failures travel back as a report rather than as a child's exit code: a child that dies before it
     * writes leaves the parent waiting on a pipe that never answers, and a hung suite is a much worse
     * thing to hand someone than a failed assertion.
     *
     * @param callable(RedisStreamPublicationBus): array<string, mixed> $work
     * @return array<string, mixed>
     */
    private function inForkedChild(RedisStreamPublicationBus $bus, callable $work): array
    {
        $process = new \Swoole\Process(static function (\Swoole\Process $child) use ($bus, $work): void {
            try {
                $report = ['pid' => getmypid()] + $work($bus);
            } catch (\Throwable $e) {
                $report = ['pid' => getmypid(), 'error' => $e::class . ': ' . $e->getMessage()];
            }

            $child->write((string) json_encode($report));
        });

        // Bounded, so a child that never answers fails the assertion instead of the run.
        $process->setTimeout(10.0);

        $process->start();

        $raw = $process->read();
        $process->wait();

        $this->assertIsString($raw, 'the forked process reported nothing within ten seconds');

        $report = json_decode($raw, true);

        $this->assertIsArray($report, 'the forked process reported nothing usable: ' . $raw);
        $this->assertArrayNotHasKey('error', $report, 'the forked process failed: ' . (string) ($report['error'] ?? ''));
        $this->assertNotSame(
            getmypid(),
            $report['pid'],
            'the work did not run in another process, so nothing here is a statement about forks'
        );

        return $report;
    }

    /**
     * The pump, running the way the module runs it: a coroutine over a registry's bus.
     *
     * @param \Closure(): void $whileRunning What the other process does while this one pumps.
     * @param int $expected How many messages it must be willing to deliver before the test gives up.
     * @return array{0: list<string>, 1: list<string>} The events delivered, and everything logged.
     */
    private function pumping(RedisStreamPublicationBus $bus, \Closure $whileRunning, int $expected): array
    {
        /** @var list<string> $delivered */
        $delivered = [];

        /** @var list<string> $warnings */
        $warnings = [];

        $logger = new class ($warnings) extends \Psr\Log\AbstractLogger {
            /** @param list<string> $warnings */
            public function __construct(private array &$warnings)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->warnings[] = $level . ': ' . $message;
            }
        };

        $registry = new TopicRegistry(
            sender: static function (Connection $c, string $payload) use (&$delivered): bool {
                $delivered[] = (string) json_decode($payload, true)['ev'];

                return true;
            },
            bus: $bus
        );

        // A subscriber, or the registry has nothing to deliver to and the test measures an empty topic
        // instead of the bus.
        $registry->subscribe(new Connection('parent-1'), 'orders');

        $pump = new PublicationPump(
            registry: $registry,
            logger: $logger,
            // Short, because the test is waiting on it and there is nothing to be polite to.
            yieldMs: 5
        );

        \Swoole\Coroutine\run(static function () use ($pump, $whileRunning, $expected): void {
            // run() rather than Coroutine::create() from inside a coroutine: this is the top level, and
            // starting a scheduler within one is a fatal.
            \Swoole\Coroutine::create(static function () use ($pump): void {
                $pump->run();
            });

            \Swoole\Coroutine::sleep(0.05);

            $whileRunning();

            $deadline = microtime(true) + 5.0;

            // On the messages themselves rather than on a fixed sleep: the assertion is about which
            // ones arrive, so the test waits for proof the read path works and then asks about the rest.
            while ($pump->delivered() < $expected && microtime(true) < $deadline) {
                \Swoole\Coroutine::sleep(0.01);
            }

            $pump->stop();
        });

        return [$delivered, $warnings];
    }

    // ------------------------------------------------------------------ taking over

    public function testTheProcessThatBuiltTheBusKeepsItsIdentityAndItsConnection(): void
    {
        $bus = $this->moduleBus();

        $origin = $bus->origin();
        $bus->retain('orders', 5);
        $bus->publish('orders', 'first', null);
        $bus->cursor();
        $bus->oldest('orders');
        iterator_to_array($bus->missed('orders', 0));
        $bus->settle();
        $bus->next();

        $this->assertSame($origin, $bus->origin(), 'The identity of a bus does not drift while it is being used.');
        $this->assertSame(0, $this->connections, 'A bus already in the right hands does not reconnect.');
        $this->assertSame(0, $this->reports, 'and does not claim to have lost anybody.');
    }

    public function testABusInheritedByAnotherProcessIsRetakenByTheProcessThatInheritedIt(): void
    {
        $bus = $this->moduleBus();

        $child = $this->inForkedChild(
            $bus,
            static fn(RedisStreamPublicationBus $inherited): array => ['origin' => $inherited->origin()]
        );

        $this->assertNotSame(
            $bus->origin(),
            $child['origin'],
            'Two processes that each believe every message on the stream is their own deliver nothing '
            . 'to each other, and nothing anywhere says why.'
        );

        // The pid, because "some other string" is also what a random suffix would produce — and an
        // identity unrelated to the process carrying it cannot be recognised as its own by the one
        // thing that needs to: the pump, comparing against the process it is running in.
        $this->assertStringEndsWith('@' . $child['pid'], (string) $child['origin']);
    }

    public function testTheInheritingProcessGetsAConnectionOfItsOwnRatherThanTheOneItInherited(): void
    {
        $bus = $this->moduleBus();

        $child = $this->inForkedChild(
            $bus,
            function (RedisStreamPublicationBus $inherited): array {
                $published = $inherited->publish('orders', 'child.said', ['from' => 'child']);

                return ['connections' => $this->connections, 'id' => $published['id']];
            }
        );

        $this->assertSame(
            1,
            $child['connections'],
            'The child asked for a connection of its own. A socket is one file descriptor and one stream '
            . 'of replies: two processes sharing it do not each get the answer to their own question.'
        );

        // The other half of the same property, and the half that bites in production: the parent keeps
        // working. Two processes interleaving commands on one connection corrupt each other's replies,
        // and the process that looks healthy is whichever of them got lucky.
        $this->assertSame(
            (int) $child['id'] + 1,
            $bus->publish('orders', 'parent.said', ['from' => 'parent'])['id'],
            "The parent's own connection survived the child using the bus."
        );
    }

    public function testTheInheritingProcessReadsFromWhereItArrivedRatherThanWhereTheParentGotTo(): void
    {
        $bus = $this->moduleBus(offset: '$');

        // Published while "this process" was still the parent. Nothing held a connection then, so
        // nobody had to hear it, and settle() is the thing that says so.
        $bus->publish('orders', 'before.the.fork', null);

        $child = $this->inForkedChild(
            $bus,
            static fn(RedisStreamPublicationBus $inherited): array => [
                'settled' => $inherited->settle(),
                'leftToRead' => $inherited->next() !== null,
            ]
        );

        $this->assertSame(1, $child['settled'], 'The child skipped what was published before it existed.');
        $this->assertFalse($child['leftToRead'], 'and so it has nothing left to read.');
    }

    public function testRetentionConfiguredBeforeTheForkStillAppliesToTheProcessThatInheritedTheBus(): void
    {
        $bus = $this->moduleBus();
        $bus->retain('orders', 2);

        $child = $this->inForkedChild(
            $bus,
            static function (RedisStreamPublicationBus $inherited): array {
                foreach (range(1, 3) as $n) {
                    $inherited->publish('orders', 'order.paid', ['n' => $n]);
                }

                return [
                    'oldest' => $inherited->oldest('orders'),
                    'retained' => count(iterator_to_array($inherited->missed('orders', 0))),
                ];
            }
        );

        // Taking the bus over must not become a second chance to lose the settings it was built with:
        // a window that silently reverted to the default would let a reconnecting client replay far
        // more history than the application said it kept. Cursors here are 1, 2, 3.
        $this->assertSame(2, $child['retained']);
        $this->assertSame(2, $child['oldest'], 'and the window starts where the configuration says it does.');
    }

    // ------------------------------------------------------------------ the delivery it exists for

    public function testAMessagePublishedByAForkedChildReachesASubscriberHeldByTheParent(): void
    {
        $inherited = $this->moduleBus();

        // Published first, so it is ahead of the second worker in the stream by the time the pump reads.
        $child = $this->inForkedChild(
            $inherited,
            static function (RedisStreamPublicationBus $inherited): array {
                $published = $inherited->publish('orders', 'child.said', ['from' => 'child']);

                return ['id' => $published['id'], 'origin' => $inherited->origin()];
            }
        );

        // The second worker: another identity, another read position. A bus object in the parent process
        // stands in for it for one reason — it publishes with an identity the pump will accept.
        $otherWorker = $this->moduleBus();

        [$delivered, $warnings] = $this->pumping(
            $inherited,
            static function () use ($otherWorker): void {
                $otherWorker->publish('orders', 'other.said', ['from' => 'other']);
            },
            2
        );

        $this->assertSame(
            ['child.said', 'other.said'],
            $delivered,
            sprintf(
                'This is the whole point of a shared bus: what one worker published reaches a connection '
                . 'held by another. The child published cursor %s as %s and it did not arrive.',
                (string) $child['id'],
                (string) $child['origin']
            )
        );

        $this->assertSame([], $warnings, 'Nothing was reported, because from the pump every delivery it skipped was correct.');
    }

    public function testWhatTheParentPublishesAfterTheForkReachesTheProcessThatInheritedTheBus(): void
    {
        $inherited = $this->moduleBus();

        // The same thing the test above proves in one direction, in the other: a reader that inherited
        // the bus gets what the parent publishes after it arrived.
        //
        // It runs the real pump rather than a read loop, because that is the only thing that settles
        // the read position. A bus left at `$` re-resolves it on every read — "anything new?" — and
        // a message published between two of its polls belongs to the past by the time it asks, which
        // is what {@see \Phue\Core\Websocket\SettablePublicationBus::settle()} exists to prevent.
        $child = new \Swoole\Process(static function (\Swoole\Process $child) use ($inherited): void {
            $report = ['pid' => getmypid(), 'origin' => null, 'delivered' => [], 'error' => null];

            try {
                $delivered = [];

                $registry = new TopicRegistry(
                    sender: static function (Connection $c, string $payload) use (&$delivered): bool {
                        $delivered[] = (string) json_decode($payload, true)['ev'];

                        return true;
                    },
                    bus: $inherited
                );

                $registry->subscribe(new Connection('child-1'), 'orders');

                $pump = new PublicationPump(
                    registry: $registry,
                    logger: new \Psr\Log\NullLogger(),
                    yieldMs: 5
                );

                \Swoole\Coroutine\run(static function () use ($pump, $child, &$delivered): void {
                    \Swoole\Coroutine::create(static function () use ($pump): void {
                        $pump->run();
                    });

                    // Long enough for the pump to have settled its read position, and the reason the
                    // parent's publish cannot land before the child is ready.
                    \Swoole\Coroutine::sleep(0.05);

                    $child->write("ready\n");

                    $deadline = microtime(true) + 3.0;

                    while ($delivered === [] && microtime(true) < $deadline) {
                        \Swoole\Coroutine::sleep(0.01);
                    }

                    $pump->stop();
                });

                $report['delivered'] = $delivered;
                $report['origin'] = $inherited->origin();
            } catch (\Throwable $e) {
                $report['error'] = $e::class . ': ' . $e->getMessage();
            }

            $child->write((string) json_encode($report));
        });

        $child->setTimeout(10.0);
        $child->start();

        $this->assertSame("ready\n", $child->read(), 'the inheriting process never started reading');

        $inherited->publish('orders', 'parent.said', ['from' => 'parent']);

        $raw = $child->read();
        $child->wait();

        $this->assertIsString($raw, 'the inherited process never reported back');
        $this->assertNotSame(
            getmypid(),
            (json_decode($raw, true)['pid'] ?? null),
            'the reader did not run in another process, so nothing here is a statement about forks'
        );

        $report = (array) json_decode($raw, true);

        $this->assertNull($report['error'] ?? null, 'the reader failed: ' . (string) ($report['error'] ?? ''));
        $this->assertSame(
            ['parent.said'],
            $report['delivered'] ?? null,
            'A worker that inherited the bus delivered nothing its parent published afterwards.'
        );
        $this->assertNotSame($inherited->origin(), $report['origin'] ?? null);
    }

    // ------------------------------------------------------------------ when there is nowhere to go

    public function testAProcessThatInheritedABusAndCannotReconnectSaysSoAndDeliversOnItsOwn(): void
    {
        $bus = $this->moduleBus(withFactory: false);

        $child = $this->inForkedChild(
            $bus,
            function (RedisStreamPublicationBus $inherited): array {
                $published = $inherited->publish('orders', 'child.said', ['from' => 'child']);

                return [
                    'reports' => $this->reports,
                    'event' => (string) json_decode($published['payload'], true)['ev'],
                    'id' => $published['id'],
                ];
            }
        );

        $this->assertSame(1, $child['reports'], 'Losing the other workers is something an operator has to be told about.');
        $this->assertSame('child.said', $child['event'], 'A worker with nowhere to publish to still answers the request that published.');
        $this->assertIsInt($child['id'], 'and still hands back a cursor the caller can use.');
    }

    public function testTheParentIsUnaffectedByAChildThatCouldNotReconnect(): void
    {
        $bus = $this->moduleBus(withFactory: false);

        $this->inForkedChild(
            $bus,
            static fn(RedisStreamPublicationBus $inherited): array => [
                'id' => $inherited->publish('orders', 'child.said', null)['id'],
            ]
        );

        $this->assertSame(0, $this->reports, "The parent's bus was never claimed, so it has nothing to report.");
        $this->assertSame(1, $bus->publish('orders', 'parent.said', null)['id'], 'and it publishes from where it always did.');
    }
}