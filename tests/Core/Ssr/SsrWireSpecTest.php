<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Ssr;

use Phue\Tests\Core\Stubs\FakeSidecar;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SsrWireSpecTest — freezes the Contract 3.2 wire protocol (HTTP/1.1 + JSON
 * over a Unix socket) without requiring the Bun runtime.
 *
 * The FakeSidecar double behaves exactly like ssr/server.ts at the transport
 * level, so the request framing (start line, headers, Content-Length) and the
 * response envelopes ({ok,html} / {ok:false,error.code}) are locked down here
 * and exercised in CI on every run.
 *
 * @package Phue\Tests\Core\Ssr
 */
#[Group('integration')]
final class SsrWireSpecTest extends TestCase
{
    private const SOCKET_PATH = '/tmp/phue-ssr-wire-spec.sock';

    private FakeSidecar $sidecar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sidecar = new FakeSidecar(self::SOCKET_PATH);
    }

    protected function tearDown(): void
    {
        $this->sidecar->close();

        parent::tearDown();
    }

    public function testHealthEndpointReturnsVersionEnvelope(): void
    {
        $response = $this->send('GET', '/v1/health', null);

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['json']['ok']);
        $this->assertTrue(isset($response['json']['vue']));
        $this->assertTrue(isset($response['json']['bun']));
    }

    public function testRenderReturnsEscapedHtmlOnValidPayload(): void
    {
        $response = $this->send('POST', '/v1/render', [
            'template' => '<div>{{ msg }}</div>',
            'data'     => ['msg' => '<script>alert(1)</script>'],
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['json']['ok']);
        $this->assertSame(
            '<div>&lt;script&gt;alert(1)&lt;/script&gt;</div>',
            $response['json']['html']
        );
    }

    public function testRenderRejectsPayloadWithoutTemplate(): void
    {
        $response = $this->send('POST', '/v1/render', ['data' => ['msg' => 'x']]);

        $this->assertSame(400, $response['status']);
        $this->assertFalse($response['json']['ok']);
        $this->assertSame('INVALID_PAYLOAD', $response['json']['error']['code']);
    }

    public function testRenderSurfacesCompileError(): void
    {
        $response = $this->send('POST', '/v1/render', [
            'template' => '<div>{{ broken', // malformed -> fake raises COMPILE_ERROR
            'data'     => [],
        ]);

        $this->assertSame(500, $response['status']);
        $this->assertFalse($response['json']['ok']);
        $this->assertSame('COMPILE_ERROR', $response['json']['error']['code']);
    }

    public function testUnknownRouteReturnsNotFound(): void
    {
        $response = $this->send('GET', '/v1/nope', null);

        $this->assertSame(404, $response['status']);
        $this->assertFalse($response['json']['ok']);
        $this->assertSame('NOT_FOUND', $response['json']['error']['code']);
    }

    public function testRenderWithGetMethodIsRejected(): void
    {
        $response = $this->send('GET', '/v1/render', ['template' => '<div>x</div>']);

        $this->assertSame(404, $response['status']);
    }

    /**
     * Sends one raw HTTP/1.1 request over the unix socket and parses the
     * response envelope.
     *
     * @param string               $method HTTP method.
     * @param string               $path   Request path.
     * @param array<string, mixed>|null $body JSON body, or null for a bodiless request.
     *
     * @return array{status: int, json: array<string, mixed>}
     */
    private function send(string $method, string $path, ?array $body): array
    {
        /* @var resource|false $socket */
        $socket = @stream_socket_client('unix://' . self::SOCKET_PATH, $errno, $errstr, 5.0);

        if ($socket === false) {
            throw new RuntimeException("Cannot connect to FakeSidecar: {$errstr} ({$errno})");
        }

        $rawBody = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);

        $request = sprintf(
            "%s %s HTTP/1.1\r\nHost: phue-ssr\r\nConnection: close\r\n",
            $method,
            $path
        );

        if ($rawBody !== null) {
            $request .= "Content-Type: application/json\r\nContent-Length: " . strlen($rawBody) . "\r\n";
        }

        $request .= "\r\n" . ($rawBody ?? '');

        fwrite($socket, $request);

        // Serve the buffered connection synchronously, then read the response.
        $this->sidecar->acceptOnce();

        $response = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
        }

        fclose($socket);

        return $this->parse($response);
    }

    /**
     * Parses a raw HTTP/1.1 response into status and decoded JSON envelope.
     *
     * @return array{status: int, json: array<string, mixed>}
     */
    private function parse(string $response): array
    {
        $position = strpos($response, "\r\n\r\n");

        $head = $position === false ? $response : substr($response, 0, $position);
        $body = $position === false ? '' : substr($response, $position + 4);

        if (!preg_match('#^HTTP/1\.1 (\d{3})#', $head, $matches)) {
            throw new RuntimeException("Malformed HTTP response head: {$head}");
        }

        $json = json_decode($body, true);

        if (!is_array($json)) {
            throw new RuntimeException("Malformed JSON envelope: {$body}");
        }

        return [
            'status' => (int) $matches[1],
            'json'   => $json,
        ];
    }
}