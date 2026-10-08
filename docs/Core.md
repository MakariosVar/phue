# Phue Core — Architecture & Usage

This document describes the `Phue\Core` components that form the foundation of the
Phue framework. The goal is to explain *how* each piece works, *why* it was designed
the way it was, and *how* you actually use it.

Subsystem-specific guides live separately: [Database](Database.md),
[Auth & RBAC](Auth-RBAC.md), [Schii](Schii.md), [Events](Events.md),
[Server Adapters](Server-Adapters.md), [Modules](Modules.md), [CLI](CLI.md).

---

## 1. Overview

The `Core` namespace (`src/Core/`) holds the building blocks every Phue application
relies on. Everything above them — controllers, modules, database access, UI — builds
on top of these primitives.

| Component | File | Responsibility |
|---|---|---|
| `Application` | `Application.php` | Composition root: boots infrastructure, loads modules, runs the request pipeline |
| `Module` | `Module.php` | Abstract base contract for all framework modules |
| `Container` | `Container.php` | Dependency injection, service resolution, autowiring |
| `Config` | `Config.php` | Per-file configuration registry with dot-notation access |
| `Env` / `env()` | `Env.php`, `functions.php` | `.env` loading + global `env()` helper |
| `Request` | `Request.php` | Immutable representation of an incoming HTTP request |
| `Response` | `Response.php` | Representation of an outgoing HTTP response |
| `EventDispatcher` | `EventDispatcher.php` | Decoupled communication via class-based events |
| `Router` | `Routing/Router.php` | Maps an HTTP method + URI to a handler, static-first matching |
| `RouteMatch` | `Routing/RouteMatch.php` | Value object holding a matched handler, params, and route middleware |
| `Route` | `Routing/Attribute/Route.php` | Attribute for declaring routes on controllers and methods |
| `Middleware` | `Routing/Attribute/Middleware.php` | Attribute for attaching middleware to classes or methods |
| `AttributeRouteLoader` | `Routing/Loader/AttributeRouteLoader.php` | Reflection loader & directory scanner for controller attributes |
| `Logger` | `Logger.php` | PSR-3 file logger (`storage/logs/app.log`) |
| `CacheInterface` / `FileCache` | `Cache/` | PSR-16 filesystem cache (L2 caching) |
| `ErrorPageRenderer` | `ErrorPages/ErrorPageRenderer.php` | Custom HTML error pages from `App/Errors/` |
| `ErrorHandlerMiddleware` | `ErrorPages/ErrorHandlerMiddleware.php` | Global middleware rendering error pages / JSON errors |
| `SecurityMiddleware` | `Security/SecurityMiddleware.php` | OWASP defense headers + HSTS (only on proved-secure requests) |
| `HealthChecker` | `Health/HealthChecker.php` | DB / Redis / memory / disk probes for K8s & ALB |
| `HealthController` | `Health/HealthController.php` | Auto-registered health endpoint |
| `ComponentRenderer` | `UI/ComponentRenderer.php` | HTML-first rendering lifecycle: component → layout → shell |
| `HtmlShellRenderer` | `Asset/HtmlShellRenderer.php` | Outer HTML5 shell with styles, scripts, font preloads, import map, module preloads, state |
| `AssetRegistry` | `Asset/AssetRegistry.php` | Asset registration + request-scoped lazy styles/scripts |
| `ComponentLoaderRegistry` | `Frontend/ComponentLoaderRegistry.php` | Composite, fail-safe `.phue` component resolution |
| `PhueComponentLoader` | `UI/PhueComponentLoader.php` | Loads & caches `.phue` components from a directory |
| `PhuePartialRenderer` | `UI/PhuePartialRenderer.php` | Renders HTML-first partial components (e.g. nav) |
| `ComponentDiscoverer` | `UI/ComponentDiscoverer.php` | Auto-discovers components referenced by a template (reserved names: see [PhUI_Components.md §1.1.1](PhUI_Components.md)) |
| `PhueTemplateParser` | `UI/PhueTemplateParser.php` | Parses `.phue` SFCs (PHP/Template/Script blocks) |
| `position.js` | `UI/js/position.js` | Placement math for an anchored panel — `positionFor()` (flip, clamp), `trackViewport()`/`releaseViewport()` (capture-phase scroll/resize), `measureAnchor()` |
| `overlay.js` | `UI/js/overlay.js` | Focus containment for a panel that must hold focus — `focusTrap()`, Tab wrapping, refcounted `inert` on the rest of the page |
| `store.js` | `UI/js/store.js` | Cross-component state — `createToastStore()` (the shared toast host) and `createConfirmQueue()` (the confirm dialog queue) |
| `MarkdownParser` | `Utils/MarkdownParser.php` | XSS-safe markdown parser (fenced code blocks without structural newlines) |

**The three composables are injected, not imported.** A `.phue` `<script>` is served
verbatim as an ESM module, and every one of them imports only from `vue` — there is no
import path to a sibling file. So `PhueComponentLoader` appends the whole composable
module to the end of the script, under a `/* --- Phue JS Helper: … */` marker, and the
component body reaches its exports as ordinary locals. Appending rather than prepending is
what makes it work: function declarations are hoisted, so they are in scope before their
position in the text, and the component's own `import { … } from 'vue'` stays at the top
where a module requires it.

Before appending, the loader scans the module's `export function` names and compares them
against the component's own declarations. A symbol declared twice **fails the build** — it
is a hard error, not a silent override in either direction, so a component can never end
up calling a same-named helper it did not write. Only `export function` is considered; a
destructured binding that happens to share a name with an export is not detected.
Their contract — option names, return shapes, the SSR rules, and how a component is
supposed to call them — is in [`src/Core/UI/js/README.md`](../src/Core/UI/js/README.md).

---

## 2. Application (Composition Root)

The `Application` is the composition root: it wires together the `Container`,
`Router`, and `EventDispatcher`, boots every module listed in `config/app.php`,
and runs incoming requests through a configurable **middleware pipeline**.

### 2.1 Construction

```php
$app = new Application(dirname(__DIR__));
```

The constructor does, in order:

1. Normalizes the base path (trailing slashes stripped).
2. `Env::load()` — reads `.env` from the base path **before** any config, so
   `env()` calls inside config files see the values.
3. Creates the `Container`, `Config` (from `basePath/config`), `Router`, and
   `EventDispatcher`.
4. Reads `app.debug` from config.
5. `registerCoreBindings()` — registers all core singletons into the container
   (see §2.2).
6. `bootstrapDatabase()` — boots the active DB connection from
   `config/database.php` if one is defined, and hands it to
   `BaseActiveRecord::setConnection()`.
7. `loadModules()` — instantiates every module from `config/app.php` (see §3).

