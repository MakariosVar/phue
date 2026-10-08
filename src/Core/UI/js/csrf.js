/**
 * CSRF token transport for the browser.
 *
 * The server issues the token in a readable cookie and expects it back in a request header.
 * This module is the client half of that contract. It is also installed automatically in
 * <head> by ComponentRenderer, so ordinary fetch/XHR calls are covered without this being
 * imported; importing it is for code that builds requests by hand.
 *
 * Why the cookie is readable rather than HttpOnly: the client has to read it to put it in
 * the header, so HttpOnly would leave it unreadable and the header path dead. It buys
 * nothing extra either way — script already running on this origin can issue authenticated
 * requests with or without the token. CSRF defends against the cross-site attacker; XSS is
 * a separate problem that this token was never going to solve.
 *
 * Rules, matching position.js:
 * 1. No imports
 * 2. No top-level window/document references
 * 3. No module-level mutable state
 * 4. Every export MUST use function declaration (export function name() {})
 * 5. Unique export names
 */

/**
 * Cookie holding the token. Must match app.csrf.cookieName in config/app.php.
 * @type {string}
 */
const CSRF_COOKIE = 'phue_csrf';

/**
 * Header the token is returned in. Must match app.csrf.headerName.
 * @type {string}
 */
const CSRF_HEADER = 'X-CSRF-Token';

/**
 * Methods that never change state and therefore need no token.
 * @type {Set<string>}
 */
const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS', 'TRACE']);

/**
 * Read a cookie value by name.
 *
 * Values are compared case-sensitively: cookie names are case-sensitive per RFC 6265, and
 * folding case here would let a cookie named PHUE_CSRF satisfy a lookup for phue_csrf.
 *
 * @param {string} name Cookie name
 * @param {string} [fallback] Returned when the cookie is absent
 * @returns {string}
 */
export function readCookie(name, fallback = '') {
    if (typeof document === 'undefined' || typeof document.cookie !== 'string') {
        return fallback;
    }

    const parts = document.cookie.split(';');

    for (const part of parts) {
        const separator = part.indexOf('=');

        if (separator === -1) {
            continue;
        }

        if (part.slice(0, separator).trim() === name) {
            return decodeURIComponent(part.slice(separator + 1).trim());
        }
    }

    return fallback;
}

/**
 * The current CSRF token, or an empty string when the server has not issued one.
 *
 * @returns {string}
 */
export function csrfToken() {
    return readCookie(CSRF_COOKIE);
}

/**
 * Headers to merge into a state-changing request.
 *
 * Returns an empty object when there is no token, so a caller can spread this
 * unconditionally: an absent token must produce a 403 from the server rather than a
 * client-side exception that silently drops the request.
 *
 * @param {string} [method] HTTP method of the request
 * @returns {Object<string, string>}
 */
export function csrfHeaders(method = 'GET') {
    if (SAFE_METHODS.has(String(method).toUpperCase())) {
        return {};
    }

    const token = csrfToken();

    return token === '' ? {} : { [CSRF_HEADER]: token };
}

/**
 * Attach the token to every state-changing fetch, once.
 *
 * Wrapping window.fetch rather than editing each call site is deliberate: it also covers
 * components this framework did not write, which would otherwise silently go unprotected.
 * Idempotent, because the head script that installs it may run on a re-render.
 *
 * @returns {boolean} True when the patch was installed by this call
 */
export function installCsrfFetchPatch() {
    if (typeof window === 'undefined' || typeof window.fetch !== 'function') {
        return false;
    }

    if (window.fetch.__phueCsrfPatched === true) {
        return false;
    }

    const original = window.fetch.bind(window);

    const patched = function (input, init) {
        const options = init || {};
        const method = String((options.method || (typeof input !== 'string' && input && input.method) || 'GET')).toUpperCase();
        const headers = csrfHeaders(method);
        const existing = options.headers;

        if (Object.keys(headers).length === 0) {
            return original(input, options);
        }

        let merged;

        if (existing && typeof existing.forEach === 'function' && typeof existing.get === 'function') {
            // A Headers instance or any Headers-like object: copy, then add.
            merged = new existing.constructor(existing);
            merged.set(CSRF_HEADER, headers[CSRF_HEADER]);
        } else if (Array.isArray(existing)) {
            merged = existing.filter((pair) => String(pair[0]).toLowerCase() !== CSRF_HEADER.toLowerCase());
            merged.push([CSRF_HEADER, headers[CSRF_HEADER]]);
        } else {
            merged = Object.assign({}, existing, headers);
        }

        return original(input, Object.assign({}, options, { headers: merged }));
    };

    patched.__phueCsrfPatched = true;
    window.fetch = patched;

    return true;
}