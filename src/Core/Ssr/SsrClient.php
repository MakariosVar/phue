<?php

declare(strict_types=1);

namespace Phue\Core\Ssr;

/**
 * Interface SsrClient
 *
 * Contract for low-level IPC transport communication with the SSR sidecar service.
 *
 * @package Phue\Core\Ssr
 */
interface SsrClient
{
    /**
     * Send an HTTP request over the transport layer and retrieve the raw response body.
     *
     * @param string $method HTTP Method (e.g., 'POST', 'GET').
     * @param string $path Target URI path (e.g., '/v1/render', '/v1/health').
     * @param string $payload Raw request body string.
     * @return string|null Raw response body string on success, or null on failure.
     */
    public function send(string $method = 'POST', string $path = '/v1/render', string $payload = ''): ?string;

    /**
     * Check whether the transport channel to the SSR service is available.
     *
     * @return bool True if health check succeeds, false otherwise.
     */
    public function isAvailable(): bool;

    /**
     * Retrieve status and diagnostic information from the sidecar service.
     *
     * @return array<string, mixed>|null Decoded health payload array, or null if unreachable.
     */
    public function health(): ?array;
}