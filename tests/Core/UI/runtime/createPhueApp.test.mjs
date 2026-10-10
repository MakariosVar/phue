/**
 * Behavioural tests for the Phue runtime factory.
 *
 * createPhueApp's contract is behavioural: pick the right Vue factory for what
 * the container holds, wire translations onto the app, replace the previous app
 * instead of stacking on top of it. A source-text assertion cannot catch any of
 * those, so Vue is doubled (see ./vue-resolver.mjs) and the double records
 * which factory was called and what the app's lifecycle did.
 *
 * Run:
 *   node --import ./tests/Core/UI/runtime/register-vue.mjs \
 *        ./tests/Core/UI/runtime/createPhueApp.test.mjs
 */

import { test, beforeEach } from "node:test";
import assert from "node:assert/strict";

import { createPhueApp } from "../../../../src/Core/UI/runtime/createPhueApp.js";
import { calls, reset } from "./vue-double.mjs";

/** A container double: the only thing the factory reads is hasChildNodes(). */
function container({ children = 0 } = {}) {
    return {
        __vue_app__: null,
        hasChildNodes: () => children > 0,
    };
}

const OPTS = { render: () => null };

beforeEach(() => {
    reset();
    delete globalThis.__PHUE_I18N__;
    delete globalThis.__phueApp;
});

// --- The hydrate / render decision -----------------------------------------

test("a container holding server markup is hydrated, not re-rendered", () => {
    const app = createPhueApp(OPTS, { target: container({ children: 5 }) });

    assert.strictEqual(calls.length, 1);
    assert.strictEqual(calls[0].factory, "createSSRApp");
    assert.strictEqual(app.mountedOn.__vue_app__, app);
});

test("an empty container is rendered from scratch", () => {
    createPhueApp(OPTS, { target: container({ children: 0 }) });

    assert.strictEqual(calls.length, 1);
    assert.strictEqual(
        calls[0].factory,
        "createApp",
        "hydrating an empty container is the SPA-navigation path; it must not warn",
    );
});

test("hydrate:false forces a client render even when markup is present", () => {
    createPhueApp(OPTS, { target: container({ children: 9 }), hydrate: false });

    assert.strictEqual(calls[0].factory, "createApp");
});

test("hydrate:true forces hydration even when the container is empty", () => {
    createPhueApp(OPTS, { target: container({ children: 0 }), hydrate: true });

    assert.strictEqual(calls[0].factory, "createSSRApp");
});

// --- Exactly one mount -----------------------------------------------------

test("the factory mounts exactly once per call", () => {
    const target = container({ children: 0 });
    const app = createPhueApp(OPTS, { target });

    assert.strictEqual(app.mountedOn, target);
    assert.strictEqual(calls.length, 1, "one factory call, therefore one mount");
});

test("a second call unmounts the app the first call published", () => {
    const first = createPhueApp(OPTS, { target: container({ children: 0 }) });

    createPhueApp(OPTS, { target: container({ children: 0 }) });

    assert.strictEqual(
        first.unmountCount,
        1,
        "without this the old instance stays wired to the container and its watchers keep firing",
    );
    assert.strictEqual(first.mountedOn, null);
});

test("re-mounting ten times leaves exactly one live app", () => {
    const apps = [];
    for (let i = 0; i < 10; i += 1) {
        apps.push(createPhueApp(OPTS, { target: container({ children: 0 }) }));
    }

    for (const app of apps.slice(0, -1)) {
        assert.strictEqual(app.unmountCount, 1, "every superseded app is torn down exactly once");
    }
    assert.strictEqual(apps.at(-1).unmountCount, 0, "the live app is never torn down");
});

test("a previous value on the global that is not an app is skipped, not unmounted", () => {
    const unrelated = { some: "unrelated value" };
    globalThis.__phueApp = unrelated;

    const app = createPhueApp(OPTS, { target: container({ children: 0 }) });

    // The unrelated object has no unmount(), so the guard must skip it rather
    // than call something that isn't there — and the global must end up
    // pointing at the new app, which is the whole point of publishing.
    assert.strictEqual(typeof unrelated.unmount, "undefined", "the guard did not mutate it");
    assert.strictEqual(globalThis.__phueApp, app);
    assert.notStrictEqual(globalThis.__phueApp, unrelated);
});

// --- Publishing ------------------------------------------------------------

test("the app is published on window.__phueApp by default", () => {
    const app = createPhueApp(OPTS, { target: container({ children: 0 }) });

    assert.strictEqual(globalThis.__phueApp, app);
});

test("a custom global key does not clobber the default one", () => {
    createPhueApp(OPTS, { target: container({ children: 0 }), global: "__phueIsolated" });

    assert.strictEqual(globalThis.__phueIsolated.mountedOn !== null, true);
    assert.strictEqual(globalThis.__phueApp, undefined);
    delete globalThis.__phueIsolated;
});

// --- Translations ----------------------------------------------------------

test("t() is bound on the app instance, which is how templates reach it", () => {
    const app = createPhueApp(OPTS, { target: container({ children: 0 }) });

    assert.strictEqual(typeof app.config.globalProperties.t, "function");
});

test("t() translates through the catalogue snapshot", () => {
    globalThis.__PHUE_I18N__ = {
        locale: "el",
        messages: { Login: "Σύνδεση", "Hello {name}": "Γεια {name}" },
    };

    const { t } = createPhueApp(OPTS, { target: container({ children: 0 }) }).config.globalProperties;

    assert.strictEqual(t("Login"), "Σύνδεση");
    assert.strictEqual(t("Hello {name}", { name: "Νίκος" }), "Γεια Νίκος");
    assert.strictEqual(t("Untranslated"), "Untranslated");
});

test("$locale carries the resolved locale", () => {
    globalThis.__PHUE_I18N__ = { locale: "de", messages: {} };

    const app = createPhueApp(OPTS, { target: container({ children: 0 }) });

    assert.strictEqual(app.config.globalProperties.$locale, "de");
});

test("$locale falls back to en when no snapshot is present", () => {
    const app = createPhueApp(OPTS, { target: container({ children: 0 }) });

    assert.strictEqual(app.config.globalProperties.$locale, "en");
});

test("t() degrades to the source message with no snapshot", () => {
    const { t } = createPhueApp(OPTS, { target: container({ children: 0 }) }).config.globalProperties;

    assert.strictEqual(t("Plain"), "Plain");
    assert.strictEqual(t("With {x}", { x: "1" }), "With 1");
});

// --- mount: false ----------------------------------------------------------

test("mount:false wires translations and publishes without attaching", () => {
    const target = container({ children: 0 });
    const app = createPhueApp(OPTS, { target, mount: false });

    assert.strictEqual(typeof app.config.globalProperties.t, "function");
    assert.strictEqual(globalThis.__phueApp, app);
    assert.strictEqual(app.mountedOn, null, "declining the mount must be honoured");
    assert.strictEqual(target.__vue_app__, null);
});