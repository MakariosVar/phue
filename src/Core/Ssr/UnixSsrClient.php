<?php

declare(strict_types=1);

namespace Phue\Core\Ssr;

use Throwable;

/**
 * Class UnixSsrClient
 *
 * Low-level Unix Domain Socket client implementing HTTP/1.1 framing for SSR communication.
 * Operates under a strict fail-open policy and will never bubble up exceptions.
 *
 * @package Phue\Core\Ssr
 */
class UnixSsrClient implements SsrClient
{
    /**
     * UnixSsrClient constructor.
     *
     * @param string $socket Path to Unix domain socket (supports abstract sockets via leading null byte).
     * @param int $connectTimeoutMs Connection timeout limit in milliseconds.
     * @param int $timeoutMs Stream read timeout limit in milliseconds.
     * @param string $hostHeader Value for the Host HTTP header.
     */
    public function __construct(
        private string $socket = "\0phue-ssr",
        private int $connectTimeoutMs = 200,
        private int $timeoutMs = 500,
        private string $hostHeader = 'phue-ssr',
    ) {}

    /**
     * Execute an HTTP request over Unix domain socket and retrieve the response body.
     *
     * @param string $method HTTP Method (GET, POST, etc.)
     * @param string $path Target URI path (e.g., /v1/render, /v1/health)
     * @param string $payload Request body payload.
     * @param array<string, string> $headers Additional HTTP headers to include in the request.
     * @return string|null Response content body string, or null on error/timeout.
     */
    public function send(
        string $method = 'POST', 
        string $path = '/v1/render', 
        string $payload = '', 
        array $headers = []
    ): ?string {
        try {
            $connectTimeoutSec = $this->connectTimeoutMs / 1000.0;

            $client = @stream_socket_client(
                'unix://' . $this->socket,
                $errno,
                $errstr,
                $connectTimeoutSec,
                STREAM_CLIENT_CONNECT
            );

            if (!$client) {
                return null;
            }

            // Set stream read/write timeouts
            $seconds = (int) floor($this->timeoutMs / 1000);
            $microseconds = ($this->timeoutMs % 1000) * 1000;
            stream_set_timeout($client, $seconds, $microseconds);

            // Construct HTTP/1.1 Request in order: Request Line -> Host -> Content-Type -> Custom Headers -> Content-Length -> Connection
            $request  = "{$method} {$path} HTTP/1.1\r\n";
            $request .= "Host: {$this->hostHeader}\r\n";
            $request .= "Content-Type: application/json\r\n";

            // Append custom headers with header injection protection (\r and \n stripping)
            foreach ($headers as $name => $value) {
                $cleanName  = str_replace(["\r", "\n"], '', (string) $name);
                $cleanValue = str_replace(["\r", "\n"], '', (string) $value);
                $request   .= "{$cleanName}: {$cleanValue}\r\n";
            }

            // Calculate Content-Length based on final payload size (compressed or raw)
            $request .= "Content-Length: " . strlen($payload) . "\r\n";
            $request .= "Connection: close\r\n\r\n";
            $request .= $payload;

            @fwrite($client, $request);

            // Total wall-clock deadline calculation
            $deadline = microtime(true) + ($this->timeoutMs / 1000.0);
            $response = '';

            // Read raw response stream with spin protection and global timeout enforcement
            while (!feof($client)) {
                if (microtime(true) > $deadline) {
                    @fclose($client);
                    return null;
                }

                $chunk = @fread($client, 8192);

                // Check metadata only if read returned empty or false to avoid hot-path syscalls
                if ($chunk === false || $chunk === '') {
                    $info = stream_get_meta_data($client);
                    if ($chunk === false || $chunk === '' || $info['timed_out']) {
                        break;
                    }
                }

                $response .= $chunk;
            }

            @fclose($client);

            if (empty($response)) {
                return null;
            }

            // Separate response headers from message body
            $parts = explode("\r\n\r\n", $response, 2);
            if (count($parts) < 2) {
                return null;
            }

            [$responseHeaders, $body] = $parts;

            // Check HTTP status code (must be 200 OK or acceptable error response)
            if (preg_match('#^HTTP/1\.[01]\s+(\d+)#i', $responseHeaders, $matches)) {
                $statusCode = (int) $matches[1];
                
                // Allow non-200 responses if they contain a valid JSON error payload (e.g., 413, 400, 500)
                if ($statusCode !== 200 && $statusCode !== 413 && $statusCode !== 400 && $statusCode !== 500) {
                    return null;
                }
            }

            // Enforce exact body size parsing if Content-Length header is present
            if (preg_match('/Content-Length:\s*(\d+)/i', $responseHeaders, $matches)) {
                $contentLength = (int) $matches[1];
                return substr($body, 0, $contentLength);
            }

            return $body;
        } catch (Throwable) {
            // Fail-open: Suppress all stream, socket, and IO exceptions
            return null;
        }
    }

    /**
     * Check service availability by querying GET /v1/health.
     *
     * @return bool True if health check returns a valid response, false otherwise.
     */
    public function isAvailable(): bool
    {
        return $this->health() !== null;
    }

    /**
     * Retrieve health status from GET /v1/health.
     *
     * @return array<string, mixed>|null Health metrics array on success (e.g. ['ok' => true, 'vue' => ..., 'bun' => ...]), or null on failure.
     */
    public function health(): ?array
    {
        try {
            $response = $this->send(method: 'GET', path: '/v1/health');
            if ($response === null) {
                return null;
            }

            $decoded = json_decode($response, true);
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }
}