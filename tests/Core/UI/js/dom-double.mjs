/**
 * dom-double.mjs — minimal DOM double for the Phue shared JS composables.
 *
 * The composables under `src/Core/UI/js/` are deliberately dependency-free and
 * only touch a narrow slice of the DOM (attribute access, class lists,
 * containment, visibility checks and keydown listeners). This double implements
 * exactly that slice.
 *
 * It implements a real (small) CSS selector matcher rather than a stub, because
 * `focusableWithin()` correctness depends on the selector list itself: a stub
 * that returned every element would silently pass a typo like `button:not([disable])`.
 *
 * Supported selector syntax (all that the composables use):
 *   - type selectors            `button`
 *   - attribute presence        `[href]`
 *   - attribute equality        [tabindex="-1"]
 *   - negation                  :not([disabled])
 *   - selector lists            `a[href], button:not([disabled])`
 */

/* ------------------------------------------------------------------ *
 * Selector compilation
 * ------------------------------------------------------------------ */

// Class selectors are matched here too. They were not, and the omission was
// silent rather than loud: `.modal` compiled to no predicates at all, which
// matches every element, so an assertion written against a class selector
// passed without testing anything.
const RE_REQ = () => /:not\(([^)]*)\)|\[([\w-]+)(?:=["']?([^"'\]]*)["']?)?\]|\.([A-Za-z_][\w-]*)/g;

/**
 * Compiles one simple selector into a matcher description.
 *
 * @param {string} selector Simple selector (no commas).
 * @returns {{tag: string|null, preds: Array<{negate: boolean, fn: Function}>}}
 */
function compileSimple(selector) {
    const trimmed = selector.trim();
    const tagMatch = trimmed.match(/^[a-zA-Z][\w-]*/);
    const tag = tagMatch ? tagMatch[0].toUpperCase() : null;
    const rest = tagMatch ? trimmed.slice(tagMatch[0].length) : trimmed;

    const preds = [];
    let m;
    // A fresh regex per call: compiling a :not() argument recurses, and a shared
    // /g regex would have its lastIndex clobbered by the nested call.
    const scanner = RE_REQ();

    while ((m = scanner.exec(rest)) !== null) {
        if (m[1] !== undefined) {
            const inner = compileSimple(m[1]);
            preds.push({ negate: true, fn: (el) => matchCompiled(el, inner) });
        } else if (m[4] !== undefined) {
            const name = m[4];
            preds.push({ negate: false, fn: (el) => el.classList.contains(name) });
        } else {
            const attr = m[2];
            const value = m[3] === undefined ? null : m[3];
            preds.push({
                negate: false,
                fn: (el) => el.hasAttribute(attr) && (value === null || el.getAttribute(attr) === value)
            });
        }
    }

    return { tag, preds };
}

/**
 * Evaluates a compiled selector against an element.
 */
function matchCompiled(el, compiled) {
    if (compiled.tag !== null && el.tagName !== compiled.tag) {
        return false;
    }
    for (const pred of compiled.preds) {
        const hit = pred.fn(el);
        if (pred.negate ? hit : !hit) {
            return false;
        }
    }
    return true;
}

/**
 * Evaluates a comma-separated selector list against an element.
 */
export function matchesSelector(el, selectorList) {
    const parts = selectorList.split(',').map((s) => s.trim()).filter((s) => s !== '');
    return parts.some((part) => matchCompiled(el, compileSimple(part)));
}

/* ------------------------------------------------------------------ *
 * Element
 * ------------------------------------------------------------------ */

const ALL_ATTRS = Symbol('all-attrs');

class FakeElement {
    constructor(tagName, doc) {
        this.tagName = String(tagName || 'div').toUpperCase();
        this._doc = doc;
        this._attrs = new Map();
        this._children = [];
        this._parent = null;
        this._disabled = false;
        this._visible = true;
        this.focusCalls = 0;

        const attrs = this._attrs;

        this.classList = {
            contains: (name) => attrs.has('class') && attrs.get('class').split(/\s+/).includes(name),
            add: (name) => {
                const current = attrs.has('class') ? attrs.get('class').split(/\s+/).filter(Boolean) : [];
                if (!current.includes(name)) {
                    current.push(name);
                }
                attrs.set('class', current.join(' '));
            }
        };

        this.style = {};

        this.addEventListener = () => {};
        this.removeEventListener = () => {};
    }

    /* --- tree --- */
    get parentElement() { return this._parent; }
    get children() { return this._children.slice(); }

    appendChild(child) {
        child._parent = this;
        this._children.push(child);
        return child;
    }

    get isConnected() {
        let node = this;
        while (node._parent) {
            node = node._parent;
        }
        // A detached subtree also has no parent, so "reached a root" is not
        // enough: the root must be this document's documentElement.
        return node === this._doc.documentElement;
    }

    contains(other) {
        let node = other;
        while (node) {
            if (node === this) {
                return true;
            }
            node = node._parent;
        }
        return false;
    }

    closest(selector) {
        let node = this;
        while (node) {
            if (matchesSelector(node, selector)) {
                return node;
            }
            node = node._parent;
        }
        return null;
    }

