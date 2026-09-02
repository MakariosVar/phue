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
$app = new Application([
    'debug' => true
]);

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
    #[Route('', methods: 'GET', name: 'index')]
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
    #[Route('/{id:int}', methods: 'GET', name: 'show')]
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
    #[Route('/{id}', methods: 'GET')]
    public function show() { ... }

    // Registered static route
    #[Route('/new', methods: 'GET')]
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
    #[Route('/dashboard', methods: 'GET')]
    public function dashboard(): string
    {
        return 'Welcome Admin!';
    }

    #[Route('/settings', methods: 'POST')]
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

    #[Route('/users', methods: 'GET')]
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

## 9. Running & Testing Locally

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
