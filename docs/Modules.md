# Modules

The module system is how Phue stays small at its core: everything beyond the
framework primitives — auth, server-driven UI, REST, server adapters, events,
the compiler, dev tools — ships as a **module** that follows one uniform
contract.

Related guides: [Core §3](Core.md) (contract overview), [CLI](CLI.md)
(`module:new` command).

---

## 1. The contract

A module is a class extending `Phue\Core\Module` (`src/Core/Module.php`) with a
short `id`, an optional dependency list, and hook overrides.

```php
<?php

declare(strict_types=1);

namespace Phue\Modules\Example;

use Phue\Core\Module;
use Phue\Core\Console\Application;

class ExampleModule extends Module
{
    public string $id = 'example';
    public string $type = 'layer';          // 'layer' | 'kit'
    /** @var array<int, string> */
    public array $requires = [];             // module ids this module depends on

    public function init(): void
    {
        $this->config();      // merge default config with config/<id>.php
        $this->bindings();    // register DI bindings
        $this->routes();      // register application routes
    }

    public function registerCommands(Application $console): void {}
}
```

### Properties

| Property | Type | Meaning |
|---|---|---|
| `$id` | `string` | Short identifier. Registered in the container as `module.<id>` when non-empty |
| `$type` | `string` | `'layer'` (functional subsystem) or `'kit'` (UI kit) |
| `$requires` | `array<int, string>` | Module **ids** this module depends on — verified at boot |
| `$basePath` | `string` | Injected by `Application` at boot |

### Lifecycle hooks

| Hook | Purpose |
|---|---|
| `init(): void` *(abstract — must implement)* | Called by `Application` after infrastructure injection. Convention: `config()` → `bindings()` → `routes()`. |
| `config(): void` *(protected)* | Read `config/<id>.php` and bind config value objects |
| `bindings(): void` *(protected)* | Register services in the DI container |
| `routes(): void` *(protected)* | Add routes to the router |
| `getRoutesForCaching(): array` *(public)* | Return programmatic routes for CLI route caching (`route:cache`) |
| `registerCommands(Application): void` | Add CLI commands (`Application` is `Phue\Core\Console\Application`) |
| `nav(): array` *(protected)* | Navigation items for `AppLayout` |
| `permissions(): array` *(protected)* | RBAC permission seeds |
| `assets(): array` *(protected)* | Register CSS/JS assets |

### Infrastructure methods

| Method | Purpose |
|---|---|
| `setDependencies(Router, Container, EventDispatcher)` | Called by `Application` before `init()` — gives the module access to the router, container, and event dispatcher |
| `getModule(string $id): ?self` | Resolve another module from the container (`module.<id>`) |
| `getController(string $id): ?object` | Resolve a controller from `controllerMap` or `controllerNamespace` |
| `requiresDb(): bool` | Override to declare a database requirement (default `false`) |

### How modules boot

`Application::loadModules()` uses a **two-pass strategy**:

1. **Pass 1** — every FQCN in `config/app.php` is validated, instantiated
   through the container, checked against `Phue\Core\Module`, given its
   `basePath` + `setDependencies()`, and `init()` is called. Each module is
   registered as a container singleton under `module.<id>`.
2. **Pass 2** — every module's `$requires` list is verified against the loaded
   modules; an unmet dependency throws:

   > `Module 'rest' requires 'auth', which is not loaded. Add it to config/app.modules.`

Because all modules mount **before** dependencies are verified, the order of
entries in `config/app.php` does not matter — a module can safely check
`$this->container->has('module.auth')` inside `init()`.

> **`$id` vs class name:** the module *id* (e.g. `'devtools'`) and the class
> name (`DevToolsModule` in the `DevTool` directory) do not have to match. The
> id is what `requires` lists and what the container registers.

---

## 2. Available modules

All modules live under `src/Modules/` and are registered in `config/app.php`.
Inspect the current graph any time with:

```bash
./phue module:list
```

| Module class | id | requires | What it provides |
|---|---|---|---|
| `Phue\Modules\Auth\AuthModule` | `auth` | *(none)* | `AuthManager` (request-scoped), `RbacManager` (singleton), Bearer authenticator wired to `config/auth.php` |
| `Phue\Modules\Schii\SchiiModule` | `schii` | *(none; `auth` added at runtime when `requireAuth`)* | Schema registry, zero-controller REST, Schii admin console at `/schii`, Phii panel at `/phii` |
| `Phue\Modules\Rest\RestModule` | `rest` | `auth` | Attribute-driven `#[Rest]` CRUD endpoints under `config/rest.php` prefix |
| `Phue\Modules\Queue\QueueModule` | `queue` | *(none)* | Background job processing, sync/database drivers, atomic CAS claim, dead-letter queue, CLI worker (`queue:*`) |
| `Phue\Modules\Server\ServerModule` | `server` | *(none)* | Registers the Swoole serve command (`serve`) |
| `Phue\Modules\Events\EventModule` | `events` | *(none)* | Event bus drivers, SSE response/streamer, `#[Broadcastable]` |
| `Phue\Modules\Websocket\WebsocketModule` | `websocket` | *(none; uses `auth` when present)* | Named WebSocket **endpoints**, each with its own topics, origins and authentication. `Broadcast` for publishing. Needs the Swoole adapter |
| `Phue\Modules\Compiler\CompilerModule` | `compiler` | *(none)* | AOT wiring generator, compatibility checker, fallback strategy |
| `Phue\Modules\DevTool\DevToolsModule` | `devtools` | *(none)* | Request profiler + collapsible debug toolbar, instrumenting the SSR transport, component loaders and the database driver (enabled when `app.debug` **and** `app.devTool`) |

