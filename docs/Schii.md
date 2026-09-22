# Schii — Server-Driven UI & Zero-Controller REST

Schii (`Phue\Modules\Schii`, `src/Modules/Schii/`) is Phue's runtime
schema-driven UI engine. It parses PHP attributes on Active Record models to
generate REST API CRUD endpoints and a JSON Schema protocol for the Vue admin
console — with **zero controller boilerplate**.

Related guides: [Database](Database.md), [Auth & RBAC](Auth-RBAC.md),
[REST](REST.md) (the attribute-driven alternative), [PhUI Components](PhUI_Components.md).

---

## 1. How it works

```
#[UI]/#[Field] attributes on App\Models\* entities
        │  (reflected by SchemaRegistry)
        ▼
   ModelSchema / FieldSchema (JSON protocol)
        │
        ├──► GET /api/schii/schema        → entity list (server-authoritative)
        ├──► GET /api/schii/schema/{e}    → JSON schema for the admin UI
        ├──► GET/POST/PUT/DELETE /api/schii/{e}/{id}  → zero-controller CRUD
        └──► GET /schii                   → the Vue 3 admin console (no build)
```

You annotate models; the module discovers them from the configured
`entityNamespace` and serves everything automatically.

---

## 2. Annotating a model

Attributes live in `Phue\Modules\Schii\Attribute`:

- **`#[UI]`** — class attribute: entity `label`, `icon`, `layout`, `description`.
- **`#[Field]`** — property attribute: widget `type`, `label`, `searchable`,
  `filterable`, `sortable`, column visibility, `options`, validation `rules`.

```php
namespace App\Models;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Modules\Schii\Attribute\UI;
use Phue\Modules\Schii\Attribute\Field;

#[UI(label: 'Products', icon: 'box', layout: 'grid')]
class Product extends BaseActiveRecord
{
    protected string $table = 'products';

    #[Field(type: 'text', label: 'Product Name', searchable: true, sortable: true, required: true)]
    public string $name;

    #[Field(type: 'number', label: 'Price ($)', filterable: true, sortable: true)]
    public float $price;

    #[Field(type: 'select', filterable: true, options: ['active', 'inactive'])]
    public string $status;
}
```

### Registering entities manually (rare)

Normally you don't register anything manually — the module discovers entities
from the configured `entityNamespace` (default `App\Models`). For standalone
use outside the module:

```php
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;

$registry = new SchemaRegistry();
$endpoint = new ModelEndpoint($registry);

$endpoint->registerEntity('products', App\Models\Product::class);
```

---

## 3. The schema protocol engine

Components (in `src/Modules/Schii/Schema/`):

| Component | Responsibility |
|---|---|
| `SchemaRegistry` | Reflection parser with dual-layer caching (L1 in-memory + L2 PSR-16), `humanize()` fallback, automatic RBAC permission generation (`admin.entity.create`) |
| `FieldSchema`, `ModelSchema` | Immutable value objects exposing `toArray()` for standard JSON schema rendering |
| `SchemaInspector` | Inspects the live database schema (per-connection) |
| `SchemaSyncEngine` | Diffs model schemas against the live database |

### Entity discovery

- `SchemaRegistry::discoverModelClasses(string $namespace): array` resolves the
  configured namespace to a directory **starting from the Application base
  path** (the module injects `Application::getBasePath()`), falling back to
  `getcwd()` — a server launched from any working directory still lists the
  same entities.
- `GET {apiPrefix}/schema` → `{"entities": ["product", ...]}` — registered
  **before** `/{entity}` routes so the literal `/schema` wins over entity
  routing.
- The module is fully self-contained: it resolves entities from the configured
  namespace and hosts its own console frontend — no `App\*` references in
  `src/Modules/Schii` (enforced by contract tests).

---

## 4. The zero-controller REST endpoint

`ModelEndpoint` (in `src/Modules/Schii/Endpoint/`) serves full CRUD for every
registered entity:

