# Getting Started

Everything you need to create a new Phue application and run it locally.

---

## 1. Requirements

| Requirement | Version | Why |
|---|---|---|
| PHP | `>= 8.5` | The framework and the generated `composer.json` both declare it |
| Composer | 2.x | Dependency management |
| (optional) Docker | — | The dev stack (`phue-docker`) and the canonical test harness |
| (optional) Bun | — | JS vendor packages + the Bun SSR sidecar |

---

## 2. Creating a project with `phue-create`

Phue ships a self-contained installer script at `tools/installer/phue-create.sh`. It scaffolds a complete new application from the framework source: core files, the modules you pick, their tests, and generated configuration.

### Interactive mode

Run without any flags to get an interactive checklist — navigate with ↑/↓, toggle a module with <kbd>space</kbd>, confirm with <kbd>enter</kbd>, cancel with <kbd>q</kbd>:

```bash
bash /path/to/phue/tools/installer/phue-create.sh my-app
```

If you omit the project name, the installer prompts for one:

```
◆  Project name: my-app
◆  Select the modules to install:
❯ ◻ auth     —  Authentication: AuthManager, Bearer auth, RBAC
  ◻ schii    —  Server-driven UI: admin console, zero-controller REST, Phii
  ◻ rest     —  REST API: attribute-driven #[Rest] CRUD endpoints
  ◻ server   —  Swoole persistent server adapter
  ◻ events   —  Events: Redis Streams bus + SSE
  ◻ devtools —  Dev tools: collapsible debug toolbar + request profiler (spans, SSR, components, SQL)
```

> Dependencies are resolved automatically: selecting `rest` (which requires `auth`) adds `auth` for you with a note in the output.

### Template mode

Prefer a curated module set? Use `--template`:

```bash
bash tools/installer/phue-create.sh my-app --template=web
```

Available templates (also listed with `--list-templates`):

| Template | Modules | Use case |
|---|---|---|
| `blank` | *(none)* | Core framework only — pure HTML-first start |
| `web` | `auth`, `schii` | Classic full-stack web app: admin console + auth |
| `api` | `auth`, `rest`, `schii` | REST-first API with RBAC + schema admin |
| `realtime` | `auth`, `events`, `rest` | SSE / Redis streaming apps |
| `fullstack` | all 6 modules | Everything, like the framework demo repo |

### Custom module set (non-interactive)

```bash
bash tools/installer/phue-create.sh my-app --modules=auth,server
```

### All flags

| Flag | Purpose |
|---|---|
| `--template=<name>` | Use a predefined template |
| `--modules=a,b,c` | Custom module selection (non-interactive) |
| `--list-templates` | List available templates and exit |
| `--repo=<path>` | Path to the Phue framework source (default: the repo the script lives in) |
| `--force` | Allow creating inside a non-empty directory |
| `-h`, `--help` | Help |

### From a URL

The script is designed to be run straight from the repository — if the Phue source isn't found locally it clones it for you:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/MakariosVar/phue/main/tools/installer/phue-create.sh) my-app --template=web
```

> With `bash <(curl ...)` the interactive checklist keeps working because your terminal's stdin stays attached. A plain `curl | bash` pipe detaches stdin, so use `--template` or `--modules` in that case.

### Guardrails

- **Unknown template or module** → error, exit code `1`.
- **Existing non-empty directory** → error, exit code `1` (unless `--force`).
- **Empty existing directory** → reused.
- If the terminal can't be made interactive and no selection flag was given, the installer stops with guidance instead of guessing.

### What gets installed

For every project, the installer copies:

- **Framework core** — `bin/`, `public/`, `config/`, `src/Core/`, the Phii generator tooling (`src/Modules/Phii/`), `docs/`, `docker/`, `ssr/` sources (without `node_modules`), `phue-docker`, `.env.example`, `.gitignore`
- **App skeleton** — `App/Errors/`, `App/Frontend/AppLayout.phue`, `App/Frontend/Pages/`, `App/Controllers/Controller.php` (abstract base), `App/Controllers/WebController.php`, `App/Config/NavigationConfig.php`
- **Selected modules** — each `src/Modules/<Name>/` directory, its test suite (`tests/<Name>/`), plus companion app files when relevant (e.g. `App/Models/User.php` with `auth`, `App/Controllers/ApiController.php` with `rest`)
- **Generated** — `composer.json` (named after your project directory), `config/app.php` (only your modules), `phpunit.xml` (one testsuite per selected module + an `app` suite), `.env` from `.env.example`, and the `storage/` skeleton

---

## 3. Project structure

```
my-app/
├── App/
│   ├── Config/NavigationConfig.php   # Navigation items, brand, theme toggle
│   ├── Controllers/
│   │   ├── Controller.php            # Abstract base (injects ComponentRenderer)
│   │   └── WebController.php         # #[Route] home controller
│   ├── Errors/                       # Custom 404/500 error pages
│   ├── Frontend/
│   │   ├── AppLayout.phue            # Master layout shell
│   │   ├── Components/               # Your reusable .phue components
│   │   └── Pages/                    # Your .phue pages
│   └── Models/                       # #[UI]/#[Field] entities (when schii is on)
├── bin/
│   ├── phue                          # Main CLI
│   ├── phue_test                     # Wrapper for phpunit
│   └── phii                          # Generator CLI alias
├── config/
│   ├── app.php                       # App settings + modules list
│   ├── database.php                  # Connections
│   └── ...                           # Per-module config (auth.php, schii.php, ...)
├── database/migrations/              # Explicit migrations
├── public/index.php                  # Web entry point
├── src/
│   ├── Core/                         # Framework core
│   └── Modules/                      # Framework modules (selected)
├── ssr/                              # Bun SSR sidecar (server.ts)
├── storage/
│   ├── assets/                       # Vendor JS/CSS (bootstrap, vue3)
│   ├── cache/                        # Views + framework cache
│   ├── generated/                    # Exported schemas
│   └── logs/                         # app.log
└── tests/                            # One testsuite per module + app/
```

### PSR-4 autoloading

The generated `composer.json` registers both namespaces:

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

After adding new classes outside these roots (or changing the map), run `composer dump-autoload`.

---

## 4. Environment (`.env`)

The installer creates `.env` from `.env.example`. `Application` loads it at boot (`Env::load()`) **before** reading config files, so every config value driven by the `env()` helper picks it up. The `./phue` CLI loads it the same way before building the console application, so commands like `migrate` and `schii:migrate` honour the configured connection:

```ini
APP_NAME="Phue App"
APP_ENV=development
APP_DEBUG=true
APP_DEV_TOOL=true

