# CLI

The command-line tooling: the `phue` console, the `phue-create` project installer, the `phue-docker` wrapper, and how testing is set up.

---

## 1. `phue` — the framework console

`./phue` is a shebang script (`#!/usr/bin/env php`) that boots the autoloader, creates a `Phue\Core\Console\Application`, registers the built-in commands, then lets every configured module contribute its own commands, and runs.

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
General:
  hello                  The main Hello-er command for CLI
    hello:world          Prints "Hello World!"
    hello:test           Prints "Hello Test!"

Build:
  new                    Scaffold a minimal project...
  make:controller        Creates a new Controller class...
  ...

Database:
  migrate                Runs all pending database migrations.
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
| `module:new` | `ModuleNewCommand` | Scaffolds a new framework module under `src/Modules/` |

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
| `npm:install` | `NpmInstallCommand` | Installs a JS vendor package (via Bun) into `storage/assets/vendor/` |
| `npm:remove` | `NpmRemoveCommand` | Removes an installed JS vendor package |
| `npm:list` | `NpmListCommand` | Lists currently installed JS vendor packages |
| `schii:export` | `SchiiExportCommand` | Exports Schii schemas to standalone Phii files |

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
./phue module:new Queue --type=layer --requires=auth

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

A self-contained bash installer at `tools/installer/phue-create.sh` that scaffolds a complete new Phue application. Full details live in [Getting Started](Getting-Started.md); quick reference:

```bash
# Interactive (arrow keys + space checklist, like create-vite)
bash tools/installer/phue-create.sh my-app

# Predefined template
bash tools/installer/phue-create.sh my-app --template=web

# Custom module set
bash tools/installer/phue-create.sh my-app --modules=auth,server

# List templates
bash tools/installer/phue-create.sh --list-templates
```

Flags: `--template=<name>`, `--modules=a,b,c`, `--list-templates`, `--repo=<path>`, `--force`, `-h/--help`.

Behavior highlights:
- Templates live in `tools/installer/templates/*.json` — one JSON file per template with a `modules` list (blank, web, api, realtime, fullstack, edge).
- Dependency auto-resolution: selecting `rest` adds its `auth` requirement.
- Copies the framework core, the selected modules **and their test suites**, the App skeleton, and generates `composer.json`, `config/app.php`, `phpunit.xml`, `.env`.
- Creates root `phue` symlink pointing to `bin/phue` automatically.
- From a URL: if the Phue source isn't found locally it clones it (`PHUE_REPO_URL` env var overrides the default GitHub URL).

### Installer tests

The installer has its own test framework — `tools/installer/tests/run-tests.sh` — that installs projects into isolated `mktemp` directories, asserts structure/config, and deletes each test project afterwards:

```bash
bash tools/installer/tests/run-tests.sh
```

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

The test suite is organized **one testsuite per module** (`phpunit.xml`): `core`, `schii`, `rest`, `phii`, `auth`, `server`, `events`, `compiler`, `devtools`, and a `kit` suite (`App`, `Benchmark`, `Cache`, `ErrorPages`, `Production`).

```bash
# Full suite (canonical harness — inside the container)
docker exec phue_app vendor/bin/phpunit --do-not-cache-result

# On the host
vendor/bin/phpunit

# A single module's suite
vendor/bin/phpunit --testsuite auth
```

### Other CLI entry points

- `bin/phue_test` — generator commands only (`generate:module`, `generate:model`, `generate:controller`, `generate:migration`, `generate:crud`), the Phii code-generator CLI.
- `bin/phii` — alias entry point for the generator commands.

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