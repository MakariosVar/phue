# Database

The database layer: connections and drivers, the fluent QueryBuilder, Active
Record, Data Mapper, and migrations.

Related guides: [Core](Core.md), [Schii](Schii.md) (auto-migration from model
attributes).

---

## 1. Abstraction hierarchy

```
DriverInterface (Universal Contract)
├── PdoDriverInterface → AbstractPdoDriver
│   ├── SqliteDriver   (sqlite::memory: or file-based)
│   ├── MysqlDriver    (mysql:host=...;charset=utf8mb4)
│   └── PostgresDriver (pgsql:host=...;sslmode=...)
└── HttpDriverInterface (Serverless Edge Drivers)
    ├── TursoDriver    (libSQL/SQLite over HTTP)
    └── NeonDriver     (PostgreSQL over HTTP)
```

The universal `DriverInterface` contract (in
`src/Core/Database/Drivers/Abstract/`) is shared by PDO and HTTP edge drivers,
so the rest of the framework never cares which one is active.

---

## 2. Connection manager (`Connection.php`)

`Connection` (in `src/Core/Database/Connection.php`) is the unified factory and
driver manager. It resolves the concrete driver from the configuration array
and proxies calls to the active driver:

```php
use Phue\Core\Database\Connection;

$connection = new Connection([
    'driver'   => 'sqlite',
    'database' => ':memory:',
]);

// DDL / DML (returns affected rows count)
$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$connection->execute('INSERT INTO users (name) VALUES (?)', ['Makarios']);

// Prepared query (returns a statement)
$stmt = $connection->query('SELECT * FROM users WHERE id = ?', [1]);
$user = $stmt->fetch();

$newId = $connection->lastInsertId();
```

### Booting the connection automatically

`Application::bootstrapDatabase()` reads `database.default` from
`config/database.php`, resolves the matching entry from
`database.connections.<name>`, registers a `Connection` singleton, and calls
`BaseActiveRecord::setConnection()` — so Active Record models just work:

```php
// config/database.php
return [
    'default' => env('DB_CONNECTION', 'sqlite'),
    'connections' => [
        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => env('DB_DATABASE', __DIR__ . '/../database/phue.sqlite'),
        ],
        'mysql' => [
            'driver'   => 'mysql',
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'phue'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
        ],
    ],
];
```

### Direct driver construction

```php
// SQLite
$db = new Connection(['driver' => 'sqlite', 'database' => 'app.sqlite']); // or ':memory:'

// MySQL
$db = new Connection([
    'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
    'database' => 'phue_db', 'username' => 'root', 'password' => 'secret',
    'charset' => 'utf8mb4',
]);

// PostgreSQL
$db = new Connection([
    'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432,
    'database' => 'phue_db', 'username' => 'postgres', 'password' => 'secret',
    'sslmode' => 'prefer',
]);
```

### Transaction control

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

### Connection singleton vs scoped

In persistent runtimes (Swoole, RoadRunner) the `Connection` should be bound as a
**singleton**, not scoped:

```php
// In Application::bootstrapDatabase()
$this->container->singleton(Connection::class, fn (): Connection => new Connection($dbConfig));
$this->container->alias('db', Connection::class);
```

A singleton connection reuses the same PDO handle across requests, preserving
connection pooling and avoiding the overhead of a new TCP handshake per request.
Combined with `BaseActiveRecord::setConnectionResolver(fn () => $container->get(Connection::class))`,
the Active Record layer always resolves the current connection from the
container, while the underlying PDO handle stays pooled.

**Why not scoped?** A scoped connection creates a new PDO handle per request,
which defeats connection pooling, loses any connection-level state (prepared
statements, temporary tables, transaction context), and adds unnecessary
overhead in persistent runtimes.

---

## 3. Fluent QueryBuilder (`QueryBuilder.php`)

An expressive, SQL-injection-safe API. Access it through the connection:

