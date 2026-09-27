<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

/**
 * PhFormHarness — the browser-side scenarios for <ph-form>, as a JS source string.
 *
 * There is no Vue mount and no DOM library here on purpose. The component's
 * logic reads exactly this surface of the platform:
 *
 *   form.elements, form.reset()
 *   el.name, el.type, el.disabled, el.tagName
 *   el.checkValidity(), el.validationMessage, el.setCustomValidity()
 *   el.reportValidity(), el.focus(), el.scrollIntoView()
 *   el.getAttribute(), el.setAttribute(), el.removeAttribute()
 *   new FormData(form) with FormData#entries() and #forEach()
 *
 * Everything above is modelled below, with two deliberate properties that make
 * the tests meaningful rather than decorative:
 *
 *  1. `form.reset()` really does dispatch a `reset` event to whatever listens,
 *     the way a browser does. That is what makes the
 *     `reset()` -> `onReset()` -> `reset()` recursion observable at all.
 *  2. `setCustomValidity()` really does make `checkValidity()` fail, and
 *     clearing it makes the element valid again. That is what makes a sticky
 *     server error observable.
 *
 * Each scenario is wrapped in `scenario()`, so a regression that breaks one of
 * those assumptions is reported as a named failure instead of aborting the
 * harness with a stack trace.
 *
 * @package Phue\Tests\Core\UI
 */
final class PhFormHarness
{
    /**
     * Source evaluated before the component is imported: installs the FormData
     * double globally so the component's collect() finds it.
     */
    public static function prelude(): string
    {
        return <<<'JS'
// A FormData over the fake form, so collect() exercises the real path.
globalThis.FormData = class FormData {
  constructor(form) { this._form = form; }
  entries() { return this._form._entries(); }
  forEach(cb) { for (const [k, v] of this._form._entries()) { cb(v, k, this); } }
};
JS;
    }

    /**
     * The scenarios. Prints one JSON document on stdout and nothing else.
     */
    public static function source(): string
    {
        return <<<'JS'
// ---------------------------------------------------------------- the doubles
// The runner wrote the component, with its `vue` import stripped, next to this
// file. Only the options object is needed: the render function is never called.
const options = (await import("./component.mjs")).default;

class El {
  constructor(spec) {
    this.tagName = spec.tag || "INPUT";
    this.type = spec.type || "text";
    this.name = spec.name || "";
    this.id = spec.id || "";
    this.disabled = !!spec.disabled;
    this.required = !!spec.required;
    this.defaultValue = spec.value === undefined ? "" : String(spec.value);
    this._value = this.defaultValue;
    this._custom = "";
    this._attrs = {};
    this.form = null;
    this.focusCount = 0;
    this.scrollCount = 0;
    this.reportCount = 0;
  }
  get value() { return this._value; }
  set value(v) { this._value = v === null || v === undefined ? "" : String(v); }
  get validity() {
    const missing = this.required && this._value === "";
    const custom = this._custom !== "";
    return { valid: !missing && !custom, valueMissing: missing, customError: custom };
  }
  get validationMessage() {
    if (this._custom !== "") return this._custom;
    if (this.required && this._value === "") return "Constraints not satisfied";
    return "";
  }
  checkValidity() { return this.validity.valid; }
  setCustomValidity(msg) { this._custom = msg === undefined || msg === null ? "" : String(msg); }
  reportValidity() { this.reportCount++; return this.checkValidity(); }
  focus() { this.focusCount++; if (this.form) { this.form.focused = this; } }
  scrollIntoView() { this.scrollCount++; }
  getAttribute(k) { return Object.prototype.hasOwnProperty.call(this._attrs, k) ? this._attrs[k] : null; }
  setAttribute(k, v) { this._attrs[k] = String(v); }
  removeAttribute(k) { delete this._attrs[k]; }
  hasAttribute(k) { return Object.prototype.hasOwnProperty.call(this._attrs, k); }
  // The form submission algorithm skips unnamed, disabled and submittable
  // controls, so the double does the same or collect() would see a "" key.
  get entries() {
    const submittable = this.type !== "submit" && this.type !== "reset";
    return this.name && !this.disabled && submittable && this.checkValidity()
      ? [[this.name, this._value]]
      : [];
  }
  reset() { this._value = this.defaultValue; }
}

class FakeForm {
  constructor(controls) {
    this._controls = controls;
    this.resetCalls = 0;
    this.focused = null;
    this._listeners = { reset: [] };
    controls.forEach((c) => { c.form = this; });
  }
  get elements() { return this._controls; }
  // The browser fires `reset` from reset(); modelling that dispatch is the whole
  // point of the recursion regression.
  reset() {
    this.resetCalls++;
    for (const fn of this._listeners.reset.slice()) {
      fn({ type: "reset", defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } });
    }
    for (const c of this._controls) c.reset();
  }
  addEventListener(type, fn) { (this._listeners[type] = this._listeners[type] || []).push(fn); }
  dispatch(type) { for (const fn of (this._listeners[type] || []).slice()) fn({ type: type, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } }); }
  _entries() {
    const out = [];
    for (const c of this._controls) for (const e of c.entries) out.push(e);
    return out;
  }
}

