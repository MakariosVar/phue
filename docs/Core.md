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
    #[Route('', methods: 'GET', name: 'index')]
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
$app = new Application([
    'debug'   => true,   // when true, 500 errors include the exception message
    'modules' => [App\DemoModule::class],
]);
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

$app = new Application(['debug' => true]);

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
- **`toSql()` & `getBindings()`**: Inspect compiled SQL strings and parameter bindings for debugging.

---

## 9. Intentional design decisions (why it's built this way)

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
