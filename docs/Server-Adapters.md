# Server Adapters, SSR & the AOT Compiler

How Phue runs on different server runtimes: classic FPM, persistent Swoole and
RoadRunner, and WASM/edge — plus the Bun SSR sidecar, the hybrid AOT compiler,
and the DevTools instrumentation.

Related guides: [Core](Core.md), [CLI](CLI.md), [Events](Events.md) (SSE).

---

## 1. Server adapters (`Phue\Modules\Server`)

| Adapter | File | Runtime |
|---|---|---|
| `ServerAdapterInterface` | `Modules/Server/ServerAdapterInterface.php` | Universal lifecycle contract: `boot()`, `handleRequest()`, `run()`, `shutdown()` |
| `FpmAdapter` | `Modules/Server/FpmAdapter.php` | Standard PHP-FPM / CGI — stateless single-request execution |
| `RoadRunnerAdapter` | `Modules/Server/RoadRunnerAdapter.php` | Persistent Go server worker loop using PSR-7 conversion |
| `SwooleAdapter` | `Modules/Server/SwooleAdapter.php` | Async coroutine HTTP server (`Swoole\Http\Server`) |
| `WasmAdapter` | `Modules/Server/WasmAdapter.php` | WebAssembly / Cloudflare edge runtimes |

> Historical note: these used to live in `Phue\Server` (`src/Server/`). After
> the modulezied restructuring they are `Phue\Modules\Server`
> (`src/Modules/Server/`).

### Running persistent adapters

```php
use Phue\Core\Application;
use Phue\Modules\Server\RoadRunnerAdapter;

$app = new Application(__DIR__ . '/..');

// Boot RoadRunner persistent adapter
$adapter = new RoadRunnerAdapter();
$adapter->boot($app);
$adapter->run();
```

### Zero state leakage — the single flush point

`Application::handle()` is the **single flush point**: its `finally` block calls
`$container->flushScope()` exactly once per request (see [Core §4](Core.md)).
Persistent worker adapters therefore perform *no* flushing of their own — they
delegate request work to the app and rely on the app to purge request-scoped
services between iterations. This is the framework's state-isolation guarantee,
protected by the test suite (`tests/Server/PersistentServerAdapterTest.php`).

### The Swoole serve command

The `ServerModule` registers the `serve` command (via `registerCommands()`):

```bash
./phue serve --port=8080 --host=127.0.0.1 --worker-num=4
```

The Swoole server provides persistent connections, coroutine support, and
better throughput for production environments. It automatically maintains
persistent database connections and relies on the app's per-request flush.

---

## 2. SSR sidecar (Bun)

Phue can server-render `.phue` components through a Bun sidecar for true
SSR + hydration.

### How it's wired

The `SsrEngine` binding (in `Application::registerCoreBindings()`) resolves:

- `NullSsrEngine` when `ssr.enabled` is `false` — graceful degradation, the
  app renders client-side only.
- Otherwise a `BunSsrEngine` wrapping a `UnixSsrClient`:

| Config key | Default | Meaning |
|---|---|---|
| `ssr.enabled` | `true` | Master switch (`config/ssr.php`) |
| `ssr.socket` | `"\0phue-ssr"` | Unix socket name |
| `ssr.connect_timeout_ms` | `200` | Connect timeout |
| `ssr.timeout_ms` | `500` | Request timeout |
| `ssr.host_header` | `phue-ssr` | Host header sent to the sidecar |
| `ssr.max_payload_kb` | `512` | Max payload size |

### Running the sidecar

The `ssr/` directory ships `server.ts` (the Bun server), `package.json`, and
`tsconfig.json`. With the Docker wrapper, `./phue-docker start` launches both
servers:

```bash
./phue-docker start
# [PHUE SERVE] ./phue serve
# [BUN SSR]    (cd /app/ssr && bun server.ts)
```

Locally: `cd ssr && bun server.ts` — the sidecar listens on the configured Unix
socket and renders component HTML on request.

---

## 3. The hybrid AOT compiler (`Phue\Modules\Compiler`)

Phue supports ahead-of-time (AOT) static compilation for native C++
translation, with graceful fallback when AOT isn't possible.

| Component | File | Responsibility |
|---|---|---|
| `WiringGenerator` | `Modules/Compiler/WiringGenerator.php` | Build-phase scanner emitting zero-reflection static dependency & routing maps |
| `AotCompatibilityChecker` | `Modules/Compiler/AotCompatibilityChecker.php` | Evaluates classes for AOT compliance |
| `AotCompatibilityReport` | `Modules/Compiler/AotCompatibilityReport.php` | Report value object |
| `FallbackStrategy` | `Modules/Compiler/FallbackStrategy.php` | Resolves execution mode with graceful fallback |

