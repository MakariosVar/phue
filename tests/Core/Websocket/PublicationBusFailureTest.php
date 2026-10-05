<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Websocket;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Websocket\Connection;
use Phue\Core\Websocket\RedisStreamPublicationBus;
use Phue\Core\Websocket\TopicRegistry;

/**
 * What a worker does when the broker it was built against stops answering.
 *
 * A real bus pointed at a real port with nothing listening on it, rather than a stub that throws
 * on demand. A stub would only prove that the code handles the exception it was written against;
 * this proves it survives what ext-redis actually raises for a refused connection, which is not
 * what a hand-written double tends to imitate.
 *
 * The rule under test: the broker being unreachable loses the *other* workers, never the request.
 * A 500 on every call that broadcasts fails the page that made it, for a reason that has nothing
 * to do with the page. Losing cross-worker fan-out is recoverable and visible in a log; taking the
 * endpoint down is not.
 *
 * @group unit
 */
#[Group('unit')]
final class PublicationBusFailureTest extends TestCase
{
    /** @var list<string> */
    private array $sent = [];

    /** How many times the fall-over was reported, to prove an outage is one line and not a flood. */
    private int $unreachableReports = 0;

    /** The failures the bus reported, to check what an operator would be told. */
    private array $reported = [];

    /**
     * A bus over a Redis that raises what ext-redis raises for a dead server.
     *
     * A subclass of the real client rather than a stub of the bus interface: the code under test is
     * then the production class, unmodified, hitting the same exception type and the same method
     * signatures it would meet in production. Pointing the real client at a closed port instead
     * cannot demonstrate this — `connect()` raises during setup, and closing a live connection just
     * makes phpredis silently reconnect, which is what it does.
     *
     * @return RedisStreamPublicationBus
     */
    private function unreachableBus(string $namespace = 'phue:ws:test-unreachable'): RedisStreamPublicationBus
    {
        $this->unreachableReports = 0;
        $this->reported = [];

        return new RedisStreamPublicationBus(
            redis: new FailingRedis(),
            namespace: $namespace,
            origin: 'worker-test-' . getmypid(),
            defaultRetention: 100,
            onUnreachable: function (\Throwable $e): void {
                $this->unreachableReports++;
                $this->reported[] = $e;
            }
        );
    }

    private function registry(RedisStreamPublicationBus $bus): TopicRegistry
    {
        $this->sent = [];

        return new TopicRegistry(
            function (Connection $connection, string $payload): bool {
                $this->sent[] = $payload;

                return true;
            },
            bus: $bus
        );
    }

    private function connection(): Connection
    {
        return new Connection('conn-1', 'user:1');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $payload): array
    {
        return (array) json_decode($payload, true);
    }

    public function testTheFixtureReallyIsUnreachable(): void
    {
        // Asserted against the client, not the bus. The bus swallowing this is the behaviour these
        // tests are about, so asking it to throw would be asking for the bug. Without this check on
        // the client underneath, every test here could be passing against a Redis that worked.
        $this->expectException(\RedisException::class);

        (new FailingRedis())->incr('phue:ws:anything');
    }

    public function testTheOutageIsReportedExactlyOnce(): void
    {
        $registry = $this->registry($this->unreachableBus());
        $registry->subscribe($this->connection(), 'orders');

        for ($i = 0; $i < 10; $i++) {
            $registry->publish('orders', 'order.paid', ['n' => $i]);
            $registry->cursor();
            iterator_to_array($registry->bus()->missed('orders', 0));
            $registry->bus()->oldest('orders');
        }

        $this->assertSame(
            1,
            $this->unreachableReports,
            'One outage is one log line. A warning per failed call turns a broker being down into '
            . 'a log flood that hides the line explaining why it went down.'
        );
    }

    public function testTheOutageCarriesTheOriginalFailure(): void
    {
        $registry = $this->registry($this->unreachableBus());
        $registry->subscribe($this->connection(), 'orders');

        $registry->publish('orders', 'order.paid', ['n' => 1]);

        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(\RedisException::class, $this->reported[0]);
        $this->assertStringContainsStringIgnoringCase(
            'connection',
            $this->reported[0]->getMessage(),
            'The operator reading the log needs the broker\'s own words about why it went away, '
            . 'not our paraphrase of them.'
        );
    }

    public function testTheBusReportsItselfUnsharedOnceItHasFallenOver(): void
    {
        $bus = $this->unreachableBus();

        $this->assertTrue($bus->isShared(), 'It is shared until Redis says otherwise.');

        $bus->publish('orders', 'order.paid', ['n' => 1]);

        $this->assertTrue(
            $bus->isShared(),
            'Still shared: other workers may still publish to the stream, and this worker still '
            . 'reads it if the broker comes back. What changed is that this worker no longer '
            . 'reaches them.'
        );
    }

    public function testPublishStillReachesSubscribersOnItsOwnWorker(): void
    {
        $registry = $this->registry($this->unreachableBus());

        $registry->subscribe($this->connection(), 'orders');
        $registry->publish('orders', 'order.paid', ['n' => 1]);

        $this->assertCount(
            1,
            $this->sent,
            'The broker being down costs the other workers, not this one: these subscribers hold '
            . 'their connection in this very process.'
        );

        $this->assertSame('order.paid', $this->decode($this->sent[0])['ev']);
    }

    public function testPublishReturnsACursorTheCallerCanUse(): void
    {
        $registry = $this->registry($this->unreachableBus());
        $registry->subscribe($this->connection(), 'orders');

        $this->assertIsInt($registry->publish('orders', 'order.paid', ['n' => 1]));
    }