// ------------------------------------------------------- component invocation

const out = [];
const t = (name, pass, extra) => out.push({ name: name, pass: !!pass, extra: extra === undefined ? null : String(extra) });

/**
 * Run a scenario, turning a thrown error into a recorded failure. Without this
 * a regression that breaks an assumption would abort the harness and surface as
 * a setup error instead of a named, readable failure.
 */
async function scenario(name, body) {
  try {
    await body();
  } catch (err) {
    out.push({ name: "scenario '" + name + "' threw", pass: false, extra: (err && err.message) || String(err) });
  }
}

function makeForm(props, controls) {
  const emitted = { submit: [], invalid: [], reset: 0, "update:errors": [] };
  const form = new FakeForm(controls);
  const vm = {
    nativeErrors: options.data().nativeErrors,
    customValidity: options.data().customValidity,
    lockedFields: [],
    name: props.name || "",
    disabled: !!props.disabled,
    loading: !!props.loading,
    errors: props.errors || {},
    validateOn: props.validateOn || "submit",
    nativeValidation: !!props.nativeValidation,
    showSummary: props.showSummary === undefined ? true : !!props.showSummary,
    summaryTitle: props.summaryTitle || "Please fix the following:",
    $emit(name, payload) {
      if (name === "reset") { emitted.reset++; return; }
      if (Array.isArray(emitted[name])) { emitted[name].push(payload); return; }
      emitted[name] = payload;
    },
    $nextTick(fn) { if (fn) fn(); return Promise.resolve(); },
    $el: form
  };
  for (const key of Object.keys(options.computed)) {
    Object.defineProperty(vm, key, { get: () => options.computed[key].call(vm) });
  }
  for (const [name, fn] of Object.entries(options.methods)) {
    vm[name] = fn.bind(vm);
  }
  vm.emitted = emitted;
  vm.form = form;
  // The template wires onReset to the form's reset event; do the same here.
  form.addEventListener("reset", (ev) => options.methods.onReset.call(vm, ev));
  options.mounted.call(vm);
  return vm;
}

const txt = (name, value, extra) => new El(Object.assign({ tag: "INPUT", type: "text", name: name, value: value }, extra || {}));

// ============================================================ control discovery
await scenario("control discovery", async () => {
  const vm = makeForm({}, [txt("title", "", { required: true })]);
  t("enumerates the form's own controls", vm.fields().length === 1, vm.fields().length);
  t("skips hidden and unnamed controls", vm.fields().every((e) => e.type !== "hidden" && e.name !== ""));
  t("serverErrors normalizes an empty prop", JSON.stringify(vm.serverErrors) === "{}", JSON.stringify(vm.serverErrors));
  t("errorList is empty while clean", vm.errorList.length === 0, JSON.stringify(vm.errorList));
});

