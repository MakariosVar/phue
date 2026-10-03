/**
 * overlay.test.mjs — behavioural contract for `src/Core/UI/js/overlay.js`.
 *
 * Executed by PhuiFocusTrapContractTest via PhueJsRunner.
 *
 * Every case here maps to a real failure mode that shipped or nearly shipped:
 * the `inert` refcount desynchronisation that could brick a page, the trap
 * stack that could disable Escape for every subsequent overlay, the leaked
 * trap that kept background elements inert forever, and the
 * `stopImmediatePropagation()` call that silently killed global shortcuts.
 */

import { Harness, makeEvent } from './dom-double.mjs';

const jsDir = process.argv[3];
const { focusTrap, focusableWithin, trapTab } = await import(`${jsDir}/overlay.js`);

const h = new Harness();

/* ------------------------------------------------------------------ *
 * 1. focusableWithin — selector correctness
 * ------------------------------------------------------------------ */

h.suite('focusableWithin');

{
    h.installDom();
    const root = h.el('div');
    const btn = h.el('button');
    const link = h.el('a', { attrs: { href: '#x' } });
    const linkNoHref = h.el('a');
    const disabledBtn = h.el('button', { disabled: true });
    const disabledInput = h.el('input', { disabled: true });
    const hiddenInput = h.el('input', { attrs: { type: 'hidden' } });
    const tabbable = h.el('div', { attrs: { tabindex: '0' } });
    const programmatic = h.el('div', { attrs: { tabindex: '-1' } });
    const editable = h.el('div', { attrs: { contenteditable: 'true' } });
    const area = h.el('textarea');
    const select = h.el('select');
    const hiddenBtn = h.el('button', { visible: false });

    [btn, link, linkNoHref, disabledBtn, disabledInput, hiddenInput,
     tabbable, programmatic, editable, area, select, hiddenBtn].forEach((e) => root.appendChild(e));

    const found = focusableWithin(root);

    h.ok('button is focusable', found.includes(btn));
    h.ok('a[href] is focusable', found.includes(link));
    h.ok('a without href is NOT focusable', !found.includes(linkNoHref));
    h.ok('disabled button excluded', !found.includes(disabledBtn));
    h.ok('disabled input excluded', !found.includes(disabledInput));
    h.ok('input[type=hidden] excluded', !found.includes(hiddenInput));
    h.ok('tabindex="0" focusable', found.includes(tabbable));
    h.ok('tabindex="-1" excluded', !found.includes(programmatic));
    h.ok('contenteditable focusable', found.includes(editable));
    h.ok('textarea focusable', found.includes(area));
    h.ok('select focusable', found.includes(select));
    h.ok('invisible button excluded', !found.includes(hiddenBtn));
}

{
    h.installDom();
    const root = h.el('div');
    const ariaHidden = h.el('div', { attrs: { 'aria-hidden': 'true' } });
    const inner = h.el('button');
    ariaHidden.appendChild(inner);
    root.appendChild(ariaHidden);

    h.ok('element inside aria-hidden subtree excluded', !focusableWithin(root).includes(inner));
}

{
    h.installDom();
    const root = h.el('div');
    const visibleBtn = h.el('button');
    root.appendChild(visibleBtn);

    const withApi = focusableWithin(root);
    h.eq('checkVisibility path returns the button', withApi.length, 1);

    // Exercise the getClientRects() fallback used on engines without checkVisibility.
    h.disableCheckVisibility();
    const withFallback = focusableWithin(root);
    h.restoreCheckVisibility();
    h.eq('getClientRects fallback returns the button', withFallback.length, 1);

    visibleBtn._setVisible(false);
    h.disableCheckVisibility();
    const hiddenFallback = focusableWithin(root);
    h.restoreCheckVisibility();
    h.eq('getClientRects fallback excludes invisible', hiddenFallback.length, 0);
}

/* ------------------------------------------------------------------ *
 * 2. trapTab
 * ------------------------------------------------------------------ */

h.suite('trapTab');

{
    h.installDom();
    const root = h.el('div', { attrs: { tabindex: '-1' } });
    const ev = makeEvent('Tab');
    trapTab(ev, root);

    h.ok('0 focusables prevents default', ev.defaultPrevented);
    h.eq('0 focusables focuses the container', h.doc.activeElement, root);
}

