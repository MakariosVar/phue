# Rate Limiting

The Phue framework provides a comprehensive rate limiting system built on Redis with support for multiple algorithms and flexible configuration.

## Overview

The rate limiting module (`Phue\Modules\RateLimit`) provides:

- **Distributed rate limiting** via Redis
- **Two algorithms**: Sliding Window and Token Bucket
- **Attribute-based** configuration via `#[RateLimit]`
- **Named policies** for reusable configurations
- **Flexible key resolution** (IP, User, API Key, custom)
- **Whitelist support** (CIDR notation)
- **Standard headers** (X-RateLimit-*, Retry-After)
- **JSON/HTML responses** for rate limited requests

## Installation

Add the module to your `config/app.php`:

```php
'modules' => [
    // ... other modules
    \Phue\Modules\RateLimit\RateLimitModule::class,
],
```

Configure Redis in `config/rate-limit.php` (see Configuration below).

## Configuration

### `config/rate-limit.php`

```php
return [
    // Master switch
    'enabled' => env('RATE_LIMIT_ENABLED', true),

    // Default policy (applied when no attribute present)
    'default' => [
        'requests'   => env('RATE_LIMIT_DEFAULT_REQUESTS', 100),
        'window'     => env('RATE_LIMIT_DEFAULT_WINDOW', 'minute'), // second, minute, hour, day, or custom seconds
        'algorithm'  => env('RATE_LIMIT_DEFAULT_ALGORITHM', 'sliding_window'), // sliding_window, token_bucket
        'key'        => 'ip', // ip, user, api_key, or callable
    ],

    // Named policies (referenced by #[RateLimit(policy: 'name')])
    'policies' => [
        'api' => [
            'requests'  => 60,
            'window'    => 'minute',
            'algorithm' => 'token_bucket',
            'key'       => 'api_key',
        ],
        'strict' => [
            'requests'  => 10,
            'window'    => 'minute',
            'algorithm' => 'sliding_window',
            'key'       => 'user',
        ],
        'login' => [
            'requests'  => 5,
            'window'    => 'minute',
            'algorithm' => 'sliding_window',
            'key'       => 'ip',
        ],
        'register' => [
            'requests'  => 3,
            'window'    => 'hour',
            'algorithm' => 'sliding_window',
            'key'       => 'ip',
        ],
    ],

    // Middleware group policies
    'groups' => [
        'api' => 'api',
        'web' => null,
    ],

    // Whitelist (CIDR supported)
    'whitelist' => [
        '::1',
    ],

    // Redis connection
    'redis' => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'port'     => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', null),
        'database' => env('REDIS_DATABASE', 0),
        'prefix'   => 'ratelimit:',
        'timeout'  => 2.0,
        'read_timeout' => 2.0,
    ],

    // Response customization
    'response' => [
        'status_code' => 429,
        'message'     => 'Too Many Requests',
        'json_format' => true,
        // Not read by the middleware: Retry-After is always emitted with the
        // X-RateLimit-* set. Listed here because older configs may carry it.
        'include_retry_after_header' => true,
    ],

    // Storage driver
    'driver' => env('RATE_LIMIT_DRIVER', 'redis'),
];
```

### Environment Variables

```bash
RATE_LIMIT_ENABLED=true
RATE_LIMIT_DEFAULT_REQUESTS=100
RATE_LIMIT_DEFAULT_WINDOW=minute
RATE_LIMIT_DEFAULT_ALGORITHM=sliding_window
RATE_LIMIT_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
```

## Usage

### Basic Attribute

```php
use Phue\Modules\RateLimit\Attribute\RateLimit;

#[Route('/api/users', methods: ['GET'])]
#[RateLimit(60, 'minute')]  // 60 requests per minute
public function index(): Response
{
    // ...
}
```

### Named Parameters

```php
#[RateLimit(requests: 100, window: 'hour', key: 'user')]
public function show(int $id): Response
{
    // ...
}
```

### Named Policy Reference

```php
#[RateLimit(policy: 'api')]  // Uses config/rate-limit.php policies.api
public function apiEndpoint(): Response
{
    // ...
}
```

### Controller-Level (applies to all methods)

```php
#[RateLimit(requests: 1000, window: 'hour', scope: 'controller')]
class ApiController extends Controller
{
    // All methods in this controller share the limit
}
```

### Method-Level Override

```php
#[RateLimit(requests: 100, window: 'minute')]
class ApiController extends Controller
{
    #[RateLimit(requests: 10, window: 'minute')]  // Overrides controller
    public function sensitive(): Response
    {
        // ...
    }
}
```

