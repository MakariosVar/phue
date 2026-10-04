<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Websocket;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Websocket\Connection;
use Phue\Core\Websocket\TopicRegistry;

/**
 * TopicRegistry is where a WebSocket feature is either sound or a memory leak.
 *
 * The two properties that matter are that one publish costs one loop over the subscribers — the
 * cost is in the deliveries the protocol requires, not in the bookkeeping — and that a client
 * which was away is caught up rather than quietly handed an incomplete answer.
 *
 * @package Phue\Tests\Core\Websocket
 */
#[Group('unit')]
final class TopicRegistryTest extends TestCase
{
    /** @var list<array{0: string, 1: string}> */
    private array $sent = [];

    /** @var array<string, true> */
    private array $failing = [];

    private function registry(array $retention = []): TopicRegistry
    {
        $this->sent = [];
        $this->failing = [];

        return new TopicRegistry(
            function (Connection $connection, string $payload): bool {
                if (isset($this->failing[$connection->id()])) {
                    return false;
                }

                $this->sent[] = [$connection->id(), $payload];

                return true;
            },
            $retention
        );
    }

    private function connection(string $id, int $queueLimit = 256): Connection
    {
        return new Connection($id, 'user:1', $queueLimit);
    }

    private function decode(string $payload): array
    {
        return (array) json_decode($payload, true);
    }

    // ------------------------------------------------------------------- publishing

    public function testAPublishReachesEverySubscriberOfThatTopicOnly(): void
    {
        $registry = $this->registry();
        $alpha = $this->connection('a');
        $beta = $this->connection('b');
        $elsewhere = $this->connection('c');

        $registry->subscribe($alpha, 'orders');
        $registry->subscribe($beta, 'orders');
        $registry->subscribe($elsewhere, 'payments');

        $registry->publish('orders', 'paid', ['id' => 7]);

        $this->assertCount(2, $this->sent);
        $this->assertSame(['a', 'b'], array_column($this->sent, 0));
    }

    public function testTheCursorIsMonotonicAcrossTopics(): void
    {
        $registry = $this->registry();
        $client = $this->connection('a');

        $registry->subscribe($client, 'orders');
        $registry->subscribe($client, 'payments');

        $first = $registry->publish('orders', 'a', null);
        $second = $registry->publish('payments', 'b', null);
        $third = $registry->publish('orders', 'c', null);

        $this->assertSame($first + 1, $second, 'cursors advance by one regardless of topic');
        $this->assertSame($second + 1, $third);
        $this->assertSame($third, $registry->cursor());
    }

    public function testAMessageCarriesItsChannelEventAndPayload(): void
    {
        $registry = $this->registry();
        $client = $this->connection('a');
        $registry->subscribe($client, 'user:1:orders');

        $registry->publish('user:1:orders', 'order.paid', ['id' => 7]);

        $message = $this->decode($this->sent[0][1]);

        $this->assertSame(1, $message['v'], 'the wire version travels with every message');
        $this->assertSame('user:1:orders', $message['ch']);
        $this->assertSame('order.paid', $message['ev']);
        $this->assertSame(['id' => 7], $message['d']);
        $this->assertIsInt($message['id']);
        $this->assertIsFloat($message['ts']);
    }

    public function testTheSenderCanBeExcluded(): void
    {
        $registry = $this->registry();
        $sender = $this->connection('a');
        $other = $this->connection('b');

        $registry->subscribe($sender, 'orders');
        $registry->subscribe($other, 'orders');

        // "Everyone but me" is the single most common broadcast shape in a collaborative UI.
        $registry->publish('orders', 'typing', null, ['a']);

        $this->assertSame(['b'], array_column($this->sent, 0));
    }

    // ------------------------------------------------------------------ subscribing

    public function testSubscribingToTheSameTopicTwiceChangesNothing(): void
    {
        $registry = $this->registry();
        $client = $this->connection('a');

        $registry->subscribe($client, 'orders');
        $registry->subscribe($client, 'orders');
        $registry->publish('orders', 'x', null);

        $this->assertSame(1, $registry->subscriberCount('orders'));
        $this->assertCount(1, $this->sent);
    }

    public function testUnsubscribingStopsDelivery(): void
    {
        $registry = $this->registry();
        $client = $this->connection('a');

        $registry->subscribe($client, 'orders');
        $registry->unsubscribe($client, 'orders');
        $registry->publish('orders', 'x', null);

        $this->assertCount(0, $this->sent);
    }

    public function testAClosedConnectionIsDroppedFromEveryTopic(): void
    {
        $registry = $this->registry();
        $client = $this->connection('a');

        $registry->subscribe($client, 'orders');
        $registry->subscribe($client, 'payments');
        $registry->publish('orders', 'x', null);
        $registry->detach($client);

        // Leaving subscriptions behind is how a registry grows forever: every publish keeps
        // trying to write to a socket that no longer exists.
        $this->assertSame([], $registry->channels());
        $this->assertSame([], $client->channels());

        $this->sent = [];
        $registry->publish('payments', 'y', null);

        $this->assertCount(0, $this->sent);
    }

    public function testTheLastSubscriberLeavingRemovesTheTopic(): void
    {
        $registry = $this->registry();
        $client = $this->connection('a');

        $registry->subscribe($client, 'orders');
        $registry->unsubscribe($client, 'orders');

        $this->assertSame(0, $registry->subscriberCount('orders'));
        $this->assertSame([], $registry->channels());
    }