{
    h.installDom();
    const root = h.el('div');
    const only = h.el('button');
    root.appendChild(only);
    const ev = makeEvent('Tab');
    trapTab(ev, root);

    h.ok('1 focusable prevents default', ev.defaultPrevented);
    h.eq('1 focusable receives focus', h.doc.activeElement, only);

    const ev2 = makeEvent('Tab');
    trapTab(ev2, root);
    h.ok('repeat Tab on the only focusable still prevents default', ev2.defaultPrevented);
}

{
    h.installDom();
    const root = h.el('div');
    const first = h.el('button');
    const mid = h.el('button');
    const last = h.el('button');
    [first, mid, last].forEach((b) => root.appendChild(b));

    mid.focus();
    const midTab = makeEvent('Tab');
    trapTab(midTab, root);
    h.ok('Tab in the middle does NOT prevent default', !midTab.defaultPrevented);

    last.focus();
    const wrap = makeEvent('Tab');
    trapTab(wrap, root);
    h.ok('Tab on last prevents default (wraps)', wrap.defaultPrevented);
    h.eq('Tab on last wraps to first', h.doc.activeElement, first);

    first.focus();
    const back = makeEvent('Tab', { shiftKey: true });
    trapTab(back, root);
    h.ok('Shift+Tab on first prevents default (wraps)', back.defaultPrevented);
    h.eq('Shift+Tab on first wraps to last', h.doc.activeElement, last);
}

{
    h.installDom();
    const root = h.el('div');
    const first = h.el('button');
    const last = h.el('button');
    [first, last].forEach((b) => root.appendChild(b));

    const outside = h.el('button');
    h.doc.body.appendChild(outside);
    outside.focus();

    const ev = makeEvent('Tab');
    trapTab(ev, root);
    h.ok('focus outside root prevents default', ev.defaultPrevented);
    h.eq('focus outside root jumps to first', h.doc.activeElement, first);

    outside.focus();
    const ev2 = makeEvent('Tab', { shiftKey: true });
    trapTab(ev2, root);
    h.eq('Shift+Tab outside root jumps to last', h.doc.activeElement, last);
}

{
    h.installDom();
    const root = h.el('div');
    const ev = makeEvent('Escape');
    trapTab(ev, root);
    h.ok('non-Tab key is ignored', !ev.defaultPrevented);

    h.eq('null root returns undefined', trapTab(makeEvent('Tab'), null), undefined);
}

/* ------------------------------------------------------------------ *
 * 3. focusTrap — inert ownership and refcounting
 * ------------------------------------------------------------------ */

h.suite('focusTrap/inert');

{
    const { doc } = h.installDom();
    const bg = h.el('div');
    const backdrop = h.el('div', { classes: ['modal-backdrop'] });
    const modal = h.el('div', { attrs: { role: 'dialog' } });
    const closeBtn = h.el('button');
    modal.appendChild(closeBtn);
    doc.body.appendChild(bg);
    doc.body.appendChild(backdrop);
    doc.body.appendChild(modal);

    const trap = focusTrap(modal);
    h.ok('background is inert while open', bg.hasAttribute('inert'));
    h.ok('backdrop is NOT inert (stays clickable)', !backdrop.hasAttribute('inert'));
    h.ok('the trap root itself is never inert', !modal.hasAttribute('inert'));
    h.eq('initial focus lands on the first focusable', doc.activeElement, closeBtn);

    trap.release();
    h.ok('background released', !bg.hasAttribute('inert'));
    trap.release();
    h.ok('double release is a no-op', !bg.hasAttribute('inert'));
}

{
    const { doc } = h.installDom();
    const appInert = h.el('div');
    appInert.setAttribute('inert', '');
    const modal = h.el('div');
    doc.body.appendChild(appInert);
    doc.body.appendChild(modal);

    const trap = focusTrap(modal);
    trap.release();
    h.ok('application-owned inert is preserved', appInert.hasAttribute('inert'));
}

