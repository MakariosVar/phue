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
| `IdentityInterface` | `Modules/Auth/IdentityInterface.php` | Contract for authenticated identities |
| `BaseUser` | `Modules/Auth/BaseUser.php` | ActiveRecord-backed identity base model |
| `AuthManager` | `Modules/Auth/AuthManager.php` | Chain-of-responsibility request authenticator & state manager |
| `BearerTokenAuthenticator` | `Modules/Auth/Authenticator/BearerTokenAuthenticator.php` | Bearer token authentication |
| `SessionAuthenticator` | `Modules/Auth/Authenticator/SessionAuthenticator.php` | Session-based authentication |
| `RbacManager` | `Modules/Auth/RbacManager.php` | Roles, permissions, inheritance with cycle detection |
| `AccessControlMiddleware` | `Modules/Auth/Middleware/AccessControlMiddleware.php` | Route middleware enforcing permission checks |

> Historical note: this subsystem used to live in `Phue\Auth` (`src/Auth/`).
> After the modulezied restructuring it is `Phue\Modules\Auth`
> (`src/Modules/Auth/`).

---

## 2. The BaseUser identity model

App models extend `Phue\Modules\Auth\BaseUser` — an ActiveRecord-backed
implementation of `IdentityInterface`. This gives you `getId()`,
`findIdentity()`, `findIdentityByAuthKey()`, `getRoles()` (reads a JSON `roles`
column), and `validatePassword()` with zero boilerplate:

```php
namespace App\Models;

use Phue\Modules\Auth\BaseUser;
use Phue\Modules\Schii\Attribute\Field;

class User extends BaseUser
{
    #[Field(type: 'text')]
    public string $username;

    #[Field(type: 'text')]
    public string $password_hash;

    #[Field(type: 'text')]
    public ?string $auth_key = null;

    #[Field(type: 'text')]
    public mixed $roles = '[]';
}
```

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

$authManager = new AuthManager([
    new SessionAuthenticator(App\Models\User::class),
    new BearerTokenAuthenticator(
        fn (string $token) => App\Models\User::findIdentityByAuthKey($token)
    ),
]);

// Authenticate incoming request
$user = $authManager->authenticate($request);
```

### How the module wires it

`AuthModule::bindings()` registers `AuthManager` as **request-scoped** (it must
not outlive a request) and `RbacManager` as a **singleton**:

```php
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

    return new AuthManager([$bearerAuth]);
});

$this->container->singleton(
    RbacManager::class,
    fn(): RbacManager => new RbacManager()
);
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

// Assign role to user ID (static assignment)
$rbac->assign(10, 'admin');

// checkAccess accepts an optional explicitRoles array:
// - with explicitRoles: uses them directly (DB-backed identity pattern)
// - without: falls back to static assignments
if ($rbac->checkAccess(10, 'posts.create')) {
    // Access granted
}
if ($rbac->checkAccess($userId, 'admin.product.read', $user->getRoles())) {
    // Authorized via the authenticated identity's DB-loaded roles
}
```

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
