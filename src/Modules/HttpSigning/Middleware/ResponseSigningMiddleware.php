<?php

declare(strict_types=1);

namespace Phue\Modules\HttpSigning\Middleware;

use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\HttpSigning\SigningKeyManager;
use Phue\Core\Config;

/**
 * Middleware that signs HTTP responses.
 *
 * Runs at the OUTERMOST layer (last registered, first executed) so it wraps
 * the entire response including headers added by other middleware.
 *
 * Adds headers:
 * - X-Response-Signature: base64url signature of (canonical_headers + "." + body)
 * - X-Response-Signature-Algo: HS256 | RS256
 * - X-Response-Signature-KeyId: key identifier for rotation
 *
 * @package Phue\Modules\HttpSigning\Middleware
 */
final class ResponseSigningMiddleware
{
    /**
     * Create a new response signing middleware.
     *
     * @param SigningKeyManager $keys          Key manager for signing
     * @param Config            $config        Application configuration
     * @param array<string>     $excludePaths  Path patterns to skip (supports * wildcard suffix)
     * @param array<string>     $signedHeaders Header names to include in signature
     */
    public function __construct(
        private readonly SigningKeyManager $keys,
        private readonly Config $config,
        private readonly array $excludePaths = [],
        private readonly array $signedHeaders = []
    ) {}

    /**
     * Handle the request and sign the response.
     *
     * @param Request   $request Incoming HTTP request
     * @param callable(Request): Response $next Next middleware in pipeline
     * @return Response Signed HTTP response
     */
    public function __invoke(Request $request, callable $next): Response
    {
        // Check if signing is enabled globally
        if (!$this->config->get('http-signing.enabled', true)) {
            return $next($request);
        }

        // Check path exclusions
        if ($this->isExcluded($request->getUri())) {
            return $next($request);
        }

        $response = $next($request);

        // Don't sign error responses (4xx and 5xx) - let error handler deal with it
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        // Don't sign streaming responses (no finite body)
        if ($this->isStreaming($response)) {
            return $response;
        }

        return $this->signResponse($response);
    }

    /**
     * Check if path matches exclusion patterns.
     *
     * @param string $path Request URI path
     * @return bool True if path should be excluded from signing
     */
    private function isExcluded(string $path): bool
    {
        foreach ($this->excludePaths as $pattern) {
            if ($this->matchPattern($pattern, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Simple wildcard pattern matching (* at end only).
     *
     * @param string $pattern Pattern like '/health*' or exact '/ping'
     * @param string $path    Request path to match
     * @return bool
     */
    private function matchPattern(string $pattern, string $path): bool
    {
        if ($pattern === $path) {
            return true;
        }
        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);
            return str_starts_with($path, $prefix);
        }
        return false;
    }

    /**
     * Check if response is streaming (no finite body).
     *
     * @param Response $response HTTP response
     * @return bool True if response appears to be streaming
     */
    private function isStreaming(Response $response): bool
    {
        // Responses with Transfer-Encoding: chunked are streaming
        if (strtolower($response->getHeader('Transfer-Encoding', '') ?? '') === 'chunked') {
            return true;
        }
        // No Content-Length and no body = likely streaming
        // Exception: 204 No Content and 304 Not Modified are valid empty-body responses
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 204 && $statusCode !== 304) {
            if ($response->getHeader('Content-Length') === null && $response->getContent() === '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Sign the response and add signature headers.
     *
     * @param Response $response HTTP response to sign
     * @return Response Same response with signature headers added
     */
    private function signResponse(Response $response): Response
    {
        $key = $this->keys->getCurrentKey();
        $headerNames = $this->keys->getHeaderNames();
        $algo = $this->keys->getAlgorithmIdentifier();

        // Build canonical payload: selected headers + "." + body
        $payload = $this->buildPayload($response);

        // Sign
        $signature = $this->keys->sign($payload);

        // Add signature headers (replace if already present)
        $response->setHeader($headerNames['signature'], $signature);
        $response->setHeader($headerNames['algorithm'], $algo);
        $response->setHeader($headerNames['key_id'], $key['id']);

        // Optionally include public key for RSA
        if ($this->keys->shouldIncludePublicKey()) {
            $pubKey = $this->keys->getCurrentPublicKeyPem();
            if ($pubKey !== null) {
                $response->setHeader('X-Response-Public-Key', $pubKey);
            }
        }

        return $response;
    }

    /**
     * Build the canonical payload for signing.
     *
     * Format: canonical_headers\n.\nbody
     * Where canonical_headers = sorted header lines "name: value"
     *
     * @param Response $response HTTP response
     * @return string Canonical payload for signing
     */
    private function buildPayload(Response $response): string
    {
        $headers = $response->getHeaders();
        $headerLines = [];

        // Include configured headers (case-insensitive match)
        $signedHeaderNames = array_map('strtolower', $this->signedHeaders);

        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), $signedHeaderNames, true)) {
                $headerLines[] = strtolower($name) . ':' . $value;
            }
        }

        // Sort for deterministic canonicalization
        sort($headerLines);

        $canonicalHeaders = implode("\n", $headerLines);
        $body = $response->getContent();

        return $canonicalHeaders . "\n.\n" . $body;
    }
}