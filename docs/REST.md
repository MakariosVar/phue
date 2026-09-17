# REST — Attribute-Driven Endpoints

The `RestModule` (`Phue\Modules\Rest`, `src/Modules/Rest/`) bootstraps
public/selective REST endpoints using an attribute-driven approach (`#[Rest]`),
registering standard CRUD routes, schema endpoints, and custom domain actions
(collection/member) with slug-collision safeguards.

Related guides: [Auth & RBAC](Auth-RBAC.md), [Schii](Schii.md) (the
zero-controller alternative), [Core](Core.md) (routing & handler binding).

---

## 1. Annotating a model

The `#[Rest]` attribute (`Phue\Modules\Rest\Attribute\Rest`) declares the
endpoint on a model class and on individual methods:

```php
namespace App\Models;

use Phue\Modules\Rest\Attribute\Rest;

#[Rest(slug: 'products', operations: ['index', 'show', 'store', 'update', 'delete'])]
class Product extends BaseActiveRecord
{
    // ...
}
```

- **Class-level**: declares the entity slug and which CRUD `operations` to
  expose.
- **Method-level**: declares custom domain actions on the model's public
  methods.

---

## 2. Configuration (`config/rest.php`)

`RestModule` binds a `RestConfig` value object from the `rest` config section:

```php
return [
    'enabled' => true,
    'prefix' => '/api',
    'entity_namespace' => 'App\\Models',   // 'entityNamespace' also accepted
    'middleware' => [],
];
```

| Setting | Default | Drives |
|---|---|---|
| `enabled` | `true` | When `false`, the module registers nothing |
| `prefix` | `/api` | URL prefix for all REST endpoints |
| `entity_namespace` | `App\Models` | Namespace scanned for `#[Rest]` models |
| `middleware` | `[]` | Middleware classes applied to every REST route |

---

## 3. What gets registered

For every annotated model discovered in the configured namespace:

| Route | Exposed when |
|---|---|
| `GET  /{prefix}/{slug}/schema` | always (per-entity schema) |
| `GET  /{prefix}/{slug}` | `index` operation |
| `POST /{prefix}/{slug}` | `store` operation |
| `GET  /{prefix}/{slug}/{id}` | `show` operation |
| `PUT  /{prefix}/{slug}/{id}` | `update` operation |
| `DELETE /{prefix}/{slug}/{id}` | `delete` operation |
| `GET  /{prefix}/{slug}/{id}/{action}` | method-level `#[Rest]` with an `id` parameter (member action) |
| `GET  /{prefix}/{slug}/{action}` | method-level `#[Rest]` without `id` (collection action) |

The HTTP method of a custom action comes from the attribute's `method` value
(uppercased).

### Custom domain actions

```php
#[Rest(slug: 'orders')]
class Order extends BaseActiveRecord
{
    #[Rest(method: 'POST', slug: 'pay')]
    public function pay(): array { ... }          // POST /api/orders/{id}/pay

    #[Rest(method: 'GET', slug: 'recent')]
    public function recent(): array { ... }       // GET /api/orders/recent
}
```

A method with an `id` parameter becomes a **member** action
(`/{slug}/{id}/{actionSlug}`); without one it becomes a **collection** action
(`/{slug}/{actionSlug}`).

### Slug-collision safeguards

Custom action paths are skipped (silently) when they collide with the reserved
paths (`/{slug}`, `/{slug}/{id}`, `/{slug}/schema`) or with a previously
registered action of the same entity.

---

## 4. RBAC enforcement

`RestModule` wraps every endpoint in a `RestModelEndpoint` — an isolated wrapper
around the Schii `ModelEndpoint` (a separate instance per entity, so there are
no collisions with SchiiModule bindings) plus:

- `AuthManager` + `RbacManager` from the container (the module `requires`
  `auth`, so they are guaranteed to be loaded at boot).
- `hidden` / `public` flags from the `#[Rest]` attribute — `public` endpoints
  skip authorization.
- `operations` from the attribute.

Custom actions pass an `actionPublic` flag (from the method attribute, falling
back to the class attribute's `public`) to the endpoint's `action()` handler.

See [Auth & RBAC](Auth-RBAC.md) for how `AuthorizedModelEndpoint` evaluates
permissions, and [Schii](Schii.md) for the validation contract enforced before
persistence (unknown fields → `422`, required → `422`, type rules, PK strip,
escaped `LIKE`, pagination clamps).

---

## 5. Module boot sequence

`RestModule::init()` in order:

1. **Config binding** — resolves `RestConfig` from `config/rest.php` (via the
   `Config` singleton, falling back to a `config` binding).
2. Returns early when `$restConfig->enabled` is `false`.
3. **Model discovery** — `$schemaRegistry->discoverModelClasses($entityNamespace)`
   finds annotated models.
4. For each model with a `#[Rest]` attribute: builds the isolated
   `RestModelEndpoint` wrapper and registers the schema + CRUD routes on the
   router with the configured middleware.
5. **Custom actions** — scans public methods for `#[Rest]` attributes and
   registers member/collection actions with slug-collision guards.

---

## 6. Schii REST vs `#[Rest]` — which one?

| | Schii (`Phue\Modules\Schii`) | REST (`Phue\Modules\Rest`) |
|---|---|---|
| Declaration | `#[UI]`/`#[Field]` attributes | `#[Rest]` attribute |
| Route prefix | `apiPrefix` (`/api/schii`) | `prefix` (`/api`) |
| Authorization | `requireAuth` → `AuthorizedModelEndpoint` | Per-entity `public` flag + RBAC wrapper |
| Custom actions | — | Method-level `#[Rest]` (member/collection) |
| Schema protocol | Full JSON schema + audit + import/export | Per-entity schema endpoint |

Both share the same `ModelEndpoint` internals and the same validation
contract — they differ in how entities are declared, authorized, and which
prefix they are mounted under. Installing both is safe: `RestModule` creates
isolated endpoint instances per entity.
