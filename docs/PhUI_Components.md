# Phue PhUI — Built-in Components

This document is the reference API for every component shipped with the Phue
frontend toolkit (`PhUI`). Components live in `src/Core/UI/Phui/*.phue` and are
single-file Vue 3 modules styled with Bootstrap 5 utility classes. There is no
build step, no npm, and no component whitelist to maintain.

---

## 1. How components are used

### 1.1 No imports, no registration

Pages are `.phue` templates. When the framework compiles a page it scans the
template with `PhueComponentLoader` (`src/Core/UI/ComponentDiscoverer.php`) and
auto-imports every component the template actually references — and only those:
**no whitelist, no import; auto-discovered
from the template and imported into one self-sufficient module, minted per page.**

You just write the tag in kebab-case:

```
<ph-button variant="primary" @click="save">Save</ph-button>
<ph-input v-model="email" label="Email" />
```

The loader builds the `components: { PhButton, PhInput, ... }` map itself and
only ships the JS module for the referenced components.

### 1.1.1 Reserved component names: `A`, `B`, `I`, `P`, `Q`, `S`, `U`, `W`

**These eight names cannot be used for components. It is deliberate, not a bug.**

Discovery classifies a tag as *native markup* when the tag case-folds to an
HTML element name. A `.phue` template is HTML, so `<b>`, `<i>`, `<p>`, `<a>`
must mean the real elements — no opt-in flag, no escaping, no prefix. The
scanner therefore normalises the tag to lower case before that check, and the
only PascalCase names that can collide are the ones whose lower-case form *is*
a single-letter HTML element:

| Reserved | Renders as | Reserved | Renders as |
|----------|-----------|----------|-----------|
| `A` | `<a>` (anchor) | `Q` | `<q>` (inline quote) |
| `B` | `<b>` (bold) | `S` | `<s>` (strikethrough) |
| `I` | `<i>` (italic) | `U` | `<u>` (underline) |
| `P` | `<p>` (paragraph) | `W` | `<w>` (obsolete, still valid HTML) |

The same rule is why the built-in kit uses a `Ph` prefix (`PhButton`,
`PhCard`, …) rather than bare words: `PhButton` case-folds to `phbutton`, which
is not an HTML element, so it can never collide. Following that convention
avoids the whole class of problem.

**Consequence, stated plainly:** a component named `B` is never discovered. The
tag renders as plain native markup, no error is raised, and the component's
script is simply never shipped. There is no escape hatch — `<B />` and `<b />`
both case-fold to `b`, so neither reaches the component. If you need a
single-letter-looking component, give it a real name: `Bold`, `Chip`, `Tag`.

### 1.1.2 Styles: the `<style>` block and the `'styles'` key

A `.phue` component (page or partial) has two independent ways to ship CSS.

**A `<style>` block**, rendered once per page into the document head:

```
<template>
<div class="ring">…</div>
</template>

<style>
.ring:focus-within { outline: 2px solid #86b7fe; }
</style>
```

`PhueTemplateParser` recognises it as a fourth top-level block, `View` carries it
as `$style`, and `ComponentRenderer` emits it as a scoped `<style
data-phue-scope="<component>">` the first time that component renders on a page.

> **Currently un-scoped.** `data-phue-scope` is emitted for identification only —
> nothing rewrites the selectors, so the CSS applies as written and two components
> styling the same class will collide. Reach for a prefixed class (`.my-ring`)
> rather than assuming the attribute scopes anything.

It is separate from `'styles'`, which is for **external** stylesheets via the
`<php>` block:
```php
<php>
return [
    'title' => 'My Page',
    'styles' => ['/assets/app/styles/main.css', '/assets/app/styles/custom.css'],
    'state' => [...],
];
</php>
<template>
<div>My component content</div>
</template>
```

**Flow:**
1. Component's `<php>` block returns `'styles' => [...]` (array of URLs)
2. `PhueComponentLoader` passes it to `View` constructor (`$styles` param)
3. `ComponentRenderer::renderView()` iterates `$view->getStyles()` and calls `$assetRegistry->addRequestStyle($url)` for each
4. `HtmlShellRenderer` renders `<link rel="stylesheet" href="...">` in `<head>`
5. `AssetRegistry::beginRequest()` clears the request pool — styles are **request-scoped**, no leak across pages

> **Key point:** external stylesheets are **request-scoped** (lazy). Each page/component declares its own styles, they're added to the request pool during render, and cleared on the next `beginRequest()`. No global pollution, no manual cleanup needed.

> **BC note:** both parameters were appended to the end of the `View` constructor, after `$html`, so existing positional calls `new View($tpl, $state, $scripts, $inlineScript, $html)` remain 100% compatible. `$styles` (the array of URLs) was added first, `$style` (the `<style>` block body) after it.

> **Asset URL convention:** Use absolute paths from registered asset sources, e.g. `/assets/app/styles/main.css` (serves `App/Assets/styles/main.css` via `registerSource('/assets/app', ...)` in `public/index.php`).

### 1.2 `v-model` is wired per component

| Form | Prop updated | Trigger |
|---|---|---|
| `v-model` | `modelValue` | `update:modelValue` |
| `v-model:current-page` | `currentPage` | `update:currentPage` |

### 1.3 Shared conventions

**Spacing scale** (used by `gap`, `padding`, `margin` props):

| Token  | Value  |
|--------|--------|
| `none` | `0`    |
| `xs`   | `0.25rem` |
| `sm`   | `0.5rem` |
| `md`   | `0.75rem` |
| `lg`   | `1rem`  |
| `xl`   | `1.5rem` |
| `2xl`  | `2rem` |

Numeric values are interpreted as **pixels**. An unknown string is passed
through as a raw CSS value (e.g. `gap="1.25rem"`).

> **`size` is not a spacing prop.** On form controls (`<ph-input-shell>`,
> `<ph-search-input>`, `<ph-switch>`, `<ph-badge>`, `<ph-button>`, …) `size` is a
> *control-size* token (`sm` / `md` / `lg`) that maps to Bootstrap's
> `*-sm` / default / `*-lg` metrics, not to a length from the scale above.
> `<ph-input-shell>` reads its metrics from a single `SIZE_TOKENS` table so the
> styles and the prop validator cannot drift apart.

**Variants.** Components that read `variant` map the neutral/error names onto
Bootstrap classes: `error` → `bg-danger`/`alert-danger`, `neutral` →
`text-bg-secondary`, and so on. Valid values are enumerated per component below.

**Slots.** Where a component has both a `label` prop and a default slot, the
slot wins when present.

### 1.4 State: works unwired, and works controlled

Components that hold a selection — `<ph-accordion>`, `<ph-tree-view>`,
`<ph-segmented-control>`, `<ph-select>`, and the form controls — follow one rule
so you do not have to wire state in every caller:

- **Unwired works.** `<ph-accordion :items="items" />` opens and closes on its
  own. Nothing is emitted that you have to handle.
- **A prop change wins.** If you pass `modelValue` / `selected` / `expanded` and
  change it, the component follows you.
- **A click is authoritative.** Clicking updates the component's own state and
  announces the result, so binding is optional rather than mandatory. Wire it
  when you need the value.

```
<!-- unwired: just works -->
<ph-accordion :items="items" />

<!-- controlled: you own the array -->
<ph-accordion v-model="open" :items="items" />
```

> **The one trade-off:** a prop you pass but never change will drift from what
> the user clicks, because the click is authoritative. If the prop has to be the
> single source of truth, handle the update (`v-model`, or `update:selected` /
> `update:expanded`) so the echo flows back. This is a deliberate choice over the
> alternative — a strictly controlled component that silently does nothing when
> you forget a listener.

Array-valued state is a **common wrong type**. `v-model="0"` on `<ph-accordion>`
used to throw inside the component and take the whole SSR render down with it.
Wrong types now degrade quietly to a sensible default, but the array is still the
contract.

---

## 2. Form Controls

### `<ph-button>`

Button with variant, size, block, loading and disabled states. **All Bootstrap 5 button variants are supported.**

| Prop | Type | Default | Notes |
|---|---|---|---|
| `type` | `String` | `'button'` | Native button type (`button`, `submit`, `reset`) |
| `variant` | `String` | `'primary'` | Any Bootstrap 5 button variant (see table below) |
| `size` | `String` | `'md'` | `sm` (btn-sm) \| `md` (default) \| `lg` (btn-lg) |
| `block` | `Boolean` | `false` | Full width (`w-100`) |
| `disabled` | `Boolean` | `false` | |
| `loading` | `Boolean` | `false` | Shows spinner, disables button |

Emits: `click`.

**Solid variants:**

| Variant | Bootstrap Class | Use Case |
|---|---|---|
| `primary` | `btn-primary` | Primary actions |
| `secondary` | `btn-secondary` | Secondary actions |
| `success` | `btn-success` | Success/confirm actions |
| `danger` | `btn-danger` | Destructive actions |
| `warning` | `btn-warning` | Warning/caution actions |
| `info` | `btn-info` | Informational actions |
| `light` | `btn-light` | Light background |
| `dark` | `btn-dark` | Dark background |
| `link` / `ghost` | `btn-link` | Minimal/ghost button |

> **There is no bare `outline`.** An outline button is `outline-primary`,
> `outline-secondary`, and so on — one per colour. `variant="outline"` is not in
> the validator, so it is rejected and falls back to the default `primary`, which
> turns an intended outline button into a solid one without any visible error
> beyond a dev-mode prop warning.

**Outline variants:**

| Variant | Bootstrap Class | Use Case |
|---|---|---|
| `outline-primary` | `btn-outline-primary` | Outlined primary |
| `outline-secondary` | `btn-outline-secondary` | Outlined secondary |
| `outline-success` | `btn-outline-success` | Outlined success |
| `outline-danger` | `btn-outline-danger` | Outlined danger |
| `outline-warning` | `btn-outline-warning` | Outlined warning |
| `outline-info` | `btn-outline-info` | Outlined info |
| `outline-light` | `btn-outline-light` | Outlined light |
| `outline-dark` | `btn-outline-dark` | Outlined dark |

```
<ph-button variant="primary" @click="save">Save</ph-button>
<ph-button variant="secondary">Secondary</ph-button>
<ph-button variant="success">Success</ph-button>
<ph-button variant="danger" loading>Deleting…</ph-button>
<ph-button variant="warning">Warning</ph-button>
<ph-button variant="info">Info</ph-button>
<ph-button variant="outline-primary">Outline Primary</ph-button>
<ph-button variant="outline-secondary">Outline Secondary</ph-button>
<ph-button variant="outline-danger">Outline Danger</ph-button>
<ph-button variant="outline-warning">Outline Warning</ph-button>
<ph-button variant="link">Link / Ghost</ph-button>
<ph-button variant="primary" block>Full width</ph-button>
<ph-button variant="outline-danger" disabled>Disabled</ph-button>

<!-- With icons -->
<ph-button variant="primary">
  <ph-icon name="download" size="sm" class="me-1" />
  Download
</ph-button>
```

### `<ph-input>`

Single-line text input with label, helper/error text, disabled/readonly states.
The id is auto-generated with Vue's `useId()` unless you pass your own.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `String \| Number` | `''` | Bound via `v-model` |
| `id` | `String` | `null` | Falls back to `ph-input-<id>` |
| `name` | `String` | `''` | Forwarded to the inner control, so `<ph-form>` can validate and submit it |
| `type` | `String` | `'text'` | Any native input type |
| `label` | `String` | `''` | Rendered with `form-label`; `*` marker when `required` |
| `placeholder` | `String` | `''` | |
| `helperText` | `String` | `''` | Shown as `form-text` |
| `error` | `String` | `''` | Non-empty → `is-invalid` + red `form-text` |
| `disabled` | `Boolean` | `false` | |
| `readonly` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | Sets the native `required` attribute (and the `*` marker) |

Emits: `update:modelValue` (on `input`), `blur`, `focus`.

```
<ph-input v-model="email" label="Email" placeholder="you@example.com"
          helper-text="We never share your email." :error="emailError" />
```

### `<ph-number-input>`

