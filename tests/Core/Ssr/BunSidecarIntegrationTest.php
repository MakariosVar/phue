<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Ssr;

use Phue\Core\Config;
use Phue\Core\UI\PhueComponentLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * BunSidecarIntegrationTest — exercises the real ssr/server.ts sidecar over the
 * abstract unix socket (Contract 3.2). Skipped when Bun (or a running sidecar)
 * is not available, e.g. in a minimal CI environment.
 *
 * @package Phue\Tests\Core\Ssr
 */
#[Group('integration')]
final class BunSidecarIntegrationTest extends TestCase
{
    private const DEFAULT_VUE = '3.5.42';

    private static function socketPath(): string
    {
        $config = new Config('/app/config');

        return (string) $config->get('ssr.socket', "\0phue-ssr");
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::sidecarReachable()) {
            self::markTestSkipped(sprintf('Bun sidecar is not reachable on "%s".', self::socketPath()));
        }
    }

    public function testHealthReportsOkWithVueAndBunVersions(): void
    {
        $response = $this->request('GET', '/v1/health', null);

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['json']['ok']);
        $this->assertTrue(isset($response['json']['vue']));
        $this->assertTrue(isset($response['json']['bun']));
    }

    public function testHealthVueVersionMatchesVersionLock(): void
    {
        $response = $this->request('GET', '/v1/health', null);

        $this->assertSame(self::DEFAULT_VUE, $response['json']['vue']);
    }

    public function testRenderEscapesHtmlInterpolation(): void
    {
        $response = $this->request('POST', '/v1/render', [
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

    public function testRenderRejectsMissingTemplatePayload(): void
    {
        $response = $this->request('POST', '/v1/render', ['data' => ['msg' => 'x']]);

        $this->assertSame(400, $response['status']);
        $this->assertFalse($response['json']['ok']);
        $this->assertSame('INVALID_PAYLOAD', $response['json']['error']['code']);
    }

    public function testRenderSurfacesCompileError(): void
    {
        $response = $this->request('POST', '/v1/render', [
            'template' => '<div>{{ 1 + }}</div>', // invalid JS expression in interpolation
            'data'     => [],
        ]);

        $this->assertSame(500, $response['status']);
        $this->assertFalse($response['json']['ok']);
        $this->assertSame('COMPILE_ERROR', $response['json']['error']['code']);
    }

    public function testRenderResolvesRegisteredPhueComponent(): void
    {
        $template = '<div class="page"><ph-button variant="primary">Save</ph-button></div>';

        $response = $this->request('POST', '/v1/render', [
            'template'   => $template,
            'data'       => ['title' => 'Demo'],
            'components' => [
                'PhButton' => $this->phuiModule('PhButton'),
            ],
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['json']['ok']);

        $html = (string) $response['json']['html'];

        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('class="btn btn-primary"', $html);
        $this->assertStringContainsString(
            '<span class="btn__content"><!--[-->Save<!--]--></span></button>',
            $html
        );
    }

    public function testRenderWithUnregisteredComponentEmitsCommentPlaceholder(): void
    {
        $response = $this->request('POST', '/v1/render', [
            'template' => '<div class="page"><ph-button variant="primary" /></div>',
            'data'     => [],
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['json']['ok']);
        $this->assertStringContainsString('<!--', (string) $response['json']['html']);
    }

    public function testUnknownHealthlessRouteReturnsNotFound(): void
    {
        $response = $this->request('GET', '/v1/nope', null);

        $this->assertSame(404, $response['status']);
        $this->assertFalse($response['json']['ok']);
        $this->assertSame('NOT_FOUND', $response['json']['error']['code']);
    }

    /**
     * Returns true when the Bun sidecar is currently listening on the socket.
     */
    private static function sidecarReachable(): bool
    {
        $client = @stream_socket_client('unix://' . self::socketPath(), $errno, $errstr, 0.5);

        if ($client !== false) {
            fclose($client);

            return true;
        }

        return false;
    }

    /**
     * Builds the SSR-ready ESM module source the framework ships for a Phui
     * component (`export const __phueSsrRender` + `export default {...}`),
     * so the sidecar receives SSR-capable code.
     */
    private function phuiModule(string $name): string
    {
        $loader = new PhueComponentLoader(dirname(__DIR__, 3) . '/src/Core/UI/Phui');
        $module = $loader->buildForSsr($name);

        if ($module === null || trim($module) === '') {
            self::fail("SSR module not available for {$name}.");
        }

        return $module;
    }

    /**
     * Sends one raw HTTP/1.1 request over the abstract unix socket and parses
     * the Contract 3.2 response envelope.
     *
     * @param string               $method HTTP method.
     * @param string               $path   Request path.
     * @param array<string, mixed>|null $body JSON body, or null for a bodiless request.
     *
     * @return array{status: int, json: array<string, mixed>}
     */
    private function request(string $method, string $path, ?array $body): array
    {
        /* @var resource|false $socket */
        $socket = @stream_socket_client('unix://' . self::socketPath(), $errno, $errstr, 2.0);

        if ($socket === false) {
            throw new RuntimeException("Cannot connect to Bun sidecar: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 5);

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