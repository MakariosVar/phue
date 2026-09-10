# Phue Core — Architecture & Usage

This document describes the `Phue\Core` components that form the foundation of the
Phue framework. The goal is to explain *how* each
piece works, *why* it was designed the way it was, and *how* you actually use it.

---

## 1. Overview

The `Core` namespace holds the small set of building blocks every Phue application
relies on. At this stage they are intentionally small and testable; larger concerns
(controllers, modules, database) build on top of them later.

| Component | File | Responsibility |
|---|---|---|
| `Container` | `Container.php` | Dependency injection, service resolution, autowiring |
| `Request` | `Request.php` | Immutable representation of an incoming HTTP request |
| `Response` | `Response.php` | Representation of an outgoing HTTP response |
| `EventDispatcher` | `EventDispatcher.php` | Decoupled communication via class-based events |
| `Router` | `Routing/Router.php` | Maps an HTTP method + URI to a handler, supports static-first matching |
| `RouteMatch` | `Routing/RouteMatch.php` | Value object holding a matched handler, params, and route middleware |
| `Route` | `Routing/Attribute/Route.php` | Attribute for declaring routes on controllers and methods |
| `Middleware` | `Routing/Attribute/Middleware.php` | Attribute for attaching middleware to classes or methods |
| `AttributeRouteLoader` | `Routing/Loader/AttributeRouteLoader.php` | Reflection loader & directory scanner for controller attributes |
| `Application` | `Application.php` | Composes everything: global & route middleware pipeline → router → handler → response |
| `Connection` | `Database/Connection.php` | Connection Manager & Driver Factory |
| `DriverInterface` | `Database/Drivers/Abstract/DriverInterface.php` | Universal database contract for PDO and HTTP Edge drivers |
| `QueryBuilder` | `Database/QueryBuilder.php` | Fluent SQL query builder with parameter binding |
| `BaseActiveRecord` | `Database/ActiveRecord/BaseActiveRecord.php` | Abstract Active Record model with persistence & dirty state tracking |
| `ActiveQuery` | `Database/ActiveRecord/ActiveQuery.php` | Model-aware QueryBuilder returning hydrated model instances |
| `Console\Application` | `Console/Application.php` | CLI Application runner, command registry, and ANSI help menu |
| `CommandInterface` | `Console/CommandInterface.php` | Interface contract for all CLI commands |
| `Migration` | `Database/Migration.php` | Abstract base class for explicit database migrations (`up`/`down`) |
| `Migrator` | `Database/Migrator.php` | Migration engine managing tracking table, batching, and transaction isolation |
| `SqlIdentifier` | `Database/SqlIdentifier.php` | SQL identifier validation & dialect-aware quoting utility |
| `Schii\SchemaRegistry` | `Schii/Schema/SchemaRegistry.php` | Reflection parser & cache for `#[UI]` and `#[Field]` attributes |
| `Schii\ModelEndpoint` | `Schii/Endpoint/ModelEndpoint.php` | Zero-Controller REST CRUD Endpoint & JSON Schema Protocol handler |
| `Schii\AutoMigrator` | `Schii/Migration/AutoMigrator.php` | Inspects database tables and auto-migrates missing columns and tables based on Schii Schema |
| `Schii\SchemaToSqlConverter` | `Schii/Migration/SchemaToSqlConverter.php` | Converts Schii ModelSchema definitions into dialect-specific SQL DDL statements |
| `IdentityInterface` | `Auth/IdentityInterface.php` | Contract representing an authenticated user identity |
| `AuthManager` | `Auth/AuthManager.php` | Chain-of-responsibility request authenticator & state manager |
| `RbacManager` | `Auth/RbacManager.php` | Role-Based Access Control manager supporting role inheritance & cycle detection |
| `AccessControlMiddleware` | `Auth/Middleware/AccessControlMiddleware.php` | Middleware enforcing permission checks on HTTP requests |
| `AuthorizedModelEndpoint` | `Schii/Endpoint/AuthorizedModelEndpoint.php` | Schii REST CRUD endpoint decorator enforcing dynamic RBAC permissions |
| `ServerAdapterInterface` | `Server/ServerAdapterInterface.php` | Universal contract for web server runtimes (FPM, RoadRunner, Swoole, FrankenPHP) |
| `FpmAdapter` | `Server/FpmAdapter.php` | Standard PHP-FPM / CGI adapter for stateless request execution |
| `RoadRunnerAdapter` | `Server/RoadRunnerAdapter.php` | Persistent RoadRunner Go application server adapter |
| `SwooleAdapter` | `Server/SwooleAdapter.php` | Async Coroutine-based Swoole HTTP Server adapter |
| `WiringGenerator` | `Compiler/WiringGenerator.php` | Static Build-Phase Wiring Generator emitting `build/wiring.php` and `build/wiring.cpp` |
| `AotCompatibilityChecker` | `Compiler/AotCompatibilityChecker.php` | Evaluates PHP classes for TypePHP AOT compilation compliance |
| `FallbackStrategy` | `Compiler/FallbackStrategy.php` | Resolves execution mode (`AOT_NATIVE`, `PHP_JIT`, `PHP_INTERPRETER`) |
| `HttpDriverInterface` | `Database/Drivers/Abstract/HttpDriverInterface.php` | Contract for Serverless/Edge HTTP Database Drivers |
| `TursoDriver` | `Database/Drivers/TursoDriver.php` | Serverless libSQL/SQLite HTTP driver for Turso REST pipeline |
| `NeonDriver` | `Database/Drivers/NeonDriver.php` | Serverless PostgreSQL HTTP driver for Neon REST API |
| `WasmAdapter` | `Server/WasmAdapter.php` | Server Adapter for WebAssembly (WASM) & Cloudflare Edge Runtimes |
| `CacheInterface` | `Cache/CacheInterface.php` | Contract for Phue framework caching engines |
| `EdgeKvCache` | `Cache/EdgeKvCache.php` | In-Memory & Key-Value Cache Driver for Read-Only Edge Filesystems |
| `ComponentCache` | `Cache/ComponentCache.php` | Persistent filesystem caching for component PHP blocks with OPCache optimization |
| `HydrationCache` | `Cache/HydrationCache.php` | JSON-based hydration cache for component state management |
| `VcloakLoader` | `Frontend/VcloakLoader.php` | Vue cloak loader for component hydration state management |
| `EventBusInterface` | `Events/Bus/EventBusInterface.php` | Contract for Phue Real-Time Event Bus Subsystem |
| `InMemoryBusDriver` | `Events/Bus/InMemoryBusDriver.php` | In-Memory Event Bus Driver for testing and local development |
| `RedisStreamDriver` | `Events/Bus/RedisStreamDriver.php` | High-Throughput Redis Streams Event Bus Driver with Consumer Groups |
| `SseResponse` | `Events/Sse/SseResponse.php` | Specialized HTTP Response for Server-Sent Events (SSE) Streaming |
| `SseStreamer` | `Events/Sse/SseStreamer.php` | Server-Sent Events (SSE) Protocol Formatter and Streamer |
| `Broadcastable` | `Events/Attribute/Broadcastable.php` | Attribute marking models for automatic real-time event broadcasting |
| `HealthChecker` | `Core/Health/HealthChecker.php` | Diagnostic health checker evaluating database, Redis, memory, and disk probes |
| `SecurityMiddleware` | `Core/Security/SecurityMiddleware.php` | Production Security Hardening Middleware injecting OWASP defense headers |
| `DeployCommand` | `Core/Console/Commands/DeployCommand.php` | Zero-Downtime Atomic Release Deployment Handler |
| `BenchmarkRunner` | `tests/Benchmark/BenchmarkRunner.php` | Automated Benchmark Suite measuring container latency & HTTP throughput |
| `ComponentRenderer` | `Core/UI/ComponentRenderer.php` | HTML-first rendering lifecycle: component → layout → shell |
| `HtmlShellRenderer` | `Core/Asset/HtmlShellRenderer.php` | Renders the outer HTML5 shell with styles, scripts, state, and layout content |
| `View` | `Core/UI/View.php` | Encapsulates template, state, scripts, and optional server-rendered HTML |
| `PhuePartialRenderer` | `Core/UI/PhuePartialRenderer.php` | Renders HTML-first partial components (e.g. nav) via the ComponentLoaderRegistry |
| `PhNavigation` | `Core/UI/Phui/PhNavigation.phue` | SSR nav partial with boundary-aware active-route matching |
| `SwooleServeCommand` | `Core/Console/Commands/SwooleServeCommand.php` | Command to serve the app through Swoole HTTP server with persistent connections |
| `JsonHydration` | `Core/JsonHydration.php` | JSON-based hydration system for component state management |
| `SpaInterceptor` | `Core/SpaInterceptor.php` | SPA interceptor for client-side navigation without full page reloads |

