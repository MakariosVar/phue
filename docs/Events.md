# Events — Event Bus, SSE & Broadcasting

The real-time subsystem — `Phue\Modules\Events` (`src/Modules/Events/`):
distributed pub/sub via Redis Streams, Server-Sent Events (SSE) streaming, and
attribute-driven model broadcasting.

> Don't confuse this with the in-process `EventDispatcher` — that one decouples
> framework components via class-based events and lives in
> `Phue\Core\EventDispatcher` (see [Core §6](Core.md)). The Events module is for
> *real-time* messaging between processes and browsers.

---

## 1. Components

| Component | File | Responsibility |
|---|---|---|
| `EventBusInterface` | `Modules/Events/Bus/EventBusInterface.php` | Contract for publishing & subscribing |
| `InMemoryBusDriver` | `Modules/Events/Bus/InMemoryBusDriver.php` | Synchronous in-memory driver for testing and local dev |
| `RedisStreamDriver` | `Modules/Events/Bus/RedisStreamDriver.php` | High-throughput Redis Streams driver with consumer groups |
| `SseResponse` | `Modules/Events/Sse/SseResponse.php` | HTTP Response pre-configured for SSE streaming |
| `SseStreamer` | `Modules/Events/Sse/SseStreamer.php` | SSE protocol formatter |
| `Broadcastable` | `Modules/Events/Attribute/Broadcastable.php` | Attribute marking models for automatic broadcasting |

> Historical note: this subsystem used to live in `Phue\Events`
> (`src/Events/`). After the modular restructuring it is
> `Phue\Modules\Events` (`src/Modules/Events/`).

---

## 2. Event bus drivers

### InMemoryBusDriver

Synchronous in-memory listener repository — ideal for tests and local
development. Publishes are delivered to subscribers immediately, no network.

### RedisStreamDriver

Built on Redis Streams (`XADD`, `XREADGROUP`, `XACK`) with consumer groups and
at-least-once delivery semantics:

```php
use Phue\Modules\Events\Bus\RedisStreamDriver;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$bus = new RedisStreamDriver($redis, consumerGroup: 'web_workers');

// Publish an event to a channel
$bus->publish('orders', ['order_id' => 101, 'status' => 'paid']);

// Subscribe a worker loop
$bus->subscribe('orders', function(array $event) {
    echo "Processing order #{$event['order_id']} ({$event['status']})\n";
});
```

- `publish(string $channel, object|array $event): void`
- `subscribe(string $channel, callable $handler): void`

Consumer groups let multiple workers share a channel without double-processing.

---

## 3. Server-Sent Events (SSE)

### SseResponse

Specialized HTTP Response pre-configured with the streaming headers:

- `Content-Type: text/event-stream`
- `Cache-Control: no-cache`
- `Connection: keep-alive`
- `X-Accel-Buffering: no` (disables proxy buffering)

```php
use Phue\Modules\Events\Sse\SseResponse;

return new SseResponse($payload);
```

The constructor accepts the body plus optional additional headers.

### SseStreamer

Formatter producing standard SSE event text blocks (`id`, `event`, `data`):

```php
use Phue\Modules\Events\Sse\SseStreamer;

$streamer = new SseStreamer();

$payload = $streamer->formatEvent('order.updated', ['id' => 101, 'status' => 'shipped'], '1001');
```

- `formatEvent(string $event, mixed $data, string|int|null $id = null): string`

---

## 4. `#[Broadcastable]` — automatic model broadcasting

Mark an Active Record model for real-time event broadcasting on CRUD
mutations:

```php
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Modules\Events\Attribute\Broadcastable;

#[Broadcastable(channel: 'orders', broadcastCreate: true, broadcastUpdate: true)]
class Order extends BaseActiveRecord
{
    // Model CRUD mutations automatically broadcast events to the 'orders' channel
}
```

| Property | Default | Meaning |
|---|---|---|
| `channel` | *(required)* | The channel events are published to |
| `broadcastCreate` | `true` | Broadcast on create |
| `broadcastUpdate` | `true` | Broadcast on update |
| `broadcastDelete` | `true` | Broadcast on delete |

---

## 5. Typical wiring: publish from CRUD, stream to browsers

```php
// 1. Worker subscribes to the bus
$bus->subscribe('orders', function(array $event) {
    $streamer = new SseStreamer();
    $payload  = $streamer->formatEvent('order.updated', $event, (string) ($event['order_id'] ?? ''));
    // push to connected SSE clients / broadcast further
});

// 2. A controller returns an SSE response for browser clients
#[Route('/api/orders/stream', methods: ['GET'])]
public function stream(): SseResponse
{
    $payload = (new SseStreamer())->formatEvent('orders.open', ['ok' => true]);
    return new SseResponse($payload);
}
```

The `events` module ships in the `realtime` and `fullstack` templates of the
`phue-create` installer — see [Getting Started](Getting-Started.md).
