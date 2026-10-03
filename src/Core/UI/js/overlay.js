/**
 * Shared overlay, focus management, and accessibility utilities for Phue primitives.
 *
 * Rules:
 * 1. No imports
 * 2. No top-level window/document references
 * 3. No module-level state that outlives active instances
 * 4. Every export MUST use function declaration (export function name() {})
 * 5. Unique export names
 */

/**
 * The state that has to be common to every overlay on the page, and why it cannot
 * simply be module-level.
 *
 * `PhueComponentLoader` does not hand a component an import of this file. It copies
 * this file's source into the component's own module, so every component that
 * declares `composables: ['overlay']` gets its **own copy** — its own module scope,
 * its own bindings. That is the right call for SSR resolution and for cache busting
 * (see `.opencode/composables.md` §13.1–13.3), and it is invisible as long as a
 * module holds nothing but functions.
 *
 * It stops being invisible the moment a module holds state. A module-level array or
 * WeakMap is per component, not per page, so anything that has to be true *across*
 * components is silently true only within one. Three promises in this file were
 * exactly that:
 *
 *   - `trapStack`      "only the top-most overlay handles Escape/Tab"
 *   - `inertRefcounts` "an element's inert is removed only when ALL traps release it"
 *   - `escapeStack`    "Escape belongs to the top-most dismissable surface"
 *
 * With one overlay each behaves correctly, which is why the defect survived the
 * tests: two modals meant two stacks, so both handled Tab and both handled Escape,
 * and the overlay underneath was released while the one above was still open.
 *
 * So the shared state is published on `globalThis` under a versioned key, and every
 * inlined copy adopts the first copy's object. The version is in the key so two kit
 * versions on one page cannot read each other's stacks — the failure mode there
 * would be a stack of the wrong shape, not a clean error.
 *
 * SSR is unaffected: the server evaluates modules per request, so `globalThis` there
 * is per request too. This is the same reason the store instances do *not* live on
 * `window`, and it is why they may still be module-level — a store has one consumer
 * by construction, while an overlay registry has one per overlay by definition.
 */
const REGISTRY_KEY = '__phueOverlayState_v1';

function overlayRegistry() {
    const scope = typeof globalThis !== 'undefined' ? globalThis : {};

    if (!scope[REGISTRY_KEY]) {
        scope[REGISTRY_KEY] = {
            /** Ordered surfaces that want Escape, newest last. */
            escapeStack: [],
            /** The single window listener that dispatches Escape to the top entry. */
            escapeListener: null,
            /** Active traps, so only the top-most one constrains Tab. */
            trapStack: [],
            /** Inert refcounts, so an element is released only by the last trap. */
            inertRefcounts: typeof WeakMap !== 'undefined' ? new WeakMap() : null
        };
    }

    return scope[REGISTRY_KEY];
}

const shared = overlayRegistry();

/**
 * WeakMap instance used to track refcounts for inert attributes across nested overlay traps.
 * Ensures an element only has its inert attribute removed when ALL active traps targeting it are released.
 *
 * An alias into the shared registry: array and WeakMap identity is stable for the
 * lifetime of the page, so every push/splice/get below reaches the shared object.
 */
const inertRefcounts = shared.inertRefcounts;

/**
 * Active trap stack tracking active focusTrap instances to ensure only the top-most overlay handles Escape/Tab events.
 */
const trapStack = shared.trapStack;

/**
 * Dismissal stack: every surface that should react to Escape, in the order it opened.
 *
 * Separate from `trapStack` on purpose. A trap answers "where may focus go?", which
 * only a modal-shaped surface has an opinion about; a dropdown does not trap focus
 * and must not inert the page, so it cannot be a trap. But it absolutely has an
 * opinion about Escape — and while it handled that on its own `document` keydown
 * listener, the trap's listener was already registered on `window` in the *capture*
 * phase, so the trap always ran first. The dropdown's `stopPropagation()` was
 * therefore dead code against it, and Escape inside a dropdown inside a modal closed
 * the modal and left the dropdown open.
 *
 * `stopPropagation()` cannot fix that ordering from the bubble phase, and would not
 * help on `window` anyway, where it does not stop a sibling listener on the same
 * node. So the ordering is expressed once, here, as data.
 *
 * @type {Array<{handler: Function, owner: Element|null}>}
 */