---

## 2. DI Container (`Container.php`)

The container is the service locator + factory for the application. You bind a
service once, then request it wherever you need it, and the container handles
construction — including automatically resolving constructor dependencies.

### The four key methods

| Method | What it does | When to use it |
|---|---|---|
| `set($id, callable $factory)` | Registers a factory that builds the service | Telling the container *how* to create something |
| `get($id)` | Resolves and **caches** the instance (singleton) | You want the *same* instance every time |
| `make($id)` | Resolves a **fresh** instance, no caching | You need a *new* instance each time |
| `has($id)` | Reports whether a service/class is resolvable | Guarding before a `get()` call |

### Singleton vs fresh

Container philosophy makers a key distinction:

```php
$container->set('db', fn (Container $c) => new DatabaseConnection($c->get('config')));

$a = $container->get('db'); // built & cached
$b = $container->get('db'); // same instance as $a
// $a === $b

$c = $container->make('db'); // brand new instance, not cached
// $a !== $c
```

`get()` implements the **singleton pattern**: after first success, the instance is
stored and reused. `make()` always constructs a new one and never touches the cache.

### Scopes: Application vs Request

For persistent runtimes (Swoole, RoadRunner) the process lives across many requests,
so a plain singleton would leak state between them. The container distinguishes two
scopes:

| Scope | Method | Lifetime | Examples |
|---|---|---|---|
| **Application** | `singleton($id, ?$factory)` | Lives as long as the app | Router, Config, Logger |
| **Request** | `scoped($id, ?$factory)` | One per request, flushed after | Auth user, DB connection, current request |

```php
$container->singleton(Router::class, fn () => new Router());        // app-scoped
$container->scoped(AuthService::class, fn () => new AuthService()); // request-scoped
```

- The optional factory can be omitted; the service is then **autowired**.
- `bind($id, $factory, bool $isScoped)` is the unified low-level registration that
  both `singleton()` and `scoped()` delegate to. It also keeps the scope caches
  consistent if a service is re-registered with a different scope.
- `get()` checks the service's scope and caches it in the matching pool.

> **Default behaviour:** a service resolved with no explicit scope (e.g. via
> autowiring) is treated as **application-scoped**. Services that must not outlive a
> single request (auth, connection) should be declared `scoped()` explicitly.

### Flushing request state (`flushScope()`)

At the end of a request you clear all request-scoped instances:

```php
$container->flushScope();
```

Before purging, the container calls `reset()` on every instance that implements
`ResettableInterface`, so a service can clean up its own state:

```php
interface ResettableInterface {
    public function reset(): void;
}
```

In `Application::handle()` this is done automatically in a `finally` block, so
request state is always cleaned — even when an exception was thrown:

```php
try {
    return $pipeline($request);
    // ...
} finally {
    $this->container->flushScope();
}
```

### Autowiring

If no factory is bound, the container falls back to **autowiring**: it uses
`ReflectionClass` to inspect the constructor, and recursively resolves each
constructor dependency out of the container itself.

```php
class ServiceWithDependency {
    public function __construct(public SomeOtherClass $dep) {}
}

// No set() needed — the container resolves SomeOtherClass automatically
$service = $container->get(ServiceWithDependency::class);
```

Rules it follows during autowiring:

- **Concrete class type-hints** are resolved recursively through the container.
- **Scalars** (int, string, ...) use their constructor **default value** if one
  exists; otherwise resolution fails.
- **Interfaces** work only if you have bound them via `set(Interface::class, ...)`,
  otherwise the container can't pick an implementation.
- A class with **no constructor** is instantiated directly.

### Error handling

The container throws three custom exceptions (in `src/Core/Exceptions/`), all
extending a base `ContainerException`:

| Exception | Meaning |
|---|---|
| `ContainerException` | General resolution problem (e.g. uninstantiable class, missing scalar) |
| `NotFoundException` | The requested service ID has no binding and no matching class (`missingFactory`) |
| `CircularDependencyException` | A dependency loop was detected (`A → B → A`) |

### Circular dependency detection

The container keeps a resolution stack. While resolving `A`, it marks `A` as
in-progress; if resolving that chain ever asks for `A` again, it throws a
`CircularDependencyException` with the full chain in the message. The marking is
cleaned up in a `finally` block so a failed resolution never leaves the container
"poisoned" into falsely detecting a cycle later.

---

## 3. Request & Response

### Request (`Request.php`)

An **immutable** representation of the incoming HTTP request. It does not read from
superglobals when you construct it — all values are passed in.

```php
$request = new Request(
    method: 'GET',
    uri: '/users?page=2',
    queryParams: ['page' => '2'],
    parsedBody: [],
    headers: ['Content-Type' => 'application/json'],
    serverParams: [],
);
```

Key ideas:

- **Normalization happens in the constructor.** The method is uppercased here
  (`strtoupper`), so a `Request` built manually behaves identically to one captured
  from the environment.
- **`Request::capture()`** is the *only* place that touches superglobals
  (`$_SERVER`, `$_GET`, `$_POST`, `php://input`). It also normalizes header keys
  (e.g. `HTTP_CONTENT_TYPE` → `Content-Type`) and parses a JSON body when the
  `Content-Type` is `application/json`.
- **Getters** are safe and plentiful:
  - `getMethod()`, `getUri()`, `getQuery()`
  - `getQueryParams()`, `getParsedBody()`
  - `input()` — value lookup with a fallback chain: `parsedBody` → `queryParams` → default
  - `getHeader($name)` — **case-insensitive** header lookup
  - `getHeaders()`, `getServerParams()`

### Response (`Response.php`)

Representation of the outgoing response, designed to be fluently built.

```php
$response = Response::json(['status' => 'ok'], 201);
// or
$response = Response::html('<h1>Hello</h1>');
```

