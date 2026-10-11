# Server Adapters, SSR & DevTools

How Phue runs on different server runtimes: classic FPM, persistent Swoole and
RoadRunner — plus the Bun SSR sidecar and the DevTools instrumentation.

Related guides: [Core](Core.md), [CLI](CLI.md), [Events](Events.md) (SSE).

---

## 1. Server adapters (`Phue\Modules\Server`)

| Adapter | File | Runtime |
|---|---|---|
| `ServerAdapterInterface` | `Modules/Server/ServerAdapterInterface.php` | Universal lifecycle contract: `boot()`, `handleRequest()`, `run()`, `shutdown()` |
| `FpmAdapter` | `Modules/Server/FpmAdapter.php` | Standard PHP-FPM / CGI — stateless single-request execution |
| `RoadRunnerAdapter` | `Modules/Server/RoadRunnerAdapter.php` | Persistent Go server worker loop using PSR-7 conversion |
| `SwooleAdapter` | `Modules/Server/SwooleAdapter.php` | Async coroutine HTTP server (`Swoole\Http\Server`) |

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

## 3. DevTools (`Phue\Modules\DevTool`)

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
| `LiveReloadWatcher` | `Modules/DevTool/LiveReloadWatcher.php` | Detects component changes on a timer and announces them |
| `LiveReloadState` | `Modules/DevTool/LiveReloadState.php` | Holds what changed until every poller has seen it |
| `LiveReloadRoute` | `Modules/DevTool/LiveReloadRoute.php` | `GET /_phue/live-reload` — the polling fallback |
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

    // How often the server looks for component changes. 0 disables live reload.
    // See §6.
    'live_reload_interval_ms' => 1000,

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

## 4. Live reload

Save a `.phue` file, and the open tab reloads. No build step, no manual refresh.

Live reload is gated with the rest of DevTools — both `app.debug` **and**
`app.devTool` — because it reports when the application's own source files move,
which is not something a deployed site should be able to ask about.

```ini
# .env
APP_DEBUG=true
APP_DEV_TOOL=true
```

### How live reload behaves without realtime

Live reload does **not** depend on the Websocket module. Realtime makes it
instant; polling is what it falls back to, and on a server that cannot hold a
socket open it is the whole mechanism.

| Configuration | What happens |
|---|---|
| Both modules, `APP_DEBUG` + `APP_DEV_TOOL` + `APP_WEBSOCKET` | A file save is announced on the `dev` endpoint and the tab reloads in about one watch interval |
| `websocket` module **absent** | The watcher has no broadcaster and records the change instead; the toolbar polls `/_phue/live-reload` |
| `websocket` listed but `APP_WEBSOCKET` **off** | Identical to absent — the module returns before wiring anything, and the toolbar cannot tell the difference |
| `devtools` module **absent**, or `APP_DEV_TOOL` off | No toolbar and no live reload. Your own WebSocket endpoints are unaffected |
| `APP_DEBUG` **off** | Same as above; the `dev` endpoint is not created either |
| `serve:php` instead of `serve` | Polling only. No adapter there completes a handshake |

> The optional dependency is a real one, not a courtesy. `LiveReloadWatcher` takes
> a `?Broadcast`, and it asks the realtime module whether it actually ran
> (`phue.websocket.active`) rather than asking whether `Broadcast` exists —
> `Container::has()` answers true for any autowireable class, so that question was
> satisfied by a project with no realtime module at all, and `get()` then built a
> broadcaster with a null resolver. Live reload stayed correct only because the
> null call threw and the exception fell into the polling path.

### The three pieces

| Piece | Responsibility |
|---|---|
| `LiveReloadWatcher` | Detects changes and announces them |
| `LiveReloadState` | Holds what changed until every poller has seen it |
| `LiveReloadRoute` | `GET /_phue/live-reload` — the polling fallback |
| `DebugToolbar` | The browser client: WebSocket first, polling as standby |

### Detection is a timer, not a side effect of a request

This is the part that is easy to get wrong, and it was wrong here first.

Change detection originally lived inside a request, which is free — and stops
working the moment detection is supposed to be request-independent. A developer
saves a file, the browser sits idle because the realtime channel means it has no
reason to poll, and nothing in the process ever looks at the filesystem. The
reload then depends on the developer happening to click something.