const escapeStack = shared.escapeStack;

/**
 * Drops entries whose surface has left the DOM.
 *
 * A surface removed without releasing — a v-if that tears the branch down between
 * two ticks — would otherwise sit on top of the stack and swallow every Escape key
 * in the document while looking perfectly inert.
 */
function pruneEscapeStack() {
    for (let i = escapeStack.length - 1; i >= 0; i--) {
        const entry = escapeStack[i];
        if (entry && entry.owner && entry.owner.isConnected === false) {
            escapeStack.splice(i, 1);
        }
    }
}

function dispatchEscape(event) {
    if (!event || event.key !== 'Escape') {
        return;
    }

    pruneEscapeStack();

    const top = escapeStack[escapeStack.length - 1];
    if (top && typeof top.handler === 'function') {
        top.handler(event);
    }
}

/**
 * Makes `handler` the surface that Escape acts on, until released.
 *
 * The last registration wins, which is the whole point: a dropdown opened inside a
 * modal is the surface the user is looking at, so Escape belongs to it, and the
 * modal underneath waits its turn.
 *
 * @param {Function} handler Called with the keydown event
 * @param {Element|null} [owner] Surface this belongs to, for stale-entry pruning
 * @returns {{release: Function}} Idempotent release
 */
export function registerEscapeHandler(handler, owner = null) {
    if (typeof handler !== 'function') {
        return { release: function() {} };
    }

    const entry = { handler: handler, owner: owner };
    escapeStack.push(entry);

    // One listener for the whole stack, installed with the first registration and
    // taken away with the last, so a page with no overlays has no listener at all.
    if (shared.escapeListener === null && typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
        shared.escapeListener = dispatchEscape;
        window.addEventListener('keydown', shared.escapeListener, true);
    }

    return {
        release: function() {
            const idx = escapeStack.indexOf(entry);
            if (idx !== -1) {
                escapeStack.splice(idx, 1);
            }

            // Only the copy that installed the listener may take it away, and only
            // once nothing is left on the stack for the others to answer.
            //
            // Guarded like the registration above it: release() runs from
            // beforeUnmount, which the server also runs, and a bare `window` there
            // is a ReferenceError rather than a no-op. A throw from release would
            // also skip whatever the caller does next — for a float, tearing down
            // its scroll tracking.
            if (escapeStack.length === 0 && shared.escapeListener !== null) {
                if (typeof window !== 'undefined' && typeof window.removeEventListener === 'function') {
                    window.removeEventListener('keydown', shared.escapeListener, true);
                }
                shared.escapeListener = null;
            }
        }
    };
}

function setInertWithRefcount(el) {
    if (!el || typeof el.setAttribute !== 'function') {
        return false;
    }

    if (!inertRefcounts) {
        if (!el.hasAttribute('inert')) {
            el.setAttribute('inert', '');
            return true;
        }
        return false;
    }

    const currentCount = inertRefcounts.get(el) || 0;
    inertRefcounts.set(el, currentCount + 1);

    if (currentCount === 0) {
        el.setAttribute('inert', '');
        return true;
    }

    return false;
}

function removeInertWithRefcount(el) {
    if (!el || typeof el.removeAttribute !== 'function') {
        return;
    }

    if (!inertRefcounts) {
        el.removeAttribute('inert');
        return;
    }

    const currentCount = inertRefcounts.get(el) || 0;
    if (currentCount <= 1) {
        inertRefcounts.delete(el);
        el.removeAttribute('inert');
    } else {
        inertRefcounts.set(el, currentCount - 1);
    }
}

/**
 * Queries and returns all visible, enabled, focusable DOM elements within a root container.
 * Optimized to eliminate layout thrashing inside keydown event handlers.
 *
 * @param {Element|null} root Container DOM element to scan
 * @returns {Array<Element>} Array of focusable DOM elements
 */