### 2.2 What `registerCoreBindings()` registers

| Binding | Scope | Notes |
|---|---|---|
| `LoggerInterface` (alias `logger`) | singleton | `Logger` writing to `storage/logs/app.log` |
| `Config`, `Container`, `Router`, `EventDispatcher`, `Application` | singleton | The app itself is resolvable from the container |
| `SecurityMiddleware` | singleton | Constructed with `app.trustedProxies` |
| `ErrorPages\ErrorPageRenderer`, `ErrorPages\ErrorHandlerMiddleware` | singleton | Error pages from `App/Errors/` |
| `CacheInterface` (alias `cache`) | singleton | `FileCache` rooted at `storage/cache` |
| `PhueComponentLoader` | singleton | App components from `App/Frontend` (recursive, covers `App/Frontend/Components/**`) |
| `phui.loader` | singleton | PhUI kit from `src/Core/UI/Phui` |
| `SsrEngine` (alias `ssr.engine`) | singleton | `BunSsrEngine` when `ssr.enabled`, else `NullSsrEngine` |
| `ComponentLoaderRegistry` | singleton | Composite loader — priority: App/Frontend → PhUI |
| `ComponentDiscoverer`, `PhuePartialRenderer`, `ComponentRenderer` | singleton | Rendering pipeline |
| `AssetRegistry`, `AssetServer`, `HtmlShellRenderer` | singleton | Asset infra (global + request-scoped styles/scripts, font preloads) |
| `NPlusOneDetector` | scoped | Request-isolated SQL query frequency and N+1 detector |

### Database connection is a singleton

`Application::bootstrapDatabase()` binds the database connection as a **singleton**,
not scoped:

```php
$this->container->singleton(Connection::class, fn (): Connection => new Connection($dbConfig));
$this->container->alias('db', Connection::class);
```

A singleton connection reuses the same PDO handle across requests, preserving
connection pooling and avoiding the overhead of a new TCP handshake per request.
Combined with `BaseActiveRecord::setConnectionResolver(fn () => $container->get(Connection::class))`,
the Active Record layer always resolves the current connection from the
container, while the underlying PDO handle stays pooled.

**Why not scoped?** A scoped connection creates a new PDO handle per request,
which defeats connection pooling, loses any connection-level state (prepared
statements, temporary tables, transaction context), and adds unnecessary
overhead in persistent runtimes.

Two global middlewares are registered by default, in this order:

1. `ErrorHandlerMiddleware` — runs first (outermost), so exceptions anywhere in
   the pipeline are rendered as proper error pages / JSON errors.
2. `SecurityMiddleware` — defense-in-depth headers on every response.

### 2.2.1 AssetRegistry: Request-Scoped Styles & Scripts

The `AssetRegistry` manages three pools — two for scripts/styles, one for font preloads:

| Pool | Behavior | Cleared by `beginRequest()` |
|------|----------|-----------------------------|
| **Global** | `addStyle($url)`, `addGlobalScript($url, $isModule, $defer)` — registered once, survives across requests | No |
| **Request** | `addRequestStyle($url)`, `addRequestScript($url, $isModule)` — per-request lazy registration | **Yes** |
| **Fonts** | `addFont($url)` — preload hints, survive across requests | No |

`Application::handle()` calls `AssetRegistry::beginRequest()` at the start of every request, so request-scoped assets from a previous page never leak into the next one (critical for Swoole/RoadRunner persistent processes). `beginRequest()` clears **only** `requestScripts` + `requestStyles` — the styles/scripts/font pools are process-lifetime, so anything added per-request into them leaks for the whole worker. Register them during bootstrap.

**Deduplication:** `addRequestStyle($url)` silently skips if the URL already exists in **either** the global or request pool. This prevents duplicate `<link>` tags when multiple components declare the same stylesheet. `addFont()` dedupes on the full URL, **query string included**.

**Rendering:** `HtmlShellRenderer` calls `AssetRegistry::getStyles()` (global + request merged) and emits `<link rel="stylesheet" href="...">` tags in the `<head>`. Global scripts carry `type="module"` when `$isModule` and `defer` when `$defer`; `getFonts()` emits `<link rel="preload" as="font" type="font/woff2" crossorigin>` in the `<head>`. Both attributes are emitted independently, but `defer` is a no-op on a `type="module"` script — module scripts are deferred by definition, so `$defer` only matters for classic scripts.

> **Preload URLs must be byte-identical to the ones the CSS requests.** A font preload is keyed by its full URL. If a stylesheet asks for `fonts/x.woff2?e34853135f9e39acf64315236852cd5a` and you preload the bare path, the browser treats them as two different cache keys and downloads the font **twice** — with no error, no 404, and no audit warning. The only symptom is a duplicated entry in the network waterfall. When a vendor upgrade changes the cache-busting hash, the preload silently stops doing anything.

### 2.2.2 HTML Shell `<head>` Layout & Module Preloads

`HtmlShellRenderer::render()` assembles the document in this fixed order:

```
<head>
  meta / title
  font preloads          (AssetRegistry::getFonts())
  import map             (<script type="importmap">)
  module preloads        ($modulePreloads → <link rel="modulepreload" as="script">)
  theme init script
  stylesheets            (AssetRegistry::getStyles())
<body>
  component markup
  __INITIAL_STATE__ → __NAV_STATE__ → scripts → inline scripts
```

The import map and the module preloads are emitted **before** any `<script type="module">` and inside `<head>`, because a bare specifier (`import { ref } from 'vue'`) can only be resolved by a map the browser has already parsed. Moving the import map out of `<body>` is therefore a hard requirement of the no-build architecture, not a cosmetic choice.

**`$modulePreloads`** (`array<string>`) is the transitive dependency graph of the rendered component, resolved by `ComponentRenderer` via `ComponentLoaderRegistry::moduleUrl()` and passed per render — it is *not* a registry pool. Two paths use it:

- **Full page load** → rendered as `<link rel="modulepreload">` in `<head>`.
- **SPA navigation** → shipped in the JSON payload under the `modulePreload` key; `phue-explorer.js` appends a matching `<link>` per entry before executing the incoming page scripts, so the dependency graph is already in the HTTP cache when the modules are requested. Entries are deduped against existing `link[rel="modulepreload"]` so a re-navigation does not re-add them.

`ImportMapManager` maps `vue` to the **production** build (`vue.runtime.esm-browser.prod.js`). The `__DEV__` branches, devtools hooks and HMR wiring of the dev build are ~31.5 KiB of extra transfer on the critical path for zero runtime value. A stale import map entry puts the browser and the SSR sidecar on different Vue builds, which surfaces as a hydration mismatch rather than an error.

### 2.2.3 SPA navigation contract (`phue-explorer.js`)

