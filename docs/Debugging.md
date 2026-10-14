# Debugging & Profiling

Phue provides built-in tools for debugging queries, detecting performance issues, and inspecting runtime values — all designed to work correctly in both FPM and persistent runtimes (Swoole, RoadRunner).

---

## 1. N+1 Query Guard

The `#[NPlusOneGuard]` attribute detects N+1 query patterns in controller actions and throws a detailed exception (or logs a warning) when the same query pattern executes repeatedly within a single request.

```php
use Phue\Core\Database\Attributes\NPlusOneGuard;

class UserController
{
    #[NPlusOneGuard(threshold: 5, throw: true)]
    public function index(): Response
    {
        $users = User::all(); // 1 query
        
        foreach ($users as $user) {
            // This triggers N+1: 1 query per user!
            echo $user->posts->count(); // Throws: "N+1 query detected: 12 similar queries executed..."
        }
    }
}
```

**How it works:**
- Tracks query fingerprints (normalized SQL structure without literals)
- Groups similar queries and counts occurrences
- Triggers when count exceeds `threshold` (default: 5)
- Reports exact file:line locations from the call stack
- Request-scoped, zero overhead when not active

**Configuration:**
- `threshold` — minimum similar queries to trigger (default: 5)
- `throw` — whether to throw `RuntimeException` or just log warning (default: true)

**Auto-enabled in debug mode:** The middleware is automatically registered by `DevToolsModule` when both `app.debug` and `app.devTool` are `true` (see `config/devtool.php`). No manual registration needed.

---

## 2. QueryBuilder::dd() and explain()

The `dd()` method records the compiled SQL, the bound parameters and the driver-specific plan into the request-scoped `AsyncDumper`, then **lets execution continue**. It never terminates the process: under a persistent runtime (Swoole, RoadRunner) `exit()` raises `Swoole\ExitException` and unwinds the whole pipeline into a 500. Because the report goes through the dumper, it lands in the DevTool **Dumps** tab.

```php
use Phue\Core\Database\QueryBuilder;

$report = $db->table('users')
    ->where('status', 'active')
    ->dd(); // Dumps SQL + bindings + plan, then keeps going

$report['compiled']; // Interpolated SQL for readability
```

**Global helper (via `src/Core/Debug/helpers.php`):**
```php
dd($db->table('users')->where('active', 1)); // Same as ->dd()
```

**Report shape:**
| Key | Value |
|---|---|
| `sql` | Raw SQL with `?` placeholders |
| `bindings` | Bound parameter values (redacted by the dumper when sensitive) |
| `compiled` | SQL with the bindings interpolated in, for reading only |
| `explain` | Plan rows, or `null` when the server refused |
| `explain_error` | Why the plan is missing |
| `driver` | Driver name (`mysql`, `postgres`, `sqlite`) |
| `time` | `microtime(true)` at dump time |
| `text` | The same report as a printable block |

**Per-driver EXPLAIN:**
| Driver | Command | Format |
|---|---|---|
| MySQL 8.0.18+ | `EXPLAIN ANALYZE` | Tabular |
| MariaDB | `ANALYZE` | Tabular |
| PostgreSQL | `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` | JSON plan |
| SQLite | `EXPLAIN QUERY PLAN` | Tabular |

The MySQL driver probes `SELECT VERSION()` once per connection and picks the spelling its server understands, keeping the driver — not the query builder — responsible for dialect differences.

`ANALYZE` executes the statement it plans, so `dd()` skips the plan for anything that is not a read (`INSERT`/`UPDATE`/`DELETE`/DDL) and reports `explain_error` instead of touching your data.

**Also available:** `explain()` — returns the plan rows as an array without dumping anything.

```php
$plan = $db->table('users')->where('active', 1)->explain();
// Returns array of explain rows
```

---

## 3. AsyncDumper (Persistent-Safe Dumping)

Use `Phue\Core\Debug\AsyncDumper` — it collects dumps during request execution and flushes them at the end via middleware.

**Global helpers (via `src/Core/Debug/helpers.php`):**
```php
use Phue\Core\Debug\AsyncDumper;

// Basic dump
dump($user->posts->count());

// Conditional dump
dump_if($debug, $expensiveComputation, 'debug');

// Dump when condition is met
dump_when(fn() => $request->hasHeader('X-Debug'), $secretData);

// Sampling (10% of requests)
dump_sample(0.1, $data);

// Dump and die (uses AsyncDumper internally)
dd($query->getSql());
```

**Features for persistent runtimes:**
- **Request-isolated** — tied to request scope, auto-cleanup via `reset()`
- **Non-blocking** — doesn't touch stdout during request
- **Format-agnostic** — JSON for API, HTML/JS for browser, ANSI for CLI
- **Dev Toolbar integration** — "Dumps" tab with syntax highlighting & stack traces
- **Conditional helpers** — `dump_if()`, `dump_when()`, `dump_sample()`
- **Auto-redaction** — masks `password`, `token`, `secret`, `api_key`, `ssn`
- **Production-safe** — sampling + redaction + disabled by default

**Dev Toolbar panel** (enabled when `app.debug` + `app.devTool`):
- Collapsible list of all dumps with timestamp, label, group
- Syntax-highlighted values (arrays, objects, primitives)
- Toggleable stack trace per dump
- Request ID for correlation with logs

**Configuration (`config/devtool.php`):**
```php
'dumper' => [
    'max_dumps' => 50,           // per request
    'max_depth' => 8,            // traversal depth for objects/arrays
    'redact_keys' => ['password', 'token', 'secret', 'api_key', 'ssn'],
    'sampling_rate' => 1.0,      // 1.0 = dev, 0.01 = production debugging
],
```

**Direct usage (if you need full control):**
```php
use Phue\Core\Debug\AsyncDumper;

$dumper = $container->get(AsyncDumper::class);
$dumper->dump($value, 'label', 'group');
$dumper->dumpIf($condition, $value);
$dumper->dumpWhen($callable, $value);
$dumper->dumpSample(0.1, $value);
```