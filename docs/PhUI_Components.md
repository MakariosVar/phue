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

### 1.1.1 Component Styles Metadata (`'styles'` in `<php>` block)

A `.phue` component (page or partial) can declare its own stylesheet dependencies
in the `<php>` block return array:

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

> **Key point:** Styles are **request-scoped** (lazy). Each page/component declares its own styles, they're added to the request pool during render, and cleared on the next `beginRequest()`. No global pollution, no manual cleanup needed.

> **BC note:** `$styles` was appended as the **last** `View` constructor parameter (after `$html`), so existing positional calls `new View($tpl, $state, $scripts, $inlineScript, $html)` remain 100% compatible.

> **Asset URL convention:** Use absolute paths from registered asset sources, e.g. `/assets/app/styles/main.css` (serves `App/Assets/styles/main.css` via `registerSource('/assets/app', ...)` in `public/index.php`).

### 1.2 `v-model` is wired per component

| Form | Prop updated | Trigger |
|---|---|---|
| `v-model` | `modelValue` | `update:modelValue` |
| `v-model:current-page` | `currentPage` | `update:currentPage` |

### 1.3 Shared conventions

**Spacing scale** (used by `gap`, `size`, `padding`, `margin` props):

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

**Variants.** Components that read `variant` map the neutral/error names onto
Bootstrap classes: `error` → `bg-danger`/`alert-danger`, `neutral` →
`text-bg-secondary`, and so on. Valid values are enumerated per component below.

**Slots.** Where a component has both a `label` prop and a default slot, the
slot wins when present.

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
| `type` | `String` | `'text'` | Any native input type |
| `label` | `String` | `''` | Rendered with `form-label`; `*` marker when `required` |
| `placeholder` | `String` | `''` | |
| `helperText` | `String` | `''` | Shown as `form-text` |
| `error` | `String` | `''` | Non-empty → `is-invalid` + red `form-text` |
| `disabled` | `Boolean` | `false` | |
| `readonly` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | Only visual (`*`); validation is your job |

Emits: `update:modelValue` (on `input`), `blur`, `focus`.

```
<ph-input v-model="email" label="Email" placeholder="you@example.com"
          helper-text="We never share your email." :error="emailError" />
```

### `<ph-select>`

Native select bound to `modelValue`. Options accept either objects
`{ label, value, disabled? }` or plain primitives (normalized internally).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `String \| Number` | `''` | |
| `options` | `Array` | `[]` | `{label, value, disabled?}` or primitive values |
| `id` | `String` | `null` | Falls back to `ph-select-<id>` |
| `label` | `String` | `''` | `*` marker when `required` |
| `placeholder` | `String` | `''` | Rendered as a disabled, selected option |
| `helperText` | `String` | `''` | |
| `error` | `String` | `''` | `is-invalid` + red `form-text` |
| `disabled` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | Visual only |

Emits: `update:modelValue` (on `change`).

```
<ph-select v-model="framework" label="Framework"
           :options="[
             { label: 'Vue 3', value: 'vue' },
             { label: 'React', value: 'react', disabled: true }
           ]" />
```

### `<ph-textarea>`

Multi-line textarea. Emits on `input` as you type.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `String` | `''` | |
| `id` | `String` | `null` | Falls back to `ph-textarea-<id>` |
| `rows` | `Number` | `4` | |
| `resize` | `String` | `'vertical'` | `none` \| `both` \| `horizontal` \| `vertical` |
| `label` | `String` | `''` | `*` marker when `required` |
| `placeholder` | `String` | `''` | |
| `helperText` | `String` | `''` | |
| `error` | `String` | `''` | `is-invalid` + red `form-text` |
| `disabled` | `Boolean` | `false` | |
| `readonly` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | Visual only |

Emits: `update:modelValue`, `blur`, `focus`.

```
<ph-textarea v-model="message" label="Message" :rows="5" resize="vertical"
              helper-text="Max 500 characters." />
```

### `<ph-checkbox>`

Boolean checkbox with optional indeterminate visual state.

| Prop | Type | Default | Notes |
|---|---|---|---|
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

### `<ph-switch>`

