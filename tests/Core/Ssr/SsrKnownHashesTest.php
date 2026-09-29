<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Ssr;

use Phue\Core\Cache\FileCache;
use Phue\Core\Ssr\BunSsrEngine;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RecordingSsrClient;

/**
 * SsrKnownHashesTest — pins the contract for "which sidecar module hashes does
 * the PHP side already know about?".
 *
 * ## The defect this file exists for
 *
 * `ssr/server.ts` caches every component module it is handed, keyed by a
 * 12-char sha256 of the module source, under `ssr/.cache/<hash>.mjs`. The
 * wire protocol already supports telling it "I only have the hash, you already
 * have the source": a components entry of `{"h":"6d154ee35e32"}` with no `s`
 * member makes the sidecar resolve from its own cache instead of demanding a
 * re-transmission (server.ts:44-78).
 *
 * Measured against the real PhUI showcase page (47 components):
 *
 *     full source envelope ....... 524.359 bytes  -> HTTP 413 (> 512 KB cap)
 *     hash-only envelope .........  14.199 bytes  -> OK, 106.312 bytes of HTML
 *
 * The PHP side never took that option, because its memory of which hashes the
 * sidecar holds was a `private static array` — process lifetime. Under FPM
 * every request is a fresh process, so the array was always empty and every
 * page load re-transmitted all 512 KB. Under Swoole/RoadRunner the same code
 * was already correct; under FPM it was pure waste that eventually tripped the
 * payload cap and produced a silently missing SSR render.
 *
 * ## What is asserted here
 *
 * The memory has to live in a PSR-16 cache, because PSR-16 is the only store
 * in this framework that survives both a new process and `phue cache:clear`.
 * That creates the failure mode this file exists to keep honest: PHP can
 * remember a hash the sidecar has since forgotten (or never had, if
 * `ssr/.cache` was wiped independently). The engine must recover from that
 * with a bounded retry instead of looping or giving up.
 *
 * These tests describe the intended behaviour. They fail against the current
 * process-scoped implementation by design — that is the regression being fixed.
 *
 * @package Phue\Tests\Core\Ssr
 */