- **Static factories**: `Response::json()` (uses `JSON_UNESCAPED_UNICODE` +
  `JSON_UNESCAPED_SLASHES`, falls back to `{}` if encoding fails) and
  `Response::html()` (sets the appropriate content-type).
- **Fluent setters**: `setStatusCode()`, `setContent()`, `setHeader()` — each
  returns `$this` for chaining.
- **Case-insensitive header handling**: `getHeader()` looks up case-insensitively,
  and `setHeader()` *deduplicates* — setting `content-type` replaces an existing
  `Content-Type` instead of adding a duplicate entry.
- **`send()`** sends the response: guards with `headers_sent()`, then sets the
  status line, each header, and echoes the body.

### PSR-7 interop (conversion)

Phue provides **conversion** (not inheritance) between its native `Request`/`Response`
and PSR-7 objects, powered by [`nyholm/psr7`](https://github.com/Nyholm/psr7). This
is what lets the persistent server adapters (e.g. `RoadRunnerAdapter`) talk to
PSR-7 runtimes while the core classes stay lean.

```php
// From a PSR-7 ServerRequest (e.g. from RoadRunner) into a native Phue Request
$request = Phue\Core\Request::fromPsr7($psr7ServerRequest);

// From a native Phue Response into a PSR-7 Response (e.g. sent back to the worker)
$psr7Response = $response->toPsr7(); // Psr\Http\Message\ResponseInterface
```

Notes:

- `Request::fromPsr7()` accepts any `Psr\Http\Message\ServerRequestInterface` and
  maps headers (joined with `, `), query params, parsed body, server params, method,
  and URI path onto a native `Request`.
- `Response::toPsr7()` returns a `Nyholm\Psr7\Response` with the same status code,
  headers, and body (`$content`).
- This requires the `nyholm/psr7` package (a runtime dependency declared in
  `composer.json`).

---

## 4. EventDispatcher

The dispatcher decouples components: something *publishes* an event, and anything
interested *subscribes* without the publisher knowing who is listening.

It follows **class-based matching** (the Symfony/PSR-14 school of thought): listeners
subscribe to a specific event *class*, and dispatch matches by that class.

### Basic usage

```php
$dispatcher = new EventDispatcher();

$dispatcher->listen(UserCreated::class, function (UserCreated $event) {
    // handle the event
});

$dispatcher->dispatch(new UserCreated(...));
```

- `listen($eventClass, callable $listener)` returns `$this` for fluent chaining.
- `dispatch(object $event)` runs every matching listener and **returns the event
  object** — listeners can mutate it, and the caller sees the final state.

### Inheritance & interface broadcast

The dispatcher does not only match the *exact* class. When resolving listeners for
an event, it considers:

1. The event's own class (`get_class()`)
2. All **parent classes** (`class_parents()`)
3. All **implemented interfaces** (`class_implements()`)

So a listener attached to a base `Event` class, or to an interface the event
implements, will also be triggered. This makes the system flexible: you can listen
broadly (at an interface) or narrowly (at the exact class).

### Stopping propagation

An event can signal that it stops further listeners from running. This is done via
the `StoppableEventInterface` (in `src/Core/Interfaces/`) rather than by a "magic
method" — the dispatcher checks with `instanceof`:

```php
class UserCreated extends BaseEvent implements StoppableEventInterface {
    private bool $stopped = false;

    public function stop(): void { $this->stopped = true; }
    public function isPropagationStopped(): bool { return $this->stopped; }
}
```

Inside the dispatch loop, if the event implements the interface and reports
`isPropagationStopped()`, the loop `break`s — remaining listeners do not run.

> **Design note:** listener order is currently *registration order*, with no
> priority system. Listener resolution runs in the order `[exact class, parents,
> interfaces]`. A priority mechanism (e.g. auth-first) is a likely future
> addition for the request lifecycle.

---

## 5. Router & Attribute Routing

The router maps an HTTP **method + URI** to a **handler**. It supports static paths,
named route parameters, inline parameter constraints, attribute-based routing,
route-level middleware, and automatic directory scanning.

### Registering routes

You can register routes manually:

```php
$router = new Router();

$router->get('/users', $handler);
$router->post('/users', $handler);
$router->put('/users/{id:int}', $handler, ['id' => '\d+']);
$router->delete('/users/{id}', $handler);

// or the generic form with custom parameter regex constraints
$router->addRoute('PATCH', '/users/{id}', $handler, where: ['id' => '\d+']);
```

### Attribute-Based Routing (`#[Route]`)

Instead of manual `addRoute` calls, controllers can declare routes using the `#[Route]` attribute (defined in `Phue\Core\Routing\Attribute\Route`):

```php
namespace App\Controllers;

use Phue\Core\Routing\Attribute\Route;
use Phue\Core\Routing\Attribute\Middleware;

#[Route('/api/v1/users', name: 'users.')]
#[Middleware(AuthMiddleware::class)]
class UserController
{
    #[Route('', methods: ['GET'], name: 'index')]
    public function index(): array { ... }

    #[Route('/{id:int}', methods: ['GET', 'POST'], where: ['id' => '\d+'])]
    #[Middleware(RoleCheckMiddleware::class)]
    public function show(Request $req, array $params): Response { ... }
}
```

- **Class & Method Level**: Class-level `#[Route]` sets a base path prefix and route name prefix for all action methods.
- **Repeatable**: Methods can have multiple `#[Route]` attributes if needed.

### Route-Level Middleware (`#[Middleware]`)

Attach middleware directly to controllers or actions using `#[Middleware]`:

- **Class-Level**: Applied to all routes in the controller.
- **Method-Level**: Applied to the specific action.
- **Onion Execution Order**: `Global Middleware → Class Middleware → Method Middleware → Controller Action`.

### Automatic Controller Scanning (`scanControllers`)

To avoid manually registering controllers one by one, use `scanControllers()`:

```php
$router->scanControllers(__DIR__ . '/Controllers', 'App\\Controllers');
```

This recursively scans the directory for `.php` files, resolves FQCNs, filters out non-instantiable classes (`interfaces`, `traits`, `abstract classes`), and registers all discovered routes automatically.

### Inline Parameter Types & Regex Constraints

Placeholders support inline type constraints:

| Placeholder | Regex Equivalent | Example Path |
|---|---|---|
| `{id:int}` | `\d+` | `/users/42` |
| `{name:alpha}` | `[a-zA-Z]+` | `/users/john` |
| `{slug:slug}` | `[a-zA-Z0-9_-]+` | `/posts/my-first-post` |
| `{id:uuid}` | standard UUID regex | `/items/550e8400-e29b-41d4-a716-446655440000` |

Custom regex constraints can also be passed via the `where` parameter in attributes or `addRoute()`.

### Static-First Route Matching Priority

The router sorts registered routes before dispatching (`sortRoutes()`):

1. **Static routes** (0 placeholders) take precedence over dynamic parameterized routes.
2. **Longer static prefixes** take precedence among tied parameter counts.

This guarantees that a static path like `/users/new` is evaluated before `/users/{id}`, regardless of registration order.

### RouteMatch

`dispatch()` returns a `RouteMatch` value object exposing:

- `getHandler()` — the matched handler
- `getParams()` — extracted named parameters (`array<string, string>`)
- `getMiddleware()` — route-specific middleware stack (`array<string>`)

### Errors

| Situation | Exception | HTTP code |
|---|---|---|
| No route matches the URI | `RouteNotFoundException` | `404` |
| Path matches but method isn't allowed | `MethodNotAllowedException` | `405` |

`MethodNotAllowedException` also exposes `getAllowedMethods()`, returning the list
of methods allowed for that path (so you can send back a correct `Allow` header).

---

## 6. Application (Composition Root)

The `Application` is the composition root: it wires together the `Container`,
`Router`, and `EventDispatcher`, runs the incoming request through a configurable
**middleware pipeline**, dispatches to a handler, and normalizes whatever the
handler returns into a valid `Response`.

### Construction

```php
$app = new Application(__DIR__ . '/..');
```

The `debug` flag controls error verbosity (below). `modules` is an optional list of
module class names.

### Middleware pipeline (`use()`)

Register global middleware that wraps every request:

```php
$app->use(function (Request $req, callable $next): Response {
    // before
    $response = $next($req);
    // after
    return $response;
});

// A middleware can also be a class FQCN — it is resolved from the container
$app->use(App\Security\Authenticate::class);
```

Middleware follows the **onion model**: the *first* middleware you register becomes
the *outermost* layer (it runs first going in, and last coming out). Global middleware wraps route-level middleware, which in turn wraps the controller handler.

```
Global.before → ClassMiddleware.before → MethodMiddleware.before → Handler → MethodMiddleware.after → ClassMiddleware.after → Global.after
```

Route middleware resolution is **lazy**: if a route-level middleware returns an early `Response` (e.g. `401 Unauthorized`), execution short-circuits and the target Controller is never instantiated by the DI Container.

### Handling a request (`handle()`)

```php
$response = $app->handle($request); // $request is a Phue\Core\Request
```

The flow:

1. Run the global middleware pipeline.
2. `dispatchToRouter()` matches the request and builds the route-level middleware pipeline.
3. Controller handler is resolved lazily via the DI Container.
4. `normalizeResponse()` turns the handler's return value into a `Response`.
5. Router/exceptions are mapped to proper HTTP status codes (`404`, `405`, `500`).
6. `finally` block executes `$container->flushScope()` to prevent request state leakage.

### Handler resolution

The router allows handlers in several forms, and `Application` normalizes them:

- **Closure** → invoked directly.
- **`'Controller@method'`** → `Controller` is resolved from the container and the
  method called on it.
- **`[Controller::class, 'method']`** → same container-based resolution.

Every handler is invoked with **two positional arguments**:

```php
function (Request $request, array $params) {
    // $params holds the route parameters, e.g. ['id' => '42']
}
```

So a route `/users/{id}` yields `$params = ['id' => '42']`.

### Response normalization (`normalizeResponse()`)

Whatever the handler returns becomes a `Response`:

| Handler returns | Result |
|---|---|
| `Response` instance | Passed through unchanged |
| `array` | Wrapped in `Response::json($array)` |
| anything else (e.g. string) | `new Response((string) $result, 200)` |

### Error mapping

`handle()` catches routing and runtime errors and maps them to responses:

| Exception | Response |
|---|---|
| `RouteNotFoundException` | `404 Not Found` |
| `MethodNotAllowedException` | `405 Method Not Allowed` + `Allow` header (from `getAllowedMethods()`) |
| any other `Throwable` | `500 Internal Server Error` (message only if `debug: true`) |

### Exposed accessors

After construction you can reach the building blocks through
`getContainer()`, `getRouter()`, and `getEvents()` — useful for registering routes
and services after boot:

```php
$app->getRouter()->get('/hello', fn (Request $r, array $p) => 'Hello');
```

---

## 7. Minimal end-to-end example

Putting the pieces together (now using `Application` as the driving force):

```php
use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;

$app = new Application(__DIR__ . '/..');

$app->getRouter()->get('/users/{id}', function (Request $request, array $params) {
    return Response::json(['user' => $params['id']]);
});

$response = $app->handle(Request::capture());
$response->send();
```

For reference, here is the same flow done against the lower-level components
directly:

```php
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Routing\Router;

$router = new Router();
$router->get('/users/{id}', function (Request $request, array $params) {
    return Response::json(['user' => $params['id']]);
});

// Capture an incoming request from the environment
$request = Request::capture();

try {
    $match = $router->dispatch($request->getMethod(), $request->getUri());
    $handler = $match->getHandler();
    $response = $handler($request, $match->getParams());
    $response->send();
} catch (\Phue\Core\Routing\Exceptions\RouteNotFoundException $e) {
    Response::html('404 Not Found', 404)->send();
} catch (\Phue\Core\Routing\Exceptions\MethodNotAllowedException $e) {
    Response::html('405 Method Not Allowed', 405)->send();
}
```

> In practice, you rarely touch these low-level steps yourself — that is exactly the
> job the `Application` (§6) automates for you.

---

## 8. Database Connection & Driver Architecture

The Database Layer provides a unified, driver-agnostic abstraction for executing queries, handling transactions, and fetching results.

### Abstraction Hierarchy

```
DriverInterface (Universal Contract)
├── PdoDriverInterface → AbstractPdoDriver
│   ├── SqliteDriver  (sqlite::memory: or file-based)
│   ├── MysqlDriver   (mysql:host=...;charset=utf8mb4)
│   └── PostgresDriver (pgsql:host=...;sslmode=...)
└── HttpDriverInterface (Serverless Edge Drivers: Neon, Turso, PlanetScale)
```

### Connection Manager (`Connection.php`)

The `Connection` class acts as the unified factory and driver manager. It resolves the concrete driver implementation from the configuration array:

```php
use Phue\Core\Database\Connection;

$connection = new Connection([
    'driver'   => 'sqlite',
    'database' => ':memory:',
]);

// Proxy calls directly to active driver
$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$connection->execute('INSERT INTO users (name) VALUES (?)', ['Makarios']);

$stmt = $connection->query('SELECT * FROM users WHERE id = ?', [1]);
$user = $stmt->fetch();
```

### Fluent Query Builder (`QueryBuilder.php`)

The `QueryBuilder` class provides an expressive, SQL-injection safe API for building and executing queries:

```php
$users = $connection->table('users')
    ->select('id', 'name', 'email')
    ->where('status', '=', 'active')
    ->where('age', '>=', 18)
    ->orWhere('role', 'admin')
    ->orderBy('name', 'DESC')
    ->limit(10)
    ->get();

$singleUser = $connection->table('users')->where('id', 1)->first();
$totalCount = $connection->table('users')->where('status', 'active')->count();

// DML Operations
$connection->table('users')->insert(['name' => 'Charlie', 'email' => 'charlie@example.com']);
$connection->table('users')->where('id', 1)->update(['status' => 'suspended']);
$connection->table('users')->where('status', 'expired')->delete();
```

- **Parameter Binding**: All `where()`, `orWhere()`, `insert()`, and `update()` values use positional `?` placeholders for parameter binding.
- **LIKE wildcard escaping**: `where('name', 'LIKE', '...')` automatically appends an `ESCAPE '\'` clause, so backslash-escaped wildcards in the pattern (`\%`, `\_`) are treated as literals instead of scanning the whole table.
---

## 9. Active Record ORM Architecture

The Active Record layer (`Phue\Core\Database\ActiveRecord`) maps database rows directly to PHP Model classes.

### Model Hydration & Querying (`ActiveQuery.php`)

`ActiveQuery` extends `QueryBuilder`. When queries are executed through a Model (e.g., `User::where(...)`), `ActiveQuery` automatically converts raw database rows into hydrated Model instances via `newFromBuilder()`:

```php
use App\Models\User;

// Static call forwarding to ActiveQuery
$users = User::where('status', 'active')->get(); // Returns array of User instances
$singleUser = User::where('id', 1)->first();    // Returns User instance or null
$allUsers   = User::all();                       // Returns array of User instances
```

### Change Tracking & Persistence (`BaseActiveRecord.php`)

- **Dirty Attributes (`getDirty()`)**: Tracks modified properties compared to `$original`. `save()` executes `UPDATE` **only** on modified attributes.
- **Persistence (`save()`)**: Determines automatically whether to perform `INSERT` (when `$exists` is `false`) or `UPDATE` (when `$exists` is `true`).
- **Primary Key Assignment**: Automatically assigns the newly generated ID from `$connection->lastInsertId()` to the model upon insertion.
- **Validation error bag**: `getErrors()` / `setErrors()` expose a validation error store that higher layers (e.g. `ModelEndpoint`) populate when schema rules fail and serialise back to the client.

---

## 10. CLI Console Subsystem Architecture

The CLI Subsystem (`Phue\Core\Console`) powers the framework executable `./bin/phue`.

### Core Components

1. **`CommandInterface` (`CommandInterface.php`)**: Contract for all executable CLI commands requiring `getName()`, `getDescription()`, and `execute(array $args): int`.
2. **`Console\Application` (`Application.php`)**: Entry point registry. Handles argument extraction (`$argv`), ANSI colorized help menu rendering, error formatting via `\STDERR`, and command execution dispatching.
3. **`bin/phue`**: Shebang script (`#!/usr/bin/env php`) initializing the autoloader and running `Console\Application`.

### Built-in Commands (`src/Core/Console/Commands/`)

- **`ServeCommand` (`serve`)**: Launches local dev HTTP server with document root `public/` (`php -S 0.0.0.0:8000 -t public`).
- **`SwooleServeCommand` (`serve:swoole`)**: Launches persistent Swoole HTTP server with coroutine support for high-performance serving.
- **`MakeControllerCommand` (`make:controller`)**: Generates controller classes with pre-filled `#[Route('/...', methods: ['GET'])]` attributes.
- **`MakeModelCommand` (`make:model`)**: Generates Active Record model classes extending `BaseActiveRecord`.
- **`MakeMigrationCommand` (`make:migration`)**: Scaffolds a timestamped migration file under `database/migrations/`.
- **`MigrateCommand` (`migrate`)**, **`MigrateRollbackCommand` (`migrate:rollback`)**: Apply / roll back pending migrations; the DB connection is resolved from `config/database.php` (never a hardcoded path).
- **`SchiiMigrateCommand` (`schii:migrate`)**: Runs Schii's auto-migration for configured entities.
- **`DeployCommand` (`deploy`)**: Zero-downtime atomic release deployment (see §17).

---

## 11. Schii Server-Driven UI & Zero-Controller REST Architecture

Schii (`Phue\Schii`) is Phue's runtime schema-driven UI engine. It parses PHP attributes on Active Record models to generate both REST API CRUD endpoints and JSON Schema protocols for the Vue Admin renderer with **zero controller boilerplate**.

### Attribute Declaration

- **`#[UI]` (`src/Schii/Attribute/UI.php`)**: Target class attribute defining entity label, icon, layout, and description.
- **`#[Field]` (`src/Schii/Attribute/Field.php`)**: Target property/method attribute declaring widget type, label, `searchable`, `filterable`, `sortable`, `column` visibility, `options`, and validation `rules`.

### Schema Protocol Engine (`src/Schii/Schema/`)

1. **`FieldSchema` & `ModelSchema`**: Immutable value objects exposing `toArray()` for standard JSON schema rendering.
2. **`SchemaRegistry`**: Reflection parser with dual-layer caching (L1 In-Memory & L2 PSR-16 cache), `humanize()` fallback string transformation, and automatic RBAC permission generation (`admin.entity.create`).

### Zero-Controller REST Endpoint (`src/Schii/Endpoint/ModelEndpoint.php`)

- **`schema($entity)`**: `GET /{apiPrefix}/schema/{entity}` returning complete JSON schema.
- **`index($entity, $request)`**: `GET /{apiPrefix}/{entity}` featuring:
  - Global Search across `searchable` fields.
  - Dynamic Filtering across `filterable` fields.
  - Sorting across `sortable` fields (`ASC`/`DESC`).
  - Pagination metadata (`total`, `page`, `limit`, `last_page`).
- **`show()`, `store()`, `update()`, `delete()`**: Full REST CRUD handlers for registered entities.
- **Strict validation contract**: before persistence, unknown fields are rejected with `422`, `required`/type `rules` from `#[Field]` are enforced, and primary keys are stripped from the payload (mass-assignment guard). `LIKE` searches escape wildcards (`%`, `_`) via an `ESCAPE` clause, and unregistered entities resolve to `404`.

### Auto-Migration Engine (`src/Schii/Migration/`)

- **`AutoMigrator` (`src/Schii/Migration/AutoMigrator.php`)**: Inspects active database tables (`tableExists`, `getExistingColumns`) and auto-migrates missing tables (`CREATE TABLE`) or missing fields (`ALTER TABLE ADD COLUMN`) based on model `ModelSchema`.
  - **Transaction Wrapping**: All migration steps run inside a database transaction (`beginTransaction()` / `commit()`) with automatic `rollBack()` on error.
  - **Multi-tiered Driver Detection**: Resolves driver names via `getName()` interface contract, `instanceof` class checks, or string fallback.
- **`SchemaToSqlConverter` (`src/Schii/Migration/SchemaToSqlConverter.php`)**: Generates dialect-aware SQL DDL queries tailored for SQLite, MySQL, or PostgreSQL (`AUTOINCREMENT` vs `AUTO_INCREMENT` vs `SERIAL`, `TINYINT(1)` vs `BOOLEAN`).
- **`SqlIdentifier` (`src/Core/Database/SqlIdentifier.php`)**: Sanitizes SQL identifiers with regex whitelist validation (`/^[a-zA-Z_][a-zA-Z0-9_]*$/`) and provides dialect-aware identifier quoting (backticks for MySQL/SQLite, double-quotes for PostgreSQL).

### Module Configuration (`config/schii.php`)

Schii reads its settings from a dedicated `config/schii.php`. The framework config is keyed **per file** (`items['app']`, `items['schii']`, ...), so `Config::get('schii')` returns the Schii section — never `get('app.schii')`. Settings:

- `apiPrefix` — URL prefix for the REST endpoint (default `/api/schii` — the single source of truth in `config/schii.php` and `SchiiConfig`) → `/{apiPrefix}/{entity}`.
- `prefix` — URL where the dev console UI is mounted (default `/schii`, i.e. the console page at `/schii` talks to `/{apiPrefix}/schema`).
- `entityNamespace` — namespace scanned for `#[UI]`/`#[Field]` entities (default `App\Models`).
- `autoMigrate`, `cachePrefix`.

`Container::singleton()` returns **void**, so bindings must be registered first and then retrieved via `$container->get(cls::class)`; a `$x = $container->singleton(...)` pattern silently degrades to `null` under `?->`/`??`.

### Entity Discovery & API Board

- `SchemaRegistry::discoverEntities(string $namespace): array` resolves the configured namespace to a directory **starting from the Application base path** (the module injects `Application::getBasePath()`), falling back to `getcwd()` — a server launched from any working directory still lists the same entities.
- `GET {apiPrefix}/schema` → `{"entities": ["product", ...]}` — registered **before** `/{entity}` so the literal `/schema` wins over entity routing.
- The Schii module is fully self-contained: it resolves entities from the configured namespace and hosts its own console frontend (see §12); no `App\*` references exist in `src/Schii` (enforced by contract tests).

---

## 12. No-Build Frontend Serving & Server-Driven Console (`Phue\Core\Frontend`)

Phue serves frontends without a bundler: `.phue` components are plain templates with PHP blocks, and module code ships as statically-served, content-hashed ES modules the browser imports directly — no `eval()`, no inline `<script>`, no build step.

### Component rendering (`ComponentRenderer`)

- `ComponentRenderer::__construct(HtmlShellRenderer, AssetRegistry, ?PhueComponentLoader = null)`.
- `render(string $name, ?array $pageData = null, array $params = []): Response` — the **3rd argument (`$params`) is passed intact to the component's PHP block**; the loader re-evaluates the template per render, so page data is always fresh.
- The result is already a full HTML `Response` — never wrap it in a new `Response()`.
- `HtmlShellRenderer` reads the component's page configuration (title, assets) and injects `window.__INITIAL_STATE__ = {...}` just before `</body>`; the module then boots from that state instead of inline JS.

### Module scripts & content-hashed URLs

- Components that declare their own script publish it at `/assets/phue/{Name}.{8hex}.phue.js` — the hash is derived from module file contents, so far-future caching is safe and parallel deploys can't collide.
- Serving is stateless: the request only reads the module file, coerces the `.php` block to plain JS, and streams it as `text/javascript`.
- Hardening (`AssetServerForPhueModules`): oversized/`.phue`-mismatched payloads → `413`; modules rendering non-HTML → `404`; TOCTOU race re-validates before serving; error responses are never cached; responses carry `X-Phue-Module: Name.HASH` and `X-Content-Type-Options: nosniff`.

### Module loaders (`PhueComponentLoader`, `ComponentLoaderRegistry`)

- `ComponentLoaderRegistry` (`src/Core/Frontend/ComponentLoaderRegistry.php`) is a **composite, fail-safe** loader — a throwing loader is skipped, the next is tried.
- Priority: the application loader first, then module loaders in registration order; first non-null wins. Modules declare their loaders (e.g. `ComponentLoaderRegistry::addLoader(...)`) instead of mutating `/assets/phue` URL prefixes.
- Per the plan's stateless/declarative-only module rules, loaders are registered as container bindings (`schii.loader`) and resolved through construction injection.

### HTML-first layout engine (`ComponentRenderer::applyLayoutIfConfigured`)

`ComponentRenderer` now accepts optional `layoutName`, `uriResolver`, and `partialRenderer` constructor arguments. When `layoutName` is provided, the renderer loads the named `.phue` layout component and wraps the page markup inside it:

- `layoutName` — name of the layout component (e.g. `AppLayout`, `SchiiLayout`).
- `uriResolver` — callable returning the current request URI (used for active nav state).
- `partialRenderer` — `PhuePartialRenderer` instance injected into the layout's PHP context.

Layouts receive two variables in their `<php>` block:

- `$content` — the evaluated page markup.
- `$uri` — the current request URI.

They return an `html` key whose value replaces the page markup inside the shell. If no layout is configured, the renderer falls back to the raw page markup (backwards-compatible).

### SSR navigation partial (`PhNavigation`)

`src/Core/UI/Phui/PhNavigation.phue` is an HTML-first SSR partial that renders a `<nav class="ph-nav">` list. It accepts `items` and `activeRoute` and computes active state in PHP:

- Root path (`/`) uses exact match only.
- Child paths activate their parent via `/`-delimited prefix matching (`str_starts_with`).
- Disabled items render as `<span class="ph-nav__link is-disabled" aria-disabled="true">`.
- Labels and hrefs are escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

### SPA interceptor (`phue-explorer.js`)

`storage/assets/js/phue-explorer.js` is registered automatically by `HtmlShellRenderer`. It turns the app into a SPA without changing server behavior:

- Intercepts same-origin `<a href>` clicks (ignores modified clicks, `target="_blank"`, fragments, `javascript:`).
- Sends `X-Phue-SPA: true` on fetch requests.
- Replaces `#app` innerHTML, updates `document.title`, and pushes history state.
- Re-executes module `<script type="module">` tags via blob URLs with automatic revocation, so Vue pages remount after navigation.

### Developer console page

`SchiiModule` routes `GET /schii` → `ConsoleController::index(Request, array $params)` which returns `ComponentRenderer::render('Console', [...])`. The console page (`src/Schii/Frontend/Console.phue`) is a **Vue 3 no-build app**:

- Boots from `/assets/vendor/vue3/vue.esm-browser.js` and reads `window.__INITIAL_STATE__`.
- On mount it queries `GET {apiPrefix}/schema` — **server-authoritative entity discovery**: the page carries no hardcoded entity list.
- `selectEntity(slug)` fetches the entity's JSON schema + rows concurrently; the sidebar lists whatever entities the configured namespace yields.

---

## 13. Auth & RBAC Subsystem (`Phue\Auth`)

Phue provides a modular Authentication and Role-Based Access Control (RBAC) subsystem designed for stateless API and traditional web applications.

### Core Contracts & Base Model

- **`IdentityInterface` (`src/Auth/IdentityInterface.php`)**: Contract for authenticated identities (`getId()`, `findIdentity()`, `getAuthKey()`).
- **`BaseUser` (`src/Auth/BaseUser.php`)**: Abstract ActiveRecord-backed implementation of `IdentityInterface`. Extends `BaseActiveRecord` and provides:
  - `findIdentity(int|string $id): ?static` — resolves by primary key (AR `find`)
  - `findIdentityByAuthKey(string $key): ?static` — resolves by `auth_key` column (Bearer token resolution)
  - `getId()`, `getAuthKey()`, `getRoles()` (decodes a JSON `roles` column), `validatePassword()`
  - App models simply extend it: `class User extends \Phue\Auth\BaseUser {}` — zero boilerplate identity code.
- **Authenticators (`src/Auth/Authenticator/`)**: Chain-of-responsibility drivers:
  - `SessionAuthenticator`: Web and Admin session-based user authentication.
  - `BearerTokenAuthenticator`: REST API and Schii Bearer token authentication. Its `tokenResolver` defaults to `[\Phue\Auth\BaseUser::class, 'findIdentityByAuthKey']` (zero-config) when `authResolver` is omitted from config.
- **`AuthManager` (`src/Auth/AuthManager.php`)**: Evaluates authentication drivers in sequence, tracks state (`getUser()`, `login()`, `logout()`, `check()`).
- **`RbacManager` (`src/Auth/RbacManager.php`)**: Handles role assignments, permissions (`addRole()`, `assign()`), and recursive role inheritance (`addChild()`) with visited set cycle detection. `checkAccess(int|string $userContext, string $permission, array $explicitRoles = [])` evaluates permissions — when explicit roles are supplied (e.g. from an authenticated identity), it uses them directly instead of static assignments, keeping the engine pure and DB-independent.
- **`AccessControlMiddleware` (`src/Auth/Middleware/AccessControlMiddleware.php`)**: Enforces required permissions on routes, returning `401 Unauthorized` for unauthenticated requests or `403 Forbidden` for insufficient privileges.
- **`AuthorizedModelEndpoint` (`src/Schii/Endpoint/AuthorizedModelEndpoint.php`)**: Decorator for Schii CRUD REST endpoints automatically enforcing dynamic permissions (`{entity}.{action}`) parsed from schema metadata. **Wired into the Schii route table** — `SchiiModule` selects `AuthorizedModelEndpoint` as the CRUD handler when `requireAuth => true` (the default in `config/schii.php`), and falls back to `ModelEndpoint` when auth is disabled. The endpoint delegates `checkAccess($user->getId(), $permission, $user->getRoles())`, bridging the authenticated identity's DB-loaded roles into the RBAC gate.

### Module Configuration (`config/schii.php`)

- `authResolver` — callable used by `BearerTokenAuthenticator` to resolve a Bearer token to an identity. Defaults to `[\Phue\Auth\BaseUser::class, 'findIdentityByAuthKey']` when omitted. Can be overridden (e.g. `['App\Models\User', 'findIdentityByAuthKey']`) for app-specific identity classes that extend `BaseUser`.
- `requireAuth` — when `true` (default), Schii routes CRUD operations through `AuthorizedModelEndpoint`; when `false`, uses bare `ModelEndpoint`.
- `middleware` — optional route middleware array applied to Schii endpoints.

### Token Resolution Semantics

The `findIdentity` method resolves by **primary key** (for session lookup). The `findIdentityByAuthKey` method resolves by the **`auth_key` column** — this is the correct semantic for Bearer tokens, since a token must never be treated as a user ID. Identity implementations should populate `auth_key` during user registration/login and use it for stateless authentication.

---

## 14. Server Adapters & TypePHP AOT Engine (`Phue\Server` & `Phue\Compiler`)

Phue supports persistent high-performance runtimes and ahead-of-time (AOT) static compilation for native C++ translation.

### Persistent Server Adapters (`src/Server/`)
- **`ServerAdapterInterface` (`src/Server/ServerAdapterInterface.php`)**: Universal lifecycle contract (`boot()`, `handleRequest()`, `run()`, `shutdown()`).
- **`FpmAdapter` (`src/Server/FpmAdapter.php`)**: Standard CGI/FPM adapter for stateless single-request execution.
- **`RoadRunnerAdapter` (`src/Server/RoadRunnerAdapter.php`)**: Persistent Go server worker loop using PSR-7 conversion helpers (`Request::fromPsr7()`, `$response->toPsr7()`).
- **`SwooleAdapter` (`src/Server/SwooleAdapter.php`)**: Async Coroutine HTTP Server adapter (`Swoole\Http\Server`).
- **Zero State Leakage**: All persistent worker adapters run `$container->flushScope()` inside `finally` blocks to purge request-scoped singletons between request iterations.

### TypePHP AOT Engine (`src/Compiler/`)
- **`WiringGenerator` (`src/Compiler/WiringGenerator.php`)**: Build-phase scanner emitting zero-reflection static dependency and routing maps:
  - `build/wiring.php`: Optimized PHP return array.
  - `build/wiring.cpp`: Native C++ struct array for TypePHP native compilation.
- **`AotCompatibilityChecker` (`src/Compiler/AotCompatibilityChecker.php`)**: Regex inspection for forbidden dynamic constructs (`eval()`, `extract()`, `$$var`, dynamic `call_user_func_array`).
- **`FallbackStrategy` (`src/Compiler/FallbackStrategy.php`)**: Resolves execution mode (`AOT_NATIVE`, `PHP_JIT`, `PHP_INTERPRETER`) with graceful JIT fallback.

---

## 15. Edge/WASM Infrastructure & Serverless DB Drivers (`Phue\Server` & `Phue\Core\Database\Drivers`)

Phue supports execution inside WebAssembly (WASM) micro-VMs, Cloudflare Workers, and serverless environments without requiring traditional PDO sockets or local disk access.

### Serverless HTTP Database Drivers (`src/Core/Database/Drivers/`)
- **`HttpDriverInterface` (`src/Core/Database/Drivers/Abstract/HttpDriverInterface.php`)**: Contract for REST API database drivers (`setEndpoint()`, `setAuthToken()`, `query()`, `execute()`, `getName()`).
- **`TursoDriver` (`src/Core/Database/Drivers/TursoDriver.php`)**: Serverless libSQL/SQLite HTTP driver for Turso REST pipeline (`/v2/pipeline`), returning dialect name `'sqlite'`.
- **`NeonDriver` (`src/Core/Database/Drivers/NeonDriver.php`)**: Serverless PostgreSQL HTTP driver for Neon REST API (`/sql`), returning dialect name `'pgsql'`.

### WASM Server Adapter & Edge Storage (`src/Server/` & `src/Cache/`)
- **`WasmAdapter` (`src/Server/WasmAdapter.php`)**: Adapter for executing Phue inside WebAssembly contexts. Transmutes WASM environment arrays into `Phue\Core\Request` objects, flushes container scopes via `$container->flushScope()`, and returns standard status/headers/body arrays.
- **`CacheInterface` (`src/Cache/CacheInterface.php`)**: Framework caching interface.
- **`EdgeKvCache` (`src/Cache/EdgeKvCache.php`)**: Memory-backed key-value cache driver for read-only Edge filesystems.

---

## 16. Real-Time Event Bus & Live SSE Streaming (`Phue\Events`)

Phue includes a pub/sub Event Bus subsystem supporting distributed messaging and Server-Sent Events (SSE) streaming for real-time applications and UI updates.

### Event Bus Drivers (`src/Events/Bus/`)
- **`EventBusInterface` (`src/Events/Bus/EventBusInterface.php`)**: Interface for message publishing and subscription (`publish()`, `subscribe()`).
- **`InMemoryBusDriver` (`src/Events/Bus/InMemoryBusDriver.php`)**: Synchronous in-memory listener repository for testing and local dev.
- **`RedisStreamDriver` (`src/Events/Bus/RedisStreamDriver.php`)**: High-throughput Redis Streams driver (`XADD`, `XREADGROUP`, `XACK`) with consumer groups and at-least-once delivery semantics.

### Server-Sent Events (SSE) & Broadcasting (`src/Events/Sse/` & `src/Events/Attribute/`)
- **`SseResponse` (`src/Events/Sse/SseResponse.php`)**: Specialized HTTP Response pre-configured with streaming headers (`text/event-stream`, `no-cache`, `keep-alive`, `X-Accel-Buffering: no`).
- **`SseStreamer` (`src/Events/Sse/SseStreamer.php`)**: Formatter producing standard SSE event stream text blocks (`id`, `event`, `data`).
- **`Broadcastable` (`src/Events/Attribute/Broadcastable.php`)**: Attribute for marking Active Record Models for real-time event broadcasting on CRUD mutations.

---

## 17. Production Hardening, Health Monitoring & Zero-Downtime Deployment (`Phue\Core\Health`, `Phue\Core\Security`, `Phue\Core\Console\Commands`)

Enterprise-grade production readiness features ensuring high availability, security defense, and zero-downtime releases.

### Health Probes (`src/Core/Health/`)
- **`HealthChecker` (`src/Core/Health/HealthChecker.php`)**: Evaluates real-time health metrics for Kubernetes/AWS ALB probes:
  - Database connectivity (`SELECT 1`).
  - Redis server PING response.
  - Memory consumption limits.
  - Filesystem disk space availability (supports custom `$basePath` for mounts).

### Security Hardening (`src/Core/Security/`)
- **`SecurityMiddleware` (`src/Core/Security/SecurityMiddleware.php`)**: Production defense middleware implementing the invokable Onion pipeline (`__invoke(Request $request, callable $next): Response`) and injecting strict OWASP HTTP headers (`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `X-XSS-Protection`, HSTS `Strict-Transport-Security`, `Content-Security-Policy`).

### Zero-Downtime Deployment (`src/Core/Console/Commands/`)
- **`DeployCommand` (`src/Core/Console/Commands/DeployCommand.php`)**: Atomic release manager executing codebase synchronization, dependency builds (`composer install --no-dev --optimize-autoloader`), database migrations, atomic symlink swapping (`current_temp` -> `current`), and historical release retention cleanup (`pruneOldReleases`).

### Automated Benchmark Suite (`tests/Benchmark/`)
- **`BenchmarkRunner` (`tests/Benchmark/BenchmarkRunner.php`)**: Measures container DI resolution latency (nanoseconds per operation) and full End-to-End Kernel throughput (`benchmarkKernelThroughput($app)` in Requests Per Second).


---

## 18a. Module Base Class (`Phue\Core\Module`)

The `Module` abstraction provides a consistent structure for framework extensions. Before this fix, every module was an ad-hoc class with a static `register()` method. Now modules follow a uniform pattern:

```php
abstract class Module
{
    public function __construct(
        protected string $id,
        protected string $basePath,
        protected array $modules = [],
        protected string $controllerNamespace = '',
        protected array $controllerMap = [],
        protected array $urlRules = []
    ) {}

    abstract public function init(): void;

    public function setDependencies(Router $router, Container $container, EventDispatcher $events): void;
    public function getController(string $name): ?object;
    public function getModule(string $id): ?object;
}
```

**How it works:**
- `Application::loadModules()` instantiates modules via the container, calls `setDependencies()` to inject infrastructure, then calls `init()`.
- `SchiiModule extends Module` uses `init()` containing the registration logic (previously in a static `register()` method).
- Legacy `register()` fallback is retained for backward compatibility.

---

## 18b. Component Loader Registry (`Phue\Core\Frontend\ComponentLoaderRegistry`)

The registry is a composite, fail-safe loader that resolves components across multiple directories:

| Method | Description |
|---|---|
| `load(string $name, array $params = [])` | Iterates loaders, returns the first loaded `PhueComponent` (for SSR/inline rendering) |
| `scriptUrl(string $name)` | Resolves raw script content, computes `substr(sha1(content), 0, 8)` hash, returns `/assets/phue/{Name}.{hash}.phue.js` |
| `scriptFor(string $name, string $hash)` | Returns script content if hash matches (security verification) |

**Global component resolution:** The registry is shared between the application and all modules. The application registers its loader first, then the PhUI loader, then module loaders (e.g., Schii). First non-null wins.

```php
// Application registers PhUI loader as Priority 2
$this->container->singleton('phui.loader', fn () => new PhueComponentLoader($basePath . '/src/Core/UI/Phui'));
$registry->addLoader(fn () => $this->container->get('phui.loader'));
```

---

## 18c. PhUI Component Kit (`src/Core/UI/Phui/`)

25 ready-to-use Vue 3 components, each a single `.phue` file styled with Bootstrap 5 classes:

| Category | Components |
|---|---|
| Form Controls | `PhButton`, `PhInput`, `PhSelect`, `PhTextarea`, `PhCheckbox`, `PhRadio` |
| Data Display | `PhBadge`, `PhChip`, `PhAvatar`, `PhCard` |
| Layout | `PhBox`, `PhStack`, `PhFlex`, `PhHeading`, `PhText`, `Spacer` |
| Feedback | `PhAlert`, `PhModal`, `PhProgressBar`, `PhSkeleton` |
| Navigation | `PhLink`, `PhPagination`, `PhBreadcrumb`, `PhTabs` |
| Data | `PhDataTable` |

Each component is a self-contained `.phue` file with `<template>` (Vue markup) + `<script>` (ESM module). Components use Bootstrap 5 component and utility classes from the global `storage/assets/vendor/bootstrap/bootstrap.min.css` stylesheet (design tokens, base reset, component styles, layout/spacing utilities).

---

## 18d. Abstract Controller Base (`App\Controllers\Controller`)

All application controllers extend the abstract `Controller` base class to avoid repeating the `ComponentRenderer` constructor injection:

```php
abstract class Controller
{
    public function __construct(protected ComponentRenderer $ui) {}
}

#[Route('/')]
class WebController extends Controller
{
    #[Route('/', methods: ['GET'])]
    public function index(): Response { ... }
}
```

**Route concatenation:** `AttributeRouteLoader::combinePaths()` concatenates class-level `#[Route('/products')]` with method-level `#[Route('/', methods: ['GET'])]` → `/products`. Avoid duplicating the prefix in the method route (e.g., don't use `#[Route('/products/index')]` with `#[Route('/products')]` class prefix — that yields `/products/products/index`).

---

## 18. Intentional design decisions (why it's built this way)

A collection of choices worth documenting so future readers understand the *why*:

- **Class-based events over string keys.** Type-safe, discoverable, and easily
  refactored — you can't mis-type a magic string.
- **`StoppableEventInterface` over `method_exists`.** Using an interface (via
  `instanceof`) is explicit and semantic instead of relying on an arbitrary method
  name existing on any object.
- **Immutable request normalization in one place.** `Request::capture()` is the only
  superglobal-touching code, and normalization (uppercase method, header keys) lives
  in the constructor so hand-built and captured requests behave identically.
- **`get()` caches by default; `make()` is the explicit escape hatch.** You opt *in*
  to a fresh instance rather than having to remember to opt out of caching.
- **Case-insensitive headers on both Request and Response.** HTTP headers are
  case-insensitive (RFC 7230), so both sides normalize and deduplicate consistently —
  avoiding the classic "they differ in casing" bug.
- **`RouteMatch` as a value object.** Dispatch returns structured data (handler +
  params), not a bare tuple, keeping call sites readable.
- **`Application` as composition root.** The app owns and wires the container,
  router, and event dispatcher; components never reach for globals or construct
  their own dependencies. This keeps wiring in one discoverable place and makes the
  pipeline ("onion") behavior explicit and testable.
- **Fixed handler signature `(Request, array $params)`.** A single, documented
  convention beats per-handler guessing. Arrays encode route params as a named map;
  closures that ignore extra arguments still work because PHP permits it, but the
  supported contract is explicit.
- **Explicit request scoping instead of implicit state.** Rather than forcing every
  framework object to live in an explicit `ScopeContext`, the container exposes
  `singleton()` / `scoped()` and a single `flushScope()` that `Application::handle()`
  runs automatically in a `finally`. This trades a bit of coroutine-friendliness for
  a far simpler mental model — and it guarantees request cleanup even on exceptions.
