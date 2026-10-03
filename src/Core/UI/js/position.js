/**
 * Shared positioning and viewport tracking utilities for Phue floating UI primitives.
 *
 * Rules:
 * 1. No imports
 * 2. No top-level window/document references
 * 3. No module-level mutable state
 * 4. Every export MUST use function declaration (export function name() {})
 * 5. Unique export names
 */

/**
 * Calculates absolute layout coordinates for a floating element relative to an anchor rect.
 * Pure function: accepts viewport and scroll coordinates explicitly for SSR safety and unit testability.
 *
 * @param {Object|null} anchor Viewport coordinates { left, top, right, bottom, width, height }
 * @param {Object} [options] Placement and measurement configuration options
 * @returns {Object} Output positioning metadata { left, top, width, flipped, measured }
 */
export function positionFor(anchor, options = {}) {
    const defaultGap = options.gap !== undefined ? options.gap : 4;
    
    const opts = {
        size: options.size || { width: 0, height: 0 },
        placement: options.placement || 'bottom',
        gap: defaultGap,
        offset: options.offset !== undefined ? options.offset : defaultGap,
        allowFlip: options.allowFlip !== undefined ? options.allowFlip : true,
        align: options.align || 'start',
        minWidth: options.minWidth !== undefined ? options.minWidth : null,
        clamp: options.clamp !== undefined ? options.clamp : false,
        margin: options.margin !== undefined ? options.margin : 0,
        viewport: options.viewport || { width: 0, height: 0 },
        scroll: options.scroll || { x: 0, y: 0 },
        measured: options.measured !== undefined ? options.measured : true
    };

    // Guard against null/falsy anchor or explicit unmeasured flag
    if (!anchor || !opts.measured) {
        const fallbackWidth = anchor && anchor.width ? anchor.width : 0;
        return {
            left: 0,
            top: 0,
            width: opts.minWidth !== null ? Math.max(fallbackWidth, opts.minWidth) : fallbackWidth,
            flipped: false,
            measured: false
        };
    }

    const anchorWidth = anchor.width || 0;
    const anchorHeight = anchor.height || 0;
    const anchorLeft = anchor.left || 0;
    const anchorTop = anchor.top || 0;
    const anchorRight = anchor.right !== undefined ? anchor.right : (anchorLeft + anchorWidth);
    const anchorBottom = anchor.bottom !== undefined ? anchor.bottom : (anchorTop + anchorHeight);

    const panelWidth = opts.size.width || 0;
    const panelHeight = opts.size.height || 0;

    let targetWidth = anchorWidth;
    if (opts.minWidth !== null) {
        targetWidth = Math.max(anchorWidth, opts.minWidth);
    }

    let placement = opts.placement;
    let flipped = false;

    // Flip evaluation
    if (opts.allowFlip && opts.viewport.width > 0 && opts.viewport.height > 0) {
        const roomBelow = opts.viewport.height - anchorBottom - opts.gap - opts.margin;
        const roomAbove = anchorTop - opts.gap - opts.margin;
        const roomRight = opts.viewport.width - anchorRight - opts.gap - opts.margin;
        const roomLeft = anchorLeft - opts.gap - opts.margin;

        if (placement === 'bottom' && panelHeight > 0 && roomBelow < panelHeight && roomAbove > roomBelow) {
            placement = 'top';
            flipped = true;
        } else if (placement === 'top' && panelHeight > 0 && roomAbove < panelHeight && roomBelow > roomAbove) {
            placement = 'bottom';
            flipped = true;
        } else if ((placement === 'right' || placement === 'end') && panelWidth > 0) {
            if (roomRight < panelWidth && roomLeft > roomRight) {
                placement = placement === 'right' ? 'left' : 'start';
                flipped = true;
            }
        } else if ((placement === 'left' || placement === 'start') && panelWidth > 0) {
            if (roomLeft < panelWidth && roomRight > roomLeft) {
                placement = placement === 'left' ? 'right' : 'end';
                flipped = true;
            }
        }
    }

    // Vertical Top Calculation (document space)
    let top = 0;
    if (placement === 'bottom') {
        top = anchorBottom + opts.offset + opts.scroll.y;
    } else if (placement === 'top') {
        top = anchorTop - panelHeight - opts.offset + opts.scroll.y;
    } else if (placement === 'right' || placement === 'left' || placement === 'start' || placement === 'end') {
        if (opts.align === 'start') {
            top = anchorTop + opts.scroll.y;
        } else if (opts.align === 'end') {
            top = anchorBottom - panelHeight + opts.scroll.y;
        } else {
            top = anchorTop + (anchorHeight / 2) - (panelHeight / 2) + opts.scroll.y;
        }
    }

    // Horizontal Left Calculation (document space)
    let left = 0;
    if (placement === 'start' || placement === 'left') {
        left = anchorLeft - panelWidth - opts.offset + opts.scroll.x;
    } else if (placement === 'end' || placement === 'right') {
        left = anchorRight + opts.offset + opts.scroll.x;
    } else {
        // 'top' or 'bottom'
        if (opts.align === 'start') {
            left = anchorLeft + opts.scroll.x;
        } else if (opts.align === 'end') {
            left = anchorRight - panelWidth + opts.scroll.x;
        } else {
            left = anchorLeft + (anchorWidth / 2) - (panelWidth / 2) + opts.scroll.x;
        }
    }

    // Clamp adjustment (applied strictly when clamp is true)
    if (opts.clamp && opts.viewport.width > 0 && opts.viewport.height > 0) {
        // Clamp against the width that will actually be rendered. A consumer that
        // sets minWidth has committed to painting the panel at `width` (targetWidth),
        // so bounding by the measured size instead would leave the panel hanging
        // past the edge by exactly the difference — the clamp would look applied
        // while doing nothing. With no minWidth the panel keeps its measured size,
        // because a consumer that ignores `width` (a menu that sizes to its content)
        // is the one that panelWidth describes.
        const clampWidth = opts.minWidth !== null ? targetWidth : panelWidth;
        const minLeft = opts.margin + opts.scroll.x;
        const maxLeft = opts.viewport.width - clampWidth - opts.margin + opts.scroll.x;
        left = Math.max(minLeft, Math.min(left, maxLeft));

        const minTop = opts.margin + opts.scroll.y;
        const maxTop = opts.viewport.height - panelHeight - opts.margin + opts.scroll.y;
        top = Math.max(minTop, Math.min(top, maxTop));
    }

    return {
        left,
        top,
        width: targetWidth,
        flipped,
        measured: true
    };
}