// ============================================================ native validation blocks submit
await scenario("native validation", async () => {
  const vm = makeForm({}, [txt("title", "", { required: true })]);
  const f = vm.fields()[0];
  t("empty required field is invalid", f.checkValidity() === false);
  t("submit() returns null when invalid", vm.submit() === null);
  t("submit NOT emitted on invalid", vm.emitted.submit.length === 0, vm.emitted.submit.length);
  t("invalid emitted once", vm.emitted.invalid.length === 1, JSON.stringify(vm.emitted.invalid));
  t("invalid keyed by field name", "title" in vm.emitted.invalid[0]);
  t("aria-invalid set on the field", f.getAttribute("aria-invalid") === "true");
  t("update:errors fired", vm.emitted["update:errors"].length === 1);
  t("errorList surfaces the field", vm.errorList.map((i) => i.field).join(",") === "title", JSON.stringify(vm.errorList));
});

// ============================================================ valid form submits
await scenario("valid submit", async () => {
  const vm = makeForm({}, [txt("title", "Hello"), txt("summary", "Body")]);
  const data = vm.submit();
  t("submit emitted when valid", vm.emitted.submit.length === 1, vm.emitted.submit.length);
  t("payload has title", data.title === "Hello", JSON.stringify(data));
  t("payload has summary", data.summary === "Body");
  t("invalid NOT emitted on success", vm.emitted.invalid.length === 0);
  t("errors map is empty", Object.keys(vm.mergedErrors).length === 0);
});

// ============================================================ collect shapes
await scenario("collect shapes", async () => {
  const vm = makeForm({}, [
    txt("tags[]", "a"), txt("tags[]", "b"),
    txt("opts", "1", { type: "checkbox" }), txt("opts", "2", { type: "checkbox" }),
    txt("solo", "x"),
    txt("", "no-name")
  ]);
  const d = vm.collect();
  t("name[] collapses to array", Array.isArray(d.tags) && d.tags.join(",") === "a,b", JSON.stringify(d.tags));
  t("repeated name becomes array", Array.isArray(d.opts) && d.opts.join(",") === "1,2", JSON.stringify(d.opts));
  t("single value stays scalar", d.solo === "x");
  t("unnamed field excluded", Object.keys(d).length === 3, Object.keys(d).join(","));
});

// ============================================================ RESET (recursion regression)
await scenario("reset", async () => {
  const vm = makeForm({}, [txt("title", "typed")]);
  const before = vm.form.resetCalls;
  vm.reset();
  t("reset() delegates to the form exactly once", vm.form.resetCalls === before + 1, vm.form.resetCalls - before);
  t("reset emitted EXACTLY once (no recursion)", vm.emitted.reset === 1, vm.emitted.reset);
  t("no runaway recursion", vm.form.resetCalls === before + 1, vm.form.resetCalls - before);
  vm.reset();
  t("a second reset adds exactly one more", vm.emitted.reset === 2, vm.emitted.reset);
  t("form.reset called twice, not four times", vm.form.resetCalls === before + 2, vm.form.resetCalls - before);
});

await scenario("reset is not cancelled", async () => {
  // The old code called preventDefault(), which cancelled the browser's own
  // field clearing. A dispatched reset must therefore not be marked prevented.
  const vm = makeForm({}, [txt("title", "v")]);
  let prevented = false;
  vm.form.addEventListener("reset", (ev) => { if (ev.defaultPrevented) prevented = true; });
  vm.form.dispatch("reset");
  t("reset event is not prevented", prevented === false);
  t("a dispatched reset still emits once", vm.emitted.reset === 1, vm.emitted.reset);
});

// ============================================================ errors prop
await scenario("errors prop", async () => {
  const vm = makeForm({ errors: { title: "The title is already taken." } }, [txt("title", "ok")]);
  const f = vm.fields()[0];
  t("server error sets custom validity", f.checkValidity() === false);
  t("server error lands on the control", f.validationMessage === "The title is already taken.");
  t("server error blocks submit", vm.submit() === null && vm.emitted.submit.length === 0);
  t("server error in errorList", vm.errorList[0].message === "The title is already taken.");
  const applied = vm.customValidity.title;
  vm.errors = {};
  options.methods.applyServerErrors.call(vm);
  t("cleared errors restore validity", f.checkValidity() === true, f.validationMessage);
  t("only our own validity was cleared", applied === "The title is already taken.");
  const data = vm.submit();
  t("submit goes through after clearing", vm.emitted.submit.length === 1 && data.title === "ok", JSON.stringify(data));
});