    _descendants(out = []) {
        for (const child of this._children) {
            out.push(child);
            child._descendants(out);
        }
        return out;
    }

    querySelectorAll(selector) {
        return this._descendants().filter((el) => matchesSelector(el, selector));
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }

    /* --- attributes --- */
    hasAttribute(name) { return this._attrs.has(name); }
    getAttribute(name) { return this._attrs.has(name) ? this._attrs.get(name) : null; }
    setAttribute(name, value) { this._attrs.set(name, String(value)); }
    removeAttribute(name) { this._attrs.delete(name); }

    getAttributeNames() { return Array.from(this._attrs.keys()); }

    /* --- state --- */
    get disabled() { return this._disabled; }
    set disabled(value) { this._disabled = Boolean(value); }

    /** Visibility switch used by tests to simulate display:none / visibility:hidden. */
    _setVisible(value) { this._visible = Boolean(value); return this; }

    /**
     * Underlying visibility logic. Kept separate from checkVisibility() so the
     * test can delete checkVisibility() (exercising the getClientRects()
     * fallback path in focusableWithin) without breaking the double itself.
     */
    _isVisible() {
        if (this.hasAttribute('hidden')) {
            return false;
        }
        if (!this._visible) {
            return false;
        }
        const inlineDisplay = this.style && this.style.display;
        return inlineDisplay !== 'none' && inlineDisplay !== 'hidden';
    }

    checkVisibility() {
        return this._isVisible();
    }

    getClientRects() {
        return this._isVisible() ? [{ width: 10, height: 10 }] : [];
    }

    getBoundingClientRect() {
        const r = this._rect || { left: 0, top: 0, width: 0, height: 0, right: 0, bottom: 0 };
        return {
            left: r.left,
            top: r.top,
            width: r.width,
            height: r.height,
            right: r.right !== undefined ? r.right : r.left + r.width,
            bottom: r.bottom !== undefined ? r.bottom : r.top + r.height
        };
    }

    focus() {
        this.focusCalls += 1;
        this._doc.activeElement = this;
    }

    blur() {
        if (this._doc.activeElement === this) {
            this._doc.activeElement = this._doc.body;
        }
    }
}

/* ------------------------------------------------------------------ *
 * Document + Window
 * ------------------------------------------------------------------ */

class FakeDocument {
    constructor() {
        this._isDocumentRoot = true;
        this.root = this;
        this.activeElement = null;
        this.listeners = new Map();
        this.documentElement = new FakeElement('html', this);
        this.body = new FakeElement('body', this);
        this.body._parent = this.documentElement;
        this.documentElement._children.push(this.body);
        this.activeElement = this.body;
    }

    createElement(tag) { return new FakeElement(tag, this); }

    contains(node) {
        if (node === this.documentElement || node === this.body || node === this) {
            return true;
        }
        return this.documentElement.contains(node);
    }

    /* --- keydown dispatch in registration order --- */
    addEventListener(type, fn, capture) { this.listeners.set(`${type}:${capture ? 1 : 0}`, fn); }
    removeEventListener() {}
    dispatch(type, event) { this.listeners.forEach((fn) => fn(event)); }
}

class FakeWindow {
    constructor(doc) {
        this._doc = doc;
        this._listeners = [];
        this.innerWidth = 1024;
        this.innerHeight = 768;
        this.scrollX = 0;
        this.scrollY = 0;
    }

    /**
     * Minimal computed-style read.
     *
     * Only `zIndex` is modelled, and only from the element's inline `style`
     * attribute — which is enough for the stacking questions the kit asks, and is
     * honest about its limits: a rule in a real stylesheet is not simulated, so a
     * test that needs one must set the value inline or install a stylesheet.
     */
    getComputedStyle(el) {
        const raw = (el && el.getAttribute && el.getAttribute('style')) || '';
        const m = /(?:^|;)\s*z-index\s*:\s*([^;]+)/i.exec(raw);
        return { zIndex: m ? m[1].trim() : 'auto' };
    }

    addEventListener(type, fn, capture) {
        this._listeners.push({ type, fn, capture: Boolean(capture) });
    }

    removeEventListener(type, fn, capture) {
        this._listeners = this._listeners.filter(
            (l) => !(l.type === type && l.fn === fn && l.capture === Boolean(capture))
        );
    }

    /** Fires a keydown event on every capture listener in registration order. */
    dispatchKeydown(event) {
        for (const l of this._listeners.filter((x) => x.type === 'keydown' && x.capture)) {
            l.fn(event);
        }
    }

    listenerCount(type) {
        return this._listeners.filter((l) => l.type === type).length;
    }

    /**
     * The registered listeners for a type, including the capture flag.
     *
     * The flag is the whole point: a bubbling scroll listener never fires for a
     * nested scroller, which is the failure mode this double has to be able to
     * catch rather than just count.
     */
    listenersFor(type) {
        return this._listeners.filter((l) => l.type === type);
    }

    dispatchScroll() {
        for (const l of this._listeners.filter((x) => x.type === 'scroll')) {
            l.fn({ type: 'scroll' });
        }
    }
}

