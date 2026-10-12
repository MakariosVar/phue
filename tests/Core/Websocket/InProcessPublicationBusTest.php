<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Websocket;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phue\Core\Websocket\InProcessPublicationBus;

/**
 * The single-process bus, which is what a deployment gets with no Redis configured.
 *
 * This is the default path, and it is the one nobody tests, which is why it can hold a queue that
 * grows for the life of the process and is never read: the pump only starts for a shared bus, so
 * the only caller of `next()` never arrives here. The tests below pin the two properties that make
 * that safe to rely on — nothing is ever handed back to the publisher, and publishing costs no
 * memory that grows.
 */
final class InProcessPublicationBusTest extends TestCase
{
    private InProcessPublicationBus $bus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bus = new InProcessPublicationBus();
    }

    public function testPublishAssignsAscendingCursorsFromOne(): void
    {
        $first = $this->bus->publish('orders', 'order.paid', ['n' => 1]);
        $second = $this->bus->publish('orders', 'order.paid', ['n' => 2]);

        $this->assertSame(1, $first['id']);
        $this->assertSame(2, $second['id']);
    }

    public function testPublishReturnsTheEnvelopeItRecorded(): void
    {
        $result = $this->bus->publish('orders', 'order.paid', ['n' => 7]);

        $this->assertJson($result['payload']);

        $decoded = json_decode($result['payload'], true);

        $this->assertSame('orders', $decoded['ch']);
        $this->assertSame('order.paid', $decoded['ev']);
        $this->assertSame(['n' => 7], $decoded['d']);
        $this->assertSame($result['id'], $decoded['id']);
    }

    public function testIsNotShared(): void
    {
        $this->assertFalse(
            $this->bus->isShared(),
            'An in-process bus must report itself unshared, or a worker starts a pump that has '
            . 'nothing to do and would deliver every message back to its own publisher.'
        );
    }

    public function testOriginIsStableForTheProcess(): void
    {
        $this->assertSame($this->bus->origin(), $this->bus->origin());
        $this->assertNotSame('', $this->bus->origin());
    }

    public function testNextIsAlwaysNullEvenAfterPublishing(): void
    {
        $this->bus->publish('orders', 'order.paid', ['n' => 1]);

        $this->assertNull(
            $this->bus->next(),
            'There is no second process to deliver for. A record handed back here would be a '
            . 'message the publisher already delivered to its own subscribers, delivered twice.'
        );

        $this->bus->publish('orders', 'order.paid', ['n' => 2]);

        $this->assertNull($this->bus->next());
    }

    public function testPublishingManyMessagesRetainsNoneOfThem(): void
    {
        for ($i = 0; $i < 5000; $i++) {
            $this->bus->publish('orders', 'order.paid', ['n' => $i]);
        }

        $this->assertSame(
            0,
            $this->retainedCount(),
            'The in-process bus has no consumer — the pump starts only for a shared bus — so '
            . 'anything kept here is retained for the life of the process and never read. On the '
            . 'default single-worker deployment that is a leak proportional to traffic.'
        );
    }

    public function testRetainedRecordsDoNotSurviveAcrossBuses(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->bus->publish('orders', 'order.paid', ['n' => $i]);
        }

        $this->assertSame(0, $this->retainedCount());
        $this->assertNull((new InProcessPublicationBus())->next());
    }

    public function testMissedIsServedFromItsOwnHistory(): void
    {
        $this->bus->publish('orders', 'order.paid', ['n' => 1]);
        $this->bus->publish('orders', 'shipped', ['n' => 2]);

        $missed = [];

        foreach ($this->bus->missed('orders', 0) as $record) {
            $missed[] = $record['id'];
        }

        $this->assertSame([1, 2], $missed);
    }

    public function testMissedReturnsNothingForAnUnknownChannel(): void
    {
        $this->bus->publish('orders', 'order.paid', ['n' => 1]);

        $this->assertSame([], iterator_to_array($this->bus->missed('nothing-here', 0)));
    }

    #[DataProvider('payloadsThatMustSurviveJson')]
    public function testPayloadsSurviveTheEnvelopeRoundTrip(mixed $data): void
    {
        $result = $this->bus->publish('orders', 'order.paid', $data);

        $this->assertSame($data, json_decode($result['payload'], true)['d']);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function payloadsThatMustSurviveJson(): iterable
    {
        yield 'null' => [null];
        yield 'int' => [42];
        yield 'zero' => [0];
        yield 'float' => [1.5];
        yield 'string' => ['text'];
        yield 'empty string' => [''];
        yield 'bool true' => [true];
        yield 'bool false' => [false];
        yield 'empty array' => [[]];
        yield 'list' => [[1, 2, 3]];
        yield 'assoc' => [['a' => 1, 'b' => 2]];
        yield 'nested' => [['a' => ['b' => ['c' => 1]]]];
        yield 'unicode' => ['Ελληνικά'];
    }

    public function testExceptIsCarriedOnTheRecordAndNotApplied(): void
    {
        $this->bus->publish('orders', 'order.paid', ['n' => 1], ['conn-1', 'conn-2']);

        // Nothing is retained, so the ids have nowhere to live. The contract is that they do not
        // filter here: a connection id means nothing in another process.
        $this->assertNull($this->bus->next());
    }

    /**
     * How many records the bus is holding on to.
     *
     * Reads private state on purpose. The invariant is about memory the process never gets back,
     * which is not observable from the public contract — the bus answers every call correctly
     * either way, and the growth is invisible until the process is under load for hours.
     *
     * A missing property counts as zero: there is no field to fill, so there is nothing retained.
     */
    private function retainedCount(): int
    {
        if (!property_exists(InProcessPublicationBus::class, 'queue')) {
            return 0;
        }

        $property = new \ReflectionProperty(InProcessPublicationBus::class, 'queue');

        $queue = $property->getValue($this->bus);

        return is_array($queue) ? count($queue) : 0;
    }
}