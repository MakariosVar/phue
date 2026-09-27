# Auth & RBAC

The authentication and Role-Based Access Control subsystem —
`Phue\Modules\Auth` (`src/Modules/Auth/`). Designed for stateless APIs and
traditional web applications alike.

Related guides: [Core](Core.md), [Schii](Schii.md) (protected CRUD endpoints),
[REST](REST.md) (RBAC enforcement on `#[Rest]` routes).

---

## 1. Components

| Component | File | Responsibility |
|---|---|---|
| `IdentityInterface` | `Core/Security/IdentityInterface.php` | Core security contract (`getId()`, `findIdentity()`, `getAuthKey()`) |
| `CurrentUserInterface` | `Core/Security/CurrentUserInterface.php` | Contract for the current-user facade (`user()`, `isGuest()`, `can()`, ...) |
| `IdentityInterface` | `Modules/Auth/IdentityInterface.php` | Auth-domain alias extending the Core contract (`extends`) |
| `BaseUser` | `Modules/Auth/BaseUser.php` | ActiveRecord-backed identity base model |
| `AuthManager` | `Modules/Auth/AuthManager.php` | Chain-of-responsibility request authenticator & state manager |
| `BearerTokenAuthenticator` | `Modules/Auth/Authenticator/BearerTokenAuthenticator.php` | Bearer token authentication |
| `SessionAuthenticator` | `Modules/Auth/Authenticator/SessionAuthenticator.php` | Session-based authentication (reads the ID via a `LoginStoreInterface`) |
| `RbacManager` | `Modules/Auth/RbacManager.php` | Roles, permissions, inheritance with cycle detection |
| `LoginStoreInterface` | `Modules/Auth/Persistence/LoginStoreInterface.php` | Session storage contract (`getId()`, `store()`, `clear()`) |
| `SessionLoginStore` | `Modules/Auth/Persistence/SessionLoginStore.php` | `$_SESSION`-backed implementation (key `__user_id`) |
| `UserAccessor` | `Modules/Auth/UserAccessor.php` | Scoped current-user facade implementing `CurrentUserInterface` |
| `AccessControlMiddleware` | `Modules/Auth/Middleware/AccessControlMiddleware.php` | Route middleware enforcing permission checks |

> Historical note: this subsystem used to live in `Phue\Auth` (`src/Auth/`).
> After the modulezied restructuring it is `Phue\Modules\Auth`
> (`src/Modules/Auth/`).

---

## 2. The BaseUser identity model

App models extend `Phue\Modules\Auth\BaseUser` — an ActiveRecord-backed
implementation of `IdentityInterface`. This gives you `getId()`,
`findIdentity()`, `findIdentityByAuthKey()`, `getRoles()` (reads a JSON `roles`
column), and `validatePassword()` with zero boilerplate.

**Secure User model configuration:**

```php
namespace App\Models;

use Phue\Modules\Auth\BaseUser;
use Phue\Modules\Schii\Attribute\Field;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

class User extends BaseUser
{
    // Hide sensitive credentials from serialization (API responses, logs, etc.)
    protected array $hidden = ['password_hash', 'auth_key'];

    #[Field(type: 'text')]
    public string $username;

    #[Field(type: 'text', writable: false, readonly: true, column: false)]
    public string $password_hash;

    #[Field(type: 'text', writable: false, readonly: true, column: false)]
    public ?string $auth_key = null;

    // The roles column carries authorization grants — it MUST NOT be writable
    // through the generic CRUD endpoint. Role assignment belongs on a dedicated
    // privileged path.
    #[Field(type: 'json', writable: false)]
    public array $roles = [];

    // Hide credentials from serialization (toArray/jsonSerialize)
    protected array $hidden = ['password_hash', 'auth_key'];
}
```

**Key points:**

