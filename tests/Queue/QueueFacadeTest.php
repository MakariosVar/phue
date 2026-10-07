<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Container;
use Phue\Modules\Queue\Driver\SyncQueueDriver;
use Phue\Modules\Queue\Queue;
use Phue\Modules\Queue\QueueConfig;
use Phue\Modules\Queue\QueueManager;
use Phue\Tests\Queue\Stubs\PlainJob;

/**
 * The one line a developer writes, and the bookkeeping the queue keeps around it.
 *
 * @package Phue\Tests\Queue
 */
#[Group('unit')]
final class QueueFacadeTest extends TestCase
{
    private Container $container;

    private QueueManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new QueueManager(new SyncQueueDriver(), QueueConfig::fromArray([]));

        Container::setInstance(null);
        Queue::swap($this->manager);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Queue::swap(null);
        Container::setInstance(null);
    }

    public function testPushReachesTheManager(): void
    {
        PlainJob::$ran = [];

        $id = Queue::push(new PlainJob('facade', 1));

        $this->assertNotSame('', $id);
        $this->assertSame(['facade:1@1'], PlainJob::$ran);
    }

    public function testPushCanNameAQueue(): void
    {
        Queue::push(new PlainJob(), 'reports');

        $this->assertSame(1, Queue::stats('reports')['completed']);
    }

    public function testStatsComeFromTheManager(): void
    {
        Queue::push(new PlainJob());

        $this->assertSame(1, Queue::stats()['completed']);
    }

    public function testLaterGoesThroughTheDelayChecks(): void
    {
        $this->expectException(\Phue\Modules\Queue\Exception\QueuePayloadException::class);

        Queue::later(new PlainJob(), 30);
    }

    /**
     * A configuration mistake should say what to do about it. "Queue is not available" on its own
     * sends people looking through their application code instead of their config.
     */
    public function testAMissingModuleSaysWhereToRegisterIt(): void
    {
        Queue::swap(null);
        Container::setInstance(new Container());

        try {
            Queue::push(new PlainJob());
            $this->fail('Expected the facade to refuse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('The queue is not available', $e->getMessage());
            $this->assertStringContainsString('config/app.php', $e->getMessage());
            $this->assertStringContainsString('QueueModule::class', $e->getMessage());
        }
    }

    public function testTheFacadeResolvesFromTheContainerWhenNothingIsSwapped(): void
    {
        Queue::swap(null);

        $config = new Container();
        $expected = new QueueManager(new SyncQueueDriver(), QueueConfig::fromArray([]));
        $config->singleton(QueueManager::class, static fn(): QueueManager => $expected);
        Container::setInstance($config);

        $this->assertSame($expected, Queue::manager());
    }

    /**
     * A swap is for a test to install a driver of its own; it must not survive one, or a leaked
     * manager quietly serves the rest of the suite.
     */
    public function testASwapCanBeCleared(): void
    {
        $this->assertSame($this->manager, Queue::manager());

        Queue::swap(null);

        $this->expectException(\RuntimeException::class);
        Queue::manager();
    }
}
