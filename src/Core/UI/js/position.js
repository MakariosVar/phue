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
        const minLeft = opts.margin + opts.scroll.x;
        const maxLeft = opts.viewport.width - panelWidth - opts.margin + opts.scroll.x;
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