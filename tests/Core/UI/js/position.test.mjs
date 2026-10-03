/**
 * position.test.mjs — behavioural contract for `src/Core/UI/js/position.js`.
 *
 * positionFor() is a pure function by design (viewport and scroll are passed
 * explicitly for SSR safety), so these cases drive it with synthetic rects and
 * assert exact coordinates. measureAnchor() and trackViewport() are driven
 * against the DOM double.
 *
 * The `start`/`end` cases deliberately pin the *current* contract
 * (`start` behaves as `left`, `end` behaves as `right`) so the behaviour cannot
 * drift silently. It is documented, not blessed.
 */

import { Harness } from './dom-double.mjs';

const jsDir = process.argv[3];
const { positionFor, measureAnchor, trackViewport, releaseViewport, overlayLayerFor, OVERLAY_CONTAINERS } = await import(`${jsDir}/position.js`);

const h = new Harness();

const VP = { width: 1000, height: 800 };

/** Anchor rect helper. */
function rect(l, t, w, hh) {
    return { left: l, top: t, width: w, height: hh, right: l + w, bottom: t + hh };
}

/* ------------------------------------------------------------------ *
 * Degenerate input
 * ------------------------------------------------------------------ */

h.suite('positionFor/guards');

{
    const r = positionFor(null, { viewport: VP });
    h.eq('null anchor -> measured=false', r.measured, false);
    h.eq('null anchor -> left 0', r.left, 0);
    h.eq('null anchor -> top 0', r.top, 0);
    h.eq('null anchor -> not flipped', r.flipped, false);

    const r2 = positionFor(rect(0, 0, 0, 0), { viewport: VP });
    h.eq('zero-rect anchor -> measured=true (it was measured, just empty)', r2.measured, true);
    h.eq('zero-rect anchor -> width 0', r2.width, 0);

    const r3 = positionFor(rect(10, 10, 50, 20), { viewport: VP, measured: false });
    h.eq('measured:false short-circuits', r3.measured, false);
    h.eq('measured:false -> left 0', r3.left, 0);

    const r4 = positionFor(null, { viewport: VP, minWidth: 120 });
    h.eq('null anchor still honours minWidth', r4.width, 120);

    const r5 = positionFor(rect(10, 10, 50, 20), { viewport: VP, minWidth: 10 });
    h.eq('minWidth below anchor width keeps the anchor width', r5.width, 50);

    const r6 = positionFor(rect(10, 10, 50, 20), { viewport: VP, minWidth: 200 });
    h.eq('minWidth above anchor width widens the output', r6.width, 200);
}

{
    const partial = { left: 30, top: 40, width: 20, height: 10 };
    const r = positionFor(partial, { viewport: VP, placement: 'bottom', offset: 0 });
    h.eq('missing right/bottom are derived from left/top+size', r.top, 50);
    h.eq('align start keeps the anchor left edge', r.left, 30);
}

/* ------------------------------------------------------------------ *
 * Vertical placement
 * ------------------------------------------------------------------ */

h.suite('positionFor/vertical');

{
    const a = rect(100, 200, 80, 40);
    const size = { width: 150, height: 60 };

    const bottom = positionFor(a, { viewport: VP, size, placement: 'bottom' });
    h.eq('bottom -> top = anchorBottom + gap(4)', bottom.top, 244);
    h.eq('bottom -> align start -> left = anchorLeft', bottom.left, 100);

    const top = positionFor(a, { viewport: VP, size, placement: 'top' });
    h.eq('top -> top = anchorTop - panelHeight - gap(4)', top.top, 136);
    h.eq('top -> align start -> left = anchorLeft', top.left, 100);

    const end = positionFor(a, { viewport: VP, size, placement: 'bottom', align: 'end' });
    h.eq('bottom align end -> left = anchorRight - panelWidth', end.left, 30);

    const center = positionFor(a, { viewport: VP, size, placement: 'bottom', align: 'center' });
    h.eq('bottom align center -> left centred on the anchor', center.left, 65);

    const offsetOverride = positionFor(a, { viewport: VP, size, placement: 'bottom', offset: 20, gap: 4 });
    h.eq('offset overrides gap for placement', offsetOverride.top, 260);
}

/* ------------------------------------------------------------------ *
 * Horizontal placement (start/end currently alias left/right)
 * ------------------------------------------------------------------ */

h.suite('positionFor/horizontal');