    // -------------------------------------------------------------------- resuming

    public function testNothingIsReplayedToAFreshSubscriber(): void
    {
        $registry = $this->registry(['orders' => 10]);

        $client = $this->connection('a');
        $registry->subscribe($client, 'orders');

        // "Since when?" is null, so there is nothing to catch up. A subscriber that asked for
        // nothing must not be handed the history of a channel it has only just joined.
        $this->assertCount(0, $this->sent);
    }

    public function testAReconnectingClientIsHandedWhatItMissed(): void
    {
        $registry = $this->registry(['orders' => 10]);
        $client = $this->connection('a');

        $registry->subscribe($client, 'orders');
        $lastSeen = $registry->publish('orders', 'first', null);

        $this->sent = [];

        $registry->subscribe($client, 'orders', $lastSeen);
        $registry->publish('orders', 'second', null);
        $registry->publish('orders', 'third', null);

        $events = array_map(fn(array $m): string => $m['ev'], array_map($this->decode(...), array_column($this->sent, 1)));

        $this->assertSame(['second', 'third'], $events, 'a backgrounded client must not be handed the next event as if nothing happened');
    }

    public function testNothingBeforeTheCursorIsReplayed(): void
    {
        $registry = $this->registry(['orders' => 10]);
        $client = $this->connection('a');

        $registry->subscribe($client, 'orders');
        $registry->publish('orders', 'first', null);
        $second = $registry->publish('orders', 'second', null);

        $this->sent = [];

        $registry->subscribe($client, 'orders', $second);
        $registry->publish('orders', 'third', null);

        $this->assertCount(1, $this->sent);
        $this->assertSame('third', $this->decode($this->sent[0][1])['ev']);
    }

    public function testATopicWithoutRetentionReplaysNothing(): void
    {
        $registry = $this->registry();
        $client = $this->connection('a');

        $registry->subscribe($client, 'orders');
        $lastSeen = $registry->publish('orders', 'first', null);

        $this->sent = [];
        $registry->subscribe($client, 'orders', $lastSeen);

        $this->assertCount(0, $this->sent);
    }

    public function testRetentionIsBounded(): void
    {
        $registry = $this->registry(['orders' => 3]);
        $client = $this->connection('a');
        $registry->subscribe($client, 'orders');

        for ($i = 1; $i <= 10; $i++) {
            $registry->publish('orders', 'event' . $i, null);
        }

        $this->sent = [];
        $registry->subscribe($client, 'orders', 7);

        // Only the last three survived the window, so exactly those three are replayed.
        $this->assertCount(3, $this->sent);

        $events = array_map(fn(array $m): string => $m['ev'], array_map($this->decode(...), array_column($this->sent, 1)));
        $this->assertSame(['event8', 'event9', 'event10'], $events);
    }

    public function testAClientAwayLongerThanRetentionIsToldItHasAGap(): void
    {
        $registry = $this->registry(['orders' => 2]);
        $client = $this->connection('a');
        $registry->subscribe($client, 'orders');

        for ($i = 1; $i <= 5; $i++) {
            $registry->publish('orders', 'event' . $i, null);
        }

        $this->sent = [];
        $registry->subscribe($client, 'orders', 1);

        $message = $this->decode($this->sent[0][1]);

        // Silently sending the part that survives would leave the client believing it is up to
        // date when it is not. A gap it can act on beats an answer that is quietly incomplete.
        $this->assertSame('phue.gap', $message['ev']);
        $this->assertSame(1, $message['d']['since']);
    }

    // ------------------------------------------------------------ backpressure

    public function testAConnectionThatCannotKeepUpDropsRatherThanBuffers(): void
    {
        $registry = $this->registry();
        $slow = $this->connection('slow', queueLimit: 2);
        $this->failing['slow'] = true;

        $registry->subscribe($slow, 'orders');

        for ($i = 1; $i <= 5; $i++) {
            $registry->publish('orders', 'event' . $i, null);
        }

        // The queue is never drained because every send fails, so it fills and the rest are
        // discarded. Unbounded, this is a slow client turning into an out-of-memory kill of the
        // worker that serves every other connection too.
        $this->assertSame(2, $slow->queued());
        $this->assertSame(3, $slow->dropped());
    }

    public function testAFailedSendDoesNotAdvanceTheCursor(): void
    {
        $registry = $this->registry();
        $client = $this->connection('a');
        $this->failing['a'] = true;

        $registry->subscribe($client, 'orders');
        $registry->publish('orders', 'missed', null);

        $this->assertSame(0, $client->lastSeen(), 'the client did not receive it, so it must not be recorded as seen');
    }

    public function testASuccessfulDeliveryAdvancesTheClientsCursor(): void
    {
        $registry = $this->registry();
        $client = $this->connection('a');

        $registry->subscribe($client, 'orders');
        $id = $registry->publish('orders', 'seen', null);

        $this->assertSame($id, $client->lastSeen());
        $this->assertSame(0, $client->queued());
    }

    public function testAConnectionClosedMidFlightIsDetached(): void
    {
        $registry = $this->registry();
        $client = $this->connection('a');

        $registry->subscribe($client, 'orders');
        $client->close();

        $registry->publish('orders', 'x', null);

        $this->assertCount(0, $this->sent);
        $this->assertSame([], $registry->channels());
    }
}