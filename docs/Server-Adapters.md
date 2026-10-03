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

Developer instrumentation, gated so it never runs in production.

The module answers one question well: **where did this request's time actually
go?** It does that by decorating the things that do the work, then rendering
the result as a collapsed bar that expands into a tabbed report.

| Component | File | Responsibility |
|---|---|---|
| `DevToolsModule` | `Modules/DevTool/DevToolsModule.php` | Binds the collector as a singleton and installs the decorators |
| `DevToolMiddleware` | `Modules/DevTool/DevToolMiddleware.php` | Global middleware — brackets the request with the `pipeline` span and injects the toolbar |
| `DebugToolbar` | `Modules/DevTool/DebugToolbar.php` | Renders the collapsed bar and the tabbed report |
| `RequestProfiler` | `Modules/DevTool/RequestProfiler.php` | Per-request façade: duration, memory, request metadata, diagnostics |
| `Stopwatch` | `Modules/DevTool/Stopwatch.php` | Nested named-span collector with per-label aggregates |
| `ProfilingSsrClient` | `Modules/DevTool/ProfilingSsrClient.php` | `SsrClient` decorator — every sidecar round trip |
| `ProfilingComponentLoader` | `Modules/DevTool/ProfilingComponentLoader.php` | `PhueComponentLoader` subclass — builds, resolutions and structure reads |
| `ProfilingComponentLoaderRegistry` | `Modules/DevTool/ProfilingComponentLoaderRegistry.php` | `ComponentLoaderRegistry` subclass — the funnel for renderer-driven resolution |
| `ProfilingDriver` | `Modules/DevTool/ProfilingDriver.php` | `DriverInterface` decorator — statements, fetches and transaction control |
| `ProfilingConnection` | `Modules/DevTool/ProfilingConnection.php` | `Connection` subclass that installs the profiling driver |
| `Dumper` | `Modules/DevTool/Dumper.php` | Variable dumping |

Two read-only seams were added to the core so these decorators could exist
without re-deriving framework wiring: `ComponentLoaderRegistry::getLoaders()`
and `PhueComponentLoader::getComponentsDir()`.

### Gating

The module returns early unless **both** flags are set — the toolbar leaks
request internals into the page, so a non-debug context must be unreachable
regardless of how the flags are combined:

```ini
# .env
APP_DEBUG=true
APP_DEV_TOOL=true
```

Every instrumentation step is individually guarded. Resolving a service it
decorates can legitimately fail — an application with no component directory,
or with no database — and **a missing measurement must never be the reason a
page fails**. A skipped instrument is recorded as a *diagnostic* and shown in
the report rather than swallowed:

> Diagnostics deliberately survive `RequestProfiler::start()`. They are a fact
> about the process, not about a request; reporting one in the per-request
> context would erase it on the next request — exactly when you are still
> wondering why a measurement is missing.

### Configuration — `config/devtool.php`

```php
return [
    'timeline' => true,   // per-operation rollup
    'spans'    => true,   // slowest calls, by total and by exclusive time
    'queries'  => true,   // database statements
    'request'  => true,   // request/response metadata
    'json'     => true,   // raw report, copy-pasteable

    'sql_literals' => 'redact',  // 'redact' | 'raw'
    'theme' => 'auto',           // 'auto' | 'light' | 'dark'

    // Rendered before the browser-side theme resolver runs; the server cannot know the
    // resolved client theme while the application preference is 'system'.
    'theme_fallback' => 'dark',
    'theme_storage_key' => 'phue-theme',
];
```

A panel with nothing to show is **omitted**, not rendered as a stub: an empty
section in a debugging tool is indistinguishable from a broken one.

### Reading the report

| Panel | Answers |
|---|---|
| **Timeline** | Which *operation* is hot — per-label rollup, heaviest first, filterable |
| **Slowest calls** | Which exact *call* was slow, with its metadata |
| **Slowest self time** | The same calls ranked by exclusive time |
| **Database** | Every statement, its bindings, rows and cost |
| **Request** | Method, URI, status, response size |
| **Raw report** | The whole thing as JSON, for an issue or a benchmark |

Three rules make the report trustworthy rather than merely pretty:

- **Aggregates are keyed by label only.** Keying by metadata would shatter one
  hot operation into one bucket per component name, which defeats the rollup.
- **A panel never grows with the event count.** A hot page can enter the
  component loader thousands of times per request; rendering one row per call
  would inject more HTML than the page being profiled. Lists are capped
  (`MAX_AGGREGATE_ROWS`, `MAX_SPAN_ROWS`, `MAX_QUERY_ROWS`) and the collector
  itself is bounded (`MAX_SPAN_DEPTH`, `MAX_SPANS`) so a memory-resident worker
  cannot grow without limit.
- **Exclusive time, not total.** A recursive parent's total is dominated by its
  descendants, so ranking by total blames the wrong operation.

### `pipeline`

The span every other span hangs off, wrapping the rest of the request. Its
`duration_ms` is the yardstick the Share bars are scaled against, and its
**`exclusive_ms` is the most useful number in the report**: the work that
happened *outside* every instrumented operation — routing, the renderer
assembling HTML, the response object. A large remainder means missing
instrumentation.

It is not the HTTP total. The middleware is appended **last** to the global
stack, so security headers, the error handler, socket I/O and the response
write all fall outside it. The toolbar's own render cost is excluded too, and
reported as the `X-Phue-Debug-Toolbar-Ms` header instead — a span opened
around it would make the panel permanently report an unbalanced stack.

### Per-request isolation

`Container::get()` memoizes autowired instances, so under Swoole the profiler
is effectively application-scoped. `RequestProfiler::start()` therefore resets
the spans and the memory baseline: without that, request N+1 reports an
ever-growing pile of request N's work.

### Theming

The toolbar renders in the **opposite** theme from the application, resolved in
the browser and following the app's own toggle. Bootstrap 5.3 inherits
`data-bs-theme`, so setting it on the toolbar root re-themes every nested table,
badge and alert with no extra CSS. Deliberately avoids theme-fixed utilities
(`btn-dark`, `text-light`, `table-dark`) — they ignore the attribute and would
defeat the whole mechanism.

Panel internals reuse the Bootstrap the framework already serves as a global
stylesheet, so they cost zero extra bytes; only the rules Bootstrap has no class
for (the fixed bar, its z-index ceiling, the collapse) are inlined. The tab and
filter behaviour is vanilla JS rather than `bootstrap.Tab`/`Collapse`: a debug
toolbar that silently stops working when an application trims its JS bundle is
a bad trade for a few saved lines.

### SQL and data safety

The framework prepares and executes separately, so a prepared statement
carries only placeholders — bound values never reach the report. The remaining
leak path is a statement assembled by interpolation, so quoted literals and
comments are redacted by default (`sql_literals => 'redact'`), and the panel
says so rather than silently showing shorter text:

```
SELECT * FROM users WHERE tenant = 'acme' AND status = 'active' LIMIT 10
→  SELECT * FROM users WHERE tenant = ? AND status = ? LIMIT 10
```

Set `'raw'` only where the page is unreachable by anyone who should not see the
schema or the data.

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