{
    const { doc } = h.installDom();
    const modal = h.el('div');
    doc.body.appendChild(modal);
    const trap = focusTrap(modal);
    const other = h.el('div');
    doc.body.appendChild(other);
    const trap2 = focusTrap(other);

    trap2.release();
    h.ok('modal freed once the newer trap released', !modal.hasAttribute('inert'));
    trap.release();
    h.ok('nested stack unwinds cleanly', !other.hasAttribute('inert'));
}

h.suite('focusTrap/nesting');

{
    const { doc } = h.installDom();
    const bg = h.el('div');
    const backdropA = h.el('div', { classes: ['modal-backdrop'] });
    const modalA = h.el('div');
    const backdropB = h.el('div', { classes: ['modal-backdrop'] });
    const modalB = h.el('div');
    doc.body.appendChild(bg);
    doc.body.appendChild(backdropA);
    doc.body.appendChild(modalA);

    const trapA = focusTrap(modalA);
    h.ok('bg inert while outer open', bg.hasAttribute('inert'));

    doc.body.appendChild(backdropB);
    doc.body.appendChild(modalB);
    const trapB = focusTrap(modalB);
    h.ok('outer modal inert while inner open', modalA.hasAttribute('inert'));
    h.ok('inner backdrop not inert', !backdropB.hasAttribute('inert'));

    trapB.release();
    h.ok('outer modal interactive again after inner closes', !modalA.hasAttribute('inert'));
    h.ok('bg STILL inert (outer holds the claim)', bg.hasAttribute('inert'));

    trapA.release();
    h.ok('bg free after outer closes', !bg.hasAttribute('inert'));
}

/* ------------------------------------------------------------------ *
 * 4. Regression: a leaked trap must not keep the page inert forever
 * ------------------------------------------------------------------ */

h.suite('focusTrap/leak-prune');

{
    const { doc } = h.installDom();
    const bg = h.el('div');
    const modalA = h.el('div');
    doc.body.appendChild(bg);
    doc.body.appendChild(modalA);

    focusTrap(modalA);
    h.ok('bg inert after first open', bg.hasAttribute('inert'));

    // Simulate a component unmounting without calling release().
    modalA._parent = null;
    h.ok('detached root reports isConnected=false', modalA.isConnected === false);

    const modalB = h.el('div');
    doc.body.appendChild(modalB);
    const trapB = focusTrap(modalB);
    h.ok('bg inert while the new trap is open', bg.hasAttribute('inert'));

    trapB.release();
    h.ok('bg is freed — the leaked claim was released during prune', !bg.hasAttribute('inert'));
}

/* ------------------------------------------------------------------ *
 * 5. Escape ownership + no propagation kill
 * ------------------------------------------------------------------ */

h.suite('focusTrap/escape');

{
    const { doc, win } = h.installDom();
    const modalA = h.el('div');
    const modalB = h.el('div');
    doc.body.appendChild(modalA);
    doc.body.appendChild(modalB);

    let escapedA = 0;
    let escapedB = 0;
    const trapA = focusTrap(modalA, { onEscape: () => { escapedA += 1; } });
    const trapB = focusTrap(modalB, { onEscape: () => { escapedB += 1; } });

    h.eq('two capture listeners registered', win.listenerCount('keydown'), 2);

    const ev = makeEvent('Escape');
    win.dispatchKeydown(ev);

    h.eq('only the top trap handles Escape', escapedB, 1);
    h.eq('the underlying trap ignored Escape', escapedA, 0);
    h.ok('Escape is NOT immediate-propagation-stopped', !ev.immediatePropagationStopped);
    h.ok('Escape is NOT propagation-stopped', !ev.propagationStopped);

    const ev2 = makeEvent('Tab');
    win.dispatchKeydown(ev2);
    h.eq('Tab reaches the top trap', escapedB, 1);
    h.ok('Tab was handled by trapTab (prevented for an empty trap)', ev2.defaultPrevented);

    trapB.release();
    h.eq('listener removed on release', win.listenerCount('keydown'), 1);

    const ev3 = makeEvent('Escape');
    win.dispatchKeydown(ev3);
    h.eq('previous trap becomes top again', escapedA, 1);

    trapA.release();
    h.eq('all listeners removed', win.listenerCount('keydown'), 0);
}

