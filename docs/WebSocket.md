# WebSocket

Push from the server, on a persistent connection, to a browser tab or a native
client.

The module exists because the rest of Phue is a request/response framework: it
is very good at answering a question and has no way to say "by the way, something
changed". The [Events](Events.md) module covers one-way server-to-client over
SSE. This covers the two-way case, and it is the transport the DevTools live
reload uses.

---

## Table of contents

- [The shape of it](#the-shape-of-it)
- [Enabling it](#enabling-it)
- [Endpoints](#endpoints) — the part to read before writing any
- [Publishing](#publishing)
- [More than one worker](#more-than-one-worker)
- [The wire protocol](#the-wire-protocol)
- [Authentication](#authentication)
- [Origins](#origins)
- [Retention and reconnecting](#retention-and-reconnecting)
- [Backpressure](#backpressure)
- [What the framework does for you](#what-the-framework-does-for-you)
- [Known limits](#known-limits)

---

## The shape of it

Two halves, and only one of them is yours to write.

**Server.** You resolve `Broadcast` and name a topic. The module owns the
socket, the subscriber table, the delivery cursor and the reconnect story.

```php
use Phue\Modules\Websocket\Broadcast;

$broadcast = $this->container->get(Broadcast::class);

$broadcast->for('app')->to('orders', 'order.paid', ['id' => 9]);
```

**Client.** You subscribe to a topic and read envelopes.

```js
const ws = new WebSocket(`ws://${location.host}/ws`);
ws.onopen = () => ws.send(JSON.stringify({ act: "sub", ch: "orders" }));
ws.onmessage = (e) => {
  const msg = JSON.parse(e.data);
  if (msg.ev === "order.paid") {
    /* msg.d */
  }
};
```

Everything between those two points — handshakes, Origin checks, authentication,
framing, resumption, backpressure — is the framework's problem.

---

## Enabling it

Three things have to be true, and they are three different things — which is
where the confusion usually comes from.

| | What it controls | Default |
|---|---|---|
| **The module is listed** in `config/app.php` | Whether the code exists at all | not listed |
| **`APP_WEBSOCKET=true`** | Whether the module wires itself up | `false` |
| **`APP_DEBUG=true`** | Whether the `dev` endpoint exists, and whether origins are permissive | `false` |

```ini
# .env — both, in development
APP_DEBUG=true
APP_WEBSOCKET=true
```

```php
// config/app.php
'modules' => [
    \Phue\Modules\Websocket\WebsocketModule::class,
    \Phue\Modules\Server\ServerModule::class,
],
```

**`APP_WEBSOCKET` is the switch that matters; `APP_DEBUG` is a modifier.**
`config/websocket.php` always supplies an explicit `enabled`, so the debug
fallback inside `WebsocketConfig` never fires — a module that switches itself on
because a flag was set somewhere else is a module nobody audited. `APP_DEBUG`
does two narrower things: it adds the `dev` endpoint, and it turns an empty
origin list into `['*']`.

So: **`APP_DEBUG` without `APP_WEBSOCKET` gets you nothing.** No endpoints, no
hooks, no handshake handling.

### A WebSocket handshake only completes under Swoole

`./phue serve` or `./phue-docker start -co`. Under `serve:php` the module loads
and its configuration is readable, but nothing holds a socket open.

> A handshake must not travel through the normal HTTP pipeline. Swoole completes
> the upgrade itself, and letting the request continue past it meant an auth
> touchpoint called `session_start()` after the 101 had already been written —
> a `headers already sent` warning on every single request. The adapters now stop
> upgrade requests at the door.

### Missing pieces, measured

Every row below is a booted application, inspected rather than reasoned about.

| Configuration | `/ws` endpoints | WS hooks | `/_phue/live-reload` | Result |
|---|---|---|---|---|
| Both modules, all flags on | `app`, `dev` | yes | `200` | Push, with polling as standby |
| **`websocket` module absent** | — | no | `200` | **Polling only.** Live reload still works |
| **`devtools` module absent** | `app`, `dev` | yes | `404` | Your endpoints work fully; no toolbar, no live reload |
| `websocket` listed, `APP_WEBSOCKET` off | *configured* `app`, `dev` | **no** | `200` | **Polling only** — see below |
| `devtools` listed, `APP_DEV_TOOL` off | `app`, `dev` | yes | `404` | Your endpoints work; no toolbar |
| `APP_DEBUG` off | `app` only | yes | `404` | Production posture: no dev channel, exact origins |

Read the third row twice. `websocket` configured but disabled **still lists its
endpoints in configuration** and **still registers no hooks**, because
`bindings()` returns before it does anything. A client connecting to `/_phue/ws`
in that state gets a successful handshake from Swoole — which completes it on its
own — and then silence, because nothing is listening.

That is why the toolbar treats an **open socket as no evidence at all**: `pushed`
is only set once a message has actually been delivered. An open socket proves the
handshake completed, and the handshake is the one part of this that always
succeeds.

## Endpoints

**An endpoint is a path plus the rules that apply to it, and each one keeps its
own topics.** Read this section before configuring anything, because it is what
makes the rest safe.

```php
// config/websocket.php
return [
    'enabled' => env('APP_WEBSOCKET', false),
    'transport' => \Phue\Modules\Websocket\SwooleWebSocketTransport::class,

    'websockets' => [
        'app' => [
            'endpoint' => '/ws',
            'auth' => true,               // default
            'allowed_origins' => ['https://app.example.com'],
            'retention' => ['user:42:orders' => 100],
            'default_retention' => 100,
        ],

        'dev' => [
            'endpoint' => '/_phue/ws',
            'auth' => false,              // refused outside debug
        ],
    ],
];
```

| Key                       | Default | Meaning                                                                     |
| ------------------------- | ------- | --------------------------------------------------------------------------- |
| `endpoint`                | `/ws`   | Path a client connects to. Normalised: `ws`, `/ws` and `/ws/` are one path. |
| `auth`                    | `true`  | A handshake must produce an identity.                                       |
| `allowed_origins`         | `[]`    | Exact origins, or `['*']` in debug. Empty means _no browser may connect_.   |
| `retention`               | `[]`    | Messages kept per topic, for resumption.                                    |
| `default_retention`       | `100`   | Messages kept for a topic that was not listed. `0` disables.                |
| `queue_limit` (top level) | `256`   | Unread messages a single connection may hold.                               |

### Why per endpoint, and not one switch

Your application's realtime traffic is user data and must never be reachable
without a login. A development channel that reloads a page when a file changes
carries no user data at all, and demanding a login for it is a tax with no
security value behind it. One global "authenticate everything" switch forces one
of those two to be wrong, so the setting is per endpoint instead.

**The separation is what gives the setting meaning.** Each endpoint has its own
`TopicRegistry`. A connection opened on an endpoint that does not authenticate
cannot subscribe to a topic belonging to one that does, because the two do not
share a registry at all — and a publish only reaches the endpoint it names:

```php
// Reaches subscribers on 'app' only. A tab on 'dev' subscribed to 'orders'
// hears nothing, which is the entire security claim.
$broadcast->for('app')->to('orders', 'order.paid');
```

> **Contract.** A refused handshake does not record its endpoint, so it cannot
> subscribe to anything. Recorded earlier, it would have kept receiving
> publishes: the authentication check closed the socket and left the door open
> behind it.

### `auth: false` is refused outside debug

```php
// throws InvalidArgumentException at boot
'websockets' => ['dev' => ['endpoint' => '/_phue/ws', 'auth' => false]],
```

The failure this prevents is a development endpoint copied into production
config with its flags intact, which is exactly how an unauthenticated channel
ships. Debug already allows every origin, so there is no scenario that needs
this outside it.

### Built-in endpoints

| Name  | Path        | When it exists                                    | Auth    |
| ----- | ----------- | ------------------------------------------------- | ------- |
| `app` | `/ws`       | When you describe no endpoints at all             | `true`  |
| `dev` | `/_phue/ws` | Added automatically in debug, unless you named it | `false` |

`app` exists so that enabling the module does not also mean designing its
surface. It is deliberately the strict one; anything looser has to be written
down.

---

## Publishing

`Broadcast` is the only API an application is expected to touch, and every
publish names its endpoint:

```php
$broadcast->for('app')
    ->to('orders', 'order.paid', ['id' => 9])
    ->toUser('42', 'invoice.ready', ['id' => 3])
    ->toUserTopic('42', 'orders', 'order.paid', ['id' => 9]);
```

| Method                                 | Topic             | Reach                              |
| -------------------------------------- | ----------------- | ---------------------------------- |
| `to($channel, $event, $data, $except)` | whatever you name | everyone subscribed to it          |
| `toUser($userId, $event, ...)`         | `user:42`         | that user's connections            |
| `toUserTopic($userId, $suffix, ...)`   | `user:42:orders`  | that user, that stream             |
| `listeners($channel)`                  | —                 | how many connections are listening |
| `Broadcast::userChannel($id, $suffix)` | —                 | build the name yourself            |

Event names are conventionally `noun.verb` (`order.paid`, `invoice.ready`).
Payloads must survive a JSON round trip, because every consumer receives them as
JSON.

Publishing to an endpoint that is not configured throws
`InvalidArgumentException` naming the ones that are. Silently dropping it would
be worse: the message is gone and the caller was told nothing, which is how a
feature ends up "sometimes not working".

---

## More than one worker

A WebSocket connection belongs to one worker process, and the process that
serves a request is whichever worker was free. So with more than one worker, a
publish reaches the subscribers held by *that* worker and nobody else's — the
other tabs are not gone, they are on another process, and nothing anywhere says
so.

The broker is what carries a published message to the other processes. It is a
Redis stream rather than pub/sub, because fan-out needs every reader to see
every message (a consumer group would hand each one to a single member) and
because a resumable protocol needs a replay window.

```ini
# .env
APP_WEBSOCKET=true
APP_WS_REDIS=true
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

```php
// config/websocket.php — the keys, with their defaults
'redis' => [
    'enabled' => env('APP_WS_REDIS', false),
    'host'    => env('REDIS_HOST', '127.0.0.1'),
    'port'    => (int) env('REDIS_PORT', 6379),
    'timeout' => 0.5,
],
```

Each worker opens its own connection, and each one reads the whole stream rather
than a share of it. Two things follow, and both are deliberate:

- **The cursor is shared.** It is an `INCR` counter, not the stream id, so every
  worker mints cursors from one sequence. Two processes counting independently
  is how a reconnecting client gets told it missed messages it received.
- **The delivery path stays free on one worker.** The publishing worker delivers
  to its own subscribers synchronously and skips its own copy when it comes back
  around the stream, so a single-worker deployment never pays a network hop.

Without the broker, everything still works with one worker and costs nothing.
With more than one, browsers on the other workers go quiet — and if Redis is
configured but unreachable, that is what happens, logged once per worker at
warning level.

---

## The wire protocol

Every frame is JSON. Server → client:

```json
{
  "v": 1,
  "id": 42,
  "ch": "orders",
  "ev": "order.paid",
  "ts": 1791148058.704,
  "d": { "id": 9 }
}
```

| Field | Meaning                                                              |
| ----- | -------------------------------------------------------------------- |
| `v`   | Protocol version. A client should ignore a version it does not know. |
| `id`  | Monotonic cursor per endpoint, used to resume.                       |
| `ch`  | Topic, so a client routing by topic can place the message.           |
| `ev`  | Event name.                                                          |
| `ts`  | Server timestamp, seconds with milliseconds.                         |
| `d`   | Payload.                                                             |

Client → server:

```json
{"act":"sub","ch":"orders","since":42}
{"act":"unsub","ch":"orders"}
{"act":"ping","ch":""}
```

`since` asks for anything missed while disconnected (see
[retention](#retention-and-reconnecting)). A malformed frame is answered with
silence and the chance to correct itself, not with a closed connection.

Two server events exist without you publishing them: `phue.pong`, the answer to
`ping`, and `phue.gap`, sent when a client asked to resume past the beginning of
what was retained.

---

## Authentication

An endpoint with `auth: true` refuses a handshake that cannot produce an
identity, with close code **1008** and a reason you can act on.

Three sources, in order:

1. **Your authenticator**, if you installed one:
   ```php
   $module->authenticateWith(function (Request $request): ?string {
       $row = MyApp::lookup($request->getHeader('X-Api-Key'));
       return $row['id'] ?? null;   // null refuses
   });
   ```
2. **The session**, when the Auth module is loaded. Checked before the token,
   because a browser tab carries a cookie and nothing else — trying the token
   first would mean a logged-in developer is asked for a token their browser
   could have answered for.
3. **A bearer token**, from `Authorization: Bearer …` or a `?token=` query
   parameter. The query form exists because a WebSocket client in a native
   runtime does not reliably send custom headers — and it puts the token in
   places that log URLs, so it must be short-lived and revocable.

The module has **no hard dependency on Auth**: an application that authenticates
its own traffic needs none of this. But it also does not read a session on its
own — Auth owns that, and a module reaching into it would make the transport
unavailable to exactly the applications that do not use it.

---

## Origins

A WebSocket handshake carries the session cookie and browsers apply no CORS to
WebSockets at all. Without an Origin check, a page on any origin could open a
connection and read whatever it subscribed to. Every mainstream WebSocket server
therefore checks `Origin` by hand, and so does this one.

```php
'allowed_origins' => ['https://app.example.com'],   // exact
'allowed_origins' => [],                             // deny every browser
```

In debug an empty list becomes `['*']`, because a development server moves
between hosts and ports all the time.

A request with **no** Origin is a non-browser client — a mobile app, a CLI, a
server-side integration. Those authenticate with a token, so the browser-only
defence does not apply and rejecting them would reject every client that is not
a web page. An empty Origin is allowed through, and the token is what decides.

---

## Retention and reconnecting

This is the setting that makes reconnecting work. A phone that was backgrounded
has an OS-suspended socket; on resume it needs to be told what it missed rather
than handed the next event as though nothing happened.

```php
'retention' => ['user:42:orders' => 100],   // per topic
'default_retention' => 100,                 // everything else
```

A client resuming sends `{"act":"sub","ch":"orders","since":42}` and receives
what it missed, or `phue.gap` if its cursor has fallen off the back. Topics not
listed retain `default_retention`; set it to `0` to keep nothing.

> Set the retention for a topic that carries user data deliberately. It is a
> replay buffer on the server, and anything in it is readable by whoever presents
> the right cursor.

---

## Backpressure

```php
'queue_limit' => 256,
```

Unread messages a single connection may hold. A client that stops reading fills
the socket and everything published queues against it in userland; unbounded,
one slow phone is an out-of-memory kill of the worker serving every other
connection too. Past the limit, messages are **dropped** rather than buffered,
and the connection is told.

---

## What the framework does for you

- **Reconnect and resubscribe.** The vendored client (`src/Modules/Websocket/client/phue-ws.js`)
  reconnects with backoff and replays its cursor.
- **Resume by cursor.** No re-fetching, no double-delivery.
- **Per-user topics.** `toUser` builds the name; the module does not have to be told what a "user" is.
- **Graceful degradation.** If the realtime channel is not available, the DevTools toolbar falls back to polling — see [Server Adapters §4](Server-Adapters.md).

---

## Known limits

Stated plainly, because each of these is a real limit rather than a roadmap item.

- **One worker unless a broker is configured.** `TopicRegistry` is per worker, so with `worker_num > 1` a publish reaches only the subscribers held by the worker that handled the request — unless `websocket.redis.enabled` is on, in which case every worker reads the same Redis stream and delivers to its own subscribers. Without it, browsers on other workers go quiet with nothing in the log to say why, because from the publishing worker's point of view there is simply nobody listening. **Test with more than one worker before you rely on either path.**
- **A worker that loses the broker delivers alone.** The fall-over is permanent for that process: it keeps serving its own subscribers, other workers stop hearing it, and it says so once in the log. Restarting the server is the fix.
- **Nothing can observe a connection.** Swoole keeps one handler per event, and the transport owns `open`, `message` and `close`. An application or a second module that registers its own would silently take the transport's away, so there is no supported way to hook a connection lifecycle — including to ask a client which worker it is sitting on. `CrossWorkerDeliveryTest` has to prove cross-process delivery with two servers for the same reason.
- **No path-level routing.** Every endpoint shares the one upgrade handler; the endpoint is chosen by the handshake path. There is no per-endpoint middleware chain beyond the Origin and authentication checks.
- **Refusals are delivered late.** Swoole ignores a close issued from inside `on('open')`, so a refused handshake closes on the client's first frame instead. The client sees `101` and then a close code; it cannot see a rejected handshake as a rejected handshake.
- **The bundled client is not served.** `phue-ws.js` has no asset route; copy it into your own build if you want it, or write the twenty lines of subscribe loop yourself — it is deliberately small.