Boolean toggle (`.form-switch`). Same contract as `<ph-checkbox>`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `Boolean` | `false` | |
| `id` | `String` | `null` | Falls back to `ph-switch-<id>` |
| `label` | `String` | `''` | Or default slot |
| `disabled` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | |
| `size` | `String` | `'md'` | `sm` \| `md` \| `lg` |

Emits: `update:modelValue` (on `change`), `blur`, `focus`.

```
<ph-switch v-model="enabled" label="Enable feature" size="md" />
```

### `<ph-search-input>`

Search input with debounce, clear button, and search icon.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `String` | `''` | |
| `id` | `String` | `null` | |
| `placeholder` | `String` | `'Search…'` | |
| `debounce` | `Number` | `300` | Ms |
| `clearable` | `Boolean` | `true` | |
| `label` | `String` | `''` | |
| `helperText` | `String` | `''` | |
| `error` | `String` | `''` | |
| `disabled` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | |
| `size` | `String` | `'md'` | `sm` \| `md` \| `lg` |

Emits: `update:modelValue`, `search(query)`, `clear`, `focus`, `blur`.

```
<ph-search-input v-model="query" @search="onSearch" placeholder="Search users…" />
```

### `<ph-number-input>`

Numeric input with increment/decrement controls, min/max, step, precision.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `String \| Number` | `''` | |
| `id` | `String` | `null` | |
| `min` | `Number` | `0` | |
| `max` | `Number` | `100` | |
| `step` | `Number` | `1` | |
| `precision` | `Number` | `0` | Decimal places |
| `controls` | `Boolean` | `true` | Show ± buttons |
| `label` | `String` | `''` | |
| `placeholder` | `String` | `''` | |
| `helperText` | `String` | `''` | |
| `error` | `String` | `''` | |
| `disabled` | `Boolean` | `false` | |
| `readonly` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | |

Emits: `update:modelValue`, `blur`, `focus`.

```
<ph-number-input v-model="quantity" :min="0" :max="100" :step="5" label="Quantity" />
```

### `<ph-range>`

Range slider (single or dual) with optional value display.

| Prop | Type | Default | Notes |
|---|---|---|---|
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

### `<ph-form>`

Form wrapper with native validation, error collection, submit/reset handling.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `disabled` | `Boolean` | `false` | |
| `loading` | `Boolean` | `false` | |
| `errors` | `Object` | `{}` | External errors by field name |
| `validateOn` | `String` | `'submit'` | `submit` \| `blur` \| `change` |

Emits: `submit(data)`, `reset`, `invalid(errors)`. Slot: default (scoped `{errors, validate, reset, submit, disabled, loading}`), `actions`.

```
<ph-form @submit="onSubmit" @invalid="errors = $event">
  <template #default="{ validate, submit }">
    <ph-input name="email" label="Email" required />
    <ph-button type="submit" @click="validate()">Save</ph-button>
  </template>
</ph-form>
```

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

Emits: `update:modelValue`, `change`. No slots.

```
<ph-segmented-control v-model="view" :options="[
  { label: 'Grid', value: 'grid', icon: 'grid' },
  { label: 'List', value: 'list', icon: 'list' }
]" />
```

### `<ph-select3>`

Searchable single/multiple select with chips (replaces the limits of the native
`<ph-select>`).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `String \| Number \| Array` | `''` | Array when `multiple` |
| `options` | `Array` | `[]` | `{label,value,disabled?}` or primitives |
| `id` | `String` | `null` | Falls back to `ph-select3-<id>` |
| `label` | `String` | `''` | |
| `placeholder` | `String` | `'Select…'` | |
| `helperText` | `String` | `''` | |
| `error` | `String` | `''` | |
| `multiple` | `Boolean` | `false` | |
| `searchable` | `Boolean` | `true` | |
| `clearable` | `Boolean` | `false` | |
| `disabled` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | |

Emits: `update:modelValue`, `search(query)`, `open`, `close`, `clear`.
Slots: `footer` (scoped `{ close }`).

### `<ph-file-input>`

File picker with drag & drop, `accept`/`maxSize` validation and image previews.

| Prop | Type | Default | Notes |
|---|---|---|---|
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
| `required` | `Boolean` | `false` | |

