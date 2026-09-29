<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Ssr;

use Phue\Core\Cache\FileCache;
use Phue\Core\Ssr\BunSsrEngine;
use Phue\Core\Ssr\SsrClient;
use Phue\Core\Ssr\UnixSsrClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RecordingSsrClient as ScriptedSsrClient;
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

    private ?string $hashCacheDir = null;

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
            $this->serverProcess = null;
        }

        @unlink(self::SOCKET_PATH);

        if ($this->hashCacheDir !== null) {
            $this->rmTree($this->hashCacheDir);
            $this->hashCacheDir = null;
        }

        parent::tearDown();
    }

    /**
     * A throwaway PSR-16 directory for the hash registry, one per test method.
     *
     * Per-test rather than shared, so a hash remembered by one test can never
     * vouch for the sidecar in the next one.
     */
    private function hashCacheDir(): string
    {
        if ($this->hashCacheDir === null) {
            $this->hashCacheDir = sys_get_temp_dir() . '/phue_component_ssr_hashes_' . uniqid();
            mkdir($this->hashCacheDir, 0777, true);
        }

        return $this->hashCacheDir;
    }

    private function rmTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . '/' . $item;
            is_dir($full) ? $this->rmTree($full) : @unlink($full);
        }

        @rmdir($path);
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
        //
        // The engine is given an explicit PSR-16 store: the memory of which
        // hashes the sidecar holds is deliberately NOT process-scoped, because
        // under FPM a process lasts exactly one request and the optimisation
        // would never fire. See SsrKnownHashesTest for the full contract.
        $source = 'export const __phueTemplate = "<button></button>"; export default {};'
            . '/*' . bin2hex(random_bytes(8)) . '*/';

        $hash = substr(hash('sha256', $source), 0, 12);
        $client = new RecordingSsrClient('{"ok":true,"html":"<div>ok</div>"}');
        $engine = new BunSsrEngine($client, 512, new FileCache($this->hashCacheDir()));

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

    public function testEngineKeepsTrackedHashesAfterATransportFailure(): void
    {
        // A transport failure says nothing about the sidecar's module cache.
        //
        // The previous contract here was "any failure invalidates the registry",
        // which assumed a dead socket implies a restarted sidecar with a cold
        // cache. That inference is wrong for this sidecar: resolveModule() falls
        // back to the on-disk CACHE_DIR when the in-memory Map misses
        // (ssr/server.ts:61-72), so a restart still resolves hash-only entries.
        // When the disk cache really is gone, the sidecar says so explicitly
        // ("not found in sidecar cache") — that is the only evidence worth
        // acting on.
        //
        // Evicting on every failure is also self-defeating: one transient
        // timeout would drop all 47 known hashes and permanently push the next
        // page back to a 512 KB envelope.
        $source = 'export const __phueTemplate = "<button></button>"; export default {};'
            . '/*' . bin2hex(random_bytes(8)) . '*/';

        $context = ['components' => ['PhButton' => $source]];
        $cache = new FileCache($this->hashCacheDir());

        $healthy = new RecordingSsrClient('{"ok":true,"html":"<div>ok</div>"}');
        (new BunSsrEngine($healthy, 512, $cache))->render(template: '<div><ph-button /></div>', state: [], context: $context);
        $this->assertArrayHasKey('s', (array) ($healthy->lastPayload()['components']['PhButton'] ?? []));

        // Sidecar dies: transport returns null.
        $dead = new RecordingSsrClient('null');
        $this->assertNull(
            (new BunSsrEngine($dead, 512, $cache))->render(template: '<div><ph-button /></div>', state: [], context: $context)
        );

        // Next render stays on the slim envelope.
        $recovered = new RecordingSsrClient('{"ok":true,"html":"<div>ok</div>"}');
        (new BunSsrEngine($recovered, 512, $cache))->render(template: '<div><ph-button /></div>', state: [], context: $context);
        $payload = $recovered->lastPayload();

        $this->assertNotNull($payload);
        $this->assertArrayNotHasKey(
            's',
            $payload['components']['PhButton'],
            'A dead socket is not evidence that the sidecar forgot a module. The hashes must survive it.'
        );
    }

    public function testEngineReTransmitsFullSourceWhenTheSidecarReportsAModuleCacheMiss(): void
    {
        // The genuine self-healing path: the sidecar answers that it does not
        // hold a hash we believed it held. The engine must evict that belief
        // and re-send the source, or the page would render with silently
        // unresolved child components forever.
        $source = 'export const __phueTemplate = "<button></button>"; export default {};'
            . '/*' . bin2hex(random_bytes(8)) . '*/';

        $context = ['components' => ['PhButton' => $source]];
        $cache = new FileCache($this->hashCacheDir());

        (new BunSsrEngine(new ScriptedSsrClient(['{"ok":true,"html":"<div>ok</div>"}']), 512, $cache))
            ->render(template: '<div><ph-button /></div>', state: [], context: $context);

        $cacheMiss = json_encode([
            'ok' => false,
            'error' => [
                'code' => 'COMPILE_ERROR',
                'message' => 'Component hash \'x\' not found in sidecar cache and no source provided.',
            ],
        ], JSON_THROW_ON_ERROR);

        $client = new ScriptedSsrClient([
            $cacheMiss,
            '{"ok":true,"html":"<div>recovered</div>"}',
        ]);

        $html = (new BunSsrEngine($client, 512, $cache))
            ->render(template: '<div><ph-button /></div>', state: [], context: $context);

        $this->assertSame('<div>recovered</div>', $html, 'A reported cache-miss must be retried, not surfaced as a silent null.');
        $this->assertSame(2, $client->dispatchCount(), 'Recovery costs exactly one extra dispatch.');
        $this->assertSame(
            ['PhButton'],
            $client->fullSourceComponents(1),
            'The retry must carry the source the sidecar is missing.'
        );
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