- `password_hash` and `auth_key` are `writable: false`, `readonly: true`, `column: false` — they are never written through the generic CRUD endpoint and are excluded from serialization via `$hidden`.
- The `roles` column carries authorization grants and **must be `writable: false`** — role assignment belongs on a dedicated privileged path, not the generic CRUD endpoint.
- `$hidden = ['password_hash', 'auth_key']` prevents credentials from leaking into API responses (`toArray()`, `jsonSerialize()`, Schii responses).

### Token resolution semantics

- `findIdentity()` resolves by **primary key** — the correct semantic for
  session lookup.
- `findIdentityByAuthKey()` resolves by the **`auth_key` column** — the correct
  semantic for Bearer tokens, since a token must never be treated as a user ID.
- Identity implementations should populate `auth_key` during user
  registration/login and use it for stateless authentication.

---

## 3. AuthManager & authenticators

`AuthManager` evaluates authentication drivers in a chain of responsibility and
tracks state (`getUser()`, `login()`, `logout()`, `check()`).

```php
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\Authenticator\BearerTokenAuthenticator;
use Phue\Modules\Auth\Authenticator\SessionAuthenticator;
use Phue\Modules\Auth\Persistence\SessionLoginStore;

$authManager = new AuthManager([
    new SessionAuthenticator(App\Models\User::class, new SessionLoginStore()),
    new BearerTokenAuthenticator(
        fn (string $token) => App\Models\User::findIdentityByAuthKey($token)
    ),
]);

// Authenticate incoming request
$user = $authManager->authenticate($request);
```

### How the module wires it

`AuthModule::bindings()` registers `LoginStoreInterface` as a **singleton**
(with `SessionLoginStore` as its implementation), `AuthManager` as
**request-scoped** (it must not outlive a request), `RbacManager` as a
**singleton** (seeded from `config/rbac_seeding.php`), and `UserAccessor` as
**request-scoped** behind the Core `CurrentUserInterface`:

```php
$this->container->singleton(
    LoginStoreInterface::class,
    fn(): LoginStoreInterface => new SessionLoginStore()
);

$this->container->scoped(AuthManager::class, function (Container $c): AuthManager {
    $authConfig = $c->has(Config::class)
        ? $c->get(Config::class)->get('auth', [])
        : [];

    $resolver = $authConfig['authResolver']
        ?? [\Phue\Modules\Auth\BaseUser::class, 'findIdentityByAuthKey'];

    $bearerAuth = new BearerTokenAuthenticator(
        tokenResolver: static function (string $token) use ($resolver): mixed {
            if (is_callable($resolver)) {
                return $resolver($token);
            }
            return null;
        }
    );

    $sessionAuth = new SessionAuthenticator(
        identityClass: \App\Models\User::class,
        loginStore: $c->get(LoginStoreInterface::class)
    );

    return new AuthManager([$sessionAuth, $bearerAuth]);
});

$this->container->singleton(
    RbacManager::class,
    fn(): RbacManager => new RbacManager('config/rbac_seeding.php')
);

$this->container->scoped(
    CurrentUserInterface::class,
    fn(Container $c): CurrentUserInterface => new UserAccessor(
        authManager: $c->get(AuthManager::class),
        rbacManager: $c->get(RbacManager::class),
        loginStore: $c->get(LoginStoreInterface::class),
        request: $c->get(Request::class)
    )
);
```

The chain order matters: the **session authenticator runs first**, so a logged-in
browser user wins over a stray Bearer token; APIs without a session fall through
to the Bearer authenticator.

`UserAccessor` is registered against the Core `CurrentUserInterface`. Its
constructor reads the request-bound `AuthManager`, `RbacManager` and
`LoginStoreInterface` **lazily on first auth attempt** (memoized per request via
the `resolved` flag), which keeps it Swoole-safe — no state crosses requests.
Access it from anywhere with `$app->user()`:

```php
use Phue\Core\Security\CurrentUserInterface;

/** @var CurrentUserInterface|null $user */
$user = $app->user();

if ($user === null || $user->isGuest()) {
    // not authenticated
} else {
    $id   = $user->getId();          // int|string|null
    $can  = $user->can('product.write'); // RBAC check (false for guests)
}
```

### Zero-config auth

