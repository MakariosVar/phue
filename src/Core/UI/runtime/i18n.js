/**
 * Client-side translation runtime.
 *
 * Reads the catalogue snapshot the server serialised into
 * `window.__PHUE_I18N__` and reproduces Phue\Core\t() EXACTLY: same lookup
 * (message IS the key, pre-resolved chain), same placeholder substitution
 * (Translator::substitute()). The server flattens the locale chain so this
 * file owns none of that logic — see Phue\Core\I18n\Translator::snapshot().
 *
 * No module-level state: the payload is read lazily at call time, which keeps
 * this file byte-identical between the browser and the Bun SSR sidecar.
 *
 * @module phue/i18n
 */

/**
 * Substitute {placeholders} the way Translator::substitute() does.
 *
 * PHP semantics, not JavaScript semantics — each rule below is a place the
 * naive JS version silently diverges:
 *
 *   - only scalars, null and Stringable substitute; arrays and plain objects
 *     do not, and their {placeholder} stays visible
 *   - null becomes "", booleans cast as PHP casts them (true -> "1")
 *   - unmatched placeholders are left in place, not blanked
 *
 * The function replacer is not optional: a string replacer would interpret
 * `$&` / `$1` inside a translated value, which PHP's strtr does not.
 */
export function substitute(message, params) {
    if (params === null || typeof params !== "object") {
        return message;
    }

    return String(message).replace(/\{([^{}]+)\}/g, (match, key) => {
        if (!Object.prototype.hasOwnProperty.call(params, key)) {
            return match;
        }

        const value = params[key];

        if (value === null) {
            return "";
        }

        switch (typeof value) {
            case "string":
                return value;
            case "boolean":
                return value ? "1" : "";
            case "number":
            case "bigint":
                return String(value);
        }

        // Arrays are scalar-adjacent in JS but explicitly excluded in PHP.
        if (typeof value === "object" && !Array.isArray(value)) {
            const asString = value[Symbol.toPrimitive] ?? value.toString;
            if (typeof asString === "function" && asString !== Object.prototype.toString) {
                return String(value);
            }
        }

        return match;
    });
}

/**
 * Translate a message using the active catalogue snapshot.
 *
 * @param {string} message Source-language text; it is the catalogue key.
 * @param {Object<string, *>} [params] Placeholder values.
 * @returns {string} The translation, or `message` when the key is unknown.
 */
export function phueT(message, params = {}) {
    return translateWith(globalThis.__PHUE_I18N__, message, params);
}

/**
 * Bind a translator to one catalogue snapshot.
 *
 * The SSR sidecar serves requests concurrently, so a payload parked on
 * globalThis would be read by whichever render ran next. Closing over the
 * snapshot makes the locale request-scoped, like Container::flushScope() on
 * the PHP side.
 */
export function createTranslator(snapshot) {
    return (message, params = {}) => translateWith(snapshot, message, params);
}

function translateWith(snapshot, message, params) {
    const catalogue = snapshot?.messages;

    const translated =
        catalogue !== null &&
        catalogue !== undefined &&
        typeof catalogue === "object" &&
        typeof catalogue[message] === "string" &&
        catalogue[message] !== ""
            ? catalogue[message]
            : message;

    return substitute(translated, params);
}

/**
 * Bind translation helpers onto a Vue app instance.
 *
 * `t` goes on `globalProperties`, which Vue's instance proxy resolves for
 * bare identifiers in templates.
 */
export function installI18n(app, snapshot = null) {
    const scoped = snapshot !== null ? createTranslator(snapshot) : phueT;
    const locale =
        typeof snapshot?.locale === "string" && snapshot.locale !== ""
            ? snapshot.locale
            : globalThis.__PHUE_I18N__?.locale;

    app.config.globalProperties.t = scoped;

    Object.defineProperty(app.config.globalProperties, "$locale", {
        get() {
            return typeof locale === "string" && locale !== "" ? locale : "en";
        },
        configurable: true,
        enumerable: true,
    });

    return app;
}