Numeric input with increment/decrement controls, min/max, step, precision.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `name` | `String` | `''` | Forwarded to the inner control, so `<ph-form>` can validate and submit it |
| `modelValue` | `String \| Number` | `''` | |
| `id` | `String` | `null` | |
| `min` | `Number` | `0` | |
| `max` | `Number` | `100` | |
| `step` | `Number` | `1` | |
| `precision` | `Number` | `0` | Decimal places |
| `controls` | `Boolean` | `true` | Show ± buttons |
| `label` | `String` | `''` | |
| `variation` | `String` | `outline-secondary` | Bootstrap variations |
| `placeholder` | `String` | `''` | |
| `helperText` | `String` | `''` | |
| `error` | `String` | `''` | |
| `disabled` | `Boolean` | `false` | |
| `readonly` | `Boolean` | `false` | |
| `required` | `Boolean` | `false`  Sets the native `required` attribute |

Emits: `update:modelValue`, `blur`, `focus`.

```
<ph-number-input v-model="quantity" :min="0" :max="100" :step="5" label="Quantity" />
```

### `<ph-select>`

Native select bound to `modelValue`. Options accept either objects
`{ label, value, disabled? }` or plain primitives (normalized internally).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `String \| Number` | `''` | |
| `options` | `Array` | `[]` | `{label, value, disabled?}` or primitive values |
| `id` | `String` | `null` | Falls back to `ph-select-<id>` |
| `name` | `String` | `''` | Forwarded to the inner control, so `<ph-form>` can validate and submit it |
| `label` | `String` | `''` | `*` marker when `required` |
| `placeholder` | `String` | `''` | Rendered as a disabled, selected option |
| `helperText` | `String` | `''` | |
| `error` | `String` | `''` | `is-invalid` + red `form-text` |
| `disabled` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | Sets the native `required` attribute |

Emits: `update:modelValue` (on `change`).

```
<ph-select v-model="framework" label="Framework"
           :options="[
             { label: 'Vue 3', value: 'vue' },
             { label: 'React', value: 'react', disabled: true }
           ]" />
```


### `<ph-select3>`

Searchable combobox with chips, replacing the limits of the native
`<ph-select>`. Renders a `role="combobox"` control; the list is a
`dropdown-menu` anchored under it, so it is not clipped by a scrolling parent.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `String \| Number \| Array` | `''` | Array of values when `multiple` |
| `options` | `Array` | `[]` | `{label, value, disabled?}` or bare primitives (label = the value) |
| `id` | `String` | `null` | Falls back to `ph-select3-<id>` |
| `label` | `String` | `''` | |
| `placeholder` | `String` | `'Select…'` | Shown while nothing is selected |
| `helperText` | `String` | `''` | |
| `error` | `String` | `''` | `is-invalid` + red `form-text` |
| `multiple` | `Boolean` | `false` | Chips + array `modelValue` |
| `searchable` | `Boolean` | `true` | Renders the filter input; autofocuses it on open |
| `clearable` | `Boolean` | `false` | ✕ appears only once a value is selected |
| `disabled` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | **Visual only** (`*` marker) — `<ph-select3>` owns its own input, so it is not part of a `<ph-form>` |

Emits: `update:modelValue`, `search(query)`, `open`, `close`, `clear`.
Slots: `footer` — scoped `{ close }`.

```
<!-- Single select -->
<ph-select3 v-model="country" label="Country" placeholder="Pick a country…"
            :options="countries" @search="onSearch" />

<!-- Multiple select: modelValue is an array of values, rendered as chips -->
<ph-select3 v-model="tags" multiple clearable label="Tags"
            :options="[
              { label: 'Vue 3', value: 'vue' },
              { label: 'React', value: 'react' },
              { label: 'Svelte', value: 'svelte', disabled: true }
            ]" />

<!-- A "create" row: the footer slot is rendered inside the list, and the scoped
     `close` collapses it so the freshly created option is visible right away -->
<ph-select3 v-model="owner" :options="users" searchable>
  <template #footer="{ close }">
    <button type="button" class="dropdown-item text-primary"
            @click="createUser().then((u) => { users.push(u); close() })">
      + Create “{{ query }}”
    </button>
  </template>
</ph-select3>
```

**Server-side search.** `search` fires on every keystroke with the raw query, but
the component always filters the `options` it was given on top of that. So a
remote source must return a **superset** of the query (never a pre-filtered list
that excludes the typed term), and you widen `options` from the `search` handler:

```
<ph-select3 v-model="user" :options="foundUsers" @search="q => foundUsers = loadUsers(q)" />
```
### `<ph-textarea>`

Multi-line textarea. Emits on `input` as you type.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `String` | `''` | |
| `id` | `String` | `null` | Falls back to `ph-textarea-<id>` |
| `name` | `String` | `''` | Forwarded to the inner control, so `<ph-form>` can validate and submit it |
| `rows` | `Number` | `4` | |
| `resize` | `String` | `'vertical'` | `none` \| `both` \| `horizontal` \| `vertical` |
| `label` | `String` | `''` | `*` marker when `required` |
| `placeholder` | `String` | `''` | |
| `helperText` | `String` | `''` | |
| `error` | `String` | `''` | `is-invalid` + red `form-text` |
| `disabled` | `Boolean` | `false` | |
| `readonly` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | Sets the native `required` attribute |

Emits: `update:modelValue`, `blur`, `focus`.

```
<ph-textarea v-model="message" label="Message" :rows="5" resize="vertical"
              helper-text="Max 500 characters." />
```

### `<ph-checkbox>`

Boolean checkbox with optional indeterminate visual state.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `id` | `String` | `''` | Explicit `id` for the inner input, so an external `<label for>` can target it. Auto-generated via `useId()` when omitted |
| `name` | `String` | `''` | Forwarded to the inner control, so `<ph-form>` can validate and submit it |
| `modelValue` | `Boolean` | `false` | |
| `label` | `String` | `''` | Falls back to default slot |
| `indeterminate` | `Boolean` | `false` | Applied live via the DOM property |
| `disabled` | `Boolean` | `false` | |

Emits: `update:modelValue` (on `change`, emits `$event.target.checked`).

```
<ph-checkbox v-model="accepted" label="I agree to the terms" />
<ph-checkbox v-model="partial" indeterminate>Parent has children</ph-checkbox>
```

### `<ph-radio>`

Radio input. A group is created by sharing the same `name`; equality with the
group's `modelValue` drives `checked`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `id` | `String` | `''` | Explicit `id` for the inner input, so an external `<label for>` can target it. Auto-generated via `useId()` when omitted |
| `modelValue` | `String \| Number \| Boolean` | `null` | |
| `value` | `String \| Number \| Boolean` | **required** | Emitted value; `checked` when `modelValue === value` |
| `name` | `String` | `''` | Grouping |
| `label` | `String` | `''` | Or default slot |
| `disabled` | `Boolean` | `false` | |

