# Phue Framework

<p align="center">
    <img src="public/Phue.svg" alt="Phue logo" width="140">
</p>
<p align="center">
    <strong>Almost a framework.</strong>
</p>
<p align="center">
    <a href="LICENSE"><img src="https://img.shields.io/github/license/MakariosVar/phue?style=flat-square" alt="License"></a>
    <a href="composer.json"><img src="https://img.shields.io/badge/PHP-%3E%3D%208.5-8892BF.svg?style=flat-square&logo=php" alt="PHP >= 8.5"></a>
    <a href="https://github.com/MakariosVar/phue"><img src="https://img.shields.io/github/stars/MakariosVar/phue?style=flat-square" alt="GitHub stars"></a>
    <a href="https://github.com/MakariosVar/phue"><img src="https://img.shields.io/github/last-commit/MakariosVar/phue?style=flat-square" alt="GitHub last commit"></a>
    <a href="CONTRIBUTING.md"><img src="https://img.shields.io/badge/PRs-welcome-brightgreen.svg?style=flat-square" alt="PRs welcome"></a>
</p>

Phue is a modular PHP framework **>= 8.5** for building modern web applications.
It is not another microframework and not another kitchen-sink monolith either —
it sits intentionally in between, with an opinionated core and pluggable
modules that you opt into per-project.

The headline feature is **`.phue`** — a single-file component format that keeps
server state (`<php>`), markup (`<template>`), and client logic (`<script>`) in
one file, rendered server-side and hydrated with plain ES modules. No build
step for your views, Vue 3 powered, no-bundle by design.

> **How to read this file.** It's the friendly — but complete — version of the
> official [Getting Started guide](docs/Getting-Started.md). Every section here
> links to the deep-dive docs, so start here and follow the breadcrumbs.

---

## Table of contents

