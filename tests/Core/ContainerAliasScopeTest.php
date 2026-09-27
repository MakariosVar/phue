<?php

declare(strict_types=1);

namespace Phue\Tests\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Container;

/**
 * Resettable spy service used to observe container instance identity.
 *
 * @package Phue\Tests\Core
 */
final class AliasScopeSpy implements \Phue\Core\ResettableInterface
{
    public static int $constructions = 0;

    public int $generation;

    public function __construct()
    {
        $this->generation = ++self::$constructions;
    }

    public function reset(): void
    {
    }
}

/**
 * Regression gate for the alias()/scoped() interaction in the container.
 *
 * Background
 * ----------
 * Application::registerDatabaseServices() does this:
 *
 *     $this->container->scoped(Connection::class, fn() => new Connection($cfg));
 *     $this->container->alias('db', Connection::class);
 *
 * with the comment "isolated database state across concurrent request scopes"
 * directly above it. But alias() only writes $factories — it never records the
 * alias in $scopedKeys. So get('db') takes the *non-scoped* branch, caches into
 * $instances['db'], and flushScope() (which only ever touches $scopedInstances)
 * never releases it. The first database connection of the process lives
 * forever.
 *
 * The same latent defect is loaded for alias('cache', …), alias('logger', …)
 * and alias('ssr.engine', …).
 *
 * This class encodes:
 *   - the security/correctness invariants that must hold under any fix, and
 *   - the two-part contract for the fix (an alias inherits its target's
 *     persistence, and identity *within* one scope is preserved).
 *
 * @package Phue\Tests\Core
 */