`storage/assets/js/phue-explorer.js` is the only client-side router in Phue, and it is not a router at all: it is a **delegated `click` listener on `document`** that intercepts same-origin anchors, re-fetches the page with `X-Phue-SPA: true`, and swaps `#app`.

```
click on a[href]
  → guards (§ below)
  → same origin?
      no  → browser handles it
      yes → preventDefault() + fetch(url, { headers: { 'X-Phue-SPA': 'true' } })
            → JSON { title, html, state, scripts, navState, modulePreload }
            → pushState → unmount #app → innerHTML → mount modules → sync #nav
            → any throw ⇒ window.location.href = url   (hard reload, by design)
```

**The consequence that drives every other rule: links must be real `<a href>`.** The selector is `a[href]`, narrowed to `HTMLAnchorElement` before any anchor-only property (`target`, `relList`) is read — an SVG `<a href>` matches the selector but has neither, so it is deliberately left to the browser. A `<button>`, a `<div onclick>` or any other non-anchor element is invisible to the interceptor, so its click is never intercepted — the user gets a full page reload, silently. This is also why the same href has to be in the SSR HTML: the link is the crawler's only handle on it, and it works with JavaScript disabled, which no router does.

There is **no `vue-router`** in the framework — `ssr/package.json` ships only `vue` and `@vue/server-renderer`, and no mount calls `app.use(router)`. A `<router-link>` does not fail loudly; Vue renders it as an unknown element with no `href` and logs one console warning.

**What the interceptor leaves to the browser** — each of these is a plain left click (`e.button === 0`, no modifier keys) that the handler declines:

| Case | Why |
|---|---|
| `download` attribute | A fetch with `X-Phue-SPA` would receive the JSON payload envelope instead of the file |
| `target` other than `_self` | "Open somewhere else" is the browser's contract; hijacking it turns a new tab into a same-tab navigation |
| `rel="external"` | The de-facto opt-out marker |
| `data-no-spa` | The explicit opt-out, for anchors that carry JS-driven behaviour |
| `href` starting with `#` | Same-page scroll, not a navigation |
| Cross-origin `href` | Not ours to route |
| Any non-HTTP scheme (`mailto:`, `tel:`, …) | Caught by the origin check, not by an allowlist — `new URL('mailto:…').origin` is `"null"` |

Modifier clicks and non-left buttons are ignored earlier still, so ctrl/middle/right-click keep their native meaning.

**Post-mount steps cannot fail the navigation.** `navigate()` wraps everything in one `try`, and its `catch` is `window.location.href = url`. So a throw in a *cosmetic* step does not degrade — it discards a page that already mounted and hard-reloads the browser instead. `updateNavigationState()` therefore isolates each nav item: an href the URL parser rejects, or a non-HTTP scheme, deactivates that one item (`active` removed, `aria-current` removed) and the sync continues.

> The two rules above exist because of the same failure mode, and neither throws where you can see it. A rejected promise inside an `async` listener is invisible, and `new URL()` on an untrusted `href` throws for plenty of real input (`http://`, `https://[`). Both were shipped: an unguarded `new URL()` in the nav sync turned **every** SPA navigation into a full page reload.

```
<!-- crawled, interceptable, works without JS -->
<a href="/products/7">Product</a>

<!-- new tab: the browser owns it -->
<a href="/products/7" target="_blank" rel="noopener">Product</a>

<!-- opt out explicitly -->
<a href="/orders" data-no-spa>Orders (full reload)</a>
```

### 2.2.4 View Styles Metadata (`'styles'` in `.phue` `<php>` block)

A `.phue` component (or page) can declare its own stylesheet dependencies in the `<php>` block return array:

```php
return [
    'title' => 'My Page',
    'styles' => ['/assets/app/styles/main.css', '/assets/app/styles/custom.css'],
    'state' => [...],
];
```

**Flow:**
1. Component's `<php>` block returns `'styles' => [...]`
2. `PhueComponentLoader` passes it to `View` constructor (`$styles` param, added at the end for BC)
3. `ComponentRenderer::renderView()` iterates `$view->getStyles()` and calls `$assetRegistry->addRequestStyle($url)` for each
4. `HtmlShellRenderer` renders `<link rel="stylesheet" href="...">` in `<head>`
5. `beginRequest()` clears request pool — styles are **request-scoped**, no leak across pages

> **BC note:** `$styles` was appended as the **last** constructor parameter in `View` (after `$html`), so the pre-styles positional signature `new View($tpl, $state, $scripts, $inlineScript, $html)` remains 100% compatible.

### 2.2.5 `App/Assets/` — User-space asset directory

Place your project's CSS/JS in `App/Assets/` (e.g. `App/Assets/styles/main.css`). In `public/index.php`, the source is registered:

```php
$registry->registerSource('/assets/app', $app->getBasePath() . '/App/Assets');
```

This makes `/assets/app/styles/main.css` serve `App/Assets/styles/main.css` via the `AssetsController` (route `/assets/{path}`). Declare it in your page/component:

```php
'styles' => ['/assets/app/styles/main.css'],
```

> **Note:** The Swoole serve command (`src/Modules/Server/Commands/SwooleServeCommand.php`) also registers this source so it works in both dev and production servers.

The `ComponentRenderer` is wired with everything a page needs:

- `layoutName: 'AppLayout'` — every page is wrapped by the layout shell.
- `uriResolver` / `requestResolver` — current request access for nav state.
- `userResolver` — reads the authenticated identity from
  `Phue\Modules\Auth\AuthManager` (when the auth module is installed).
- `themeConfig` — from the `themeToggle` key in `config/app.php`
  (`$config->get('app.themeToggle', [])`).

Two controllers are auto-registered on the router: `AssetsController` (serves
`/assets/*`) and `HealthController`.

### 2.3 Global middleware pipeline (`use()`)

```php
$app->use(function (Request $req, callable $next): Response {
    // before
    $response = $next($req);
    // after
    return $response;
});

// A middleware can also be a class FQCN — it is resolved from the container
$app->use(App\Security\Authenticate::class);
```

Middleware follows the **onion model**: the first middleware registered becomes
the outermost layer (runs first going in, last coming out).

```
Global.before → ClassMiddleware.before → MethodMiddleware.before → Handler → MethodMiddleware.after → ClassMiddleware.after → Global.after
```

Route middleware resolution is **lazy**: if a route-level middleware returns an
early response (e.g. `401 Unauthorized`), execution short-circuits and the target
controller is never instantiated by the DI container.

### 2.4 Handling a request (`handle()`)

```php
$response = $app->handle($request); // or handle() to capture from globals
```

The flow:

