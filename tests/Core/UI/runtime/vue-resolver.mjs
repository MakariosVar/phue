/**
 * ESM resolve hook for the runtime tests.
 *
 * The factory under test imports `vue` the way a browser module does — a bare
 * specifier an import map resolves at serve time. Node has no import map and
 * `vue` is not hoisted to the repo root (it ships inside the SSR sidecar), so
 * this hook redirects that specifier to a test double.
 *
 * Redirecting rather than resolving the real Vue is deliberate: createPhueApp's
 * contract is WHICH factory it picks and WHAT it wires onto the app. Mounting
 * is Vue's own behaviour, and it needs a real DOM that Node does not have;
 * that half is covered by the SSR sidecar tests and the live page.
 *
 * @see ./register-vue.mjs
 */

const VUE_DOUBLE = new URL("./vue-double.mjs", import.meta.url).href;

export async function resolve(specifier, context, nextResolve) {
    if (specifier === "vue") {
        return { url: VUE_DOUBLE, shortCircuit: true, format: "module" };
    }

    return nextResolve(specifier, context);
}