So detection moved to a `Swoole\Timer` registered per worker:

```php
// DevToolsModule::watchForChanges()
$server->on('WorkerStart', function ($server) use ($intervalMs, $container) {
    \Swoole\Timer::tick($intervalMs, function () use ($container) {
        $container->get(LiveReloadWatcher::class)->tick();
    });
});
```

**Every worker watches, deliberately.** Each holds different WebSocket
connections with different subscriptions, and a change found in one cannot be
pushed from another. A single shared watcher would leave every tab on every other
worker waiting for an event nobody sends.

The cost is one directory scan per interval per worker — which is what every
open tab used to pay on its own. The scan is not the expensive part; rebuilding
components is, and that still happens lazily on the next request.

### Delivery

The watcher announces on the `dev` endpoint of the [WebSocket](WebSocket.md)
module, which is the one endpoint that does not authenticate — a reload channel
carries no user data, and requiring a login would only mean live reload quietly
stops working for whoever is not signed in.

```php
$broadcast->for(WebsocketConfig::DEV_ENDPOINT)
    ->to(LiveReloadRoute::ENDPOINT_TOPIC, 'components.changed', ['changed' => $changed]);
```

The same change is recorded in `LiveReloadState` for the polling fallback. That
is not a belt-and-braces gesture: **a server without a timer has nobody else
looking**, and would otherwise answer "nothing changed" to every poll forever. The
polling endpoint therefore ticks the watcher itself. Safe to do both, because
`ChangeTracker` moves its baseline as it answers — whichever gets there first
reports the change and the other finds nothing. They cannot both claim it, and
they cannot both miss it.

The pending set is **peeked, not drained**. Draining on read meant the first tab
to poll took the change and every other tab got nothing, so a developer with the
page open in two windows watched one of them never reload. Clearing happens on
the following tick instead: every tab gets a full interval to notice, and a page
that already reloaded does not see it again and loop.

### In the browser

```js
socket.onopen  = () => socket.send(JSON.stringify({ act: 'sub', ch: '_phue:components' }));
socket.onmessage = (e) => { if (JSON.parse(e.data).ev === 'components.changed') reload(); };
socket.onclose = () => setTimeout(retry, 1000);          // reconnect is the client's job
```

Polling runs only while no socket has connected. A server that cannot hold one —
anything but Swoole — keeps working, because the client polls while `pushed` is
false.

> The script the toolbar emits is handed to a real JavaScript parser by
> `DebugToolbarScriptSyntaxTest`. A syntax error in it is invisible to the server:
> the request succeeds, the response is 200, and the browser throws before the
> first statement runs — every toolbar feature dies at once and nothing in the
> logs says why.

### Configuration

```php
// config/devtool.php
return [
    // How often the *server* looks for changes, in milliseconds.
    // 1000 by default. 0 switches the watcher off entirely, which is what an
    // application that moved change detection into its own build step wants:
    // nothing polls, nothing ticks, and the toolbar simply never reloads.
    'live_reload_interval_ms' => 1000,
];
```

Two intervals, deliberately distinct. This one is the server's scan. The
toolbar's own poll interval is separate and defaults to `0` — meaning "only poll
while waiting for a socket to connect".

### What it watches

Every registered component directory, discovered from the loaders themselves
rather than from a list kept anywhere else — a second list of directories is a
list that starts drifting the first time a module registers a loader of its own.
In practice: your `.phue` component directories. PHP under `App/` is **not**
watched; a change there is picked up by the next request.

## 5. Performance & benchmarks

The framework's performance posture:

- **Persistent runtimes** — request isolation via the scoped container and a
  single `flushScope()` per request; no `gc_collect_cycles()` on the hot path.
- **Hot paths without reflection** — the router and the DI container keep their
  resolution work off the request path where they can, so a warm worker does not
  re-reflect on every request.
- **Automated benchmarks** — `tests/Benchmark/BenchmarkRunner.php` measures
  container DI resolution latency (nanoseconds per operation) and full
  end-to-end kernel throughput (`benchmarkKernelThroughput($app)`, in requests
  per second).
