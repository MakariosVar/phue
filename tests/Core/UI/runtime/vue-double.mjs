/**
 * The Vue test double used by createPhueApp.test.mjs.
 *
 * Records which factory the runtime chose, so the hydrate/render decision is
 * observable, and hands back an app double that records its own lifecycle.
 * This is what lets the tests assert "did not mount twice" and "unmounted the
 * previous app" instead of asserting on source text.
 */

/** @type {Array<{factory: string, options: object}>} */
export const calls = [];

export function reset() {
    calls.length = 0;
}

function makeApp(factory) {
    const app = {
        factory,
        config: { globalProperties: {} },
        mountedOn: null,
        unmountCount: 0,
        mount(el) {
            app.mountedOn = el;
            app.el = el;
            if (el && typeof el === "object") el.__vue_app__ = app;
        },
        unmount() {
            app.unmountCount += 1;
            app.mountedOn = null;
        },
    };

    return app;
}

export function createApp(options) {
    calls.push({ factory: "createApp", options });
    return makeApp("createApp");
}

export function createSSRApp(options) {
    calls.push({ factory: "createSSRApp", options });
    return makeApp("createSSRApp");
}

export const ref = (value) => ({ value });
export const computed = (fn) => ({ value: fn() });
export default { createApp, createSSRApp };