export function focusableWithin(root) {
    if (!root || typeof root.querySelectorAll !== 'function') {
        return [];
    }

    const selector = [
        'a[href]',
        'button:not([disabled])',
        'textarea:not([disabled])',
        'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])',
        '[contenteditable]',
        '[tabindex]:not([tabindex="-1"])'
    ].join(', ');

    const candidates = Array.from(root.querySelectorAll(selector));

    return candidates.filter(function(el) {
        if (!el || el.disabled) {
            return false;
        }

        // Exclude elements inside aria-hidden="true" subtrees
        if (typeof el.closest === 'function' && el.closest('[aria-hidden="true"]')) {
            return false;
        }

        if (typeof el.checkVisibility === 'function') {
            return el.checkVisibility({ checkOpacity: false, checkVisibilityCSS: true });
        }

        return el.getClientRects().length > 0;
    });
}

/**
 * Handles Tab key navigation to constrain focus within a root container.
 * Only calls preventDefault() when focus actually wraps, with explicit exceptions for 0 and 1 focusable elements.
 *
 * @param {KeyboardEvent} event Keydown event object
 * @param {Element} root Container DOM element acting as focus trap boundary
 */
export function trapTab(event, root) {
    if (!event || event.key !== 'Tab' || !root) {
        return;
    }

    const focusables = focusableWithin(root);

    if (focusables.length === 0) {
        event.preventDefault();
        if (typeof root.focus === 'function') {
            root.focus();
        }
        return;
    }

    if (focusables.length === 1) {
        event.preventDefault();
        if (document.activeElement !== focusables[0] && typeof focusables[0].focus === 'function') {
            focusables[0].focus();
        }
        return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    const active = typeof document !== 'undefined' ? document.activeElement : null;

    if (!active || typeof root.contains !== 'function' || !root.contains(active)) {
        event.preventDefault();
        if (event.shiftKey) {
            if (typeof last.focus === 'function') last.focus();
        } else {
            if (typeof first.focus === 'function') first.focus();
        }
        return;
    }

    if (event.shiftKey && active === first) {
        event.preventDefault();
        if (typeof last.focus === 'function') {
            last.focus();
        }
    } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        if (typeof first.focus === 'function') {
            first.focus();
        }
    }
}

/**
 * Initializes a focus trap on a root element, applying inert attributes to background siblings
 * with refcounting and restoring focus safely upon release.
 *
 * @param {Element|null} root Container DOM element acting as the overlay boundary
 * @param {Object} [options] Configuration options { onEscape, initialFocus }
 * @returns {Object} Object containing the idempotent release() method
 */
