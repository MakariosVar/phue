<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

use Phue\Core\Ssr\SsrClient;

/**
 * Class ProfilingSsrClient
 *
 * Decorates the SSR transport so every Unix-socket round trip to the Bun sidecar shows
 * up as a named span with its payload size and outcome.
 *
 * This is the only way to tell a slow render apart from a slow sidecar: without it, the
 * SSR phase is one opaque block of wall-clock time. Decorating the transport (rather
 * than instrumenting the engine) also keeps the payload envelope itself out of the
 * report, so the bar stays readable.
 *
 * @package Phue\Modules\DevTool
 */
class ProfilingSsrClient implements SsrClient
{
    /**
     * @param SsrClient $inner Real transport performing the round trip.
     * @param RequestProfiler $profiler Collector receiving the span.
     */
    public function __construct(
        private SsrClient $inner,
        private RequestProfiler $profiler
    ) {}

    /**
     * Execute an HTTP request over the transport, recording it as a span.
     *
     * @param string $method HTTP method.
     * @param string $path Target URI path.
     * @param string $payload Request body payload.
     * @param array<string, string> $headers Additional request headers.
     * @return string|null Response body, or null on transport failure (fail-open).
     */
    public function send(
        string $method = 'POST',
        string $path = '/v1/render',
        string $payload = '',
        array $headers = []
    ): ?string {
        return $this->profiler->measure(
            'ssr.send',
            fn(): ?string => $this->inner->send($method, $path, $payload, $headers),
            [
                'path' => $path,
                'method' => $method,
                'request_bytes' => strlen($payload),
                'gzip' => isset($headers['Content-Encoding']),
            ]
        );
    }

    /**
     * Check service availability.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->profiler->measure(
            'ssr.health',
            fn(): bool => $this->inner->isAvailable()
        );
    }

    /**
     * Retrieve health status from the sidecar.
     *
     * @return array<string, mixed>|null Health metrics, or null on failure.
     */
    public function health(): ?array
    {
        return $this->profiler->measure(
            'ssr.health',
            fn(): ?array => $this->inner->health()
        );
    }
}