| Route | Handler | What it does |
|---|---|---|
| `GET /{apiPrefix}/schema` | `listSchemas` | Server-authoritative entity list |
| `GET /{apiPrefix}/schema/{entity}` | `schema` | Complete JSON schema for the entity |
| `GET /{apiPrefix}/{entity}` | `index` | List with search, filter, sort, pagination |
| `GET /{apiPrefix}/{entity}/{id}` | `show` | Single record |
| `POST /{apiPrefix}/{entity}` | `store` | Create record |
| `PUT /{apiPrefix}/{entity}/{id}` | `update` | Update record |
| `DELETE /{apiPrefix}/{entity}/{id}` | `delete` | Delete record |
| `POST /{apiPrefix}/{entity}/bulk` | `bulk` | Bulk operations |
| `GET /{apiPrefix}/{entity}/export` | `export` | Export records |
| `POST /{apiPrefix}/{entity}/import` | `import` | Import records |

`index()` features:

- Global search across `searchable` fields.
- Dynamic filtering across `filterable` fields.
- Sorting across `sortable` fields (`ASC`/`DESC`).
- Pagination metadata (`total`, `page`, `limit`, `last_page`).

### The validation contract (protected)

These rules are enforced **before** any persistence, and are protected by the
test suite — changing any of them is a breaking change:

| Rule | Behavior |
|---|---|
| Unknown fields → `422` | Any property not declared via `#[Field]` is rejected with `"Unknown field 'X' is not allowed"`. Since query strings and body are merged, this also covers stray URL parameters on `POST`/`PUT` — keep request URLs clean. |
| Required fields → `422` | Fields declared `required: true` must be present and non-empty on `store()`. |
| Type rules → `422` | `number`/`numeric` fields reject non-numeric input, `email` fields require a valid e-mail. Additional rules are declared directly on the attribute: `#[Field(type: 'select', rules: ['email'])]`. |
| Mass-assignment protection | The primary key and non-schema fields are stripped from the payload before `fill()` — clients can never forge their own `id`. |
| Escaped `LIKE` search | The wildcard characters `%` and `_` in `q` are escaped (the underlying `QueryBuilder` appends an `ESCAPE '\'` clause), so a bare `q=%` matches nothing instead of returning the whole table. |
| Unregistered entities → `404` | Requesting `/api/schii/unknown` returns `404 Not Found` instead of throwing. |
| Pagination clamps | `limit` is clamped to `[1, 100]` and `page` to `>= 1`; garbage values fall back to defaults. |

---

## 5. Configuration (`config/schii.php`)

Schii reads its settings from a dedicated `config/schii.php` (keyed per file —
`Config::get('schii')`, see [Core §5](Core.md)):

```php
return [
    'enabled' => env('SCHII_ENABLED', true),
    'apiPrefix' => '/api/schii',
    'prefix' => '/schii',
    'entityNamespace' => 'App\\Models',
    'autoMigrate' => true,
    'cachePrefix' => 'schii_schema_',
    'requireAuth' => false,
    'middleware' => [],
    'exportDir' => 'storage/generated',
    'phiiNamespace' => 'App\\Models',
    'phiiPrefix' => '/phii',
    'audit' => [
        'enabled' => true,
        'limit' => 200,
    ],
    'import' => [
        'maxUpload' => '10M',
        'allowedMime' => ['text/csv', 'application/json'],
    ],
    'phii' => [
        'allowInProduction' => false,
    ],
    'relations' => [
        'autoInfer' => false,
    ],
];
```

Two distinct settings drive URLs:

| Setting | Default | Drives |
|---|---|---|
| `prefix` | `/schii` | Where the **console page** is mounted |
| `apiPrefix` | `/api/schii` | Where the **REST API** lives (`/{apiPrefix}/{entity}`, `/{apiPrefix}/schema`) |
| `phiiPrefix` | `/phii` | Where the Phii generator panel is mounted |
| `entityNamespace` | `App\Models` | Namespace scanned for `#[UI]`/`#[Field]` entities |
| `requireAuth` | `false` | When `true` (and auth is loaded), CRUD goes through `AuthorizedModelEndpoint` |
| `middleware` | `[]` | Route middleware applied to Schii endpoints |
| `autoMigrate` | `true` | Auto-migration of missing tables/columns |
| `audit` | enabled, limit 200 | Audit log endpoints |
| `import` | 10M, csv/json | Import upload limits |
| `phii.allowInProduction` | `false` | Phii panel in production |

