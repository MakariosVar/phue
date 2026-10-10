# PhUI client composables

Three shared client-side helpers used by the `.phue` components. They exist because
the same three problems — *"where exactly do I draw this?"*, *"keep the keyboard
inside me"* and *"something else needs to say something"* — were being solved
separately, and slightly differently, in every component that needed them.

Each file is a **client-only** module. `document`/`window` are touched inside function
bodies, never at the top level, so appending one to a component's script is safe even
when that component is built for the server — and a `ssr: false` component can rely on
the DOM being there.

---

## Not here: the always-on runtime

This directory is **opt-in**. A helper reaches your script only because you named
it in `composables: [...]`.

`src/Core/UI/runtime/` is the opposite: `createPhueApp`, `installI18n` and `t` are
injected into every module the loader builds, whether you asked for them or not, and
they resolve through the reserved `phue` import-map entry rather than being copied
into your file. They are ES modules the browser fetches once and caches, not source
the loader inlines — which is what lets `createPhueApp` `import … from "vue"` without
colliding with the loader's own `const createApp` proxy.

| | `js/` (this directory) | `runtime/` |
|---|---|---|
| opt-in | yes, via `composables:` | no |
| how it arrives | source copied into your module | `import … from 'phue'` |
| cost | duplicated per component | fetched once, cached |
| browser-only | yes | yes — SSR installs translations itself |

The split is not cosmetic. A helper inlined into a module cannot `import` anything
(an import binding and the loader's module-scope `const` for the same name is a
`SyntaxError`), so anything that needs Vue itself has to live in `runtime/`.

---

## Consuming a composable

Declare it in the `<script>` frontmatter and call the helper normally. There is no
import to write and no bundler step — `PhueComponentLoader` reads the list, resolves
the names against this directory and appends the whole module to the end of your
script, so its exports are already in scope when your code runs.

```vue
<script>
  composables: ['position', 'overlay'],
</script>
```

Rules the loader enforces:

- Names must match `/^[A-Za-z_$][A-Za-z0-9_$]*$/`. Anything else fails the build
  rather than reaching the browser.
- Duplicates are removed; order of first appearance is preserved.
- A name that is not one of the files here fails the build.
- If your script already declares that identifier, the build **fails on the
  collision** instead of silently letting the local win.

**There is no import to write.** The loader copies the module's source in, it does not
add an import statement for it — so a hand-written `import { positionFor } from '…'`
cannot resolve and is not needed. Note that the loader only inspects `export function`
names, so a *destructured* binding of the same name in your own script slips past the
collision check; prefer a distinct name.

The copy lands at the **end** of the module. That is deliberate: function declarations
are hoisted, so they are in scope from the first line of your code, and appending leaves
your own `import { … } from 'vue'` at the top where a module requires it.

---

## `position.js` — where to draw an anchored panel

```js
const rect = measureAnchor(triggerEl);      // snapshot, or null if detached
const pos  = positionFor(rect, { … });      // pure geometry, no DOM
const handle = trackViewport(reposition);    // capture-phase listeners
releaseViewport(handle);                     // idempotent
```

`positionFor(anchor, options)` is a **pure function**: same input, same output, no
DOM access. That is what makes the geometry testable at all — the 82 cases in
`tests/Core/UI/js/position.test.mjs` run with no browser.

| option | default | meaning |
| --- | --- | --- |
| `placement` | `'bottom'` | `top`/`bottom`/`left`/`right` (also `start`/`end` aliases for `left`/`right`) |
| `align` | `'start'` | cross-axis alignment of the panel against the anchor |
| `offset` | `gap` | distance between anchor edge and panel |
| `gap` | `4` | base offset; `offset` wins when both are given |
| `allowFlip` | `true` | flip to the opposite side when there is no room |
| `clamp` | `false` | slide the panel back inside the viewport |
| `margin` | `0` | keep-out distance from the viewport edge |
| `minWidth` | `null` | panel never narrower than this |
| `size` | `{0,0}` | measured panel size |
| `viewport` | `{0,0}` | viewport size |
| `scroll` | `{0,0}` | document scroll offset |
| `measured` | `true` | set `false` while the panel is unmeasured |

Returns `{ left, top, width, height, right, bottom, flipped, placement }` in
**document space** — `scroll` is already added, so you can position a teleported
panel without doing the addition yourself. `positionFor` never clamps to `width`/
`height` unless you ask: a component owns its own axis.

### `trackViewport(onChange)` returns

```js
{ active, onScroll, onResize, … }
```

`onChange` is called **already coalesced into one `requestAnimationFrame`**, and is
also exposed as `handle.onScroll` so a caller can fire it directly in a test.
`releaseViewport()` is idempotent and safe to call twice.

