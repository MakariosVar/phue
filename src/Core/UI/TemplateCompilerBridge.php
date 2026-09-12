<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * Class TemplateCompilerBridge
 *
 * Handles IPC communications with the Bun SSR sidecar via a Unix Domain Socket
 * to pre-compile Vue templates into optimized JavaScript render functions.
 * Integrates PSR-16 caching to prevent redundant socket RTT overhead.
 *
 * @package Phue\Core\UI
 */
class TemplateCompilerBridge
{
    /**
     * Absolute filesystem path to the Unix domain socket.
     *
     * @var string
     */
    private string $socketPath;

    /**
     * Optional PSR-16 cache implementation for storing compiled render functions.
     *
     * @var CacheInterface|null
     */
    private ?CacheInterface $cache;

    /**
     * TemplateCompilerBridge constructor.
     *
     * @param string              $socketPath Absolute path to the Unix socket file.
     * @param CacheInterface|null $cache      Optional PSR-16 cache instance.
     */
    public function __construct(string $socketPath = '/tmp/phue-ssr.sock', ?CacheInterface $cache = null)
    {
        $this->socketPath = $socketPath;
        $this->cache = $cache;
    }

    /**
     * Compiles a raw Vue HTML template string into JavaScript render function code.
     *
     * First checks the persistent PSR-16 cache using a SHA-1 hash of the template.
     * On cache miss, sends a POST request over the Unix socket to the Bun compiler service.
     *
     * @param string $template Raw Vue HTML component template.
     *
     * @return string Valid JavaScript code defining the render function.
     *
     * @throws RuntimeException If socket communication fails, times out, or returns a compile error (Fail-Closed).
     */
    public function compile(string $template): string
    {
        $templateHash = sha1($template);
        $cacheKey = 'phue_compiled_render_v2_' . $templateHash;

        // 1. Hit PSR-16 cache to avoid Socket RTT
        if ($this->cache !== null) {
            /** @var string|null $cachedCode */
            $cachedCode = $this->cache->get($cacheKey);
            if (is_string($cachedCode)) {
                return $cachedCode;
            }
        }

        $payload = json_encode(['template' => $template], JSON_THROW_ON_ERROR);

        $ch = curl_init('http://localhost/v1/compile-template');
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => $this->socketPath,
            CURLOPT_POST             => true,
            CURLOPT_POSTFIELDS       => $payload,
            CURLOPT_HTTPHEADER       => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_TIMEOUT          => 2,
        ]);

        /** @var string|false $response */
        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $ch = null;

        // Fail-Closed: Throw Exception instead of silent empty render function
        if ($response === false || $statusCode !== 200) {
            throw new RuntimeException("SSR Compiler service failure (Status {$statusCode}): {$error}");
        }

        /** @var array{ok?: bool, code?: string, error?: string} $data */
        $data = json_decode($response, true);
        if (empty($data['ok']) || !isset($data['code'])) {
            throw new RuntimeException("SSR Compiler error: " . ($data['error'] ?? 'Unknown error'));
        }

        // Store compiled JS code in persistent cache
        if ($this->cache !== null) {
            $this->cache->set($cacheKey, $data['code']);
        }

        return $data['code'];
    }
}