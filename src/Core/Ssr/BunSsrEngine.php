<?php

declare(strict_types=1);

namespace Phue\Core\Ssr;

use Throwable;

/**
 * Class BunSsrEngine
 *
 * High-level SSR engine communicating with a Bun JavaScript sidecar via an SsrClient interface.
 * Implements content-addressed hash tracking and strict fail-open error handling.
 *
 * @package Phue\Core\Ssr
 */
class BunSsrEngine implements SsrEngine
{
    /**
     * Maximum number of hashes retained in long-lived workers before cache eviction.
     */
    private const MAX_KNOWN_HASHES = 1000;

    /**
     * In-memory registry tracking sidecar-cached component hashes.
     *
     * @var array<string, bool>
     */
    private static array $knownHashes = [];

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
     * Implements immediate same-call retry on cache-miss/restart errors.
     *
     * @param string $template Template name or component path identifier.
     * @param array<string, mixed> $state Rendering props and variables context.
     * @param array<string, mixed> $context Additional execution context parameters (e.g. components map).
     * @return string|null Evaluated component HTML on success, or null on fail-open error.
     */
    public function render(string $template, array $state = [], array $context = []): ?string
    {
        try {
            // Unbounded array protection for Swoole workers
            if (count(self::$knownHashes) > self::MAX_KNOWN_HASHES) {
                self::$knownHashes = [];
            }

            // Attempt initial rendering dispatch
            $result = $this->dispatchRender($template, $state, $context, forceFullSource: false);

            if ($result !== null) {
                return $result;
            }

            // If initial dispatch failed, invalidate tracked hashes and execute immediate same-call retry with full sources
            self::$knownHashes = [];
            return $this->dispatchRender($template, $state, $context, forceFullSource: true);

        } catch (Throwable) {
            // Fail-open: Reset tracking state and return fallback null
            self::$knownHashes = [];
            return null;
        }
    }

    /**
     * Helper to construct envelope, transmit payload over client socket, and process response.
     *
     * @param string $template
     * @param array<string, mixed> $state
     * @param array<string, mixed> $context
     * @param bool $forceFullSource When true, forces transmitting component source string 's' regardless of cache history.
     * @return string|null Rendered HTML string or null.
     */
    private function dispatchRender(
        string $template, 
        array $state, 
        array $context, 
        bool $forceFullSource
    ): ?string {
        $envelope = [
            'v' => 1,
            'template' => $template,
            'data' => $state,
        ];

        $pendingHashes = [];

        if (isset($context['components']) && is_array($context['components']) && $context['components'] !== []) {
            $components = [];

            foreach ($context['components'] as $name => $source) {
                if (is_string($source) && $source !== '') {
                    $hash = substr(hash('sha256', $source), 0, 12);
                    $componentEntry = ['h' => $hash];

                    if ($forceFullSource || !isset(self::$knownHashes[$hash])) {
                        $componentEntry['s'] = $source;
                        $pendingHashes[] = $hash;
                    }

                    $components[$name] = $componentEntry;
                }
            }

            if ($components !== []) {
                $envelope['components'] = $components;
            }
        }

        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || strlen($json) > ($this->maxPayloadKb * 1024)) {
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

        if (isset($response['ok']) && $response['ok'] === true && isset($response['html']) && is_string($response['html'])) {
            foreach ($pendingHashes as $hash) {
                self::$knownHashes[$hash] = true;
            }

            return $response['html'];
        }

        return null;
    }
}