<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Ssr;

use Phue\Core\Ssr\BunSsrEngine;
use Phue\Core\Ssr\SsrClient;
use Phue\Core\Ssr\UnixSsrClient;
use RuntimeException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PhueSsrClientTest — exercises the PHP-side SSR transport (UnixSsrClient) and
 * the high-level fail-open engine (BunSsrEngine) against a live wire double.
 *
 * The FakeSidecarServer runs tests/Core/Stubs/FakeSidecarServer.php in a child
 * process (the same wire contract as ssr/server.ts), so THIS suite performs
 * real blocking socket I/O without the Bun runtime: happy paths, health
 * decomposition, fail-open (socket refused / non-200 / malformed envelopes) and
 * the payload-size guard are locked down here.
 *
 * @package Phue\Tests\Core\Ssr
 */
#[Group('integration')]
final class PhueSsrClientTest extends TestCase
{
    private const SOCKET_PATH = '/tmp/phue-ssr-client-test.sock';

    private const MISSING_SOCKET_PATH = '/tmp/phue-ssr-client-test-missing.sock';

    /** @var resource|null */
    private $serverProcess = null;

    private UnixSsrClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverProcess = $this->spawnFakeSidecarServer(self::SOCKET_PATH);
        $this->client = new UnixSsrClient(self::SOCKET_PATH);
    }

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

    public function testSendReturnsRawEnvelopeOnValidRenderOverPostV1Render(): void
    {
        $payload = json_encode(
            ['v' => 1, 'template' => '<div>{{ msg }}</div>', 'data' => ['msg' => '<script>alert(1)</script>']],
            JSON_THROW_ON_ERROR
        );

        $raw = $this->client->send(method: 'POST', path: '/v1/render', payload: $payload);

        $this->assertIsString($raw);

        $response = json_decode($raw, true);

        $this->assertIsArray($response);
        $this->assertTrue($response['ok']);
        $this->assertSame('<div>&lt;script&gt;alert(1)&lt;/script&gt;</div>', $response['html']);
    }

    public function testSendReturnsNullOnNon200Response(): void
    {
        $this->assertNull($this->client->send(method: 'GET', path: '/v1/nope'));
    }

    public function testSendReturnsNullWhenSocketIsMissing(): void
    {
        $orphan = new UnixSsrClient(self::MISSING_SOCKET_PATH);

        $this->assertNull($orphan->send(method: 'POST', path: '/v1/render', payload: '{}'));
    }

    public function testSendReturnsNullWhenSocketIsMissingDoesNotThrow(): void
    {
        $orphan = new UnixSsrClient(self::MISSING_SOCKET_PATH);

        try {
            $orphan->send(method: 'POST', path: '/v1/render', payload: '{}');
            $this->addToAssertionCount(1);
        } catch (\Throwable $exception) {
            $this->fail('Fail-open client must never bubble exceptions: ' . $exception->getMessage());
        }
    }

    public function testHealthReturnsVueAndBunEnvelope(): void
    {
        $health = $this->client->health();

        $this->assertIsArray($health);
        $this->assertTrue($health['ok']);
        $this->assertSame('3.5.42', $health['vue']);
        $this->assertSame('1.3.0', $health['bun']);
    }

    public function testHealthReturnsNullWhenSidecarIsUnreachable(): void
    {
        $orphan = new UnixSsrClient(self::MISSING_SOCKET_PATH);

        $this->assertNull($orphan->health());
    }

    public function testHealthReturnsNullOnMalformedJsonBody(): void
    {
        $client = $this->createPartialMock(UnixSsrClient::class, ['send']);
        $client->method('send')->willReturn('not-json-at-all');

        $this->assertNull($client->health());
    }

    public function testIsAvailableFollowsHealthProbe(): void
    {
        $this->assertTrue($this->client->isAvailable());

        $orphan = new UnixSsrClient(self::MISSING_SOCKET_PATH);
        $this->assertFalse($orphan->isAvailable());
    }

    public function testEngineReturnsHtmlOnHappyPath(): void
    {
        $engine = new BunSsrEngine($this->client);

        $html = $engine->render('<div>{{ msg }}</div>', ['msg' => '<script>alert(1)</script>']);

        $this->assertSame('<div>&lt;script&gt;alert(1)&lt;/script&gt;</div>', $html);
    }

    public function testEngineReturnsNullWhenEnvelopeReportsFailure(): void
    {
        $client = $this->createMock(SsrClient::class);
        $client->method('send')->willReturn(
            '{"ok":false,"error":{"code":"COMPILE_ERROR","message":"boom"}}'
        );

        $engine = new BunSsrEngine($client);

        $this->assertNull($engine->render('<div>{{ broken'));
    }

    public function testEngineReturnsNullOnMalformedEnvelope(): void
    {
        $client = $this->createMock(SsrClient::class);
        $client->method('send')->willReturn('garbage-body');

        $engine = new BunSsrEngine($client);

        $this->assertNull($engine->render('<div>hi</div>'));
    }

    public function testEngineReturnsNullWhenHtmlIsNotAString(): void
    {
        $client = $this->createMock(SsrClient::class);
        $client->method('send')->willReturn('{"ok":true,"html":null}');

        $engine = new BunSsrEngine($client);

        $this->assertNull($engine->render('<div>hi</div>'));
    }

    public function testEngineDoesNotDispatchOversizedPayload(): void
    {
        $client = $this->createMock(SsrClient::class);
        $client->expects($this->never())->method('send');

        $engine = new BunSsrEngine($client, maxPayloadKb: 1);

        $oversized = '<div>' . str_repeat('a', 2048) . '</div>';

        $this->assertNull($engine->render($oversized, ['msg' => 'value']));
    }

    public function testEngineRejectsOversizedPayloadAgainstLiveServer(): void
    {
        $engine = new BunSsrEngine($this->client, maxPayloadKb: 1);

        $oversized = '<div>' . str_repeat('a', 4096) . '</div>';

        $this->assertNull($engine->render($oversized, ['msg' => 'value']));
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