1. **Kernel scope boundary:** adopt an existing scope or create a new one.
   ```php
   $existingScope = $this->container->getScope();
   $isLocallyCreatedScope = false;

   if ($existingScope === null) {
       $scope = $this->container->createScope();
       $this->container->setScope($scope);
       $isLocallyCreatedScope = true;
   } else {
       $scope = $existingScope;
   }
   ```
2. Binds the request as request-scoped (`Request::class`).
3. Calls `AssetRegistry::beginRequest()` so per-request script tracking resets
   (SPA responses only include the current page's scripts).
4. Builds and runs the global middleware pipeline.
5. Exception mapping (below) — with a `finally` block that:
   ```php
   finally {
       // 1. Flush the request scope
       $this->container->flushScope($scope);

       // 2. Flush the fallback bucket (state created outside any scope)
       $this->container->flushScope(null);

       // 3. Detach scope only if handle() created it
       if ($isLocallyCreatedScope) {
           $this->container->setScope(null);
       }
   }
   ```
   This ensures the request scope is flushed, the fallback bucket is cleared
   (state created outside any scope, e.g. boot-time migrations), and the scope
   context is detached only if `handle()` created it.
6. Applies `SecurityMiddleware::applyHeaders()` on the final response.

| Exception | Response |
|---|---|
| `HttpException` (in `Core/Http/Exceptions/`) | Its status code; JSON when the client sends `Accept: application/json` or `X-Requested-With: XMLHttpRequest` |
| `InvalidArgumentException` | `400 Bad Request` (message only in debug) |
| `RouteNotFoundException` | `404 Not Found` |
| `MethodNotAllowedException` | `405` + `Allow` header from `getAllowedMethods()` |
| any other `Throwable` | `500` — JSON payload (debug) or custom HTML error page from `App/Errors/` |

### 2.5 Handler resolution & argument binding

Handlers can be closures, `'Controller@method'` strings,
`[Controller::class, 'method']` arrays, or invokable objects — all resolved
through the container (string forms) or invoked directly (closures).

Arguments are bound by reflection, in priority order:

1. **`Request`-typed parameter** → the current request instance.
2. **Name match against route params** → the raw (string) value coerced to the
   declared builtin type (`int`, `float`, `bool`, `string`). Non-numeric values
   against an `int`/`float` hint throw an `InvalidArgumentException` (→ `400`).
3. **`array`-typed parameter** → the full route params map (the name does not
   have to be `$params`).
4. **Fallback** → the parameter's reflected default value.

```php
function (Request $request, array $params) {
    // $params holds ALL route parameters, e.g. ['id' => '42']
}

function (int $id) {
    // 'id' is matched by name and coerced to a real int
}
```

**Union types skip coercion.** A parameter typed `int|string` cannot be coerced —
the raw string is passed through untouched and no `400` is triggered. Coercion
only ever targets a single `ReflectionNamedType`.

### 2.6 Response normalization

| Handler returns | Result |
|---|---|
| `Response` instance | Passed through unchanged |
| `array` | Wrapped in `Response::json($array)` |
| anything else (e.g. string) | `new Response((string) $result, 200)` |

### 2.7 Exposed accessors

```php
$app->getBasePath();   // root directory
$app->getConfig();     // Config instance
$app->use($middleware);
$app->handle($request);
$app->getContainer();  // Container
$app->getRouter();     // Router
$app->getEvents();     // EventDispatcher
```

---

## 3. The Module Contract (`Phue\Core\Module`)

Every framework extension follows a uniform pattern defined in
`src/Core/Module.php`. A module is a plain class extending `Module` — the
framework boots it, injects infrastructure into it, and verifies its
dependencies.

### 3.1 Properties

| Property | Type | Meaning |
|---|---|---|
| `$id` | `string` | Short identifier (e.g. `'auth'`). Registered in the container as `module.<id>` when non-empty |
| `$basePath` | `string` | Injected by `Application` at boot |
| `$modules` | `array` | Nested child modules |
| `$controllerNamespace`, `$controllerMap`, `$urlRules` | — | Routing infrastructure |
| `$type` | `string` | `'layer'` (default) or `'kit'` |
| `$requires` | `array<int, string>` | Module **ids** this module depends on |

### 3.2 Methods

| Method | Purpose |
|---|---|
| `init(): void` *(abstract)* | Called automatically after properties are injected. Convention: call `config()`, `bindings()`, `routes()` in order. |
| `setDependencies(Router, Container, EventDispatcher)` | Injects core infrastructure (called by `Application` before `init()`) |
| `getModule(string $id): ?self` | Resolves a child module from the container (`module.{id}`) |
| `getController(string $id): ?object` | Resolves a controller from `controllerMap` or `controllerNamespace` |
| `requiresDb(): bool` | Override to declare a database requirement (default `false`) |

### 3.3 Hooks (override points)

| Hook | Purpose |
|---|---|
| `bindings(): void` *(protected)* | Register DI bindings |
| `routes(): void` *(protected)* | Register application routes |
| `getRoutesForCaching(): array` *(public)* | Return programmatic routes for CLI route caching (`route:cache`) |
| `registerCommands(Application): void` | Register console commands on the CLI |
| `config(): void` *(protected)* | Merge default config with `config/<id>.php` |
| `nav(): array` *(protected)* | Navigation items for `AppLayout` |
| `permissions(): array` *(protected)* | RBAC permission seeds |
| `assets(): array` *(protected)* | Register CSS/JS assets |

### 3.4 How modules boot (`Application::loadModules()`)

A **two-pass strategy** avoids dependency ordering bugs:

1. **Pass 1** — for every FQCN in `config/app.php` under `app.modules`:
   - Validates the class exists (`RuntimeException` if not).
   - Instantiates it **through the container** (`$this->container->get()`).
   - Validates it extends `Phue\Core\Module`.
   - Injects `$module->basePath`, then `setDependencies(router, container, events)`,
     then calls `init()`.
   - Registers it as a singleton under `module.{id}` (when `$id` is non-empty).
2. **Pass 2** — verifies every module's `$requires` declarations are satisfied;
   throws a `RuntimeException` naming the missing module otherwise:

   > `Module 'rest' requires 'auth', which is not loaded. Add it to config/app.modules.`

This means the **order of modules in `config/app.php` does not matter** — a
module can call `$this->container->has('module.auth')` during `init()` and the
answer is reliable, because all modules are mounted before dependencies are
checked.

```php
// config/app.php
'modules' => [
    \Phue\Modules\Auth\AuthModule::class,
    \Phue\Modules\Schii\SchiiModule::class,
    \Phue\Modules\Rest\RestModule::class,
    \Phue\Modules\Server\ServerModule::class,
    \Phue\Modules\Events\EventModule::class,
    \Phue\Modules\Compiler\CompilerModule::class,
    \Phue\Modules\DevTool\DevToolsModule::class,
],
```

See [Modules](Modules.md) for the full module guide — creating modules with
`module:new`, the hook lifecycle, and the available modules table.

---

## 4. DI Container (`Container.php`)

The container is the service locator + factory for the application. You bind a
service once, then request it wherever you need it, and the container handles
construction — including automatically resolving constructor dependencies.

### The four key methods

| Method | What it does | When to use it |
|---|---|---|
| `set($id, callable $factory)` | Registers a factory that builds the service | Telling the container *how* to create something |
| `get($id)` | Resolves and **caches** the instance (singleton) | You want the *same* instance every time |
| `make($id)` | Resolves a **fresh** instance, no caching | You need a *new* instance each time |
| `has($id)` | Reports whether a service/class is resolvable | Guarding before a `get()` call |

### Singleton vs fresh

```php
$container->set('db', fn (Container $c) => new DatabaseConnection($c->get('config')));

$a = $container->get('db'); // built & cached
$b = $container->get('db'); // same instance as $a
// $a === $b

$c = $container->make('db'); // brand new instance, not cached
// $a !== $c
```

`get()` implements the **singleton pattern**: after first success, the instance
is stored and reused. `make()` always constructs a new one and never touches the
cache.

### Scopes: Application vs Request

For persistent runtimes (Swoole, RoadRunner) the process lives across many
requests, so a plain singleton would leak state between them. The container
distinguishes two scopes:

| Scope | Method | Lifetime | Examples |
|---|---|---|---|
| **Application** | `singleton($id, ?$factory)` | Lives as long as the app | Router, Config, Logger, DB Connection |
| **Request** | `scoped($id, ?$factory)` | One per request, flushed after | Auth user (`UserAccessor`), Current request (`Request`), `NPlusOneDetector` |

```php
$container->singleton(Router::class, fn () => new Router());        // app-scoped
$container->scoped(AuthService::class, fn () => new AuthService()); // request-scoped
```

- The optional factory can be omitted; the service is then **autowired**.
- `bind($id, $factory, bool $isScoped)` is the unified low-level registration
  that both `singleton()` and `scoped()` delegate to. It also keeps the scope
  caches consistent if a service is re-registered with a different scope.
- `alias($alias, $id)` registers an alternative key for the same service
  (e.g. `alias('logger', LoggerInterface::class)`).
- `get()` checks the service's scope and caches it in the matching pool.

> **Default behaviour:** a service resolved with no explicit scope (e.g. via
> autowiring) is treated as **application-scoped**. Services that must not
> outlive a single request (auth, connection) should be declared `scoped()`
> explicitly.

> **Note:** `singleton()` / `scoped()` / `bind()` return **void**. Bind first,
> then retrieve with `$container->get()` — a `$x = $container->singleton(...)`
> pattern silently degrades to `null` under `?->`/`??`.

### Flushing request state (`flushScope()`)

At the end of a request you clear all request-scoped instances:

```php
$container->flushScope();
```

Before purging, the container calls `reset()` on every instance that implements
`ResettableInterface` (in `src/Core/Interfaces/`), so a service can clean up its
own state. `Application::handle()` does this automatically in a `finally` block,
so request state is always cleaned — even when an exception was thrown.

### Alias resolution (all entry points)

`alias($alias, $id)` registers an alternative key for the same service. The
container resolves alias chains **in every entry point** (`get()`, `has()`,
`bound()`, `make()`) via a shared `resolveAliasTarget()` method:

```php
$container->alias('logger', LoggerInterface::class);
$container->get('logger');    // resolves LoggerInterface
$container->has('logger');    // true
$container->bound('logger');  // true
$container->make('logger');   // resolves LoggerInterface
```

- **Cycle detection:** self-referential aliases (`alias('db', 'db')`) and mutual
  recursion (`alias('a', 'b'); alias('b', 'a')`) are detected via a visited set
  and throw an exception — no infinite loops.
- **Single target:** the final concrete ID is used for caching and scoping, so
  `alias('cache', CacheInterface::class)` caches under `CacheInterface` and is
  correctly flushed.

### Single storage for scoped instances (`FALLBACK_SCOPE`)

The container maintains **one storage array** for scoped instances, keyed by
scope ID. The "no active scope" state is represented by the reserved
`FALLBACK_SCOPE` key (`__none__`):

```php
// Inside the container (simplified)
private const FALLBACK_SCOPE = '__none__';
private array $scopedInstances = [];
```

- When no scope is active (`setScope(null)`), services are stored under
  `FALLBACK_SCOPE`.
- When a scope is active, services are stored under that scope's ID.
- `flushScope($scope)` targets the given scope's bucket.
- `flushScope(null)` flushes the **fallback bucket** (`FALLBACK_SCOPE`), not the
  current scope.

This eliminates the "two buckets" problem where services resolved outside a
scope were invisible inside a request scope, and the fallback bucket was never
flushed. Now there is **one storage location per scope**, and `flushScope(null)`
explicitly drains the fallback bucket.

### Request-scope flush with fallback (kernel pattern)

`Application::handle()` implements the kernel-scope pattern:

```php
try {
    $response = $pipeline($request);
} finally {
    // 1. Flush the request scope
    $this->container->flushScope($scope);

    // 2. Flush the fallback bucket (state created outside any scope)
    $this->container->flushScope(null);

    // 3. Detach scope only if handle() created it
    if ($isLocallyCreatedScope) {
        $this->container->setScope(null);
    }
}
```

This ensures:
- The request scope is flushed.
- Any state prepared outside a scope (e.g. boot-time migrations, warm caches)
  is also flushed.
- No stale scope context leaks into the next request.

### Autowiring

If no factory is bound, the container falls back to **autowiring**: it uses
`ReflectionClass` to inspect the constructor and recursively resolves each
constructor dependency out of the container itself.

```php
class ServiceWithDependency {
    public function __construct(public SomeOtherClass $dep) {}
}

// No set() needed — the container resolves SomeOtherClass automatically
$service = $container->get(ServiceWithDependency::class);
```

Rules it follows during autowiring:

- **Concrete class type-hints** are resolved recursively through the container.
- **Scalars** (int, string, ...) use their constructor **default value** if one
  exists; otherwise resolution fails.
- **Interfaces** work only if you have bound them via
  `set(Interface::class, ...)` — the container can't pick an implementation.
- A class with **no constructor** is instantiated directly.

### Error handling

The container throws three custom exceptions (in `src/Core/Exceptions/`), all
extending a base `ContainerException`:

| Exception | Meaning |
|---|---|
| `ContainerException` | General resolution problem (e.g. uninstantiable class, missing scalar) |
| `NotFoundException` | The requested service ID has no binding and no matching class |
| `CircularDependencyException` | A dependency loop was detected (`A → B → A`) |

The container keeps a resolution stack: while resolving `A` it marks `A` as
in-progress; if the chain ever asks for `A` again it throws a
`CircularDependencyException` with the full chain in the message. The marking is
cleaned up in a `finally` block, so a failed resolution never leaves the
container "poisoned" into falsely detecting a cycle later.

---

## 5. Configuration (`Config.php`) & Environment

### Per-file keying

`Config` scans a directory of PHP files and keys each file's returned array by
its **filename**:

```php
// config/app.php        → Config::get('app')     returns the whole app array
// config/database.php   → Config::get('database')
// config/schii.php      → Config::get('schii')
```

```php
$config = new Config($basePath . '/config');
$config->get('database.default');          // dot notation into the array
$config->get('database.connections.sqlite');
$config->has('app.debug');                 // true when the value is non-null
$config->set('app.debug', false);          // dynamic override (dot notation)
```

- `get($key, $default)` walks dot-notation segments and returns the default when
  any segment is missing.
- `has()` is a thin wrapper over `get() !== null`.
- `set()` creates intermediate arrays on demand.

> Because keying is **per file**, `Config::get('schii')` returns the Schii
> section — never `get('app.schii')`.

### Environment (`.env`)

`Env::load($basePath)` reads `.env` at the very start of `Application::__construct()`.
The global helper is the single supported way to read variables:

```php
use function Phue\Core\env;

$debug = env('APP_DEBUG', true);
```

`env($key, $default)` delegates to `Env::get()`. It is used across every
`config/*.php` file (`app.php`, `database.php`, `schii.php`, ...) so environment
overrides work without touching code.

---

## 6. EventDispatcher

The dispatcher decouples components: something *publishes* an event, and anything
interested *subscribes* without the publisher knowing who is listening.

It follows **class-based matching** (the Symfony/PSR-14 school of thought):
listeners subscribe to a specific event *class*, and dispatch matches by that class.

### Basic usage

```php
$dispatcher = new EventDispatcher();

$dispatcher->listen(UserCreated::class, function (UserCreated $event) {
    // handle the event
});

$dispatcher->dispatch(new UserCreated(...));
```

- `listen($eventClass, callable $listener)` returns `$this` for fluent chaining.
- `dispatch(object $event)` runs every matching listener and **returns the event
  object** — listeners can mutate it, and the caller sees the final state.

### Inheritance & interface broadcast

When resolving listeners for an event, the dispatcher considers:

1. The event's own class (`get_class()`)
2. All **parent classes** (`class_parents()`)
3. All **implemented interfaces** (`class_implements()`)

So a listener attached to a base `Event` class, or to an interface the event
implements, will also be triggered.

### Stopping propagation

An event signals that it stops further listeners via `StoppableEventInterface`
(in `src/Core/Interfaces/`) rather than a "magic method" — the dispatcher checks
with `instanceof`:

```php
class UserCreated extends BaseEvent implements StoppableEventInterface {
    private bool $stopped = false;

    public function stop(): void { $this->stopped = true; }
    public function isPropagationStopped(): bool { return $this->stopped; }
}
```

Inside the dispatch loop, if the event reports `isPropagationStopped()`, the loop
`break`s — remaining listeners do not run.

> **Design note:** listener order is currently *registration order*, with no
> priority system. Listener resolution runs in the order
> `[exact class, parents, interfaces]`.

> For the real-time pub/sub Event Bus (Redis Streams, SSE, `#[Broadcastable]`)
> see the [Events](Events.md) guide — that is the `Phue\Modules\Events` subsystem,
> separate from this in-process dispatcher.

---

## 7. Request & Response

### Request (`Request.php`)

An **immutable** representation of the incoming HTTP request. It does not read
from superglobals when you construct it — all values are passed in.

```php
$request = new Request(
    method: 'GET',
    uri: '/users?page=2',
    queryParams: ['page' => '2'],
    parsedBody: [],
    headers: ['Content-Type' => 'application/json'],
    serverParams: [],
);
```

Key ideas:

- **Normalization happens in the constructor.** The method is uppercased
  (`strtoupper`), so a `Request` built manually behaves identically to one
  captured from the environment.
- **`Request::capture()`** is the *only* place that touches superglobals
  (`$_SERVER`, `$_GET`, `$_POST`, `php://input`). It also normalizes header keys
  (e.g. `HTTP_CONTENT_TYPE` → `Content-Type`) and parses a JSON body when the
  `Content-Type` is `application/json`.
- **Getters**:
  - `getMethod()`, `getUri()`, `getQuery()`
  - `getQueryParams()`, `getParsedBody()`
  - `input()` — value lookup with a fallback chain: `parsedBody` → `queryParams` → default
  - `getHeader($name)` — **case-insensitive** header lookup
  - `getHeaders()`, `getServerParams()`
  - `isXmlHttpRequest()` — checks the `X-Requested-With` header

### Response (`Response.php`)

Representation of the outgoing response, designed to be fluently built.

```php
$response = Response::json(['status' => 'ok'], 201);
// or
$response = Response::html('<h1>Hello</h1>');
```

- **Static factories**: `Response::json()` (uses `JSON_UNESCAPED_UNICODE` +
  `JSON_UNESCAPED_SLASHES`, falls back to `{}` if encoding fails) and
  `Response::html()` (sets the appropriate content-type).
- **Fluent setters**: `setStatusCode()`, `setContent()`, `setHeader()` — each
  returns `$this` for chaining.
- **Case-insensitive header handling**: `getHeader()` looks up
  case-insensitively, and `setHeader()` *deduplicates* — setting
  `content-type` replaces an existing `Content-Type` instead of adding a
  duplicate entry.
- **`send()`** sends the response: guards with `headers_sent()`, then sets the
  status line, each header, and echoes the body.

### PSR-7 interop (conversion)

Phue provides **conversion** (not inheritance) between its native
`Request`/`Response` and PSR-7 objects, powered by
[`nyholm/psr7`](https://github.com/Nyholm/psr7). This is what lets the
persistent server adapters (e.g. `RoadRunnerAdapter`) talk to PSR-7 runtimes
while the core classes stay lean.

```php
// From a PSR-7 ServerRequest (e.g. from RoadRunner) into a native Phue Request
$request = Phue\Core\Request::fromPsr7($psr7ServerRequest);

// From a native Phue Response into a PSR-7 Response (e.g. sent back to the worker)
$psr7Response = $response->toPsr7(); // Psr\Http\Message\ResponseInterface
```

Notes:

- `Request::fromPsr7()` accepts any `Psr\Http\Message\ServerRequestInterface`
  and maps headers (joined with `, `), query params, parsed body, server params,
  method, and URI path onto a native `Request`.
- `Response::toPsr7()` returns a `Nyholm\Psr7\Response` with the same status
  code, headers, and body.
- This requires the `nyholm/psr7` package (a runtime dependency declared in
  `composer.json`).

---

## 8. Router & Attribute Routing

The router maps an HTTP **method + URI** to a **handler**. It supports static
paths, named route parameters, inline parameter constraints, attribute-based
routing, route-level middleware, and automatic directory scanning.

### Registering routes

```php
$router = new Router();

$router->get('/users', $handler);
$router->post('/users', $handler);
$router->put('/users/{id:int}', $handler, ['id' => '\d+']);
$router->delete('/users/{id}', $handler);

// or the generic form with custom parameter regex constraints
$router->addRoute('PATCH', '/users/{id}', $handler, where: ['id' => '\d+']);
```

### Attribute-Based Routing (`#[Route]`)

Instead of manual `addRoute` calls, controllers declare routes using the
`#[Route]` attribute (defined in `Phue\Core\Routing\Attribute\Route`):

```php
namespace App\Controllers;

use Phue\Core\Routing\Attribute\Route;
use Phue\Core\Routing\Attribute\Middleware;

#[Route('/api/v1/users', name: 'users.')]
#[Middleware(AuthMiddleware::class)]
class UserController
{
    #[Route('', methods: ['GET'], name: 'index')]
    public function index(): array { ... }

    #[Route('/{id:int}', methods: ['GET', 'POST'], where: ['id' => '\d+'])]
    #[Middleware(RoleCheckMiddleware::class)]
    public function show(Request $req, array $params): Response { ... }
}
```

- **Class & Method Level**: class-level `#[Route]` sets a base path prefix and
  route name prefix for all action methods.
- **Repeatable**: methods can have multiple `#[Route]` attributes.
- **Route concatenation**: `AttributeRouteLoader::combinePaths()` concatenates
  the class-level prefix with the method path — `#[Route('/products')]` +
  `#[Route('/', methods: ['GET'])]` → `/products`. Avoid duplicating the prefix
  in the method route (e.g. `#[Route('/products/index')]` with a
  `#[Route('/products')]` class prefix yields `/products/products/index`).

### Route-Level Middleware (`#[Middleware]`)

- **Class-Level**: applied to all routes in the controller.
- **Method-Level**: applied to the specific action.
- **Onion Execution Order**: `Global Middleware → Class Middleware → Method
  Middleware → Controller Action`.

### Automatic Controller Scanning (`scanControllers`)

```php
$router->scanControllers(__DIR__ . '/Controllers', 'App\\Controllers');
```

Recursively scans the directory for `.php` files, resolves FQCNs, filters out
non-instantiable classes (`interfaces`, `traits`, `abstract classes`), and
registers all discovered routes automatically.

### Inline Parameter Types & Regex Constraints

| Placeholder | Regex Equivalent | Example Path |
|---|---|---|
| `{id:int}` | `\d+` | `/users/42` |
| `{name:alpha}` | `[a-zA-Z]+` | `/users/john` |
| `{slug:slug}` | `[a-zA-Z0-9_-]+` | `/posts/my-first-post` |
| `{id:uuid}` | standard UUID regex | `/items/550e8400-e29b-41d4-a716-446655440000` |

Custom regex constraints can also be passed via the `where` parameter in
attributes or `addRoute()`.

### Static-First Route Matching Priority

The router sorts registered routes before dispatching (`sortRoutes()`):

1. **Static routes** (0 placeholders) take precedence over dynamic
   parameterized routes.
2. **Longer static prefixes** take precedence among tied parameter counts.

This guarantees that a static path like `/users/new` is evaluated before
`/users/{id}`, regardless of registration order.

### RouteMatch

`dispatch()` returns a `RouteMatch` value object exposing:

- `getHandler()` — the matched handler
- `getParams()` — extracted named parameters (`array<string, string>`)
- `getMiddleware()` — route-specific middleware stack (`array<string>`)

### Errors

| Situation | Exception | HTTP code |
|---|---|---|
| No route matches the URI | `RouteNotFoundException` | `404` |
| Path matches but method isn't allowed | `MethodNotAllowedException` | `405` |

`MethodNotAllowedException` exposes `getAllowedMethods()`, returning the list of
methods allowed for that path (so you can send back a correct `Allow` header).

### 8.5 Route Caching (`route:cache`)

For production performance, Phue can compile all controller and module routes into a single PHP array file (`storage/cache/routes.php`), eliminating filesystem scanning and Reflection at boot:

- **Enabled via config**: `'routeCache' => true` in `config/app.php` (or auto-detected when cache exists).
- **Compile routes**: `./phue route:cache` runs `RouteCacheCommand` to generate the cache file with a source fingerprint.
- **Clear cache**: `./phue route:clear` removes `storage/cache/routes.php`.
- **Integrity safeguards**: `AttributeRouteLoader::loadCached()` checks the file, validates the source fingerprint against controllers and modules, and ensures framework-required paths (like `/health/live` and `/assets/*`) are present before accepting the cache. If any check fails, it cleanly falls back to live scanning.

---

## 9. Error pages, Security & Health

### Custom error pages (`App/Errors/`)

The `ErrorPages\ErrorPageRenderer` (registered as a singleton with the base
path) renders HTML error pages from your application's `App/Errors/` directory:

- `404.php`, `500.php`, `default.php` — the specific page wins over the default.
- The `ErrorHandlerMiddleware` (global, outermost) catches exceptions in the
  pipeline and renders these pages; when the client asks for JSON
  (`Accept: application/json` or `X-Requested-With: XMLHttpRequest`) it returns
  a JSON error payload instead. In debug mode the JSON payload includes
  `class`, `file`, `line`, and `trace`.

### Security headers (`SecurityMiddleware`)

`SecurityMiddleware` is registered as a global middleware **and** applied on the
final response in `Application::handle()` — covering 404/405/500 paths too.

| Header | Rule |
|---|---|
| `X-Content-Type-Options: nosniff` | always |
| `X-Frame-Options: SAMEORIGIN` | always |
| `X-XSS-Protection: 1; mode=block` | always |
| `Referrer-Policy: strict-origin-when-cross-origin` | always |
| `Strict-Transport-Security` | **only when the request is deemed secure** |

A request counts as secure when either:

1. **Direct TLS termination** — `$_SERVER['HTTPS'] === 'on'`.
2. **Forwarded over a trusted proxy** — `X-Forwarded-Proto: https` **and** the
   connecting remote address (`REMOTE_ADDR`) is listed in `app.trustedProxies`.

`app.trustedProxies` defaults to `[]` (trust no proxy) — a spoofed
`X-Forwarded-Proto` from an untrusted source is ignored, so a plain-HTTP request
can never be tricked into advertising HSTS. Use `'*'` when every connecting peer
is a trusted reverse proxy:

```php
// config/app.php
'trustedProxies' => ['10.0.0.0/8', '192.168.1.10'],  // or ['*'] behind a CDN
```

### CSRF Protection (`CsrfMiddleware`)

CSRF protection is enabled by default and is registered as a global middleware **inside** the
error handler, so a rejected token is rendered as a proper 403 (JSON when the client asks
for it) rather than escaping as a bare 500.

#### How it works

The framework uses a **signed double-submit cookie** pattern:

1. A random nonce is generated for each session/render and signed with HMAC-SHA256 using
   the application secret (`APP_KEY`). The signed token is issued in a **readable** cookie
   (`phue_csrf` by default, `SameSite=Strict`, `Secure` on HTTPS).
2. The client must return the full token on every state-changing request (POST, PUT,
   PATCH, DELETE) either in the `X-CSRF-Token` header or in the `_csrf` form field.
3. The middleware verifies the HMAC with `hash_equals` (constant-time) and rejects
   malformed or missing tokens with a 403.

#### What is exempt

| Condition | Reason |
|---|---|
| Safe methods (GET, HEAD, OPTIONS, TRACE) | No state change, no CSRF risk. |
| `Authorization: Bearer …` header present | A bearer token is attached by client code, not the browser, so it cannot be ridden cross-site. This keeps Schii/Rest APIs working untouched. |
| `#[CsrfExempt]` on the route handler | For webhooks / callbacks that genuinely cannot carry a token. |
| Request carries **no cookies** | No ambient credential → nothing to ride; a pure API client or a browser with an empty cookie jar is not forced to invent a token. |

#### Token delivery to the client

The token cookie is issued on every HTML response (see `CsrfConfig::issueTokenOnHtmlResponses`),
and a synchronous `<head>` script patches `window.fetch` to attach the `X-CSRF-Token` header
automatically on unsafe methods. This covers:

- All `.phue` components' `api()` helpers (`Schii`, `Phii`, login)
- Every third-party component that uses `fetch`/`XMLHttpRequest`
- Native `<form>` submissions (the cookie value is echoed in a hidden `_csrf` field by the browser)

A response that never passes through the middleware (e.g. an exception page) still receives
the token cookie because `Application::handle()` re-applies it on the final response —
exactly like `SecurityMiddleware::applyHeaders()`.

#### Configuration (`config/app.php` → `app.csrf`)

```php
'csrf' => [
    'enabled' => true,
    'headerName' => 'X-CSRF-Token',
    'fieldName'  => '_csrf',
    'cookieName' => 'phue_csrf',
    'cookiePath' => '/',
    'cookieSameSite' => 'Strict',
    'requireSecureCookie' => true,      // Secure flag forced on HTTPS
    'issueTokenOnHtmlResponses' => true,
    'safeMethods' => ['GET', 'HEAD', 'OPTIONS', 'TRACE'],
    'nonAmbientAuthSchemes' => ['bearer'],
],
```

#### Application secret (`APP_KEY`)

The signing key is read from `app.appKey` (`config/app.php`) or `env('APP_KEY')`. A
missing or malformed key is a **misconfiguration**, not a runtime fallback:

```
./phue generate:key          # writes APP_KEY into .env (creates it if empty)
./phue generate:key --force  # rotates an existing key
```

Rotating the key invalidates every token issued under the old one — use `--force`
intentionally, not as a troubleshooting step.

### Health probes (`HealthChecker` + `HealthController`)

`HealthChecker` evaluates real-time metrics for Kubernetes / AWS ALB probes:

- Database connectivity (`SELECT 1`)
- Redis server PING response
- Memory consumption limits
- Filesystem disk space availability (supports a custom `$basePath` for mounts)

`HealthController` is auto-registered on the router by the `Application`, so the
health endpoint is available without any wiring.

---

## 9.5 MarkdownParser: Fenced Code Blocks Without Structural Newlines

The `MarkdownParser` (`src/Core/Utils/MarkdownParser.php`) parses fenced code blocks (``` ```). Previously, the parser emitted structural newlines around the code content due to the final `implode("\n", $htmlLines)` join, producing output like:

```html
<pre><code>
$code = 'hello';
</code></pre>
```

The leading/trailing newlines inside `<pre><code>` rendered as blank lines at the top/bottom of the code block — visible as "phantom whitespace" that no CSS padding could remove.

**Fixed:** The parser now collects code block lines in a dedicated buffer and emits the entire `<pre><code>...</code></pre>` as a **single** HTML line entry, so the structural newlines from `implode("\n")` never enter the `<pre>`. Output is now:

```html
<pre><code>$code = 'hello';
</code></pre>
```

- **Leading newline** after opening fence: removed
- **Trailing newline** before closing fence: removed  
- **Internal newlines** between code lines: **preserved** (joined by `\n`)

This matches standard CommonMark/GFM rendering behavior and eliminates the "unremovable padding-top" visual artifact on code blocks styled with `padding` in CSS.

---

## 10. Intentional design decisions (why it's built this way)

A collection of choices worth documenting so future readers understand the *why*:

- **Class-based events over string keys.** Type-safe, discoverable, and easily refactored — you can't mis-type a magic string.
- **`StoppableEventInterface` over `method_exists`.** Using an interface (via `instanceof`) is explicit and semantic instead of relying on an arbitrary method name existing on any object.
- **Immutable request normalization in one place.** `Request::capture()` is the only superglobal-touching code, and normalization (uppercase method, header keys) lives in the constructor so hand-built and captured requests behave identically.
- **`get()` caches by default; `make()` is the explicit escape hatch.** You opt *in* to a fresh instance rather than having to remember to opt out of caching.
- **Case-insensitive headers on both Request and Response.** HTTP headers are case-insensitive (RFC 7230), so both sides normalize and deduplicate consistently — avoiding the classic "they differ in casing" bug.
- **`RouteMatch` as a value object.** Dispatch returns structured data (handler + params), not a bare tuple, keeping call sites readable.
- **`Application` as composition root.** The app owns and wires the container, router, and event dispatcher; components never reach for globals or construct their own dependencies. This keeps wiring in one discoverable place and makes the pipeline ("onion") behavior explicit and testable.
- **Explicit request scoping instead of implicit state.** The container exposes `singleton()` / `scoped()` and a single `flushScope()` that `Application::handle()` runs automatically in a `finally`. This trades a bit of coroutine-friendliness for a far simpler mental model — and it guarantees request cleanup even on exceptions.
- **Modules boot in two passes.** All modules mount first (with infrastructure injected), *then* `requires` are verified — so module order in `config/app.php` never causes ordering bugs.
