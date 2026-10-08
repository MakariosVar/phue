<?php

namespace Phue\Core;

use JsonException;
use Nyholm\Psr7\Response as Psr7Response;
use Phue\Core\Security\Cookie;
use Psr\Http\Message\ResponseInterface;

/**
 * Representation of an outgoing HTTP response.
 *
 * Holds HTTP status code, headers, cookies, and body payload ready to send to the browser.
 *
 * Cookies are held apart from $headers on purpose: $headers is a flat array<string, string>
 * and setHeader() replaces case-insensitively, so a second Set-Cookie would silently evict
 * the first. Each cookie is therefore emitted as its own header line, with PHP's
 * header() replace flag turned off.
 *
 * @package Phue\Core
 */
class Response
{
    /**
     * Response constructor.
     *
     * @param string $content Response body content
     * @param int $statusCode HTTP response status code (default: 200 OK)
     * @param array<string, string> $headers Key-value array of HTTP headers
     * @param array<int, \Phue\Core\Security\Cookie> $cookies Cookies to send with this response
     */
    public function __construct(
        private string $content = '',
        private int $statusCode = 200,
        private array $headers = [],
        private array $cookies = []
    ) {}

    /**
     * Create an HTML HTTP response object.
     *
     * @param string $content HTML payload
     * @param int $statusCode HTTP status code
     * @return self
     */
    public static function html(string $content, int $statusCode = 200): self
    {
        return new self(
            content: $content,
            statusCode: $statusCode,
            headers: ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    /**
     * Create a JSON HTTP response object.
     *
     * Automatically encodes data array/object into JSON string.
     * Throws 500 server error payload on JSON serialization failure.
     *
     * @param mixed $data Data payload to serialize into JSON
     * @param int $statusCode HTTP status code
     * @return self
     */
    public static function json(mixed $data, int $statusCode = 200): self
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return new self(
                content: $json,
                statusCode: $statusCode,
                headers: ['Content-Type' => 'application/json']
            );
        } catch (JsonException $e) {
            return new self(
                content: json_encode(['error' => 'Malformed JSON payload: ' . $e->getMessage()]),
                statusCode: 500,
                headers: ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * Set the HTTP status code.
     *
     * @param int $code Status code (e.g. 200, 404, 500)
     * @return self Fluent instance
     */
    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Get the current HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Set an HTTP header (replaces existing case-insensitive header).
     *
     * @param string $name Header name
     * @param string $value Header value
     * @return self Fluent instance
     */
    public function setHeader(string $name, string $value): self
    {
        $normalizedName = strtolower($name);
        foreach (array_keys($this->headers) as $key) {
            if (strtolower($key) === $normalizedName) {
                unset($this->headers[$key]);
            }
        }

        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Get all registered response headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get a specific header value (case-insensitive).
     *
     * @param string $name Header name
     * @param string|null $default Fallback value
     * @return string|null
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        $normalizedName = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $normalizedName) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Set the raw body content.
     *
     * @param string $content Body string payload
     * @return self Fluent instance
     */
    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Attach a cookie to the response.
     *
     * A cookie already queued with the same name, path and domain is replaced rather than
     * duplicated, so a middleware that runs twice over the same response (the pattern
     * SecurityMiddleware::applyHeaders() already uses for headers) stays idempotent.
     * Cookies that differ in path or domain are a genuinely different cookie and both
     * survive, which is what a browser would store.
     *
     * @param \Phue\Core\Security\Cookie $cookie Cookie to send
     * @return self Fluent instance
     */
    public function withCookie(Cookie $cookie): self
    {
        foreach ($this->cookies as $index => $queued) {
            if ($queued->name === $cookie->name
                && $queued->path === $cookie->path
                && $queued->domain === $cookie->domain
            ) {
                $this->cookies[$index] = $cookie;
                return $this;
            }
        }

        $this->cookies[] = $cookie;
        return $this;
    }

    /**
     * Get all cookies queued for this response, in the order they will be emitted.
     *
     * @return array<int, \Phue\Core\Security\Cookie>
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Get the raw body content.
     *
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Output headers and body payload to the output stream.
     *
     * @return void
     */
    public function send(): void
    {
        // Emit HTTP headers only if headers haven't already been sent by PHP runtime
        if (!headers_sent()) {
            http_response_code($this->statusCode);

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }

            // replace = false is mandatory here: PHP's header() replaces by header NAME,
            // so the default true would make each Set-Cookie evict the previous one and
            // only the last cookie would ever reach the client.
            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie->toHeaderString(), false);
            }
        }

        // Echo response content payload
        echo $this->content;
    }

    /**
     * Converts native Phue Response to PSR-7 Response (e.g. Nyholm/Psr7).
     *
     * @return ResponseInterface PSR-7 compliant response instance.
     */
    public function toPsr7(): ResponseInterface
    {
        $psr7 = new Psr7Response(
            status: $this->statusCode,
            headers: $this->headers,
            body: $this->content,
            version: '1.1'
        );

        // PSR-7 is multi-value natively, so each cookie becomes its own Set-Cookie line.
        foreach ($this->cookies as $cookie) {
            $psr7 = $psr7->withAddedHeader('Set-Cookie', $cookie->toHeaderString());
        }

        return $psr7;
    }
}