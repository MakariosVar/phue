<?php

declare(strict_types=1);

namespace Phue\Tests\Modules\RateLimit;

use Phue\Core\Config;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Routing\Router;
use Phue\Modules\RateLimit\Attribute\RateLimit;
use Phue\Modules\RateLimit\Middleware\RateLimitMiddleware;
use Phue\Modules\RateLimit\Policy\PolicyResolver;
use Phue\Modules\RateLimit\Policy\RateLimitPolicy;
use Phue\Modules\RateLimit\Storage\RateLimiterInterface;
use Phue\Modules\RateLimit\Storage\RateLimitResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hardening suite for RateLimitMiddleware — the enforcement gate itself.
 *
 * The module shipped with storage and policy suites and no coverage of the
 * middleware that decides whether the controller runs at all, which is where
 * every "am I actually protected" question is answered: the disabled switch,
 * the whitelist, the policy lookup, the key derivation, the 429 build, and
 * the one ordering invariant the whole module exists for — a limited request
 * never reaches the handler.
 *
 * The trust-boundary tests at the bottom pin a hole this suite found: the
 * middleware used to honour X-Forwarded-For from ANY peer as soon as
 * app.trustedProxies was non-empty, contradicting the documented contract
 * (docs/Core.md) — trust a forwarded header only when the connecting remote
 * address is itself trusted. A client could mint a fresh bucket per request
 * by rotating the header and the per-IP limit would never bind.
 *
 * @package Phue\Tests\Modules\RateLimit
 */
#[Group('unit')]
final class RateLimitMiddlewareTest extends TestCase
{
    private Config $config;
    private Router $router;
    private RecordingLimiter $limiter;
    private RateLimitMiddleware $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $configPath = realpath(__DIR__ . '/../../../config');
        $this->config = new Config($configPath !== false ? $configPath : '/app/config');

        $this->router = new Router();
        $this->router->get('/throttled', [ThrottledController::class, 'index']);
        $this->router->get('/apikey', [ApiKeyController::class, 'index']);
        $this->router->get('/open', fn () => 'no limit');
        $this->router->get('/plain', [PlainController::class, 'index']);