When `authResolver` is omitted from `config/auth.php`, the framework default
applies: `[\Phue\Modules\Auth\BaseUser::class, 'findIdentityByAuthKey']` — any
model extending `BaseUser` is resolved by its `auth_key` column with no extra
configuration.

Override it for app-specific identity classes:

```php
// config/auth.php
return [
    'authResolver' => ['App\\Models\\User', 'findIdentityByAuthKey']
];
```

`config/schii.php` can also set `authResolver` to override the default used by
`BearerTokenAuthenticator`.

---

## 4. RBAC (`RbacManager`)

Roles, direct permissions, and recursive role inheritance — with visited-set
cycle detection. The engine is pure and DB-independent: permissions are
evaluated from the data you pass in.

```php
use Phue\Modules\Auth\RbacManager;

$rbac = new RbacManager();

// Register roles and their direct permissions
$rbac->addRole('author', ['posts.create']);
$rbac->addRole('editor', ['posts.update', 'posts.delete']);
$rbac->addRole('admin', ['admin.dashboard']);

// Define role inheritance: admin -> editor -> author
$rbac->addChild('admin', 'editor');
$rbac->addChild('editor', 'author');

// Assign role to user ID (static assignment / bootstrap)
$rbac->assign(10, 'admin');

// checkAccess accepts an optional explicitRoles array:
$rbac->checkAccess($userId, $permission, $explicitRoles = []);

// - with explicitRoles: uses them directly as the authority (DB-backed identity pattern)
// - without explicitRoles: falls back to static assignments from config/rbac_seeding.php

if ($rbac->checkAccess(10, 'posts.create')) {
    // Access granted via static assignment
}
if ($rbac->checkAccess($userId, 'admin.product.read', $user->getRoles())) {
    // Authorized via the authenticated identity's DB-loaded roles
}
```

### Authority model: explicit roles are the authority

`RbacManager::checkAccess()` resolves the effective role set as:

```php
$userRoles = !empty($explicitRoles)
    ? $explicitRoles                    // identity-supplied roles = authority
    : ($this->assignments[$userContext] ?? []);  // fallback to static assignments
```

**Design rationale:** the `roles` column on the identity model is the **authority**.
It is per-user and dynamic, which is exactly why it exists; `config/rbac_seeding.php`
is only the bootstrap for the first administrator.

**Security implication:** the `roles` column **can only grant** — it never removes
access unless the column is empty. The security boundary is the **write path**:
the `roles` column **must not be writable** through the generic CRUD endpoint.
Role assignment belongs on a dedicated privileged path that checks the actor's
own authority over the target.

Role inheritance is recursive — `admin` → `editor` → `author` means an admin
satisfies `posts.create` too. Cycles are detected with a visited set.

---

## 5. Enforcing permissions on routes (`AccessControlMiddleware`)

Attach the middleware to a route (class or method level) to enforce permission
checks:

- **Unauthenticated request** → `401 Unauthorized`
- **Insufficient privileges** → `403 Forbidden`

```php
use Phue\Modules\Auth\Middleware\AccessControlMiddleware;

#[Route('/admin')]
#[Middleware(AccessControlMiddleware::class)]
class AdminController { ... }
```

---

## 6. RBAC on REST endpoints (`AuthorizedModelEndpoint`)

`AuthorizedModelEndpoint` (in `Phue\Modules\Schii\Endpoint/`) is a decorator for
Schii CRUD endpoints that automatically enforces dynamic permissions
(`{entity}.{action}`) parsed from schema metadata.

It calls `$rbacManager->checkAccess($user->getId(), $permission, $user->getRoles())`,
bridging the authenticated identity's DB-loaded roles into the RBAC gate.

**Wiring**: `SchiiModule` selects `AuthorizedModelEndpoint` as the CRUD handler
when `requireAuth => true` in `config/schii.php` **and** the auth module is
loaded, and falls back to the bare `ModelEndpoint` otherwise — so Schii
degrades gracefully without auth. See [Schii](Schii.md).