> Historical note: these used to live in `Phue\Compiler` (`src/Compiler/`).
> After the modulezied restructuring they are `Phue\Modules\Compiler`
> (`src/Modules/Compiler/`).

### Generating static wiring artifacts

```php
use Phue\Modules\Compiler\WiringGenerator;

$generator = new WiringGenerator(
    scanDirectories: ['src', 'App'],
    outputDir: 'build'
);

// Emits build/wiring.php and build/wiring.cpp
$result = $generator->generate();
echo "Generated static wiring for {$result['count']} classes.\n";
```

- `build/wiring.php` — optimized PHP return array.
- `build/wiring.cpp` — native C++ struct array for TypePHP native compilation.

### AOT compatibility rules

`AotCompatibilityChecker` inspects classes for forbidden dynamic constructs:
`eval()`, `extract()`, `$$var`, dynamic `call_user_func_array`. The framework's
non-negotiables: stateless modules, declarative-only global scope, no `eval()`,
no dynamic class definitions, `main()`-based boot, hot paths with native types.
Anything new must pass the checker or degrade gracefully through
`FallbackStrategy` (which resolves `AOT_NATIVE` → `PHP_JIT` →
`PHP_INTERPRETER`) — the framework never crashes on AOT failure.

---

## 4. WASM / edge infrastructure

### WasmAdapter

Stateless adapter (no `Container` in its constructor) for executing Phue inside
WebAssembly contexts — it transmutes WASM environment arrays into
`Phue\Core\Request` objects and returns standard status/headers/body arrays.
Scope flushing lives in `Application::handle()`, not here.

```php
use Phue\Modules\Server\WasmAdapter;
use Phue\Core\Cache\EdgeKvCache;

// Memory-backed KV cache for read-only edge filesystems
$cache = new EdgeKvCache();

$adapter = new WasmAdapter();

$result = $adapter->handleWasmRequest([
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/api/edge',
    'HEADERS' => ['Accept' => 'application/json'],
], fn($request) => $app->handle($request));
```

### Edge parity guarantees

Edge runtimes have no raw TCP: the HTTP-based DB drivers
([Turso](Database.md), [Neon](Database.md)) and `EdgeKvCache`
(`Phue\Core\Cache\EdgeKvCache` — read-only FS, memory-backed KV) must keep
working when PDO is unavailable. Protected by the test suite
(`tests/Cache/EdgeKvCacheTest.php`, `tests/Core/Database/EdgeDriverTest.php`).

---

## 5. DevTools (`Phue\Modules\DevTool`)

Developer instrumentation, gated so it never runs in production:

| Component | File | Responsibility |
|---|---|---|
| `DevToolsModule` | `Modules/DevTool/DevToolsModule.php` | Bootstrapping module — attaches the debug toolbar middleware |
| `DebugToolbar` | `Modules/DevTool/DebugToolbar.php` | Debug toolbar rendering |
| `DevToolMiddleware` | `Modules/DevTool/DevToolMiddleware.php` | Global middleware injecting the toolbar |
| `Dumper` | `Modules/DevTool/Dumper.php` | Variable dumping |
| `RequestProfiler` | `Modules/DevTool/RequestProfiler.php` | Per-request profiling |
| `Stopwatch` | `Modules/DevTool/Stopwatch.php` | Timing helper |

### Gating

`DevToolsModule::bindings()` checks both `app.debug` **and** `app.devTool`
before attaching the middleware:

```php
if ($debug && $devTool) {
    $this->container->get(\Phue\Core\Application::class)
        ->use(\Phue\Modules\DevTool\DevToolMiddleware::class);
}
```

Enable locally via `.env`:

```ini
APP_DEBUG=true
APP_DEV_TOOL=true
```

With both `true`, the toolbar is attached as a global middleware; in production
(neither flag) the module does nothing.

---

## 6. Performance & benchmarks

The framework's performance posture:

- **Persistent runtimes** — request isolation via the scoped container and a
  single `flushScope()` per request; no `gc_collect_cycles()` on the hot path.
- **AOT-forward design** — hot paths with native types, no reflection in the
  request path (the `WiringGenerator` emits zero-reflection maps).
- **Automated benchmarks** — `tests/Benchmark/BenchmarkRunner.php` measures
  container DI resolution latency (nanoseconds per operation) and full
  end-to-end kernel throughput (`benchmarkKernelThroughput($app)`, in requests
  per second).