```php
$users = $db->table('users')
    ->select('id', 'name', 'email')
    ->where('status', '=', 'active')
    ->where('age', '>=', 18)
    ->orWhere('role', 'admin')
    ->orderBy('name', 'ASC')
    ->limit(15)
    ->get();

$singleUser = $db->table('users')->where('id', 42)->first();
$count      = $db->table('users')->where('status', 'pending')->count();

// DML
$success     = $db->table('users')->insert(['name' => 'Makarios', 'email' => 'makarios@example.com']);
$updatedRows = $db->table('users')->where('email', 'makarios@example.com')->update(['status' => 'verified']);
$deletedRows = $db->table('users')->where('status', 'expired')->delete();
```

- **Parameter binding**: all `where()`, `orWhere()`, `insert()`, and `update()`
  values use positional `?` placeholders.
- **LIKE wildcard escaping**: `where('name', 'LIKE', '...')` automatically
  appends an `ESCAPE '!'` clause, so escaped wildcards (`!%`, `!_`) are treated
  as literals instead of scanning the whole table. The escape character is `!`
  and not a backslash on purpose: a backslash is an escape character inside a
  MySQL/MariaDB string literal, so `ESCAPE '\'` is an unterminated literal to
  those drivers and breaks every LIKE query there. Run user input through
  `QueryBuilder::escapeLikePattern($value)` — it escapes `%`, `_` and `!` with
  the same character the clause declares, so the two cannot drift apart.
- **Operator whitelist**: `addWhereClause()` only accepts a strict whitelist of
  SQL operators (`=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`,
  `ILIKE`, `NOT ILIKE`, `IS`, `IS NOT`). Any operator outside this list throws
  `InvalidArgumentException`. The operator is sanitized and the cleaned version
  is used in the SQL string; for `LIKE`/`NOT LIKE`/`ILIKE`/`NOT ILIKE` the
  `ESCAPE` clause is preserved.

---

## 4. Active Record ORM (`Phue\Core\Database\ActiveRecord`)

Maps database rows directly to PHP model classes.

### Defining a model

```php
namespace App\Models;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

class Product extends BaseActiveRecord
{
    // Table name is inferred as 'products' automatically if omitted.
    protected string $table = 'products';

    // Primary key defaults to 'id' if omitted.
    protected string $primaryKey = 'id';
}
```

### CRUD

```php
use App\Models\Product;

// Create & persist — $product->id is assigned from lastInsertId() after insert
$product = Product::create([
    'name'   => 'Alice',
    'price'  => 12.5,
    'status' => 'active',
]);

// Find
$product = Product::find(1); // instance or null

// Update — dirty tracking: UPDATE only for modified fields
$product->name = 'Updated';
$product->save();

// Query with the model-aware ActiveQuery
$active   = Product::where('status', 'active')->orderBy('name', 'ASC')->limit(10)->get();
$pending  = Product::where('status', 'pending')->first();
$all      = Product::all();

// Delete
$product->delete();
```

`ActiveQuery` extends `QueryBuilder` and converts raw rows into hydrated model
instances via `newFromBuilder()`.

### Change tracking & persistence (`BaseActiveRecord`)

- **Dirty attributes (`getDirty()`)**: tracks modified properties compared to
  `$original`; `save()` executes `UPDATE` **only** on modified attributes.
- **`save()`** determines INSERT vs UPDATE from the model's `$exists` state.
- **Primary key assignment**: the generated ID from `$connection->lastInsertId()`
  is assigned to the model upon insertion.
- **Validation error bag**: `getErrors()` / `setErrors()` expose a validation
  error store that higher layers (e.g. `ModelEndpoint`) populate when schema
  rules fail, serialised back to the client.

### Serialization control (`$hidden`)

To prevent sensitive attributes (password hashes, tokens, etc.) from being
included in `toArray()`, `jsonSerialize()`, or API responses, declare a
`$hidden` array on your model:

```php
namespace App\Models;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

class User extends BaseActiveRecord
{
    protected array $hidden = ['password_hash', 'auth_key'];
}
```

- `toArray()` filters out keys listed in `$hidden` using
  `array_diff_key($attributes, array_flip($this->hidden))`.
- The filter applies on **every** serialization pass (including early returns
  from cycle guards) and is applied recursively to loaded relations — each
  nested model uses **its own** `$hidden` declaration.
- The filter runs on `jsonSerialize()` and `toArray()`, so API responses
  automatically exclude hidden attributes.

