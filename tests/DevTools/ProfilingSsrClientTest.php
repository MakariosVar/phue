<?php

declare(strict_types=1);

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Ssr\SsrClient;
use Phue\Modules\DevTool\ProfilingSsrClient;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;
use RuntimeException;

/**
 * A scriptable transport double. Records the calls it received and replays a queued
 * response, so the decorator can be asserted without a live Bun sidecar.
 */
final class FakeSsrClient implements SsrClient
{
    /** @var list<array{method: string, path: string, bytes: int, headers: array<string, string>}> */
    public array $calls = [];

    public bool $failWithNull = false;

    public ?\Throwable $throw = null;

    public function send(
        string $method = 'POST',
        string $path = '/v1/render',
        string $payload = '',
        array $headers = []
    ): ?string {
        $this->calls[] = [
            'method' => $method,
            'path' => $path,
            'bytes' => strlen($payload),
            'headers' => $headers,
        ];

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->failWithNull ? null : '{"ok":true}';
    }

    public function isAvailable(): bool
    {
        $this->calls[] = ['method' => 'GET', 'path' => '/v1/health', 'bytes' => 0, 'headers' => []];

        return !$this->failWithNull;
    }

    public function health(): ?array
    {
        $this->calls[] = ['method' => 'GET', 'path' => '/v1/health', 'bytes' => 0, 'headers' => []];

        return $this->failWithNull ? null : ['ok' => true];
    }
}

#[Group('unit')]
class ProfilingSsrClientTest extends TestCase
{
    private FakeSsrClient $inner;
    private RequestProfiler $profiler;
    private ProfilingSsrClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inner = new FakeSsrClient();
        $this->profiler = new RequestProfiler(new Stopwatch());
        $this->profiler->start();
        $this->client = new ProfilingSsrClient($this->inner, $this->profiler);
    }

    public function testSendReturnsTheInnerResponseUnchanged(): void
    {
        $this->assertSame('{"ok":true}', $this->client->send());
    }

    public function testSendForwardsEveryArgument(): void
    {
        $this->client->send('POST', '/v1/render', 'payload-bytes', ['Content-Encoding' => 'gzip']);

        $this->assertCount(1, $this->inner->calls);
        $this->assertSame('POST', $this->inner->calls[0]['method']);
        $this->assertSame('/v1/render', $this->inner->calls[0]['path']);
        $this->assertSame(13, $this->inner->calls[0]['bytes']);
        $this->assertSame(['Content-Encoding' => 'gzip'], $this->inner->calls[0]['headers']);
    }

    public function testFailOpenNullIsPassedThrough(): void
    {
        $this->inner->failWithNull = true;

        $this->assertNull($this->client->send());
    }

    public function testSendIsRecordedAsASpan(): void
    {
        $this->client->send('POST', '/v1/render', 'abc', ['Content-Encoding' => 'gzip']);

        $spans = $this->profiler->getSpans();

        $this->assertCount(1, $spans);
        $this->assertSame('ssr.send', $spans[0]['label']);
        $this->assertSame('/v1/render', $spans[0]['meta']['path']);
        $this->assertSame('POST', $spans[0]['meta']['method']);
        $this->assertSame(3, $spans[0]['meta']['request_bytes']);
        $this->assertTrue($spans[0]['meta']['gzip']);
    }

    public function testUncompressedPayloadIsFlagged(): void
    {
        $this->client->send('POST', '/v1/render', 'abc');

        $this->assertFalse($this->profiler->getSpans()[0]['meta']['gzip']);
    }

    public function testEveryRoundTripIsCounted(): void
    {
        // The measurement that matters for a cache-miss retry loop: how many times did we
        // actually talk to the sidecar during one request?
        for ($i = 0; $i < 4; $i++) {
            $this->client->send();
        }

        $aggregate = $this->profiler->getAggregates()['ssr.send'];

        $this->assertSame(4, $aggregate['count']);
    }

    public function testSpanIsClosedWhenTheTransportThrows(): void
    {
        $this->inner->throw = new RuntimeException('socket gone');

        try {
            $this->client->send();
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('socket gone', $e->getMessage());
        }

        $this->assertSame(0, $this->profiler->getOpenSpanCount());
        $this->assertCount(1, $this->profiler->getSpans());
    }

    public function testNullResponseIsStillRecorded(): void
    {
        $this->inner->failWithNull = true;

        $this->client->send();

        $this->assertCount(1, $this->profiler->getSpans());
        $this->assertSame('ssr.send', $this->profiler->getSpans()[0]['label']);
    }

    public function testHealthProbeIsRecordedSeparatelyFromRender(): void
    {
        $this->client->send();
        $this->client->health();

        $labels = array_column($this->profiler->getSpans(), 'label');

        $this->assertContains('ssr.send', $labels);
        $this->assertContains('ssr.health', $labels);
    }

    public function testHealthReturnValueIsUnchanged(): void
    {
        $this->assertSame(['ok' => true], $this->client->health());
    }

    public function testIsAvailableDelegatesAndRecords(): void
    {
        $this->assertTrue($this->client->isAvailable());
        $this->assertSame('ssr.health', $this->profiler->getSpans()[0]['label']);
    }
}