{
    const a = rect(100, 200, 80, 40);
    const size = { width: 150, height: 60 };

    // allowFlip:false isolates the coordinate math from the flip pass.
    const left = positionFor(a, { viewport: VP, size, placement: 'left', allowFlip: false });
    h.eq('left -> left = anchorLeft - panelWidth - gap', left.left, -54);

    const right = positionFor(a, { viewport: VP, size, placement: 'right', allowFlip: false });
    h.eq('right -> left = anchorRight + gap', right.left, 184);

    const start = positionFor(a, { viewport: VP, size, placement: 'start', allowFlip: false });
    h.eq('start currently aliases left (documented contract)', start.left, left.left);
    h.eq('start -> top = anchorTop with align start', start.top, 200);

    const end = positionFor(a, { viewport: VP, size, placement: 'end', allowFlip: false });
    h.eq('end currently aliases right (documented contract)', end.left, right.left);

    const hCenter = positionFor(a, { viewport: VP, size, placement: 'right', align: 'center', allowFlip: false });
    h.eq('horizontal align center -> vertically centred', hCenter.top, 190);

    const hEnd = positionFor(a, { viewport: VP, size, placement: 'right', align: 'end', allowFlip: false });
    h.eq('horizontal align end -> bottom edges aligned', hEnd.top, 180);
}

/* ------------------------------------------------------------------ *
 * Flip
 * ------------------------------------------------------------------ */

h.suite('positionFor/flip');

{
    // Near the bottom edge: only ~30px of room below, plenty above.
    const a = rect(100, 750, 80, 20);
    const size = { width: 150, height: 200 };
    const r = positionFor(a, { viewport: VP, size, placement: 'bottom' });
    h.eq('bottom flips to top when room above is larger', r.flipped, true);
    h.eq('flipped top = anchorTop - panelHeight - gap', r.top, 750 - 200 - 4);

    const a2 = rect(100, 10, 80, 20);
    const r2 = positionFor(a2, { viewport: VP, size, placement: 'top' });
    h.eq('top flips to bottom', r2.flipped, true);
    h.eq('flipped bottom = anchorBottom + gap', r2.top, 34);

    const noRoom = positionFor(a, { viewport: VP, size, placement: 'bottom', allowFlip: false });
    h.eq('allowFlip=false disables flipping', noRoom.flipped, false);
    h.eq('allowFlip=false keeps the requested placement', noRoom.top, 774);
}

{
    // Regression: an unmeasured panel (height 0) must never trigger a flip.
    const a = rect(100, 750, 80, 20);
    const unmeasured = positionFor(a, { viewport: VP, size: { width: 150, height: 0 }, placement: 'bottom' });
    h.eq('panelHeight 0 does not flip', unmeasured.flipped, false);

    const a2 = rect(950, 100, 40, 20);
    const unmeasuredH = positionFor(a2, { viewport: VP, size: { width: 0, height: 60 }, placement: 'end' });
    h.eq('panelWidth 0 does not flip horizontally', unmeasuredH.flipped, false);
}

{
    // Near the right edge: flips end -> start.
    const a = rect(950, 100, 40, 20);
    const size = { width: 300, height: 60 };
    const r = positionFor(a, { viewport: VP, size, placement: 'end' });
    h.eq('end flips to start', r.flipped, true);
    h.eq('flipped start -> left = anchorLeft - panelWidth - gap', r.left, 950 - 300 - 4);

    const r2 = positionFor(a, { viewport: VP, size, placement: 'right' });
    h.eq('right flips to left', r2.flipped, true);
    h.eq('flipped left -> left = anchorLeft - panelWidth - gap', r2.left, 646);

    const a3 = rect(10, 100, 40, 20);
    const r3 = positionFor(a3, { viewport: VP, size, placement: 'start' });
    h.eq('start flips to end', r3.flipped, true);
    h.eq('flipped end -> left = anchorRight + gap', r3.left, 54);
}

{
    const a = rect(100, 750, 80, 20);
    const noViewport = positionFor(a, { viewport: { width: 0, height: 0 }, size: { width: 150, height: 200 } });
    h.eq('zero-size viewport never flips', noViewport.flipped, false);
}

/* ------------------------------------------------------------------ *
 * Clamp + scroll
 * ------------------------------------------------------------------ */

h.suite('positionFor/clamp-and-scroll');