### Connection resolver vs direct connection

For request-scoped environments (Swoole, RoadRunner), prefer a **resolver**
over a static connection so each request gets a fresh connection from the
container:

```php
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;

BaseActiveRecord::setConnectionResolver(
    fn (): Connection => $container->get(Connection::class)
);
```

`setConnectionResolver()` is preferred over `setConnection()` because it
returns the current connection on each call, which is essential in persistent
runtimes where the container may swap the underlying connection per request.
The static `$dbConnection` property is only used as a fallback when no resolver
is set.

### Relationships & Eager Loading

`BaseActiveRecord` supports expressive model relationships:

- **`hasMany(RelatedModel::class, ?foreignKey, ?localKey)`**
- **`hasOne(RelatedModel::class, ?foreignKey, ?localKey)`**
- **`belongsTo(RelatedModel::class, ?foreignKey, ?ownerKey)`**

```php
namespace App\Models;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

class User extends BaseActiveRecord
{
    public function posts(): \Phue\Core\Database\ActiveRecord\Relations\HasMany
    {
        return $this->hasMany(Post::class);
    }
}

class Post extends BaseActiveRecord
{
    public function user(): \Phue\Core\Database\ActiveRecord\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

Lazy resolution caches the relation instance:
```php
$user = User::find(1);
$posts = $user->posts; // Fetched via relationship and cached
```

**Eager loading (`with`)**: Avoids N+1 query loops by batching relation queries:
```php
$posts = Post::with('user')->limit(20)->get();
```

### Attribute Casting

Models can declare cast types for columns:

```php
class Order extends BaseActiveRecord
{
    protected array $casts = [
        'is_paid' => 'bool',
        'items' => 'json',
        'total' => 'float',
        'created_at' => 'datetime',
    ];
}
```

Supported cast types: `int`, `float`, `bool`, `string`, `array`, `json`, `datetime`. Additionally, models using `#[Field(type: '...')]` automatically inherit casting metadata.

---

## 5. Data Mapper ORM (`Phue\Core\Database\DataMapper`)

Where Active Record couples models to the query builder, the **Data Mapper**
subsystem keeps domain entities pure and delegates persistence to a dedicated
mapper layer.

| Class | Responsibility |
|---|---|
| `Entity` | Abstract domain base: static `tableName()` (abstract) and `primaryKey()` (defaults to `'id'`) |
| `Mapper` | Bidirectional row↔entity conversion: camelCase props ↔ snake_case columns |
| `Repository` | Query-builder integration, identity-map lookup, hydration & persistence queueing |
| `UnitOfWork` | Identity map, dirty tracking snapshots, atomic commit/rollback |

### Entity (pure domain)

```php
use Phue\Core\Database\DataMapper\Entity;

class UserEntity extends Entity
{
    public ?int    $id          = null;
    public string  $username    = '';
    public ?string $email       = null;
    public ?string $lastLoginAt = null;

    public static function tableName(): string { return 'users'; }
    // primaryKey() defaults to 'id' — override if the PK is not 'id'
}
```

### Repository & UnitOfWork in action

```php
use Phue\Core\Database\Connection;
use Phue\Core\Database\DataMapper\Mapper;
use Phue\Core\Database\DataMapper\Repository;
use Phue\Core\Database\DataMapper\UnitOfWork;

$connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
$mapper     = new Mapper();
$uow        = new UnitOfWork($connection, $mapper);
$users      = new Repository($connection, $uow, $mapper, UserEntity::class);

// Insert — deferred until commit
$ada = new UserEntity();
$ada->username = 'ada';
$users->persist($ada);
$uow->commit();                  // atomic; $ada->id gets the auto-increment value

// Identity map — the same object is returned for the same row
$again = $users->findById($ada->id);
// $again === $ada

// Dirty tracking — only changed columns are written
$ada->email = 'ada@example.com';
$uow->commit();

// Filtered query scoped to the entity table
$result = $users->query()->where('username', 'like', 'ad%')->get();

// Delete
$users->remove($ada);
$uow->commit();
```

### Mapper semantics