### Shorthand Factories

```php
// Policy reference
#[RateLimit::policy('strict')]

// Inline config
#[RateLimit::inline(10, 'minute', 'user', 'sliding_window', 'route')]
```

## Key Resolvers

| Resolver | Description | Key Format |
|----------|-------------|------------|
| `ip` | Client IP address | the bare IP, e.g. `203.0.113.7` |
| `user` | Authenticated user ID | `user_123` |
| `api_key` | X-API-Key or Bearer token | `apikey_<sha256>` |
| callable | Custom function | whatever it returns |

A **callable** resolver cannot be written inside `#[RateLimit(...)]` — PHP
attribute arguments are constant expressions, so a closure there is a compile
error. Declare it where a closure *can* live: a named policy or the `default`
policy in `config/rate-limit.php`:

```php
// config/rate-limit.php
'policies' => [
    'tenant' => [
        'requests' => 60,
        'window'   => 'minute',
        'key'      => static fn (Request $r): string => 'tenant_' . ($r->getHeader('X-Tenant') ?? 'none'),
    ],
],
```

```php
#[RateLimit(policy: 'tenant')]   // the callable key resolver comes from config
public function index(): Response { /* ... */ }
```

## Algorithms

### Sliding Window (Default)

Accurate rate limiting using Redis sorted sets. Each request adds a timestamp entry; expired entries are removed automatically.

- **Precision**: Per-request accuracy
- **Memory**: O(requests) per key
- **Best for**: General API rate limiting

```php
#[RateLimit(requests: 100, window: 'minute', algorithm: 'sliding_window')]
```

### Token Bucket

Allows bursts up to bucket capacity, then enforces steady rate. Uses Redis hash with atomic Lua script.

- **Burst handling**: Yes (up to capacity)
- **Smooth rate**: Tokens refill continuously
- **Best for**: APIs needing burst tolerance

```php
#[RateLimit(requests: 100, window: 'minute', algorithm: 'token_bucket')]
// 100 tokens, refills at 100/60 per second
```

## Policy Resolution Cascade

Policies are merged in order (later overrides earlier):

1. **Global default** (`config/rate-limit.php` → `default`)
2. **Middleware group** (`groups` config, e.g., `'api' => 'api'`)
3. **Controller attribute** (`#[RateLimit]` on class)
4. **Method attribute** (`#[RateLimit]` on method) — highest priority

```php
// Config: default=100/min, groups.api=api(60/min)
// Controller: #[RateLimit(200, 'hour')]
// Method: #[RateLimit(policy: 'strict')]  // 10/min wins
```

> **The group layer is currently dormant in the web pipeline.** The middleware
> resolves the policy from the matched route's handler, and a route does not
> record which middleware group registered it — `getMiddlewareGroup()` returns
> `null`, so steps 1, 3 and 4 are what actually merge today. `groups` stays in
> the config as the declaration a future route-metadata layer will feed; a
> group policy is honoured when a caller resolves with a group name (e.g. a
> custom pipeline you wire yourself).

## Response Headers

Successful responses include:

```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 99
X-RateLimit-Reset: 1791645262
```

Rate limited responses (429) include:

```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1791645262
Retry-After: 45
```

JSON body:
```json
{
    "error": "Too Many Requests",
    "retry_after": 45,
    "limit": 100,
    "reset": 1791645262
}
```

## Whitelist

IPs/CIDRs bypass rate limiting entirely:

```php
'whitelist' => [
    '127.0.0.1',
    '::1',
    '10.0.0.0/8',
    '192.168.1.0/24',
],
```

Unlike `app.trustedProxies` (exact match, see [Core §9](Core.md)), this list
**does** speak CIDR for IPv4; an exact entry without `/` matches verbatim
(`::1` works this way). IPv6 CIDR is not evaluated.

## Proxy trust (X-Forwarded-For)