/* ------------------------------------------------------------------ *
 * Test harness
 * ------------------------------------------------------------------ */

export function makeEvent(key, extra = {}) {
    return {
        key,
        shiftKey: false,
        altKey: false,
        metaKey: false,
        ctrlKey: false,
        ...extra,
        target: null,
        defaultPrevented: false,
        propagationStopped: false,
        immediatePropagationStopped: false,
        preventDefault() { this.defaultPrevented = true; },
        stopPropagation() { this.propagationStopped = true; },
        stopImmediatePropagation() { this.immediatePropagationStopped = true; }
    };
}

/**
 * Renders a value for a failure message without throwing.
 *
 * DOM nodes hold a cyclic reference to their owning document, so a plain
 * JSON.stringify() would crash the reporter and lose the actual failure.
 */
function describe(value) {
    if (value === null || value === undefined) {
        return String(value);
    }
    if (typeof value === 'number' || typeof value === 'string' || typeof value === 'boolean') {
        return JSON.stringify(value);
    }
    if (typeof value === 'function') {
        return `function ${value.name || '(anonymous)'}`;
    }
    if (typeof value.tagName === 'string') {
        const role = value.getAttribute ? value.getAttribute('role') : null;
        const cls = value.classList && value.classList.contains('modal-backdrop') ? '.modal-backdrop' : '';
        return `<${value.tagName.toLowerCase()}${role ? ` role=${role}` : ''}${cls}>`;
    }
    if (value instanceof Array) {
        return `[${value.map(describe).join(', ')}]`;
    }
    try {
        return JSON.stringify(value);
    } catch (e) {
        return String(value);
    }
}

export class Harness {
    constructor() {
        this.passed = 0;
        this.failures = [];
        this._suite = '';
    }

    suite(name) { this._suite = name; return this; }

    ok(name, condition, detail = '') {
        if (condition) {
            this.passed += 1;
        } else {
            const label = this._suite ? `[${this._suite}] ${name}` : name;
            this.failures.push(detail ? `${label} :: ${detail}` : label);
        }
    }

    eq(name, actual, expected) {
        const same = Object.is(actual, expected);
        this.ok(name, same, same ? '' : `expected ${describe(expected)}, got ${describe(actual)}`);
    }

    deepEq(name, actual, expected) {
        const a = JSON.stringify(actual);
        const e = JSON.stringify(expected);
        this.ok(name, a === e, a === e ? '' : `expected ${describe(expected)}, got ${describe(actual)}`);
    }

    throws(name, fn) {
        let threw = false;
        try { fn(); } catch (e) { threw = true; }
        this.ok(name, threw, threw ? '' : 'expected the call to throw, it returned');
    }

    /** Creates a fresh document/window pair and installs them as globals. */
    installDom() {
        const doc = new FakeDocument();
        const win = new FakeWindow(doc);

        globalThis.document = doc;
        globalThis.window = win;
        globalThis.Element = FakeElement;
        globalThis.HTMLElement = FakeElement;
        if (typeof globalThis.requestAnimationFrame !== 'function') {
            globalThis.requestAnimationFrame = (fn) => setTimeout(fn, 0);
            globalThis.cancelAnimationFrame = (id) => clearTimeout(id);
        }

        this.doc = doc;
        this.win = win;
        return { doc, win };
    }

    el(tag, opts = {}) {
        const el = new FakeElement(tag, this.doc);
        if (opts.attrs) {
            for (const [k, v] of Object.entries(opts.attrs)) {
                el.setAttribute(k, v);
            }
        }
        if (opts.classes) {
            opts.classes.forEach((c) => el.classList.add(c));
        }
        if (opts.disabled) {
            el.disabled = true;
        }
        if (opts.visible === false) {
            el._setVisible(false);
        }
        if (opts.rect) {
            el._rect = opts.rect;
        }
        if (opts.children) {
            opts.children.forEach((c) => el.appendChild(c));
        }
        return el;
    }

    /** Removes `checkVisibility` from every element so the fallback path runs. */
    disableCheckVisibility() {
        if (!FakeElement.prototype.__originalCheckVisibility) {
            FakeElement.prototype.__originalCheckVisibility = FakeElement.prototype.checkVisibility;
        }
        FakeElement.prototype.checkVisibility = undefined;
    }

    restoreCheckVisibility() {
        if (FakeElement.prototype.__originalCheckVisibility) {
            FakeElement.prototype.checkVisibility = FakeElement.prototype.__originalCheckVisibility;
            delete FakeElement.prototype.__originalCheckVisibility;
        }
    }

    report() {
        return JSON.stringify({ passed: this.passed, failures: this.failures });
    }

    finish() {
        const report = this.report();
        if (this.failures.length > 0) {
            console.error(report);
            process.exit(1);
        }
        console.log(report);
    }
}

/**
 * Emits the final report and exits with the right status code.
 */
export function finish(harness) {
    const report = harness.report();
    if (harness.failures.length > 0) {
        console.error(report);
        process.exit(1);
    }
    console.log(report);
}

export { FakeElement, FakeDocument, FakeWindow };