- [What Phue gives you](#what-phue-gives-you)
- [The module system](#the-module-system)
- [Requirements](#requirements)
- [Quick start — the 60-second version](#quick-start--the-60-second-version)
- [Creating a project with `phue-create`](#creating-a-project-with-phue-create)
  - [Interactive mode](#interactive-mode)
  - [Template mode](#template-mode)
  - [Custom module set](#custom-module-set-non-interactive)
  - [From a URL](#from-a-url)
  - [Guardrails](#guardrails)
  - [What gets installed](#what-gets-installed)
- [Project structure](#project-structure)
- [Environment (`.env`)](#environment-env)
- [First run](#first-run)
- [Running with Docker](#running-with-docker)
- [Documentation map](#documentation-map)
- [License & trademark](#license--trademark)
- [Contributing](#contributing)

---

## What Phue gives you

- **Attribute-based routing** — declare routes on controller methods with
  `#[Route('/path', methods: ['GET'])]`. No separate route tables to keep in sync.
- **Scoped DI container** — service container with scoped state, designed for
  both short-lived FPM requests and long-lived persistent runtimes.
- **Active Record + Data Mapper ORM** — query through the QueryBuilder, access
  rows as objects, and keep explicit control when you need it. `#[UI]`/`#[Field]`
  attributes drive the schema for free.
- **Server-driven UI with zero controllers** — the Schii module exposes a JSON
  schema protocol and gives you an admin console, generated REST endpoints, and
  the Phii code generator, straight from your models.
- **Built-in auth & RBAC** — `AuthManager`, Bearer authentication, roles and
  permissions on top of your `BaseUser`.
- **A no-build Vue 3 component kit** — the PhUI components: tables, forms,
  navbars, toasts — plain ESM, no Webpack/Vite required.
- **SSR sidecar** — server-side rendering via a Bun sidecar when you want it.
- **Edge-ready** — runs on classic **FPM**, persistent **Swoole**, **RoadRunner**,
  and even **WASM** at the edge. Same application code, different adapter.

## The module system

Phue is a thin, stable **Core** with feature **Modules** that hook into it.
A module declares its identity, its dependencies, and what it provides:
routes, RBAC permissions, nav items, config, CLI commands, assets. You pick the
modules a project needs — everything else stays out.

| Module | What it adds | Docs |
|---|---|---|
| `auth` | `AuthManager`, Bearer auth, RBAC roles & permissions | [Auth & RBAC](docs/Auth-RBAC.md) |
| `schii` | Admin console, zero-controller REST, Phii generator, `.phue` models | [Schii](docs/Schii.md) |
| `rest` | Attribute-driven `#[Rest]` CRUD endpoints with RBAC | [REST](docs/REST.md) |
| `server` | Swoole persistent server adapter (and friends) | [Server Adapters](docs/Server-Adapters.md) |
| `events` | Redis Streams event bus + SSE streaming, `#[Broadcastable]` | [Events](docs/Events.md) |
| `compiler` | AOT compiler & wiring generation | [Server Adapters](docs/Server-Adapters.md) |
| `devtools` | Debug toolbar + profiler (development) | [Server Adapters](docs/Server-Adapters.md) |

> Want to know how it all fits together? Read [Core](docs/Core.md) and
> [Modules](docs/Modules.md). Creating your own module is a first-class use case,
> and `phue module:new` scaffolds one for you.

---

## Requirements

| Requirement | Version | Why |
|---|---|---|
| PHP | `>= 8.5` | The framework and the generated `composer.json` both declare it |
| Composer | 2.x | Dependency management |
| (optional) Docker | — | The dev stack (`phue-docker`) and the canonical test harness |
| (optional) Bun | — | JS vendor packages + the Bun SSR sidecar |

---

## Quick start — the 60-second version

```bash
# Create a new project (interactive module picker)
bash <(curl -fsSL https://raw.githubusercontent.com/MakariosVar/phue/main/tools/installer/phue-create.sh) my-app

# Or from a local clone of this repo
bash tools/installer/phue-create.sh my-app --template=web

## DOCKER 
./phue-docker build
./phue-docker shell   # open docker container shell
composer install      # inside docker container

./phue-docker start   # runs docker up and starts both (swoole+bun) servers 
./phue-docker restart # drop and recreate and start both (swoole+bun) servers

## OR MANUAL RUN
cd my-app
composer install
./phue serve:php # Start standard FPM server or simply
# ./phue serve   # if swoole is enabled

# if SSR_ENABLED in true in .env you need to run
bun ./ssr/server.ts
```

Open `http://localhost:8000` — you have a working Phue app with routing,
`.phue` pages, and whatever modules you picked.

---

## Creating a project with `phue-create`

Phue ships a self-contained installer script at `tools/installer/phue-create.sh`.
It scaffolds a complete new application from the framework source: core files,
the modules you pick, their tests, and generated configuration.

### Interactive mode

Run without any flags to get an interactive checklist — navigate with ↑/↓,
toggle a module with <kbd>space</kbd>, confirm with <kbd>enter</kbd>, cancel with
<kbd>q</kbd>:

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
  ◻ compiler —  AOT compiler: wiring generation
  ◻ devtools —  Dev tools: debug toolbar, profiler
```

> Dependencies are resolved automatically: selecting `rest` (which requires
> `auth`) adds `auth` for you with a note in the output.

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
| `fullstack` | all 7 modules | Everything, like the framework demo repo |
| `edge` | `compiler`, `server` | WASM / AOT experiments |

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

The script is designed to be run straight from the repository — if the Phue
source isn't found locally it clones it for you:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/MakariosVar/phue/main/tools/installer/phue-create.sh) my-app --template=web
```

> With `bash <(curl ...)` the interactive checklist keeps working because your
> terminal's stdin stays attached. A plain `curl | bash` pipe detaches stdin, so
> use `--template` or `--modules` in that case.

### Guardrails

- **Unknown template or module** → error, exit code `1`.
- **Existing non-empty directory** → error, exit code `1` (unless `--force`).
- **Empty existing directory** → reused.
- If the terminal can't be made interactive and no selection flag was given, the
  installer stops with guidance instead of guessing.

### What gets installed

For every project, the installer copies:

- **Framework core** — `bin/`, `public/`, `config/`, `src/Core/`, the Phii
  generator tooling (`src/Modules/Phii/`), `docs/`, `docker/`, `ssr/` sources
  (without `node_modules`), `phue-docker`, `.env.example`, `.gitignore`
- **App skeleton** — `App/Errors/`, `App/Frontend/AppLayout.phue`,
  `App/Frontend/Pages/`, `App/Controllers/Controller.php` (abstract base),
  `App/Controllers/WebController.php`, `App/Config/NavigationConfig.php`
- **Selected modules** — each `src/Modules/<Name>/` directory, its test suite
  (`tests/<Name>/`), plus companion app files when relevant (e.g.
  `App/Models/User.php` with `auth`, `App/Controllers/ApiController.php` with `rest`)
- **Generated** — `composer.json` (named after your project directory),
  `config/app.php` (only your modules), `phpunit.xml` (one testsuite per selected
  module + an `app` suite), `.env` from `.env.example`, and the `storage/` skeleton

---

## Project structure

```
my-app/
├── App/
│   ├── Config/NavigationConfig.php   # Navigation items, brand
│   ├── Controllers/
│   │   ├── Controller.php            # Abstract base (injects ComponentRenderer)
│   │   └── WebController.php         # #[Route] home controller
│   ├── Errors/                       # Custom 404/500 error pages
│   ├── Commands/                     # Custom CLI commands
│   ├── Frontend/
│   │   ├── AppLayout.phue            # Master layout shell
│   │   ├── Components/               # Your reusable .phue components
│   │   └── Pages/                    # Your .phue pages
│   └── Models/                       # #[UI]/#[Field] entities (when schii is on)
├── bin/
│   ├── phue                          # Main CLI
│   ├── phue_test                     # Generator CLI (Phii)
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

After adding new classes outside these roots (or changing the map), run
`composer dump-autoload`.

---

## Environment (`.env`)

The installer creates `.env` from `.env.example`. `Application` loads it at boot
(`Env::load()`) **before** reading config files, so every config value driven by
the `env()` helper picks it up:

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
| `APP_DEV_TOOL` | DevTools debug toolbar (needs `APP_DEBUG=true` too) |
| `SCHII_ENABLED` | Schii console + Phii nav items |
| `DB_*` | The active database connection |

Read any variable from PHP with the global helper:

```php
use function Phue\Core\env;

$name = env('APP_NAME', 'Phue App');
```

---

## First run

```bash
cd my-app
composer install
./phue serve:       # http://localhost:8000, docroot public/
```

Then:

| URL | What you see |
|---|---|
| `/` | The Home page (`.phue` rendered through AppLayout) |
| `/docs` | The Docs page |
| `/ui` | The PhUI component showcase |
| `/schii` | The Schii admin console (when `schii` is installed) |
| `/phii` | The Phii code generator panel (when `schii` is installed) |

If the SQLite database doesn't exist yet, Schii's auto-migration creates tables
for your `#[UI]` models on the fly — see [Schii](docs/Schii.md).

---

## Running with Docker

```bash
./phue-docker build     # Build the dev image
./phue-docker up        # Start containers (app + redis)
./phue-docker start     # Start containers + Phue HTTP + Bun SSR servers
./phue-docker shell     # Interactive shell inside the app container
./phue-docker down      # Stop containers
./phue-docker restart   # Safely stop containers (down) and start them back up
./phue-docker status    # View current container states
./phue-docker logs      # Stream live logs (tail 100)
./phue-docker clean     # Stop containers AND purge volumes (destructive)

```

See [CLI](docs/CLI.md) for the full command list.

---

## Documentation map

| Guide | What it covers |
|---|---|
| [Getting Started](docs/Getting-Started.md) | Installing Phue with `phue-create`, project structure, environment, first run |
| [Core](docs/Core.md) | `Application`, the Module contract, DI Container, Config, Request/Response, Router, EventDispatcher |
| [Modules](docs/Modules.md) | The module contract in depth: hooks, dependencies, `module:new`, available modules |
| [Database](docs/Database.md) | Connections, drivers (PDO + HTTP edge), QueryBuilder, Active Record, Data Mapper, migrations |
| [Auth & RBAC](docs/Auth-RBAC.md) | `AuthManager`, authenticators, `BaseUser`, RBAC roles & permissions |
| [Schii](docs/Schii.md) | Server-driven UI, zero-controller REST, JSON schema protocol, auto-migration |
| [REST](docs/REST.md) | Attribute-driven `#[Rest]` CRUD endpoints with RBAC enforcement |
| [Events](docs/Events.md) | Event bus drivers, SSE streaming, `#[Broadcastable]` models |
| [Server Adapters](docs/Server-Adapters.md) | FPM, Swoole, RoadRunner, WASM/edge — plus SSR sidecar, AOT compiler, DevTools |
| [CLI](docs/CLI.md) | `bin/phue` commands, the `phue-create` project installer, `phue-docker` wrapper, testing |
| [PhUI Components](docs/PhUI_Components.md) | Reference API for every built-in `.phue` component |

Conventions used across the docs: code examples reference real classes (every
namespace matches the source), and behavior marked as a **contract** is
protected by the test suite — changing it is a breaking change.

---

## License & trademark

Phue is licensed under the [Apache License 2.0](LICENSE). A
[NOTICE](NOTICE) file preserves the copyright attribution, and the
[Trademark Policy](TRADEMARK.md) governs how the "Phue" name and logo may be
used. In short: you may use, modify, and redistribute the source code —
including commercially — but you may not ship a fork under the Phue name or
imply official endorsement.

## Contributing

Contributions of all sizes are welcome — bug reports, docs, tests, and code.
See [CONTRIBUTING.md](CONTRIBUTING.md). By participating you agree to the
Developer Certificate of Origin (DCO) for every commit you submit.