The console at `/schii` queries `GET /api/schii/schema` server-authoritatively —
nothing is hardcoded in the frontend.

---

## 6. Audit & protected endpoints

With `requireAuth => true` (and the auth module loaded), Schii routes CRUD and
audit operations through the authorized decorators:

- `AuthorizedModelEndpoint` — enforces `{entity}.{action}` permissions via
  `RbacManager::checkAccess($user->getId(), $permission, $user->getRoles())`.
- `AuthorizedAuditEndpoint` — same pattern for audit endpoints
  (`/{apiPrefix}/audit`, `/{apiPrefix}/audit/{entity}`).

See [Auth & RBAC](Auth-RBAC.md).

---

## 7. Auto-migration

Schii can create missing tables and columns directly from model schema
declarations.

### Programmatic

```php
use Phue\Modules\Schii\Migration\AutoMigrator;
use Phue\Modules\Schii\Schema\SchemaRegistry;

$migrator = new AutoMigrator(new SchemaRegistry(), $dbConnection);

// Creates the table or ALTERs columns based on the Product model attributes
$actions = $migrator->migrate(App\Models\Product::class);

foreach ($actions as $action) {
    echo "Migrated: {$action}\n";
}
```

Engine internals:

- **`AutoMigrator`** inspects active database tables (`tableExists`,
  `getExistingColumns`) and auto-migrates missing tables (`CREATE TABLE`) or
  missing columns (`ALTER TABLE ADD COLUMN`) based on the model's `ModelSchema`.
  All steps run inside a database transaction (`beginTransaction()` / `commit()`)
  with automatic `rollBack()` on error. Driver detection is multi-tiered:
  `getName()` contract, `instanceof` class checks, or string fallback.
- **`SchemaToSqlConverter`** generates dialect-aware SQL DDL tailored for
  SQLite, MySQL, or PostgreSQL (`AUTOINCREMENT` vs `AUTO_INCREMENT` vs `SERIAL`,
  `TINYINT(1)` vs `BOOLEAN`).
- **`SqlIdentifier`** (in `Phue\Core\Database`) sanitizes identifiers with
  regex whitelist validation and dialect-aware quoting.

### CLI

```bash
./phue schii:migrate "App\Models\Product"
```

---

## 8. The admin console & Phii panel

### Console (`/schii`)

`SchiiModule` routes `GET /schii` → `ConsoleController::index()` which returns
`ComponentRenderer::render(...)`. When the auth module is present, an anonymous
visitor is redirected to `/login` (302) instead of rendering the console. The
console page (`src/Modules/Schii/Frontend/Schii.phue`) is a **Vue 3 no-build
app**:

- Boots from `/assets/vendor/vue3/vue.esm-browser.js` and reads
  `window.__INITIAL_STATE__`.
- On mount it queries `GET {apiPrefix}/schema` — **server-authoritative entity
  discovery**: the page carries no hardcoded entity list.
- The sidebar is populated from whatever entities the configured namespace
  yields; clicking an entity fetches its JSON schema + rows and renders them.

Module frontend components (read-only, framework territory): `Schii.phue`,
`SchiiRecordTable`, `SchiiRecordToolbar`, `SchiiAuditPanel`,
`SchiiImportModal`, `SchiiSchemaDiffModal`, `Phii.phue`, `PhiiPreviewPanel`.

### Phii panel (`/phii`)

The Phii code generator panel: generate, preview, and apply file mutations from
model schema metadata (`GET /phii`, `POST /phii/generate`, `POST /phii/preview`,
`POST /phii/apply`). Gated by `phii.allowInProduction => false` by default.
The panel also requires authentication: anonymous page requests are redirected
to `/login` (302), while anonymous API calls (`generate`/`preview`/`apply`)
receive a 401 JSON error.

The Phii generator tooling also powers the CLI generators
(`generate:model`, `generate:controller`, ... via `bin/phue_test` /
`bin/phii`) — see [CLI](CLI.md).
