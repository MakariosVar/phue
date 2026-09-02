<?php

namespace Phue\Core;

/**
 * Representation of an outgoing HTTP response.
 *
 * Holds HTTP status code, headers, and body payload ready to send to the browser.
 */
class Response
{
    /**
     * Response constructor.
     *
     * @param string $content Response body content
     * @param int $statusCode HTTP response status code (default: 200 OK)
     * @param array<string, string> $headers Key-value array of HTTP headers
     */
    public function __construct(
        private string $content = '',
        private int $statusCode = 200,
        private array $headers = []
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
     *
     * @param mixed $data Data payload to serialize into JSON
     * @param int $statusCode HTTP status code
     * @return self
     */
    public static function json(mixed $data, int $statusCode = 200): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new self(
            content: $json !== false ? $json : '{}',
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json']
        );
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
        }

        // Echo response content payload
        echo $this->content;
    }

    /**
     * Converts native Phue Response to PSR-7 Response (e.g. Nyholm/Psr7).
     */
    public function toPsr7(): ResponseInterface
    {
        return new Psr7Response(
            status: $this->statusCode,
            headers: $this->headers,
            body: $this->body,
            version: '1.1'
        );
    }
}