/**
 * Shared state stores and registries for global Phue UI primitives (Toasts, Confirm Dialogs).
 *
 * Rules:
 * 1. No imports
 * 2. No top-level window/document references
 * 3. No module-level mutable state
 * 4. Every export MUST use function declaration (export function name() {})
 * 5. Unique export names
 */

/**
 * Creates an isolated toast store instance managing active toasts, timers, and host registry.
 *
 * @returns {Object} Toast store interface { state, push, dismiss, remove, clear, pause, resume, success, error, warning, info, attachHost, detachHost, subscribe }
 */
export function createToastStore() {
    const storeState = {
        toasts: [],
        hosts: [],
        listeners: [],
        maxToasts: 5
    };

    let nextToastId = 1;

    function notify() {
        // One faulty subscriber must not wedge the shared store: the toast has
        // already been mutated by the time notify() runs, so letting a
        // subscriber's exception escape would reject push()/dismiss()/clear()
        // and leave callers disagreeing with the actual state. Every listener is
        // invoked, and failures are reported rather than silently swallowed.
        storeState.listeners.slice().forEach(function(listener) {
            if (typeof listener !== 'function') {
                return;
            }
            try {
                listener(storeState.toasts);
            } catch (error) {
                if (typeof console !== 'undefined' && typeof console.error === 'function') {
                    console.error('[phue/toast] subscriber failed:', error);
                }
            }
        });
    }

    function clearToastTimer(toast) {
        if (toast && toast.timerId) {
            clearTimeout(toast.timerId);
            toast.timerId = null;
        }
        if (toast && toast.leaveTimerId) {
            clearTimeout(toast.leaveTimerId);
            toast.leaveTimerId = null;
        }
    }

    function defaultTitle(variant) {
        switch (variant) {
            case 'success':
                return 'Success';
            case 'error':
                return 'Error';
            case 'warning':
                return 'Warning';
            case 'info':
            default:
                return 'Info';
        }
    }

    function remove(id) {
        const index = storeState.toasts.findIndex(function(t) { return t.id === id; });
        if (index !== -1) {
            const toast = storeState.toasts[index];
            clearToastTimer(toast);
            storeState.toasts.splice(index, 1);
            notify();
        }
    }

    function dismiss(id) {
        const toast = storeState.toasts.find(function(t) { return t.id === id; });
        if (!toast || toast.leaving) {
            return;
        }

        clearToastTimer(toast);
        toast.leaving = true;
        notify();

        toast.leaveTimerId = setTimeout(function() {
            remove(id);
        }, 200);
    }

    function push(options) {
        const opts = typeof options === 'string' ? { message: options } : (options || {});
        const id = nextToastId++;
        const variant = opts.variant || 'info';
        const title = opts.title || defaultTitle(variant);
        const duration = opts.duration !== undefined ? opts.duration : 5000;

        const toast = {
            id: id,
            title: title,
            message: opts.message || '',
            variant: variant,
            duration: duration,
            remaining: duration,
            startedAt: null,
            paused: false,
            leaving: false,
            timerId: null,
            leaveTimerId: null
        };

        if (duration > 0) {
            toast.startedAt = Date.now();
            toast.timerId = setTimeout(function() {
                dismiss(id);
            }, duration);
        }

        storeState.toasts.push(toast);

        // Store-level overflow trimming with explicit timer cleanup
        const maxToasts = storeState.maxToasts || 5;
        while (storeState.toasts.length > maxToasts) {
            const overflowToast = storeState.toasts.shift();
            clearToastTimer(overflowToast);
        }

        notify();
        return id;
    }

    function clear() {
        storeState.toasts.forEach(function(toast) {
            clearToastTimer(toast);
        });
        storeState.toasts = [];
        notify();
    }

    function pause(id) {
        const toast = storeState.toasts.find(function(t) { return t.id === id; });
        if (toast && !toast.paused && !toast.leaving && toast.duration > 0) {
            clearToastTimer(toast);
            const elapsed = Date.now() - (toast.startedAt || Date.now());
            toast.remaining = Math.max(0, toast.remaining - elapsed);
            toast.paused = true;
        }
    }

    function resume(id) {
        const toast = storeState.toasts.find(function(t) { return t.id === id; });
        if (toast && toast.paused && !toast.leaving && toast.remaining > 0) {
            toast.paused = false;
            toast.startedAt = Date.now();
            toast.timerId = setTimeout(function() {
                dismiss(id);
            }, toast.remaining);
        }
    }

    function attachHost(hostId) {
        if (!hostId) {
            return false;
        }

        const index = storeState.hosts.indexOf(hostId);
        if (index === -1) {
            storeState.hosts.push(hostId);
        }

        // First-Claim-Wins: The primary active host is always the first host registered at index 0
        const isPrimary = storeState.hosts[0] === hostId;
        return isPrimary;
    }

    function detachHost(hostId) {
        const index = storeState.hosts.indexOf(hostId);
        if (index !== -1) {
            storeState.hosts.splice(index, 1);
        }
        return storeState.hosts.length > 0 ? storeState.hosts[0] : null;
    }

    return {
        state: storeState,
        push: push,
        dismiss: dismiss,
        remove: remove,
        clear: clear,
        pause: pause,
        resume: resume,
        success: function(message, title, duration) {
            return push({ variant: 'success', message: message, title: title, duration: duration });
        },
        error: function(message, title, duration) {
            return push({ variant: 'error', message: message, title: title, duration: duration });
        },
        warning: function(message, title, duration) {
            return push({ variant: 'warning', message: message, title: title, duration: duration });
        },
        info: function(message, title, duration) {
            return push({ variant: 'info', message: message, title: title, duration: duration });
        },
        attachHost: attachHost,
        detachHost: detachHost,
        subscribe: function(listener) {
            if (typeof listener === 'function') {
                storeState.listeners.push(listener);
            }
            return function() {
                const idx = storeState.listeners.indexOf(listener);
                if (idx !== -1) {
                    storeState.listeners.splice(idx, 1);
                }
            };
        }
    };
}

/**
 * Creates an isolated confirmation dialog queue with per-entry loading state support.
 *
 * @returns {Object} Confirm queue interface { request, setLoading, processNext, state }
 */
export function createConfirmQueue() {
    const queueState = {
        queue: [],
        current: null
    };

    let nextQueueId = 1;

    function processNext() {
        if (queueState.current || queueState.queue.length === 0) {
            return;
        }

        const nextRequest = queueState.queue.shift();
        queueState.current = nextRequest;
    }

    function setLoading(loading) {
        if (queueState.current) {
            queueState.current.loading = !!loading;
        }
    }

    function request(options) {
        const opts = typeof options === 'string' ? { message: options } : (options || {});

        return new Promise(function(resolve) {
            const entry = {
                id: nextQueueId++,
                title: opts.title || 'Confirm Action',
                message: opts.message || 'Are you sure you want to proceed?',
                confirmText: opts.confirmText || 'Confirm',
                cancelText: opts.cancelText || 'Cancel',
                variant: opts.variant || 'primary',
                icon: opts.icon || '',
                closeOnBackdrop: opts.closeOnBackdrop === true,
                loading: false,
                setLoading: function(loading) {
                    entry.loading = !!loading;
                },
                resolve: function(result) {
                    resolve(result);
                    queueState.current = null;
                    processNext();
                }
            };

            queueState.queue.push(entry);
            processNext();
        });
    }

    return {
        state: queueState,
        request: request,
        setLoading: setLoading,
        processNext: processNext
    };
}