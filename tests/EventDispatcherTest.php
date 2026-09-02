<?php

namespace Phue\Tests\Core;

use PHPUnit\Framework\TestCase;
use Phue\Core\EventDispatcher;
use Phue\Core\Interfaces\StoppableEventInterface;

// --- Dummy Classes & Interfaces για Testing ---

interface SampleInterface {}

class BaseEvent {}

class UserCreatedEvent extends BaseEvent implements
    SampleInterface,
    StoppableEventInterface
{
    private bool $stopped = false;

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}

// --- Tests ---

class EventDispatcherTest extends TestCase
{
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
    }

    public function test_dispatches_event_to_exact_class_listener(): void
    {
        $called = false;

        $this->dispatcher->listen(UserCreatedEvent::class, function () use (
            &$called,
        ) {
            $called = true;
        });

        $event = new UserCreatedEvent();
        $returnedEvent = $this->dispatcher->dispatch($event);

        $this->assertTrue($called);
        $this->assertSame($event, $returnedEvent);
    }

    public function test_dispatches_event_to_parent_class_listener(): void
    {
        $called = false;

        $this->dispatcher->listen(BaseEvent::class, function () use (&$called) {
            $called = true;
        });

        $this->dispatcher->dispatch(new UserCreatedEvent());

        $this->assertTrue($called);
    }

    public function test_dispatches_event_to_interface_listener(): void
    {
        $called = false;

        $this->dispatcher->listen(SampleInterface::class, function () use (
            &$called,
        ) {
            $called = true;
        });

        $this->dispatcher->dispatch(new UserCreatedEvent());

        $this->assertTrue($called);
    }

    public function test_stops_propagation_when_event_is_stopped(): void
    {
        $executionOrder = [];

        $this->dispatcher->listen(UserCreatedEvent::class, function (
            UserCreatedEvent $event,
        ) use (&$executionOrder) {
            $executionOrder[] = "first";
            $event->stop();
        });

        $this->dispatcher->listen(UserCreatedEvent::class, function () use (
            &$executionOrder,
        ) {
            $executionOrder[] = "second";
        });

        $this->dispatcher->dispatch(new UserCreatedEvent());

        $this->assertEquals(["first"], $executionOrder);
    }

    public function test_returns_same_event_instance_when_no_listeners_exist(): void
    {
        $event = new UserCreatedEvent();
        $returnedEvent = $this->dispatcher->dispatch($event);

        $this->assertSame($event, $returnedEvent);
    }

    public function test_executes_listeners_in_priority_order(): void
    {
        $executionOrder = [];

        $this->dispatcher->listen(
            UserCreatedEvent::class,
            function () use (&$executionOrder) {
                $executionOrder[] = "low priority";
            },
            priority: 0,
        );

        $this->dispatcher->listen(
            UserCreatedEvent::class,
            function () use (&$executionOrder) {
                $executionOrder[] = "high priority";
            },
            priority: 100,
        );

        $this->dispatcher->dispatch(new UserCreatedEvent());

        $this->assertEquals(["high priority", "low priority"], $executionOrder);
    }
}