/**
 * Attaches scroll (capture phase) and resize listeners to monitor viewport changes with rAF coalescing.
 *
 * @param {Function} onChange Callback executed on viewport changes
 * @returns {Object} Handle object containing state and cleanup method
 */
export function trackViewport(onChange) {
    const handle = {
        rafId: null,
        active: true,
        running: false,
        onScroll: null,
        onResize: null
    };

    if (typeof window === 'undefined') {
        return handle;
    }

    const scheduleUpdate = function() {
        if (!handle.active || handle.running) {
            return;
        }

        handle.running = true;

        if (typeof requestAnimationFrame === 'function') {
            handle.rafId = requestAnimationFrame(function() {
                handle.running = false;
                if (handle.active && typeof onChange === 'function') {
                    onChange();
                }
            });
        } else {
            handle.running = false;
            if (handle.active && typeof onChange === 'function') {
                onChange();
            }
        }
    };

    handle.onScroll = scheduleUpdate;
    handle.onResize = scheduleUpdate;

    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
        window.addEventListener('scroll', handle.onScroll, true);
        window.addEventListener('resize', handle.onResize);
    }

    return handle;
}

/**
 * Idempotently releases scroll and resize listeners attached by trackViewport.
 *
 * @param {Object} handle Handle object returned by trackViewport
 */
export function releaseViewport(handle) {
    if (!handle || !handle.active) {
        return;
    }

    handle.active = false;
    handle.running = false;

    if (handle.rafId !== null && typeof cancelAnimationFrame === 'function') {
        cancelAnimationFrame(handle.rafId);
        handle.rafId = null;
    }

    if (typeof window !== 'undefined' && typeof window.removeEventListener === 'function') {
        if (handle.onScroll) {
            window.removeEventListener('scroll', handle.onScroll, true);
        }
        if (handle.onResize) {
            window.removeEventListener('resize', handle.onResize);
        }
    }
}

