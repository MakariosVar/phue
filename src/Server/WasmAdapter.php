<?php

declare(strict_types=1);

namespace Phue\Server;

use Phue\Core\Container;
use Phue\Core\Request;
use Phue\Core\Response;
use Closure;

/**
 * Server Adapter for WebAssembly (WASM) & Cloudflare Edge Runtimes.
 *
 * Handles execution inside WASM Micro-VMs with zero-disk assumptions and strict memory scoping.
 *
 * @package Phue\Server
 */
final class WasmAdapter
{
    /**
     * Initializes the WASM server adapter with the dependency container.
     *
     * @param Container $container Application dependency injection container instance.
     */
    public function __construct(
        private readonly Container $container
    ) {}

    /**
     * Handles an incoming WASM HTTP invocation context.
     *
     * Transmutes the host environment parameters into a Phue HTTP Request, passes it through
     * the kernel pipeline, flushes memory scopes, and returns a formatted execution response.
     *
     * @param array<string, mixed> $wasmEnvironment Standardized environment array containing HTTP metadata.
     * @param Closure $kernelHandler Application Request-to-Response pipeline closure.
     * @return array{status: int, headers: array<string, string>, body: string} HTTP response structure.
     */
    public function handleWasmRequest(array $wasmEnvironment, Closure $kernelHandler): array
    {
        $method = strtoupper((string) ($wasmEnvironment['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($wasmEnvironment['REQUEST_URI'] ?? '/');
        $headers = (array) ($wasmEnvironment['HEADERS'] ?? []);
        $body = (string) ($wasmEnvironment['BODY'] ?? '');
        $parsedBody = json_decode($body, true);

        $request = new Request(
            method: $method,
            uri: $uri,
            headers: $headers,
            parsedBody: is_array($parsedBody) ? $parsedBody : []
        );

        try {
            /** @var Response $response */
            $response = $kernelHandler($request);
        } finally {
            $this->container->flushScope();
        }

        return [
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => (string) $response->getContent(),
        ];
    }
}