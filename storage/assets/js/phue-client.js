/**
 * @file phue-client.js
 * @description Dynamic client-side Single File Component (SFC) module loader for Phue.
 */

/**
 * @typedef {Object} VueComponentOptions
 * @property {string} [template] The HTML/Vue template string.
 * @property {Object|Function} [data] Reactive data factory or object.
 * @property {Object} [methods] Component methods.
 * @property {Object} [computed] Computed properties.
 * @property {Function} [mounted] Lifecycle hook.
 */

/**
 * In-memory registry to cache loaded components and prevent redundant dynamic imports.
 * @type {Map<string, VueComponentOptions>}
 */
const componentCache = new Map();

/**
 * Dynamically imports an ES module Phue component from the server and merges
 * its structural template (`__phueTemplate`) with its component options.
 *
 * @async
 * @param {string} url - The resolved Phue JS asset URL (e.g. "/assets/phue/PhButton.a1b2c3d4.phue.js").
 * @returns {Promise<VueComponentOptions>} Resolves with the ready-to-render Vue component options object.
 * @throws {Error} If the network request fails or the module export is malformed.
 *
 * @example
 * import { loadPhueComponent } from '/assets/js/phue-client.js';
 *
 * const PhButton = await loadPhueComponent('/assets/phue/PhButton.a1b2c3d4.phue.js');
 * app.component('PhButton', PhButton);
 */
export async function loadPhueComponent(url) {
    if (!url || typeof url !== 'string') {
        throw new Error('[Phue Client] Invalid asset URL provided to loadPhueComponent().');
    }

    // 1. Fast path: return cached component definition if already resolved
    if (componentCache.has(url)) {
        return componentCache.get(url);
    }

    try {
        // 2. Perform native dynamic ES module import
        const module = await import(url);
        const options = module.default || {};

        // 3. Merge raw template export with Vue component options payload
        const componentDefinition = {
            ...options,
            ...(module.__phueTemplate ? { template: module.__phueTemplate } : {})
        };

        // 4. Save to client-side cache
        componentCache.set(url, componentDefinition);

        return componentDefinition;
    } catch (error) {
        console.error(`[Phue Client] Failed to load SFC module from "${url}":`, error);
        throw error;
    }
}

/**
 * Utility to batch-load multiple Phue components in parallel.
 * Useful for page-level component whitelists.
 *
 * @async
 * @param {Record<string, string>} whitelist - Key-value map of component names to URLs.
 * @returns {Promise<Record<string, VueComponentOptions>>} Map of component names to resolved Vue options.
 *
 * @example
 * const components = await loadPhueWhitelist({
 *     PhButton: '/assets/phue/PhButton.a1b2c3d4.phue.js',
 *     PhCard: '/assets/phue/PhCard.e5f6g7h8.phue.js'
 * });
 */
export async function loadPhueWhitelist(whitelist = {}) {
    const entries = Object.entries(whitelist);

    const resolvedEntries = await Promise.all(
        entries.map(async ([name, url]) => {
            const component = await loadPhueComponent(url);
            return [name, component];
        })
    );

    return Object.fromEntries(resolvedEntries);
}