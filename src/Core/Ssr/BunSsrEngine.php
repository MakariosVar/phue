<?php

declare(strict_types=1);

namespace Phue\Core\Ssr;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Class BunSsrEngine
 *
 * High-level SSR engine communicating with a Bun JavaScript sidecar via an SsrClient interface.
 * Implements content-addressed hash tracking via PSR-16 caching and strict fail-open error handling.
 *
 * @package Phue\Core\Ssr
 */
class BunSsrEngine implements SsrEngine
{
    /**
     * Cache key prefix for tracking component hashes.
     * Uses underscores to avoid reserved PSR-16 characters (such as ':') in FileCache implementations.
     */
    private const CACHE_KEY_PREFIX = 'phue_ssr_hash_';

    /**
     * Cache TTL in seconds (24 hours).
     */
    private const CACHE_TTL = 86400;

    /**
     * BunSsrEngine constructor.
     *
     * @param SsrClient $client Transport layer client instance.
     * @param int $maxPayloadKb Maximum allowed JSON payload size in KB before dispatch.
     * @param CacheInterface|null $cache Optional PSR-16 cache instance for tracking component hashes.
     * @param LoggerInterface|null $logger Optional PSR-3 logger instance for diagnostic warnings and errors.
     */
    public function __construct(
        private SsrClient $client,
        private int $maxPayloadKb = 512,
        private ?CacheInterface $cache = null,
        private ?LoggerInterface $logger = null
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
            // Attempt initial rendering dispatch
            $result = $this->dispatchRender($template, $state, $context, forceFullSource: false);

            if ($result['html'] !== null) {
                return $result['html'];
            }

            // Only retry with full sources if the sidecar explicitly reported a cache-miss error
            if ($result['isCacheMiss']) {
                $this->logger?->notice('SSR sidecar reported cache-miss; re-sending full component sources', [
                    'template' => $template,
                ]);

                $retryResult = $this->dispatchRender($template, $state, $context, forceFullSource: true);
                return $retryResult['html'];
            }

            return null;

        } catch (Throwable $e) {
            // Log uncaught rendering exception
            $this->logger?->error('SSR rendering exception encountered', [
                'exception' => $e,
                'template' => $template,
            ]);

            // Fail-open: Return fallback null without wiping unrelated cached hashes
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
     * @return array{html: string|null, isCacheMiss: bool}
     */
    private function dispatchRender(
        string $template, 
        array $state, 
        array $context, 
        bool $forceFullSource
    ): array {
        $failure = ['html' => null, 'isCacheMiss' => false];

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

                    if ($forceFullSource || !$this->isHashKnown($hash)) {
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

        $maxBytes = $this->maxPayloadKb * 1024;
        if ($json === false || strlen($json) > $maxBytes) {
            $this->logger?->warning('SSR payload size exceeded limit', [
                'bytes' => $json !== false ? strlen($json) : 0,
                'max_bytes' => $maxBytes,
                'template' => $template,
            ]);

            return $failure;
        }

        $rawResponse = $this->client->send(method: 'POST', path: '/v1/render', payload: $json);

        if ($rawResponse === null) {
            $this->logger?->warning('SSR transport failed', [
                'template' => $template,
            ]);

            return $failure;
        }

        $response = json_decode($rawResponse, true);

        if (!is_array($response)) {
            $this->logger?->warning('SSR response body is not valid JSON', [
                'body' => substr($rawResponse, 0, 256),
                'template' => $template,
            ]);

            return $failure;
        }

        if (isset($response['ok']) && $response['ok'] === true && isset($response['html']) && is_string($response['html'])) {
            foreach ($pendingHashes as $hash) {
                $this->rememberHash($hash);
            }

            return ['html' => $response['html'], 'isCacheMiss' => false];
        }

        // Inspect error payload to identify sidecar module cache-misses
        $isCacheMiss = false;
        if (isset($response['error']['message']) && is_string($response['error']['message'])) {
            $message = strtolower($response['error']['message']);
            if (
                str_contains($message, 'not found in sidecar cache') || 
                str_contains($message, 'cache miss') || 
                str_contains($message, 'module not found') || 
                str_contains($message, 'unknown component')
            ) {
                $isCacheMiss = true;
                // Evict pending/sent hashes so full source is included on the retry call
                foreach ($pendingHashes as $hash) {
                    $this->forgetHash($hash);
                }
            }
        }

        return ['html' => null, 'isCacheMiss' => $isCacheMiss];
    }

    /**
     * Check if component hash exists in PSR-16 cache.
     *
     * @param string $hash
     * @return bool
     */
    private function isHashKnown(string $hash): bool
    {
        if ($this->cache === null) {
            return false;
        }

        try {
            return (bool) $this->cache->has(self::CACHE_KEY_PREFIX . $hash);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Store component hash in PSR-16 cache with TTL.
     *
     * @param string $hash
     * @return void
     */
    private function rememberHash(string $hash): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->set(self::CACHE_KEY_PREFIX . $hash, true, self::CACHE_TTL);
        } catch (Throwable) {
            // Ignore cache storage errors to maintain fail-open design
        }
    }

    /**
     * Remove component hash from PSR-16 cache on sidecar cache-miss.
     *
     * @param string $hash
     * @return void
     */
    private function forgetHash(string $hash): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->delete(self::CACHE_KEY_PREFIX . $hash);
        } catch (Throwable) {
            // Ignore cache deletion errors
        }
    }
}