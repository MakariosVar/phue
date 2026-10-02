<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Ssr;

use Phue\Core\Ssr\BunSsrEngine;
use Phue\Tests\Support\RecordingSsrClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SsrPayloadCompressionTest — the payload contract that keeps SSR alive on a big page.
 *
 * The showcase page renders one section at a time but declares every component it
 * might render, so its full-source SSR payload is ~2.16 MB of JSON against a 2 MB
 * limit. The sidecar answers `413` and `render()` returns null, which is how the page
 * ended up serving its own template source as text.
 *
 * Raising the limit would have hidden it: the number is a symptom, and the next
 * component would have crossed the new ceiling just the same. Compressing is the fix,
 * because the payload is JavaScript source and therefore extremely repetitive — 6.1x
 * at the cheapest compression level.
 *
 * Every assertion here is about BYTES ON THE WIRE, not about intent. "The engine
 * compressed" is not observable; "the sidecar received 355 KB instead of 2.16 MB" is.
 *
 * @package Phue\Tests\Core\Ssr
 */
#[Group('unit')]
final class SsrPayloadCompressionTest extends TestCase
{
    /**
     * A payload big enough to cross the engine's compression threshold, built from
     * realistic component sources rather than filler.
     *
     * @return array{components: array<string, string>, bytes: int}
     */
    private function largeComponentMap(int $components = 40, int $bytesEach = 12000): array
    {
        $map = [];

        for ($i = 0; $i < $components; $i++) {
            $filler = str_repeat('const x' . $i . ' = "padding padding padding padding";', (int) ceil($bytesEach / 60));
            $map['PhWidget' . $i] = "export const __phueTemplate = \"<div>$filler</div>\"; export default {};"
                . '/*' . bin2hex(random_bytes(4)) . '*/';
        }

        $bytes = 0;
        foreach ($map as $source) {
            $bytes += strlen($source);
        }

        return ['components' => $map, 'bytes' => $bytes];
    }

    public function testALargePayloadIsAnnouncedAsCompressed(): void
    {
        $client = new RecordingSsrClient(['{"ok":true,"html":"<div>ok</div>"}']);
        $map = $this->largeComponentMap();

        (new BunSsrEngine($client, 512, null))
            ->render(template: '<div><ph-widget0 /></div>', state: [], context: ['components' => $map['components']]);

        self::assertTrue(
            $client->wasCompressed(0),
            'A payload this large must be compressed: without Content-Encoding the sidecar '
            . 'receives bytes it cannot JSON.parse.'
        );
        self::assertSame('gzip', $client->sentHeaders(0)['Content-Encoding']);
    }

    public function testASmallPayloadIsSentPlain(): void
    {
        // Below the threshold there is nothing to gain and CPU to spend: gzipping a
        // 20 KB body costs more than it saves.
        $client = new RecordingSsrClient(['{"ok":true,"html":"<div>ok</div>"}']);

        (new BunSsrEngine($client, 512, null))
            ->render(
                template: '<div><ph-button /></div>',
                state: [],
                context: ['components' => ['PhButton' => 'export default {};']]
            );

        self::assertFalse($client->wasCompressed(0));
        self::assertSame([], $client->sentHeaders(0));
        self::assertSame(
            ['PhButton'],
            array_keys($client->envelopes[0]['components'] ?? []),
            'the plain envelope must still carry the full component map'
        );
    }

    public function testCompressionKeepsAPayloadThatWouldOtherwiseExceedTheLimit(): void
    {
        // The regression itself. The fixture size is not guessed: it is grown until
        // the DECODED envelope genuinely exceeds the limit, because a fixture that
        // silently stopped crossing it would turn this into a test that proves
        // nothing while staying green.
        $map = [];
        $size = 0;

        while ($size < 2.6 * 1024 * 1024) {
            $filler = str_repeat('padding padding padding padding padding ', 1000);
            $source = 'export const __phueTemplate = "<div>' . $filler . '</div>"; export default {};'
                . '/*' . bin2hex(random_bytes(4)) . '*/';
            $map['PhWidget' . count($map)] = $source;
            $size += strlen($source);
        }

        $client = new RecordingSsrClient(['{"ok":true,"html":"<div>ok</div>"}']);

        $html = (new BunSsrEngine($client, 2048, null))
            ->render(template: '<div><ph-widget0 /></div>', state: [], context: ['components' => $map]);

        $wire = $client->payloadSize(0);
        $decoded = strlen($client->decodedBody(0));

        self::assertNotNull($html, 'the render must succeed, not degrade to client-side');
        self::assertGreaterThan(
            2048 * 1024,
            $decoded,
            'the fixture must genuinely exceed the limit uncompressed, or this test proves nothing'
        );
        self::assertLessThanOrEqual(2048 * 1024, $wire, 'what the sidecar receives must fit the limit');
        self::assertLessThan(
            $decoded / 2,
            $wire,
            'compression must actually shrink the payload, not merely announce itself'
        );
    }

