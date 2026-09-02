<?php

declare(strict_types=1);

namespace Phue\Events\Sse;

/**
 * Server-Sent Events (SSE) Protocol Formatter and Streamer.
 *
 * Formats event name, payload data, and optional event ID into the standard SSE text format.
 *
 * @package Phue\Events\Sse
 */
final class SseStreamer
{
    /**
     * Formats an event into a standard SSE text stream block.
     *
     * Format specification:
     * id: {id}
     * event: {event}
     * data: {json_data}
     * \n\n
     *
     * @param string $event The event name identifier (e.g., 'product.updated').
     * @param mixed $data The event payload to serialize to JSON.
     * @param string|int|null $id Optional unique event sequence ID.
     * @return string Formatted SSE protocol string block.
     */
    public function formatEvent(string $event, mixed $data, string|int|null $id = null): string
    {
        $output = '';

        if ($id !== null) {
            $output .= "id: {$id}\n";
        }

        $output .= "event: {$event}\n";

        $jsonPayload = is_string($data) ? $data : json_encode($data);
        $output .= "data: {$jsonPayload}\n\n";

        return $output;
    }
}