#[Group('unit')]
#[Group('security')]
final class ContainerAliasScopeTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        AliasScopeSpy::$constructions = 0;
        $this->container = new Container();
    }

    // ---------------------------------------------------------------------
    // The defect
    // ---------------------------------------------------------------------

    /**
     * The exact production wiring from Application::registerDatabaseServices().
     *
     * Two requests, two scopes, two different spy instances. Before the fix
     * both requests observe the SAME object: the alias resolved through the
     * non-scoped branch and was cached in the permanent $instances map.
     */
    #[Group('p0')]
    public function testAliasedScopedServiceIsRecreatedForEachScope(): void
    {
        $this->container->scoped(AliasScopeSpy::class, static fn (): AliasScopeSpy => new AliasScopeSpy());
        $this->container->alias('spy', AliasScopeSpy::class);

        $scopeOne = $this->container->createScope();
        $this->container->setScope($scopeOne);
        $first = $this->container->get('spy');
        $this->container->flushScope($scopeOne);

        $scopeTwo = $this->container->createScope();
        $this->container->setScope($scopeTwo);
        $second = $this->container->get('spy');
        $this->container->flushScope($scopeTwo);

        $this->assertNotSame(
            $first,
            $second,
            'The aliased service survived a scope flush — alias() defeated scoped(), '
            . 'so request state leaks across requests in a persistent runtime.'
        );
    }

    /**
     * flushScope() must actually release an aliased scoped service, not just
     * stop returning the cached copy.
     */
    #[Group('p0')]
    public function testFlushScopeReleasesAliasedScopedService(): void
    {
        $this->container->scoped(AliasScopeSpy::class, static fn (): AliasScopeSpy => new AliasScopeSpy());
        $this->container->alias('spy', AliasScopeSpy::class);

        $scope = $this->container->createScope();
        $this->container->setScope($scope);
        $before = $this->container->get('spy');
        $this->container->flushScope($scope);
        $after = $this->container->get('spy');

        $this->assertNotSame($before, $after);
    }

    /**
     * Every alias registered by Application must be safe, not just 'db'.
     *
     * Each alias is walked through a sequence of successive scopes on its own.
     * The earlier version of this test pushed one scope per alias and then
     * asserted four generations for a single alias, which can never hold: an
     * alias visited once only ever collects one entry. It would have failed for
     * any implementation, including the broken one it was meant to catch.
     */
    #[Group('p0')]
    public function testEveryAliasShapeRegisteredByApplicationIsScopeSafe(): void
    {
        $this->container->scoped(AliasScopeSpy::class, static fn (): AliasScopeSpy => new AliasScopeSpy());

        $report = [];

        foreach (['cache', 'logger', 'ssr.engine', 'db'] as $alias) {
            $this->container->alias($alias, AliasScopeSpy::class);

            $generations = [];

            for ($i = 0; $i < 4; $i++) {
                $scope = $this->container->createScope();
                $this->container->setScope($scope);
                $generations[] = $this->container->get($alias)->generation;
                $this->container->flushScope($scope);
            }

            $this->assertCount(4, $generations, "Alias [{$alias}] did not produce one generation per scope.");

            // The invariant is "a distinct instance per scope", not a particular
            // numbering: all four aliases point at the same class and therefore
            // share one construction counter, so the absolute values keep
            // climbing across aliases. Comparing to a hardcoded [1,2,3,4] would
            // fail for a perfectly correct container.
            $this->assertSame(
                $generations,
                array_values(array_unique($generations)),
                "Alias [{$alias}] returned the SAME instance for a different scope: "
                . var_export($generations, true)
            );

            $sorted = $generations;
            sort($sorted);
            $this->assertSame(
                $sorted,
                $generations,
                "Alias [{$alias}] handed out instances out of order, so a scope reused a "
                . 'previous generation: ' . var_export($generations, true)
            );

            $report[$alias] = $generations;
        }

        $this->assertCount(4, $report, 'Not every application-registered alias was exercised.');
    }

    // ---------------------------------------------------------------------
    // The FPM fallback branch: array-valued scoped services are never flushed
    // ---------------------------------------------------------------------

    /**
     * flushScope() decides "is this a partition map or an instance?" with
     * is_array(). A scoped service whose factory legitimately returns an array
     * is therefore classified as a partition map and skipped forever.
     *
     * With no active scope (the plain FPM path) the array must be released.
     */
    #[Group('p0')]
    public function testFpmFallbackFlushesArrayValuedScopedInstances(): void
    {
        $built = 0;

        $this->container->scoped('bag', static function () use (&$built): array {
            return ['build' => ++$built];
        });

        $first = $this->container->get('bag');

        // No active scope: this is the flat FPM fallback branch.
        $this->container->setScope(null);
        $this->container->flushScope();

        $second = $this->container->get('bag');

        $this->assertNotSame(
            $first['build'],
            $second['build'],
            'An array-valued scoped service was never flushed — is_array() misread it as a partition map.'
        );
    }

    /**
     * The same release must happen when a scope IS active, for consistency.
     */
    #[Group('p0')]
    public function testActiveScopeFlushesArrayValuedScopedInstances(): void
    {
        $built = 0;

        $this->container->scoped('bag', static function () use (&$built): array {
            return ['build' => ++$built];
        });

        $scope = $this->container->createScope();
        $this->container->setScope($scope);
        $first = $this->container->get('bag');
        $this->container->flushScope($scope);

        $this->container->setScope($scope);
        $second = $this->container->get('bag');

        $this->assertNotSame($first['build'], $second['build']);
    }

    // ---------------------------------------------------------------------
    // Contract: an alias must inherit its target's persistence, and must not
    // break identity WITHIN a single scope.
    // ---------------------------------------------------------------------

    /**
     * Within one scope the alias and the abstract must be the same object.
     *
     * A naive fix that gives the alias its own factory closure (instead of
     * delegating to the target) would pass the "recreated per scope" test
     * while silently producing two different connections per request. This
     * test is what catches that.
     */
    #[Group('p0')]
    public function testAliasAndAbstractAreTheSameInstanceWithinOneScope(): void
    {
        $this->container->scoped(AliasScopeSpy::class, static fn (): AliasScopeSpy => new AliasScopeSpy());
        $this->container->alias('spy', AliasScopeSpy::class);

        $scope = $this->container->createScope();
        $this->container->setScope($scope);

        $this->assertSame(
            $this->container->get(AliasScopeSpy::class),
            $this->container->get('spy'),
            'The alias and its abstract resolved to different objects inside one scope.'
        );
    }

    /**
     * The inverse: the abstract must see the alias's instance too, otherwise
     * the framework ends up with two live connections per request.
     */
    #[Group('p0')]
    public function testAbstractAndAliasShareOneInstanceInBothResolutionOrders(): void
    {
        $this->container->scoped(AliasScopeSpy::class, static fn (): AliasScopeSpy => new AliasScopeSpy());
        $this->container->alias('spy', AliasScopeSpy::class);

        $scopeA = $this->container->createScope();
        $this->container->setScope($scopeA);
        $viaAlias = $this->container->get('spy');
        $this->assertSame($viaAlias, $this->container->get(AliasScopeSpy::class));
        $this->container->flushScope($scopeA);

        $scopeB = $this->container->createScope();
        $this->container->setScope($scopeB);
        $viaAbstract = $this->container->get(AliasScopeSpy::class);
        $this->assertSame($viaAbstract, $this->container->get('spy'));
        $this->container->flushScope($scopeB);
    }

    // ---------------------------------------------------------------------
    // Non-regression: the existing behaviour that must NOT change
    // ---------------------------------------------------------------------

    /**
     * An alias to a NON-scoped service is a genuine application singleton and
     * must stay one. Fixing P0-2 must not turn every alias into a factory.
     */
    #[Group('p0')]
    public function testAliasToNonScopedServiceRemainsASingleton(): void
    {
        $this->container->bind(AliasScopeSpy::class, static fn (): AliasScopeSpy => new AliasScopeSpy());
        $this->container->alias('singleton', AliasScopeSpy::class);

        $this->assertSame(
            $this->container->get('singleton'),
            $this->container->get(AliasScopeSpy::class)
        );
        $this->assertSame(
            $this->container->get('singleton'),
            $this->container->get('singleton')
        );
    }

    /**
     * A plain scoped service (no alias involved) must keep working exactly as
     * before — same instance inside a scope, new instance across scopes.
     */
    #[Group('p0')]
    public function testPlainScopedServiceSemanticsAreUnchanged(): void
    {
        $this->container->scoped(AliasScopeSpy::class, static fn (): AliasScopeSpy => new AliasScopeSpy());

        $scopeOne = $this->container->createScope();
        $this->container->setScope($scopeOne);
        $a = $this->container->get(AliasScopeSpy::class);
        $b = $this->container->get(AliasScopeSpy::class);
        $this->assertSame($a, $b, 'A scoped service returned two instances inside one scope.');
        $this->container->flushScope($scopeOne);

        $scopeTwo = $this->container->createScope();
        $this->container->setScope($scopeTwo);
        $c = $this->container->get(AliasScopeSpy::class);
        $this->assertNotSame($a, $c, 'A scoped service survived a scope flush.');
        $this->container->flushScope($scopeTwo);
    }

    /**
     * An aliased scoped service must be released even when the caller does not
     * know the alias exists, and even for state created outside any explicit
     * scope. This reproduces Application::handle() exactly: it flushes the
     * request scope and then the fallback bucket.
     *
     * Note the contract being pinned: flushScope($scope) targets that scope,
     * and a bare flushScope() targets the fallback bucket. A bare call is NOT
     * "flush the current scope" — the earlier version of this test assumed it
     * was, and passed for the wrong reason before the two were separated.
     */
    #[Group('p0')]
    public function testAliasedScopedServiceIsReleasedByBareFlushScopeCall(): void
    {
        $this->container->scoped(AliasScopeSpy::class, static fn (): AliasScopeSpy => new AliasScopeSpy());
        $this->container->alias('spy', AliasScopeSpy::class);

        // State inside an explicit request scope.
        $scope = $this->container->createScope();
        $this->container->setScope($scope);
        $inScope = $this->container->get('spy');

        // State resolved with no active scope at all, i.e. the fallback bucket.
        $this->container->setScope(null);
        $inFallback = $this->container->get('spy');

        $this->assertNotSame(
            $inScope,
            $inFallback,
            'Precondition: the request scope and the fallback bucket must be distinct storage.'
        );

        // Exactly what Application::handle() does in its finally block.
        $this->container->flushScope($scope);
        $this->container->flushScope();

        $this->container->setScope($scope);
        $this->assertNotSame(
            $inScope,
            $this->container->get('spy'),
            'An aliased scoped service survived the request-scope flush.'
        );

        $this->container->setScope(null);
        $this->assertNotSame(
            $inFallback,
            $this->container->get('spy'),
            'An aliased scoped service resolved outside any scope was never released — '
            . 'the fallback bucket is only drained by a bare flushScope().'
        );
    }

    // ---------------------------------------------------------------------
    // Every resolution entry point must agree about aliases.
    //
    // Resolving an alias in get() but not in has()/bound()/make() makes the
    // container lie about its own contents: a caller that probes with has()
    // and then resolves with get() silently takes a different branch.
    // ---------------------------------------------------------------------

    /**
     * has() must resolve aliases.
     *
     * This is a live regression, not a hypothetical: SchiiModule.php:171 does
     *
     *     $cache = $c->has('cache') ? $c->get('cache') : null;
     *
     * so a has() that does not know about the 'cache' alias hands SchemaRegistry
     * a permanently null cache and the schema cache is silently dead.
     */
    #[Group('p0')]
    public function testHasResolvesAlias(): void
    {
        $this->container->scoped(AliasScopeSpy::class, static fn (): AliasScopeSpy => new AliasScopeSpy());
        $this->container->alias('cache', AliasScopeSpy::class);

        $this->assertTrue(
            $this->container->has('cache'),
            "has('cache') returned false for a registered alias — the optional-dependency "
            . 'probe silently takes the null branch.'
        );
    }

    /**
     * bound() must resolve aliases. HealthController uses bound() for exactly
     * this kind of optional probe.
     */
    #[Group('p0')]
    public function testBoundResolvesAlias(): void
    {
        $this->container->bind(AliasScopeSpy::class, static fn (): AliasScopeSpy => new AliasScopeSpy());
        $this->container->alias('singleton', AliasScopeSpy::class);

        $this->assertTrue(
            $this->container->bound('singleton'),
            "bound() returned false for a registered alias."
        );
    }

    /**
     * make() must resolve aliases. make() is documented as "force resolution of
     * a NEW, un-cached service instance" — it must not be the one entry point
     * where an alias stops existing.
     */
    #[Group('p0')]
    public function testMakeResolvesAlias(): void
    {
        $this->container->scoped(AliasScopeSpy::class, static fn (): AliasScopeSpy => new AliasScopeSpy());
        $this->container->alias('db', AliasScopeSpy::class);

        $resolved = $this->container->make('db');

        $this->assertInstanceOf(
            AliasScopeSpy::class,
            $resolved,
            "make('db') could not resolve a registered alias."
        );
    }

    /**
     * Chained aliases must resolve through every hop, in every entry point.
     */
    #[Group('p0')]
    public function testAliasChainResolvesThroughEveryEntryPoint(): void
    {
        $this->container->scoped(AliasScopeSpy::class, static fn (): AliasScopeSpy => new AliasScopeSpy());
        $this->container->alias('level1', AliasScopeSpy::class);
        $this->container->alias('level2', 'level1');

        $scope = $this->container->createScope();
        $this->container->setScope($scope);

        $this->assertTrue($this->container->has('level2'), 'has() did not follow the alias chain.');
        $this->assertTrue($this->container->bound('level2'), 'bound() did not follow the alias chain.');
        $this->assertInstanceOf(AliasScopeSpy::class, $this->container->make('level2'));
        $this->assertSame(
            $this->container->get('level2'),
            $this->container->get(AliasScopeSpy::class),
            'A chained alias resolved to a different object than its final target.'
        );
    }

    /**
     * A self-referential alias must not hang the container.
     *
     * Alias resolution is a loop over the alias map. Without a visited-set
     * guard, alias('db', 'db') spins forever and burns a CPU core — in a
     * persistent runtime that is a worker that never comes back.
     *
     * The probe runs in a subprocess with a hard deadline, because the failure
     * mode is an infinite loop: asserted in-process it would hang CI instead
     * of failing it.
     */
    #[Group('p0')]
    public function testSelfReferentialAliasDoesNotHang(): void
    {
        $exitCode = $this->runInSubprocess(
            <<<'PHP'
            $c = new \Phue\Core\Container();
            $c->bind('db', fn(): string => 'value');
            $c->alias('db', 'db');
            echo $c->get('db');
            PHP,
            5
        );

        $this->assertNotSame(
            self::TIMEOUT_EXIT,
            $exitCode,
            'A self-referential alias sends the container into an unbounded loop — '
            . 'in a persistent runtime that worker never returns.'
        );
    }

    /**
     * A mutually recursive alias pair must terminate too.
     */
    #[Group('p0')]
    public function testMutuallyRecursiveAliasesDoNotHang(): void
    {
        $exitCode = $this->runInSubprocess(
            <<<'PHP'
            $c = new \Phue\Core\Container();
            $c->bind('concrete', fn(): string => 'value');
            $c->alias('ping', 'pong');
            $c->alias('pong', 'ping');
            echo $c->get('ping');
            PHP,
            5
        );

        $this->assertNotSame(
            self::TIMEOUT_EXIT,
            $exitCode,
            'Mutually recursive aliases send the container into an unbounded loop.'
        );
    }

    /**
     * Sentinel exit code used when a subprocess exceeds its deadline.
     */
    private const TIMEOUT_EXIT = 'timed-out';

    /**
     * Runs a PHP snippet in a separate process with a hard deadline and
     * returns its exit code, or self::TIMEOUT_EXIT if it overran.
     */
    private function runInSubprocess(string $body, int $timeoutSeconds): string|int
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = sprintf(
            'require %s; %s',
            var_export($autoload, true),
            $body
        );

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-r', $script],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            $this->fail('Could not spawn the alias-resolution probe process.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }

            usleep(20_000);
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($timedOut) {
            return self::TIMEOUT_EXIT;
        }

        $this->assertSame(
            '',
            $stderr,
            'The alias-resolution probe wrote to stderr: ' . $stderr
        );

        return strlen($stdout) > 0 ? 'completed' : 'empty';
    }

    /**
     * The partition-detection heuristic in flushScope() must not swallow a
     * legitimate service whose id happens to share the scope-id prefix.
     */
    #[Group('p0')]
    public function testServiceIdSharingTheScopePrefixIsStillFlushed(): void
    {
        $built = 0;

        $this->container->scoped('scope_reader', static function () use (&$built): array {
            return ['build' => ++$built];
        });

        $this->container->setScope(null);
        $first = $this->container->get('scope_reader');
        $this->container->flushScope();
        $second = $this->container->get('scope_reader');

        $this->assertNotSame(
            $first['build'],
            $second['build'],
            'A service id beginning with "scope_" is mistaken for a partition map and never flushed.'
        );
    }
}