Behind a reverse proxy the client identity is only as trustworthy as the hop
that tells you so, and the middleware applies one gate — the same one
`SecurityMiddleware` applies to `X-Forwarded-Proto` (see
[Core §9](Core.md#security-headers-securitymiddleware)):

| `app.trustedProxies` | Connecting peer (REMOTE_ADDR) | Header honoured |
|---|---|---|
| `[]` (default) | anything | **no** — the bucket binds to REMOTE_ADDR |
| `['10.0.0.1']` | `10.0.0.1` | yes — first hop of `X-Forwarded-For` |
| `['10.0.0.1']` | `203.0.113.9` | **no** — falls back to REMOTE_ADDR |
| `['*']` | anything | yes — every peer is a trusted proxy |

The rule: **a forwarded header is honoured only when the peer we can see is
itself a trusted proxy.** An untrusted peer's `X-Forwarded-For` used to be
honoured wholesale whenever the list was non-empty — one fake IP per request
meant a fresh bucket per request, and the per-IP limit never bound. The failure
mode is fail-closed by design: a peer that fails the gate is measured by its
real address, not ignored.

## Console Command

```bash
# Clear all rate limit data
php bin/phue rate-limit:clear

# Clear specific key
php bin/phue rate-limit:clear --key=ip_192.168.1.1
php bin/phue rate-limit:clear -k ip_192.168.1.1
```

## Testing

### Unit Tests

```php
use Phue\Modules\RateLimit\Storage\RedisRateLimiter;
use Phue\Modules\RateLimit\Policy\RateLimitPolicy;

$limiter = new RedisRateLimiter($redis, 'test:');
$policy = RateLimitPolicy::fromArray('test', [
    'requests' => 5,
    'window' => 'minute',
    'algorithm' => 'sliding_window',
]);

// Allow up to 5
for ($i = 1; $i <= 5; $i++) {
    $result = $limiter->check('test_key', $policy);
    $this->assertTrue($result->allowed);
}

// 6th blocked
$result = $limiter->check('test_key', $policy);
$this->assertFalse($result->allowed);
```

### Integration Tests

Requires Redis (`PHUE_TEST_REDIS_HOST`). Run them through the canonical
runner, the same way every other suite runs:

```bash
docker exec phue_app php bin/phue_test --impact tests/Modules/RateLimit
# or the redis-tagged suite alone:
docker exec phue_app php bin/phue_test --group=redis
```

The middleware suite (`tests/Modules/RateLimit/RateLimitMiddlewareTest.php`)
needs no Redis: it drives a scripted limiter, so the enforcement decisions —
including the proxy-trust rows above — run in milliseconds anywhere.

## Architecture

### Core Classes

| Class | Responsibility |
|-------|----------------|
| `RateLimitModule` | Module bootstrap, bindings, middleware registration |
| `RateLimitMiddleware` | Request interception, policy resolution, limit checking |
| `PolicyResolver` | Cascade resolution: default → group → controller → method |
| `RateLimitPolicy` | Immutable policy configuration |
| `RateLimit` | Attribute definition |
| `RedisRateLimiter` | Redis-backed storage with Lua scripts |
| `RateLimiterInterface` | Storage abstraction |
| `RateLimitResult` | Check result with headers |
| `RateLimitExceededException` | Exception for custom handling |

### Redis Key Schema

```
ratelimit:{key}          # Sliding window (ZSET)
ratelimit:{key}:bucket   # Token bucket (HASH)
```

TTL = window + buffer (1 second for sliding, capacity/refill + 10s for token bucket).

### Lua Scripts

All Redis operations use atomic Lua scripts (`EVALSHA`) for:
- Sliding window: `ZREMRANGEBYSCORE` + `ZCARD` + `ZADD`
- Token bucket: `HMGET` + `HMSET` with refill calculation
- Peek operations (read without increment)

## Best Practices

1. **Use named policies** for consistency across routes
2. **Set appropriate windows**: `minute` for APIs, `hour`/`day` for auth
3. **Use token_bucket** for burst-tolerant endpoints
4. **Configure whitelist** for internal services/health checks
5. **Monitor Redis memory** — each key stores request timestamps
6. **Test with realistic traffic** — simulate bursts and sustained load

## Troubleshooting

### Rate limit not triggering
- Check `config/rate-limit.php` → `enabled: true`
- Verify middleware registered: `RateLimitMiddleware` in stack
- Ensure attribute on correct class/method
- Check Redis connection

### All requests blocked
- Verify Redis is accessible
- Check `whitelist` doesn't include client IP
- Verify `default` policy has reasonable limits

### Headers missing
- Ensure middleware runs before response
- `Retry-After` is always emitted with the `X-RateLimit-*` set — it comes from
  the result's own `toHeaders()`, not from a config toggle

### Performance
- Use connection pooling (Redis singleton)
- Monitor Lua script cache (`SCRIPT FLUSH` clears it)
- Consider `token_bucket` for high-traffic endpoints

## Migration from Other Systems

### Laravel Throttle
```php
// Laravel: Throttle::limit(60)->everyMinute()
// Phue:
#[RateLimit(60, 'minute')]
```

### Symfony RateLimiter
```php
// Symfony: $limiter->consume(1)
// Phue: Automatic via middleware
```

### Custom Middleware
Replace custom middleware with `#[RateLimit]` attributes.