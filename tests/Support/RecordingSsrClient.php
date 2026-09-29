<?php

declare(strict_types=1);

namespace Phue\Tests\Support;

use Phue\Core\Ssr\SsrClient;

/**
 * Scriptable SsrClient double that records every dispatched payload.
 *
 * The contract under test is about *bytes on the wire* and about *what the
 * engine remembers between requests* — neither of which a PHPUnit mock can
 * assert on, because createMock() has no memory and no payload log. This
 * double does both: it appends every payload it is handed to $payloads, and it
 * answers from a scripted queue of responses.
 *
 * A response script entry is either:
 *   - a string, returned verbatim as the raw body; or
 *   - null, returned as a transport failure (the "socket refused" case).
 *
 * When the script runs out, the last entry is reused. That makes the
 * "the sidecar keeps complaining" failure mode expressible as a single
 * permanently-repeating entry.
 *
 * @package Phue\Tests\Support
 */
final class RecordingSsrClient implements SsrClient
{
    /**
     * Every raw request body this client was asked to send, in order.
     *
     * @var list<string>
     */
    public array $payloads = [];

    /**
     * Paths this client was asked to hit, parallel to $payloads.
     *
     * @var list<string>
     */
    public array $paths = [];

    /**
     * Decoded envelopes of every dispatched payload, for structural assertions.
     *
     * @var list<array<string, mixed>>
     */
    public array $envelopes = [];

    /**
     * @param list<string|null> $script Responses to hand back, in order.
     */
    public function __construct(private array $script = ['{"ok":true,"html":"<div>ok</div>"}'])
    {
    }

    public function send(string $method = 'POST', string $path = '/v1/render', string $payload = ''): ?string
    {
        $this->payloads[] = $payload;
        $this->paths[] = $path;

        $decoded = json_decode($payload, true);
        $this->envelopes[] = is_array($decoded) ? $decoded : [];

        $index = count($this->payloads) - 1;
        $response = $this->script[$index] ?? $this->script[count($this->script) - 1] ?? null;

        return $response;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function health(): ?array
    {
        return ['ok' => true, 'vue' => '3.5.42', 'bun' => '1.3.0'];
    }

    /**
     * Number of dispatches performed so far.
     */
    public function dispatchCount(): int
    {
        return count($this->payloads);
    }

    /**
     * Byte size of the nth dispatched payload (0-indexed).
     */
    public function payloadSize(int $index): int
    {
        return strlen($this->payloads[$index] ?? '');
    }

    /**
     * Component names carried by the nth envelope whose entry omitted the
     * inline 's' source member, i.e. the entries that assert "the sidecar
     * already has this one".
     *
     * @return list<string>
     */
    public function hashOnlyComponents(int $index): array
    {
        $components = $this->envelopes[$index]['components'] ?? [];

        if (!is_array($components)) {
            return [];
        }

        $names = [];

        foreach ($components as $name => $entry) {
            if (!is_string($name) || !is_array($entry)) {
                continue;
            }

            if (array_key_exists('s', $entry)) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return $names;
    }

    /**
     * Component names carried by the nth envelope whose entry DID include the
     * inline 's' source member.
     *
     * @return list<string>
     */
    public function fullSourceComponents(int $index): array
    {
        $components = $this->envelopes[$index]['components'] ?? [];

        if (!is_array($components)) {
            return [];
        }

        $names = [];

        foreach ($components as $name => $entry) {
            if (!is_string($name) || !is_array($entry)) {
                continue;
            }

            if (!array_key_exists('s', $entry)) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return $names;
    }
}
