# Queue

The queue module provides background job processing with a driver-based architecture.
It ships with a synchronous inline driver (default) and a database-backed driver
with compare-and-set atomic claims, lease-based reaper, and dead-letter queue.

Related guides: [Core](Core.md), [Database](Database.md), [CLI](CLI.md).

---

## 1. Architecture

```
QueueModule (layer, no deps, default: sync)
├── QueueDriverInterface (contract)
│   ├── SyncQueueDriver      (inline execution, default)
│   └── DatabaseQueueDriver  (database-backed, CAS claim, lease, reaper)
├── QueueManager             (push, reserve, runNext, stats, prune)
├── Queue facade             (Queue::push(), Queue::later(), Queue::stats())
├── JobInterface             (single handle(JobContext) method)
├── #[Job] attribute         (per-job overrides: queue, tries, timeout, backoff)
├── QueueSchema              (idempotent install/upgrade, module-owned tables)
└── Commands
    ├── queue:install   (create/upgrade schema)
    ├── queue:work      (--once|--max-jobs=N|--daemon)
    ├── queue:status    (counts per queue)
    ├── queue:failed    (dead letters with payload/error)
    └── queue:prune     (completed TTL, failures kept 10× longer)
```

---

## 2. Installation

```bash
# Install the queue module
php bin/phue queue:install

# Run a worker (bounded by default, safe for cron)
QUEUE_DRIVER=database php bin/phue queue:work --max-jobs=100
```

The `queue:install` command creates three tables (prefix from `queue.table` config):
- `queue_jobs`        — live jobs (claim via CAS, lease, reaper)
- `queue_jobs_failed` — dead-letter records (exception + payload preserved)
- `queue_jobs_meta`   — schema version for idempotent upgrades

**No application migrations.** The schema is module-owned; it never rolls back with
application migrations, so a deploy rollback never drops the queue table while a
worker is holding a job.

---

## 3. Configuration

`config/queue.php` (defaults shown):

```php
return [
    'driver'           => env('QUEUE_DRIVER', 'sync'),   // sync | database | class-string
    'queue'            => env('QUEUE_DEFAULT_QUEUE', 'default'),
    'max_tries'        => 3,
    'backoff_base'     => 5,          // seconds, doubled per retry
    'backoff_max'      => 600,        // ceiling
    'backoff_jitter'   => true,       // deterministic spread by job id
    'lease_seconds'    => 60,         // worker lease duration
    'max_payload_bytes'=> 65536,      // refused at push, not truncated
    'table'            => 'queue_jobs',
    'job_namespaces'   => [],         // empty = unrestricted
    'drivers'          => [],         // extra drivers keyed by name
];
```

Environment overrides:

```bash
QUEUE_DRIVER=database        # use database driver
QUEUE_DEFAULT_QUEUE=emails   # default queue name
```

**Payload size limit** is enforced at `QueueManager::push()`. A job whose JSON payload
exceeds `max_payload_bytes` throws `QueuePayloadException` **at enqueue time**, not
later in a worker.

---

## 4. Creating Jobs

Jobs are plain classes implementing `JobInterface` (one method). The payload is
derived from constructor parameters — **no manual serialization**.

```php
namespace App\Jobs;

use Phue\Modules\Queue\JobInterface;
use Phue\Modules\Queue\JobContext;

final class SendWelcomeEmail implements JobInterface
{
    public function __construct(
        public readonly int    $userId,
        public readonly string $template = 'welcome',
    ) {}

    public function handle(JobContext $ctx): void
    {
        // $this->userId, $this->template available
        // $ctx->attempts     — current attempt (1-based, readonly property)
        // $ctx->maxTries     // from #[Job] or config
        // $ctx->timeout      // lease seconds remaining, readonly property
        // $ctx->release(30)  // requeue with delay (throws)
        // $ctx->fail($e)     // dead-letter immediately (throws)
        // $ctx->isFinalAttempt()  // true if next failure = dead letter
        // $ctx->put('k', $v) / $ctx->get('k') — attempt-scoped bag
    }
}
```

**Payload is constructor arguments** — promoted properties become JSON fields
automatically. No `serializePayload()`/`deserializePayload()` boilerplate.
Override with `toPayload()`/`fromPayload()` only if the job has non-serializable
state (private constructor, external resource).

### Per-job overrides (`#[Job]` attribute)

```php
use Phue\Modules\Queue\Attribute\Job;

#[Job(queue: 'emails', tries: 1, timeout: 30, backoff: 0)]
final class SendWelcomeEmail implements JobInterface { ... }
```

Precedence: caller option > `#[Job]` > config default.

---

