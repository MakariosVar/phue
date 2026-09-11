<?php

declare(strict_types=1);

namespace Phue\Core\Ssr;

use Throwable;

/**
 * Class BunSsrEngine
 *
 * High-level SSR engine communicating with a Bun JavaScript sidecar via an SsrClient interface.
 * Implements envelope validation and strict fail-open error handling.
 *
 * @package Phue\Core\Ssr
 */
class BunSsrEngine implements SsrEngine
{
    /**
     * BunSsrEngine constructor.
     *
     * @param SsrClient $client Transport layer client instance.
     * @param int $maxPayloadKb Maximum allowed JSON payload size in KB before dispatch.
     */
    public function __construct(
        private SsrClient $client,
        private int $maxPayloadKb = 512
    ) {}

    /**
     * Build standard protocol envelope, send rendering job to sidecar, and unpack HTML output.
     *
     * @param string $template Template name or component path identifier.
     * @param array<string, mixed> $state Rendering props and variables context.
     * @param array<string, mixed> $context Additional execution context parameters (e.g. components map).
     * @return string|null Evaluated component HTML on success, or null on fail-open error.
     */
    public function render(string $template, array $state = [], array $context = []): ?string
    {
        try {
            // Strict Contract 3.2 envelope {v, template, data}
            $envelope = [
                'v' => 1,
                'template' => $template,
                'data' => $state,
            ];

            // Attach components to envelope only if $context['components'] is a non-empty array
            if (isset($context['components']) && is_array($context['components']) && $context['components'] !== []) {
                $envelope['components'] = $context['components'];
            }

            $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                return null;
            }

            // Reject payload before dispatch if size exceeds configuration threshold
            if (strlen($json) > ($this->maxPayloadKb * 1024)) {
                return null;
            }

            $rawResponse = $this->client->send(method: 'POST', path: '/v1/render', payload: $json);

            if ($rawResponse === null) {
                return null;
            }

            $response = json_decode($rawResponse, true);

            if (!is_array($response)) {
                return null;
            }

            // Validate standard envelope response structure: ok ? html : null
            if (isset($response['ok']) && $response['ok'] === true && isset($response['html']) && is_string($response['html'])) {
                return $response['html'];
            }

            return null;
        } catch (Throwable) {
            // Fail-open: Catch all internal runtime exceptions and return fallback null
            return null;
        }
    }
}