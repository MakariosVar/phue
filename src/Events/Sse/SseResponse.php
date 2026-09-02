<?php

declare(strict_types=1);

namespace Phue\Events\Sse;

use Phue\Core\Response;

/**
 * Specialized HTTP Response for Server-Sent Events (SSE) Streaming.
 *
 * Configures the required HTTP headers for SSE including cache prevention,
 * persistent connections, and Nginx buffer bypassing.
 *
 * @package Phue\Events\Sse
 */
final class SseResponse extends Response
{
    /**
     * Initializes an SSE-compliant HTTP Response.
     *
     * @param string $body Initial body payload or event stream data.
     * @param array<string, string> $additionalHeaders Additional headers to merge.
     */
    public function __construct(string $body = '', array $additionalHeaders = [])
    {
        $sseHeaders = array_merge([
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ], $additionalHeaders);

        parent::__construct(
            statusCode: 200,
            headers: $sseHeaders,
            content: $body
        );
    }
}