SCHII_ENABLED=true

DB_CONNECTION=sqlite
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=database/phue.sqlite
DB_USERNAME=root
DB_PASSWORD=
```

| Variable | Drives |
|---|---|
| `APP_NAME` | Brand label (`NavigationConfig::getAppBrand()`) |
| `APP_ENV`, `APP_DEBUG` | Environment + error verbosity |
| `APP_DEV_TOOL` | DevTools debug toolbar + profiler (needs `APP_DEBUG=true` too) — see [Server Adapters §5](Server-Adapters.md) |
| `SCHII_ENABLED` | Schii console + Phii nav items |
| `DB_*` | The active database connection |
| `QUEUE_DRIVER` | Active queue driver (`sync`, `database`) |
| `APP_WEBSOCKET` | WebSocket real-time server toggle (persistent Swoole) |
| `APP_KEY`      | **Required** — signing key for CSRF tokens. Leave empty in `.env.example`; run `./phue generate:key` to fill it. A missing key throws on any state-changing request. |

Read any variable from PHP with the global helper:

```php
use function Phue\Core\env;

$name = env('APP_NAME', 'Phue App');
```

---

## 5. First run

```bash
cd my-app
composer install
./phue serve:php        # http://localhost:8000, docroot public/
```

Then:

| URL | What you see |
|---|---|
| `/` | The Home page (`.phue` rendered through AppLayout) |
| `/docs` | The Docs page |
| `/ui` | The PhUI component showcase |
| `/schii` | The Schii admin console (when `schii` is installed) |
| `/phii` | The Phii code generator panel (when `schii` is installed) |

If the SQLite database doesn't exist yet, Schii's auto-migration creates tables for your `#[UI]` models on the fly — see [Schii](Schii.md).

### Cache commands (development & production)

Clear caches during development:

```bash
./phue cache:clear                    # all types (default)
./phue cache:clear --type=general     # PSR-16 file cache
./phue cache:clear --type=views       # compiled views
./phue cache:clear --type=schemas     # generated DB schemas
./phue cache:clear --type=logs        # application logs
```

Route caching (zero-reflection production performance):

```bash
./phue route:cache                    # compile and cache controller routes to storage/cache/routes.php
./phue route:clear                    # remove compiled route cache file
```

Optimize (clear + warm) for production-ready performance:

```bash
./phue optimize                       # all types (default: general, views, schemas)
./phue optimize --type=views          # pre-parse & compile all .phue components
./phue optimize --type=schemas        # pre-generate DB schemas for ActiveRecord models
./phue optimize --dry-run             # show what would be optimized without executing
```

> **Warming behavior:** `views` discovers & parses all `.phue` components; `schemas` generates schema files for all `App\Models\* extends BaseActiveRecord` classes.

---

## 6. Running with Docker

```bash
./phue-docker build           # Build the dev image
./phue-docker up              # Start containers (app + redis)
./phue-docker start           # Start containers + Phue HTTP + Bun SSR servers
./phue-docker start -c        # Clear all caches before starting
./phue-docker start -o        # Optimize (clear + warm) caches before starting
./phue-docker start -co       # Both: clear then optimize
./phue-docker shell           # Interactive shell inside the app container
./phue-docker down            # Stop containers
./phue-docker clean           # Stop containers AND purge volumes (destructive)
```

See [CLI](CLI.md) for the full command list and `phue-docker start -h` for cache/optimize flags.

> The dev image installs `pdo_mysql` alongside the bundled SQLite driver. The
> `app` service runs with **host networking**, so it can reach a MySQL server
> listening on the host loopback at `127.0.0.1:3306` — set `DB_CONNECTION=mysql`
> and `DB_HOST=127.0.0.1` in `.env` to use it.

---

## 7. Next steps

- [Core](Core.md) — understand the `Application` boot sequence and the Module contract
- [Modules](Modules.md) — create your own module with `module:new`
- [Database](Database.md) — models, migrations, the QueryBuilder
- [Schii](Schii.md) — server-driven UI with zero controllers