`scroll` is registered with **`capture: true`**. A bubbling scroll listener never
fires for a nested scroller, which is where the interesting cases live — a dropdown
inside a scrolling column would otherwise sit still while the column moved under it.

### Measure in two phases

Measure the trigger, draw, then measure the panel and reposition. The panel's own
height is not known before it exists, and a single-phase implementation either
guesses or flickers. While unmeasured, pass `measured: false` (or `null` anchor) —
you then get a zeroed position, which is the signal to keep the panel hidden
instead of flashing it at the top-left corner.

---

## `overlay.js` — keep focus inside

```js
const trap = focusTrap(panelEl, { onEscape: close, initialFocus: 'input' });
trap.release();   // idempotent
```

`focusTrap`:

- handles **Tab / Shift+Tab wrapping** at the edges (via the `trapTab` handler),
- marks background siblings `inert`, **refcounted** — two stacked overlays do not
  fight over the same attribute, and the app's own `inert` state is restored rather
  than clobbered,
- keeps a stack so nested overlays release in the right order, pruning entries whose
  root already left the DOM,
- restores focus to whatever was active before it opened.

Returns a no-op `release()` when `root` is null or there is no `document` (SSR), so
callers never need to branch.

Helper exports for bespoke cases: `focusableWithin(root)` and `trapTab(event, root)`.

---

## `store.js` — cross-component state

Two isolated factories. Both keep their state in a closure; the component that owns
the instance decides where it lives.

### `createToastStore()`

```js
{ state, push, dismiss, remove, clear, pause, resume,
  success, error, warning, info,
  attachHost, detachHost, subscribe }
```

`push` appends and returns the toast (so you can `dismiss(toast.id)`).
`dismiss` marks it for the leave transition; `remove` deletes it immediately —
keep that order or the exit animation is skipped.

`subscribe(listener)` pushes a snapshot to the callback and returns an unsubscribe
function.

A store with **no host component cannot animate**, because the host owns the DOM.
The host calls `attachHost(id)` on mount and `detachHost(id)` on unmount; when
several hosts are mounted only the primary one (`state.hosts[0]`) renders, so
remounting does not duplicate the toasts.

### `createConfirmQueue()`

```js
{ state: { queue, current }, request, setLoading, processNext }
```

Requests queue when one is already showing, so two rapid `window.confirm`-style
calls do not overwrite each other. Each entry carries `message`, `title`, `variant`,
`icon`, `closeOnBackdrop` and its own `loading` flag. `setLoading(id, bool)` disables
the buttons of one entry without disturbing the rest.

### Subscriber isolation

A throwing subscriber cannot take the store down. Each callback is invoked
independently and failures are reported through `console.error`; a shared store that
one broken component can crash is worse than no store.

### Where to put the instance

Inside an `ssr: false` component the instance is a module-level `const`:

```js
const toastStore = createToastStore();
```

The evaluated module is per browser page, so state is not shared between SSR
requests — the reason the state does not live on `window`. `window.toast` and
`window.confirm` may still be installed as facades for the legacy API, but they hold
no state.

---

## Tests

| suite | what it pins |
| --- | --- |
| `tests/Core/UI/js/position.test.mjs` | geometry, flip/clamp, viewport tracking |
| `tests/Core/UI/js/overlay.test.mjs` | Tab wrapping, inert refcounting, focus restore |
| `tests/Core/UI/js/store.test.mjs` | queue ordering, loading, subscriber isolation |
| `tests/Core/UI/PhueComponentComposablePreludeTest.php` | loader injection, collisions, comments, dedupe |
| `tests/Core/UI/PhuiOverlayContractTest.php` | component-level invariants (teleport, capture scroll, z-index) |

`tests/Core/UI/js/dom-double.mjs` is the shared DOM double. The window double records
the `capture` flag of every listener — that is the only way to catch a scroll
listener that silently became bubbling.

The runtime has its own suites, in `tests/Core/UI/runtime/`:

| suite | what it pins |
| --- | --- |
| `createPhueApp.test.mjs` | hydrate/render choice, teardown of the previous app, `mount:false`, `t()` wiring |
| `i18n.parity.test.mjs` | the client reproduces `Translator::t()` byte-for-byte, against a PHP-generated fixture |
| `PhueRuntimeExportsTest.php` | every symbol the loader injects is actually exported — a missing one breaks every component in the browser, after the page was already served |

`createPhueApp.test.mjs` doubles Vue rather than importing it, because mounting needs
a real DOM and Node has none. The double records which factory was chosen, which is
what makes the hydrate decision observable. The mounting half is covered by the SSR
sidecar tests and by the live page.