{
    // An anchor that genuinely overflows the viewport, and flipping disabled, so
    // the clamp pass is the only thing under test.
    const a = rect(950, 700, 100, 40);
    const size = { width: 300, height: 300 };
    const base = { viewport: VP, size, placement: 'bottom', align: 'end', allowFlip: false };

    const clamped = positionFor(a, { ...base, clamp: true });
    h.eq('clamp pulls the panel back inside horizontally', clamped.left, 700);
    h.eq('clamp pulls the panel back inside vertically', clamped.top, 500);

    const marginClamped = positionFor(a, { ...base, clamp: true, margin: 16 });
    h.eq('clamp honours the margin horizontally', marginClamped.left, 684);
    h.eq('clamp honours the margin vertically', marginClamped.top, 484);

    const unclamped = positionFor(a, { ...base, clamp: false });
    h.eq('clamp=false leaves left overflowing', unclamped.left, 750);
    h.eq('clamp=false leaves top overflowing', unclamped.top, 744);

    const scrolled = positionFor(a, { ...base, clamp: false, scroll: { x: 40, y: 120 } });
    h.eq('scroll.x is added to left', scrolled.left, 790);
    h.eq('scroll.y is added to top', scrolled.top, 864);

    const scrollClamped = positionFor(a, { ...base, clamp: true, scroll: { x: 40, y: 120 } });
    h.eq('clamp is scroll-aware horizontally', scrollClamped.left, 740);
    h.eq('clamp is scroll-aware vertically', scrollClamped.top, 620);

    // Clamp corrects overflow on *both* sides. `placement: 'left'` with the
    // anchor near the left edge produces negative coordinates, which is the
    // only way to exercise the min-side of the clamp.
    const nearLeftEdge = rect(10, 100, 40, 20);
    const negative = positionFor(nearLeftEdge, {
        viewport: VP,
        size: { width: 150, height: 60 },
        placement: 'left',
        allowFlip: false,
        clamp: true,
    });
    h.eq('a negative coordinate is pulled to the viewport origin', negative.left, 0);

    const nearOrigin = positionFor(nearLeftEdge, {
        viewport: VP,
        size: { width: 10, height: 10 },
        placement: 'bottom',
        align: 'start',
        allowFlip: false,
        clamp: true,
    });
    h.eq('a panel that fits is left untouched', nearOrigin.top, 124);
    h.eq('a panel that fits keeps its left edge', nearOrigin.left, 10);
}

/* ------------------------------------------------------------------ *
 * measureAnchor
 * ------------------------------------------------------------------ */

h.suite('measureAnchor');

{
    h.installDom();

    h.eq('measureAnchor(null) returns null', measureAnchor(null), null);
    h.eq('measureAnchor(undefined) returns null', measureAnchor(undefined), null);
    h.eq('measureAnchor(non-element) returns null', measureAnchor({}), null);

    const el = h.el('div', { rect: { left: 12, top: 34, width: 56, height: 78 } });
    const m = measureAnchor(el);
    h.eq('rect.left passthrough', m.left, 12);
    h.eq('rect.top passthrough', m.top, 34);
    h.eq('rect.width passthrough', m.width, 56);
    h.eq('rect.height passthrough', m.height, 78);
    h.eq('right derived when absent', m.right, 68);
    h.eq('bottom derived when absent', m.bottom, 112);
}

/* ------------------------------------------------------------------ *
 * trackViewport / releaseViewport
 * ------------------------------------------------------------------ */

h.suite('trackViewport');

{
    h.installDom();
    const handle = trackViewport(() => {});
    h.ok('handle is returned', typeof handle === 'object' && handle !== null);
    h.ok('scroll listener attached (capture)', handle.onScroll !== null);
    h.ok('resize listener attached', handle.onResize !== null);

    const scroll = h.win.listenersFor('scroll');
    h.eq('exactly one scroll listener', scroll.length, 1);
    h.eq('the scroll listener is capture-phase (nested scrollers)', scroll[0].capture, true);
    h.eq('exactly one resize listener', h.win.listenersFor('resize').length, 1);

    releaseViewport(handle);
    h.eq('handle is deactivated', handle.active, false);
    h.eq('the scroll listener is removed', h.win.listenersFor('scroll').length, 0);
    h.eq('the resize listener is removed', h.win.listenersFor('resize').length, 0);
    releaseViewport(handle);
    h.eq('release is idempotent', handle.active, false);
}

{
    h.installDom();
    let calls = 0;
    const handle = trackViewport(() => { calls += 1; });

    h.win.dispatchScroll();
    h.win.dispatchScroll();
    h.win.dispatchScroll();
    h.eq('burst of scroll events is coalesced into one rAF callback', calls, 0);

    await new Promise((resolve) => setTimeout(resolve, 20));
    h.eq('the coalesced callback ran exactly once', calls, 1);

    h.win.dispatchScroll();
    await new Promise((resolve) => setTimeout(resolve, 20));
    h.eq('a later scroll fires again', calls, 2);

    releaseViewport(handle);
    h.win.dispatchScroll();
    await new Promise((resolve) => setTimeout(resolve, 20));
    h.eq('no callbacks after release', calls, 2);
}