Emits: `update:modelValue` (emits the radio's `value`).

```
<ph-radio v-model="plan" name="plan" value="free" label="Free" />
<ph-radio v-model="plan" name="plan" value="pro" label="Pro" />
```
### `<ph-switch>`

Boolean toggle (`.form-switch`). Same contract as `<ph-checkbox>`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `Boolean` | `false` | |
| `id` | `String` | `null` | Falls back to `ph-switch-<id>` |
| `name` | `String` | `''` | Forwarded to the inner control, so `<ph-form>` can validate and submit it |
| `label` | `String` | `''` | Or default slot |
| `disabled` | `Boolean` | `false` | |
| `required` | `Boolean` | `false`  Sets the native `required` attribute |
| `size` | `String` | `'md'` | `sm` \| `md` \| `lg` |

Emits: `update:modelValue` (on `change`), `blur`, `focus`.

```
<ph-switch v-model="enabled" label="Enable feature" size="md" />
```

### `<ph-search-input>`

Search input built on `<ph-input-shell>`, so the clear button renders **inside**
the border box and the focus ring covers the magnifier, the field and the button
as one unit.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `String` | `''` | |
| `id` | `String` | `null` | Falls back to `ph-search-<id>` |
| `name` | `String` | `''` | Forwarded to the inner control, so `<ph-form>` can validate and submit it |
| `placeholder` | `String` | `'Search…'` | |
| `debounce` | `Number` | `300` | Ms. `0` emits `search` **synchronously** |
| `clearable` | `Boolean` | `true` | Shows the ✕ while `modelValue` is non-empty |
| `label` | `String` | `''` | |
| `ariaLabel` | `String` | `'Search'` | Accessible name, applied only when no visible `label` is rendered |
| `helperText` | `String` | `''` | |
| `error` | `String` | `''` | Red shell border + red focus ring + `role="alert"` message |
| `disabled` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | Sets the real `required` / `aria-required` |
| `size` | `String` | `'md'` | `sm` \| `md` \| `lg` |

Emits: `update:modelValue`, `search(query)`, `clear`, `focus`, `blur`.

**`search` fires from user input only.** The debounce is driven by the `input`
event, not by a `modelValue` watcher, so a parent that pushes a new value
programmatically does **not** trigger a search.

| Key | Behaviour |
|---|---|
| `Enter` | Cancels the pending debounce and emits `search` immediately |
| `Esc` | Clears the field, emits `clear`, and returns focus to the input |

Other behaviour:

- Clicking the magnifier focuses the field, as does clicking anywhere on the
  shell's padding.
- The magnifier is `aria-hidden` and is **not** a tab stop — the input is the
  single keyboard stop, so no redundant tab target is added.
- The pending debounce is cancelled on `clear` and on unmount, so a stale
  `search` can never land after the field was emptied.
- The browser's native `::-webkit-search-cancel-button` is suppressed
  (`appearance: none`), so there is exactly one ✕.
- `autocomplete="off"`, `spellcheck="false"` and `enterkeyhint="search"` are set
  on the input.

```
<ph-search-input v-model="query" @search="onSearch" placeholder="Search users…"
                 :error="queryError" size="sm" label="Users" />
```

### `<ph-range>`

Range slider (single or dual) with optional value display.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `name` | `String` | `''` | Forwarded to the inner control, so `<ph-form>` can validate and submit it |
| `modelValue` | `Number \| Array` | `0` | Array `[min, max]` when `dual` |
| `id` | `String` | `null` | |
| `min` | `Number` | `0` | |
| `max` | `Number` | `100` | |
| `step` | `Number` | `1` | |
| `dual` | `Boolean` | `false` | Dual thumb |
| `showValue` | `Boolean` | `false` | Display value |
| `label` | `String` | `''` | |
| `disabled` | `Boolean` | `false` | |

Emits: `update:modelValue`.

```
<ph-range v-model="priceRange" :min="0" :max="1000" dual show-value label="Price" />
```

### `<ph-file-input>`

File picker with drag & drop, `accept`/`maxSize` validation and image previews.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `name` | `String` | `''` | Forwarded to the inner control, so `<ph-form>` can validate and submit it |
| `modelValue` | `Array` | `[]` | `File[]` |
| `multiple` | `Boolean` | `false` | |
| `accept` | `String` | `''` | Comma list of mime types / extensions |
| `maxSize` | `Number` | `0` | Max bytes (`0` = unlimited) |
| `dragDrop` | `Boolean` | `true` | |
| `preview` | `Boolean` | `true` | Image thumbnails |
| `label` | `String` | `''` | |
| `helperText` | `String` | `''` | |
| `error` | `String` | `''` | |
| `disabled` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | **Visual only** (`*` marker). The real input is `d-none`, so a required file input would block submit on an invisible element |

Emits: `update:modelValue`, `change(files)`, `remove(file)`, `error(reason)`.

```
<!-- Images only, 2 MB cap, with thumbnails -->
<ph-file-input v-model="attachments" multiple accept="image/*" :max-size="2097152"
               label="Screenshots" helper-text="PNG or JPG, up to 2 MB each."
               @error="onUploadError" />

<!-- Any file, drop zone off, no previews -->
<ph-file-input v-model="contract" accept=".pdf,.docx" :drag-drop="false" :preview="false" />
```

`maxSize` is enforced client-side and reports through the `error` emit rather
than silently dropping the file; the native `accept` attribute is passed through
so the OS picker filters too, but that is a hint, not validation.

### `<ph-date-picker>`

Date / datetime picker with a self-contained calendar popover, or a native input
via `native`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `String` | `''` | ISO `YYYY-MM-DD` or `YYYY-MM-DDTHH:mm` |
| `mode` | `String` | `'date'` | `date` \| `datetime` |
| `min` | `String` | `''` | ISO lower bound |
| `max` | `String` | `''` | ISO upper bound |
| `native` | `Boolean` | `false` | Use native `<input type=date>` |
| `weekStart` | `Number` | `1` | `0` = Sunday. Not working with `native` |
| `id` | `String` | `null` | Falls back to `ph-date-picker-<id>` |
| `label` | `String` | `''` | |
| `placeholder` | `String` | `'Select date…'` | |
| `helperText` | `String` | `''` | |
| `error` | `String` | `''` | |
| `disabled` | `Boolean` | `false` | |
| `required` | `Boolean` | `false`  Sets the native `required` attribute |
| `clearable` | `Boolean` | `true` | |

Emits: `update:modelValue`, `change`, `open`, `close`, `clear`.

```
<!-- Date only, bounded -->
<ph-date-picker v-model="dueOn" mode="date" min="2026-01-01" max="2026-12-31"
                label="Due date" helper-text="Within the current year." />

<!-- Date + time -->
<ph-date-picker v-model="startsAt" mode="datetime" label="Starts at" />

<!-- Native input, Monday-first week -->
<ph-date-picker v-model="publishedOn" native :week-start="1" />
```

`modelValue` is always an ISO string (`YYYY-MM-DD`, or `YYYY-MM-DDTHH:mm` in
`datetime` mode) — never a `Date` — so it round-trips through `v-model` into a
form payload without a serialisation step.


### `<ph-segmented-control>`

Segmented button group (radio-based) with icons and variants.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `String \| Number \| Boolean` | `null` | |
| `options` | `Array` | **required** | `{label, value, icon?, disabled?, key?}` |
| `size` | `String` | `'md'` | `sm` \| `md` \| `lg` |
| `block` | `Boolean` | `false` | Full width |
| `variant` | `String` | `'primary'` | Bootstrap variant |
| `disabled` | `Boolean` | `false` | |
| `name` | `String` | `'ph-segmented'` | Radio group name |
| `ariaLabel` | `String` | `'Segmented control'` | |

The control is a real radio group: each option is a native `<input type="radio">`, and the visible button is its `<label>`. State lives on the input, so no `aria-*` state is duplicated onto the label.

Emits: `update:modelValue`, `change`. No slots.

```
<ph-segmented-control v-model="view" :options="[
  { label: 'Grid', value: 'grid', icon: 'grid' },
  { label: 'List', value: 'list', icon: 'list' }
]" />
```

### `<ph-form>`

Form wrapper that owns the submit cycle: native validation, an error summary, server-side `errors` that block submit, and a native `reset`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `name` | `String` | `''` | The form's own name. Kept out of the submit payload, so `<form name="project">` does not appear as a `project` key |
| `disabled` | `Boolean` | `false` | Sets `disabled` on every field the form manages. Fields that were **already** disabled stay disabled when it flips back to `false` |
| `loading` | `Boolean` | `false` | Sets `aria-busy` and **blocks** `submit` — the double-submit guard |
| `errors` | `Object` | `{}` | Server errors by field name. See below |
| `validateOn` | `String` | `'submit'` | `submit` \| `blur` \| `change` |
| `nativeValidation` | `Boolean` | `false` | `true` asks the browser to render **its own** bubble via `reportValidity()` on the first failing field, and hides the summary. The `<form>` is `novalidate` either way, so the submit event is never swallowed by the browser and `invalid` always fires — see below |
| `showSummary` | `Boolean` | `true` | Render the error summary |
| `summaryTitle` | `String` | `'Please fix the following:'` | Summary heading |

**Emits**

| Emit | Payload | When |
|---|---|---|
| `submit` | `data` | Only after validation passes |
| `reset` | — | After the browser has cleared the fields |
| `invalid` | `errors` | Only on a validation pass that failed. Carries the **whole** failure set, server errors included — it is never an empty object on a failed pass |
| `update:errors` | `errors` | On **every** validation pass, so a pass that clears errors reports them |

**Slots**

| Slot | Scoped props |
|---|---|
| `default` | `{ errors, errorList, validate, reset, submit, disabled, loading, busy }` |
| `actions` | `{ validate, reset, submit, disabled, loading, busy }` |

**Fields must be reachable.** The form enumerates its own `form.elements` and
reads `name` off each control. Native controls work as-is. For PhUI controls that
means the `name` prop has to reach the **inner** control — every form control in
this kit takes `name` and forwards it (`<ph-input name="title">` puts
`name="title"` on the real `<input>`, not on the wrapper `<div>`). A custom
component that swallows `name` is invisible to the form: it will not validate and
will not appear in the payload.

**`errors` blocks submit.** The prop is pushed onto the real controls with
`setCustomValidity`, so a server error fails `checkValidity()` exactly like a
native one — `submit` does not fire, `:invalid` and Bootstrap feedback work, and
the field lands in the summary. Clearing the prop clears the validity again. Only
validity the form itself set is cleared, so a caller managing its own
`setCustomValidity` keeps it.

Accepted shapes, all flattened to one message:

```js
:errors="{ title: 'Already taken.' }"                       // string
:errors="{ title: ['Too short.', 'Too narrow.'] }"          // array  -> joined
:errors="{ title: { min: 'At least 3 characters.' } }"      // keyed  -> "min At least 3 characters."
```

**`validateOn`.** The three modes are real: `submit` validates on submit only,
`blur` on field blur, `change` while typing. The listeners are capture-phase on
the `<form>`, so a child that calls `stopPropagation` cannot opt out.

**Error summary.** When `showSummary` and there is at least one error, an
`alert alert-danger` with `role="alert"` is rendered at the top of the form, one
line per field. Each field name is a link that focuses and scrolls to the control.

**Messages are browser-localized.** `validationMessage` is whatever the user's
browser reports (Greek locale → Greek text), so do not assert on its exact string
in tests.

```
<ph-form
  :loading="isSaving"
  :errors="serverErrors"
  validate-on="blur"
  @submit="onSubmit"
>
  <template #default="{ errorList }">
    <ph-input name="title" label="Project title" required />
    <ph-select name="category" label="Category" :options="categories" required />
    <ph-textarea name="summary" label="Summary" :rows="3" />
  </template>

  <template #actions="{ busy }">
    <ph-button type="submit" :loading="busy">Save</ph-button>
    <ph-button type="button" @click="reset()">Reset</ph-button>
  </template>
</ph-form>
```

```js
// A 422 from the API blocks the next submit until the prop is cleared.
this.serverErrors = response.errors;   // { title: ['The title is already taken.'] }
```

**`nativeValidation`.** The `<form>` always carries `novalidate`, in both modes.
That is deliberate: if the browser were allowed to block the submit event, the form
would never learn that validation failed, `invalid` would not fire and the caller
could not react. So the form owns the submit event and, in native mode, calls
`reportValidity()` on the first failing control to get the browser's own bubble —
and hides the summary, so the two never appear together.

> `reset()` deliberately does **not** cancel the native reset — the browser clears
> the fields, and `reset` is emitted once afterwards. A `type="reset"` button and
> the slot's `reset()` are therefore equivalent, and neither can recurse.

> **`required` is real, not decorative.** `<ph-input>`, `<ph-textarea>`,
> `<ph-select>`, `<ph-number-input>`, `<ph-switch>`, `<ph-search-input>` and
> `<ph-date-picker>` all bind `required` to their inner control, so the browser
> and `<ph-form>` both act on it. The exceptions are deliberate and are marked in
> each component's table: `<ph-select3>` (it owns its own input) and
> `<ph-file-input>` (its real input is hidden).


### `<ph-form-field>`

Layout wrapper around any control: label, slot, and error-or-helper text. Adds
`was-validated` to the wrapper when an error is present (used by Bootstrap for
feedback styling on the slotted control).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `label` | `String` | `''` | `form-label` |
| `error` | `String` | `''` | `valid-feedback d-block` |
| `helperText` | `String` | `''` | `form-text`; shown only when `error` is empty |

Slots: default. No emits.

```
<ph-form-field label="Email" :error="error">
  <ph-input v-model="email" placeholder="you@example.com" />
</ph-form-field>
```


### `<ph-input-shell>`

Shared bordered shell for composite controls: the magnifier/prefix icon, the
field and the trailing clear button all live **inside one border box** and light
up as a single unit on focus.

**Why it exists.** Bootstrap 5.3 declares the focus ring as a `box-shadow` on
`.form-control:focus` — on that element only — and ships **no**
`.input-group:focus-within` rule. So a row of siblings glued together with
`input-group` can never render one unified ring: the field glows in the middle
while the affixes and the button stay flat. This component inverts the structure
instead of fighting it — the shell owns `border`, `border-radius` and the ring,
and the slotted control is a borderless, transparent flex child. Affixes are
spaced by the shell's `gap`, not by per-slot padding.

Because Bootstrap 5.3 gives no unified ring for a glued-together `input-group`,
this component emits the ring as a **bound style** driven by `focusin`/`focusout`
(which are exactly `:focus-within` semantics, and work inside a slotted control),
reusing Bootstrap's own values — `#86b7fe` + `0 0 0 .25rem rgba(13,110,253,.25)`,
and the `--bs-danger-rgb` variant when invalid. It is bound style rather than a
`<style>` block so the ring is driven by one code path for both mouse and
keyboard focus, and so the invalid variant can be switched from the `invalid`
prop.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `size` | `String` | `'md'` | `sm` \| `md` \| `lg` — drives padding, font-size, gap and radius |
| `invalid` | `Boolean` | `false` | Error border + red focus ring |
| `disabled` | `Boolean` | `false` | Muted background, `not-allowed`, focus suppressed |

Emits: `focus`, `blur` (as `focusin`/`focusout`, so they fire for the affixes too).

Slots:

| Slot | Scoped props | Notes |
|---|---|---|
| `prefix` | — | Leading affix (icons, static text) |
| `default` | `{ controlStyle, size, disabled, invalid }` | **The control.** Bind `:style="controlStyle"` — it neutralises `.form-control`'s own box so the field does not draw a second border inside the shell |
| `suffix` | — | Trailing affix (clear button, badges) |

Methods: `focus()` — focuses the slotted control, so consumers never need a
template ref into slot content. Clicking the shell's padding also redirects focus
to the control, but a direct hit on the control is left to the browser so the
caret lands where the user clicked.

```
<ph-input-shell size="sm" :invalid="!!error">
  <template #prefix>
    <span class="d-inline-flex align-items-center" aria-hidden="true">
      <ph-icon name="search" size="sm" variant="muted" />
    </span>
  </template>

  <template #default="{ controlStyle }">
    <input
      type="search"
      class="form-control"
      :style="controlStyle"
      :value="modelValue"
      @input="$emit('update:modelValue', $event.target.value)"
    />
  </template>

  <template #suffix>
    <button v-if="modelValue" type="button" class="btn btn-sm p-0 border-0"
            aria-label="Clear" @click="clear">
      <ph-icon name="x" size="sm" />
    </button>
  </template>
</ph-input-shell>
```

> The slotted control keeps the `form-control` class on purpose: `controlStyle`
> then overrides only the box-drawing declarations, so the placeholder colour and
> disabled colour still come from Bootstrap. It also sets `appearance: none`,
> without which `type="search"` lets the browser draw its own clear affordance
> next to yours.

---

## 3. Data Display

### `<ph-data-table>`

Client-side sortable, paginatable table. Sorting toggles `asc`/`desc` on the
last clicked column (arrow `▲`/`▼`, `↕` when inactive).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `columns` | `Array` | **required** | `{ key, label?, sortable? }` |
| `items` | `Array` | `[]` | Row objects; keys correspond to `columns[].key` |
| `paginated` | `Boolean` | `false` | Toggles the pager row |
| `pageSize` | `Number` | `10` | `<= 0` collapses to a single page |

Internal state: `sortKey`, `sortOrder`, `currentPage` (client-side; the
component does **not** emit page changes).

Slots:
- `empty` (default "No data available")
- `cell(<col.key>)` — scoped `{ row, value }` for per-cell rendering

No emits.

```
<ph-data-table :columns="[
    { key: 'name', label: 'Product', sortable: true },
    { key: 'price', label: 'Price', sortable: true },
  ]" :items="rows" paginated :page-size="4">
  <template #cell(price)="{ value }">€{{ value }}</template>
</ph-data-table>
```

### `<ph-badge>`

Small status label.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `label` | `String` | `''` | Or default slot |
| `variant` | `String` | `'neutral'` | `primary` \| `secondary` \| `success` \| `warning` \| `error` \| `info` \| `neutral` |
| `size` | `String` | `'md'` | `sm` \| `md` \| `lg` |
| `pill` | `Boolean` | `false` | `rounded-pill` |

No emits.

```
<ph-badge variant="success" pill label="Deployed" />
```

### `<ph-chip>`

Pill-shaped removable tag, built on `badge rounded-pill`. Disabled chips are
visually dimmed (`opacity-50`) and non-interactive (`pe-none`).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `label` | `String` | `''` | Or default slot |
| `variant` | `String` | `'neutral'` | Same values as `<ph-badge>` |
| `removable` | `Boolean` | `false` | Renders the close button |
| `disabled` | `Boolean` | `false` | Hides remove button, dims chip |

Emits: `remove`.

```
<ph-chip v-for="(chip, i) in chips" :key="chip" :label="chip"
         variant="primary" removable @remove="chips.splice(i, 1)" />
```

### `<ph-avatar>`

Image avatar with initials fallback. `name` produces initials: first + last
word initials, or the first two characters of a single-word name. Falls back to
`?` when empty.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `src` | `String` | `''` | Image URL; on load error → initials |
| `name` | `String` | `''` | Drives fallback initials |
| `alt` | `String` | `'Avatar'` | |
| `size` | `String` | `'md'` | `xs` \| `sm` \| `md` \| `lg` \| `xl` |
| `shape` | `String` | `'circle'` | `circle` \| `square` \| `rounded` |

No emits.

```
<ph-avatar name="Makarios Varos" size="lg" shape="circle" />
<ph-avatar src="/uploads/u.png" name="Ada Lovelace" alt="Ada" />
```

### `<ph-card>`

Content card. Header (title/subtitle or custom), body (default slot), footer.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `title` | `String` | `''` | |
| `subtitle` | `String` | `''` | |
| `bordered` | `Boolean` | `true` | `false` → `border-0` |
| `hoverable` | `Boolean` | `false` | Adds the `card-hoverable` class |

Slots: `header` (default: title + subtitle), default (body), `footer`
(rendered only if provided).

```
<ph-card title="Weekly Report" subtitle="Last 7 days" hoverable>
  Revenue grew 12% week over week.
  <template #footer>
    <ph-button variant="outline-secondary" size="sm">View details</ph-button>
  </template>
</ph-card>
```

### `<ph-empty-state>`

Empty state placeholder with icon, title, message and optional action.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `title` | `String` | `''` | |
| `message` | `String` | `''` | |
| `icon` | `String` | `''` | Bootstrap icon name |
| `iconSize` | `String \| Number` | `'xl'` | |
| `actionLabel` | `String` | `''` | Button label |
| `actionVariant` | `String` | `'primary'` | Button variant |
| `actionSize` | `String` | `'md'` | `sm` \| `md` \| `lg` |
| `variant` | `String` | `''` | Color variant for background/border |
| `flush` | `Boolean` | `false` | Remove border/radius |

Emits: `action`. Slots: `icon`, `default` (title), `action`.

```
<ph-empty-state
  icon="inbox"
  title="No messages"
  message="You're all caught up!"
  action-label="Compose"
  @action="compose"
/>
```

### `<ph-stat>`

Stat card with label, value, optional delta trend and icon.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `label` | `String` | `''` | |
| `value` | `String \| Number` | `''` | |
| `delta` | `String \| Number` | `null` | Change amount |
| `trend` | `String` | `'neutral'` | `up` \| `down` \| `neutral` |
| `icon` | `String` | `''` | Bootstrap icon name |
| `iconSize` | `String \| Number` | `'lg'` | |
| `iconVariant` | `String` | `''` | Icon color variant |
| `variant` | `String` | `''` | Affects value color and border |
| `bordered` | `Boolean` | `true` | |

Slots: `icon`, `default` (value). No emits.

```
<ph-stat label="Revenue" value="€12,345" delta="+12%" trend="up" icon="graph-up" variant="success" />
```

### `<ph-list-group>`

Flexible list group with headers, dividers, badges, actions and slots.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `items` | `Array` | `[]` | `{label, href?, to?, icon?, badge?, disabled?, active?, header?, divider?, action?}` |
| `flush` | `Boolean` | `false` | Remove borders |
| `numbered` | `Boolean` | `false` | (reserved) |
| `horizontal` | `Boolean \| String` | `false` | `true` or breakpoint (`sm`..`xxl`) |

Emits: `select(item)`. Slots: `item-<key>` (scoped).

```
<ph-list-group :items="[
  { label: 'Item 1', icon: 'star', badge: 3 },
  { divider: true },
  { label: 'Action', action: true }
]" @select="onSelect" />
```

### `<ph-description-list>`

Description list (`<dl>`) with responsive columns.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `items` | `Array` | `[]` | `{label, value, key?}` |
| `columns` | `Number` | `2` | `1` \| `2` \| `3` |

Slots: `item-<key>` (scoped). No emits.

```
<ph-description-list :items="[
  { label: 'Status', value: 'Active' },
  { label: 'Created', value: '2024-01-15' }
]" :columns="2" />
```

### `<ph-timeline>`

Chronological list of events on a connected spine. Vertical (default) or
horizontal, with icon / numbered / plain markers, per-item colour overrides, an
`active` highlight, optional click handling, and a per-item slot for anything
the `items` shape cannot express.

Everything is inline (`style` bindings + Bootstrap utilities) — the component
ships **no `<style>` block and no external stylesheet**, so it can be dropped on
any page without touching a single CSS file and cannot collide with another
page's `.ph-timeline-*` rules.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `items` | `Array` | `[]` | See the item shape below. Non-object entries are dropped |
| `variant` | `String` | `''` | Fallback colour for markers and lines: `primary` \| `secondary` \| `success` \| `danger` \| `error` \| `warning` \| `info` \| `neutral` |
| `orientation` | `String` | `'vertical'` | `vertical` \| `horizontal` |
| `reverse` | `Boolean` | `false` | Flips the reading order (newest-first) via `column-reverse` / `row-reverse` — the `items` array is never mutated |
| `spacing` | `String` | `'md'` | `none` \| `xs` \| `sm` \| `md` \| `lg` \| `xl` (the shared spacing scale) |
| `marker` | `String` | `'md'` | `xs` \| `sm` \| `md` \| `lg` \| `xl` — drives the dot, the content gutter **and** the icon size. Floored at `1.25rem` when any marker has to hold a digit (see below) |
| `lineStyle` | `String` | `'solid'` | `solid` \| `dashed` \| `dotted` \| `none` — `dashed`/`dotted` are painted gradients, not CSS borders (see below) |
| `lineWidth` | `Number` | `2` | Connector thickness in px. Independent of `marker` — the two stay concentric at any combination |
| `clickable` | `Boolean` | `false` | Renders each item as a real `<button type="button">` and emits `item-click` |
| `numbered` | `Boolean` | `false` | Prints the 1-based position inside a marker that has no icon |
| `card` | `Boolean` | `false` | Content sits on a `bg-body-tertiary` card instead of the bare surface |
| `emptyText` | `String` | `'No timeline entries yet.'` | Shown when `items` is empty |

Emits: `item-click(item, index)` — `index` is the position **in the rendered
order**, so it already accounts for `reverse`.

Slots:

| Slot | Scoped props | Notes |
|---|---|---|
| `item-<key>` | `{ item, index, active }` | Projected after the body. The name comes from `item.key` (or `item.id`), falling back to the index |
| `empty` | — | Replaces `emptyText` |

**Item shape** — every field except `title` is optional:

| Field | Type | Notes |
|---|---|---|
| `key` / `id` | `String` | Stable identity: the `v-for` key *and* the `item-<key>` slot name |
| `time` | `String` | Rendered inside a `<time>` element |
| `dateTime` | `String` | Machine-readable value for the `<time datetime="…">` (machine parsing, sorting) |
| `title` | `String` | Takes the item's `variant` as its text colour |
| `body` | `String` | Secondary text |
| `meta` | `String` | Free-form trailing text in the time row (an author, a host) |
| `badge` | `String \| Object` | `'live'` or `{ label, variant }` |
| `icon` | `String` | Bootstrap Icons name, drawn inside the marker |
| `variant` | `String` | Per-item colour; **beats** the component-level `variant` |
| `outline` | `Boolean` | Marker keeps the surface background and takes a coloured ring + glyph |
| `active` | `Boolean` | Coloured halo around the marker, and `aria-current="step"` |
| `done` / `completed` | `Boolean` | Marker falls back to a `check-lg` glyph when no `icon` is given |
| `label` | `String \| Number` | Explicit marker content; wins over `numbered` |
| `dimmed` | `Boolean` | Mutes the whole content block |
| `href` | `String` | Renders the item as an `<a>`; no `item-click` is emitted |
| `disabled` | `Boolean` | `aria-disabled`, click swallowed, not a tab stop |
| `iconVariant` | `String` | Forces the glyph colour |
| `onColor` | `String` | Glyph colour inside a filled marker (defaults to `light`) |
| `class` | `String` | Extra classes on the item element |

**What the component guarantees:**

- **Exactly `n - 1` connectors.** The last item never draws a line into empty
  space, and a single-item timeline draws none at all.
- **The connector always meets the next marker.** It is absolutely positioned
  from the centre of its own dot with a negative offset of `-(gap + dot / 2)`,
  so it crosses the inter-item gap regardless of how tall the content is. The
  in-flow approach (a flex sibling with `height: 100%`) breaks as soon as one
  item has more text than the next.
- **Dot and line are provably concentric, at any size.** Both are pinned to the
  same `left/top` origin and centred by the same cross-axis
  `transform: translate(-50%)`. They used to be centred independently — the dot
  by a negative `margin` of `-dot/2`, the line by `-lineWidth/2` — which only
  coincides when `lineWidth` happens to equal the dot size. At
  `:line-width="6" marker="xl"` (or any other mismatch) the line visibly drifted
  off the dot's axis, and horizontally the drift was along the wrong axis
  entirely. `translate` is applied to the **cross axis only**: the main axis is
  already pinned by `top`/`bottom` or `left`/`right`, and translating it too
  would slide the bar off its own extent.
- **`dashed` is a gradient, not a border.** A CSS `dashed` border derives its
  dash length from the border box, so a 2px dashed border renders as 2px dashes
  with 2px gaps — disconnected fragments, with a pattern that shifts as the
  segment's length changes. `dashed` is a `repeating-linear-gradient` with a
  fixed period (`lineWidth * 2` on, `* 2` off), and `dotted` is a tiled
  `radial-gradient`; both are identical on every item regardless of height.
  Note the gradient stops are written as explicit two-position stops
  (`color 0 4px, transparent 4px 8px`) — a three-position stop is invalid and
  silently drops the whole declaration.
- **Nothing is clipped.** The dot is centred on the spine, so half of it hangs
  outside the first item; the component insets itself by `dot / 2` on the
  leading axis to make room. Without that inset the leading marker is cut in
  half by the parent's padding.
- **A dot that has to hold a digit is big enough to hold it.** When any marker
  renders a number (`numbered`, or an explicit `label`), the dot is floored at
  `1.25rem` regardless of `marker`, and the digit is sized from the *inner* box
  (the box minus the 2px ring) so the border cannot swallow it. A `16px` circle
  with an `8px` digit inside a `2px` ring is not a step marker, it is a smudge.
- **Colour precedence is item → component → neutral**, with `error → danger` and
  `neutral → secondary` aliased onto Bootstrap's real class names, so
  `bg-error` can never be emitted. The connector is drawn at `0.65` alpha: at a
  lower alpha a tinted line on a dark surface — green especially — disappears
  entirely, and the connector is the only thing communicating sequence.
- **Real controls when interactive.** `clickable` gives every item a
  `<button type="button">` (never a form-submitting default) with `Enter` /
  `Space` support; a `disabled` item is not a tab stop and swallows its click.
  An `href` item is left entirely to the browser, so navigation and the emit
  never both fire. Interactive rows carry a hover tint and a focus outline as
  **bound styles driven by `mouseenter`/`mouseleave` and `focusin`/`focusout`**
  — the same approach `<ph-input-shell>` uses, since with no stylesheet there
  is no `:hover` rule to hook. The focus ring is an `outline`, not a
  `box-shadow`, because that channel already carries the `active` halo.
- **No role conflicts.** A static timeline is `role="list"` / `role="listitem"`;
  as soon as any item is interactive those roles are dropped, because a
  `<button role="listitem">` is invalid ARIA.

```
<!-- Vertical, coloured spine, deployment feed -->
<ph-timeline variant="primary" :items="[
  { key: 'd1', time: '10:00', dateTime: '2026-01-15T10:00', title: 'Deployed', body: 'v2.4.0 to production' },
  { key: 'd2', time: '09:30', title: 'Tests passed', body: 'All 1,234 tests green', variant: 'success' },
  { key: 'd3', time: '09:00', title: 'Build failed', body: '2 lint errors', variant: 'danger', icon: 'x-lg' }
]" />

<!-- Steps with numbers, newest first, on cards -->
<ph-timeline numbered reverse card orientation="vertical" :items="steps" />

<!-- Audit log: selectable rows -->
<ph-timeline clickable line-style="dashed" spacing="lg" :items="audit" @item-click="openEntry" />
```

```
<ph-timeline :items="[{ key: 'deploy', title: 'Deploy' }]">
  <template #item-deploy="{ item, index, active }">
    <ph-badge :label="item.stage" variant="info" />
  </template>
  <template #empty>No deployments in this window.</template>
</ph-timeline>
```


### `<ph-code>`

Code block with syntax highlighting (Prism.js), line numbers, copy button, and an interactive theme picker. Prism is loaded same-origin from the framework's npm vendor pipeline (`/assets/vendor/prismjs`); third-party CDN mirrors are blocked by `SecurityMiddleware` (`script-src 'self'`), so a missing vendor pipeline shows plain text with a console warning — run `bin/phue npm:install prismjs`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `code` | `String` | **required** | Source code |
| `language` | `String` | `'text'` | Prism language id; aliases resolved internally (e.g. `js`→`javascript`, `html`→`markup`, `sh`→`bash`) |
| `lineNumbers` | `Boolean` | `false` | Show line numbers (plugin) |
| `copyable` | `Boolean` | `true` | Show copy button |
| `wrap` | `Boolean` | `false` | Wrap long lines |
| `maxHeight` | `String \| Number` | `'400px'` | Max height |
| `theme` | `String` | `'twilight'` | Theme name. The picker writes local state and emits `theme-change`; an external change to this prop wins over the local pick |
| `themeSwitcher` | `Boolean` | `true` | Show the theme dropdown in the header |
| `themes` | `Array` | `['coy','dark','funky','okaidia','solarizedlight','tomorrow','twilight']` | Available theme names |
| `assetBase` | `String` | `'/assets/vendor/prismjs'` | Prism asset root (trailing slash normalized) |

Emits: `copy(code)`, `theme-change(themeName)`. No slots.

The header shows the language label (human-readable alias, e.g. `PHP` not `php`), a theme picker (`<ph-dropdown>` with paint-bucket icon), and a copy button. While Prism is loading the block renders at `opacity: 0.55` so the server-rendered code stays readable without a white-flash jump. The `data-ph-highlighted="1"` attribute on `<code>` signals a successful highlight pass. Copy uses the Clipboard API with an `execCommand` fallback; failures do not stick the button in a copied state.

**Theming is per instance, not per page.** Two blocks on one page can hold two
different themes at once, and switching one does not repaint the other. Prism
ships its themes as page-global CSS — `.token.comment`, `code[class*=language-]`
and friends, with no per-instance opt-in — so a `<link>` can only ever be a
page-wide switch. The component therefore `fetch()`es the theme sheet (same
origin, so `default-src 'self'` allows it), rewrites every selector against
`[data-ph-theme-scope="<id>"]`, and injects one `<style>` it owns. The raw sheet
is cached per URL, so N blocks on a page cost one request; only the rewrite is
per instance. The scope id comes from `useId()`, so it is hydration-stable, and
the `<style>` is removed on unmount.

> It cannot be done with a `<link>`, and it could not be done by simply letting
> the plugin manage the sheet: a shared `data-prism-css="theme-*"` link meant the
> last block to mount or switch took ownership and repainted every block, and a
> second block on a different theme silently fought it for that one link.
>
> The page-wide `<link>` survives only as a **fallback for a failed fetch** — a
> degraded block beats an unstyled one, and it logs a warning. If you ever see
> two blocks share a theme, that is the fallback, not the normal path.

One consequence worth knowing: a scoped theme must be fetched as *text* to be
rewritten, which costs a round trip a plain `<link>` never paid. Prism core and
the theme are therefore **started before either is awaited**, so the two
requests stay in flight together — awaiting the theme first and then starting the
scripts would serialise them and put the white-block flash straight back.

```
<ph-code language="php" :code="$code" copyable theme="twilight" />

<!-- Two blocks, two themes — independent -->
<ph-code :code="$readme" theme="coy" />
<ph-code :code="$diff" theme="dark" />
```

### `<ph-tree-view>`

Hierarchical tree with selection, expansion, checkboxes, a full keyboard model and
per-node slots. State works unwired (see §1.4) or controlled.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `nodes` | `Array` | `[]` | Root nodes — see the node shape below |
| `selected` | `Array` | `[]` | Keys of the selected nodes. Re-synced whenever the prop changes |
| `expanded` | `Array` | `[]` | Keys of the open branches |
| `checkable` | `Boolean` | `false` | Renders a checkbox per node |
| `multiSelect` | `Boolean` | `false` | More than one selection. Without it a click **replaces** the selection |
| `ariaLabel` | `String` | `'Tree'` | Accessible name for the tree |

Emits: `select(node)`, `toggle(node)`, `check(node)`, `update:selected`,
`update:expanded`. Slots: `node-<key>` (scoped).

```
<ph-tree-view :nodes="treeData" checkable @select="onSelect" />

<ph-tree-view :nodes="treeData" v-model:selected="selected"
              v-model:expanded="expanded" checkable multi-select />
```

**Node shape** — every field is optional except `label`:

| Field | Type | Notes |
|---|---|---|
| `key` | `String \| Number` | Identity. Matched against `selected`/`expanded`, used as the `v-for` key, and appended to build the `node-<key>` slot name. **Set it on every node** |
| `label` | `String` | Row text, and the fallback identity when `key` is absent |
| `children` | `Array` | Child nodes. The row only becomes expandable when this is a **non-empty** array |
| `icon` | `String` | Bootstrap Icons name |
| `collapsedIcon` | `String` | Icon shown while the branch is closed |
| `checked` | `Boolean \| String` | `true` / `false`, or the string `'indeterminate'`. **Read-only** — the component emits the next value, it does not write it |
| `disabled` | `Boolean` | `aria-disabled`, click swallowed, not a tab stop |

**`selected` vs `checked` are different on purpose.** `selected` answers "which
is current?" (one, or a few) and maps to `aria-selected`. `checked` answers
"which are ticked?" (many, tri-state) and maps to `aria-checked`. A file tree
legitimately shows both at once: a current file *and* a set of ticked files.
ARIA APG treats them as separate states for exactly this reason.

> **But the storage is asymmetric, and it will bite you.** `selected` is
> component state you can bind with `v-model:selected`. `checked` is **not** —
> it lives on the node itself, so a ticked node means mutating your own
> `nodes` in place. A `check` payload arrives with `checked` already computed
> (and it reads the live input, so a partially-checked node completes rather
> than clearing). Walking a tree to write one node back, and deriving a
> branch's own tri-state from its children, is yours to write:
>
> ```js
> onCheck(node) {
>   this.updateNode(node.key, { checked: node.checked });
>   this.syncAncestors();   // all children on -> true, some -> 'indeterminate'
> }
> ```

**Keyboard.** `role="tree"` is a **single** tab stop, not one per row. Roving
tabindex puts `tabindex="0"` on the selected row (or the first) and `-1` on the
rest, so a 40-node tree is one stop rather than forty.

| Key | Behaviour |
|---|---|
| `↑` / `↓` | Move between visible rows (collapsed branches' children are skipped) |
| `→` | Open a collapsed branch; step into the first child of an open one |
| `←` | Close an open branch; from a leaf, jump to the branch that owns it |
| `Home` / `End` | First / last visible row |
| `Enter` / `Space` | Select the focused row |

Accessibility notes: leaves carry **no** `aria-expanded` (it is invalid on a node
with no children); the leaf spacer is a non-focusable `<span>` rather than an
`aria-hidden` `<button>`, which would be both a dead tab stop and an
`aria-hidden-focus` violation; every checkbox has an `aria-label` derived from
the node's label; and state is expressed as Bootstrap utility classes
(`fw-semibold text-primary` for a selected label, `opacity-50` for a disabled
one) because the kit ships no component stylesheet.

### `<ph-accordion>`

Collapsible panels. Works unwired, or controlled with `v-model` (see §1.4).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `items` | `Array` | `[]` | Panels — see the item shape below |
| `modelValue` | `Array` | `[]` | Keys of the open panels. **An array** — a wrong type degrades to "nothing open" rather than throwing |
| `multiple` | `Boolean` | `false` | Allow more than one panel open. When false, opening one closes the others |
| `flush` | `Boolean` | `false` | Borderless, full-bleed (Bootstrap `accordion-flush`) |

Emits: `update:modelValue(keys)`. Slots: `item-<key>` — scoped
`{ item, index, open }`, falling back to `item.content`.

**Item shape** — every field except `label` is optional:

| Field | Type | Notes |
|---|---|---|
| `key` / `id` | `String \| Number` | Identity. It is what `modelValue` holds; falls back to the array index |
| `label` | `String` | Toggle button text |
| `content` | `String` | Body text, used when the `item-<key>` slot is absent |
| `icon` | `String` | Bootstrap Icons name in front of the label |
| `active` | `Boolean` | Opens the panel on first render. Read **once** — later changes are ignored; drive state with `v-model` |

```
<!-- Unwired: opening and closing is internal -->
<ph-accordion :items="items" />

<!-- Controlled. v-model is an ARRAY of open keys, not a single index -->
<ph-accordion v-model="open" :items="items" />

<!-- Several panels at once, borderless -->
<ph-accordion v-model="multiOpen" :items="items" multiple flush />
```

> **This is Vue-controlled, not Bootstrap's collapse plugin.** It used to set
> `data-bs-toggle="collapse"` and delegate the open/close animation to
> Bootstrap's JS. That had three defects: `data-bs-parent` was handed `$el` — a
> DOM *element*, which Vue stringified to `"[object HTMLDivElement]"` and
> Bootstrap passed to `querySelector`, throwing
> `Document.querySelector: '[object HTMLDivElement]' is not a valid selector`;
> the plugin and the vdom both wrote the `show` class on the same element, so the
> DOM and the model desynchronised; and `active` was tested as
> `item.active !== false`, which is `true` for the `undefined` a plain item has,
> so every panel rendered open. It is impossible to fix the first by passing an
> id instead, because the second problem remains. The height animation is
> preserved through `<Transition>` hooks driving Bootstrap's own `collapsing`
> class.

Panel and heading ids are prefixed per instance (via `useId()`, so they survive
hydration) — two accordions on one page can no longer emit duplicate ids and
break each other's `aria-controls`.

> **`modelValue` must be an array.** `v-model="0"` used to throw
> `value.slice is not a function` inside the watcher and take the whole SSR
> render down with it. A wrong type now degrades quietly instead, but the array
> is still the contract.

---

## 4. Feedback

Every component here is stateless-by-default with the exceptions called out per
component. Where a component owns state it says so in **bold**, because that is
what decides whether you must wire something up.

### `<ph-alert>`

Inline alert with an optional title. The default slot and the `message` prop are
interchangeable — **the slot wins**.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `title` | `String` | `''` | Rendered as `<h4 class="alert-heading">` above the body |
| `message` | `String` | `''` | Body text, when the default slot is absent |
| `variant` | `String` | `'info'` | `info` \| `success` \| `warning` \| `error` → `alert-info` / `-success` / `-warning` / `alert-danger` |
| `dismissible` | `Boolean` | `false` | Renders the `btn-close` and adds `alert-dismissible` |

Emits: `dismiss` — fired **after** the alert has already hidden itself.

Slots: `default` (body).

```
<ph-alert variant="success" title="Deployed" message="v2.4.0 is now live." />
<ph-alert variant="error" title="Build failed">2 lint errors.</ph-alert>
```

> **Visibility is internal, and there is no way back.** `dismiss` sets the
> component's own `visible` to `false`; nothing can set it to `true` again. To
> re-show an alert, **re-mount it** — `v-if` is enough, and no `:key` bump is
> needed (going `false` unmounts, so `true` mounts a fresh instance).
>
> **There is no `v-model`.** No `modelValue` prop and no `update:modelValue` emit,
> so `v-model` silently does nothing *and* leaks a stray `modelvalue` attribute
> into the DOM. Use `v-if` + `@dismiss`.

### `<ph-modal>`

Dialog teleported to `body`, rendered while `modelValue` is true. Closes via the
✕, the backdrop (when `closeOnBackdrop`), or a parent flipping the model.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `Boolean` | `false` | Visibility — wire with `v-model` |
| `title` | `String` | `''` | Rendered as `<h3 class="modal-title">` unless the `header` slot is used |
| `size` | `String` | `'md'` | `sm` \| `md` \| `lg` \| `xl` \| `full` → `modal-sm` / default / `-lg` / `-xl` / `modal-fullscreen` |
| `closable` | `Boolean` | `true` | Shows the ✕. `false` also disables backdrop closing |
| `closeOnBackdrop` | `Boolean` | `true` | Backdrop click closes. Only honoured while `closable` is also `true` |

Emits: `update:modelValue(false)`, `close`.

Slots: `default` (body), `header` (replaces the `<h3>`; the ✕ stays), `footer`
(**rendered only if the slot is provided** — omit it and there is no footer row).

```
<ph-button @click="open = true">Open</ph-button>

<ph-modal v-model="open" title="Confirm" size="lg" @close="onClose">
  Are you sure you want to continue?

  <template #footer>
    <ph-button variant="ghost" @click="open = false">Cancel</ph-button>
    <ph-button variant="primary" @click="save">Confirm</ph-button>
  </template>
</ph-modal>
```

> It reuses Bootstrap's real `.modal` markup deliberately. A hand-rolled overlay
> would leave the `--bs-modal-*` variables undefined and the dialog
> unstyled — `.modal-content`, its header and its padding all come from them.

### `<ph-drawer>`

Off-canvas panel, teleported to `body`. The same shape as `<ph-modal>` anchored
to an edge.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `Boolean` | `false` | Visibility — wire with `v-model` |
| `position` | `String` | `'end'` | `start` \| `end` \| `top` \| `bottom` — the edge it is anchored to |
| `size` | `String \| Number` | `null` | The extent along that edge, as a **raw CSS length** (`'420px'`) — *not* a token |
| `title` | `String` | `''` | Accessible name (`aria-label`) and the default header text |
| `closable` | `Boolean` | `true` | Shows the ✕ and enables the state-changing close actions |
| `closeOnBackdrop` | `Boolean` | `true` | Backdrop click closes |
| `scrollable` | `Boolean` | `true` | The body scrolls independently, so the header stays put |

Emits: `update:modelValue(false)`, `open`, `close`.

Slots: `default` (body), `header`, `footer`.

```
<ph-drawer v-model="open" position="end" title="Details" scrollable>
  The body scrolls on its own.
  <template #footer>
    <ph-button variant="primary" @click="save">Save</ph-button>
  </template>
</ph-drawer>

<ph-drawer v-model="open" position="start" size="420px" />
<ph-drawer v-model="open" :closable="false" :close-on-backdrop="false" />
```

> **`size` is a length, not a token.** `sm`/`md`/`lg` mean nothing here the way
> they do on `<ph-button>`; pass a CSS length or leave it `null`.

### `<ph-confirm-dialog>`

Promise-based confirmation dialog. It takes over `window.confirm`, so a call site
reads as ordinary control flow and the caller can `await` the answer. Focus is
trapped, starts on **Cancel**, and is restored to the trigger on close.

| Prop | Type | Default | Notes |
|---|---|---|---|
| *(none)* | — | — | Fully driven by `window.confirm(options)` and the emitted events |

**Options for `window.confirm(options)`** — the payload is a `Promise<boolean>`:

| Option | Type | Default | Notes |
|---|---|---|---|
| `title` | `String` | `'Are you sure?'` | Header text |
| `message` | `String` | `''` | Body text |
| `confirmText` | `String` | `'Confirm'` | Label of the confirm button |
| `cancelText` | `String` | `'Cancel'` | Label of the cancel button, and where focus starts |
| `variant` | `String` | `'primary'` | `primary` \| `danger`. `danger` also tints the icon |
| `icon` | `String` | `''` | Bootstrap Icons name in the header |
| `loading` | `Boolean` | `false` | Busy state: disables both buttons and blocks closing. Usually driven by `setLoading()` |
| `closeOnBackdrop` | `Boolean` | **`false`** | Backdrop click confirms. Off by default — a stray click must not answer a destructive prompt |

Methods on `window.confirm`: `confirm(options)` and `setLoading(bool)`.

Emits: `confirm`, `cancel`.

```
<!-- Once per page, high in the tree. -->
<ph-confirm-dialog />

<script setup>
async function remove(id) {
  const ok = await window.confirm({
    title: 'Delete this project?',
    message: 'It also deletes its deployments. This cannot be undone.',
    confirmText: 'Delete',
    cancelText: 'Keep it',
    variant: 'danger',
    icon: 'exclamation-triangle',
  });
  if (!ok) return;
  await api.delete(id);
}

// Hold it open while the server works, so it cannot be dismissed mid-flight.
window.confirm.setLoading(true);
try { await api.save(); } finally { window.confirm.setLoading(false); }
</script>
```

> **One per page.** It registers on `window` in `mounted` and clears that in
> `beforeUnmount`, so a second instance takes the global over and then nulls it
> when it unmounts — leaving the host with a dead `window.confirm`. Mount it once,
> high in the tree, and leave it there.
>
> **`window.confirm` is a bound function, not the instance.** It is exposed as
> `this.confirm.bind(this)` so the receiver is the component rather than `window`;
> it stays directly callable, and `window.confirm.setLoading` is bound the same
> way. Exposing the raw instance would break every `this.*` inside it.

### `<ph-toast>`

Programmatic notification stack, teleported to `body` under a fixed region.
Mounts onto `window.toast`, so you push from anywhere without holding a ref.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `position` | `String` | `'top-right'` | `top-right` \| `top-left` \| `top-center` \| `bottom-right` \| `bottom-left` \| `bottom-center` |
| `duration` | `Number` | `5000` | Auto-dismiss in ms. A per-toast `duration` wins |
| `max` | `Number` | `5` | Excess toasts are dropped from the front |
| `dismissible` | `Boolean` | `true` | Shows the `btn-close` per toast |

Emits: `dismiss(id)`.

**Methods on `window.toast`:**

| Method | Signature | Notes |
|---|---|---|
| `push` | `push(source)` | `source` is a string, or `{ message, variant, title?, duration? }`. **Returns the toast id** |
| `remove` | `remove(id)` | Manual dismiss, with a fade-out |
| `clear` | `clear()` | Drops the whole stack |

`variant`: `info` \| `success` \| `warning` \| `error`. Hovering a toast pauses
its countdown, so a long message stays readable.

```
<!-- Once per page, high in the tree. -->
<ph-toast position="top-right" :duration="5000" :max="5" />

<script setup>
window.toast.push('Saved.');
window.toast.push({ variant: 'success', title: 'Saved', message: 'Changes persisted.' });
window.toast.push({ variant: 'error', title: 'Failed', message: 'Try again.', duration: 8000 });

const id = window.toast.push('Working…');
window.toast.remove(id);
window.toast.clear();
</script>
```

> **One per page**, for the same reason as `<ph-confirm-dialog>`: `window.toast` is
> set in `mounted` and nulled in `beforeUnmount`.
>
> The exposed methods are **bound** to the instance, not the raw instance —
> `window.toast.push(...)` is a method call *on `window`*, so an unbound `this`
> would make every `this.duration` / `this.toasts` / `this.startTimer` undefined.

### `<ph-progress-bar>`

Progress meter. `value` is **clamped to 0–100**, so an over- or under-shot number
renders a full or empty bar rather than an overflowing one.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `value` | `Number` | `0` | Percentage, clamped to 0–100 |
| `label` | `String` | `''` | Left of the value row. The row renders only with a label or `showValue` |
| `variant` | `String` | `'primary'` | `primary` \| `secondary` \| `success` \| `warning` \| `error` (`error` → `bg-danger`) |
| `size` | `String` | `'md'` | `sm` \| `md` \| `lg` |
| `showValue` | `Boolean` | `false` | Renders the clamped number on the right |
| `animated` | `Boolean` | `false` | `progress-bar-striped progress-bar-animated` |

No emits.

```
<ph-progress-bar label="Syncing" :value="sync" variant="success" show-value animated />
<ph-progress-bar :size="sm" :value="65" />

<ph-progress-bar :value="140" />   <!-- renders 100% -->
<ph-progress-bar :value="-5" />    <!-- renders 0% -->
```

> **`size` drives `--bs-progress-height`, not a class.** The vendored Bootstrap
> build ships **no** `.progress-sm` / `.progress-lg`, so those classes match
> nothing and every size renders identically. The component sets the variable
> (`0.5rem` / `1rem` / `1.5rem`) and keeps the class for builds that define it.

### `<ph-skeleton>`

Loading placeholder. Inert markup, no emits.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `variant` | `String` | `'text'` | `text` \| `circular` \| `rectangular` → `rounded` / `rounded-circle` / `rounded-0` |
| `width` | `String \| Number` | `'100%'` | A **number** is px; a string is passed through as a CSS value |
| `height` | `String \| Number` | `null` | Same rules. `null` derives it: text → `1em`, circular → the width, rectangular → `100%` |
| `animated` | `Boolean` | `true` | `placeholder-glow` + `placeholder-wave` |

```
<ph-skeleton width="100%" />
<ph-skeleton width="75%" />
<ph-skeleton :width="240" />          <!-- :number -> px -->

<ph-skeleton variant="circular" :width="48" />
<ph-skeleton variant="rectangular" :width="200" :height="120" />
<ph-skeleton width="100%" :animated="false" />
```

> **Bind the number: `:width="48"`, not `width="48"`.** The px conversion is
> `typeof === 'number'`, and a bare attribute is a **string** — so
> `width="48"` produces `width: 48`, a unitless value the browser discards. The
> placeholder then has no size at all: a `circular` skeleton collapses to nothing.
>
> The size is applied to the **root** element, which is a `d-inline-block` box.
> With it on the inner span instead, a `width="100%"` inside a flex row resolved
> against a shrink-to-fit parent — i.e. against a width computed from the very
> child being sized — and collapsed to zero. It also means a `class` on
> `<ph-skeleton>` lands on a real box, so `mt-2` and friends actually work.

### `<ph-spinner>`

Busy indicator. `role="status"` with the label in a `visually-hidden` span, so it
announces itself rather than being a silent spinner. No emits.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `size` | `String \| Number` | `'md'` | `sm` \| `md` \| `lg`, **or a number of px**. `sm` maps to `*-sm`; the rest are inline width/height |
| `variant` | `String` | `'border'` | `border` \| `grow` |
| `color` | `String` | `''` | `primary` \| `secondary` \| `success` \| `warning` \| `danger` \| `error` \| `info` \| `light` \| `dark`. `error` aliases `danger` |
| `label` | `String` | `'Loading…'` | Visually hidden text. Pass `''` to announce nothing |

```
<ph-spinner />
<ph-spinner variant="grow" />
<ph-spinner size="sm" />
<ph-spinner :size="64" />
<ph-spinner color="success" />
<ph-spinner label="Loading projects" />
```

> For a button, prefer `<ph-button :loading>` over swapping a spinner in.

### `<ph-popover>`

Rich overlay anchored to a trigger, teleported to `body` and positioned from the
trigger's own `getBoundingClientRect()`, so a scrolling parent cannot clip it.
`Esc` closes it, and so does a click on the backdrop.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `Boolean` | `null` | Open state. **`null` (unbound) = the popover keeps its own**; bind it to own the flag |
| `title` | `String` | `''` | Header text. Omit for a body-only popover |
| `content` | `String` | `''` | Body text. The `content` slot wins over it |
| `triggerLabel` | `String` | `'Popover'` | Label of the default trigger button. Ignored when the `trigger` slot is used |
| `placement` | `String` | `'top'` | `top`, `bottom`, `start`, `end`, plus `top-start`, `top-end`, `bottom-start`, `bottom-end`, `start-top`, `start-bottom`, `end-top`, `end-bottom` |
| `trigger` | `String` | `'click'` | `click` \| `hover` \| `focus` |
| `variant` | `String` | `''` | Border tint: `primary` \| `secondary` \| `success` \| `danger` \| `warning` \| `info`. Empty means no tint |
| `disabled` | `Boolean` | `false` | Short-circuits every trigger — the popover never opens |

Emits: `show`, `hide`, `update:modelValue(open)`.
Slots: `trigger` (replaces the default button; the slot content is wrapped in a
focusable element), `content`.

```
<ph-popover title="Click me" content="Opened by a click." trigger="click" />

<ph-popover title="Help" content="…">
  <template #trigger>
    <ph-button variant="primary" size="sm">Help</ph-button>
  </template>
</ph-popover>

<ph-popover placement="bottom-start" trigger-label="Anchored" content="…" />
<ph-popover disabled trigger-label="Disabled" content="…" />
```

> **Bind `modelValue` to keep several mutually exclusive.** Unbound, each popover
> keeps its own state and they will happily stack up. One key does it:
>
> ```html
> <ph-popover v-for="p in items" :key="p.id"
>             :model-value="openKey === p.id"
>             :title="p.title" :content="p.body"
>             @update:model-value="openKey = $event ? p.id : null" />
> ```
>
> **The anchor is measured before the open, not in `$nextTick`.** Measuring after
> the flag flips meant the *first* render ran with no rect at all, so
> `popoverStyle` was `{}` — no `position: fixed`, no coordinates — and the
> popover painted wherever the body happened to drop it.
>
> **There is no arrow.** Bootstrap only *positions* `.popover-arrow`; it relies on
> **Popper** to write `position: absolute` and the transform inline. This
> component has no Popper, so an arrow would sit in normal flow as a `1rem ×
> .5rem` block at the top of the box and push the header down. Positioning uses
> `clientWidth`/`clientHeight` rather than `100vw`/`100vh`, which include the
> scrollbar, and the anchor point is clamped so a popover can never hang off the
> edge.
>
> `hover` and `focus` are registered with **bound** listeners. `addEventListener`
> calls a method with `this` = the DOM element, so an unbound handler wrote
> `this.open` onto the element instead of the component and the popover never
> opened.

### `<ph-tooltip>`

CSS-positioned hint — no Popper, nothing to measure. The default slot is the
trigger; `hover` is the default trigger, with a short delay so the tip does not
flash as the cursor passes.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `content` | `String` | `''` | Tip text. The `content` slot wins over it |
| `placement` | `String` | `'top'` | `top` \| `bottom` \| `start` \| `end` |
| `trigger` | `String` | `'hover'` | `hover` \| `focus` \| `click` |
| `delay` | `Number` | `120` | Show delay in ms. `0` shows it **synchronously**, with no timer |
| `variant` | `String` | `'dark'` | `dark` \| `light` |
| `maxWidth` | `String` | `'260px'` | Any CSS length |
| `disabled` | `Boolean` | `false` | Suppresses the tip entirely |

No emits. Slots: `default` (the trigger — any element, not just a button),
`content` (the tip body).

```
<ph-tooltip content="Explain the thing.">
  <ph-button variant="outline-secondary" size="sm">Hover me</ph-button>
</ph-tooltip>

<ph-tooltip placement="end" content="Anchored to the end.">
  <span class="badge bg-secondary">a badge</span>
</ph-tooltip>

<ph-tooltip trigger="click" content="Click toggles it.">…</ph-tooltip>
<ph-tooltip :delay="0" content="No timer at all.">…</ph-tooltip>
<ph-tooltip variant="light" max-width="360px" content="A wider tip.">…</ph-tooltip>
```

> The tip is linked with `aria-describedby` **only while visible**, so assistive
> tech reads the trigger's own label until there is a description to read.

---

## 5. Navigation

### `<ph-breadcrumb>`

Crumbs; the last item is `active` with `aria-current="page"`. Only non-active
items with an `href` render as links.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `items` | `Array` | **required** | `{ label }` (required) + optional `href` |

No emits.

```
<ph-breadcrumb :items="[
  { label: 'Home', href: '/' },
  { label: 'Docs', href: '/docs' },
  { label: 'UI Components' },
]" />
```

### `<ph-link>`

Switches between native `<a>` and `router-link` based on `href` type:

- string starting with `http`, `mailto:`, or `#` → native anchor;
- any other string or an object → `router-link` bound through `to`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `href` | `String \| Object` | `'#'` | Drives element + `href`/`to` |
| `label` | `String` | `''` | Default slot wins |
| `variant` | `String` | `'primary'` | `primary` \| `secondary` \| `neutral` \| `danger` |
| `target` | `String` | `'_self'` | |
| `rel` | `String` | `null` | Auto `noopener noreferrer` when `target="_blank"` |
| `underline` | `Boolean` | `true` | `false` → `link-underline-opacity-0` |
| `disabled` | `Boolean` | `false` | `preventDefault` on click, dimmed, `aria-disabled` |

Emits: `click` (suppressed when disabled).

```
<ph-link href="mailto:hi@example.com" variant="danger" underline>Contact</ph-link>
<ph-link :href="{ name: 'product', params: { id: 7 } }" target="_blank">Buy</ph-link>
```

### `<ph-pagination>`

Ellipsised pager (`…`) when `totalPages > maxVisible`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `currentPage` | `Number` | `1` | |
| `totalPages` | `Number` | **required** | |
| `maxVisible` | `Number` | `5` | Window of page buttons around current |

Emits: `update:currentPage`, `page-change` (both with the new page).

```
<ph-pagination v-model:current-page="page" :total-pages="lastPage" />
```

### `<ph-tabs>`

Tab headers + a scoped-slot panel where you render content for the active tab.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `String \| Number` | **required** | Active tab `value` |
| `items` | `Array` | **required** | `{ label, value, disabled? }` |

Emits: `update:modelValue`, `change` (both with the tab value).

Slots: default — scoped `{ activeTab }`, rendered inside `.tab-content`.

```
<ph-tabs v-model="active" :items="items">
  <template #default="{ activeTab }">
    <p v-if="activeTab === 'overview'">Overview…</p>
    <p v-else-if="activeTab === 'settings'">Settings…</p>
  </template>
</ph-tabs>
```

### `<ph-theme>`

Interactive dark/light theme switcher toggle button. Automatically synchronizes with Bootstrap 5 `data-bs-theme` attribute on `<html>` and persists selection to `localStorage`. Designed for zero-hydration mismatch in SSR environments.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `storageKey` | `String` | `null` | LocalStorage key name (defaults to `'phue-theme'`) |
| `ariaLabel` | `String` | `null` | Accessible button label override |
| `themeConfig` | `Object` | `{}` | Optional config fallback dictionary |

Emits: None (handles internal DOM theme mutation and storage).

**Hydration Contract:**
Initializes with `'light'` state identically on server and initial client render, deferring the active DOM/OS reading to `mounted()` to prevent SSR hydration mismatches.

```html
<ph-theme aria-label="Toggle theme" storage-key="my-app-theme" />
```

### `<ph-stepper>`

Step wizard with horizontal/vertical layout, linear/non-linear, clickable steps.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `steps` | `Array` | `[]` | `{label, description?, disabled?, key?}` |
| `modelValue` | `Number` | `0` | Active step index |
| `orientation` | `String` | `'horizontal'` | `horizontal` \| `vertical` |
| `clickable` | `Boolean` | `false` | Click steps to navigate |
| `linear` | `Boolean` | `true` | Enforce order |

Emits: `update:modelValue`, `change`. Slot: default (scoped `{activeStep, steps, goToStep}`).

```
<ph-stepper v-model="step" :steps="[{label:'Step 1'},{label:'Step 2'}]" orientation="vertical" />
```

### `<ph-dropdown>`

Action menu with self-managed open state (no Bootstrap JS/Popper).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `label` | `String` | `'Menu'` | Default trigger label |
| `items` | `Array` | `[]` | `{label,href,icon,disabled,divider,header}` |
| `variant` | `String` | `'secondary'` | Trigger button variant |
| `size` | `String` | `'md'` | `sm` \| `md` \| `lg` |
| `align` | `String` | `'start'` | `start` \| `end` |
| `closeOnSelect` | `Boolean` | `true` | |
| `disabled` | `Boolean` | `false` | |

Emits: `select(item)`, `open`, `close`. Slots: `trigger` (scoped `{ open, toggle }`),
default (menu content).

```
<ph-dropdown label="Actions" variant="outline-primary" size="sm" align="end"
             :items="[
               { label: 'Edit',   icon: 'pencil' },
               { label: 'Archive', icon: 'archive' },
               { divider: true },
               { label: 'Delete', icon: 'trash', variant: 'danger' },
               { label: 'Purge',  disabled: true }
             ]"
             @select="onAction">
  <template #trigger="{ toggle }">
    <ph-button variant="link" @click="toggle">
      <ph-icon name="three-dots" /> More
    </ph-button>
  </template>
</ph-dropdown>
```

`{header: '…'}` renders a non-interactive group label and `{disabled: true}`
blocks selection, so no item needs its own markup. With `closeOnSelect` (the
default) the menu collapses after every emit.

---

## 6. Content & Layout

### `<ph-text>`

Typography via props instead of utility classes. Renders a dynamic element
(`:is="as"`, default `p`).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `as` | `String` | `'p'` | Any tag name |
| `size` | `String` | `'md'` | `xs` \| `sm` \| `md` \| `lg` \| `xl` |
| `weight` | `String` | `'normal'` | `light` \| `normal` \| `medium` \| `semibold` \| `bold` |
| `color` | `String` | `'default'` | `default` \| `muted` \| `main` \| `primary` \| `secondary` \| `danger` \| `inverse` |
| `align` | `String` | `'left'` | `left` \| `center` \| `right` \| `justify` |
| `truncate` | `Boolean` | `false` | `text-truncate` |

No emits.

```
<ph-text size="xl" weight="bold" color="main">Hero line</ph-text>
```

### `<ph-heading>`

Semantic `h1`–`h6` through the `level` prop.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `level` | `String \| Number` | `1` | `1`–`6` |
| `color` | `String` | `'default'` | `default` \| `muted` \| `primary` \| `secondary` \| `danger` |
| `align` | `String` | `'left'` | `left` \| `center` \| `right` \| `justify` |

No emits.

```
<ph-heading :level="2" color="primary">Section title</ph-heading>
```

### `<ph-box>`

Themed spacing/radius/border/background div.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `padding` | `String \| Number` | `'md'` | Scale token, px, or raw CSS |
| `margin` | `String \| Number` | `'0'` | Same; `'0'` stays unset |
| `border` | `Boolean` | `false` | |
| `radius` | `String` | `'md'` | `none` \| `sm` \| `md` \| `lg` \| `full` |
| `bg` | `String` | `'surface'` | Named: `primary` \| `secondary` \| `success` \| `danger` \| `warning` \| `light` \| `dark` \| `white` \| `transparent`; any other string → inline `background-color` (e.g. `'surface'`, `'info'`) |

No emits.

```
<ph-box padding="lg" radius="lg" border bg="surface">
  Surfaced, rounded box.
</ph-box>
```

### `<ph-stack>`

Vertical flex flow.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `gap` | `String \| Number` | `'md'` | Scale / px / raw CSS |
| `align` | `String` | `'stretch'` | `stretch` \| `flex-start` \| `center` \| `flex-end` \| `baseline` |

No emits.

```
<ph-stack gap="lg" align="center">
  <div>A</div><div>B</div>
</ph-stack>
```

### `<ph-flex>`

Full flex container via props.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `direction` | `String` | `'row'` | `row` \| `row-reverse` \| `column` \| `column-reverse` |
| `wrap` | `String` | `'nowrap'` | `nowrap` \| `wrap` \| `wrap-reverse` |
| `align` | `String` | `'stretch'` | `stretch` \| `flex-start` \| `center` \| `flex-end` \| `baseline` |
| `justify` | `String` | `'flex-start'` | `flex-start` \| `center` \| `flex-end` \| `space-between` \| `space-around` \| `space-evenly` |
| `gap` | `String \| Number` | `'0'` | Scale / px / raw CSS |

No emits.

```
<ph-flex direction="row" justify="space-between" align="center" gap="lg" wrap="wrap">
  <ph-button variant="primary">Primary</ph-button>
  <ph-button variant="ghost">Ghost</ph-button>
</ph-flex>
```

### `<ph-row>`

Bootstrap 12-column grid row: the container for `<ph-col>` grid cells. Serves as
its own (new) grid system, separate from `PhFlex`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `tag` | `String` | `'div'` | Host element |
| `gutter` | `String \| Number` | `null` | `g-*` gutters (`g-0`…`g-5`, old `2`-`20`/`30`-`90` gutters also accepted) |
| `gutterX` | `String \| Number` | `null` | `gx-*` horizontal gutters |
| `gutterY` | `String \| Number` | `null` | `gy-*` vertical gutters |
| `align` | `String` | `null` | `start` \| `end` \| `center` \| `baseline` \| `stretch` → `align-items-*` |
| `justify` | `String` | `null` | `start` \| `end` \| `center` \| `between` \| `around` \| `evenly` → `justify-content-*` |
| `cols` | `String \| Number \| Object` | `null` | `row-cols-*`; per-breakpoint via object (`{ cols: 2, md: 3 }`) |

The `gutter`/`gutterX`/`gutterY` props accept Bootstrap's named classes
(`g-0`…`g-5`), raw spacing values like `'3rem'` (→ inline `gap`), or legacy
escape-grid values (`'2'`→`8px`, `'90'`→`90px`). `cols` applies `row-cols-*` so
children auto-fill the row at equal widths. Breakpoints for `cols` come from the
object keys, with `cols` as the base key (`{ cols: 2, md: 3 }` → `row-cols-2
row-cols-md-3`).

No emits.

```
<ph-row gutter="3" align="center">
  <ph-col span="4">A</ph-col>
  <ph-col span="4">B</ph-col>
  <ph-col span="4">C</ph-col>
</ph-row>
```

### `<ph-col>`

Bootstrap 12-column grid column. `span` is **cumulative** with breakpoint props —
`span="12" md="6"` renders `col-12 col-md-6`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `tag` | `String` | `'div'` | Host element |
| `span` | `String \| Number \| Boolean` | `null` | Base `col-*`; bare attr → `col`, `"auto"` → `col-auto` |
| `xs`…`xxl` | `String \| Boolean` | `null` | `sm` \| `md` \| `lg` \| `xl` \| `xxl` → `col-md-*`; bare → auto, `"auto"` → `col-md-auto` |
| `cols` | `Object` | `{}` | Per-breakpoint spans, `xs` as base key (`{ xs: 12, md: 6 }` → `col-12 col-md-6`) |
| `offset` | `String \| Number \| Object` | `null` | `offset-*`; per-breakpoint via object (`xs` base key, `0`→`offset-0`) |
| `order` | `String \| Number \| Object` | `null` | `order-*`; per-breakpoint via object, `xs` base key |

`span` + breakpoint props accumulate, so `<ph-col span="12" md="6" lg="4">` yields
`col-12 col-md-6 col-lg-4`. `cols` is the object form of the same set and can be
combined with bare `span`. `offset`/`order` accept scalars, `"auto"`, or
breakpoint objects (`offset-{md}-*`, `order-{md}-*`).

No emits.

```
<ph-row gutter="2">
  <ph-col span="12" md="6" lg="4">Full → half → third</ph-col>
  <ph-col span="12" md="6" lg="4">Full → half → third</ph-col>
  <ph-col span="12" lg="4">Full → full → third</ph-col>
</ph-row>
```

### `<ph-spacer>`

Tiny non-collapsing spacer (`flex-shrink-0`, `aria-hidden`). `horizontal` swaps
the dominant axis.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `size` | `String \| Number` | `'md'` | Scale / px / raw CSS |
| `horizontal` | `Boolean` | `false` | Width vs height |

No emits.

```
<ph-stack gap="none">
  <span>Above the gap</span>
  <ph-spacer size="lg" />
  <span>Below the gap</span>
</ph-stack>
```

### `<ph-divider>`

Horizontal or vertical divider with optional label and variant.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `orientation` | `String` | `'horizontal'` | `horizontal` \| `vertical` |
| `label` | `String` | `''` | Centered label |
| `variant` | `String` | `''` | Color variant |
| `spacing` | `String \| Number` | `'md'` | Margin around |

No emits or slots.

```
<ph-divider label="Section" variant="primary" />
<ph-divider orientation="vertical" />
```

---

## 7. Server-Side, HTML-first components

### `<ph-markdown>`

SSR markdown renderer. The `<php>` block receives either raw markdown
(`$markdown`) or a base-relative file path (`$src`), parses server-side via
`Phue\Core\Utils\MarkdownParser` (XSS-safe: content escaped before formatting,
link schemes sanitized — `javascript:`/`data:` URIs are neutralized), and
returns the rendered HTML as the static `html` payload. Consumed by
`PhuePartialRenderer` in Direct Static HTML mode — **zero client JS, no
hydration**.

| Param | Type | Default | Notes |
|---|---|---|---|
| `markdown` | `String` | `null` | Raw markdown string to parse |
| `src` | `String` | `null` | Base-relative file path (e.g. `docs/Core.md`); used when `markdown` is absent |

```php
// From a layout/page <php> block:
$guideHtml = $partialRenderer->renderPartial('PhMarkdown', ['src' => 'docs/Core.md']);
// or inline:
$inlineHtml = $partialRenderer->renderPartial('PhMarkdown', ['markdown' => '**bold** text']);
```

The parser supports headings, bold/italic, inline code, fenced code blocks,
lists, links, blockquotes, and paragraphs — see
`tests/Core/Markdown/MarkdownParserTest.php` for the full contract.

### `<ph-navigation>`

PhNavigation is the **hybrid** navigation component: a full Vue 3 client
template plus an SSR data path. It is typically injected into a layout
placeholder (`<div id="nav">`, see `App/Frontend/AppLayout.phue`) via
`PhuePartialRenderer::renderPartial()` (or `ComponentLoaderRegistry::load()`),
which seeds `window.__NAV_STATE__` and lets the client app take over on mount —
no flash-of-unstyled content. On the SSR path, attributes are escaped with
`htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`.

Props:

| Prop | Type | Default | Notes |
|---|---|---|---|
| `items` | `Array` | `[]` | `{ label, href, disabled?, group? }` |
| `actions` | `Array` | `[]` | Right-slot topbar links (horizontal mode) |
| `activeRoute` | `String` | `'/'` | Current request URI |
| `orientation` | `String` | `'horizontal'` | `horizontal` \| `sidebar` \| `vertical` |
| `themeConfig` | `Object` | `{}` | `{ position: 'actions_prefix' \| 'actions_suffix' }` controls whether `<ph-theme>` precedes or follows `actions` |

Behavior:
- **Sidebar/vertical mode** renders a `nav.nav-pills.flex-column`; **horizontal**
  renders `navbar-nav` and groups as `li.nav-item.dropdown` Bootstrap dropdowns.
- **Group headings** render as `<h6 class="phue-nav-group sidebar-heading">` in
  sidebar/vertical mode only, keyed to consecutive `group` values.
- **Active detection**: the item whose normalized href equals `activeRoute`, or
  is a prefix (`/docs` matches `/docs/guide`). The root `/` matches only
  `activeRoute === '/'`.
- **Disabled** items render as `<span class="nav-link disabled">` with
  `aria-disabled="true"`.

Seeding from PHP (layout `<php>` block):

```php
<?php
$navHtml = $partialRenderer->renderPartial('PhNavigation', [
    'items'       => $navItems,
    'activeRoute' => $uri,
    'orientation' => 'sidebar',
]);
?>
```

---

## 8. Icons, Overlays & Advanced Inputs

### `<ph-icon>`

Bootstrap Icons renderer. `name` maps to the `bi-<name>` class.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `name` | `String` | **required** | Icon name, e.g. `check`, `trash` |
| `size` | `String \| Number` | `'md'` | Token (`xs`..`2xl`) or pixels |
| `variant` | `String` | `''` | Bootstrap text-color variant |
| `color` | `String` | `''` | Raw CSS color override |
| `spin` | `Boolean` | `false` | Animated rotation |
| `label` | `String` | `''` | When set → `role="img"` + `aria-label`; otherwise `aria-hidden` |

No emits or slots.

```
<ph-icon name="check" />
<ph-icon name="gear" size="lg" variant="primary" />
<ph-icon name="arrow-repeat" spin label="Refreshing" />
<ph-icon name="chevron-down" :size="12" />
```

An icon with no `label` is `aria-hidden`, so it disappears from the accessibility
tree — correct for a decorative glyph next to real text. Pass `label` whenever the
icon is the *only* content of a control, or the control has no accessible name.

---

## 9. Component index

| Component | Tag | Kind |
|---|---|---|
| PhAccordion | `<ph-accordion>` | Data display |
| PhAlert | `<ph-alert>` | Feedback |
| PhAvatar | `<ph-avatar>` | Data display |
| PhBadge | `<ph-badge>` | Data display |
| PhBox | `<ph-box>` | Layout |
| PhBreadcrumb | `<ph-breadcrumb>` | Navigation |
| PhButton | `<ph-button>` | Action |
| PhCard | `<ph-card>` | Data display |
| PhCheckbox | `<ph-checkbox>` | Form control |
| PhChip | `<ph-chip>` | Data display |
| PhCode | `<ph-code>` | Data display |
| PhCol | `<ph-col>` | Layout |
| PhConfirmDialog | `<ph-confirm-dialog>` | Feedback — **one per page**, self-registers on `window.confirm` |
| PhDataTable | `<ph-data-table>` | Data display |
| PhDatePicker | `<ph-date-picker>` | Form control |
| PhDescriptionList | `<ph-description-list>` | Data display |
| PhDivider | `<ph-divider>` | Layout |
| PhDrawer | `<ph-drawer>` | Feedback |
| PhDropdown | `<ph-dropdown>` | Navigation |
| PhEmptyState | `<ph-empty-state>` | Data display |
| PhFileInput | `<ph-file-input>` | Form control |
| PhFlex | `<ph-flex>` | Layout |
| PhForm | `<ph-form>` | Form control |
| PhFormField | `<ph-form-field>` | Form control |
| PhHeading | `<ph-heading>` | Typography |
| PhIcon | `<ph-icon>` | Data display |
| PhInput | `<ph-input>` | Form control |
| PhInputShell | `<ph-input-shell>` | Form control (bordered shell primitive for composite controls) |
| PhLink | `<ph-link>` | Navigation |
| PhListGroup | `<ph-list-group>` | Data display |
| PhMarkdown | `<ph-markdown>` | SSR partial (server-side markdown rendering) |
| PhModal | `<ph-modal>` | Feedback |
| PhNavigation | `<ph-navigation>` | Navigation (hybrid SSR partial + client Vue) |
| PhNumberInput | `<ph-number-input>` | Form control |
| PhPagination | `<ph-pagination>` | Navigation |
| PhPopover | `<ph-popover>` | Feedback |
| PhProgressBar | `<ph-progress-bar>` | Feedback |
| PhRadio | `<ph-radio>` | Form control |
| PhRange | `<ph-range>` | Form control |
| PhRow | `<ph-row>` | Layout |
| PhSearchInput | `<ph-search-input>` | Form control |
| PhSelect | `<ph-select>` | Form control |
| PhSelect3 | `<ph-select3>` | Form control |
| PhSegmentedControl | `<ph-segmented-control>` | Form control |
| PhSkeleton | `<ph-skeleton>` | Feedback |
| PhSpinner | `<ph-spinner>` | Feedback |
| PhStack | `<ph-stack>` | Layout |
| PhStat | `<ph-stat>` | Data display |
| PhStepper | `<ph-stepper>` | Navigation |
| PhSwitch | `<ph-switch>` | Form control |
| PhTabs | `<ph-tabs>` | Navigation |
| PhText | `<ph-text>` | Typography |
| PhTextarea | `<ph-textarea>` | Form control |
| PhTheme | `<ph-theme>` | Navigation |
| PhTimeline | `<ph-timeline>` | Data display |
| PhToast | `<ph-toast>` | Feedback — **one per page**, self-registers on `window.toast` |
| PhTooltip | `<ph-tooltip>` | Feedback |
| PhTreeNode | `<ph-tree-node>` | Internal — recursion helper rendered by `<ph-tree-view>`; not for direct use |
| PhTreeView | `<ph-tree-view>` | Data display |
| Spacer | `<ph-spacer>` | Layout |

Live demonstrations of every component live on the built-in `/ui` showcase page
(`App/Frontend/Pages/UI.phue`).