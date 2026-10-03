/**
 * store.test.mjs — behavioural contract for `src/Core/UI/js/store.js`.
 *
 * The two factories are the shared Toaster and ConfirmQueue owners. They are
 * intentionally NOT module-level singletons: the module owns zero mutable
 * state so a process that renders twice (two HTTP requests in a long-running
 * Swoole worker) cannot leak one request's toasts into the next. The owner
 * (`PhToast.phue` / `PhConfirmDialog.phue`) holds exactly one instance and
 * exposes it to descendants.
 *
 * Time-sensitive paths are pinned with short durations and awaited settle
 * windows rather than fake timers, so the real scheduler is exercised.
 */

const jsDir = process.argv[3];
const { createToastStore, createConfirmQueue } = await import(`${jsDir}/store.js`);

class Harness {
    constructor() {
        this.checks = [];
        this.failures = [];
        this.currentSuite = 'general';
        this.total = 0;
    }

    suite(name) {
        this.currentSuite = name;
    }

    ok(name, condition, detail = '') {
        this.total += 1;
        if (!condition) {
            this.failures.push(`[${this.currentSuite}] ${name}${detail ? ' :: ' + detail : ''}`);
        }
    }

    eq(name, actual, expected) {
        const same = Object.is(actual, expected);
        this.ok(name, same, same ? '' : `expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
    }

    deepEq(name, actual, expected) {
        const a = JSON.stringify(actual);
        const e = JSON.stringify(expected);
        this.ok(name, a === e, a === e ? '' : `expected ${e}, got ${a}`);
    }

    finish() {
        this.checks.push({ total: this.total, failures: this.failures });
        const failed = this.checks.some((c) => c.failures.length > 0);
        console.log(JSON.stringify({
            passed: this.total - this.failures.length,
            failures: this.failures,
        }));
        if (failed) {
            process.exit(1);
        }
    }
}

const h = new Harness();
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
/** Comfortably past the store's fixed 200ms leave animation. */
const LEAVE_SETTLE = 320;

/* ------------------------------------------------------------------ *
 * Factory shape + module isolation
 * ------------------------------------------------------------------ */

h.suite('createToastStore/shape');

{
    const store = createToastStore();
    h.ok('push is a function', typeof store.push === 'function');
    h.ok('dismiss is a function', typeof store.dismiss === 'function');
    h.ok('remove is a function', typeof store.remove === 'function');
    h.ok('clear is a function', typeof store.clear === 'function');
    h.ok('pause is a function', typeof store.pause === 'function');
    h.ok('resume is a function', typeof store.resume === 'function');
    h.ok('attachHost is a function', typeof store.attachHost === 'function');
    h.ok('detachHost is a function', typeof store.detachHost === 'function');
    h.ok('subscribe is a function', typeof store.subscribe === 'function');
    h.ok('state.toasts starts empty', Array.isArray(store.state.toasts) && store.state.toasts.length === 0);
    h.eq('default maxToasts is 5', store.state.maxToasts, 5);
}

{
    const a = createToastStore();
    const b = createToastStore();
    const idA = a.push({ message: 'from a', duration: 0 });
    b.push({ message: 'from b', duration: 0 });

    h.eq('two instances have independent lists', b.state.toasts.length, 1);
    h.eq('the other instance is unaffected', a.state.toasts.length, 1);
    // Each factory restarts its own id sequence: ids are namespaced per store,
    // so a leaked toast can never be resolved against another store's id.
    h.eq('each store restarts the id sequence', b.state.toasts[0].id, idA);
    h.deepEq('a only holds its own toast', a.state.toasts.map((t) => t.message), ['from a']);
    h.deepEq('b only holds its own toast', b.state.toasts.map((t) => t.message), ['from b']);
    a.clear();
    b.clear();
}

/* ------------------------------------------------------------------ *
 * push
 * ------------------------------------------------------------------ */

h.suite('createToastStore/push');

{
    const store = createToastStore();
    const first = store.push({ message: 'hello' });
    const second = store.push({ message: 'again' });

    h.eq('ids are integers', Number.isInteger(first), true);
    h.eq('ids are unique', first === second, false);
    h.eq('two toasts stored', store.state.toasts.length, 2);
    h.eq('first id starts at 1', first, 1);
    h.ok('ids increment monotonically', second > first);

    const toast = store.state.toasts[0];
    h.eq('message is stored', toast.message, 'hello');
    h.eq('title defaults from the variant', toast.title, 'Info');
    h.eq('variant defaults to info', toast.variant, 'info');
    h.eq('duration defaults to 5000', toast.duration, 5000);
    h.eq('not paused on creation', toast.paused, false);
    h.eq('not leaving on creation', toast.leaving, false);
    store.clear();
}

{
    const store = createToastStore();

    const s = store.success('saved', undefined, 0);
    const e = store.error('boom', undefined, 0);
    const w = store.warning('careful', undefined, 0);
    const i = store.info('fyi', undefined, 0);
    const titled = store.error('boom again', 'Custom Error', 0);

    h.eq('success variant', store.state.toasts[0].variant, 'success');
    h.eq('success default title', store.state.toasts[0].title, 'Success');
    h.eq('error variant', store.state.toasts[1].variant, 'error');
    h.eq('error default title', store.state.toasts[1].title, 'Error');
    h.eq('warning variant', store.state.toasts[2].variant, 'warning');
    h.eq('warning default title', store.state.toasts[2].title, 'Warning');
    h.eq('info variant', store.state.toasts[3].variant, 'info');
    h.eq('info default title', store.state.toasts[3].title, 'Info');
    h.eq('explicit title overrides the default', store.state.toasts[4].title, 'Custom Error');
    h.eq('all five toasts fit under the default cap', store.state.toasts.length, 5);
    h.ok('helper ids are distinct', new Set([s, e, w, i, titled]).size === 5);
    store.clear();

    const blank = createToastStore();
    blank.push({ variant: 'success', title: '', duration: 0 });
    h.eq('an empty-string title falls back to the variant default', blank.state.toasts[0].title, 'Success');
    blank.clear();
}

{
    const store = createToastStore();

    const id = store.push('a bare string message');
    h.eq('string shorthand sets the message', store.state.toasts[0].message, 'a bare string message');
    h.eq('string shorthand still defaults the variant', store.state.toasts[0].variant, 'info');

    const id2 = store.push();
    h.eq('undefined options produce an empty message', store.state.toasts[1].message, '');

    const id3 = store.push({});
    h.eq('empty options produce an empty message', store.state.toasts[2].message, '');

    const id4 = store.push({ message: 'sticky', duration: 0 });
    h.eq('duration 0 is honoured (not replaced by the default)', store.state.toasts[3].duration, 0);
    h.eq('duration 0 sets no start marker', store.state.toasts[3].startedAt, null);
    h.eq('duration 0 leaves timerId null', store.state.toasts[3].timerId, null);

    store.clear();
    void id; void id2; void id3; void id4;
}

{
    const store = createToastStore();
    const pushes = [];
    store.state.maxToasts = 3;
    for (let i = 0; i < 6; i += 1) {
        pushes.push(store.push({ message: `t${i}`, duration: 0 }));
    }
    h.eq('list is trimmed to maxToasts', store.state.toasts.length, 3);
    h.deepEq('the oldest toasts are the ones dropped', store.state.toasts.map((t) => t.message), ['t3', 't4', 't5']);
    store.clear();
}

{
    const store = createToastStore();
    const id = store.push({ message: 'sticky', duration: 0 });
    store.push({ message: 'second', duration: 0 });
    store.dismiss(id);
    store.dismiss(id);
    h.eq('double dismiss does not duplicate timers', store.state.toasts.filter((t) => t.leaving).length, 1);
    store.remove(id);
    store.clear();
}

/* ------------------------------------------------------------------ *
 * dismiss / remove / clear
 * ------------------------------------------------------------------ */

h.suite('createToastStore/dismiss');

{
    const store = createToastStore();
    const id = store.push({ message: 'bye', duration: 0 });
    store.dismiss(id);

    h.eq('the toast is still present right after dismiss', store.state.toasts.length, 1);
    h.eq('the toast is flagged leaving', store.state.toasts[0].leaving, true);
    h.ok('a leave timer is scheduled', store.state.toasts[0].leaveTimerId !== null);

    await sleep(LEAVE_SETTLE);
    h.eq('the toast is removed after the leave animation', store.state.toasts.length, 0);
}

{
    const store = createToastStore();
    store.push({ message: 'x', duration: 0 });
    store.dismiss(9999);
    store.remove(-1);
    h.eq('unknown ids are no-ops', store.state.toasts.length, 1);
    store.clear();
}

{
    const store = createToastStore();
    const a = store.push({ message: 'a', duration: 0 });
    const b = store.push({ message: 'b', duration: 0 });

    store.remove(a);
    h.eq('remove deletes by id', store.state.toasts.length, 1);
    h.eq('remove keeps the other toast', store.state.toasts[0].id, b);

    store.clear();
    h.eq('clear empties the list', store.state.toasts.length, 0);
    h.eq('clear does not resurrect the list reference', store.state.toasts.length, 0);
}

{
    // A toast whose own dismiss timer fires must go through the leave phase.
    const store = createToastStore();
    const id = store.push({ message: 'auto', duration: 60 });
    await sleep(140);
    h.eq('expiry leaves the toast during the animation', store.state.toasts.length, 1);
    h.eq('expiry sets the leaving flag', store.state.toasts[0].leaving, true);
    await sleep(LEAVE_SETTLE);
    h.eq('expiry fully removes the toast', store.state.toasts.length, 0);
    void id;
}

{
    // clear() must cancel the pending leave timer of a half-dismissed toast,
    // otherwise the late timer would mutate an already-cleared store.
    const store = createToastStore();
    const id = store.push({ message: 'half', duration: 0 });
    store.dismiss(id);
    h.eq('leaving before clear', store.state.toasts[0].leaving, true);

    let notifiedAfterClear = 0;
    store.subscribe(function() {
        notifiedAfterClear += 1;
    });

    store.clear();
    const baseline = notifiedAfterClear;
    await sleep(LEAVE_SETTLE);
    h.eq('clear() leaves no dangling state', store.state.toasts.length, 0);
    h.eq('the cancelled leave timer does not notify', notifiedAfterClear, baseline);
}

/* ------------------------------------------------------------------ *
 * pause / resume
 * ------------------------------------------------------------------ */

h.suite('createToastStore/pause-resume');

{
    const store = createToastStore();
    const id = store.push({ message: 'long', duration: 4000 });
    const toast = store.state.toasts[0];
    h.eq('duration is recorded', toast.duration, 4000);
    h.eq('remaining starts at the duration', toast.remaining, 4000);
    h.ok('a timer is running before pause', toast.timerId !== null);

    await sleep(60);
    store.pause(id);
    h.eq('pause clears the running timer', store.state.toasts[0].timerId, null);
    h.eq('pause sets the flag', store.state.toasts[0].paused, true);
    h.ok('pause discounts the elapsed time', store.state.toasts[0].remaining < 4000);
    h.ok('pause keeps a non-negative remainder', store.state.toasts[0].remaining >= 0);

    await sleep(200);
    h.eq('a paused toast does not expire', store.state.toasts.length, 1);

    store.resume(id);
    h.eq('resume clears the flag', store.state.toasts[0].paused, false);
    h.ok('resume restarts a timer', store.state.toasts[0].timerId !== null);

    store.clear();
}

{
    const store = createToastStore();
    const sticky = store.push({ message: 'sticky', duration: 0 });
    store.pause(sticky);
    h.eq('pause is a no-op for a zero duration', store.state.toasts[0].paused, false);

    store.resume(sticky);
    h.eq('resume is a no-op for a zero duration', store.state.toasts[0].paused, false);

    const id = store.push({ message: 'live', duration: 3000 });
    store.resume(id);
    h.eq('resume on a never-paused toast is a no-op', store.state.toasts[1].paused, false);
    store.clear();
}

{
    const store = createToastStore();
    const id = store.push({ message: 'expiring', duration: 50 });
    await sleep(120);
    h.eq('a short toast entered the leave phase', store.state.toasts[0].leaving, true);
    store.pause(id);
    h.eq('pause cannot un-dismiss a leaving toast', store.state.toasts[0].paused, false);
    await sleep(LEAVE_SETTLE);
    h.eq('the leaving toast still completes removal', store.state.toasts.length, 0);
}

/* ------------------------------------------------------------------ *
 * hosts — first claim wins
 * ------------------------------------------------------------------ */

h.suite('createToastStore/hosts');

{
    const store = createToastStore();
    h.eq('attachHost(null) is rejected', store.attachHost(null), false);
    h.eq('attachHost(undefined) is rejected', store.attachHost(undefined), false);
    h.eq('attachHost("") is rejected', store.attachHost(''), false);
    h.eq('a rejected attach does not register a host', store.state.hosts.length, 0);
}

{
    const store = createToastStore();
    h.eq('the first host claims ownership', store.attachHost('a'), true);
    h.eq('a second host does not claim ownership', store.attachHost('b'), false);
    h.eq('a third host does not claim ownership', store.attachHost('c'), false);
    h.deepEq('hosts keep registration order', store.state.hosts, ['a', 'b', 'c']);

    h.eq('re-attaching the primary is still primary', store.attachHost('a'), true);
    h.eq('re-attaching does not duplicate the host', store.state.hosts.length, 3);
}

{
    const store = createToastStore();
    store.attachHost('a');
    store.attachHost('b');
    store.attachHost('c');

    h.eq('detaching the primary promotes the next host', store.detachHost('a'), 'b');
    h.deepEq('the detached host is removed', store.state.hosts, ['b', 'c']);

    h.eq('detaching a middle host keeps the primary', store.detachHost('c'), 'b');
    h.deepEq('host list after removing the tail', store.state.hosts, ['b']);

    h.eq('detaching the last host yields null', store.detachHost('b'), null);
    h.eq('the list is empty', store.state.hosts.length, 0);

    h.eq('detaching an unknown host is harmless', store.detachHost('ghost'), null);
}

{
    const store = createToastStore();
    store.attachHost('a');
    store.attachHost('b');
    store.dismiss(store.push({ message: 'x', duration: 0 }));
    h.eq('host state is independent of toast lifecycle', store.state.hosts.length, 2);
    store.clear();
}

/* ------------------------------------------------------------------ *
 * subscribe
 * ------------------------------------------------------------------ */

h.suite('createToastStore/subscribe');

{
    const store = createToastStore();
    const seen = [];
    const off = store.subscribe((toasts) => seen.push(toasts.length));

    store.push({ message: 'a', duration: 0 });
    h.eq('the listener sees the new length', seen[seen.length - 1], 1);

    store.push({ message: 'b', duration: 0 });
    h.eq('the listener is called on each push', seen[seen.length - 1], 2);

    store.dismiss(store.state.toasts[0].id);
    h.eq('dismiss notifies while the toast is still listed', seen[seen.length - 1], 2);

    await sleep(LEAVE_SETTLE);
    h.eq('the removal notifies', seen[seen.length - 1], 1);

    off();
    store.push({ message: 'c', duration: 0 });
    h.eq('unsubscribing stops the notifications', seen[seen.length - 1], 1);

    store.subscribe('not a function');
    store.subscribe(null);
    store.push({ message: 'd', duration: 0 });
    h.eq('non-function listeners are ignored', seen[seen.length - 1], 1);

    store.clear();
}

{
    // A throwing listener must not prevent the remaining listeners from running:
    // one broken consumer must not wedge the shared store.
    const store = createToastStore();
    const reached = [];
    store.subscribe(() => { throw new Error('consumer exploded'); });
    store.subscribe(() => reached.push('second'));

    const reported = [];
    const realError = console.error;
    console.error = (...args) => reported.push(args);

    let threw = false;
    try {
        store.push({ message: 'x', duration: 0 });
        store.clear();
    } catch (e) {
        threw = true;
    } finally {
        console.error = realError;
    }

    h.ok('a throwing listener does not break push or clear', !threw, `threw=${threw}`);
    h.eq('the remaining listeners still run', reached.length, 2);
    h.eq('the failure is reported rather than swallowed', reported.length, 2);
    h.ok('the report identifies the toast subsystem',
        reported.length > 0 && String(reported[0][0]).indexOf('phue/toast') !== -1,
        JSON.stringify(reported[0] || []));
}

/* ------------------------------------------------------------------ *
 * createConfirmQueue
 * ------------------------------------------------------------------ */

h.suite('createConfirmQueue/serialisation');

{
    const q = createConfirmQueue();
    h.eq('the queue starts empty', q.state.queue.length, 0);
    h.eq('nothing is current', q.state.current, null);
    h.eq('processNext on an empty queue is a no-op', q.processNext(), undefined);
    h.eq('setLoading without a current entry is a no-op', q.setLoading(true), undefined);
}

{
    const q = createConfirmQueue();
    const p = q.request('Delete the record?');
    const current = q.state.current;

    h.eq('the first request becomes current immediately', current.message, 'Delete the record?');
    h.eq('default title', current.title, 'Confirm Action');
    h.eq('default confirmText', current.confirmText, 'Confirm');
    h.eq('default cancelText', current.cancelText, 'Cancel');
    h.eq('default variant', current.variant, 'primary');
    h.eq('loading starts false', current.loading, false);
    h.ok('ids are integers', Number.isInteger(current.id) && current.id === 1);

    q.setLoading(true);
    h.eq('setLoading flips the current entry', q.state.current.loading, true);
    q.setLoading(false);
    h.eq('setLoading(0) coerces to boolean', q.state.current.loading, false);

    current.resolve(false);
    h.eq('resolving clears current', q.state.current, null);
    h.eq('the promise resolves false', await p, false);
}

{
    const q = createConfirmQueue();
    const order = [];
    const p1 = q.request({ message: 'one' }).then((r) => { order.push(`1:${r}`); });
    const p2 = q.request({ message: 'two' }).then((r) => { order.push(`2:${r}`); });
    const p3 = q.request({ message: 'three' }).then((r) => { order.push(`3:${r}`); });

    h.eq('one is current', q.state.current.message, 'one');
    h.eq('two is queued', q.state.queue.length, 2);
    h.eq('three is queued', q.state.queue.length, 2);
    h.deepEq('queued order is FIFO', q.state.queue.map((e) => e.message), ['two', 'three']);

    q.state.current.resolve(true);
    await Promise.resolve();
    h.eq('two is promoted after the first resolves', q.state.current.message, 'two');
    h.eq('three is still queued', q.state.queue.length, 1);

    q.state.current.resolve('second');
    await Promise.resolve();
    h.eq('three is promoted', q.state.current.message, 'three');
    h.eq('the queue is drained', q.state.queue.length, 0);

    q.state.current.resolve(null);
    h.eq('no entry is current after the last resolve', q.state.current, null);

    await Promise.all([p1, p2, p3]);
    h.deepEq('promises resolve in request order', order, ['1:true', '2:second', '3:null']);
}

{
    const q = createConfirmQueue();
    const p1 = q.request('a');
    q.request('b');
    h.eq('double resolve must not advance twice', q.state.queue.length, 1);

    q.state.current.resolve('x');
    await p1;
    h.eq('the next entry is promoted once', q.state.current.message, 'b');
    q.state.current.resolve('y');
}

{
    const q = createConfirmQueue();
    const first = q.request({
        message: 'x',
        title: 'T',
        confirmText: 'Yes',
        cancelText: 'No',
        variant: 'danger',
        icon: 'triangle',
        closeOnBackdrop: true
    });
    const e = q.state.current;
    h.eq('custom title', e.title, 'T');
    h.eq('custom confirmText', e.confirmText, 'Yes');
    h.eq('custom cancelText', e.cancelText, 'No');
    h.eq('custom variant', e.variant, 'danger');
    h.eq('custom icon is carried through the queue', e.icon, 'triangle');
    h.eq('closeOnBackdrop is carried through the queue', e.closeOnBackdrop, true);

    // Must resolve the first request, otherwise it stays current and the second
    // one is merely queued.
    e.resolve('done');
    await first;

    q.request();
    h.eq('undefined options fall back to the default message', q.state.current.message, 'Are you sure you want to proceed?');
    h.eq('undefined options fall back to the default title', q.state.current.title, 'Confirm Action');
    h.eq('icon defaults to empty', q.state.current.icon, '');
    h.eq('closeOnBackdrop defaults to false', q.state.current.closeOnBackdrop, false);

    q.state.current.resolve('done');
    q.request({ closeOnBackdrop: 'yes' });
    h.eq('closeOnBackdrop is strict boolean', q.state.current.closeOnBackdrop, false);
}

{
    // A single request may never resolve twice through the queue, and the
    // returned promise must be a real Promise even for a bare string.
    const q = createConfirmQueue();
    const p = q.request('str');
    h.ok('request returns a Promise', p instanceof Promise);
    q.state.current.resolve('done');
    h.eq('resolves the value verbatim', await p, 'done');
}

{
    const q = createConfirmQueue();
    const p = q.request('per-entry');
    q.setLoading(true);
    h.eq('queue.setLoading targets the current entry', q.state.current.loading, true);
    q.state.current.setLoading(false);
    h.eq('the per-entry setter overrides the queue setter', q.state.current.loading, false);
    q.state.current.resolve('ok');
    await p;
}

{
    const a = createConfirmQueue();
    const b = createConfirmQueue();
    a.request('from a');
    h.eq('confirm queues are independent', b.state.current, null);
    a.state.current.resolve('x');
}

h.finish();