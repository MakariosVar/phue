<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Websocket;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Websocket\Connection;
use Phue\Core\Websocket\Envelope;
use Phue\Core\Websocket\InProcessPublicationBus;
use Phue\Core\Websocket\RedisStreamPublicationBus;
use Phue\Core\Websocket\TopicRegistry;
use Phue\Tests\Support\RedisTestConnection;

/**
 * The multi-process behaviour, against the real thing.
 *
 * Not against a fake. Every property that matters here is a property of Redis rather than of this
 * code: that a consumer group would have distributed the messages we need duplicated, that `XREAD`
 * without one hands the same entry to every reader, that `XADD ... MAXLEN ~` actually trims, and
 * that a blocking read returns when a message arrives rather than when it next looks. A fake built
 * from assumptions about those would confirm the assumptions.
 *
 * Skips when there is no Redis, and touches only its own namespaced keys.
 *
 * @group integration
 */
#[Group('integration')]
final class RedisStreamPublicationBusTest extends TestCase
{
    private ?RedisTestConnection $harness = null;

    /** @var list<RedisStreamPublicationBus> */
    private array $buses = [];

    /** @var list<TopicRegistry> */
    private array $registries = [];

    /** @var list<array{0: string, 1: string}> Every payload a registry sent. */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->harness = RedisTestConnection::available();