Emits: `update:modelValue`, `change(files)`, `remove(file)`, `error(reason)`.

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
| `weekStart` | `Number` | `1` | `0` = Sunday |
| `id` | `String` | `null` | Falls back to `ph-date-picker-<id>` |
| `label` | `String` | `''` | |
| `placeholder` | `String` | `'Select date…'` | |
| `helperText` | `String` | `''` | |
| `error` | `String` | `''` | |
| `disabled` | `Boolean` | `false` | |
| `required` | `Boolean` | `false` | |
| `clearable` | `Boolean` | `true` | |

Emits: `update:modelValue`, `change`, `open`, `close`, `clear`.

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
<ph-avatar name="Makarios Varoutsos" size="lg" shape="circle" />
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
    <ph-button variant="outline" size="sm">View details</ph-button>
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

Vertical timeline with markers, time, title, body and variant colors.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `items` | `Array` | `[]` | `{time, title, body, icon?, variant?}` |
| `variant` | `String` | `''` | Global variant for line/markers |

Slots: `item-<key>` (scoped). No emits.

```
<ph-timeline variant="primary" :items="[
  { time: '10:00', title: 'Deployed', body: 'v2.4.0 to production' },
  { time: '09:30', title: 'Tests passed', body: 'All 1,234 tests green' }
]" />
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
| `theme` | `String` | `'twilight'` | Active theme (read-only; the picker writes local `activeTheme` state and emits `theme-change`) |
| `themeSwitcher` | `Boolean` | `true` | Show the theme dropdown in the header |
| `themes` | `Array` | `['coy','dark','funky','okaidia','solarizedlight','tomorrow','twilight']` | Available theme names |
| `assetBase` | `String` | `'/assets/vendor/prismjs'` | Prism asset root (trailing slash normalized) |

Emits: `copy(code)`, `theme-change(themeName)`. No slots.

The header shows the language label (human-readable alias, e.g. `PHP` not `php`), a theme picker (`<ph-dropdown>` with paint-bucket icon), and a copy button. While Prism is loading the block renders at `opacity: 0.55` so the server-rendered code stays readable without a white-flash jump. Theme switching drops the old `<link>` and injects the new one — the same DOM node is reused so A→B→A does not stack sheets. The `data-ph-highlighted="1"` attribute on `<code>` signals a successful highlight pass. Copy uses the Clipboard API with an `execCommand` fallback; failures do not stick the button in a copied state.

```
<ph-code language="php" :code="$code" copyable theme="twilight" />
<ph-code :code="$code" :themes="['coy','dark','okaidia']" @theme-change="onTheme" />
```

### `<ph-tree-view>`

Hierarchical tree with selection, expansion, checkboxes and slots.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `nodes` | `Array` | `[]` | `{label, children?, icon?, checked?, key?}` |
| `selected` | `Array` | `[]` | Selected node keys |
| `expanded` | `Array` | `[]` | Expanded node keys |
| `checkable` | `Boolean` | `false` | Show checkboxes |

Emits: `select(node)`, `toggle(node)`, `check(node)`. Slots: `node-<key>` (scoped).

```
<ph-tree-view :nodes="treeData" :selected="selected" :expanded="expanded" checkable @select="onSelect" />
```

### `<ph-accordion>`

Collapsible panels with single/multiple open, flush style, v-model array.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `items` | `Array` | `[]` | `{label, content, icon?, active?, disabled?, key?}` |
| `multiple` | `Boolean` | `false` | Allow multiple open |
| `flush` | `Boolean` | `false` | Remove borders |
| `modelValue` | `Array` | `[]` | Open panel keys/indices |

Emits: `update:modelValue`. Slots: `item-<key>` (scoped).

```
<ph-accordion v-model="openPanels" :items="[
  { label: 'Section 1', content: 'Details…', icon: 'chevron-right' }
]" @update:modelValue="openPanels = $event" />
```

---

## 4. Feedback

### `<ph-alert>`

Dismissible alert with optional title. Internal `visible` state — dismiss hides
the whole alert; re-showing is your job (e.g. a `v-if`/`v-else` with the
`dismiss` event).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `title` | `String` | `''` | Bold `alert-heading` |
| `message` | `String` | `''` | Default slot content wins |
| `variant` | `String` | `'info'` | `info` \| `success` \| `warning` \| `error` |
| `dismissible` | `Boolean` | `false` | Shows the `btn-close` |

Emits: `dismiss`.

```
<ph-alert variant="success" title="Deployed"
         dismissible @dismiss="alertVisible = false">
  v2.4.0 is now live.
