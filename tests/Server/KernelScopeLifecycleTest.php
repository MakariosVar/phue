<?php

declare(strict_types=1);

namespace Phue\Tests\Server;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Container;
use Phue\Core\Request;
use Phue\Core\ScopeContext;
use Phue\Modules\Server\FpmAdapter;
use Phue\Modules\Server\RoadRunnerAdapter;
use Phue\Modules\Server\SwooleAdapter;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * Spy service used to observe instance identity across handled requests.
 *
 * @package Phue\Tests\Server
 */
final class RequestScopeSpy
{
    public static int $constructions = 0;

    public int $generation;

    public function __construct()
    {
        $this->generation = ++self::$constructions;
    }
}

/**
 * Regression gate for request-scope isolation in the serving path.
 *
 * Background
 * ----------
 * The scope lifecycle — the thing the whole persistent-runtime design rests on
 * — is wired in exactly one place: SwooleAdapter::run(), which calls
 * createScope() / setScope() / flushScope() around the request.
 *
 * That code is unreachable in production. `phue serve` runs
 * SwooleServeCommand::execute(), whose on('request') closure (lines 130-135)
 * contains no scope handling at all — grep for Scope in that file returns
 * nothing. So the adapter that the CLI never uses has the isolation, and the
 * adapter the CLI does use does not.
 *
 * RoadRunner and Wasm never create a scope either, so they always run the flat
 * FPM fallback branch of flushScope() — which is precisely the branch that
 * never releases array-valued instances and never sees alias-resolved ones.
 *
 * The architectural fix is to move the scope lifecycle into the kernel:
 * Application::handle() should establish a scope when none is active and
 * release it when it returns. Then every adapter — Swoole, RoadRunner, Wasm,
 * FPM, and `phue serve` — inherits isolation from one chokepoint instead of
 * each having to remember.
 *
 * These tests encode that guarantee. They use reflection to read the
 * container's private active-scope pointer, because "is a scope active during
 * the request?" is an internal-state question with no public accessor yet;
 * that is deliberate, so a fix cannot pass by hiding the pointer.
 *
 * @package Phue\Tests\Server
 */