        if ($this->harness === null) {
            $this->markTestSkipped('No Redis available; set PHUE_TEST_REDIS_HOST to run this suite.');
        }
    }

    protected function tearDown(): void
    {
        $this->harness?->cleanup();

        parent::tearDown();

        $this->harness = null;
        $this->buses = [];
        $this->registries = [];
        $this->sent = [];
    }

    /**
     * A second bus on the same namespace: what another worker looks like.
     */
    private function worker(string $origin, array $retention = [], string $offset = '0-0'): RedisStreamPublicationBus
    {
        // Reading from 0-0 makes this worker look like one that was *already running* when the
        // message was published, which is the case under test. A worker starting cold reads only
        // from now on, and holding no connections it has nothing to replay to.
        $bus = new RedisStreamPublicationBus(
            redis: $this->harness->redis,
            namespace: $this->harness->namespace,
            origin: $origin,
            initialOffset: $offset
        );

        foreach ($retention as $channel => $messages) {
            $bus->retain($channel, $messages);
        }

        return $this->buses[] = $bus;
    }

    /**
     * A registry whose deliveries land in the shared capture.
     */
    private function registry(RedisStreamPublicationBus $bus, array $retention = []): TopicRegistry
    {
        return $this->registries[] = new TopicRegistry(
            sender: function (Connection $connection, string $payload): bool {
                $this->sent[] = [$connection->id(), $payload];

                return true;
            },
            retention: $retention,
            bus: $bus
        );
    }

    // ------------------------------------------------------------------ fan-out

    public function testAMessagePublishedOnOneWorkerReachesAnother(): void
    {
        $publisher = $this->worker('worker-a');
        $subscriber = $this->worker('worker-b');

        $publisher->publish('orders', 'order.paid', ['id' => 9]);

        $message = $subscriber->next();

        $this->assertNotNull($message, 'the second worker must see what the first published');
        $this->assertSame('orders', $message['channel']);
        $this->assertSame('order.paid', $this->event($message['payload']));
        $this->assertSame('worker-a', $message['origin'], 'the publisher is recorded, so it can skip its own copy');
    }

    /**
     * The property a consumer group would have broken: four readers, four copies of every message.
     *
     * A group hands each message to one member, so with four workers in one group three quarters of
     * the browsers would have gone quiet — and nothing would say why.
     */
    public function testEveryWorkerSeesEveryMessage(): void
    {
        $workers = [];

        for ($i = 0; $i < 4; $i++) {
            $workers['w' . $i] = $this->worker('worker-' . $i);
        }

        $workers['w0']->publish('orders', 'order.paid', ['id' => 1]);

        foreach ($workers as $name => $worker) {
            $message = $worker->next();

            $this->assertNotNull($message, $name . ' saw nothing');
            $this->assertSame('order.paid', $this->event($message['payload']), $name);
        }
    }

    public function testAConsumerWouldNotHaveDeliveredTheSameWayTwice(): void
    {
        // Recorded as a fact about the design rather than asserted against Redis: the delivery path
        // for a publishing worker is its own synchronous loop plus the copy that comes back around.
        $bus = $this->worker('only');
        $registry = $this->registry($bus);

        $connection = new Connection('a1');
        $registry->subscribe($connection, 'orders');

        $registry->publish('orders', 'order.paid', ['id' => 1]);

        $this->assertCount(1, $this->sent, 'the publisher delivers to its own subscribers once');

        // And the copy that comes back is attributable, so a consumer can skip it.
        $message = $bus->next();
        $this->assertSame('only', $message['origin']);
    }

    // ------------------------------------------------------------------ cursors

    public function testTheCursorIsSharedByEveryWorker(): void
    {
        $a = $this->worker('worker-a');
        $b = $this->worker('worker-b');

        $a->publish('orders', 'one', null);
        $b->publish('orders', 'two', null);

        // Two processes counting independently is how a reconnecting client gets told it missed
        // messages it received: it reports a cursor from one worker and is answered from another.
        $this->assertSame(2, $a->cursor());
        $this->assertSame(2, $b->cursor(), 'both workers must report the same cursor');
    }

    public function testCursorsAreMonotonicAcrossWorkers(): void
    {
        $a = $this->worker('worker-a');
        $b = $this->worker('worker-b');

        $cursors = [
            $a->publish('orders', 'one', null)['id'],
            $b->publish('orders', 'two', null)['id'],
            $a->publish('orders', 'three', null)['id'],
        ];

        $this->assertSame([1, 2, 3], $cursors);
    }

    public function testTheCursorAppearsInsideTheEnvelope(): void
    {
        $bus = $this->worker('worker-a');

        $published = $bus->publish('orders', 'order.paid', ['id' => 9]);

        $decoded = json_decode($published['payload'], true);

        $this->assertSame($published['id'], $decoded['id'], 'a client reports this id back to resume');
        $this->assertSame(1, $decoded['v']);
        $this->assertSame('orders', $decoded['ch']);
        $this->assertSame('order.paid', $decoded['ev']);
        $this->assertSame(['id' => 9], $decoded['d']);
    }

    // ------------------------------------------------------------------ resume

    public function testAClientResumesFromWhereItLeftOff(): void
    {
        $bus = $this->worker('worker-a', ['orders' => 10]);

        foreach (range(1, 4) as $n) {
            $bus->publish('orders', 'order.paid', ['n' => $n]);
        }

        $missed = [];

        foreach ($bus->missed('orders', 2) as $message) {
            $missed[] = json_decode($message['payload'], true)['d']['n'];
        }

        $this->assertSame([3, 4], $missed, 'only what was missed, oldest first');
    }

    public function testResumeIsAnsweredTheSameWayFromAnyWorker(): void
    {
        $a = $this->worker('worker-a', ['orders' => 10]);
        $b = $this->worker('worker-b', ['orders' => 10]);

        foreach (range(1, 3) as $n) {
            $a->publish('orders', 'order.paid', ['n' => $n]);
        }

        $fromA = array_map(static fn(array $m): int => json_decode($m['payload'], true)['d']['n'], iterator_to_array($a->missed('orders', 1)));
        $fromB = array_map(static fn(array $m): int => json_decode($m['payload'], true)['d']['n'], iterator_to_array($b->missed('orders', 1)));

        $this->assertSame([2, 3], $fromA);
        $this->assertSame($fromA, $fromB, 'a reconnecting client may land on either worker');
    }

    // ------------------------------------------------------------------ retention

    public function testTheWindowIsBoundedSoTheStreamCannotGrowForever(): void
    {
        $bus = $this->worker('worker-a', ['orders' => 5]);

        for ($i = 1; $i <= 60; $i++) {
            $bus->publish('orders', 'order.paid', ['n' => $i]);
        }

        $retained = iterator_to_array($bus->missed('orders', 0));

        // Per-channel retention is applied on read, so the window is exactly what the channel
        // asked for, however long the stream itself is.
        $this->assertCount(5, $retained, 'the window must be exactly what the channel retains');
        $this->assertNotSame([], $retained, 'and must not trim everything either');
        $this->assertSame($retained[0]['id'], $bus->oldest('orders'));
    }

    /**
     * `retention => 0` has to mean "keep nothing".
     *
     * A zero trim length is Redis for "no limit", so the stream keeps the entry for fan-out and
     * the channel keeps nothing replayable. Rounding that up to one would tell every reconnecting
     * client it had fallen behind when it had not.
     */
    public function testOldestIsNullWhenNothingIsRetained(): void
    {
        $bus = $this->worker('worker-a', ['orders' => 0]);

        $bus->publish('orders', 'order.paid', null);

        $this->assertNull($bus->oldest('orders'), 'a resume must not be told it fell behind when there was nothing');
    }

    // ------------------------------------------------------------------ the real path

    /**
     * The end of it: two registries, one bus, one connection each — the shape two Swoole workers
     * have. A publish on one reaches the subscriber on the other.
     */
    public function testASubscriberOnAnotherWorkerReceivesTheMessage(): void
    {
        $busA = $this->worker('worker-a');
        $busB = $this->worker('worker-b');

        $registryA = $this->registry($busA);
        $registryB = $this->registry($busB);

        $remote = new Connection('b1');
        $registryB->subscribe($remote, 'orders');

        $registryA->publish('orders', 'order.paid', ['id' => 9]);

        // Nothing reached b1 yet: the publisher delivers only to its own process.
        $this->assertSame([], $this->sent);

        $message = $busB->next();
        $this->assertNotNull($message);

        // And what the bus hands back is what a worker writes to its own subscribers.
        $registryB->bus();
        $this->assertSame('order.paid', $this->event($message['payload']));
        $this->assertSame('orders', $message['channel']);
    }

    public function testCleanupRemovesOnlyThisHarnessKeys(): void
    {
        $bus = $this->worker('worker-a');
        $bus->publish('orders', 'order.paid', null);

        $this->assertTrue($this->harness->has('stream'));
        $this->assertTrue($this->harness->has('cursor'));

        $this->harness->cleanup();

        $this->assertFalse($this->harness->has('stream'), 'teardown must leave nothing behind');
        $this->assertFalse($this->harness->has('cursor'));

        // And it touched nothing outside its own namespace.
        $this->assertSame(0, (int) $this->harness->redis->exists('phue:ws:stream'));
    }

    private function event(string $payload): string
    {
        return (string) json_decode($payload, true)['ev'];
    }
}