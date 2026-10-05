# Phue Framework — Documentation

**Phue** is a modular PHP framework for building modern web applications: attribute-based routing, a scoped DI container, Active Record + Data Mapper ORM, server-driven UI, and a no-build Vue 3 component kit — all designed to stay fast on both classic FPM and persistent runtimes (Swoole, RoadRunner, WASM).

This documentation is organized into focused guides. Pick the one that matches what you are doing:

---

## Getting started

| Guide | What it covers |
|---|---|
| [Getting Started](Getting-Started.md) | Installing Phue with `phue-create`, project structure, environment, first run |
| [Core](Core.md) | `Application`, the Module contract, DI Container, Config, Request/Response, Router, EventDispatcher |
| [Modules](Modules.md) | The module contract in depth: hooks, dependencies, `module:new`, available modules |

## Building your application

| Guide | What it covers |
|---|---|
| [Database](Database.md) | Connections, drivers (PDO + HTTP edge), QueryBuilder, Active Record, Data Mapper, migrations |
| [Auth & RBAC](Auth-RBAC.md) | AuthManager, authenticators, BaseUser, RBAC roles & permissions |
| [Schii](Schii.md) | Server-driven UI, zero-controller REST, JSON schema protocol, auto-migration |
| [REST](REST.md) | Attribute-driven `#[Rest]` CRUD endpoints with RBAC enforcement |
| [Events](Events.md) | Event bus drivers, SSE streaming, `#[Broadcastable]` models |
| [WebSocket](WebSocket.md) | Named endpoints with per-endpoint auth and topics, `Broadcast`, retention, backpressure |

## Running & shipping

| Guide | What it covers |
|---|---|
| [Server Adapters](Server-Adapters.md) | FPM, Swoole, RoadRunner, WASM/edge — plus SSR sidecar, AOT compiler, DevTools, **live reload** |
| [CLI](CLI.md) | `bin/phue` commands, the `phue-create` project installer, `phue-docker` wrapper, testing |
| [PhUI Components](PhUI_Components.md) | Reference API for every built-in `.phue` component |

---

## The 60-second version

```bash
# Create a new project (interactive module picker)
bash <(curl -fsSL https://raw.githubusercontent.com/MakariosVar/phue/main/tools/installer/phue-create.sh) my-app

# Or with a predefined template
bash /path/to/phue/tools/installer/phue-create.sh my-app --template=web

# Install dependencies and run
cd my-app
composer install
./phue serve:php
```

Open `http://localhost:8000` — you have a working Phue app with routing, `.phue` pages, and whatever modules you picked.

## Where everything lives

```
my-app/
├── App/                    # YOUR application code
│   ├── Config/             # NavigationConfig, app-wide settings
│   ├── Controllers/        # #[Route] controllers
│   ├── Errors/             # Custom error pages (404, 500)
│   ├── Frontend/           # .phue pages + Components/
│   └── Models/             # #[UI]/#[Field] entities (Schii)
├── bin/                    # phue, phue_test, phii executables
├── config/                 # app.php, database.php, per-module config
├── database/migrations/    # Migration files
├── docs/                   # This documentation
├── public/                 # Web entry point (index.php)
├── src/                    # Phue framework source
│   ├── Core/               # Framework core (Application, Router, DB, UI, ...)
│   └── Modules/            # Framework modules (Auth, Schii, Rest, ...)
├── ssr/                    # Bun SSR sidecar (server.ts)
├── storage/                # Cache, logs, generated schemas, assets
└── tests/                  # Per-module test suites
```

## Conventions used in these guides

- Code examples reference real classes — every namespace (`Phue\Core\...`, `Phue\Modules\...`) matches the source.
- Behavior marked as a **contract** (e.g. Schii's validation rules, request isolation) is protected by the test suite; changing it is a breaking change.
- Defaults shown are the real defaults from `config/*.php` and the class definitions.