</ph-alert>
```

### `<ph-modal>`

Teleported to `body`, shown when `modelValue` is truthy. Closable via the ✕,
the backdrop, or a parent flipping `modelValue`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `Boolean` | `false` | Controls visibility |
| `title` | `String` | `''` | Shown in default header |
| `size` | `String` | `'md'` | `sm` \| `md` \| `lg` \| `xl` \| `full` |
| `closable` | `Boolean` | `true` | Shows the ✕ and enables state-affecting close actions |
| `closeOnBackdrop` | `Boolean` | `true` | Backdrop click closes only when `closable` too |

Emits: `update:modelValue` (`false`), `close`.

Slots: `header` (default: `<h3>{{ title }}</h3>`), default (body), `footer`
(rendered only if provided).

```
<ph-modal v-model="open" title="Confirm" size="md" closable>
  Are you sure you want to continue?
  <template #footer>
    <ph-button variant="ghost" @click="open = false">Cancel</ph-button>
    <ph-button variant="primary" @click="open = false">Confirm</ph-button>
  </template>
</ph-modal>
```

### `<ph-progress-bar>`

Clamped to 0–100. Optional label row and live value.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `value` | `Number` | `0` | Clamped via `min(100, max(0, value))` |
| `label` | `String` | `''` | Left of the value row |
| `variant` | `String` | `'primary'` | `primary` \| `secondary` \| `success` \| `warning` \| `error` |
| `size` | `String` | `'md'` | `sm` \| `md` \| `lg` (extra small/large progress) |
| `showValue` | `Boolean` | `false` | Shows `{{ value }}%` |
| `animated` | `Boolean` | `false` | Striped + animated |

No emits.

```
<ph-progress-bar :value="syncProgress" label="Syncing"
                 variant="success" show-value animated />
```

### `<ph-skeleton>`

Loading placeholder. Width/height accept scale tokens, `%`, px numbers, or any
CSS length.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `variant` | `String` | `'text'` | `text` \| `circular` \| `rectangular` |
| `width` | `String \| Number` | `'100%'` | Number → px |
| `height` | `String \| Number` | `null` | Auto: text `1em`, circular = width, rectangular `100%` |
| `animated` | `Boolean` | `true` | `placeholder-glow` + `placeholder-wave` |

No emits.

```
<ph-skeleton variant="text" width="240" height="12" />
<ph-skeleton variant="circular" width="48" />
<ph-skeleton variant="rectangular" width="200" height="120" />
```

### `<ph-toast>`

Programmatic notification stack, teleported to `body` under a fixed container.
Auto-registers on `window.toast`. Push toasts from anywhere without holding a `ref`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `position` | `String` | `'top-right'` | `top-right` \| `top-left` \| `top-center` \| `bottom-right` \| `bottom-left` \| `bottom-center` |
| `duration` | `Number` | `5000` | Auto-dismiss ms (per-item override wins) |
| `max` | `Number` | `5` | Excess toasts are dropped from the front |
| `dismissible` | `Boolean` | `true` | Shows the `btn-close` |

Emits: `dismiss` (toast id).

Methods (on `window.toast`):

| Method | Signature | Notes |
|---|---|---|
| `push` | `push(source)` | `source` = string (message) or `{ message, variant, title?, duration? }`; returns the toast id |
| `remove` | `remove(id)` | Manual dismiss with fade-out |
| `clear` | `clear()` | Drops the whole stack |

`variant`: `info` | `success` | `warning` | `error` (colored left border via
Bootstrap CSS variables — dark-mode safe). Hovering a toast pauses its countdown.

```
<ph-toast position="top-right" @dismiss="onToastDismiss" />

<ph-button variant="primary" @click="window.toast.push({
    variant: 'success', title: 'Saved',
    message: 'Changes persisted.', duration: 3000
  })">Mention success</ph-button>