export function focusTrap(root, options = {}) {
    if (!root) {
        if (typeof console !== 'undefined' && typeof console.warn === 'function') {
            console.warn('[Phue overlay] focusTrap called with null/falsy root element. Ensure template refs are populated (e.g. inside $nextTick) before calling focusTrap.');
        }
        return {
            release: function() {}
        };
    }

    const state = {
        active: true,
        previousActiveElement: null,
        inertElements: [],
        listener: null,
        escape: null
    };

    if (typeof document === 'undefined') {
        return {
            release: function() {}
        };
    }

    if (document.activeElement) {
        state.previousActiveElement = document.activeElement;
    }

    // Release stale entries from trapStack whose root elements have disconnected from the DOM.
    // Dropping the entry alone is not enough: a leaked trap still holds its window keydown
    // listener and still holds `inert` refcounts on background elements, so the page would
    // stay inert (unclickable, untabbable) until a hard reload. Re-entering release() tears
    // down the listener, the refcounts, the entry and restores focus.
    //
    // This MUST run before the sibling scan below: a leaked trap still has its `inert`
    // attributes applied, and the scan skips elements that are already inert (those belong
    // to the application). Pruning afterwards would therefore make this trap skip a
    // background element it is responsible for.
    for (let i = trapStack.length - 1; i >= 0; i--) {
        const stale = trapStack[i];

        if (stale && stale.root && stale.root.isConnected) {
            continue;
        }

        if (stale && typeof stale.release === 'function') {
            stale.release();
        } else if (stale) {
            trapStack.splice(i, 1);
        }
    }

    // Mark sibling DOM nodes of the teleported root/overlay as inert with refcounting
    if (root.parentElement) {
        const siblings = Array.from(root.parentElement.children);
        siblings.forEach(function(sibling) {
            const isBackdrop = sibling.classList.contains('modal-backdrop') || 
                               sibling.classList.contains('phue-backdrop') ||
                               sibling.hasAttribute('data-phue-backdrop');

            if (sibling === root || isBackdrop) {
                return;
            }

            // An element that is already inert was made inert either by the application or
            // by another trap, and the two have to be told apart. Claiming one the
            // application owns would let release() strip an attribute that is not ours to
            // remove. But *skipping* one that another trap holds is worse than that: the
            // refcount never reaches two, so whichever trap closes first strips `inert`
            // and the page comes alive under a dialog that is still open.
            //
            // The refcount map is the discriminator: an entry means a trap holds it, no
            // entry means the application owns it.
            const heldByTrap = inertRefcounts ? (inertRefcounts.get(sibling) || 0) > 0 : false;
            if (sibling.hasAttribute('inert') && !heldByTrap) {
                return;
            }

            // Counted and recorded together so every increment has exactly one matching
            // decrement, which is what keeps the refcount (and `inert`) from getting stuck.
            setInertWithRefcount(sibling);
            state.inertElements.push(sibling);
        });
    }

    const trapInstance = { root: root, state: state, release: null };

    trapStack.push(trapInstance);

    // Escape is dispatched by the shared dismissal stack rather than here, so a
    // surface opened inside this dialog gets it first. Tab stays here: only a trap
    // constrains Tab, and only the topmost trap should.
    state.escape = registerEscapeHandler(function(event) {
        if (typeof options.onEscape === 'function') {
            options.onEscape(event);
        }
    }, root);

    state.listener = function(event) {
        if (!state.active || !event) {
            return;
        }

        // Active Trap Stack Contract — Only the top active trap processes key events
        const topTrap = trapStack[trapStack.length - 1];
        if (topTrap !== trapInstance) {
            return;
        }

        if (event.key === 'Tab') {
            trapTab(event, root);
        }
    };

    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
        window.addEventListener('keydown', state.listener, true);
    }

    // Initial focus placement
    let targetElement = null;

    if (options.initialFocus) {
        if (typeof options.initialFocus === 'string') {
            targetElement = root.querySelector(options.initialFocus);
        } else if (typeof Element !== 'undefined' && options.initialFocus instanceof Element) {
            targetElement = options.initialFocus;
        }
    }

    if (!targetElement) {
        const focusables = focusableWithin(root);
        if (focusables.length > 0) {
            targetElement = focusables[0];
        } else {
            targetElement = root;
        }
    }

    if (targetElement && typeof targetElement.focus === 'function') {
        targetElement.focus();
    }

    const handle = {
        release: function() {
            if (!state.active) {
                return;
            }

            state.active = false;

            const idx = trapStack.indexOf(trapInstance);
            if (idx !== -1) {
                trapStack.splice(idx, 1);
            }

            if (state.escape && typeof state.escape.release === 'function') {
                state.escape.release();
                state.escape = null;
            }

            if (typeof window !== 'undefined' && typeof window.removeEventListener === 'function' && state.listener) {
                window.removeEventListener('keydown', state.listener, true);
                state.listener = null;
            }

            state.inertElements.forEach(function(el) {
                removeInertWithRefcount(el);
            });
            state.inertElements = [];

            if (state.previousActiveElement && typeof document.contains === 'function' && document.contains(state.previousActiveElement)) {
                if (typeof state.previousActiveElement.focus === 'function') {
                    state.previousActiveElement.focus();
                }
            }
            state.previousActiveElement = null;
        }
    };

    // Expose release on the stack entry so a later focusTrap() can tear this trap down
    // when it discovers the root has been detached from the document.
    trapInstance.release = handle.release;

    return handle;
}