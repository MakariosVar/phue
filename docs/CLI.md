# CLI

The command-line tooling: the `phue` console, the `phue-create` project installer, the `phue-docker` wrapper, and how testing is set up.

---

## 1. `phue` — the framework console

`./phue` is a shebang script (`#!/usr/bin/env php`) that loads the `.env` file (`Env::load()`) **before** reading `config/`, creates a `Phue\Core\Console\Application`, registers the built-in commands, then lets every configured module contribute its own commands, and runs. Loading the environment first mirrors the web runtime, so `env()`-driven settings (e.g. `DB_CONNECTION`, `DB_HOST`) apply to CLI commands such as `migrate` and `schii:migrate`.

```bash
./phue list      # or --help / -h
```

### How commands are registered

- Every command implements `Phue\Core\Console\BaseCommandInterface`:
  `getName(): string`, `getDescription(): string`, `getGroup(): string`, and
  `execute(array $args, $outputStream, $errorStream): int`.
- `Console\Application::run()` takes `$argv`, treats `$argv[1]` as the command
  name and `$argv[2..]` as arguments, dispatches — or renders an ANSI-colored
  help menu for `list` / `--help`.
- Built-in commands come from `src/Core/Console/Commands/`; module commands are
  added by each module's `registerCommands()` (see [Modules](Modules.md)).
- User-space commands in `App/Commands/` are auto-discovered at runtime.

### Command Groups

Commands are organized into groups for cleaner help output. The group is defined
by each command's `getGroup()` method. Available groups:

| Group | Purpose |
|-------|---------|
| **General** | Framework core commands (no specific category) |
| **Build** | Scaffolding, code generation, project/module creation |
| **Database** | Migrations, schema management |
| **Dev** | Development tools, cache, npm, optimization |
| **Server** | Serving and deployment |
| **Custom** | User-space commands (auto-discovered from `App/Commands/`) |

The help output renders each group as a bold header, with commands listed
alphabetically within each group:

```
Build:
  new                   Scaffold a minimal isolation-harness project...
  make:controller       Creates a new Controller class...
  module:new            Scaffold a new framework module...

Database:
  migrate               Runs all pending database migrations.
  ...
```

### Sub-commands (Convention-based Routing)

Commands extending `BaseCommand` (or implementing `CommandInterface`) get
**automatic sub-command routing** based on their public method names:

```bash
phue hello:world   # calls hello->world()
phue hello:test    # calls hello->test()
phue hello         # shows help (no sub-command provided)
```

Rules:
- Any `public` method (except `getName`, `getDescription`, `getGroup`, `execute`, `showHelp`, `getSubCommands`) becomes a sub-command.
- Sub-command name = method name.
- Description comes from the method's `@description` docblock tag (or first sentence of summary).
- Sub-commands appear indented under their parent command in the help output.
- Use `command:sub arg1 arg2` syntax to invoke with arguments.

**Example** (`App/Commands/HelloCommand.php`):

```php
final class HelloCommand extends BaseCommand
{
    public function getName(): string { return 'hello'; }
    public function getGroup(): string { return 'Custom'; }

    /** @description Prints "Hello World!" */
    public function world(array $args, $out, $err): int { ... }

    /** @description Prints "Hello Test!" */
    public function test(array $args, $out, $err): int { ... }
}
```

Usage:
```bash
./phue hello:world      # "Hello World!"
./phue hello:test       # "Hello Test!"
./phue hello            # shows sub-command help
```

### Built-in Commands

Commands are grouped by their `getGroup()` assignment:

#### Build
| Command | Class | What it does |
|---|---|---|
| `new` | `NewProjectCommand` | Scaffolds a minimal isolation-harness project around specific modules (`--modules=<Name>`) to verify subportability |
| `make:controller` | `MakeControllerCommand` | Generates `App/Controllers/XxxController.php` with a `#[Route]` attribute |
| `make:model` | `MakeModelCommand` | Generates `App/Models/Xxx.php` extending `BaseActiveRecord` |
| `make:migration` | `MakeMigrationCommand` | Generates a timestamped migration under `database/migrations/` |
| `module:list` | `ModuleListCommand` | Lists installed modules + dependency graph |
| `module:new`    | `ModuleNewCommand`  | Scaffolds a new framework module under `src/Modules/`   |
| `generate:key`  | `GenerateKeyCommand` | Generates `APP_KEY` in `.env` (or fills it when empty); use `--force` to rotate an existing key |