{
    // Regression: trackViewport() must not throw when `window` is absent (SSR / bun).
    h.installDom();
    const saved = globalThis.window;
    delete globalThis.window;

    let threw = false;
    let handle = null;
    try {
        handle = trackViewport(() => {});
    } catch (e) {
        threw = true;
    }
    globalThis.window = saved;

    h.ok('trackViewport does not throw without a window', !threw);
    h.ok('trackViewport still returns a handle', handle !== null && typeof handle === 'object');
    h.eq('the handle is inert', handle.active, true);
    releaseViewport(handle);

    delete globalThis.window;
    let releaseThrew = false;
    try {
        releaseViewport(handle);
    } catch (e) {
        releaseThrew = true;
    }
    globalThis.window = saved;
    h.ok('releaseViewport does not throw without a window', !releaseThrew);
}

{
    // Regression: the clamp used to bound `left` by the *measured* panel width even
    // when `minWidth` forced a wider panel, so the panel was left hanging past the
    // viewport edge by exactly the difference. The bound has to be the width the
    // caller will actually paint.
    const vp = { width: 1024, height: 800 };
    const anchor = rect(800, 100, 320, 40);   // right edge of the anchor: 1120, past the viewport

    // minWidth wins over the measured size, which is narrower than what will render.
    const r1 = positionFor(anchor, {
        placement: 'bottom',
        align: 'start',
        size: { width: 272, height: 320 },
        minWidth: 272,
        viewport: vp,
        scroll: { x: 0, y: 0 },
        clamp: true,
        margin: 8
    });
    h.eq('minWidth above the measured size widens the output', r1.width, 320);
    h.eq('clamp bounds by the painted width, not the measured one', r1.left, 1024 - 320 - 8);
    h.eq('the painted panel fits the viewport exactly', r1.left + r1.width, 1024 - 8);

    // minWidth between the anchor width and the measured size: the caller paints
    // max(anchor, minWidth) = 200, so the bound must be 200. Bounding by the
    // measured 400 would park the panel 200px further from the edge than it needs
    // to be; bounding by the anchor 100 would leave it hanging past the viewport.
    const r2 = positionFor(rect(900, 100, 100, 40), {
        placement: 'bottom',
        align: 'start',
        size: { width: 400, height: 320 },
        minWidth: 200,
        viewport: vp,
        scroll: { x: 0, y: 0 },
        clamp: true,
        margin: 8
    });
    h.eq('minWidth between anchor and measured sets the painted width', r2.width, 200);
    h.eq('clamp bounds by the painted width, not the measured one', r2.left, 1024 - 200 - 8);
    h.eq('the painted panel sits against the margin', r2.left + r2.width, 1024 - 8);

    // No minWidth at all: a consumer that ignores `width` (a content-sized menu) is
    // the case panelWidth describes, so the bound must not collapse to anchorWidth.
    const r3 = positionFor(rect(900, 100, 100, 40), {
        placement: 'bottom',
        align: 'start',
        size: { width: 400, height: 320 },
        viewport: vp,
        scroll: { x: 0, y: 0 },
        clamp: true,
        margin: 8
    });
    h.eq('clamp bounds by the measured width when minWidth is unset', r3.left, 1024 - 400 - 8);
    h.eq('a content-sized menu still fits', r3.left + 400, 1024 - 8);
}

