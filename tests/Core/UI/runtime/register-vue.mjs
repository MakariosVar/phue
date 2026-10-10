/**
 * Entry point for the runtime tests: points the bare `vue` specifier at the
 * test double so `src/Core/UI/runtime/createPhueApp.js` can be imported
 * outside a browser.
 *
 * Run:
 *   node --import ./tests/Core/UI/runtime/register-vue.mjs \
 *        ./tests/Core/UI/runtime/createPhueApp.test.mjs
 */

import { register } from "node:module";

register(new URL("./vue-resolver.mjs", import.meta.url), {
    parentURL: import.meta.url,
});