// ============================================================ errors shapes
await scenario("errors shapes", async () => {
  const vm = makeForm({ errors: { a: ["one", "two"], b: { min: "too small" }, c: "", d: null } }, [txt("a", ""), txt("b", "")]);
  const norm = vm.serverErrors;
  t("array errors joined", norm.a === "one two", norm.a);
  t("keyed errors formatted", norm.b === "min too small", norm.b);
  t("empty error values dropped", !("c" in norm) && !("d" in norm), Object.keys(norm).join(","));
});

// ============================================================ unknown error key
await scenario("unknown error key", async () => {
  const vm = makeForm({ errors: { ghost: "no such field", title: "real" } }, [txt("title", "")]);
  t("unknown error key does not crash", true);
  t("known error still applied", vm.fields()[0].validationMessage === "real", vm.fields()[0].validationMessage);
  t("unknown key not applied to another control", vm.fields().length === 1);
});

// ============================================================ loading
await scenario("loading", async () => {
  const vm = makeForm({ loading: true }, [txt("title", "v")]);
  t("loading blocks submit", vm.submit() === null && vm.emitted.submit.length === 0);
  t("busy reflects loading", vm.busy === true);
  vm.loading = false;
  t("submit works once loading is false", vm.submit() !== null && vm.emitted.submit.length === 1);
});

// ============================================================ disabled
await scenario("disabled", async () => {
  const a = txt("a", "x");
  const b = txt("b", "y", { disabled: true });
  const vm = makeForm({ disabled: true }, [a, b]);
  t("disabled propagated to a field", a.disabled === true);
  t("already-disabled field stays disabled", b.disabled === true);
  vm.disabled = false;
  options.methods.applyDisabled.call(vm, false);
  t("field we disabled is re-enabled", a.disabled === false);
  t("originally-disabled field STAYS disabled", b.disabled === true);
});

await scenario("disabled fields are not validated", async () => {
  const vm = makeForm({ disabled: true }, [txt("req", "", { required: true })]);
  t("disabled required field does not block submit", vm.submit() !== null && vm.emitted.submit.length === 1);
  t("disabled field reported no error", Object.keys(vm.mergedErrors).length === 0, JSON.stringify(vm.mergedErrors));
});

// ============================================================ validateOn
await scenario("validateOn=submit", async () => {
  const vm = makeForm({ validateOn: "submit" }, [txt("t", "", { required: true })]);
  vm.onFieldEvent({ type: "blur" });
  t("validateOn=submit ignores blur", vm.emitted.invalid.length === 0, vm.emitted.invalid.length);
});

await scenario("validateOn=blur", async () => {
  const vm = makeForm({ validateOn: "blur" }, [txt("t", "", { required: true })]);
  vm.onFieldEvent({ type: "blur" });
  t("validateOn=blur validates on blur", vm.emitted.invalid.length === 1, vm.emitted.invalid.length);
  const vm2 = makeForm({ validateOn: "blur" }, [txt("t", "", { required: true })]);
  vm2.onFieldEvent({ type: "input" });
  t("validateOn=blur ignores input", vm2.emitted.invalid.length === 0, vm2.emitted.invalid.length);
});

await scenario("validateOn=change", async () => {
  const vm = makeForm({ validateOn: "change" }, [txt("t", "", { required: true })]);
  vm.onFieldEvent({ type: "input" });
  t("validateOn=change validates on input", vm.emitted.invalid.length === 1, vm.emitted.invalid.length);
  const vm2 = makeForm({ validateOn: "change" }, [txt("t", "", { required: true })]);
  vm2.onFieldEvent({ type: "blur" });
  t("validateOn=change ignores blur", vm2.emitted.invalid.length === 0, vm2.emitted.invalid.length);
});