- `hydrate(array $row, string $entityClass, UnitOfWork $uow): object` —
  instantiates without a constructor, maps snake_case row keys onto camelCase
  properties (`stock_level` → `stockLevel`), and registers the entity in the
  identity map when the PK is present.
- `dehydrate(object $entity): array` — maps initialized camelCase properties
  back to snake_case keys. **Uninitialized** properties are skipped; explicit
  `= null` defaults are included.
- `toSnakeCase()` / `toCamelCase()` name helpers (stable round-trip).

### UnitOfWork semantics

- `commit()` wraps inserts, dirty updates, and deletes in one DB transaction,
  with `rollback` on any failure.
- **Identity map** keyed `"{table}:{pk}"` guarantees at most one live instance
  per row.
- **Dirty tracking** compares the current `dehydrate()` output against the
  original snapshot (`array_diff_assoc`) and issues `UPDATE` only for changed
  columns.
- **Auto-increment vs exogenous PKs**: when the dehydrated data has no PK value,
  `lastInsertId()` is read back and assigned; if the PK is already present
  (e.g. a string customer code), it is left untouched.
- New entities can be cancelled pre-commit via `registerRemoved()` (removed
  from the insert queue).

### Custom / string primary keys

```php
class CustomerEntity extends Entity
{
    public ?string $customerCode = null;
    public string  $fullName     = '';

    public static function tableName(): string { return 'customers'; }
    public static function primaryKey(): string { return 'customer_code'; }
}
```

```php
$repo = new Repository($connection, $uow, new Mapper(), CustomerEntity::class);
$cus  = new CustomerEntity();
$cus->customerCode = 'C-200';
$cus->fullName     = 'John Smith';
$repo->persist($cus);
$uow->commit();

$found = $repo->findById('C-200');    // $found === $cus (identity map hit)
```

---

## 6. Serverless / HTTP-only drivers

For databases reached over HTTPS instead of a raw TCP socket — serverless Postgres,
libSQL, or any host whose outbound sockets are restricted — Phue provides
HTTP-based drivers implementing `HttpDriverInterface`
(`setEndpoint()`, `setAuthToken()`, `query()`, `execute()`, `getName()`):

```php
use Phue\Core\Database\Drivers\TursoDriver;
use Phue\Core\Database\Drivers\NeonDriver;

// Turso libSQL at the Edge over HTTP (/v2/pipeline) — dialect name 'sqlite'
$turso = new TursoDriver('https://db-org.turso.io', 'your_auth_token');
$users = $turso->query('SELECT * FROM users WHERE active = ?', [1]);

// Neon Serverless Postgres over HTTP (/sql) — dialect name 'pgsql'
$neon = new NeonDriver('https://ep-proj-1234.neon.tech/sql', 'your_auth_token');
$affected = $neon->execute('UPDATE users SET status = ? WHERE id = ?', ['active', 42]);
```

---

## 7. Migrations

### Explicit migrations (`Migration.php` + `Migrator.php`)

Generate a timestamped migration file:

```bash
./phue make:migration create_orders_table
# Generates: database/migrations/<timestamp>_create_orders_table.php
```

Write the migration logic:

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

Run / roll back:

```bash
./phue migrate             # apply pending migrations
./phue migrate:rollback    # roll back the last applied batch
```

The `Migrator` manages the tracking table, batching, and transaction isolation;
the DB connection is resolved from `config/database.php` (never a hardcoded
path).

### Schema migrations from Schii attributes