        $this->limiter = new RecordingLimiter();
        $this->gate = $this->buildMiddleware();
    }

    private function buildMiddleware(): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            limiter: $this->limiter,
            resolver: new PolicyResolver($this->config),
            config: $this->config,
            router: $this->router
        );
    }

    private function request(
        string $uri = '/throttled',
        array $headers = [],
        ?string $remoteAddr = '203.0.113.10'
    ): Request {
        $server = $remoteAddr !== null ? ['REMOTE_ADDR' => $remoteAddr] : [];

        return new Request('GET', $uri, [], [], $headers, $server);
    }

    // ------------------------------------------------------------------
    // The gate: what gets limited at all
    // ------------------------------------------------------------------

    public function testGloballyDisabledPassesThroughWithoutCheckingLimiter(): void
    {
        $this->config->set('rate-limit.enabled', false);

        $called = 0;
        $response = ($this->gate)($this->request(), static function () use (&$called) {
            $called++;
            return Response::json(['ok' => true]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $called);
        $this->assertSame([], $this->limiter->checkedKeys);
    }

    public function testRouteWithoutPolicyIsNotLimited(): void
    {
        // A closure handler carries no attributes, so no policy resolves —
        // an unannotated route must never be limited by accident.
        $called = 0;
        $response = ($this->gate)($this->request('/open'), static function () use (&$called) {
            $called++;
            return Response::json(['ok' => true]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $called);
        $this->assertSame([], $this->limiter->checkedKeys);
    }

    public function testWhitelistedIpBypassesTheLimiter(): void
    {
        $this->config->set('rate-limit.whitelist', ['203.0.113.0/24']);

        $called = 0;
        $response = ($this->gate)($this->request(), static function () use (&$called) {
            $called++;
            return Response::json(['ok' => true]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $called);
        $this->assertSame([], $this->limiter->checkedKeys, 'a whitelisted IP must never reach the limiter');
    }

    public function testWhitelistDoesNotMatchUnrelatedIp(): void
    {
        $this->config->set('rate-limit.whitelist', ['198.51.100.0/24']);

        ($this->gate)($this->request(), static fn () => Response::json(['ok' => true]));

        $this->assertCount(1, $this->limiter->checkedKeys, 'an IP outside the whitelist must still be limited');
    }

    // ------------------------------------------------------------------
    // Allowed path
    // ------------------------------------------------------------------

    public function testAllowedRequestAddsRateLimitHeaders(): void
    {
        $this->limiter->allowed = true;

        $response = ($this->gate)($this->request(), static fn () => Response::json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('2', $response->getHeader('X-RateLimit-Limit'));
        $this->assertSame('1', $response->getHeader('X-RateLimit-Remaining'));
        $this->assertNotNull($response->getHeader('X-RateLimit-Reset'));
        $this->assertSame('0', $response->getHeader('Retry-After'));
    }

    public function testLimitedRequestNeverReachesTheHandler(): void
    {
        $this->limiter->allowed = false;

        $called = 0;
        $response = ($this->gate)($this->request(), static function () use (&$called) {
            $called++;
            return Response::json(['ok' => true]);
        });

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(0, $called, 'a limited request must not execute the controller');

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Too Many Requests', $body['error']);
        $this->assertSame(60, $body['retry_after']);
        $this->assertSame(2, $body['limit']);

        $this->assertSame('60', $response->getHeader('Retry-After'));
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
    }

    public function testRateLimitResponseHonoursConfiguredStatusAndFormat(): void
    {
        $this->limiter->allowed = false;
        $this->config->set('rate-limit.response', [
            'status_code' => 420,
            'message'    => 'Slow down',
            'json_format' => false,
        ]);

        $response = ($this->gate)($this->request(), static fn () => Response::json(['ok' => true]));

        $this->assertSame(420, $response->getStatusCode());
        $this->assertSame('Slow down', $response->getContent());
        $this->assertSame('text/plain', $response->getHeader('Content-Type'));
    }

    // ------------------------------------------------------------------
    // Key derivation — the identity the bucket binds to
    // ------------------------------------------------------------------

    public function testIpKeyResolverBindsTheBucketToRemoteAddr(): void
    {
        ($this->gate)($this->request(remoteAddr: '198.51.100.7'), static fn () => Response::json([]));

        $this->assertSame(['198.51.100.7'], $this->limiter->checkedKeys);
    }

    public function testUnresolvableKeyAllowsTheRequest(): void
    {
        // No REMOTE_ADDR and the ip resolver cannot guess: the documented
        // behaviour is to allow (availability first) rather than 500.
        $called = 0;
        $response = ($this->gate)($this->request(remoteAddr: null), static function () use (&$called) {
            $called++;
            return Response::json(['ok' => true]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $called);
        $this->assertSame([], $this->limiter->checkedKeys);
    }

    public function testApiKeyResolverHashesTheHeader(): void
    {
        $response = ($this->gate)(
            $this->request('/apikey', ['X-API-Key' => 'super-secret']),
            static fn () => Response::json([])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['apikey_' . hash('sha256', 'super-secret')], $this->limiter->checkedKeys);
    }

    public function testBearerTokenIsAcceptedAsApiKey(): void
    {
        ($this->gate)(
            $this->request('/apikey', ['Authorization' => 'Bearer tok-abc']),
            static fn () => Response::json([])
        );

        $this->assertSame(['apikey_' . hash('sha256', 'tok-abc')], $this->limiter->checkedKeys);
    }

    public function testCustomCallableKeyResolverIsUsed(): void
    {
        $this->config->set('rate-limit.default', [
            'requests' => 5,
            'window'   => 'minute',
            'key'      => static fn (Request $r): string => 'tenant_' . ($r->getHeader('X-Tenant') ?? 'none'),
        ]);

        $this->gate = $this->buildMiddleware();
        // A class handler WITHOUT an attribute: the policy comes from the
        // configured default, which is where a callable key resolver can be
        // declared (an attribute parameter cannot hold a closure).
        ($this->gate)(
            $this->request('/plain', ['X-Tenant' => 'acme']),
            static fn () => Response::json([])
        );

        $this->assertSame(['tenant_acme'], $this->limiter->checkedKeys);
    }

    public function testUserKeyResolverWithoutAuthManagerDoesNotBlock(): void
    {
        $this->config->set('rate-limit.default', [
            'requests' => 5,
            'window'   => 'minute',
            'key'      => 'user',
        ]);

        $this->gate = $this->buildMiddleware();
        $response = ($this->gate)($this->request('/plain'), static fn () => Response::json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->limiter->checkedKeys);
    }

    // ------------------------------------------------------------------
    // Proxy trust — the boundary this suite found broken
    // ------------------------------------------------------------------

    public function testForwardedForIsIgnoredWhenPeerIsNotATrustedProxy(): void
    {
        $this->config->set('app.trustedProxies', ['10.0.0.1']);

        ($this->gate)(
            $this->request(headers: ['X-Forwarded-For' => '10.9.9.9'], remoteAddr: '203.0.113.42'),
            static fn () => Response::json([])
        );

        // An untrusted peer's X-Forwarded-For used to be honoured wholesale:
        // one fake IP per request means the per-IP limit never binds. The
        // bucket must bind to the peer we can actually see.
        $this->assertSame(['203.0.113.42'], $this->limiter->checkedKeys);
    }

    public function testForwardedForIsHonouredWhenPeerIsATrustedProxy(): void
    {
        $this->config->set('app.trustedProxies', ['10.0.0.1']);

        ($this->gate)(
            $this->request(headers: ['X-Forwarded-For' => '10.9.9.9, 10.0.0.1'], remoteAddr: '10.0.0.1'),
            static fn () => Response::json([])
        );

        // First hop wins: the left-most entry is the original client, the
        // rest are proxies that appended.
        $this->assertSame(['10.9.9.9'], $this->limiter->checkedKeys);
    }

    public function testWildcardTrustEntryHonoursEveryForwardedFor(): void
    {
        $this->config->set('app.trustedProxies', ['*']);

        ($this->gate)(
            $this->request(headers: ['X-Forwarded-For' => '10.9.9.9'], remoteAddr: '172.16.0.5'),
            static fn () => Response::json([])
        );

        $this->assertSame(['10.9.9.9'], $this->limiter->checkedKeys);
    }

    public function testFallsBackToRemoteAddrWhenNoForwardedHeaderPresent(): void
    {
        $this->config->set('app.trustedProxies', ['10.0.0.1']);

        ($this->gate)($this->request(remoteAddr: '10.0.0.1'), static fn () => Response::json([]));

        $this->assertSame(['10.0.0.1'], $this->limiter->checkedKeys);
    }

    public function testEmptyTrustedProxiesIgnoresForwardedFor(): void
    {
        $this->config->set('app.trustedProxies', []);

        ($this->gate)(
            $this->request(headers: ['X-Forwarded-For' => '10.9.9.9'], remoteAddr: '203.0.113.42'),
            static fn () => Response::json([])
        );

        $this->assertSame(['203.0.113.42'], $this->limiter->checkedKeys);
    }
}

// ----------------------------------------------------------------------
// Fixtures
// ----------------------------------------------------------------------

final class ThrottledController
{
    #[RateLimit(2, 'minute', key: 'ip')]
    public function index(): void {}
}

final class ApiKeyController
{
    #[RateLimit(2, 'minute', key: 'api_key')]
    public function index(): void {}
}

/**
 * A handler with no attribute of its own: policies for it come from
 * configuration, which is the only place a callable key resolver can live.
 */
final class PlainController
{
    public function index(): void {}
}

/**
 * Scripted limiter: records the keys it was asked to check and answers from
 * a single flag, so the middleware's decisions are asserted without a Redis.
 */
final class RecordingLimiter implements RateLimiterInterface
{
    public bool $allowed = true;

    /** @var list<string> */
    public array $checkedKeys = [];

    public function check(string $key, RateLimitPolicy $policy): RateLimitResult
    {
        $this->checkedKeys[] = $key;

        return $this->allowed
            ? RateLimitResult::allowed($key, $policy, 1, time() + 60)
            : RateLimitResult::denied($key, $policy, time() + 60, 60);
    }

    public function peek(string $key, RateLimitPolicy $policy): RateLimitResult
    {
        return RateLimitResult::allowed($key, $policy, 0, time() + 60);
    }

    public function reset(string $key): bool
    {
        return true;
    }

    public function clearAll(): void {}
}
