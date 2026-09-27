<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Ssr;

use Phue\Core\Ssr\BunSsrEngine;
use Phue\Core\Ssr\SsrClient;
use Phue\Core\Ssr\UnixSsrClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ComponentSsrTest — freezes the PHP-side contract for nested SSR: component
 * module sources declared in the render context MUST be transmitted inside the
 * envelope (`components` map), and MUST drive resolution of the tags that
 * reference them once the sidecar honours them.
 *
 * Two layers:
 *  1. Envelope contract (recording transport double) — no sockets.
 *  2. End-to-end engine + transport + wire double (child process) — the same
 *     path a real page render takes, without the Bun runtime.
 *
 * @package Phue\Tests\Core\Ssr
 */
#[Group('integration')]
final class ComponentSsrTest extends TestCase
{
    private const SOCKET_PATH = '/tmp/phue-ssr-component-test.sock';

    /** @var resource|null */
    private $serverProcess = null;

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
            $this->serverProcess = null;
        }

        @unlink(self::SOCKET_PATH);

        parent::tearDown();
    }

    public function testEngineAttachesComponentsToEnvelope(): void
    {
        // A per-run nonce keeps this first-sight: BunSsrEngine tracks the hashes
        // the sidecar has already confirmed in a *static* registry, so a fixed
        // source string would be omitted from 's' on the second run of the suite.
        $source = 'export const __phueTemplate = "<button></button>"; export default { name: "PhButton" };'
            . '/*' . bin2hex(random_bytes(8)) . '*/';

        $client = new RecordingSsrClient('{"ok":true,"html":"<div>ok</div>"}');

        $engine = new BunSsrEngine($client);

        $engine->render(
            template: '<div class="page"><ph-button /></div>',
            state: ['title' => 'Demo'],
            context: ['components' => ['PhButton' => $source]]
        );

        $envelope = $client->lastPayload();

        $this->assertNotNull($envelope);
        $this->assertSame(1, $envelope['v']);
        $this->assertSame('<div class="page"><ph-button /></div>', $envelope['template']);
        $this->assertSame(['title' => 'Demo'], $envelope['data']);

        // Contract: components travel content-addressed as {h, s?}, never as a
        // bare source string, so the sidecar can answer with hashes alone.
        $this->assertSame(
            [
                'PhButton' => [
                    'h' => substr(hash('sha256', $source), 0, 12),
                    's' => $source,
                ],
            ],
            $envelope['components']
        );
    }

    public function testEngineOmitsSourceOnRepeatRenderForAKnownHash(): void
    {
        // Content-addressed contract: once the sidecar has confirmed a hash, the
        // next envelope carries the hash only. This is what shrinks a 45-dep
        // page payload from ~497 KB to ~12 KB.
        $source = 'export const __phueTemplate = "<button></button>"; export default {};'
            . '/*' . bin2hex(random_bytes(8)) . '*/';

        $hash = substr(hash('sha256', $source), 0, 12);
        $client = new RecordingSsrClient('{"ok":true,"html":"<div>ok</div>"}');
        $engine = new BunSsrEngine($client);

        $context = ['components' => ['PhButton' => $source]];

        $engine->render(template: '<div><ph-button /></div>', state: [], context: $context);
        $first = $client->lastPayload();

        $this->assertNotNull($first);
        $this->assertSame(['h' => $hash, 's' => $source], $first['components']['PhButton']);

        $engine->render(template: '<div><ph-button /></div>', state: [], context: $context);
        $second = $client->lastPayload();

        $this->assertNotNull($second);
        $this->assertSame(['h' => $hash], $second['components']['PhButton']);
        $this->assertArrayNotHasKey('s', $second['components']['PhButton']);
    }

    public function testEngineDropsTrackedHashesAfterATransportFailure(): void
    {
        // Self-healing contract: a transport failure must invalidate the local
        // hash registry, otherwise the next render would send hashes the
        // (possibly restarted) sidecar has never seen and silently lose its
        // child components.
        $source = 'export const __phueTemplate = "<button></button>"; export default {};'
            . '/*' . bin2hex(random_bytes(8)) . '*/';

        $context = ['components' => ['PhButton' => $source]];

        $healthy = new RecordingSsrClient('{"ok":true,"html":"<div>ok</div>"}');
        (new BunSsrEngine($healthy))->render(template: '<div><ph-button /></div>', state: [], context: $context);
        $this->assertArrayHasKey('s', (array) ($healthy->lastPayload()['components']['PhButton'] ?? []));

        // Sidecar dies: transport returns null.
        $dead = new RecordingSsrClient('null');
        $this->assertNull(
            (new BunSsrEngine($dead))->render(template: '<div><ph-button /></div>', state: [], context: $context)
        );

        // Next render must re-transmit the full source.
        $recovered = new RecordingSsrClient('{"ok":true,"html":"<div>ok</div>"}');
        (new BunSsrEngine($recovered))->render(template: '<div><ph-button /></div>', state: [], context: $context);
        $payload = $recovered->lastPayload();

        $this->assertNotNull($payload);
        $this->assertArrayHasKey('s', $payload['components']['PhButton']);
    }

    public function testEngineOmitsComponentsKeyWhenContextHasNone(): void
    {
        $client = new RecordingSsrClient('{"ok":true,"html":"<div>ok</div>"}');

        $engine = new BunSsrEngine($client);

        $engine->render(template: '<div>{{ msg }}</div>', state: ['msg' => 'hi']);

        $envelope = $client->lastPayload();

        $this->assertNotNull($envelope);
        $this->assertArrayNotHasKey('components', $envelope);
        $this->assertArrayHasKey('template', $envelope);
        $this->assertArrayHasKey('data', $envelope);
    }

    public function testEngineIgnoresNonArrayComponentContext(): void
    {
        $client = new RecordingSsrClient('{"ok":true,"html":"<div>ok</div>"}');

        $engine = new BunSsrEngine($client);

        $engine->render(
            template: '<div>{{ msg }}</div>',
            state: ['msg' => 'hi'],
            context: ['components' => 'not-an-array']
        );

        $envelope = $client->lastPayload();

        $this->assertNotNull($envelope);
        $this->assertArrayNotHasKey('components', $envelope);
    }

    public function testEngineRendersNestedComponentEndToEnd(): void
    {
        $this->serverProcess = $this->spawnFakeSidecarServer(self::SOCKET_PATH);

        $client = new UnixSsrClient(self::SOCKET_PATH);
        $engine = new BunSsrEngine($client);

        $html = $engine->render(
            template: '<div class="page"><ph-button variant="primary" /></div>',
            state: [],
            context: [
                'components' => [
                    'PhButton' => 'export const __phueTemplate = "<button class=\"btn btn-primary\"></button>"; export default {};',
                ],
            ]
        );

        $this->assertIsString($html);
        $this->assertStringContainsString('<button', $html);
        $this->assertStringNotContainsString('<ph-button', $html);
    }

    public function testEngineKeepsUnknownTagAsCommentWithoutComponents(): void
    {
        $this->serverProcess = $this->spawnFakeSidecarServer(self::SOCKET_PATH);

        $client = new UnixSsrClient(self::SOCKET_PATH);
        $engine = new BunSsrEngine($client);

        $html = $engine->render(template: '<div class="page"><ph-button /></div>', state: []);

        $this->assertIsString($html);
        $this->assertStringContainsString('<!-- -->', $html);
    }

    private function spawnFakeSidecarServer(string $socketPath)
    {
        $script = dirname(__DIR__) . '/Stubs/FakeSidecarServer.php';

        $process = proc_open(
            [PHP_BINARY, $script, $socketPath],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 3)
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start FakeSidecarServer child process.');
        }

        $this->waitForSocket($socketPath, $process);

        return $process;
    }

    private function waitForSocket(string $socketPath, $process): void
    {
        $deadline = microtime(true) + 3.0;

        while (microtime(true) < $deadline) {
            $client = @stream_socket_client('unix://' . $socketPath, $errno, $errstr, 0.1);

            if ($client !== false) {
                fclose($client);

                return;
            }

            usleep(50_000);
        }

        proc_terminate($process);
        proc_close($process);

        throw new RuntimeException("FakeSidecarServer did not bind {$socketPath} in time.");
    }
}

/**
 * In-memory SsrClient double that records raw payloads so the envelope shape
 * can be asserted without any process or socket I/O.
 */
final class RecordingSsrClient implements SsrClient
{
    /** @var array<int, array<string, mixed>> */
    private array $payloads = [];

    private string $response;

    public function __construct(string $response = '{"ok":true,"html":"<div>ok</div>"}')
    {
        $this->response = $response;
    }

    public function send(string $method = 'POST', string $path = '/v1/render', string $payload = ''): ?string
    {
        $decoded = json_decode($payload, true);

        if (is_array($decoded)) {
            $this->payloads[] = $decoded;
        }

        return $this->response;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function health(): ?array
    {
        return ['ok' => true, 'vue' => '3.5.42', 'bun' => '1.3.0'];
    }

    public function lastPayload(): ?array
    {
        $count = count($this->payloads);

        return $count > 0 ? $this->payloads[$count - 1] : null;
    }
}