#### Database
| Command | Class | What it does |
|---|---|---|
| `migrate` | `MigrateCommand` | Applies pending migrations (connection resolved from `config/database.php`) |
| `migrate:rollback` | `MigrateRollbackCommand` | Rolls back the last applied batch |
| `schii:migrate` | `SchiiMigrateCommand` | Auto-migrates database tables from Schii entity schemas |

#### Dev
| Command | Class | What it does |
|---|---|---|
| `cache:clear` | `CacheClearCommand` | Clears application cache by type: general, views, schemas, logs |
| `optimize` | `OptimizeCommand` | Clears + warms caches (views, schemas, general) |
| `route:cache` | `RouteCacheCommand` | Compiles and caches all controller routes into a PHP file for fast production loading |
| `route:clear` | `RouteClearCommand` | Clears the compiled route cache file (`storage/cache/routes.php`) |
| `npm:install` | `NpmInstallCommand` | Installs a JS vendor package (via Bun) into `storage/assets/vendor/` |
| `npm:remove` | `NpmRemoveCommand` | Removes an installed JS vendor package |
| `npm:list` | `NpmListCommand` | Lists currently installed JS vendor packages |
| `schii:export` | `SchiiExportCommand` | Exports Schii schemas to standalone Phii files |

> **Module Commands:** Modules contribute their own CLI commands dynamically via `registerCommands(Application $console)` when enabled in `config/app.php` (e.g. `queue:*` from `QueueModule`, see [Queue](Queue.md)).

#### Server
| Command | Class | What it does |
|---|---|---|
| `serve:php` | `ServeCommand` | Local dev HTTP server, docroot `public/`, default host `0.0.0.0:8000` |
| `serve` | `SwooleServeCommand` | Persistent Swoole HTTP server with coroutine support |
| `schii:serve` | `SchiiServeCommand` | Schii-aware serving variant |
| `deploy` | `DeployCommand` | Zero-downtime atomic release deployment |

#### Custom
User-space commands auto-discovered from `App/Commands/` appear here.
Example: `hello` (with sub-commands `hello:world`, `hello:test`).

---

### Cache Commands

#### `cache:clear`

Clears application cache by type.

```bash
./phue cache:clear                    # all types (default)
./phue cache:clear --type=general     # PSR-16 file cache (storage/cache/*.cache)
./phue cache:clear --type=views       # compiled views (storage/cache/views/*.php)
./phue cache:clear --type=schemas     # generated DB schemas (storage/generated/schemas/*)
./phue cache:clear --type=logs        # application logs (storage/logs/*)
```

#### `optimize`

Clears **and warms** caches for production-ready performance.

```bash
./phue optimize                       # all types (default: general, views, schemas)
./phue optimize --type=general        # clear + warm general cache
./phue optimize --type=views          # pre-parse & compile all .phue components
./phue optimize --type=schemas        # pre-generate DB schemas for all ActiveRecord models
./phue optimize --dry-run             # show what would be optimized without executing
```

> **Warming behavior:**
> - **general**: clears FileCache; warming is on-demand (components load on first request)
> - **views**: discovers all `.phue` files in `App/Frontend` + `src/Core/UI/Phui`, parses & compiles them via `PhueTemplateParser` to populate `storage/cache/views/`
> - **schemas**: discovers all `App\Models\* extends BaseActiveRecord` classes and calls `SchemaRegistry::get()` to pre-generate schema files in `storage/generated/schemas/`

---

### Common Examples