For model-driven tables, Schii can auto-migrate missing tables/columns — see
[Schii](Schii.md#7-auto-migration).

```bash
./phue schii:migrate "App\Models\Product"
```

### SQL identifier safety (`SqlIdentifier.php`)

`Phue\Core\Database\SqlIdentifier` sanitizes SQL identifiers with regex
whitelist validation (`/^[a-zA-Z_][a-zA-Z0-9_]*$/`) and provides dialect-aware
quoting: backticks for MySQL/SQLite, double quotes for PostgreSQL.

---

## Debugging & Profiling

For query debugging (N+1 guard, EXPLAIN ANALYZE) and persistent-safe dumping,
see [Debugging](Debugging.md).

---

## 8. Soft Deletes

Models can opt into soft deletes using the `#[SoftDelete]` attribute. Instead of
removing rows, `delete()` sets a timestamp column; queries automatically exclude
soft-deleted records unless explicitly opted in.

### Enabling soft deletes

```php
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\SoftDelete;

#[SoftDelete]
class User extends BaseActiveRecord
{
    protected string $table = 'users';
}
```

The attribute accepts optional configuration:

```php
// Default: column = 'deleted_at', cascade = false
#[SoftDelete(column: 'deleted_at', cascade: false)]
class User extends BaseActiveRecord { }

// Custom column name
#[SoftDelete(column: 'archived_at')]
class Post extends BaseActiveRecord { }
```

The column must be a nullable datetime/timestamp column in the database.

### Migration helper

Use `addSoftDeleteColumn()` in a migration:

```php
use Phue\Core\Database\Migration;

final class M2026_10_10_120000_Add_Soft_Delete_To_Users extends Migration
{
    public function up(): void
    {
        $this->addSoftDeleteColumn('users');           // adds deleted_at
        $this->addSoftDeleteColumn('posts', 'archived_at'); // custom column
    }

    public function down(): void
    {
        // Revert manually if needed
    }
}
```

### Model methods

| Method | Behavior |
|--------|----------|
| `delete()` | **Soft delete** — sets `deleted_at = NOW()`, marks model as not persisted (`exists = false`). |
| `forceDelete()` | **Hard delete** — permanently removes the row, bypassing soft delete. |
| `restore()` | Sets `deleted_at = NULL`, marks model as persisted again. Only works on soft-deleted models. |
| `isTrashed()` | Returns `true` if the model has a non-null deletion timestamp. |
| `isPersisted()` | Returns `true` if the model exists in the database (inserted or loaded). |

```php
$user = User::create(['name' => 'Makarios', 'email' => 'makarios@example.com']);

// Soft delete
$user->delete();
$user->isTrashed();      // true
$user->isPersisted();    // false

// Restore
$user->restore();
$user->isTrashed();      // false
$user->isPersisted();    // true

// Permanent removal
$user->forceDelete();
```

### Query scopes

Soft delete models have an **automatic global scope**: `WHERE deleted_at IS NULL`
is applied to all queries by default.

```php
// Excludes soft-deleted (default)
User::all();
User::find(1);
User::where('status', 'active')->get();

// Include soft-deleted
User::withTrashed()->all();
User::withTrashed()->find(1);

// Only soft-deleted
User::onlyTrashed()->all();
User::onlyTrashed()->where('email', 'test@example.com')->first();

// Static forwarding works too
User::withTrashed()->count();
```

| Scope | SQL condition |
|-------|---------------|
| Default | `deleted_at IS NULL` |
| `withTrashed()` | (no condition) |
| `onlyTrashed()` | `deleted_at IS NOT NULL` |

### Bulk operations

For bulk soft deletes, use the query builder directly:

```php
$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

User::query()
    ->whereIn('email', ['a@test.com', 'b@test.com'])
    ->update(['deleted_at' => $now]);
```

### Custom column names

If you use a custom column (e.g., `archived_at`), all methods and scopes work
transparently:

```php
#[SoftDelete(column: 'archived_at')]
class Post extends BaseActiveRecord { }

$post = Post::create(['title' => 'Hello']);
$post->delete();           // sets archived_at
$post->isTrashed();        // true
$post->restore();          // sets archived_at = NULL

Post::onlyTrashed()->get(); // WHERE archived_at IS NOT NULL
```

### Important notes

- **Models without `#[SoftDelete]` are completely unaffected** — zero overhead.
- **Relations are not cascaded automatically** (the `cascade` option is reserved
  for future use). Use explicit queries for related records:
  ```php
  $user->posts()->withTrashed()->get(); // include trashed posts
  ```
- **Unique indexes**: If you need unique constraints that ignore soft-deleted
  rows, use a partial/filtered index (PostgreSQL `WHERE deleted_at IS NULL`,
  MySQL 8.0+ filtered index).
- **Works with all drivers**: SQLite, MySQL, PostgreSQL, Turso, Neon.
