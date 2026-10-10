/**
 * Phue application factory for `.phue` single-file components.
 *
 * Replaces the four-line bootstrap every page used to repeat:
 *
 *     window.__phueApp?.unmount?.();
 *     window.__phueApp = createSSRApp({ ... });
 *     window.__phueApp.mount('#app');
 *
 * The component loader injects `import { createPhueApp } from 'phue'` into
 * every module it builds, so a page script uses it with no import of its own.
 *
 * What the factory owns, so that no page has to:
 *   - unmounting the previous app before mounting a new one
 *   - installing translations (`t` in templates, `t()` in scripts)
 *   - publishing the app on `window.__phueApp`
 *   - mounting into `#app`
 *
 * @module phue/createPhueApp
 */

import { createApp, createSSRApp } from "vue";
import { installI18n, phueT } from "./i18n.js";

/** Where the renderer plants the root container (ComponentRenderer wraps the page in this). */
const DEFAULT_MOUNT_TARGET = "#app";

/**
 * Choose the factory that matches what is in the container.
 *
 * Vue's createSSRApp handles both cases but warns when hydrating an empty
 * container, which is every SPA navigation. Picking here keeps it silent.
 */
function factoryFor(element, hydrate) {
    if (hydrate === true) return createSSRApp;
    if (hydrate === false) return createApp;
    return element !== null && element.hasChildNodes() ? createSSRApp : createApp;
}

/**
 * Create, wire and mount a Phue application.
 *
 * Picks its Vue factory from what the mount target actually holds: markup to
 * hydrate, or an empty container to render into. That single decision makes
 * the same call correct on a full page load and on an SPA re-mount.
 *
 * @param {object} options Component options (`setup`, `data`, `methods`, ...).
 * @param {object} [config]
 * @param {string|Element} [config.target='#app'] Selector or element to mount into.
 * @param {boolean} [config.mount=true] Set false to build the app without mounting it.
 * @param {string} [config.global='__phueApp'] `window` property the app is published on.
 * @param {"auto"|boolean} [config.hydrate='auto'] `true` forces hydration, `false` forces a client render.
 * @returns {import('vue').App} The application instance.
 */
export function createPhueApp(options, config = {}) {
    const target = config.target ?? DEFAULT_MOUNT_TARGET;
    const mount = config.mount !== false;
    const globalKey = config.global ?? "__phueApp";

    // Vue keeps no registry of applications, so a re-mount must unmount the
    // previous one or its watchers keep firing.
    const previous = globalThis[globalKey];
    if (previous && typeof previous.unmount === "function") {
        globalThis[globalKey] = null;
        previous.unmount();
    }

    const element = typeof target === "string" ? document.querySelector(target) : target;
    const app = factoryFor(element, config.hydrate ?? "auto")(options);

    installI18n(app);

    globalThis[globalKey] = app;

    if (mount) {
        app.mount(element);
    }

    return app;
}

// The `phue` import-map entry points here, and the loader imports all three
// from this module. installI18n is what makes `t()` work in templates of
// components that still call the raw Vue factories.
export { phueT as t, installI18n };