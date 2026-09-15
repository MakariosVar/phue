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
     * @param string $template Raw Vue HTML component template.
     * @param bool   $ssr      Whether to compile for SSR using @vue/compiler-ssr.
     *
     * @return string Valid JavaScript code defining the render function.
     *
     * @throws RuntimeException If socket communication fails, times out, or returns a compile error.
     */
    public function compile(string $template, bool $ssr = false): string
    {
        $templateHash = sha1($template);
        $prefix = $ssr ? 'phue_compiled_ssr_render_v1_' : 'phue_compiled_render_v2_';
        $cacheKey = $prefix . $templateHash;

        // 1. Hit PSR-16 cache to avoid Socket RTT
        if ($this->cache !== null) {
            /** @var string|null $cachedCode */
            $cachedCode = $this->cache->get($cacheKey);
            if (is_string($cachedCode)) {
                return $cachedCode;
            }
        }

        $payload = json_encode([
            'template' => $template,
            'ssr'      => $ssr,
        ], JSON_THROW_ON_ERROR);

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

        if ($response === false || $statusCode !== 200) {
            $this->logCompileFailure($template, $response, $statusCode, $error, 'HTTP');
            $bodySnippet = $response !== false ? substr($response, 0, 500) : '(empty)';
            throw new RuntimeException("SSR Compiler service failure (Status {$statusCode}): {$error}. Debug log: " . $this->debugLogPath());
        }

        /** @var array{ok?: bool, code?: string, error?: string} $data */
        $data = json_decode($response, true);
        if (empty($data['ok']) || !isset($data['code'])) {
            $ssrError = $data['error'] ?? 'Unknown error';
            $this->logCompileFailure($template, $response, $statusCode, $error, 'COMPILE:' . $ssrError);
            throw new RuntimeException("SSR Compiler error: {$ssrError}. Debug log: " . $this->debugLogPath());
        }

        // Store compiled JS code in persistent cache
        if ($this->cache !== null) {
            $this->cache->set($cacheKey, $data['code']);
        }

        return $data['code'];
    }

    /**
     * Logs server-side rendering (SSR) compilation failures to a daily log file.
     *
     * @param string     $template   The template string that failed to compile or render.
     * @param mixed      $response   The raw response from the SSR engine (string, false, or other type).
     * @param int        $statusCode The HTTP status code returned during the compilation request.
     * @param string     $error      The cURL error message if a network or transport failure occurred.
     * @param string     $context    Additional contextual identifier or message for the failure.
     *
     * @return void
     */
    private function logCompileFailure(string $template, $response, int $statusCode, string $error, string $context): void
    {
        $logDir = dirname(__DIR__, 3) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/ssr-compile-' . date('Y-m-d') . '.log';
        $hash = sha1($template);
        $bodySnippet = is_string($response) ? substr($response, 0, 2000) : ($response === false ? '(empty/curl-fail)' : gettype($response));
        $entry = "\n" . str_repeat('=', 80) . "\n"
            . "Timestamp: " . date('Y-m-d H:i:s') . "\n"
            . "Context:   {$context}\n"
            . "Template SHA1: {$hash}\n"
            . "HTTP Status: {$statusCode}\n"
            . "Curl Error: {$error}\n"
            . "SSR Response: {$bodySnippet}\n"
            . str_repeat('-', 80) . "\n"
            . "TEMPLATE (first 5000 chars):\n"
            . substr($template, 0, 5000) . "\n"
            . str_repeat('=', 80) . "\n";
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Resolves the file path of the most recent SSR compile log, or a default daily path if none exist.
     *
     * @return string Absolute file path to the target log file.
     */
    private function debugLogPath(): string
    {
        $logDir = dirname(__DIR__, 3) . '/storage/logs';
        $pattern = $logDir . '/ssr-compile-*.log';
        $files = glob($pattern);
        if (empty($files)) {
            return $logDir . '/ssr-compile-' . date('Y-m-d') . '.log';
        }
        rsort($files);
        return $files[0];
    }
}