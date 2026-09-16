<?php

declare(strict_types=1);

namespace Phue\Modules\Server;

use Closure;
use Phue\Core\Request;
use Phue\Core\Response;

/**
 * Server Adapter for WebAssembly (WASM) & Cloudflare Edge Runtimes.
 *
 * Handles execution inside WASM Micro-VMs with zero-disk assumptions and strict memory scoping.
 *
 * @package Phue\Modules\Server
 */
final class WasmAdapter
{
    /**
     * Handles an incoming WASM HTTP invocation context.
     *
     * Transmutes host environment parameters into a Phue HTTP Request, executes
     * the request through the kernel pipeline handler, and returns a formatted array response.
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

        /** @var Response $response */
        $response = $kernelHandler($request);

        return [
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => (string) $response->getContent(),
        ];
    }
}