## 5. Enqueueing

```php
use Phue\Modules\Queue\Queue;

Queue::push(new SendWelcomeEmail(42));                    // immediate
Queue::later(new SendWelcomeEmail(42), 60);               // delay 60s
Queue::push(new SendWelcomeEmail(42), 'emails');          // custom queue
```

Returns the job ID (string). Fails at enqueue time for:

- negative delay
- delay > 0 on sync driver (throws, does not run immediately)
- payload > `max_payload_bytes`
- job class outside `job_namespaces` (if configured)
- job class not implementing `JobInterface`

---

## 6. Processing Jobs

### Bounded run (default, cron-friendly)

```bash
QUEUE_DRIVER=database php bin/phue queue:work --max-jobs=100
```

Runs up to 100 jobs then exits. Safe for cron (`* * * * *`).

### One shot

```bash
php bin/phue queue:work --once
```

Processes one job and exits. Good for testing or systemd oneshot.

### Daemon (supervised)

```bash
QUEUE_DRIVER=database php bin/phue queue:work --daemon
```

Runs forever. Requires **ext-pcntl** for graceful SIGTERM/SIGINT. If unavailable,
exits with error — a worker that cannot stop cleanly on SIGTERM will be killed
mid-job on every supervisor restart.

### Queue targeting

```bash
php bin/phue queue:work --queue=emails
```

Only processes the named queue.

### Timeout

```bash
php bin/phue queue:work --max-seconds=300
```

Stops after 300 seconds even if jobs remain.

---

## 6.1 Worker Isolation (Critical)

In persistent runtimes (Swoole, RoadRunner) the worker runs in the same process
as HTTP requests. The worker **does not rely on Application::handle()** — it
manages isolation per job:

```php
// Inside runOne()
$scope = $container->createScope();
$container->setScope($scope);
try {
    $manager->run($envelope, $workerId);
} finally {
    $connection->reset();     // rollback open transaction
    $container->flushScope($scope);
    $container->setScope(null);
}
```

Each job gets a **fresh container scope** — scoped services are rebuilt, scoped
bindings are discarded, open transactions are rolled back. A job that dies mid-
transaction does not leak locks to the next job.

---

## 6.2 Retries & Backoff

- Attempts counted at **claim time** (not enqueue) — a job waiting 3 hours and
  then failing has made 1 attempt.
- Backoff: `min(base * 2^(attempt-1), max)` + deterministic jitter (hash of
  job_id + attempt). Jitter is deterministic for test stability.
- `max_tries` from `#[Job(tries:)]` or config `max_tries`.
- On failure: if attempts < max_tries → `release(backoff)`; else → dead-letter.
- Non-retryable: throw `NonRetryableException` → dead-letter immediately.
- Control flow from inside handle():
  - `$ctx->release(30)` → requeue with delay (throws `JobReleasedException`)
  - `$ctx->fail($e)` → dead-letter immediately (throws `JobFailedException`)

---

## 6.3 Lease & Reaper

- Claim sets `reserved_until = now + lease_seconds` and `reserved_by = worker_id`.
- **No worker heartbeat.** If the worker dies, the lease expires and the job
  becomes claimable by another worker.
- `reclaimExpiredLeases()` runs every N iterations (default 25) and resets
  expired reservations to `pending`.
- `queue:prune` removes completed jobs older than N seconds; failures kept
  10× longer (default 90 days vs 7 days).

---

## 6.4 Ownership Guard (CAS)

Every terminal operation validates ownership:

```sql
-- complete
UPDATE ... SET status='completed' WHERE id=? AND status='reserved' AND reserved_by=?

-- release
UPDATE ... SET status='pending' WHERE id=? AND status='reserved' AND reserved_by=?

-- fail
UPDATE ... SET status='failed' WHERE id=? AND status='reserved' AND reserved_by=?
```

Returns `bool` (affected rows). A worker whose lease expired cannot complete a
job another worker now owns.

---

## 6.5 Dead Letter Queue

Jobs that exhaust `max_tries` or throw `NonRetryableException` move to
`queue_jobs_failed` (separate table). Stores: full payload, exception class/
message/trace, failed_at, attempts.

```bash
php bin/phue queue:failed          # list (default 20)
php bin/phue queue:failed --limit 50
php bin/phue queue:status          # shows failed count
```

---

## 7. Commands Reference

| Command | Description |
|---|---|
| `queue:install` | Create/upgrade schema (idempotent, safe to re-run) |
| `queue:work [--once\|--max-jobs=N\|--daemon] [--queue=x] [--max-seconds=N]` | Run worker |
| `queue:status [--queue=x]` | Counts per status |
| `queue:failed [--limit=N] [--queue=x]` | Dead letters with error/payload |
| `queue:prune [--days=N]` | Remove completed > N days (default 7), failures kept 10× |