```bash
# Dev server (override host/port)
./phue serve:php --port=8080 --host=127.0.0.1

# Swoole server (worker options)
./phue serve --port=8080 --host=127.0.0.1 --worker-num=4

# Generate code
./phue make:controller UserController
./phue make:model Product
./phue make:migration create_products_table

# Database
./phue migrate
./phue migrate:rollback
./phue schii:migrate "App\Models\Product"

# Modules
./phue module:list
./phue module:new MyModule --type=layer

# Route caching
./phue route:cache
./phue route:clear

# JS vendor packages
./phue npm:install vue
./phue npm:list

# Cache
./phue cache:clear --type=views
./phue optimize --type=schemas --dry-run

# Sub-commands
./phue hello:world
./phue hello:test
```

---

## 2. `phue-create` — the project installer

A self-contained bash installer at `tools/installer(deprecated)/phue-create.sh` that scaffolds a complete new Phue application. The directory is marked **deprecated** in-tree (the tool is complete and self-testing, but the framework no longer evolves it) — the path below is where it actually lives. Full details live in [Getting Started](Getting-Started.md); quick reference:

```bash
# Interactive: pick a starting point, then modules
bash tools/installer(deprecated)/phue-create.sh my-app

# Predefined template
bash tools/installer(deprecated)/phue-create.sh my-app --template=web

# Custom module set
bash tools/installer(deprecated)/phue-create.sh my-app --modules=auth,server

# See what this framework ships / what the presets contain
bash tools/installer(deprecated)/phue-create.sh --list-modules
bash tools/installer(deprecated)/phue-create.sh --list-templates

# Print the install plan, write nothing
bash tools/installer(deprecated)/phue-create.sh my-app --template=api --dry-run
```

Flags: `--template=<name>`, `--modules=a,b,c`, `--list-templates`, `--list-modules`, `--dry-run`, `--repo=<path>`, `--force`, `-h/--help`.

Behavior highlights:
- **The module list is scanned, not written down.** The installer reads `src/Modules/` — each module's `$id`, `$type`, `$requires`, and its docblock — so a module added to the framework is installable immediately and one that is renamed or deleted cannot leave a dead entry behind. Use `--list-modules` to see what the scan found.
- Templates live in `tools/installer(deprecated)/templates/*.json`, one file each: `name`, `title`, `description`, `order`, `tags`, and a `modules` list. `fullstack` uses `"modules": ["*"]` so it cannot fall behind the framework.
- Dependency auto-resolution: selecting `rest` adds its `auth` requirement, and says which module required it.
- Module ids are accepted in any spelling (`ratelimit`, `rate_limit`, `rate-limit` → `rateLimit`); an unknown id is an error that lists the real ones.
- Copies the framework core, the selected modules **and their test suites**, the App skeleton, and generates `composer.json`, `config/app.php`, `phpunit.xml`, `.env`.
- `composer.json` gets a `suggest` section for the extensions the selection actually uses (`ext-redis`, `ext-swoole`).
- Anything not copied is reported with a reason instead of being skipped silently.
- Creates root `phue` symlink pointing to `bin/phue` automatically.
- From a URL: if the Phue source isn't found locally it clones it (`PHUE_REPO_URL` env var overrides the default GitHub URL).

### Installer tests

The installer has its own test framework — `tools/installer(deprecated)/tests/run-tests.sh` — that installs projects into isolated `mktemp` directories, asserts structure/config, and deletes each test project afterwards:

```bash
bash tools/installer(deprecated)/tests/run-tests.sh
```

It also drives the interactive menus through a pseudo-terminal (`tests/pty_run.py`) and asserts on the screen a user would see, because a full-screen menu rewrites itself on every keypress and the byte stream alone cannot tell a working menu from one that stacks a copy per keystroke. `tests/framework_probe.php` reads the framework's module classes directly, so discovery is checked against `src/Modules/` rather than against a list maintained next to the installer.

Pass a substring to run part of the suite: `bash tools/installer(deprecated)/tests/run-tests.sh template`.

---

## 3. `phue-docker` — Docker wrapper

```bash
./phue-docker up      # Start containers detached (app + redis)
./phue-docker start   # Start containers + Phue HTTP + Bun SSR servers
./phue-docker down    # Stop and remove containers
./phue-docker build   # Rebuild images without cache
./phue-docker restart # Stop + start back up
./phue-docker shell   # Interactive shell inside the app container
./phue-docker logs    # Stream live logs (tail 100)
./phue-docker status  # Container states
./phue-docker clean   # Stop containers AND purge volumes (destructive, asks for confirmation)
```