{
    // A panel that teleports out of a modal lands in the base layer as a sibling of
    // the modal, which paints under its opaque surface: the options are in the DOM
    // and "visible", just behind the dialog. The layer has to clear the container.
    h.installDom();
    const doc = h.doc;

    const build = (containerAttrs, containerStyle) => {
        const container = h.el('div', { attrs: containerAttrs });
        if (containerStyle !== undefined) {
            container.setAttribute('style', containerStyle);
        }
        const trigger = h.el('button', { attrs: { id: 't' } });
        container.appendChild(trigger);
        doc.body.appendChild(container);
        return trigger;
    };

    // The live path: read the container's own z-index.
    h.eq(
        'inside a modal at 1055 paints one step above it',
        overlayLayerFor(build({ class: 'modal' }, 'z-index: 1055'), 1040),
        1056
    );
    h.eq(
        'a restyled modal is followed, not pinned to the documented layer',
        overlayLayerFor(build({ class: 'modal' }, 'z-index: 1200'), 1040),
        1201
    );
    h.eq(
        'inside the drawer panel paints one step above it',
        overlayLayerFor(build({ 'data-phui': 'drawer' }, 'z-index: 1046'), 1040),
        1047
    );

    // The fallback path: no stylesheet, so getComputedStyle reports 'auto'. It must
    // NOT return the base layer — that is precisely the bug this exists to fix, and
    // returning it would restore it silently.
    h.eq(
        'a modal with no resolvable z-index falls back to the documented layer',
        overlayLayerFor(build({ class: 'modal' }), 1040),
        1056
    );
    h.deepEq(
        'the documented layers sit above every container they belong to',
        OVERLAY_CONTAINERS.map((c) => c.layer),
        [1056, 1047]
    );

    // Outside any container: the documented base layer is the contract, because a
    // float on the page must stay under an overlay that is already open.
    const plain = h.el('button');
    doc.body.appendChild(plain);
    h.eq('a trigger outside any container keeps the base layer', overlayLayerFor(plain, 1040), 1040);

    // A container the base layer already clears must not be raised above it.
    h.eq(
        'a container below the base layer does not lower the panel',
        overlayLayerFor(build({ class: 'modal' }, 'z-index: 5'), 1040),
        1040
    );
    h.eq(
        'the documented fallback never lowers the panel below the base layer',
        overlayLayerFor(build({ class: 'modal' }), 5000),
        5000
    );

    // Degenerate input.
    h.eq('a null anchor keeps the base layer', overlayLayerFor(null, 1040), 1040);
    h.eq('an unmeasurable anchor keeps the base layer', overlayLayerFor({}, 1040), 1040);
    h.eq(
        'a selector list that matches nothing keeps the base layer',
        overlayLayerFor(build({ class: 'modal' }, 'z-index: 1055'), 1040, [{ selector: '.nope', layer: 9999 }]),
        1040
    );

    // SSR: no window at all. The container is still detected, so the documented
    // layer is used rather than the base one.
    const savedWindow = globalThis.window;
    const trigger = build({ class: 'modal' }, 'z-index: 1055');
    delete globalThis.window;
    h.eq('without a window the documented layer is used', overlayLayerFor(trigger, 1040), 1056);
    globalThis.window = savedWindow;
}

{
    // Clamping and this are two halves of one decision. Clamp stops an *onscreen*
    // panel from hanging off an edge, but it cannot tell that case from a panel whose
    // trigger has scrolled away — it pins that one to the viewport edge instead, so a
    // menu whose button is two screens below stays open at the bottom of the screen,
    // following nothing.
    const vp = { width: 1000, height: 800 };
    const at = (l, t, w, hh) => rect(l, t, w, hh);
    const off = (a) => positionFor(a, { viewport: vp, clamp: true, margin: 8, size: { width: 200, height: 170 } });

    h.eq('a trigger inside the viewport is not offscreen', off(at(100, 300, 100, 40)).offscreen, false);
    h.eq('a trigger straddling the top edge is not offscreen', off(at(100, -20, 100, 40)).offscreen, false);
    h.eq('a trigger straddling the bottom edge is not offscreen', off(at(100, 780, 100, 40)).offscreen, false);
    h.eq('a trigger below the viewport is offscreen', off(at(100, 900, 100, 40)).offscreen, true);
    h.eq('a trigger far below the viewport is offscreen', off(at(100, 2000, 100, 40)).offscreen, true);
    h.eq('a trigger scrolled off the top is offscreen', off(at(100, -300, 100, 40)).offscreen, true);
    h.eq('a trigger left of the viewport is offscreen', off(at(-400, 300, 100, 40)).offscreen, true);
    h.eq('a trigger right of the viewport is offscreen', off(at(1200, 300, 100, 40)).offscreen, true);

    // The test is in viewport space, so a scrolled document does not change it.
    h.eq(
        'offscreen is viewport-space, not document-space',
        positionFor(at(100, 900, 100, 40), {
            viewport: vp,
            clamp: true,
            margin: 8,
            size: { width: 200, height: 170 },
            scroll: { x: 0, y: 4000 }
        }).offscreen,
        true
    );

    // An unmeasurable viewport must never hide a panel: nobody could have measured
    // the page, and "hide it" is the wrong answer to that.
    h.eq(
        'a zero-sized viewport is not treated as offscreen',
        positionFor(at(100, 300, 100, 40), { viewport: { width: 0, height: 0 } }).offscreen,
        false
    );

    // The pinned-at-the-ceiling behaviour this exists to stop, pinned as a number.
    const pinned = off(at(100, 2000, 100, 40));
    h.eq('an offscreen panel is still given coordinates', pinned.top, 800 - 170 - 8);
    h.eq('which is exactly why it needs the flag', pinned.offscreen, true);
}

h.finish();