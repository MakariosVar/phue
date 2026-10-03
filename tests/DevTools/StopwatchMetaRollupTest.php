<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\DevTool\Stopwatch;

/**
 * An aggregate row must describe every call it summarizes.
 *
 * Metadata was previously taken from whichever span closed last. On a component-heavy
 * page that made the rollup actively misleading: the `loader.buildFor` row claimed
 * `component: ExampleFactory` while its 1227 calls covered roughly 140 different
 * components. The toolbar invites you to filter by name, so the report was confidently
 * pointing at the least relevant name in the request — three calls out of 1227 — and the
 * profiling exercise went after a component that was never hot.
 *
 * A last-wins value is worse than no value: it looks like evidence.
 *
 * Metadata now rolls up. Categorical keys report an exact distinct count plus the most
 * frequent values; numeric keys report min/max/last. Grouping by metadata would shatter
 * one hot operation into one bucket per component, so the rollup stays keyed by label and
 * the distribution is carried inside the row.
 */
#[Group('unit')]
class StopwatchMetaRollupTest extends TestCase
{
    private Stopwatch $stopwatch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stopwatch = new Stopwatch();
    }

    // -------------------------------------------------- the last-wins regression

    public function testAggregateMetaReportsEveryDistinctValueNotOnlyTheLast(): void
    {
        foreach (['PhButton', 'PhCard', 'PhButton', 'PhButton', 'PhIcon'] as $component) {
            $this->stopwatch->measure('loader.buildFor', static function (): void {
            }, ['component' => $component]);
        }

        $meta = $this->stopwatch->getAggregates()['loader.buildFor']['meta'];

        $this->assertSame('values', $meta['component']['kind']);
        $this->assertSame(3, $meta['component']['distinct'], 'Every component the label touched has to be accounted for.');
    }

    public function testAggregateMetaCountsAreTheRealCallCounts(): void
    {
        foreach (['PhButton', 'PhCard', 'PhButton', 'PhButton', 'PhIcon'] as $component) {
            $this->stopwatch->measure('loader.buildFor', static function (): void {
            }, ['component' => $component]);
        }

        $counts = $this->collectCounts($this->stopwatch->getAggregates()['loader.buildFor']['meta']['component']);

        $this->assertSame(3, $counts['PhButton']);
        $this->assertSame(1, $counts['PhCard']);
        $this->assertSame(1, $counts['PhIcon']);
        $this->assertSame(5, array_sum($counts), 'The counts must add up to the label\'s own call count.');
    }

    public function testAggregateMetaIsOrderedByFrequency(): void
    {
        foreach (['a', 'b', 'b', 'c', 'c', 'c'] as $component) {
            $this->stopwatch->measure('loader.buildFor', static function (): void {
            }, ['component' => $component]);
        }

        $top = $this->stopwatch->getAggregates()['loader.buildFor']['meta']['component']['top'];

        $this->assertSame('c', $top[0]['value']);
        $this->assertSame(3, $top[0]['count']);
        $this->assertSame('b', $top[1]['value']);
    }

    public function testAggregateMetaIsNeverAPerCallValueForAMultiCallLabel(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->stopwatch->measure('loader.buildFor', static function (): void {
            }, ['component' => 'component-' . $i]);
        }

        $meta = $this->stopwatch->getAggregates()['loader.buildFor']['meta'];

        $this->assertSame(50, $meta['component']['distinct']);
        $this->assertTrue($meta['component']['truncated']);
    }

    public function testAggregateMetaSurvivesASingleCallLabel(): void
    {
        $this->stopwatch->measure('ssr.send', static function (): void {
        }, ['path' => '/v1/render']);

        $meta = $this->stopwatch->getAggregates()['ssr.send']['meta'];

        $this->assertSame(1, $meta['path']['distinct']);
        $this->assertSame('/v1/render', $meta['path']['top'][0]['value']);
        $this->assertFalse($meta['path']['truncated']);
    }

    // ------------------------------------------------------------------ capping

    public function testReportedValuesAreCappedButDistinctStaysExact(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->stopwatch->measure('op', static function (): void {
            }, ['component' => 'component-' . $i]);
        }

        $meta = $this->stopwatch->getAggregates()['op']['meta']['component'];

        $this->assertCount(Stopwatch::META_VALUE_LIMIT, $meta['top']);
        $this->assertSame(40, $meta['distinct'], 'Capping the report must not shrink the truth.');
        $this->assertTrue($meta['truncated']);
    }

    public function testValuesAtTheCapAreReportedExactly(): void
    {
        $limit = Stopwatch::META_VALUE_LIMIT;

        for ($i = 0; $i < $limit; $i++) {
            $this->stopwatch->measure('op', static function (): void {
            }, ['component' => 'component-' . $i]);
        }

        $meta = $this->stopwatch->getAggregates()['op']['meta']['component'];

        $this->assertCount($limit, $meta['top']);
        $this->assertSame($limit, $meta['distinct']);
        $this->assertFalse($meta['truncated'], 'A histogram that fits must not claim to be cut.');
    }

    public function testEqualCountsKeepAStableOrder(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->stopwatch->measure('op', static function (): void {
            }, ['component' => 'component-' . $i]);
        }

        $first = array_column($this->stopwatch->getAggregates()['op']['meta']['component']['top'], 'value');

        $this->stopwatch->resetSpans();

        for ($i = 0; $i < 30; $i++) {
            $this->stopwatch->measure('op', static function (): void {
            }, ['component' => 'component-' . $i]);
        }

        $second = array_column($this->stopwatch->getAggregates()['op']['meta']['component']['top'], 'value');

        // A report that reorders equal-frequency values between requests cannot be
        // diffed against the previous one.
        $this->assertSame($first, $second);
    }

    // ------------------------------------------------------------ numeric values

    public function testNumericKeysRollUpToMinMaxAndLast(): void
    {
        foreach ([5132, 40529, 900] as $bytes) {
            $this->stopwatch->measure('ssr.send', static function (): void {
            }, ['request_bytes' => $bytes]);
        }

        $meta = $this->stopwatch->getAggregates()['ssr.send']['meta']['request_bytes'];

        $this->assertSame('metric', $meta['kind']);
        $this->assertSame(900, $meta['min']);
        $this->assertSame(40529, $meta['max']);
        $this->assertSame(900, $meta['last']);
        $this->assertArrayNotHasKey('distinct', $meta, 'A numeric key has no distinct values to report.');
    }

    public function testFloatMetadataIsTreatedAsAMetric(): void
    {
        $this->stopwatch->measure('op', static function (): void {
        }, ['ratio' => 0.25]);
        $this->stopwatch->measure('op', static function (): void {
        }, ['ratio' => 0.75]);

        $this->assertSame('metric', $this->stopwatch->getAggregates()['op']['meta']['ratio']['kind']);
    }

    public function testABooleanIsNotMistakenForANumber(): void
    {
        $this->stopwatch->measure('ssr.send', static function (): void {
        }, ['gzip' => false]);
        $this->stopwatch->measure('ssr.send', static function (): void {
        }, ['gzip' => true]);

        $meta = $this->stopwatch->getAggregates()['ssr.send']['meta']['gzip'];

        $this->assertSame('values', $meta['kind'], 'true must not be counted as 1 and false as 0.');
        $this->assertSame(2, $meta['distinct']);
    }

    public function testNullMetadataIsCountedAsItsOwnValue(): void
    {
        $this->stopwatch->measure('op', static function (): void {
        }, ['name' => null]);
        $this->stopwatch->measure('op', static function (): void {
        }, ['name' => null]);

        $meta = $this->stopwatch->getAggregates()['op']['meta']['name'];

        $this->assertSame('values', $meta['kind']);
        $this->assertSame(1, $meta['distinct']);
        $this->assertSame(2, $meta['top'][0]['count']);
        $this->assertNull($meta['top'][0]['value']);
    }

    public function testAKeyKeepsTheClassificationItWasFirstSeenWith(): void
    {
        $this->stopwatch->measure('op', static function (): void {
        }, ['mixed' => 10]);
        $this->stopwatch->measure('op', static function (): void {
        }, ['mixed' => 4]);
        $this->stopwatch->measure('op', static function (): void {
        }, ['mixed' => 'late-string']);

        $meta = $this->stopwatch->getAggregates()['op']['meta']['mixed'];

        // Reshaping mid-request would make the reported structure depend on which span
        // happened to close first, which is exactly the kind of non-determinism a report
        // copied into a bug thread must not have.
        $this->assertSame('metric', $meta['kind']);
        $this->assertSame(4, $meta['min']);
        $this->assertSame(10, $meta['max']);
        $this->assertSame(4, $meta['last']);

        // The string is counted, not folded in. min()/max() over mixed types compare as
        // strings in PHP 8, so a range that absorbed it would report max_ms = 'late-string'.
        $this->assertSame(1, $meta['conflicts']);
    }

    public function testAKeyWithoutTypeChangesReportsNoConflicts(): void
    {
        $this->stopwatch->measure('op', static function (): void {
        }, ['component' => 'PhButton']);
        $this->stopwatch->measure('op', static function (): void {
        }, ['request_bytes' => 10]);

        $aggregates = $this->stopwatch->getAggregates();

        $this->assertArrayNotHasKey('conflicts', $aggregates['op']['meta']['component']);
        $this->assertArrayNotHasKey('conflicts', $aggregates['op']['meta']['request_bytes']);
    }

    // ------------------------------------------------------------------ plumbing

    public function testAggregateNeverExposesTheInternalAccumulator(): void
    {
        $this->stopwatch->measure('op', static function (): void {
        }, ['component' => 'PhButton']);

        $aggregate = $this->stopwatch->getAggregates()['op'];

        $this->assertArrayNotHasKey('metaRollup', $aggregate);
        $this->assertSame(['count', 'total_ms', 'exclusive_ms', 'max_ms', 'min_ms', 'avg_ms', 'meta'], array_keys($aggregate));
    }

    public function testLabelsKeepSeparateHistograms(): void
    {
        $this->stopwatch->measure('loader.load', static function (): void {
        }, ['component' => 'PhButton']);
        $this->stopwatch->measure('loader.buildFor', static function (): void {
        }, ['component' => 'PhCard']);

        $aggregates = $this->stopwatch->getAggregates();

        $this->assertSame('PhButton', $aggregates['loader.load']['meta']['component']['top'][0]['value']);
        $this->assertSame('PhCard', $aggregates['loader.buildFor']['meta']['component']['top'][0]['value']);
    }

    public function testMetadataAddedAfterTheCallStillReachesTheRollup(): void
    {
        // describeResult knows things the caller cannot: returned rows, bytes written, a
        // status. It mutates the recorded span, so it has to land in the histogram too.
        $this->stopwatch->measure(
            'ssr.send',
            static fn(): string => 'ok',
            ['path' => '/v1/render'],
            static fn(string $r): array => ['status' => 200]
        );
        $this->stopwatch->measure(
            'ssr.send',
            static fn(): string => 'ok',
            ['path' => '/v1/render'],
            static fn(string $r): array => ['status' => 500]
        );

        $meta = $this->stopwatch->getAggregates()['ssr.send']['meta'];

        $this->assertSame(1, $meta['path']['distinct']);
        $this->assertSame(2, $meta['path']['top'][0]['count']);
        $this->assertSame(200, $meta['status']['min']);
        $this->assertSame(500, $meta['status']['max']);
    }

    public function testLabelWithoutMetadataReportsNoKeys(): void
    {
        $this->stopwatch->measure('pipeline', static function (): void {
        });

        $this->assertSame([], $this->stopwatch->getAggregates()['pipeline']['meta']);
    }

    public function testSpansWithoutMetadataDoNotBreakTheRollup(): void
    {
        $this->stopwatch->measure('op', static function (): void {
        });
        $this->stopwatch->measure('op', static function (): void {
        }, ['component' => 'PhButton']);

        $this->assertSame(1, $this->stopwatch->getAggregates()['op']['meta']['component']['distinct']);
    }

    public function testAFoldedFrameContributesNoMetadataOfItsOwn(): void
    {
        $this->stopwatch->measure('op', function (): void {
            $this->stopwatch->measure('op', static function (): void {
            }, ['component' => 'from-the-folded-layer']);
        });

        $meta = $this->stopwatch->getAggregates()['op']['meta'];

        // The folded frame is the same operation seen from another layer, so the
        // surviving span's metadata is what describes the operation.
        $this->assertArrayNotHasKey('component', $meta);
    }

    /**
     * @param array<string, mixed> $meta Rolled-up metadata of one key.
     * @return array<string, int> Value => call count.
     */
    private function collectCounts(array $meta): array
    {
        $counts = [];

        foreach ($meta['top'] as $entry) {
            $counts[(string) $entry['value']] = $entry['count'];
        }

        return $counts;
    }
}