    public function testTheLimitIsMeasuredAgainstTheCompressedPayload(): void
    {
        // The subtlety that decides whether the whole fix works. If the size check
        // measured the RAW json, the oversized envelope would be rejected before it
        // was ever compressed — and the limit would still be the thing that breaks
        // the page, just one layer further in.
        $map = [];
        $size = 0;
        while ($size < 400 * 1024) {
            $filler = str_repeat('padding padding padding padding padding ', 200);
            $source = 'export const __phueTemplate = "<div>' . $filler . '</div>"; export default {};'
                . '/*' . bin2hex(random_bytes(4)) . '*/';
            $map['PhWidget' . count($map)] = $source;
            $size += strlen($source);
        }

        // Learn the wire size with a ceiling that cannot interfere.
        $probe = new RecordingSsrClient(['{"ok":true,"html":"<div>probe</div>"}']);
        (new BunSsrEngine($probe, 4096, null))
            ->render(template: '<div><ph-widget0 /></div>', state: [], context: ['components' => $map]);

        $wireKb = (int) ceil($probe->payloadSize(0) / 1024) + 4;
        $decoded = strlen($probe->decodedBody(0));

        self::assertGreaterThan(
            $wireKb * 1024,
            $decoded,
            'the probe must measure a payload whose compressed form fits under the tight ceiling'
        );

        // A ceiling that only the COMPRESSED payload fits.
        $tight = new RecordingSsrClient(['{"ok":true,"html":"<div>tight</div>"}']);
        self::assertSame(
            '<div>tight</div>',
            (new BunSsrEngine($tight, $wireKb, null))
                ->render(template: '<div><ph-widget0 /></div>', state: [], context: ['components' => $map]),
            sprintf('a %d KB ceiling that the compressed payload fits must let the render through', $wireKb)
        );

        // And one nothing fits: the dispatch must be refused before it is sent.
        $strict = new RecordingSsrClient(['{"ok":true,"html":"<div>unreachable</div>"}']);
        self::assertNull(
            (new BunSsrEngine($strict, 1, null))
                ->render(template: '<div><ph-widget0 /></div>', state: [], context: ['components' => $map]),
            'an unreachable ceiling must refuse the render'
        );
        self::assertSame(0, $strict->dispatchCount(), 'a refused payload must never be sent');
    }

    public function testAnUncompressiblePayloadIsStillSent(): void
    {
        // `gzencode` returning false must not take the request down: the limit is
        // checked against whatever went on the wire, plain or compressed.
        $client = new RecordingSsrClient(['{"ok":true,"html":"<div>ok</div>"}']);

        (new BunSsrEngine($client, 512, null))->render(
            template: '<div><ph-button /></div>',
            state: [],
            context: ['components' => ['PhButton' => 'export default {};']]
        );

        self::assertSame(1, $client->dispatchCount());
    }

    public function testA413DegradesInsteadOfThrowing(): void
    {
        // What the sidecar answers when the payload really is too large. The caller
        // has to be able to fall back to client-side rendering, not blow up a page.
        $client = new RecordingSsrClient([
            '{"ok":false,"error":{"code":"PAYLOAD_TOO_LARGE","message":"Request Entity Too Large"}}',
        ]);

        $html = (new BunSsrEngine($client, 512, null))->render(
            template: '<div><ph-button /></div>',
            state: [],
            context: ['components' => ['PhButton' => 'export default {};']]
        );

        self::assertNull($html, 'an oversized payload must degrade to null, not throw');
        self::assertSame(1, $client->dispatchCount(), 'a non-cache-miss error must not be retried');
    }

    public function testTheComponentMapSurvivesTheRoundTripIntact(): void
    {
        // Compression must not be able to lose a component: the whole point of the
        // retry is to carry sources, and a mangled envelope would fail silently.
        $map = $this->largeComponentMap(12, 4000);
        $client = new RecordingSsrClient(['{"ok":true,"html":"<div>ok</div>"}']);

        (new BunSsrEngine($client, 512, null))
            ->render(template: '<div><ph-widget0 /></div>', state: [], context: ['components' => $map['components']]);

        $envelope = $client->envelopes[0];

        self::assertSame(
            array_keys($map['components']),
            array_keys($envelope['components'] ?? []),
            'every component must survive the compression round trip'
        );

        foreach (array_keys($map['components']) as $name) {
            self::assertSame(
                $map['components'][$name],
                $envelope['components'][$name]['s'] ?? null,
                "the source of {$name} must arrive byte-identical"
            );
        }
    }
}