// ============================================================ nativeValidation
await scenario("nativeValidation", async () => {
  const vm = makeForm({ nativeValidation: true }, [txt("t", "", { required: true })]);
  const f = vm.fields()[0];
  vm.submit();
  t("native mode calls reportValidity()", f.reportCount === 1, f.reportCount);
  t("native mode still emits invalid", vm.emitted.invalid.length === 1, vm.emitted.invalid.length);
});

// ============================================================ emit contract
await scenario("emit contract", async () => {
  const vm = makeForm({}, [txt("t", "", { required: true })]);
  const f = vm.fields()[0];
  vm.submit();
  t("update:errors fired on the failing pass", vm.emitted["update:errors"].length === 1);
  f.value = "now filled";
  vm.submit();
  t("update:errors fired on the passing pass too", vm.emitted["update:errors"].length === 2, vm.emitted["update:errors"].length);
  t("invalid did NOT fire on the passing pass", vm.emitted.invalid.length === 1, vm.emitted.invalid.length);
  t("errors map cleared on the passing pass", Object.keys(vm.mergedErrors).length === 0, JSON.stringify(vm.mergedErrors));
});

// ============================================================ invalid carries the full failure set
// `invalid` must never be empty on a failed pass: a consumer that does
// `errors = $event` would render nothing while the form is invalid.
await scenario("invalid full failure set", async () => {
  const vm = makeForm({ errors: { title: "taken" } }, [txt("title", "", { required: true })]);
  vm.submit();
  t("server-only failure still reports in invalid", Object.keys(vm.emitted.invalid[0] || {}).join(",") === "title", JSON.stringify(vm.emitted.invalid[0]));
  t("server-only failure is not an empty invalid", JSON.stringify(vm.emitted.invalid[0] || {}) !== "{}", JSON.stringify(vm.emitted.invalid[0]));
});

await scenario("invalid mixes server and native", async () => {
  const vm = makeForm({ errors: { a: "server a" } }, [txt("a", ""), txt("b", "", { required: true })]);
  vm.submit();
  t("invalid reports server AND native failures", Object.keys(vm.emitted.invalid[0] || {}).sort().join(",") === "a,b", JSON.stringify(vm.emitted.invalid[0]));
});

// ============================================================ REGRESSION: sticky validity
// applyServerErrors() used to read the *merged* map, which includes native
// errors, so a native failure was fed back as custom validity and the field
// could never become valid again.
await scenario("sticky validity regression", async () => {
  const vm = makeForm({}, [txt("t", "", { required: true })]);
  const f = vm.fields()[0];
  vm.submit();
  t("first pass fails", vm.emitted.invalid.length === 1);
  t("no custom validity was set by a native error", vm.customValidity.t === undefined, JSON.stringify(vm.customValidity));
  f.value = "fixed";
  t("field valid after fixing", f.checkValidity() === true, f.validationMessage);
  const data = vm.submit();
  t("fixing the field clears the error (not sticky)", vm.emitted.submit.length === 1 && data !== null);
  t("errors map is empty again", Object.keys(vm.mergedErrors).length === 0, JSON.stringify(vm.mergedErrors));
});

// ============================================================ several failures
await scenario("several failures", async () => {
  const vm = makeForm({}, [txt("a", "", { required: true }), txt("b", "", { type: "email", required: true }), txt("c", "fine")]);
  vm.submit();
  const first = vm.emitted.invalid[0] || {};
  t("all invalid fields reported", Object.keys(first).sort().join(",") === "a,b", JSON.stringify(first));
  t("valid field not reported", !("c" in first));
  t("errorList has one entry per failure", vm.errorList.length === 2, vm.errorList.length);
});

const passed = out.filter((r) => r.pass).length;
console.log(JSON.stringify({
  total: out.length,
  passed: passed,
  failed: out.filter((r) => !r.pass).map((r) => r.name),
  results: out
}));
JS;
    }
}