All commands refuse with clear message if tables are not installed:
> "The queue table does not exist. Run `php bin/phue queue:install`..."

---

## 8. Queue Facade

```php
use Phue\Modules\Queue\Queue;

Queue::push(new SendWelcomeEmail(42));          // id
Queue::later(new SendWelcomeEmail(42), 60);     // delay
Queue::stats();                                 // all queues
Queue::stats('emails');                         // single queue
```

In tests: `Queue::swap($mockManager)` to replace the manager.

---

## 8.1 Job Context

```php
final class JobContext
{
    public readonly string $jobId;
    public readonly string $queue;
    public readonly string $jobClass;
    public readonly int    $attempts;       // 1-based
    public readonly int    $maxTries;
    public readonly int    $timeout;        // lease seconds
    public readonly string $workerId;
    public readonly ?int   $reservedAt;

    public function release(int $delaySeconds = 0): never;
    public function fail(?\Throwable $reason = null): never;
    public function isFinalAttempt(): bool;
    public function put(string $key, mixed $value): mixed;    // attempt-scoped bag
    public function get(string $key, mixed $default = null): mixed;
}
```

Methods `release()` and `fail()` throw control-flow exceptions (`JobReleasedException`,
`JobFailedException`) — they do not return. The worker catches them and records the
outcome.

---

## 9. Database Driver Details

### Atomic Claim (CAS)

```sql
UPDATE queue_jobs
SET status='reserved', reserved_at=?, reserved_until=?, reserved_by=?, attempts=attempts+1
WHERE id = (
    SELECT id FROM queue_jobs
    WHERE status='pending' AND available_at<=? AND (queue=? OR 1)
      AND (status='pending' OR (status='reserved' AND reserved_until<=?))
    ORDER BY available_at, id LIMIT 1
) AND (status='pending' OR (status='reserved' AND reserved_until<=?))
```

- Single statement, no transaction.
- Works on MySQL, PostgreSQL, SQLite (no `SKIP LOCKED` needed).
- Expired leases (`status='reserved' AND reserved_until<=now`) are reclaimed
  automatically in the same query.

### Dead Letter Table

```sql
CREATE TABLE queue_jobs_failed (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue VARCHAR(64) NOT NULL,
    job_class VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    exception TEXT NOT NULL,
    failed_at BIGINT NOT NULL
);
CREATE INDEX ... ON queue_jobs_failed (failed_at);
CREATE INDEX ... ON queue_jobs_failed (queue, job_class);
```

---

## 10. Edge / Serverless

- **Sync driver** works everywhere (no DB, no worker needed).
- **Database driver** works with any driver in `config/database.php`:
  - PDO: sqlite, mysql, pgsql
  - HTTP edge: Neon (libSQL), Turso (libSQL) — via HTTP drivers in core
- No `pcntl` required for `--once` / `--max-jobs`.
- `--daemon` requires ext-pcntl (exits with message if absent).

---

## 11. Testing

```php
use Phue\Tests\Queue\Stubs\PlainJob;
use Phue\Modules\Queue\QueueManager;
use Phue\Modules\Queue\Driver\SyncQueueDriver;
use Phue\Modules\Queue\QueueConfig;

$manager = new QueueManager(new SyncQueueDriver(), QueueConfig::fromArray([]));
$id = $manager->push(new PlainJob('test'));
$manager->runNext();  // runs inline
```

In tests, `Queue::swap($mockManager)` replaces the facade's manager.

---

## 12. Common Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Job stuck in `reserved` | Worker died, lease not expired | Wait lease seconds, or `reclaimExpiredLeases()` |
| `Class "X" does not exist` | Job class not autoloadable / not in `job_namespaces` | Fix autoload or add namespace to `job_namespaces` |
| `delay` ignored on sync driver | Sync driver runs inline, no delay | Use `--max-jobs=1` for sync testing, or switch to `database` driver |
| `Class "X" does not implement JobInterface` | Job class missing `implements JobInterface` | Add `implements JobInterface` |
| Memory ceiling triggered early | Process already near limit | Baseline resets per `run()`; check `memory_limit` |

---

## 13. What's NOT Included (Future Slices)

- **Redis driver** (Slice E): Redis Streams / BRPOPLPUSH with consumer groups
- **queue:listen**: restart-on-change dev worker
- **queue:retry**: replay dead letters
- **queue:retry-all**: replay all dead letters
- Scheduled/delayed jobs UI
- Dashboard/widgets