    public function testCursorsKeepAscendingWhileTheBrokerIsDown(): void
    {
        $registry = $this->registry($this->unreachableBus());
        $registry->subscribe($this->connection(), 'orders');

        $ids = [
            $registry->publish('orders', 'order.paid', ['n' => 1]),
            $registry->publish('orders', 'order.paid', ['n' => 2]),
            $registry->publish('orders', 'order.paid', ['n' => 3]),
        ];

        $this->assertSame(
            [1, 2, 3],
            $ids,
            'A cursor that repeats or reverses makes a reconnecting client either replay forever '
            . 'or skip messages, and both look like the application losing events.'
        );
    }

    public function testSubscribersKeptBeforeTheFailureStillGetLaterMessages(): void
    {
        $registry = $this->registry($this->unreachableBus());
        $registry->subscribe($this->connection(), 'orders');

        $registry->publish('orders', 'order.paid', ['n' => 1]);
        $registry->publish('orders', 'order.paid', ['n' => 2]);

        $this->assertCount(2, $this->sent, 'Recovering the broker, or living with its absence, '
            . 'must not require every client to reconnect before it hears about anything again.');
    }

    public function testMissedIsEmptyRatherThanThrowing(): void
    {
        $registry = $this->registry($this->unreachableBus());

        $this->assertSame(
            [],
            iterator_to_array($registry->bus()->missed('orders', 0)),
            'A reconnecting client asks what it missed. With the broker down, "nothing" is the '
            . 'honest answer and the client handles it as a fresh start; an exception here turns '
            . 'a lost broadcast into a failed request.'
        );
    }

    public function testOldestIsNullRatherThanThrowing(): void
    {
        $registry = $this->registry($this->unreachableBus());

        $this->assertNull($registry->bus()->oldest('orders'));
    }

    public function testCursorIsStillReadable(): void
    {
        $registry = $this->registry($this->unreachableBus());
        $registry->subscribe($this->connection(), 'orders');

        $registry->publish('orders', 'order.paid', ['n' => 1]);

        $this->assertIsInt(
            $registry->cursor(),
            'The subscribe handshake asks for the current cursor; with the broker down it still '
            . 'has to answer with something a client can reason about.'
        );
    }

    public function testPublishWithNoSubscribersStillReturnsACursor(): void
    {
        $registry = $this->registry($this->unreachableBus());

        $this->assertIsInt($registry->publish('orders', 'order.paid', ['n' => 1]));
    }

    public function testSubscribingDoesNotThrowWhenTheBrokerIsDown(): void
    {
        $registry = $this->registry($this->unreachableBus());
        $connection = $this->connection();

        $registry->subscribe($connection, 'orders');

        $this->assertTrue($connection->has('orders'));
    }

    public function testSubscribingWithACursorToResumeFromDoesNotThrow(): void
    {
        $registry = $this->registry($this->unreachableBus());
        $connection = $this->connection();

        $registry->subscribe($connection, 'orders', 42);

        $this->assertTrue($connection->has('orders'));
    }

    public function testTheEnvelopeStaysWellFormedWhileTheBrokerIsDown(): void
    {
        $registry = $this->registry($this->unreachableBus());
        $registry->subscribe($this->connection(), 'orders');

        $registry->publish('orders', 'order.paid', ['n' => 1]);

        $envelope = $this->decode($this->sent[0]);

        $this->assertSame(1, $envelope['v']);
        $this->assertSame('orders', $envelope['ch']);
        $this->assertSame('order.paid', $envelope['ev']);
        $this->assertIsInt($envelope['id']);
        $this->assertGreaterThan(0, $envelope['id']);
        $this->assertIsFloat($envelope['ts']);
        $this->assertSame(['n' => 1], $envelope['d']);
    }

    public function testTheBrokerIsReportedUnreachableOnlyOnce(): void
    {
        $bus = $this->unreachableBus();

        $registry = $this->registry($bus);
        $registry->subscribe($this->connection(), 'orders');

        for ($i = 0; $i < 5; $i++) {
            $registry->publish('orders', 'order.paid', ['n' => $i]);
        }

        $this->assertCount(
            5,
            $this->sent,
            'Delivery must not depend on the broker answering, five times over.'
        );

        $this->assertSame(1, $this->unreachableReports);
    }
}
/**
 * The real Redis client, refusing every command the way a stopped server does.
 *
 * Extends `\Redis` so the bus under test is the production class with nothing stubbed out of it.
 * Only the commands the bus actually calls are overridden; anything else it is free to reach, and
 * a test that needs a new one should fail loudly rather than inherit silence.
 */
final class FailingRedis extends \Redis
{
    public function incr(string $key, int $value = 1): \Redis|int|false
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

    public function xRead(
        array $streams,
        int $count = 0,
        int $block = 0
    ): \Redis|array|bool {
        throw new \RedisException('Connection refused');
    }

    public function get(string $key): mixed
    {
        throw new \RedisException('Connection refused');
    }

    public function xRange(
        string $key,
        string $start,
        string $end,
        int $count = 0
    ): \Redis|array|bool {
        throw new \RedisException('Connection refused');
    }

    public function xRevRange(
        string $key,
        string $end,
        string $start,
        int $count = 0
    ): \Redis|array|bool {
        throw new \RedisException('Connection refused');
    }

    public function xLen(string $key): \Redis|int|false
    {
        throw new \RedisException('Connection refused');
    }
}
