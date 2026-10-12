<?php

declare(strict_types=1);

namespace Phue\Tests\Core;

use Phue\Core\Container;
use Phue\Core\ResettableInterface;
use Phue\Core\ScopeContext;
use PHPUnit\Framework\TestCase;

/**
 * Singleton spy that records how many times the container reset it.
 */
final class ResettableSingletonSpy implements ResettableInterface
{
    public int $resets = 0;

    public function reset(): void
    {
        $this->resets++;
    }
}

/**
 * Scoped spy, used to prove the scoped path was never broken in the first place.
 */
final class ResettableScopedSpy implements ResettableInterface
{
    public int $resets = 0;

    public function reset(): void
    {
        $this->resets++;
    }
}

/**
 * Verification gate: does ResettableInterface mean anything for a singleton?
 *
 * Background
 * ----------
 * ResettableInterface is documented as "contract for services that store
 * request state and need resetting in persistent runtimes". Container::flushScope()
 * honours it — but only for entries in `$scopedInstances`, i.e. only for services
 * registered with `scoped()`.
 *
 * A service registered with `singleton()` lands in `$instances`, and
 * `flushScope()` never looks there. So the contract silently does nothing for
 * singletons, no matter how loudly a class declares it.
 *
 * The concrete victim in this framework is `Phue\Core\Database\Connection`:
 * it implements ResettableInterface, its reset() rolls back a dangling
 * transaction, and it is registered with `singleton()`. The rollback therefore
 * happens only because `Application::handle()` and `QueueWorker` each remember
 * to call it by hand — a convention, not a guarantee. A WebSocket handler, a
 * Swoole timer tick, or any third-party worker following the documented pattern
 * gets no rollback.
 *
 * @package Phue\Tests\Core
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class ContainerResettableSingletonTest extends TestCase
{
    public function testSingletonImplementingResettableInterfaceIsResetOnScopeFlush(): void
    {
        $container = new Container();
        $container->singleton(ResettableSingletonSpy::class, static fn(): ResettableSingletonSpy => new ResettableSingletonSpy());

        $scope = $container->createScope();
        $container->setScope($scope);

        $spy = $container->get(ResettableSingletonSpy::class);

        $container->flushScope($scope);

        $this->assertSame(
            1,
            $spy->resets,
            'A singleton that implements ResettableInterface was not reset when its scope flushed. '
            . 'flushScope() only walks $scopedInstances, so the contract is inert for singletons.'
        );
    }

    public function testResettableSingletonSurvivesTheFlushAndStaysCached(): void
    {
        $container = new Container();
        $container->singleton(ResettableSingletonSpy::class, static fn(): ResettableSingletonSpy => new ResettableSingletonSpy());

        $first = $container->createScope();
        $container->setScope($first);
        $spy = $container->get(ResettableSingletonSpy::class);
        $container->flushScope($first);

        $second = $container->createScope();
        $container->setScope($second);
        $again = $container->get(ResettableSingletonSpy::class);

        $this->assertSame(
            $spy,
            $again,
            'Reset must clean state, not evict the instance. A singleton that is rebuilt on the '
            . 'next request is no longer a singleton.'
        );
    }

    public function testScopedResettableServiceIsStillResetExactlyOnce(): void
    {
        $container = new Container();
        $container->scoped(ResettableScopedSpy::class, static fn(): ResettableScopedSpy => new ResettableScopedSpy());

        $scope = $container->createScope();
        $container->setScope($scope);

        $spy = $container->get(ResettableScopedSpy::class);

        $container->flushScope($scope);

        $this->assertSame(
            1,
            $spy->resets,
            'The existing scoped reset path must keep working exactly once per flush.'
        );
    }

    public function testFlushOfOneScopeDoesNotResetSingletonsTwice(): void
    {
        $container = new Container();
        $container->singleton(ResettableSingletonSpy::class, static fn(): ResettableSingletonSpy => new ResettableSingletonSpy());

        $scope = $container->createScope();
        $container->setScope($scope);
        $spy = $container->get(ResettableSingletonSpy::class);

        $container->flushScope($scope);
        $container->flushScope(null);

        $this->assertSame(
            1,
            $spy->resets,
            'Flushing a scope and then the fallback bucket must not double-reset a singleton.'
        );
    }

    public function testScopeContextIdIsWhatPartitionsInstances(): void
    {
        $container = new Container();
        $container->scoped(ResettableScopedSpy::class, static fn(): ResettableScopedSpy => new ResettableScopedSpy());

        $a = new ScopeContext('a');
        $b = new ScopeContext('b');

        $container->setScope($a);
        $spyA = $container->get(ResettableScopedSpy::class);

        $container->setScope($b);
        $spyB = $container->get(ResettableScopedSpy::class);

        $this->assertNotSame($spyA, $spyB, 'Distinct scope ids must partition scoped instances.');
    }
}