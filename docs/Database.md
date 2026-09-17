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
  appends an `ESCAPE '\'` clause, so backslash-escaped wildcards (`\%`, `\_`)
  are treated as literals instead of scanning the whole table.

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

## 6. Serverless / edge HTTP drivers

For environments without raw TCP sockets (WASM, edge runtimes), Phue provides
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
./bin/phue make:migration create_orders_table
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
./bin/phue migrate             # apply pending migrations
./bin/phue migrate:rollback    # roll back the last applied batch
```

The `Migrator` manages the tracking table, batching, and transaction isolation;
the DB connection is resolved from `config/database.php` (never a hardcoded
path).

### Schema migrations from Schii attributes

For model-driven tables, Schii can auto-migrate missing tables/columns — see
[Schii](Schii.md#auto-migration).

```bash
./bin/phue schii:migrate "App\Models\Product"
```

### SQL identifier safety (`SqlIdentifier.php`)

`Phue\Core\Database\SqlIdentifier` sanitizes SQL identifiers with regex
whitelist validation (`/^[a-zA-Z_][a-zA-Z0-9_]*$/`) and provides dialect-aware
quoting: backticks for MySQL/SQLite, double quotes for PostgreSQL.
