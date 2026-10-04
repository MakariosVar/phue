<?php

declare(strict_types=1);

namespace Phue\Core\Websocket;

/**
 * The shape of every message the server sends.
 *
 * A version travels with each message rather than in a handshake, because the consumers here are
 * not all the same client: a browser bundle served by one deploy, a mobile app that updates on
 * its own schedule, and a third-party integration written against documentation. A client that
 * cannot parse what it receives must be able to say so instead of silently misreading it.
 *
 * The `id` is the cursor. It is monotonic per server process and is what makes a reconnect
 * recoverable: a client that says "I last saw 41" gets everything after it, and a client that
 * says nothing is told it missed something rather than quietly handed an incomplete answer.
 *
 * @package Phue\Core\Websocket
 */
final class Envelope
{
    /**
     * Wire protocol version.
     */
    public const VERSION = 1;

    /**
     * Build one outbound message.
     *
     * @param string $channel Topic the message belongs to.
     * @param string $event Event name, e.g. 'order.paid'.
     * @param mixed $data Event payload.
     * @param int $id Monotonic cursor value.
     * @param float|null $timestamp Unix timestamp; injected so it can be pinned in a test.
     * @return array<string, mixed>
     */
    public static function make(
        string $channel,
        string $event,
        mixed $data,
        int $id,
        ?float $timestamp = null
    ): array {
        return [
            'v' => self::VERSION,
            'id' => $id,
            'ch' => $channel,
            'ev' => $event,
            'ts' => round($timestamp ?? microtime(true), 3),
            'd' => $data,
        ];
    }

    /**
     * Encode an outbound message for the wire.
     *
     * @param array<string, mixed> $envelope
     * @return string
     */
    public static function encode(array $envelope): string
    {
        // Nothing here is trusted-adjacent enough to need raw output, but the payload is
        // caller-supplied and a JSON failure has to be visible rather than becoming an empty
        // string that the client reads as a truncated message.
        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return json_encode([
                'v' => self::VERSION,
                'id' => 0,
                'ch' => (string) ($envelope['ch'] ?? ''),
                'ev' => 'phue.error',
                'ts' => round(microtime(true), 3),
                'd' => ['reason' => 'the message could not be encoded'],
            ], JSON_UNESCAPED_UNICODE) ?: '{"ev":"phue.error"}';
        }

        return $json;
    }

    /**
     * Decode an inbound client message.
     *
     * @param string $raw
     * @return array{act: string, ch: string, since: null|int}|null Null when the message is
     *         unusable. A malformed frame is not fatal: a client that sends rubbish should get
     *         silence and a chance to correct itself, not a closed connection it cannot reopen.
     */
    public static function decodeInbound(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        $action = $decoded['act'] ?? null;

        if (!is_string($action) || $action === '') {
            return null;
        }

        $channel = $decoded['ch'] ?? '';
        $since = $decoded['since'] ?? null;

        return [
            'act' => $action,
            'ch' => is_string($channel) ? $channel : '',
            'since' => is_int($since) ? $since : (is_numeric($since) ? (int) $since : null),
        ];
    }
}