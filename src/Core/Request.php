<?php

namespace Phue\Core;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Representation of an incoming HTTP request.
 *
 * Encapsulates HTTP method, URI, query parameters, parsed body data,
 * headers, and server variables into an immutable object.
 */
class Request
{
    /**
     * Request constructor.
     *
     * @param string $method HTTP Method (GET, POST, PUT, DELETE, etc.)
     * @param string $uri Request URI path (e.g., /users)
     * @param array<string, mixed> $queryParams Query string parameters ($_GET)
     * @param array<string, mixed> $parsedBody Body parameters ($_POST or JSON payload)
     * @param array<string, string> $headers HTTP request headers
     * @param array<string, mixed> $serverParams Server variables ($_SERVER)
     * @param array<string, string> $cookies Cookie parameters; kept last so existing
     *                                        positional and named calls stay valid.
     */
    public function __construct(
        private string $method,
        private string $uri,
        private array $queryParams = [],
        private array $parsedBody = [],
        private array $headers = [],
        private array $serverParams = [],
        private array $cookies = []
    ) {
        // Normalize method to uppercase regardless of manual instantiation or capture()
        $this->method = strtoupper($this->method);
    }

    /**
     * Factory method to capture the current HTTP request from PHP global state.
     *
     * Automatically extracts PHP superglobals ($_SERVER, $_GET, $_POST) and parses
     * JSON body payloads if the Content-Type header is application/json.
     *
     * @return self
     */
    public static function capture(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Standardize HTTP headers from $_SERVER superglobal
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                // Convert HTTP_CONTENT_TYPE to Content-Type
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerName] = $value;
            }
        }

        // Explicitly add CONTENT_TYPE and CONTENT_LENGTH (PHP FPM / CGI do not prefix them with HTTP_)
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }

        // Parse JSON body payload when Content-Type matches application/json
        $parsedBody = $_POST;
        $contentType = $headers['Content-Type'] ?? '';
        
        if (str_contains($contentType, 'application/json')) {
            $rawInput = file_get_contents('php://input');
            if ($rawInput !== false && $rawInput !== '') {
                $decoded = json_decode($rawInput, true);
                if (is_array($decoded)) {
                    $parsedBody = $decoded;
                }
            }
        }

        return new self(
            method: $method,
            uri: $uri ?: '/',
            queryParams: $_GET,
            parsedBody: $parsedBody,
            headers: $headers,
            serverParams: $_SERVER,
            cookies: self::normalizeCookies($_COOKIE)
        );
    }

    /**
     * Get the HTTP request method.
     *
     * @return string Upper-case HTTP method (e.g., 'GET', 'POST')
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get the requested URI path.
     *
     * @return string URI path without query strings
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Get all query string parameters ($_GET).
     *
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * Get a specific query string parameter.
     *
     * @param string $key Parameter name
     * @param mixed $default Fallback value if key is not found
     * @return mixed
     */
    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * Get the parsed body data ($_POST or JSON payload).
     *
     * @return array<string, mixed>
     */
    public function getParsedBody(): array
    {
        return $this->parsedBody;
    }

    /**
     * Check if the request was made via AJAX / XmlHttpRequest.
     *
     * @return bool
     */
    public function isXmlHttpRequest(): bool
    {
        return strtolower($this->getHeader('X-Requested-With', '')) === 'xmlhttprequest';
    }

    /**
     * Retrieve input parameter checking body first, then fallback to query parameters.
     *
     * @param string $key Parameter key
     * @param mixed $default Fallback value
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->parsedBody[$key] ?? $this->queryParams[$key] ?? $default;
    }

    /**
     * Get all HTTP headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get a specific HTTP header by name (case-insensitive).
     *
     * @param string $name Header name (e.g., 'Content-Type')
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
     * Get raw server parameters ($_SERVER).
     *
     * @return array<string, mixed>
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * Get all request cookies.
     *
     * @return array<string, string>
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Get a specific cookie value by name.
     *
     * Cookie names are case-sensitive (RFC 6265 §5.4), unlike HTTP header names,
     * so this deliberately does NOT fold case the way getHeader() does.
     *
     * @param string $name Cookie name (e.g., 'session')
     * @param string|null $default Fallback value if the cookie is absent
     * @return string|null
     */
    public function getCookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * Reduce a raw cookie bag to the string map the rest of the framework expects.
     *
     * A query such as "?token[]=a" makes PHP populate $_COOKIE['token'] with an
     * array, so a raw bag is not trustworthy as array<string, string>. Anything
     * that is not a scalar is dropped rather than coerced, and the key must be a
     * usable cookie name.
     *
     * @param array<mixed> $cookies Raw cookie bag from the runtime
     * @return array<string, string>
     */
    private static function normalizeCookies(array $cookies): array
    {
        $normalized = [];

        foreach ($cookies as $name => $value) {
            if (!is_string($name) || $name === '' || !is_scalar($value)) {
                continue;
            }

            $normalized[$name] = is_bool($value) ? ($value ? '1' : '') : (string) $value;
        }

        return $normalized;
    }

    /**
     * Factory to instantiate a Phue Request from any PSR-7 ServerRequest.
     */
    public static function fromPsr7(ServerRequestInterface $psr7): self
    {
        $headers = [];
        foreach ($psr7->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $query = $psr7->getQueryParams();
        $parsedBody = $psr7->getParsedBody();
        $bodyParams = is_array($parsedBody) ? $parsedBody : [];

        $server = $psr7->getServerParams();
        $uri = $psr7->getUri()->getPath();
        $method = $psr7->getMethod();

        return new self(
            uri: $uri,
            method: $method,
            queryParams: $query,
            parsedBody: $bodyParams,
            headers: $headers,
            serverParams: $server,
            cookies: self::normalizeCookies($psr7->getCookieParams())
        );
    }
}