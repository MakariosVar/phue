# Phue Framework — Practical Usage Guide

Welcome to **Phue Framework**! This guide provides practical examples and best practices for building modern, high-performance web applications using Phue.

---

## Table of Contents

1. [Quick Start & Application Setup](#1-quick-start--application-setup)
2. [Controllers & Attribute-Based Routing](#2-controllers--attribute-based-routing)
3. [Middlewares & Request Lifecycle](#3-middlewares--request-lifecycle)
4. [Automatic Controller Scanning](#4-automatic-controller-scanning)
5. [Dependency Injection & Scopes](#5-dependency-injection--scopes)
6. [HTTP Request & Response API](#6-http-request--response-api)
7. [Running & Testing Locally](#7-running--testing-locally)

---

## 1. Quick Start & Application Setup

### Directory Structure Convention

A standard Phue application structure looks like this:

```
my-app/
├── App/
│   ├── Controllers/
│   │   ├── HomeController.php
│   │   └── UserController.php
│   ├── Middleware/
│   │   └── AuthMiddleware.php
│   ├── Services/
│   │   └── UserService.php
│   └── index.php             # Web entry point
├── config/
│   └── app.php
├── docker/
├── src/                      # Phue Framework Core
├── composer.json
└── phue-docker               # CLI Docker wrapper
```

### PSR-4 Autoloading (`composer.json`)

Ensure your `App` namespace is registered in `composer.json`:

```json
{
    "autoload": {
        "psr-4": {
            "Phue\\": "src/",
            "App\\": "App/"
        }
    }
}
```

Run `composer dump-autoload` after editing `composer.json`.

### Web Entry Point (`App/index.php`)

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Phue\Core\Application;
use Phue\Core\Request;

// 1. Instantiate Application
$app = new Application(__DIR__ . '/..');

// 2. Automatically scan and register all controllers
$app->getRouter()->scanControllers(__DIR__ . '/Controllers', 'App\\Controllers');

// 3. Capture incoming HTTP request and execute pipeline
$request = Request::capture();
$response = $app->handle($request);

// 4. Send HTTP headers and body to client
$response->send();
```

---

## 2. Controllers & Attribute-Based Routing

Phue uses native PHP 8 Attributes for declaring routes directly above controller classes and methods.

### Basic Controller Example

```php
namespace App\Controllers;

use Phue\Core\Routing\Attribute\Route;
use Phue\Core\Request;
use Phue\Core\Response;

#[Route('/api/v1/users', name: 'users.')]
class UserController
{
    /**
     * GET /api/v1/users
     */
    #[Route('', methods: ['GET'], name: 'index')]
    public function index(): array
    {
        return [
            'status' => 'success',
            'data'   => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ]
        ];
    }

    /**
     * GET /api/v1/users/{id}
     */
    #[Route('/{id:int}', methods: ['GET'], name: 'show')]
    public function show(Request $request, array $params): Response
    {
        $userId = (int) $params['id'];

        return Response::json([
            'id'   => $userId,
            'name' => 'Alice'
        ]);
    }
}
```

### Route Attribute Options

The `#[Route]` attribute supports several parameters:

```php
#[Route(
    path: '/posts/{id}',
    methods: ['GET', 'POST'],
    name: 'posts.show',
    where: ['id' => '\d+']
)]
```

- **`path`**: The URI pattern. Can include inline type constraints (e.g., `{id:int}`).
- **`methods`**: Allowed HTTP method(s) (string or array). Automatically normalized to uppercase.
- **`name`**: Optional route name. Class-level route names act as prefixes.
- **`where`**: Array of custom regex patterns per placeholder parameter.

### Inline Parameter Type Constraints

Phue supports built-in type shortcuts inside path placeholders:

| Pattern | Inline Constraint | Regex Used | Matches |
|---|---|---|---|
| `/users/{id:int}` | `:int` | `\d+` | `/users/42` |
| `/users/{name:alpha}` | `:alpha` | `[a-zA-Z]+` | `/users/alice` |
| `/posts/{slug:slug}` | `:slug` | `[a-zA-Z0-9_-]+` | `/posts/hello-world-2026` |
| `/items/{id:uuid}` | `:uuid` | Standard UUID regex | `/items/550e8400-e29b-41d4-a716-446655440000` |

### Static-First Priority Matching

Routes are automatically sorted so static routes take precedence over dynamic ones.

```php
#[Route('/users')]
class UserController
{
    // Registered dynamic route
    #[Route('/{id}', methods: ['GET'])]
    public function show() { ... }

    // Registered static route
    #[Route('/new', methods: ['GET'])]
    public function create() { ... }
}
```

Requesting `GET /users/new` will correctly route to `create()`, even if `show()` was registered first.

---

## 3. Middlewares & Request Lifecycle

Middlewares in Phue implement the **Onion Architecture**. A middleware can process the request before passing it to `$next($request)` and inspect or modify the returned `Response`.

### Writing a Middleware

A middleware can be an invokable class (`__invoke`) or a Closure:

```php
namespace App\Middleware;

use Phue\Core\Request;
use Phue\Core\Response;

class AuthMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $token = $request->getHeader('Authorization');

        if (!$token || $token !== 'Bearer secret-token') {
            // Short-circuit execution and return early response
            return Response::json(['error' => 'Unauthorized access'], 401);
        }

        // Pass request down the pipeline to the next layer
        $response = $next($request);

        // Add custom header to outgoing response
        return $response->setHeader('X-Processed-By', 'AuthMiddleware');
    }
}
```

### Global Middlewares (`$app->use()`)

Global middlewares wrap **every** incoming request:

```php
$app->use(App\Middleware\CorsMiddleware::class);
$app->use(App\Middleware\LoggingMiddleware::class);
```

### Route-Level Middlewares (`#[Middleware]`)

Attach middlewares to specific controllers or individual action methods using `#[Middleware]`:

```php
namespace App\Controllers;

use Phue\Core\Routing\Attribute\Route;
use Phue\Core\Routing\Attribute\Middleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleCheckMiddleware;

#[Route('/admin')]
#[Middleware(AuthMiddleware::class)] // Applied to all actions in AdminController
class AdminController
{
    #[Route('/dashboard', methods: ['GET'])]
    public function dashboard(): string
    {
        return 'Welcome Admin!';
    }

    #[Route('/settings', methods: ['POST'])]
    #[Middleware(RoleCheckMiddleware::class)] // Applied ONLY to settings()
    public function settings(): array
    {
        return ['status' => 'settings updated'];
    }
}
```

### Execution Order

```
Global Middleware (Before)
   └── Class Middleware (Before)
          └── Method Middleware (Before)
                 └── Controller Action Method
          ┌── Method Middleware (After)
   ┌── Class Middleware (After)
Global Middleware (After)
```

---

## 4. Automatic Controller Scanning

Rather than registering controllers one by one, use `scanControllers()` to discover them automatically:

```php
// Scans App/Controllers directory recursively
$app->getRouter()->scanControllers(__DIR__ . '/Controllers', 'App\\Controllers');
```

- Scans directory recursively for `.php` files.
- Converts relative file paths into fully qualified PSR-4 class names.
- Ignores non-instantiable types (`interfaces`, `traits`, `abstract classes`).
- Extracts and registers all `#[Route]` attributes found inside.

---

## 5. Dependency Injection & Scopes

Phue includes a PSR-11 compliant Dependency Injection Container supporting automatic reflection-based autowiring.

### Scope Types

1. **Application-Scoped (`singleton`)**: Persistent services shared across requests (Config, Router, Database Connection Pool).
2. **Request-Scoped (`scoped`)**: Recreated fresh for each request (Auth User, Current Request State).

```php
$container = $app->getContainer();

// Register application singleton
$container->singleton(App\Services\Logger::class);

// Register request-scoped service
$container->scoped(App\Services\CurrentUserService::class);

// Bind custom factory
$container->set(App\Services\PaymentGateway::class, function ($container) {
    return new PaymentGateway(apiKey: 'secret_key');
});
```

### Constructor Autowiring

Controller classes automatically receive dependencies declared in their constructor:

```php
namespace App\Controllers;

use App\Services\UserService;
use Phue\Core\Routing\Attribute\Route;

class UserController
{
    // UserService is automatically instantiated and injected by DI Container
    public function __construct(private UserService $userService) {}

    #[Route('/users', methods: ['GET'])]
    public function index(): array
    {
        return $this->userService->getAllUsers();
    }
}
```

---

## 6. HTTP Request & Response API

### Reading Request Data (`Phue\Core\Request`)

```php
// HTTP Method & URI
$method = $request->getMethod(); // 'GET', 'POST', etc.
$uri    = $request->getUri();    // '/api/v1/users'

// Query Parameters ($_GET)
$page = $request->getQuery('page', default: '1');

// Input Lookup (checks parsed JSON body first, then query params, then default)
$name = $request->input('name', default: 'Guest');

// Case-insensitive Headers
$contentType = $request->getHeader('content-type');
```

### Sending Responses (`Phue\Core\Response`)

```php
use Phue\Core\Response;

// JSON Response
return Response::json(['status' => 'ok'], statusCode: 200);

// HTML Response
return Response::html('<h1>Hello World</h1>', statusCode: 200);

// Fluent Response Builder
return (new Response())
    ->setStatusCode(201)
    ->setHeader('Content-Type', 'application/json')
    ->setContent(json_encode(['created' => true]));
```

---

## 7. Database Connections & Drivers

Phue provides a unified `Connection` manager supporting SQLite, MySQL, PostgreSQL, and Serverless HTTP drivers.

### Instantiating a Connection

```php
use Phue\Core\Database\Connection;

// 1. SQLite Connection
$db = new Connection([
    'driver'   => 'sqlite',
    'database' => __DIR__ . '/../storage/database.sqlite', // or ':memory:'
]);

// 2. MySQL Connection
$db = new Connection([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'phue_db',
    'username' => 'root',
    'password' => 'secret',
    'charset'  => 'utf8mb4',
]);

// 3. PostgreSQL Connection
$db = new Connection([
    'driver'   => 'pgsql',
    'host'     => '127.0.0.1',
    'port'     => 5432,
    'database' => 'phue_db',
    'username' => 'postgres',
    'password' => 'secret',
    'sslmode'  => 'prefer',
]);
```

### Executing Queries & Statements

```php
// Execute DDL or DML statements (returns affected rows count)
$affected = $db->execute(
    'INSERT INTO users (name, email) VALUES (?, ?)',
    ['Alice', 'alice@example.com']
);

$newId = $db->lastInsertId();

// Prepared Query Execution (returns StatementInterface)
$stmt = $db->query('SELECT * FROM users WHERE email = ?', ['alice@example.com']);

// Fetch single row
$user = $stmt->fetch();

### Using the Fluent Query Builder

Instead of writing raw SQL strings, use `$db->table()` to construct safe, fluent queries:

```php
// SELECT with WHERE, ORDER BY, LIMIT, and OFFSET
$users = $db->table('users')
    ->select('id', 'name', 'email')
    ->where('status', 'active')
    ->where('age', '>=', 18)
    ->orWhere('role', 'admin')
    ->orderBy('name', 'ASC')
    ->limit(15)
    ->get();

// Fetch single record or null
$user = $db->table('users')->where('id', 42)->first();

// Retrieve aggregate count
$count = $db->table('users')->where('status', 'pending')->count();

// Insert record
$success = $db->table('users')->insert([
    'name'   => 'Makarios',
    'email'  => 'makarios@example.com',
    'status' => 'active'
]);

// Update records (returns affected rows count)
$updatedRows = $db->table('users')
    ->where('email', 'makarios@example.com')
    ->update(['status' => 'verified']);

// Delete records (returns affected rows count)
$deletedRows = $db->table('users')
    ->where('status', 'expired')
    ->delete();
```

### Transaction Control

```php
$db->beginTransaction();

try {
    $db->execute('UPDATE accounts SET balance = balance - 100 WHERE id = ?', [1]);
    $db->execute('UPDATE accounts SET balance = balance + 100 WHERE id = ?', [2]);

    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    throw $e;
}
```

---

## 8. Active Record ORM Usage

Phue includes a full-featured Active Record ORM mapping database rows directly to PHP Model classes.

### Defining a Model

```php
namespace App\Models;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

class User extends BaseActiveRecord
{
    // Table name is inferred as 'users' automatically if omitted.
    protected string $table = 'users';

    // Primary key defaults to 'id' if omitted.
    protected string $primaryKey = 'id';
}
```

### Initializing Database Connection for Models

```php
use Phue\Core\Database\Connection;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

$db = new Connection(['driver' => 'sqlite', 'database' => 'app.sqlite']);
BaseActiveRecord::setConnection($db);
```

### Model Operations (CRUD)

```php
use App\Models\User;

// 1. Create & Persist Model
$user = User::create([
    'name'   => 'Alice',
    'email'  => 'alice@example.com',
    'status' => 'active',
]);
// $user->id is automatically assigned after insert!

// 2. Find Single Record by Primary Key
$user = User::find(1); // Returns User instance or null

// 3. Update Model (Selective Dirty Attributes UPDATE)
$user->name = 'Alice Updated';
$user->save(); // Executes UPDATE only for modified fields

// 4. Querying Models with Fluent ActiveQuery
$activeUsers = User::where('status', 'active')
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->get(); // Returns array<User>

$singlePending = User::where('status', 'pending')->first(); // Returns User instance or null

// 5. Delete Model
$user->delete();
```

---

## 9. CLI Console Tool (`bin/phue`)

Phue provides a terminal executable `./bin/phue` for managing dev servers and generating code.

### Available Commands List

```bash
./bin/phue list
```

### Launch Development Web Server (`serve`)

```bash
# Starts server on 127.0.0.1:8000
./bin/phue serve

# Override port or host
./bin/phue serve --port=8080 --host=0.0.0.0
```

### Generate Controller Class (`make:controller`)

```bash
./bin/phue make:controller UserController
# Creates App/Controllers/UserController.php with pre-configured #[Route] attribute
```

### Generate Model Class (`make:model`)

```bash
./bin/phue make:model Product
# Creates App/Models/Product.php extending BaseActiveRecord
```

---

## 10. Schii Server-Driven UI & Zero-Controller REST

Schii allows building full REST APIs and admin screens directly from model attribute declarations.

### Annotating a Model

```php
namespace App\Models;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Schii\Attribute\UI;
use Phue\Schii\Attribute\Field;

#[UI(label: 'Products', icon: 'box', layout: 'grid')]
class Product extends BaseActiveRecord
{
    protected string $table = 'products';

    #[Field(type: 'text', label: 'Product Name', searchable: true, sortable: true, required: true)]
    public string $name;

    #[Field(type: 'number', label: 'Price ($)', filterable: true, sortable: true)]
    public float $price;

    #[Field(type: 'select', filterable: true, options: ['active', 'inactive'])]
    public string $status;
}
```

### Registering Entity & Serving Zero-Controller REST API

```php
use Phue\Schii\Schema\SchemaRegistry;
use Phue\Schii\Endpoint\ModelEndpoint;

$registry = new SchemaRegistry();
$endpoint = new ModelEndpoint($registry);

// Register entity mapping
$endpoint->registerEntity('products', App\Models\Product::class);

// Handled Endpoints:
// GET    /schii/schema/products      -> Returns JSON Schema protocol for Vue Admin
// GET    /schii/products?q=Lap&page=1 -> List with search, filter, sort & pagination
// GET    /schii/products/1            -> Show record
// POST   /schii/products              -> Create record
// PUT    /schii/products/1            -> Update record
// DELETE /schii/products/1            -> Delete record
```

> **Namespace discovery**: normally you don't register entities manually — the module discovers them from the configured `entityNamespace` (default `App\Models`). Put `#[UI]`/`#[Field]` models there and the REST API + console board pick them up automatically. A discovery endpoint — `GET /{apiPrefix}/schema` → `{"entities":[...]}` — lists the served slugs server-authoritatively.

### Serving the Developer Console (No-Build Vue UI)

Schii ships a dev console at `/schii` — a **Vue 3 no-build app** (ES modules straight from the server, no bundler). Run the built-in server and open `http://localhost:8000/schii`:

```bash
./bin/phue serve  # -> http://127.0.0.1:8000
```

- The sidebar is populated **server-authoritatively**: the page loads `GET /schii/schema` and renders whatever entities the configured namespace yields — nothing hardcoded in the frontend.
- Clicking an entity fetches its JSON schema + rows and renders them.
- Configuration lives in a dedicated `config/schii.php`:

```php
return [
    'apiPrefix'       => 'schii',       // URL prefix -> /schii/{entity}, /schii/schema
    'entityNamespace' => 'App\Models',  // entities scanned for #[UI]/#[Field]
    'cachePrefix'     => 'schii_schema_',
];
```

> To add entities, create your model in the configured namespace (e.g. `make:model`) and restart the server. Discovery is rooted at the application base path, so it works no matter which directory you launch the server from.

### Input Validation & Security Contract

The REST endpoint applies strict validation **before** any persistence:

- **Unknown fields → `422`**: any property not declared via `#[Field]` is rejected with `"Unknown field 'X' is not allowed"`. Since query strings and body are merged, this also covers stray URL parameters on `POST`/`PUT` — keep request URLs clean.
- **Required fields → `422`**: fields declared with `required: true` must be present and non-empty on `store()`.
- **Type rules → `422`**: `number`/`numeric` fields reject non-numeric input (e.g. `price: "abc"`), `email` fields require a valid e-mail. Declare additional rules directly on the attribute: `#[Field(type: 'select', rules: ['email'])]`.
- **Mass-assignment protection**: the primary key and non-schema fields are stripped from the payload before `fill()` — clients can never forge their own `id`.
- **Escaped `LIKE` search**: the wildcard characters `%` and `_` in `q` are escaped (the underlying `QueryBuilder` appends an `ESCAPE '\'` clause), so a bare `q=%` matches nothing instead of returning the whole table.
- **Unregistered entities → `404`**: requesting `/schii/unknown` returns `404 Not Found` instead of throwing an exception.
- **Pagination clamps**: `limit` is clamped to `[1, 100]` and `page` to `>= 1`; garbage values fall back to defaults.

### Auto-Migrating Database Tables from Schii Schemas

You can auto-migrate missing tables or new columns directly from model schema declarations programmatically or via CLI:

#### Programmatic Auto-Migration

```php
use Phue\Schii\Migration\AutoMigrator;
use Phue\Schii\Schema\SchemaRegistry;

$migrator = new AutoMigrator(new SchemaRegistry(), $dbConnection);

// Automatically creates table or ALTERs columns based on Product model attributes
$actions = $migrator->migrate(App\Models\Product::class);

foreach ($actions as $action) {
    echo "Migrated: {$action}\n";
}
```

#### CLI Command

```bash
# Run auto-migration for a model
./bin/phue schii:migrate "App\Models\Product"
```

### Explicit Database Migrations

For complex schema refactorings, column renames, or type changes, use explicit migration files:

#### Generating Migration Files

```bash
./bin/phue make:migration create_orders_table
# Generates: database/migrations/m2026_09_03_014500_create_orders_table.php
```

#### Writing Migration Logic

```php
use Phue\Core\Database\Migration;

final class M2026_09_03_014500_Create_Orders_Table extends Migration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE orders (id INTEGER PRIMARY KEY, total REAL);");
    }

    public function down(): void
    {
        $this->dropTable('orders');
    }
}
```

#### Running & Rolling Back Migrations

```bash
# Run all pending migrations
./bin/phue migrate

# Rollback the last batch of executed migrations
./bin/phue migrate:rollback
```

### 11. Authentication & RBAC Subsystem

#### Configuring AuthManager & Authenticators

```php
use Phue\Auth\AuthManager;
use Phue\Auth\Authenticator\BearerTokenAuthenticator;
use Phue\Auth\Authenticator\SessionAuthenticator;

$authManager = new AuthManager([
    new SessionAuthenticator(App\Models\User::class),
    new BearerTokenAuthenticator(fn($token) => App\Models\User::findByToken($token)),
]);

// Authenticate incoming request
$user = $authManager->authenticate($request);
```

#### Configuring RBAC Roles & Inheritance

```php
use Phue\Auth\RbacManager;

$rbac = new RbacManager();

// Register roles and permissions
$rbac->addRole('author', ['posts.create']);
$rbac->addRole('editor', ['posts.update', 'posts.delete']);
$rbac->addRole('admin', ['admin.dashboard']);

// Define role inheritance: admin -> editor -> author
$rbac->addChild('admin', 'editor');
$rbac->addChild('editor', 'author');

// Assign role to user ID
$rbac->assign(10, 'admin');

// Check access (evaluates full inheritance tree safely)
if ($rbac->checkAccess(10, 'posts.create')) {
    // Access granted
}
```

### 12. Server Adapters & TypePHP AOT Engine

#### Running Persistent Server Adapters (RoadRunner & Swoole)

```php
use Phue\Core\Application;
use Phue\Server\RoadRunnerAdapter;

$app = new Application(__DIR__ . '/..');

// Boot RoadRunner persistent adapter
$adapter = new RoadRunnerAdapter();
$adapter->boot($app);
$adapter->run();
```

#### Generating Static Wiring Artifacts for TypePHP AOT

```php
use Phue\Compiler\WiringGenerator;

$generator = new WiringGenerator(
    scanDirectories: ['src', 'App'],
    outputDir: 'build'
);

// Emits build/wiring.php and build/wiring.cpp
$result = $generator->generate();
echo "Generated static wiring for {$result['count']} classes.\n";
```

### 13. Edge / WASM Infrastructure & Serverless Database Drivers

#### Using Turso & Neon Serverless HTTP Drivers

```php
use Phue\Core\Database\Drivers\TursoDriver;
use Phue\Core\Database\Drivers\NeonDriver;

// Turso libSQL at the Edge over HTTP
$turso = new TursoDriver('https://db-org.turso.io', 'your_auth_token');
$users = $turso->query('SELECT * FROM users WHERE active = ?', [1]);

// Neon Serverless Postgres over HTTP
$neon = new NeonDriver('https://ep-proj-1234.neon.tech/sql', 'your_auth_token');
$affected = $neon->execute('UPDATE users SET status = ? WHERE id = ?', ['active', 42]);
```

#### Executing inside WebAssembly (WASM) Micro-VMs

```php
use Phue\Server\WasmAdapter;
use Phue\Cache\EdgeKvCache;

// Memory-backed KV cache for Read-Only Edge filesystems
$cache = new EdgeKvCache();

$adapter = new WasmAdapter($container);

// Handle WASM execution context
$result = $adapter->handleWasmRequest([
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/api/edge',
    'HEADERS' => ['Accept' => 'application/json'],
], fn($request) => $app->handle($request));
```

### 14. Real-Time Event Bus & Live SSE Streaming

#### Publishing & Subscribing via Redis Streams Driver

```php
use Phue\Events\Bus\RedisStreamDriver;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$bus = new RedisStreamDriver($redis, consumerGroup: 'web_workers');

// Publish event to channel
$bus->publish('orders', ['order_id' => 101, 'status' => 'paid']);

// Subscribe worker loop
$bus->subscribe('orders', function(array $event) {
    echo "Processing order #{$event['order_id']} ({$event['status']})\n";
});
```

#### Streaming Server-Sent Events (SSE) to Web Browsers

```php
use Phue\Events\Sse\SseResponse;
use Phue\Events\Sse\SseStreamer;

$streamer = new SseStreamer();

// Format event stream block
$payload = $streamer->formatEvent('order.updated', ['id' => 101, 'status' => 'shipped'], '1001');

// Return SSE HTTP Streaming Response
return new SseResponse($payload);
```

#### Marking Models for Real-Time Broadcasting

```php
use Phue\Events\Attribute\Broadcastable;

#[Broadcastable(channel: 'orders', broadcastCreate: true, broadcastUpdate: true)]
class Order extends ActiveRecord
{
    // Model CRUD mutations will automatically broadcast events to 'orders' channel
}
```

### 15. Production Hardening, Health Probes & Zero-Downtime Deployment

#### Running Diagnostic Health Probes

```php
use Phue\Core\Health\HealthChecker;

$checker = new HealthChecker(
    pdo: $db->getPdo(),
    redis: $redis,
    maxMemoryThresholdMb: 128.0
);

// Evaluate probes for Kubernetes / ALB (/health/ready)
$report = $checker->check();
if (!$report['healthy']) {
    return new Response(json_encode($report), 503, ['Content-Type' => 'application/json']);
}
```

#### Enforcing Security Hardening Middleware

```php
use Phue\Core\Security\SecurityMiddleware;

$middleware = new SecurityMiddleware();
$hardenedResponse = $middleware->handle($response);
```

#### Triggering Zero-Downtime Atomic Deployment

```bash
# Execute zero-downtime deployment script
./bin/phue deploy
```

---

## 11. Running & Testing Locally

### Using Docker Wrapper (`phue-docker`)

```bash
# Build dev environment
./phue-docker build

# Start container
./phue-docker up

# Enter interactive container shell
./phue-docker shell
```

### Running Test Suite (Inside Container)

```bash
vendor/bin/phpunit
```

### Launching Dev Web Server (Inside Container)

```bash
php -S 0.0.0.0:8000 App/index.php
```

Test endpoints in terminal or HTTP client:

```bash
curl -i http://localhost:8000/api/v1/users
curl -i http://localhost:8000/api/v1/users/42
```
