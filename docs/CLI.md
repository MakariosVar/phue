# CLI

The command-line tooling: the `bin/phue` console, the `phue-create` project
installer, the `phue-docker` wrapper, and how testing is set up.

---

## 1. `bin/phue` — the framework console

`./bin/phue` is a shebang script (`#!/usr/bin/env php`) that boots the
autoloader, creates a `Phue\Core\Console\Application`, registers the built-in
commands, then lets every configured module contribute its own commands, and
runs.

```bash
./bin/phue list      # or --help / -h
```

### How commands are registered

- Every command implements `Phue\Core\Console\CommandInterface`:
  `getName(): string`, `getDescription(): string`, and
  `execute(array $args, $outputStream, $errorStream): int`.
- `Console\Application::run()` takes `$argv`, treats `$argv[1]` as the command
  name and `$argv[2..]` as arguments, dispatches — or renders an ANSI-colored
  help menu for `list` / `--help`.
- Built-in commands come from `src/Core/Console/Commands/`; module commands are
  added by each module's `registerCommands()` (see [Modules](Modules.md)).

### Built-in commands (verified names)

| Command | Class | What it does |
|---|---|---|
| `new` | `NewProjectCommand` | Scaffolds a minimal isolation-harness project around specific modules (`--modules=<Name>`) to verify subportability |
| `serve:php` | `ServeCommand` | Local dev HTTP server, docroot `public/`, default host `0.0.0.0:8000` |
| `make:controller` | `MakeControllerCommand` | Generates `App/Controllers/XxxController.php` with a `#[Route]` attribute |
| `make:model` | `MakeModelCommand` | Generates `App/Models/Xxx.php` extending `BaseActiveRecord` |
| `make:migration` | `MakeMigrationCommand` | Generates a timestamped migration under `database/migrations/` |
| `migrate` | `MigrateCommand` | Applies pending migrations (connection resolved from `config/database.php`) |
| `migrate:rollback` | `MigrateRollbackCommand` | Rolls back the last applied batch |
| `deploy` | `DeployCommand` | Zero-downtime atomic release deployment |
| `module:list` | `ModuleListCommand` | Lists installed modules + dependency graph |
| `module:new` | `ModuleNewCommand` | Scaffolds a new framework module under `src/Modules/` |
| `npm:install` | `NpmInstallCommand` | Installs a JS vendor package (via Bun) into `storage/assets/vendor/` |
| `npm:remove` | `NpmRemoveCommand` | Removes an installed JS vendor package |
| `npm:list` | `NpmListCommand` | Lists currently installed JS vendor packages |

### Module commands (added via `registerCommands()`)

| Command | Module | What it does |
|---|---|---|
| `serve` | Server | Persistent Swoole HTTP server with coroutine support |
| `schii:migrate` | Schii | Auto-migrates database tables from Schii entity schemas |
| `schii:export` | Schii | Exports schemas to `storage/generated/` |
| `schii:serve` | Schii | Schii-aware serving variant |

> **Naming note:** `serve:php` is the plain dev server and `serve` is the
> Swoole one — the Swoole command is registered by the `Server` *module* with
> the shorter name. Check `./bin/phue list` for the authoritative list.

### Common examples

```bash
# Dev server (override host/port)
./bin/phue serve:php --port=8080 --host=127.0.0.1

# Swoole server (worker options)
./bin/phue serve --port=8080 --host=127.0.0.1 --worker-num=4

# Generate code
./bin/phue make:controller UserController
./bin/phue make:model Product
./bin/phue make:migration create_products_table

# Database
./bin/phue migrate
./bin/phue migrate:rollback
./bin/phue schii:migrate "App\Models\Product"

# Modules
./bin/phue module:list
./bin/phue module:new Queue --type=layer --requires=auth

# JS vendor packages
./bin/phue npm:install vue
./bin/phue npm:list
```

---

## 2. `phue-create` — the project installer

A self-contained bash installer at `tools/installer/phue-create.sh` that
scaffolds a complete new Phue application. Full details live in
[Getting Started](Getting-Started.md); quick reference:

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

Flags: `--template=<name>`, `--modules=a,b,c`, `--list-templates`,
`--repo=<path>`, `--force`, `-h/--help`.

Behavior highlights:

- Templates live in `tools/installer/templates/*.json` — one JSON file per
  template with a `modules` list (blank, web, api, realtime, fullstack, edge).
- Dependency auto-resolution: selecting `rest` adds its `auth` requirement.
- Copies the framework core, the selected modules **and their test suites**, the
  App skeleton, and generates `composer.json`, `config/app.php`, `phpunit.xml`,
  `.env`.
- From a URL: if the Phue source isn't found locally it clones it
  (`PHUE_REPO_URL` env var overrides the default GitHub URL).

### Installer tests

The installer has its own test framework —
`tools/installer/tests/run-tests.sh` — that installs projects into isolated
`mktemp` directories, asserts structure/config, and deletes each test project
afterwards:

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

The compose file is `docker/dev/docker-compose.yml` (service `app`, container
`phue_app`, port 8000). `start` pre-cleans the stale SSR socket, launches
`./bin/phue serve` and the Bun SSR sidecar (`ssr/server.ts`), prefixing log
lines with `[PHUE SERVE]` / `[BUN SSR]`.

---

## 4. Testing

The test suite is organized **one testsuite per module** (`phpunit.xml`):
`core`, `schii`, `rest`, `phii`, `auth`, `server`, `events`, `compiler`,
`devtools`, and a `kit` suite (`App`, `Benchmark`, `Cache`, `ErrorPages`,
`Production`).

```bash
# Full suite (canonical harness — inside the container)
docker exec phue_app vendor/bin/phpunit --do-not-cache-result

# On the host
vendor/bin/phpunit

# A single module's suite
vendor/bin/phpunit --testsuite auth
```

### Other CLI entry points

- `bin/phue_test` — generator commands only (`generate:module`, `generate:model`,
  `generate:controller`, `generate:migration`, `generate:crud`), the Phii
  code-generator CLI.
- `bin/phii` — alias entry point for the generator commands.

### Console internals

`Phue\Core\Console\Application` (`src/Core/Console/Application.php`) is the
command registry + dispatcher:

- `add(CommandInterface)` registers a command keyed by `getName()`.
- `run($argv)` dispatches; unknown commands print an ANSI-red error + help.
- Errors go to `\STDERR`; every command follows the same
  `fwrite($outputStream, ...)` I/O pattern and `--flag=value` option parsing.
