<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Stubs;

use RuntimeException;

/**
 * Fake SSR sidecar implementing the Contract 3.2 wire protocol over a Unix
 * socket, without requiring the real Bun runtime.
 *
 * This fixture is a faithful wire-level double of ssr/server.ts so that the
 * protocol contract can be exercised in CI even when `bun` is unavailable.
 */
final class FakeSidecar
{
    /** @var resource|null */
    private $server = null;

    private string $socketPath;

    /**
     * In-memory hash => module source registry.
     *
     * Stands in for the `moduleCache` Map in ssr/server.ts, so that
     * hash-only component entries are only accepted after the sidecar has
     * been handed the corresponding source at least once.
     *
     * @var array<string, string>
     */
    private array $moduleCache = [];

    public function __construct(string $socketPath)
    {
        $this->socketPath = $socketPath;

        @unlink($this->socketPath);

        $this->server = @stream_socket_server(
            'unix://' . $this->socketPath,
            $errno,
            $errstr
        );

        if ($this->server === false) {
            throw new RuntimeException("Failed to bind FakeSidecar socket: {$errstr} ({$errno})");
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Accepts the next incoming connection, applies the wire contract handler,
     * and blocks until a single request has been fully served.
     *
     * @return array{method: string, path: string, body: ?string} Parsed request payload for assertions.
     */
    public function acceptOnce(): array
    {
        /* @var resource|false $client */
        $client = @stream_socket_accept($this->server, 5.0);

        if ($client === false) {
            throw new RuntimeException('FakeSidecar timed out waiting for a client connection.');
        }

        $request = '';
        while (!feof($client)) {
            $chunk = fread($client, 8192);
            if ($chunk === false) {
                break;
            }

            $request .= $chunk;

            if ($this->isComplete($request)) {
                break;
            }
        }

        [$head, $body] = $this->splitRequest($request);
        [$method, $path] = $this->parseStartLine($head);

        $response = $this->resolve($method, $path, $body);

        fwrite($client, $response);
        fclose($client);

        return [
            'method' => $method,
            'path'   => $path,
            'body'   => $body,
        ];
    }

    public function close(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }

        if ($this->socketPath !== '' && is_file($this->socketPath)) {
            @unlink($this->socketPath);
        }

        $this->server = null;
    }

    /**
     * Determines whether a full HTTP/1.1 request (head + body honoring
     * Content-Length) has been received.
     */
    private function isComplete(string $request): bool
    {
        $separator = strpos($request, "\r\n\r\n");

        if ($separator === false) {
            return false;
        }

        $head = substr($request, 0, $separator);

        if (!preg_match('/^Content-Length:\s*(\d+)\s*$/mi', $head, $matches)) {
            return true;
        }

        return strlen($request) - $separator - 4 >= (int) $matches[1];
    }

    /**
     * Splits a raw HTTP/1.1 request into head and body sections.
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitRequest(string $request): array
    {
        $position = strpos($request, "\r\n\r\n");

        if ($position === false) {
            return [$request, null];
        }

        $head = substr($request, 0, $position);
        $body = substr($request, $position + 4);

        return [$head, $body];
    }

    /**
     * Extracts method and path from the HTTP status line.
     *
     * @return array{0: string, 1: string}
     */
    private function parseStartLine(string $head): array
    {
        $line = strtok($head, "\r\n");
        $parts = $line !== false ? explode(' ', trim((string) $line)) : [];

        return [
            strtoupper($parts[0] ?? 'GET'),
            $parts[1] ?? '/',
        ];
    }

    /**
     * Resolves the fake request against Contract 3.2 and renders an HTTP response.
     *
     * @param string      $method HTTP method.
     * @param string      $path   Request path.
     * @param string|null $body   Raw request body, if any.
     *
     * @return string Full HTTP/1.1 response string.
     */
    private function resolve(string $method, string $path, ?string $body): string
    {
        if ($method === 'GET' && $path === '/v1/health') {
            return $this->http(
                200,
                ['ok' => true, 'vue' => '3.5.42', 'bun' => '1.3.0']
            );
        }

        if ($path !== '/v1/render' || $method !== 'POST') {
            return $this->http(
                404,
                ['ok' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Not Found']]
            );
        }

        $payload = json_decode((string) $body, true);

        if (!is_array($payload) || !isset($payload['template']) || !is_string($payload['template'])) {
            return $this->http(
                400,
                ['ok' => false, 'error' => ['code' => 'INVALID_PAYLOAD', 'message' => 'Missing template']]
            );
        }

        $components = $payload['components'] ?? [];
        if (!is_array($components)) {
            $components = [];
        }

        try {
            $components = $this->resolveComponentEntries($components);
        } catch (RuntimeException $exception) {
            return $this->http(
                500,
                ['ok' => false, 'error' => ['code' => 'COMPILE_ERROR', 'message' => $exception->getMessage()]]
            );
        }

        try {
            $html = $this->pretendToRender($payload['template'], $payload['data'] ?? [], $components);

            return $this->http(200, ['ok' => true, 'html' => $html]);
        } catch (RuntimeException $exception) {
            return $this->http(
                500,
                ['ok' => false, 'error' => ['code' => 'COMPILE_ERROR', 'message' => $exception->getMessage()]]
            );
        }
    }

    /**
     * Simulates renderToString() for the minuscule subset the wire spec needs:
     * interpolations and — when a components map is supplied — tag resolution.
     * Returns stable, deterministic HTML.
     *
     * @param string               $template   Vue template string.
     * @param array<string, mixed> $data       SSR data scope.
     * @param array<string, mixed> $components Optional map name => ESM module source.
     *
     * @return string Pretend-rendered HTML.
     */
    private function pretendToRender(string $template, array $data, array $components = []): string
    {
        $trimmed = trim($template);

        if ($trimmed === '<div>{{ msg }}</div>') {
            return sprintf('<div>%s</div>', htmlspecialchars((string) ($data['msg'] ?? ''), ENT_QUOTES, 'UTF-8'));
        }

        if (str_contains($trimmed, 'items.length')) {
            return sprintf('<div>%d</div>', count($data['items'] ?? []));
        }

        // Vue SSR semantics for custom tags: registered components resolve to
        // their root element, unregistered ones render as comment placeholders.
        if ($this->hasCustomTag($trimmed)) {
            $out = $template;

            foreach ($components as $name => $source) {
                if (!is_string($name) || !is_string($source)) {
                    continue;
                }

                $rootTag = $this->rootTagFromModule($source);

                if ($rootTag === '') {
                    continue;
                }

                foreach ([$name, $this->kebabCase($name)] as $tag) {
                    $out = $this->replaceComponentTag($out, $tag, $rootTag);
                }
            }

            $out = preg_replace($this->customTagPattern(), '<!-- -->', $out);

            return rtrim((string) $out);
        }

        throw new RuntimeException("Unsupported fake template: {$template}");
    }

    /**
     * Normalises the content-addressed components map back to Name => source.
     *
     * Mirrors resolveModule() in ssr/server.ts: an entry is either a bare
     * source string (legacy) or a {h, s?} envelope. When 's' is present the
     * module is registered under the supplied hash; when only 'h' is present
     * the hash must already be known, otherwise the sidecar raises
     * COMPILE_ERROR exactly like the Bun implementation does.
     *
     * @param array<array-key, mixed> $components Raw components map from the envelope.
     *
     * @return array<string, string> Name => ESM module source.
     *
     * @throws RuntimeException When a hash-only entry references an unknown module.
     */
    private function resolveComponentEntries(array $components): array
    {
        $resolved = [];

        foreach ($components as $name => $entry) {
            if (!is_string($name)) {
                continue;
            }

            if (is_string($entry)) {
                // Legacy shape: the hash is derived server-side.
                $this->moduleCache[substr(hash('sha256', $entry), 0, 12)] = $entry;
                $resolved[$name] = $entry;

                continue;
            }

            if (!is_array($entry) || !isset($entry['h']) || !is_string($entry['h'])) {
                continue;
            }

            $hash = $entry['h'];
            $source = $entry['s'] ?? null;

            if (is_string($source) && $source !== '') {
                $this->moduleCache[$hash] = $source;
                $resolved[$name] = $source;

                continue;
            }

            if (!isset($this->moduleCache[$hash])) {
                throw new RuntimeException(
                    "Component hash '{$hash}' not found in sidecar cache and no source provided."
                );
            }

            $resolved[$name] = $this->moduleCache[$hash];
        }

        return $resolved;
    }

    /**
     * Determines whether a template string contains a custom component tag
     * (PascalCase or kebab-case), which Vue would resolve via components.
     */
    private function hasCustomTag(string $template): bool
    {
        return preg_match($this->customTagPattern(), $template) === 1;
    }

    /**
     * Matches opening/self-closing tags of custom components: `<PhButton .../>`,
     * `<ph-button ...>`, `</PhButton>`, `</ph-button>`.
     */
    private function customTagPattern(): string
    {
        return '~</?([A-Z][A-Za-z0-9]*|(?:[a-z][a-z0-9]*)-[a-z0-9-]+)(?=[\s/>])[^>]*>~';
    }

    /**
     * Converts a PascalCase component name to kebab-case (`PhButton` => `ph-button`).
     */
    private function kebabCase(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }

    /**
     * Extracts the root tag name from a module source's `__phueTemplate` export.
     *
     * @return string Root tag name, or '' if it cannot be derived.
     */
    private function rootTagFromModule(string $source): string
    {
        if (!preg_match('/__phueTemplate\s*=\s*"\\s*<([a-zA-Z][a-zA-Z0-9]*)/', $source, $matches)) {
            return '';
        }

        return $matches[1];
    }

    /**
     * Replaces occurrences of a component tag (self-closing or paired) with the
     * component root element, mirroring client-side hydration parity at a
     * wire-test granularity.
     */
    private function replaceComponentTag(string $template, string $tag, string $rootTag): string
    {
        $quoted = preg_quote($tag, '~');

        $template = (string) preg_replace(
            '~<' . $quoted . '(?=[\s>])[^>]*/>~',
            '<' . $rootTag . ' data-phue-fake="' . $tag . '"></' . $rootTag . '>',
            $template
        );

        $template = (string) preg_replace(
            '~<' . $quoted . '(?=[\s>])[^>]*>(.*?)</' . $quoted . '>~s',
            '<' . $rootTag . ' data-phue-fake="' . $tag . '">$1</' . $rootTag . '>',
            $template
        );

        return $template;
    }

    /**
     * Builds a minimal HTTP/1.1 JSON response with Content-Length framing.
     *
     * @param int   $status HTTP status code.
     * @param mixed $body   JSON-serializable payload.
     *
     * @return string Raw HTTP response.
     */
    private function http(int $status, mixed $body): string
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);

        $reason = match ($status) {
            200     => 'OK',
            400     => 'Bad Request',
            404     => 'Not Found',
            500     => 'Internal Server Error',
            default => 'Unknown',
        };

        return sprintf(
            "HTTP/1.1 %d %s\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            $status,
            $reason,
            strlen($json),
            $json
        );
    }
}