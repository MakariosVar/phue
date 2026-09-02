<?php

namespace Phue\Core;

use Phue\Core\Interfaces\StoppableEventInterface;

class EventDispatcher
{
    /**
     * @var array<string, array<int, callable[]>>
     */
    protected array $listeners = [];

    /**
     * Register a listener with an optional priority (higher numbers run first).
     */
    public function listen(string $eventClass, callable $listener, int $priority = 0): self
    {
        $this->listeners[$eventClass][$priority][] = $listener;

        return $this;
    }

    public function dispatch(object $event): object
    {
        $listeners = $this->getListenersForEvent($event);

        foreach ($listeners as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }

    /**
     * Resolves and sorts listeners by priority (highest first).
     *
     * @return callable[]
     */
    protected function getListenersForEvent(object $event): array
    {
        $eventClass = get_class($event);

        $targets = array_merge(
            [$eventClass],
            class_parents($eventClass) ?: [],
            class_implements($eventClass) ?: []
        );

        $prioritizedListeners = [];

        foreach ($targets as $target) {
            if (!isset($this->listeners[$target])) {
                continue;
            }

            foreach ($this->listeners[$target] as $priority => $listeners) {
                foreach ($listeners as $listener) {
                    $prioritizedListeners[$priority][] = $listener;
                }
            }
        }

        if (empty($prioritizedListeners)) {
            return [];
        }

        // Sort by priority descending (e.g., 100 before 0)
        krsort($prioritizedListeners);

        // Flatten array to a single list of callables
        return array_merge(...$prioritizedListeners);
    }
}