### `start` / `restart` flags (cache & optimize)

```bash
# Clear all caches before starting
./phue-docker start -c
./phue-docker start --clear-cache

# Optimize (clear + warm) caches before starting
./phue-docker start -o
./phue-docker start --optimize

# Both: clear then optimize
./phue-docker start -co
./phue-docker restart -co
./phue-docker start --clear-cache --optimize
```

| Flag | Description |
|------|-------------|
| `-c`, `--clear-cache` | Runs `cache:clear --type=all` inside the container before starting servers |
| `-o`, `--optimize` | Runs `optimize --type=all` (clear + warm) inside the container before starting servers |
| `-co`, `-oc` | Both: clear then optimize (equivalent to `-c -o`) |

> Flags are only parsed for `start` and `restart` commands. They run **after** containers are up, inside the running app container.

The compose file is `docker/dev/docker-compose.yml` (service `app`, container `phue_app`, port 8000). `start` pre-cleans the stale SSR socket, launches `./phue serve` and the Bun SSR sidecar (`ssr/server.ts`), prefixing log lines with `[PHUE SERVE]` / `[BUN SSR]`.

---

## 4. Testing

The test suite is organized **one testsuite per module** (`phpunit.xml`):
`core`, `schii`, `rest`, `phii`, `auth`, `server`, `events`, `devtools`,
`queue`, `websocket`, `ratelimit`, `httpsigning`, plus a `kit` suite for
cross-module tests (`App`, `Benchmark`, `Cache`, `ErrorPages`, `Production`)
and a `suite` suite for framework-wide guards.

```bash
# Full suite (canonical harness — inside the container)
docker exec phue_app php bin/phue_test

# Only what the working tree can affect (the default working mode)
docker exec phue_app php bin/phue_test --changed

# Re-run last run's failures
docker exec phue_app php bin/phue_test --only-failed

# A single module's suite, via PHPUnit directly
docker exec phue_app vendor/bin/phpunit --testsuite auth --no-coverage
```

### Other CLI entry points

- `bin/phue_test` — the **test runner** (`php bin/phue_test [mode]`): selects
  which tests matter (`--changed`, `--impact <paths...>`, `--only-failed`,
  `--list`) and hands the selection to PHPUnit in one process. It never runs the
  suite concurrently — the MariaDB-backed tests share a server and the DevTool's
  PDO wrapper resolves by method name, so sharding loses races.
- `bin/phii` — alias entry point for the Phii generator commands
  (`generate:module`, `generate:model`, `generate:controller`,
  `generate:migration`, `generate:crud`).

### Console Internals

`Phue\Core\Console\Application` (`src/Core/Console/Application.php`) is the command registry + dispatcher:
- `add(BaseCommandInterface)` registers a command keyed by `getName()`.
- `run($argv)` dispatches; unknown commands print an ANSI-red error + help.
- Errors go to `\STDERR`; every command follows the same `fwrite($outputStream, ...)` I/O pattern and `--flag=value` option parsing.

### BaseCommand (Recommended Base Class)

`Phue\Core\Console\BaseCommand` is the recommended abstract base class for all commands. It provides:

- **Convention-based sub-command routing** — public methods become sub-commands automatically
- **Group assignment** — `$group` property + `getGroup()` method for help grouping
- **Auto help generation** — `showHelp()` renders sub-commands with descriptions from `@description` docblock tags
- **Sub-command listing** — `getSubCommands()` returns `name => description` map

```php
final class MyCommand extends BaseCommand
{
    protected string $group = 'Build';  // appears under "Build:" in help

    public function getName(): string { return 'mycmd'; }
    public function getDescription(): string { return 'My command'; }

    /** @description First sub-command */
    public function first(array $args, $out, $err): int { ... }

    /** @description Second sub-command */
    public function second(array $args, $out, $err): int { ... }
}
```

Commands implementing `CommandInterface` directly (without extending `BaseCommand`) must manually implement `execute()`, `getGroup()`, and sub-command handling.

---