/* ------------------------------------------------------------------ *
 * 6. initialFocus
 * ------------------------------------------------------------------ */

h.suite('focusTrap/initialFocus');

{
    const { doc } = h.installDom();
    const modal = h.el('div');
    const first = h.el('button');
    const danger = h.el('button', { attrs: { 'data-role': 'danger' } });
    modal.appendChild(first);
    modal.appendChild(danger);
    doc.body.appendChild(modal);

    const trap = focusTrap(modal, { initialFocus: '[data-role="danger"]' });
    h.eq('initialFocus string selector honoured', doc.activeElement, danger);
    trap.release();
}

{
    const { doc } = h.installDom();
    const modal = h.el('div');
    const first = h.el('button');
    const explicit = h.el('button');
    modal.appendChild(first);
    modal.appendChild(explicit);
    doc.body.appendChild(modal);

    const trap = focusTrap(modal, { initialFocus: explicit });
    h.eq('initialFocus Element honoured', doc.activeElement, explicit);
    trap.release();
}

{
    const { doc } = h.installDom();
    const modal = h.el('div');
    const only = h.el('button');
    modal.appendChild(only);
    doc.body.appendChild(modal);

    const trap = focusTrap(modal, { initialFocus: '#missing' });
    h.eq('unmatched initialFocus falls back to first focusable', doc.activeElement, only);
    trap.release();
}

{
    const { doc } = h.installDom();
    const modal = h.el('div', { attrs: { tabindex: '-1' } });
    doc.body.appendChild(modal);

    const trap = focusTrap(modal);
    h.eq('no focusables falls back to the container', doc.activeElement, modal);
    trap.release();
}

/* ------------------------------------------------------------------ *
 * 7. Degenerate inputs / SSR safety
 * ------------------------------------------------------------------ */

h.suite('focusTrap/degenerate');

{
    const { doc } = h.installDom();
    let warned = false;
    const original = console.warn;
    console.warn = () => { warned = true; };
    const handle = focusTrap(null);
    console.warn = original;

    h.ok('null root warns the developer', warned);
    h.ok('null root returns a usable handle', typeof handle.release === 'function');
    handle.release();
}

{
    const savedDocument = globalThis.document;
    delete globalThis.document;
    const handle = focusTrap(h.el('div'));
    globalThis.document = savedDocument;

    h.ok('missing document returns a noop handle', typeof handle.release === 'function');
    handle.release();
}

{
    const { doc } = h.installDom();
    const modal = h.el('div');
    const btn = h.el('button');
    modal.appendChild(btn);
    doc.body.appendChild(modal);

    const savedElement = globalThis.Element;
    delete globalThis.Element;
    let threw = false;
    let handle = null;
    try {
        handle = focusTrap(modal, { initialFocus: btn });
    } catch (e) {
        threw = true;
    }
    globalThis.Element = savedElement;

    h.ok('undefined Element global does not throw (SSR/bun)', !threw);
    h.eq('falls back to the first focusable without Element', doc.activeElement, btn);
    if (handle) {
        handle.release();
    }
}

h.suite('focusTrap/focus-restore');

{
    const { doc } = h.installDom();
    const trigger = h.el('button');
    doc.body.appendChild(trigger);
    trigger.focus();

    const modal = h.el('div');
    const btn = h.el('button');
    modal.appendChild(btn);
    doc.body.appendChild(modal);

    const trap = focusTrap(modal);
    h.eq('focus moved into the trap', doc.activeElement, btn);

    trap.release();
    h.eq('focus restored to the trigger', doc.activeElement, trigger);
}

{
    const { doc } = h.installDom();
    const trigger = h.el('button');
    doc.body.appendChild(trigger);
    trigger.focus();

    const modal = h.el('div');
    const btn = h.el('button');
    modal.appendChild(btn);
    doc.body.appendChild(modal);

    const trap = focusTrap(modal);
    trigger._parent = null;
    modal._parent = null;
    trigger.focus();
    doc.activeElement = doc.body;

    trap.release();
    h.ok('focus is NOT restored to a removed node', doc.activeElement !== trigger);
}

h.finish();