```

```
window.toast.push({ variant: 'success', title: 'Saved', message: 'Done!' });
```

### `<ph-popover>`

Popover with title, content, multiple placements, click/hover/focus triggers.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `title` | `String` | `''` | Header title |
| `content` | `String` | `''` | Body content |
| `triggerLabel` | `String` | `'Popover'` | Default trigger button label |
| `placement` | `String` | `'top'` | 12 positions |
| `trigger` | `String` | `'click'` | `click` \| `hover` \| `focus` |
| `disabled` | `Boolean` | `false` | |
| `variant` | `String` | `''` | Border color variant |

Emits: `show`, `hide`. Slots: `trigger`, `content`.

```
<ph-popover title="Info" content="Details here" placement="top" trigger="hover">
  <template #trigger><ph-button size="sm">Hover me</ph-button></template>
</ph-popover>
```

### `<ph-tooltip>`

CSS-positioned tooltip (no Popper).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `content` | `String` | `''` | Or `content` slot |
| `placement` | `String` | `'top'` | `top` \| `bottom` \| `start` \| `end` |
| `trigger` | `String` | `'hover'` | `hover` \| `focus` \| `click` |
| `delay` | `Number` | `120` | Show delay in ms |
| `disabled` | `Boolean` | `false` | |
| `variant` | `String` | `'dark'` | `dark` \| `light` |
| `maxWidth` | `String` | `'260px'` | |

No emits. Slots: default (trigger), `content`.

### `<ph-drawer>`

Off-canvas side panel (teleported, focus-trapped, `Esc`-closable).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `modelValue` | `Boolean` | `false` | |
| `position` | `String` | `'end'` | `start` \| `end` \| `top` \| `bottom` |
| `size` | `String` | `null` | CSS length (default `25rem` / `20rem`) |
| `title` | `String` | `''` | |
| `closable` | `Boolean` | `true` | |
| `closeOnBackdrop` | `Boolean` | `true` | |
| `scrollable` | `Boolean` | `true` | |

Emits: `update:modelValue`, `close`, `open`. Slots: `header`, default, `footer`.

### `<ph-confirm-dialog>`

Promise-based confirmation dialog host. Mount **once** (e.g. in a base page). Auto-registers on `window.confirm`. Call `window.confirm.confirm(options)` returning `Promise<boolean>`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `title` | `String` | `''` | Dialog title |
| `message` | `String` | `''` | Dialog body text |
| `confirmText` | `String` | `'Confirm'` | Confirm button label |
| `cancelText` | `String` | `'Cancel'` | Cancel button label |
| `variant` | `String` | `'primary'` | `'primary'` \| `'danger'` (affects confirm button + icon color) |
| `icon` | `String` | `''` | Bootstrap icon name for header |
| `loading` | `Boolean` | `false` | Disables buttons, shows spinner on confirm |
| `closeOnBackdrop` | `Boolean` | `false` | Allow backdrop click to cancel |

Emits: `confirm`, `cancel`. Methods: `confirm(options)`, `setLoading(bool)`, `close(result)`.

```
<ph-confirm-dialog />

const ok = await window.confirm.confirm({
  title: 'Delete product?',
  message: 'This cannot be undone.',
  variant: 'danger',
  icon: 'exclamation-triangle',
  confirmText: 'Delete',
});
```

### `<ph-spinner>`

Standalone loading spinner (`spinner-border` / `spinner-grow`).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `size` | `String \| Number` | `'md'` | `sm` \| `md` \| `lg` or pixels |
| `variant` | `String` | `'border'` | `border` \| `grow` |
| `color` | `String` | `''` | Bootstrap text-color variant |
| `label` | `String` | `'Loading…'` | Visually-hidden text for screen readers |

No emits or slots.

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

### `<spacer>`

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
  <spacer size="lg" />
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
| PhConfirmDialog | `<ph-confirm-dialog>` | Feedback |
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
| PhTimeline | `<ph-timeline>` | Data display |
| PhToast | `<ph-toast>` | Feedback |
| PhTooltip | `<ph-tooltip>` | Feedback |
| PhTreeView | `<ph-tree-view>` | Data display |
| Spacer | `<spacer>` | Layout |

Live demonstrations of every component live on the built-in `/ui` showcase page
(`App/Frontend/Pages/UI.phue`).