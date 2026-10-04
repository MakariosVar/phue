/*
 * Phue realtime client.
 *
 * Deliberately free of DOM, cookies and dependencies. A browser bundle and a React Native app
 * have almost nothing in common — one has `document` and `window.localStorage`, the other has
 * neither and resolves modules through Metro — so anything this file touched would make it
 * unusable in one of the two places it is needed.
 *
 * It therefore takes its credentials as an argument and keeps its own state in plain objects.
 *
 * Vendored rather than served, for the native case: a mobile bundler cannot fetch
 * /assets/phue/ws.js at runtime, and a client that only works when the app happens to be
 * serving it is a client that cannot be used from a phone.
 *
 *   var ch = PhueWs.channel('user:42:orders', { url: 'wss://app.example.com/ws', token: t });
 *   ch.on('order.paid', function (data) { ... });
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();          // Node / Metro
    } else {
        root.PhueWs = factory();             // browser
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var PROTOCOL = 1;
    var MIN_BACKOFF = 500;
    var MAX_BACKOFF = 15000;

    function backoffFor(attempt) {
        // Exponential with jitter. Fixed intervals make every client in a deployment reconnect
        // at the same instant after a restart, which is how a small server gets knocked over by
        // its own clients.
        var base = Math.min(MAX_BACKOFF, MIN_BACKOFF * Math.pow(2, attempt));
        return Math.round(base * (0.5 + Math.random() * 0.5));
    }

    function Channel(options) {
        this.url = options.url;
        this.token = options.token || null;
        this.name = options.name;
        this.handlers = {};
        this.state = { lastSeen: options.since || 0 };
        this.socket = null;
        this.attempt = 0;
        this.closed = false;
        this.connected = false;
        this.onState = options.onState || null;
    }

    Channel.prototype.endpoint = function () {
        // A native WebSocket cannot be relied on to send custom headers, so the token may ride
        // in the query string. That puts it wherever URLs are logged, so the token behind it
        // should be short-lived and revocable — which is the issuer's decision, not the
        // client's.
        var url = this.url;
        if (this.token) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'token=' + encodeURIComponent(this.token);
        }
        return url;
    };

    Channel.prototype.connect = function () {
        if (this.closed) { return this; }

        var self = this;
        var socket;

        try {
            socket = new WebSocket(this.endpoint());
        } catch (e) {
            this.scheduleReconnect();
            return this;
        }

        this.socket = socket;

        socket.onopen = function () {
            self.attempt = 0;
            self.connected = true;
            self.setState('connected');
            // The whole reason this client exists in this form: the subscription is replayed
            // on every reconnect, so an application writes it once and never thinks about it
            // again. A client that reconnects without resubscribing is silently deaf.
            self.send({ act: 'sub', ch: self.name, since: self.state.lastSeen });
        };

        socket.onmessage = function (event) {
            var message;

            try {
                message = JSON.parse(event.data);
            } catch (e) {
                return;
            }

            if (!message || typeof message !== 'object') { return; }

            if (message.v !== PROTOCOL) {
                // Stated rather than ignored: quietly misreading a payload from a newer server
                // is worse than being told the versions do not line up.
                self.setState('version-mismatch');
                return;
            }

            if (typeof message.id === 'number' && message.id > self.state.lastSeen) {
                self.state.lastSeen = message.id;
            }

            if (message.ev === 'phue.pong') { return; }

            var handler = self.handlers[message.ev];
            if (handler) {
                handler(message.d, message);
            }

            var any = self.handlers['*'];
            if (any) {
                any(message.d, message);
            }
        };

        socket.onclose = function () {
            self.connected = false;
            self.setState('disconnected');
            self.scheduleReconnect();
        };

        socket.onerror = function () {
            // onclose always follows, and reconnecting from both would double the backoff.
        };

        return this;
    };

    Channel.prototype.scheduleReconnect = function () {
        var self = this;

        if (this.closed) { return; }

        var delay = backoffFor(this.attempt);
        this.attempt++;

        this.setState('reconnecting');

        setTimeout(function () { self.connect(); }, delay);
    };

    Channel.prototype.setState = function (state) {
        this.state.status = state;
        if (this.onState) { this.onState(state); }
    };

    Channel.prototype.send = function (payload) {
        if (!this.socket || this.socket.readyState !== 1) { return false; }
        try {
            this.socket.send(JSON.stringify(payload));
            return true;
        } catch (e) {
            return false;
        }
    };

    /** Listen for one event name, or for '*' to get everything. */
    Channel.prototype.on = function (event, handler) {
        this.handlers[event] = handler;
        return this;
    };

    /** Subscribe to a second topic over the same socket. */
    Channel.prototype.join = function (topic, since) {
        this.send({ act: 'sub', ch: topic, since: since || 0 });
        return this;
    };

    Channel.prototype.leave = function (topic) {
        this.send({ act: 'unsub', ch: topic });
        return this;
    };

    Channel.prototype.close = function () {
        this.closed = true;
        if (this.socket) { this.socket.close(); }
        return this;
    };

    /** The cursor to persist so a later session can ask where it left off. */
    Channel.prototype.lastSeen = function () {
        return this.state.lastSeen;
    };

    return {
        version: PROTOCOL,
        channel: function (name, options) {
            return new Channel(options || {}).connect();
        }
    };
}));