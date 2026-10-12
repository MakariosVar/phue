<?php

declare(strict_types=1);

namespace Phue\Tests\Server;

use Phue\Core\Container;
use Phue\Core\Request;
use Phue\Core\ScopeContext;
use PHPUnit\Framework\TestCase;

/**
 * Scoped service used to observe which scope bucket a resolution landed in.
 *
 * Two concurrent coroutines that each resolve this must get two different
 * objects. If they get the same one, the container partitioned them into the
 * same bucket — which means per-request state is shared across requests.
 */
final class CoroutineScopeProbe
{
    public function __construct(public readonly string $tag) {}
}

/**
 * Verification gate: is the request scope actually coroutine-isolated?
 *
 * Background
 * ----------
 * Container::createScope() derives the scope id from the coroutine id
 * (`coroutine_<cid>`), so the *key* is per-coroutine. But the pointer that
 * says "which scope is currently active" is a single property,
 * `Container::$currentScope`, and `getEffectiveScopeKey()` reads that property.
 *
 * Key per coroutine, pointer shared. Under `enable_coroutine => true` — which
 * is what SwooleAdapter defaults to (`src/Modules/Server/SwooleAdapter.php`,
 * worker_num 4) — two requests interleave at every I/O point: a PDO query, a
 * `stream_socket_client` to the Bun SSR sidecar, a socket write. When B calls
 * setScope() during one of A's yields, A's next resolution is computed against
 * B's scope.
 *
 * This is not theoretical. The tests below drive real Swoole coroutines, so
 * the interleaving is the real scheduler's, not a hand-rolled simulation of it.
 *
 * The second pair covers the other half: the factory table. Application::handle()
 * calls `$container->scoped(Request::class, fn() => $request)` on the *shared*
 * container, and Container::bind() overwrites `$this->factories[Request::class]`.
 * Last writer wins, so a resumed coroutine resolves the other request object —
 * another user's headers, cookies, body and IP address.
 *
 * @package Phue\Tests\Server
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('security')]
final class CoroutineScopeIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension is required to exercise real coroutine interleaving.');
        }
    }

    /**
     * Runs two closures as sibling coroutines and waits for both.
     *
     * The trailing sleep in the parent is not decoration: without it the
     * parent coroutine returns immediately and the children may not have
     * reached their assertions yet.
     *
     * @param callable $a
     * @param callable $b
     * @return void
     */
    private function runConcurrently(callable $a, callable $b): void
    {
        \Swoole\Coroutine\run(static function () use ($a, $b): void {
            \Swoole\Coroutine::create($a);
            \Swoole\Coroutine::create($b);

            // Both children yield for 20ms; the parent outlives them.
            \Swoole\Coroutine::sleep(0.3);
        });
    }

    public function testConcurrentCoroutinesDoNotShareAScopedInstance(): void
    {
        $container = new Container();
        $container->scoped(CoroutineScopeProbe::class, static fn(): CoroutineScopeProbe => new CoroutineScopeProbe('probe'));

        $observed = [];

        $this->runConcurrently(
            static function () use ($container, &$observed): void {
                $container->setScope($container->createScope());
                \Swoole\Coroutine::sleep(0.02);
                $observed['alpha'] = $container->get(CoroutineScopeProbe::class);
            },
            static function () use ($container, &$observed): void {
                $container->setScope($container->createScope());
                \Swoole\Coroutine::sleep(0.02);
                $observed['beta'] = $container->get(CoroutineScopeProbe::class);
            }
        );

        $this->assertArrayHasKey('alpha', $observed, 'alpha coroutine never recorded an instance');
        $this->assertArrayHasKey('beta', $observed, 'beta coroutine never recorded an instance');

        $this->assertNotSame(
            $observed['alpha'],
            $observed['beta'],
            'Two concurrent coroutines resolved the same scoped instance. The container is '
            . 'partitioning by a shared active-scope pointer, so request-scoped state leaks '
            . 'between concurrent requests.'
        );
    }

    public function testEachCoroutineObservesItsOwnActiveScopeAfterAYield(): void
    {
        $container = new Container();

        $observed = [];

        $this->runConcurrently(
            static function () use ($container, &$observed): void {
                $ownScopeId = $container->createScope()->id;
                $container->setScope($container->createScope());
                \Swoole\Coroutine::sleep(0.02);
                $observed['alpha'] = ['own' => $ownScopeId, 'active' => $container->getScope()?->id];
            },
            static function () use ($container, &$observed): void {
                $ownScopeId = $container->createScope()->id;
                $container->setScope($container->createScope());
                \Swoole\Coroutine::sleep(0.02);
                $observed['beta'] = ['own' => $ownScopeId, 'active' => $container->getScope()?->id];
            }
        );

        foreach (['alpha', 'beta'] as $who) {
            $this->assertSame(
                $observed[$who]['own'],
                $observed[$who]['active'],
                sprintf(
                    'Coroutine %s set scope %s, yielded, and came back to %s. The active scope '
                    . 'pointer is process-shared instead of coroutine-local.',
                    $who,
                    $observed[$who]['own'],
                    $observed[$who]['active'] ?? 'null'
                )
            );
        }
    }

    public function testConcurrentScopesCanHoldIndependentRequestInstances(): void
    {
        $container = new Container();

        $scopeA = new ScopeContext('request-alpha');
        $scopeB = new ScopeContext('request-beta');

        $requestA = new Request('GET', '/account/alpha');
        $requestB = new Request('GET', '/account/beta');

        // Exactly the call Application::handle() makes (src/Core/Application.php:627).
        $container->setScope($scopeA);
        $container->scopedInstance(Request::class, $requestA, $scopeA);

        $container->setScope($scopeB);
        $container->scopedInstance(Request::class, $requestB, $scopeB);

        // Both coroutines come back after a yield.
        $container->setScope($scopeA);
        $this->assertSame(
            $requestA,
            $container->get(Request::class),
            'Alpha resolved Beta\'s Request.'
        );

        $container->setScope($scopeB);
        $this->assertSame(
            $requestB,
            $container->get(Request::class),
            'Beta resolved Alpha\'s Request.'
        );
    }

    public function testConcurrentCoroutinesEachResolveTheirOwnRequest(): void
    {
        $container = new Container();

        $requestA = new Request('GET', '/account/alpha', headers: ['X-User' => 'alpha']);
        $requestB = new Request('GET', '/account/beta', headers: ['X-User' => 'beta']);

        $observed = [];

        $this->runConcurrently(
            static function () use ($container, $requestA, &$observed): void {
                $container->setScope($container->createScope());
                $container->scopedInstance(Request::class, $requestA);
                \Swoole\Coroutine::sleep(0.02);
                $observed['alpha'] = $container->get(Request::class);
            },
            static function () use ($container, $requestB, &$observed): void {
                $container->setScope($container->createScope());
                $container->scopedInstance(Request::class, $requestB);
                \Swoole\Coroutine::sleep(0.02);
                $observed['beta'] = $container->get(Request::class);
            }
        );

        $this->assertSame($requestA, $observed['alpha'] ?? null, 'Alpha saw the wrong Request.');
        $this->assertSame($requestB, $observed['beta'] ?? null, 'Beta saw the wrong Request.');
    }

    public function testScopedInstanceDoesNotDisturbTheSharedFactoryTable(): void
    {
        $container = new Container();

        $request = new Request('GET', '/one');

        $container->scoped(Request::class, static fn(): Request => $request);

        // A second scope contributes its own instance without re-registering the
        // definition, so any later scoped() re-registration still wins for the
        // scopes that did not supply an instance.
        $otherScope = new ScopeContext('other');
        $container->scopedInstance(Request::class, new Request('GET', '/two'), $otherScope);

        $container->setScope($otherScope);
        $this->assertSame('/two', $container->get(Request::class)->getUri());

        $freshScope = new ScopeContext('fresh');
        $container->setScope($freshScope);
        $this->assertSame('/one', $container->get(Request::class)->getUri());
    }

    public function testRebindingDoesNotEvictAnotherScopesCachedInstance(): void
    {
        $container = new Container();

        $scopeA = new ScopeContext('request-alpha');
        $scopeB = new ScopeContext('request-beta');

        $container->setScope($scopeA);
        $container->scoped(CoroutineScopeProbe::class, static fn(): CoroutineScopeProbe => new CoroutineScopeProbe('alpha'));
        $alphaInstance = $container->get(CoroutineScopeProbe::class);

        $container->setScope($scopeB);
        $container->scoped(CoroutineScopeProbe::class, static fn(): CoroutineScopeProbe => new CoroutineScopeProbe('beta'));

        // Alpha's cached instance must survive beta's re-registration of the same id.
        $container->setScope($scopeA);

        $this->assertSame(
            $alphaInstance,
            $container->get(CoroutineScopeProbe::class),
            'Rebinding an id in scope B evicted the instance already cached in scope A.'
        );
    }
}