#[Group('unit')]
final class SsrKnownHashesTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir() . '/phue_ssr_hashes_' . uniqid();
        mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->cacheDir);

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Injection surface
    // ---------------------------------------------------------------

    public function testEngineExposesAnOptionalPsr16CacheAsItsThirdConstructorArgument(): void
    {
        $parameters = (new \ReflectionClass(BunSsrEngine::class))->getConstructor()?->getParameters() ?? [];

        $this->assertCount(3, $parameters, 'BunSsrEngine must accept (SsrClient, int maxPayloadKb, ?CacheInterface).');

        $cache = $parameters[2];

        $this->assertSame(
            'cache',
            $cache->getName(),
            'The third constructor argument must be named $cache to match TemplateCompilerBridge, the only other '
            . 'class in this codebase that takes an optional PSR-16 dependency.'
        );
        $this->assertTrue($cache->isDefaultValueAvailable(), 'The cache argument must be optional for backward compatibility.');
        $this->assertNull(
            $cache->getDefaultValue(),
            'The cache argument must default to null so existing construction sites keep working unchanged.'
        );

        $type = $cache->getType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertTrue(
            $type->allowsNull(),
            'The cache argument must accept null — that null is the documented "no memory configured" path.'
        );
        $this->assertSame(
            \Psr\SimpleCache\CacheInterface::class,
            $type->getName(),
            'The third argument must be typed as ?CacheInterface (PSR-16), not a concrete cache class.'
        );
    }

    // ---------------------------------------------------------------
    // Happy path: the memory actually persists
    // ---------------------------------------------------------------

    public function testFirstRenderTransmitsFullSourceForEveryComponent(): void
    {
        $client = new RecordingSsrClient();
        $engine = new BunSsrEngine($client, 512, new FileCache($this->cacheDir));

        $engine->render('<ph-card />', [], $this->context(['PhButton' => 'AAA', 'PhCard' => 'BBB']));

        $this->assertSame(
            ['PhButton', 'PhCard'],
            $client->fullSourceComponents(0),
            'The very first render must ship the source of every component: the sidecar has never seen any of them.'
        );
        $this->assertSame(
            [],
            $client->hashOnlyComponents(0),
            'Nothing can be hash-only on the first render.'
        );
    }

    public function testSecondRenderOnAFreshEngineSendsHashesOnly(): void
    {
        $cache = new FileCache($this->cacheDir);
        $components = $this->context(['PhButton' => 'AAA', 'PhCard' => 'BBB']);

        $first = new RecordingSsrClient();
        (new BunSsrEngine($first, 512, $cache))->render('<ph-card />', [], $components);

        // A brand new engine + a brand new client: this is a different PHP
        // process under FPM, or a different worker under RoadRunner.
        $second = new RecordingSsrClient();
        (new BunSsrEngine($second, 512, $cache))->render('<ph-card />', [], $components);

        $this->assertSame(
            [],
            $second->fullSourceComponents(0),
            'Once the PSR-16 cache knows the hashes, the second render must not re-transmit any source. This is the '
            . 'assertion that fails against the process-scoped static implementation.'
        );
        $this->assertSame(
            ['PhButton', 'PhCard'],
            $second->hashOnlyComponents(0),
            'Every component must still be referenced, just by hash alone.'
        );
    }

    public function testRepeatedRendersOnTheSameEngineAlsoSendHashesOnly(): void
    {
        $client = new RecordingSsrClient();
        $engine = new BunSsrEngine($client, 512, new FileCache($this->cacheDir));
        $components = $this->context(['PhButton' => 'AAA']);

        $engine->render('<ph-card />', [], $components);
        $engine->render('<ph-card />', [], $components);
        $engine->render('<ph-card />', [], $components);

        $this->assertSame(3, $client->dispatchCount(), 'Three successful renders need three dispatches.');
        $this->assertSame([], $client->fullSourceComponents(1), 'Render 2 must be hash-only.');
        $this->assertSame([], $client->fullSourceComponents(2), 'Render 3 must be hash-only.');
    }

    public function testHashMemoryIsNamespacedPerSocket(): void
    {
        $other = sys_get_temp_dir() . '/phue_ssr_hashes_other_' . uniqid();
        mkdir($other, 0777, true);

        try {
            $shared = new FileCache($this->cacheDir);
            $components = $this->context(['PhButton' => 'AAA']);

            $a = new RecordingSsrClient();
            (new BunSsrEngine($a, 512, $shared))->render('<ph-card />', [], $components);

            // Same cache, different store instance — the memory must still
            // resolve, because PSR-16 is the contract, not the object identity.
            $b = new RecordingSsrClient();
            (new BunSsrEngine($b, 512, new FileCache($this->cacheDir)))->render('<ph-card />', [], $components);

            $this->assertSame([], $b->fullSourceComponents(0), 'A separate FileCache instance over the same path must see the same memory.');
        } finally {
            $this->rmTree($other);
        }
    }

    // ---------------------------------------------------------------
    // Backward compatibility
    // ---------------------------------------------------------------

    public function testWithoutACacheEveryRenderTransmitsFullSource(): void
    {
        $client = new RecordingSsrClient();
        $engine = new BunSsrEngine($client);
        $components = $this->context(['PhButton' => 'AAA']);

        $engine->render('<ph-card />', [], $components);
        $engine->render('<ph-card />', [], $components);

        $this->assertSame(
            ['PhButton'],
            $client->fullSourceComponents(0),
            'With no cache configured, behaviour must be exactly what it is today.'
        );
        $this->assertSame(
            ['PhButton'],
            $client->fullSourceComponents(1),
            'With no cache configured there is nothing to remember, so render 2 must also be full source.'
        );
    }

    public function testNullCacheIsEquivalentToOmittingIt(): void
    {
        $client = new RecordingSsrClient();
        $engine = new BunSsrEngine($client, 512, null);
        $components = $this->context(['PhButton' => 'AAA']);

        $engine->render('<ph-card />', [], $components);
        $engine->render('<ph-card />', [], $components);

        $this->assertSame(['PhButton'], $client->fullSourceComponents(1));
    }

    public function testHashMemoryDoesNotLeakBetweenInstancesWithoutASharedStore(): void
    {
        $components = $this->context(['PhButton' => 'AAA']);

        $first = new RecordingSsrClient();
        (new BunSsrEngine($first))->render('<ph-card />', [], $components);

        $second = new RecordingSsrClient();
        (new BunSsrEngine($second))->render('<ph-card />', [], $components);

        $this->assertSame(
            ['PhButton'],
            $second->fullSourceComponents(0),
            'Without a shared PSR-16 store, a new engine must know nothing. A process-scoped static would let the first '
            . 'engine vouch for the second one\'s sidecar, which is exactly the assumption that is false under FPM and '
            . 'false again whenever the sidecar restarts.'
        );
    }

    // ---------------------------------------------------------------
    // The failure mode the cache introduces: PHP knows, sidecar does not
    // ---------------------------------------------------------------

    public function testSidecarCacheMissForgetsHashesAndRetriesWithFullSource(): void
    {
        $cacheMiss = json_encode([
            'ok' => false,
            'error' => [
                'code' => 'COMPILE_ERROR',
                'message' => "Component hash 'abc123abc123' not found in sidecar cache and no source provided.",
            ],
        ], JSON_THROW_ON_ERROR);

        $client = new RecordingSsrClient([
            $cacheMiss,                                            // hash-only attempt, cold sidecar
            '{"ok":true,"html":"<div>recovered</div>"}',            // full-source retry succeeds
        ]);

        $cache = new FileCache($this->cacheDir);
        $components = $this->context(['PhButton' => 'AAA']);

        // Prime the cache so the first render is hash-only.
        (new BunSsrEngine(new RecordingSsrClient(), 512, $cache))->render('<ph-card />', [], $components);

        $html = (new BunSsrEngine($client, 512, $cache))->render('<ph-card />', [], $components);

        $this->assertSame('<div>recovered</div>', $html, 'The engine must recover from a sidecar that forgot a hash.');
        $this->assertSame(2, $client->dispatchCount(), 'Recovery costs exactly one extra dispatch.');
        $this->assertSame(
            ['PhButton'],
            $client->fullSourceComponents(1),
            'The retry must re-transmit the source that the sidecar is missing.'
        );
    }

    public function testSidecarCacheMissRecoveryIsBoundedToASingleRetry(): void
    {
        $alwaysCacheMiss = json_encode([
            'ok' => false,
            'error' => [
                'code' => 'COMPILE_ERROR',
                'message' => "Component hash 'abc123abc123' not found in sidecar cache and no source provided.",
            ],
        ], JSON_THROW_ON_ERROR);

        $client = new RecordingSsrClient([$alwaysCacheMiss]);
        $cache = new FileCache($this->cacheDir);
        $components = $this->context(['PhButton' => 'AAA']);

        (new BunSsrEngine(new RecordingSsrClient(), 512, $cache))->render('<ph-card />', [], $components);

        $html = (new BunSsrEngine($client, 512, $cache))->render('<ph-card />', [], $components);

        $this->assertNull($html, 'An unrecoverable sidecar failure still fails open — SSR degrades, it does not 500.');
        $this->assertLessThanOrEqual(
            2,
            $client->dispatchCount(),
            'The retry must be bounded. A sidecar that never recovers must not turn into a dispatch loop — under FPM '
            . 'that would burn a socket round trip per component per request.'
        );
    }

    public function testSidecarCacheMissRecoveryDoesNotEvictHashesForUnrelatedCompileErrors(): void
    {
        $unrelated = json_encode([
            'ok' => false,
            'error' => [
                'code' => 'COMPILE_ERROR',
                'message' => 'Invalid JavaScript syntax in compiled module',
            ],
        ], JSON_THROW_ON_ERROR);

        $cache = new FileCache($this->cacheDir);
        $components = $this->context(['PhButton' => 'AAA']);

        (new BunSsrEngine(new RecordingSsrClient(), 512, $cache))->render('<ph-card />', [], $components);

        $failing = new RecordingSsrClient([$unrelated]);
        (new BunSsrEngine($failing, 512, $cache))->render('<ph-card />', [], $components);

        $next = new RecordingSsrClient();
        (new BunSsrEngine($next, 512, $cache))->render('<ph-card />', [], $components);

        $this->assertSame(
            [],
            $next->fullSourceComponents(0),
            'A generic COMPILE_ERROR is not evidence that the sidecar lost a module. Evicting every hash on any error '
            . 'would rebuild the full 512 KB payload after a single unrelated failure — a transient render error would '
            . 'permanently downgrade the page back to fat envelopes.'
        );
    }

    public function testHashesAreForgottenAfterAFailedCacheMissRecovery(): void
    {
        $cacheMiss = json_encode([
            'ok' => false,
            'error' => [
                'code' => 'COMPILE_ERROR',
                'message' => "Component hash 'abc123abc123' not found in sidecar cache and no source provided.",
            ],
        ], JSON_THROW_ON_ERROR);

        $cache = new FileCache($this->cacheDir);
        $components = $this->context(['PhButton' => 'AAA']);

        (new BunSsrEngine(new RecordingSsrClient(), 512, $cache))->render('<ph-card />', [], $components);

        $failing = new RecordingSsrClient([$cacheMiss, $cacheMiss]);
        (new BunSsrEngine($failing, 512, $cache))->render('<ph-card />', [], $components);

        $next = new RecordingSsrClient();
        (new BunSsrEngine($next, 512, $cache))->render('<ph-card />', [], $components);

        $this->assertSame(
            ['PhButton'],
            $next->fullSourceComponents(0),
            'Once the sidecar is known to be missing a hash, that hash must be evicted from PSR-16 — otherwise every '
            . 'subsequent request pays a doomed hash-only round trip before the retry.'
        );
    }

    // ---------------------------------------------------------------
    // phue cache:clear
    // ---------------------------------------------------------------

    public function testClearingThePsr16CacheForcesFullSourceOnTheNextRender(): void
    {
        $cacheDir = $this->cacheDir;
        $components = $this->context(['PhButton' => 'AAA']);

        (new BunSsrEngine(new RecordingSsrClient(), 512, new FileCache($cacheDir)))
            ->render('<ph-card />', [], $components);

        // `phue cache:clear` wipes the directory. The sidecar keeps its own
        // ssr/.cache, so a full-source render still succeeds — the point is
        // that the engine never trusts a cache it can no longer read.
        $this->rmTree($cacheDir);
        mkdir($cacheDir, 0777, true);

        $client = new RecordingSsrClient();
        (new BunSsrEngine($client, 512, new FileCache($cacheDir)))->render('<ph-card />', [], $components);

        $this->assertSame(
            ['PhButton'],
            $client->fullSourceComponents(0),
            'After a cache clear the engine must fall back to full source, exactly as it did before the cache existed.'
        );
    }

    public function testAWriteOnlyCacheThatAlwaysMissesDegradesToFullSource(): void
    {
        // A PSR-16 store that accepts writes and never returns anything: this
        // is what an unwritable or evicting cache looks like in production.
        $brokenCache = new class implements \Psr\SimpleCache\CacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->store = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return false;
            }
        };

        $client = new RecordingSsrClient();
        $engine = new BunSsrEngine($client, 512, $brokenCache);
        $components = $this->context(['PhButton' => 'AAA']);

        $engine->render('<ph-card />', [], $components);
        $engine->render('<ph-card />', [], $components);

        $this->assertSame(['PhButton'], $client->fullSourceComponents(0));
        $this->assertSame(
            ['PhButton'],
            $client->fullSourceComponents(1),
            'A cache that never reads back must silently degrade to the old behaviour, not break rendering.'
        );
    }

    // ---------------------------------------------------------------
    // The reason any of this exists: bytes on the wire
    // ---------------------------------------------------------------

    public function testHashOnlyEnvelopeIsAtLeastAnOrderOfMagnitudeSmaller(): void
    {
        $components = [];
        for ($i = 0; $i < 47; $i++) {
            $components['ExamplePhDemo' . $i] = str_repeat('x', 10_000);
        }

        $context = $this->context($components);
        $cache = new FileCache($this->cacheDir);

        $warm = new RecordingSsrClient();
        (new BunSsrEngine($warm, 512, $cache))->render('<ph-card />', [], $context);

        $second = new RecordingSsrClient();
        (new BunSsrEngine($second, 512, $cache))->render('<ph-card />', [], $context);

        $this->assertLessThan(
            524_288,
            $warm->payloadSize(0),
            'The shaping fixture must reproduce a payload the 512 KB cap would reject — otherwise this test proves nothing.'
        );
        $this->assertLessThan(
            $warm->payloadSize(0) / 10,
            $second->payloadSize(0),
            'A hash-only envelope must be at least 10x smaller than the full-source one. On the real PhUI page the '
            . 'measured ratio is 37x (512.1 KB -> 13.9 KB).'
        );
    }

    public function testPayloadCapStillRefusesToDispatchAndStaysUnchangedByTheCache(): void
    {
        $client = new RecordingSsrClient();
        $engine = new BunSsrEngine($client, 1, new FileCache($this->cacheDir));

        $engine->render(str_repeat('a', 4096), ['msg' => 'x']);

        $this->assertSame(0, $client->dispatchCount(), 'The payload guard is a separate concern and must stay in front.');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Builds a render context whose components map mirrors what
     * ComponentRenderer actually hands the engine: Name => module source, as a
     * plain string. The engine is the component that decides whether to carry
     * the source across the wire (`s`) or just the digest (`h`) — that decision
     * is precisely what this suite inspects on the encoded envelope.
     *
     * Every source is salted with a per-call token. Without it these tests
     * would silently depend on execution order: the current implementation
     * keeps its hash memory in a `private static`, so the first test in the
     * class would pre-seed it for every test after it. That cross-instance
     * leakage is itself one of the defects under test, so the suite must not
     * let it reach the other assertions by accident.
     *
     * @param array<string, string> $nameToSource
     * @return array<string, mixed>
     */
    private function context(array $nameToSource): array
    {
        $salt = uniqid('', true);
        $components = [];

        foreach ($nameToSource as $name => $source) {
            $components[$name] = $salt . '::' . $source;
        }

        return ['name' => 'PhUI', 'uri' => '/phui', 'components' => $components];
    }

    private function rmTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . '/' . $item;
            is_dir($full) ? $this->rmTree($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
