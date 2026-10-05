<?php

declare(strict_types=1);

use function Phue\Core\env;

return [
    /*
     * Real-time channels. Off unless APP_DEBUG is on or this is turned on explicitly.
     *
     * Origins are an allowlist and the default list is empty, which means "no browser may open a
     * connection". That is not paranoia: a WebSocket handshake carries the session cookie and
     * browsers do not apply CORS to WebSockets at all, so without this check a page on any
     * origin could open a connection and read whatever it subscribed to. With APP_DEBUG on, an
     * empty list becomes ['*'] so the dev server can be reached from anywhere on the LAN.
     */
    'enabled' => env('APP_WEBSOCKET', false),

    /*
     * The server implementation, named rather than hard-coded so the interface can be resolved
     * from it. Swapping this is how another adapter would be supported without the module
     * knowing it exists.
     */
    'transport' => \Phue\Modules\Websocket\SwooleWebSocketTransport::class,

    /*
     * The endpoints clients connect to, keyed by name.
     *
     * An endpoint is a path plus the rules that apply to it, and each keeps its own topics. That
     * separation is what makes `auth` mean anything: a connection opened on an endpoint that does
     * not authenticate cannot subscribe to a topic belonging to one that does, because the two do
     * not share a registry. One global setting would have forced a choice between locking the
     * development reload channel behind a login and leaving application data reachable without
     * one.
     *
     * `auth` defaults to true, and setting it to false is refused outside debug mode: a flag like
     * that is exactly what travels unnoticed from a development config into a deployment, and the
     * application should say so at boot rather than after the data has been read.
     *
     * Each endpoint takes `endpoint`, `auth`, `allowed_origins`, `retention` and
     * `default_retention`.
     */
    'websockets' => [
        'app' => [
            'endpoint' => '/ws',
            // 'auth' => true,
            'allowed_origins' => [
                // 'https://app.example.com',
            ],

            /*
             * Messages kept per topic so a client that was disconnected can be caught up.
             *
             * This is the setting that makes reconnecting work: a phone that was backgrounded has
             * an OS-suspended socket, and on resume it needs to be told what it missed rather than
             * handed the next event as though nothing happened. Topics listed here retain that
             * many messages; anything not listed retains `default_retention` unless that is zero.
             */
            'retention' => [
                // 'user:42:orders' => 100,
            ],

            'default_retention' => 100,
        ],
    ],

    /*
     * Unread messages a single connection may hold before new ones are dropped rather than
     * buffered. A client that stops reading fills the socket, and everything published queues
     * against it in userland; unbounded, one slow phone is an out-of-memory kill of the worker
     * that serves every other connection too.
     */
    'queue_limit' => 256,
];