Notes worth knowing:

- **QueueModule**: provides `Queue` facade (`Queue::push()`, `Queue::later()`), `JobInterface`, `#[Job]` attribute, and database-backed worker commands (`queue:install`, `queue:work`, `queue:status`, `queue:failed`, `queue:prune`). Requires a DB connection only when `queue.driver` is set to `database`. See [Queue](Queue.md).
- **Schii + auth**: `SchiiModule` reads `schii.requireAuth` from
  `config/schii.php`. When it is `true` **and** the auth module is loaded, it
  appends `'auth'` to its own `requires` and routes CRUD through
  `AuthorizedModelEndpoint`; otherwise it degrades gracefully to the bare
  `ModelEndpoint`.
- **DevTool gating**: `DevToolsModule::bindings()` returns early unless both
  `app.debug` and `app.devTool` are set — the toolbar leaks request internals
  into the page, so a non-debug context must be unreachable however the flags
  are combined. The live-reload watcher is installed *inside* that gate: it
  depends on the `ChangeTracker` registered under the same two flags, so
  registering the timer outside it meant a production Swoole server ran the
  callback once a second and it threw, once a second, for a feature nobody had
  switched on. The module does not merely attach middleware: it binds the
  collector as a singleton and installs decorators over the SSR transport, the
  component loaders and the database driver. Panel toggles, theme mode and SQL
  redaction live in `config/devtool.php`; see
  [Server Adapters §5](Server-Adapters.md#5-devtools-phuemodulesdevtool).
- **WebSocket endpoints**: the module takes a `websockets` map rather than one
  set of settings, because authentication and transport do not change together —
  an application's realtime traffic is user data and must never be reachable
  without a login, while the DevTools reload channel carries none and demanding a
  login for it would only make it stop working for whoever is not signed in.
  Each endpoint keeps its **own** `TopicRegistry`, which is what makes the
  per-endpoint `auth` flag a real boundary rather than a label. Enabling the
  module needs `APP_WEBSOCKET`; `APP_DEBUG` only adds the `dev` endpoint and
  relaxes origins. A module that wires itself up registers `phue.websocket.active`,
  so another module can ask whether realtime is really available rather than
  guessing from the presence of a class name. A handshake needs
  the Swoole adapter, and Swoole ignores a close issued from inside `on('open')`,
  so a refusal is delivered on the client's first frame — see
  [WebSocket](WebSocket.md).
- **Rest requires auth**: enforced twice — by `Application::loadModules()`
  (boot-time `RuntimeException`) and by the `phue-create` installer, which
  auto-selects `auth` when you pick `rest`.

---

## 3. Creating a module

### With the CLI

```bash
./phue module:new Queue --type=layer --requires=auth
```

This scaffolds, following the module contract:

- `src/Modules/Queue/QueueModule.php` — module class with `$id = 'queue'`,
  `$type`, `$requires`, and `init()` calling `config()`/`bindings()`/`routes()`
- `config/queue.php` — default config (`['enabled' => true]`)
- `tests/Queue/QueueModuleTest.php` — contract-compliance test
- `tests/Queue/.gitkeep`

Flags:

| Flag | Purpose |
|---|---|
| `--type=layer|kit` | Module type (default `layer`) |
| `--requires=a,b` | Dependency ids |
| `--output=<path>` | Write to a different base path |

The module name must be alphabetic and PascalCase (`Queue`) — the id becomes
`lcfirst($name)` (`queue`).

### Register it

Add the FQCN to `config/app.php`:

```php
'modules' => [
    // ...
    \Phue\Modules\Queue\QueueModule::class,
],
```

Then verify:

```bash
./phue module:list
```

### Registering CLI commands from a module

A module can contribute commands to `./bin/phue` — the entry point iterates
every configured module and calls `registerCommands($app)`:

```php
public function registerCommands(Application $console): void
{
    $console->add(new \Phue\Modules\Queue\Commands\WorkCommand());
}
```

This is how the `Server` module registers `serve` (Swoole) and the `Schii`
module registers `schii:migrate`, `schii:export`, `schii:serve`.

### Per-module tests

The test suite is organized **one testsuite per module** (`phpunit.xml`):
`core`, `schii`, `rest`, `phii`, `auth`, `server`, `events`, `compiler`,
`devtools`, plus a `kit` suite for cross-module tests. Run a single module's
suite:

```bash
vendor/bin/phpunit --testsuite auth
```

Isolation rules for module tests:

- Tests under `tests/<Module>/` use `Phue\Tests\Support` fixtures (SQLite
  factory, request factory) and their own logic — no `\App` references.
- Cross-cutting behavior (e.g. Schii degrading without auth) has dedicated
  tests in both suites (`tests/Schii/SchiiAuthIsolationTest.php`).

---

## 4. Module configuration

Each module reads its own `config/<id>.php`. Module config files follow the
same conventions as the rest of `config/` (a PHP file returning an array, keyed
by filename — see [Core §5](Core.md)).

The typical pattern inside a module's `bindings()` is to bind a config value
object from the module's own config section:

```php
protected function bindings(): void
{
    $this->container->singleton(
        ExampleConfig::class,
        function (): ExampleConfig {
            $raw = $this->container->has(Config::class)
                ? $this->container->get(Config::class)->get('example', [])
                : [];

            return ExampleConfig::fromArray($raw);
        }
    );
}
```

This is exactly how `RestConfig` (from `config/rest.php`) and `SchiiConfig`
(from `config/schii.php`) are resolved — see [REST](REST.md) and [Schii](Schii.md).