#[Group('integration')]
#[Group('security')]
final class KernelScopeLifecycleTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private Application $app;

    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        RequestScopeSpy::$constructions = 0;

        $this->app = new Application(dirname(__DIR__, 2));
        $this->isolateApplicationDatabase($this->app);
        $this->container = $this->app->getContainer();
    }

    /**
     * Reads the container's private active-scope pointer.
     */
    private function activeScope(): ?ScopeContext
    {
        $property = new \ReflectionProperty(Container::class, 'currentScope');

        /** @var ScopeContext|null $scope */
        $scope = $property->getValue($this->container);

        return $scope;
    }

    /**
     * Runs a request and reports the scope state observed from inside the
     * route handler, i.e. the only moment isolation actually matters.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function observeScopeFromInsideRequest(string $path = '/scope-probe'): array
    {
        $observed = ['active' => false, 'id' => null];

        $this->app->getRouter()->get($path, function () use (&$observed): string {
            $scope = $this->activeScope();
            $observed['active'] = $scope instanceof ScopeContext;
            $observed['id'] = $scope?->id;

            return 'probed';
        });

        $this->app->handle(new Request('GET', $path));

        return [$observed['active'], $observed['id']];
    }

    // ---------------------------------------------------------------------
    // The defect: the kernel establishes no scope at all
    // ---------------------------------------------------------------------

    /**
     * Inside a request handler there must be an active scope.
     *
     * Before the fix this is null: Application::handle() binds the Request as
     * scoped and then calls flushScope() with no scope ever created, so every
     * "scoped" service lands in the flat fallback array instead of a partition.
     */
    #[Group('p0')]
    public function testKernelEstablishesAnActiveScopeDuringRequestHandling(): void
    {
        [$active] = $this->observeScopeFromInsideRequest();

        $this->assertTrue(
            $active,
            'No scope is active while a request is being handled — the whole partition '
            . 'machinery is inert and every runtime silently degrades to the flat FPM array.'
        );
    }

    /**
     * Each request must get a DISTINCT scope. A scope that is reused across
     * requests is exactly the leak the design claims to prevent.
     */
    #[Group('p0')]
    public function testEachRequestGetsADistinctScope(): void
    {
        $this->app->getRouter()->get('/scope-id-probe', function (): string {
            return (string) ($this->activeScope()?->id ?? 'none');
        });

        $first = $this->app->handle(new Request('GET', '/scope-id-probe'))->getContent();
        $second = $this->app->handle(new Request('GET', '/scope-id-probe'))->getContent();

        $this->assertNotSame(
            $first,
            $second,
            'Two requests shared the same scope id, so their request state is shared.'
        );
    }

    /**
     * A stale scope left behind by a crashed request must not be adopted by the
     * next one. Two requests after a leaked scope must still be isolated.
     */
    #[Group('p0')]
    public function testStaleScopeFromAFailedRequestIsNotAdoptedByTheNextOne(): void
    {
        $this->app->getRouter()->get('/crash-probe', function (): string {
            throw new \RuntimeException('boom');
        });

        $this->app->handle(new Request('GET', '/crash-probe'));

        $this->app->getRouter()->get('/after-crash-probe', function (): string {
            return (string) ($this->activeScope()?->id ?? 'none');
        });

        $this->assertNotSame(
            'none',
            $this->app->handle(new Request('GET', '/after-crash-probe'))->getContent(),
            'The request after a failure ran with no active scope.'
        );
    }

    // ---------------------------------------------------------------------
    // Isolation actually holds end to end
    // ---------------------------------------------------------------------

    /**
     * The end-to-end proof of the serving-path defect: a service registered
     * the way Application registers the database connection (scoped + alias)
     * must not be shared between two handled requests.
     */
    #[Group('p0')]
    public function testAliasedScopedServiceIsNotSharedBetweenTwoHandledRequests(): void
    {
        $this->container->scoped(
            RequestScopeSpy::class,
            static fn (): RequestScopeSpy => new RequestScopeSpy()
        );
        $this->container->alias('spy', RequestScopeSpy::class);

        $generations = [];

        $this->app->getRouter()->get('/spy-probe', function () use (&$generations): string {
            $generations[] = $this->container->get('spy')->generation;

            return 'ok';
        });

        $this->app->handle(new Request('GET', '/spy-probe'));
        $this->app->handle(new Request('GET', '/spy-probe'));

        $this->assertNotSame(
            $generations[0] ?? null,
            $generations[1] ?? null,
            'Two requests shared the same aliased scoped service — request state leaked.'
        );
    }

    /**
     * A plain scoped service must be new per request even when an adapter has
     * already established a scope around it.
     */
    #[Group('p0')]
    public function testScopedServiceIsNewPerRequestUnderAnAdapterProvidedScope(): void
    {
        $this->container->scoped(
            RequestScopeSpy::class,
            static fn (): RequestScopeSpy => new RequestScopeSpy()
        );

        $generations = [];

        $this->app->getRouter()->get('/scoped-probe', function () use (&$generations): string {
            $generations[] = $this->container->get(RequestScopeSpy::class)->generation;

            return 'ok';
        });

        $adapterScope = $this->container->createScope();
        $this->container->setScope($adapterScope);
        $this->app->handle(new Request('GET', '/scoped-probe'));
        $this->container->flushScope($adapterScope);

        $this->container->setScope($adapterScope);
        $this->app->handle(new Request('GET', '/scoped-probe'));
        $this->container->flushScope($adapterScope);

        $this->assertNotSame(
            $generations[0] ?? null,
            $generations[1] ?? null,
            'A scoped service was shared between two adapter-scoped requests.'
        );
    }

    // ---------------------------------------------------------------------
    // The kernel must not fight an adapter that already scoped the request
    // ---------------------------------------------------------------------

    /**
     * If an adapter established a scope, the kernel must use THAT scope and
     * must not detach it. SwooleAdapter::run() sets the scope and expects to
     * own the flush in its own finally block; if handle() calls
     * setScope(null) it would break the adapter's teardown.
     */
    #[Group('p0')]
    public function testKernelAdoptsAnAdapterProvidedScopeWithoutDetachingIt(): void
    {
        $adapterScope = $this->container->createScope();
        $this->container->setScope($adapterScope);

        $this->app->getRouter()->get('/adopt-probe', function (): string {
            return (string) ($this->activeScope()?->id ?? 'none');
        });

        $content = $this->app->handle(new Request('GET', '/adopt-probe'))->getContent();

        $this->assertSame(
            $adapterScope->id,
            $content,
            'The kernel replaced the scope the adapter established.'
        );
        $this->assertSame(
            $adapterScope,
            $this->activeScope(),
            'The kernel detached the adapter scope — the adapter teardown can no longer flush it.'
        );

        $this->container->setScope(null);
    }

    // ---------------------------------------------------------------------
    // The kernel is the single chokepoint for every runtime
    // ---------------------------------------------------------------------

    /**
     * Every adapter delegates to Application::handle(). That is what makes one
     * fix in the kernel sufficient — this test pins the convergence so a future
     * adapter cannot quietly bypass the chokepoint.
     */
    #[Group('p0')]
    public function testEveryAdapterDelegatesToTheKernelHandleBoundary(): void
    {
        $observed = [];

        $this->app->getRouter()->get('/adapter-probe', function () use (&$observed): string {
            $observed[] = $this->activeScope()?->id ?? 'none';

            return 'ok';
        });

        foreach ([new FpmAdapter(), new RoadRunnerAdapter(), new SwooleAdapter()] as $adapter) {
            $adapter->boot($this->app);
            $adapter->handleRequest(new Request('GET', '/adapter-probe'));
        }

        $this->assertCount(3, $observed, 'Not every adapter reached the route handler.');
        $this->assertNotContains(
            'none',
            $observed,
            'An adapter ran the request with no active scope: ' . var_export($observed, true)
        );
        $this->assertCount(
            3,
            array_unique($observed),
            "Two adapters shared a scope id:\n" . var_export($observed, true)
        );
    }

    /**
     * After handle() returns, the container must be back to a clean state so
     * the next request starts from nothing.
     */
    #[Group('p0')]
    public function testContainerIsCleanAfterHandleReturns(): void
    {
        $this->app->handle(new Request('GET', '/definitely-not-routed-xyz'));

        $this->assertNull(
            $this->activeScope(),
            'A scope was left active after handle() returned, so the next request inherits it.'
        );
    }

    // ---------------------------------------------------------------------
    // One storage location per scoped service, not two
    //
    // Container keeps scoped instances in $scopedInstances, partitioned by
    // scope id when a scope is active and flat when it is not. The kernel
    // lifecycle means "inside handle()" there is ALWAYS a scope, so a service
    // resolved at boot or between requests lands in the flat bucket and is
    // invisible — and never flushed — inside the request.
    // ---------------------------------------------------------------------

    /**
     * A SINGLETON is the same object inside and outside the request.
     *
     * This is the invariant the kernel scope lifecycle must not break, and it
     * is deliberately NOT the same claim as "a scoped service keeps its
     * identity" — that would contradict what scoped() means. A scoped service
     * is expected to be rebuilt per request; a singleton is expected to be
     * shared, and anything that resolves infrastructure outside a request
     * (a migrator, a console command, a warm-up path) must be talking to the
     * same object the request handler sees.
     *
     * Regression: while Connection was bound with scoped(), every handle()
     * built a new PDO handle and state prepared at boot was invisible to
     * requests — a :memory: database simply did not contain the tables the
     * boot code had created, and the request died with "no such table".
     */
    #[Group('p0')]
    public function testSingletonIsTheSameInstanceInsideAndOutsideTheRequest(): void
    {
        $this->container->singleton(
            RequestScopeSpy::class,
            static fn (): RequestScopeSpy => new RequestScopeSpy()
        );

        $outsideRequest = $this->container->get(RequestScopeSpy::class);

        $seen = null;
        $this->app->getRouter()->get('/identity-probe', function () use (&$seen): string {
            $seen = $this->container->get(RequestScopeSpy::class);

            return 'ok';
        });

        $this->app->handle(new Request('GET', '/identity-probe'));

        $this->assertSame(
            $outsideRequest,
            $seen,
            'A singleton resolved before handle() is a DIFFERENT object inside the request. '
            . 'Anything that prepares infrastructure state at boot is then invisible to '
            . 'request handlers, which is how a :memory: database ends up with no tables.'
        );
    }

    /**
     * The inverse leak: a scoped service resolved outside any scope is never
     * released, because flushScope($scope) only drains the partition it was
     * handed. It accumulates in the flat bucket for the life of the process.
     */
    #[Group('p0')]
    public function testServiceResolvedOutsideAnyScopeIsEventuallyReleased(): void
    {
        $this->container->scoped(
            RequestScopeSpy::class,
            static fn (): RequestScopeSpy => new RequestScopeSpy()
        );

        $this->container->setScope(null);
        $first = $this->container->get(RequestScopeSpy::class);

        $this->app->handle(new Request('GET', '/definitely-not-routed-xyz'));

        $this->container->setScope(null);
        $second = $this->container->get(RequestScopeSpy::class);

        $this->assertNotSame(
            $first,
            $second,
            'A scoped service resolved outside the request scope was never released — '
            . 'flushScope($scope) drains only the partition, so the flat bucket leaks forever.'
        );
    }
}
