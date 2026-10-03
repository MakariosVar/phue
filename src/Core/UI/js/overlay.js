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
 * WeakMap instance used to track refcounts for inert attributes across nested overlay traps.
 * Ensures an element only has its inert attribute removed when ALL active traps targeting it are released.
 */
const inertRefcounts = typeof WeakMap !== 'undefined' ? new WeakMap() : null;

/**
 * Active trap stack tracking active focusTrap instances to ensure only the top-most overlay handles Escape/Tab events.
 */
const trapStack = [];

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
        listener: null
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

            // Elements that are already inert were made inert by the application, not by this
            // toolkit. They are deliberately never counted and never released: claiming one
            // would make release() strip an `inert` attribute the application still owns.
            if (sibling.hasAttribute('inert')) {
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

    state.listener = function(event) {
        if (!state.active || !event) {
            return;
        }

        // Active Trap Stack Contract — Only the top active trap processes key events
        const topTrap = trapStack[trapStack.length - 1];
        if (topTrap !== trapInstance) {
            return;
        }

        if (event.key === 'Escape') {
            if (typeof options.onEscape === 'function') {
                options.onEscape(event);
            }
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