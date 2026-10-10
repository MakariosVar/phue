<?php

declare(strict_types=1);

namespace Phue\Core\Ssr;

use Error;
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
     * Payload size threshold in bytes (64 KB) above which gzip compression is applied.
     */
    private const COMPRESS_THRESHOLD_BYTES = 65536;

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
     * Implements incremental retry loop for cache-miss errors up to 8 rounds.
     *
     * @param string $template Template name or component path identifier.
     * @param array<string, mixed> $state Rendering props and variables context.
     * @param array<string, mixed> $context Additional execution context parameters (e.g. components map).
     * @return string|null Evaluated component HTML on success, or null on fail-open error.
     */
    public function render(string $template, array $state = [], array $context = []): ?string
    {
        try {
            $forceNames = [];

            // Loop until 8 rounds for incremental resolution of missing components
            for ($round = 0; $round < 8; $round++) {
                $result = $this->dispatchRender($template, $state, $context, forceNames: $forceNames);

                if ($result['html'] !== null) {
                    return $result['html'];
                }

                if ($result['isCacheMiss']) {
                    $missingNames = [];
                    foreach ($result['missing'] as $missingHash) {
                        if (isset($result['hashToName'][$missingHash])) {
                            $missingNames[] = $result['hashToName'][$missingHash];
                        }
                    }

                    if ($missingNames === []) {
                        // If we couldn't map the missing hash to a name, we stop
                        return null;
                    }

                    $this->logger?->notice('SSR sidecar reported cache-miss; re-sending specific missing component sources', [
                        'template' => $template,
                        'missing_names' => $missingNames,
                        'round' => $round + 1,
                    ]);

                    // Add the missing names to the forceNames list for the next round
                    $forceNames = array_values(array_unique(array_merge($forceNames, $missingNames)));
                    continue;
                }

                // Error which is not a cache miss
                return null;
            }

            return null;

        } catch (Error $e) {
            // Re-throw fatal PHP internal errors to prevent hiding code bugs
            throw $e;
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
     * @param list<string> $forceNames List of component names that must be sent with full source code ('s').
     * @return array{html: string|null, isCacheMiss: bool, missing: list<string>, hashToName: array<string, string>}
     */
    private function dispatchRender(
        string $template, 
        array $state, 
        array $context, 
        array $forceNames
    ): array {
        // $hashToName mapping
        $hashToName = [];
        $failure = [
            'html' => null, 
            'isCacheMiss' => false, 
            'missing' => [], 
            'hashToName' => &$hashToName
        ];

        $envelope = [
            'v' => 1,
            'template' => $template,
            'data' => $state,
        ];

        if (isset($context['i18n']) && is_array($context['i18n'])) {
            $envelope['i18n'] = $context['i18n'];
        }

        $pendingHashes = [];

        if (isset($context['components']) && is_array($context['components']) && $context['components'] !== []) {
            $components = [];

            foreach ($context['components'] as $name => $source) {
                if (is_string($source) && $source !== '') {
                    $hash = substr(hash('sha256', $source), 0, 12);
                    
                    // Keep the connection hash -> name
                    $hashToName[$hash] = (string) $name;

                    $componentEntry = ['h' => $hash];

                    // Check if the component is in the $forceNames list
                    if (in_array($name, $forceNames, true) || !$this->isHashKnown($hash)) {
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

        if ($json === false) {
            $this->logger?->warning('Failed to JSON-encode SSR payload envelope', [
                'template' => $template,
            ]);

            return $failure;
        }

        $headers = [];
        $payload = $json;
        $uncompressedBytes = strlen($json);

        // Compress if the uncompressed payload exceeds the threshold (64 KB)
        if ($uncompressedBytes > self::COMPRESS_THRESHOLD_BYTES) {
            $compressed = gzencode($json, 1); // Fast compression level 1
            if ($compressed !== false) {
                $payload = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }
        }

        // Check the size limit of the payload on the wire (compressed payload)
        $wireBytes = strlen($payload);
        $maxBytes = $this->maxPayloadKb * 1024;

        if ($wireBytes > $maxBytes) {
            $this->logger?->warning('SSR payload size exceeded limit', [
                'bytes' => $wireBytes,
                'uncompressed_bytes' => $uncompressedBytes,
                'max_bytes' => $maxBytes,
                'template' => $template,
            ]);

            return $failure;
        }

        $rawResponse = $this->client->send(
            method: 'POST', 
            path: '/v1/render', 
            payload: $payload,
            headers: $headers
        );

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

            return [
                'html' => $response['html'], 
                'isCacheMiss' => false, 
                'missing' => [], 
                'hashToName' => $hashToName
            ];
        }

        // Inspect error payload to identify sidecar module cache-misses
        $isCacheMiss = false;
        $missingHashes = [];

        if (isset($response['error']['missing']) && is_array($response['error']['missing'])) {
            // Support for new payload with complete set of missing hashes
            $isCacheMiss = true;
            foreach ($response['error']['missing'] as $missingHash) {
                if (is_string($missingHash)) {
                    $missingHashes[] = $missingHash;
                    $this->forgetHash($missingHash);
                }
            }
        } elseif (isset($response['error']['message']) && is_string($response['error']['message'])) {
            $message = $response['error']['message'];
            
            // Export of missing hash with regex from the error (fallback)
            if (preg_match("/Component hash '([0-9a-f]+)'/i", $message, $matches) === 1) {
                $isCacheMiss = true;
                $missingHash = $matches[1];
                $missingHashes[] = $missingHash;

                // Clear ONLY this hash from cache
                $this->forgetHash($missingHash);
            } elseif (
                str_contains(strtolower($message), 'not found in sidecar cache') || 
                str_contains(strtolower($message), 'cache miss') || 
                str_contains(strtolower($message), 'module not found') || 
                str_contains(strtolower($message), 'unknown component')
            ) {
                $isCacheMiss = true;
                // Fallback if for any reason not mathed the regex
                foreach ($pendingHashes as $hash) {
                    $this->forgetHash($hash);
                }
            }
        }

        return [
            'html' => null, 
            'isCacheMiss' => $isCacheMiss, 
            'missing' => $missingHashes, 
            'hashToName' => $hashToName
        ];
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