/**
 * Safely extracts viewport-relative bounding rect coordinates from a DOM element.
 * Returns null if the target element is unmeasurable or unmounted.
 *
 * @param {Element|null} el Target DOM element to measure
 * @returns {Object|null} Measured bounding rectangle coordinates or null
 */
export function measureAnchor(el) {
    if (!el || typeof el.getBoundingClientRect !== 'function') {
        return null;
    }

    const rect = el.getBoundingClientRect();
    return {
        left: rect.left,
        top: rect.top,
        right: rect.right,
        bottom: rect.bottom,
        width: rect.width,
        height: rect.height
    };
}
/**
 * The containers a teleported panel can find itself inside, and the layer to use
 * when the container's own layer cannot be read.
 *
 * `.modal` covers all three of the kit's modal surfaces: PhModal, PhConfirmDialog
 * (which rolls its own `modal fade show d-block` markup rather than reusing
 * PhModal) and PhCommandPalette (which renders a PhModal). `[data-phui="drawer"]`
 * is PhDrawer's panel.
 *
 * The toast region is deliberately absent. It has no stable hook of its own, and a
 * form control inside a toast is not a shape this kit supports; adding a selector
 * for it would be a guess. The list is exported, so extending it is one entry.
 *
 * @type {Array<{selector: string, layer: number}>}
 */
export const OVERLAY_CONTAINERS = [
    // One step above the modal's own 1055, and below the popover at 1070 so a
    // popover opened from inside the same dialog still wins.
    { selector: '.modal', layer: 1056 },
    // One step above the drawer panel's 1046.
    { selector: '[data-phui="drawer"]', layer: 1047 }
];

/**
 * The stacking layer a teleported panel needs in order to stay visible.
 *
 * A panel that teleports to `body` leaves the stacking context of whatever held
 * its trigger. That is the point — it is how the panel escapes a scrolling
 * ancestor's clipping — but it also means the panel and its former host are now
 * siblings competing on z-index alone. Teleporting out of a modal into a lower
 * layer does not put the panel "beside" the modal: the modal's own surface is
 * opaque and paints over it, so a select inside a modal opens its options
 * *underneath* the dialog, which reads to the user as a control that does nothing.
 *
 * So the layer is not a constant, it is a function of the container that was
 * escaped: paint one step above it.
 *
 * The container's live z-index is preferred over the documented one, so a
 * Bootstrap minor bump cannot leave this quietly wrong. The documented layer is
 * the fallback, and it is a fallback rather than a preference on purpose: without
 * a stylesheet `getComputedStyle` reports `auto` for everything, and returning
 * the base layer there would restore the exact bug this exists to fix — silently,
 * as "the options do not appear".
 *
 * @param {Element|null} anchorEl The element the panel is anchored to (its trigger)
 * @param {number} baseLayer Layer to use when the trigger is not inside a container
 * @param {Array<{selector: string, layer: number}>} [containers] Overlay containers
 * @returns {number} The layer the panel should paint at
 */
export function overlayLayerFor(anchorEl, baseLayer, containers = OVERLAY_CONTAINERS) {
    if (!anchorEl || typeof anchorEl.closest !== 'function') {
        return baseLayer;
    }

    let container = null;
    let documented = null;
    for (const entry of containers) {
        const found = anchorEl.closest(entry.selector);
        if (found) {
            container = found;
            documented = entry.layer;
            break;
        }
    }

    if (!container) {
        return baseLayer;
    }

    const view = typeof window !== 'undefined' ? window : null;
    if (view && typeof view.getComputedStyle === 'function') {
        const z = parseInt(view.getComputedStyle(container).zIndex, 10);
        // NaN means 'auto', which is the no-stylesheet case and is handled below.
        if (Number.isFinite(z)) {
            // A container the base layer already clears needs nothing done to it.
            return z >= baseLayer ? z + 1 : baseLayer;
        }
    }

    return Math.max(documented || 0, baseLayer);
}
