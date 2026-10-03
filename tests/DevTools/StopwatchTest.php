<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\DevTool\Stopwatch;
use RuntimeException;

#[Group('unit')]
class StopwatchTest extends TestCase
{
    private Stopwatch $stopwatch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stopwatch = new Stopwatch();
    }

    public function testStartSetsStartTime(): void
    {
        $this->stopwatch->start();
        // If start works without error and getDurationMs returns 0 before stop
        $this->assertSame(0.0, $this->stopwatch->getDurationMs());
    }

    public function testStopReturnsPositiveDuration(): void
    {
        $this->stopwatch->start();
        usleep(1000); // 1ms sleep
        $duration = $this->stopwatch->stop();

        $this->assertIsFloat($duration);
        $this->assertGreaterThan(0.0, $duration);
    }

    public function testGetDurationMsReturnsStopValue(): void
    {
        $this->stopwatch->start();
        usleep(500);
        $this->stopwatch->stop();

        $duration = $this->stopwatch->getDurationMs();

        $this->assertGreaterThan(0.0, $duration);
    }

    public function testStopWithoutStartReturnsZero(): void
    {
        $duration = $this->stopwatch->stop();

        $this->assertSame(0.0, $duration);
    }

    public function testMultipleStartStopCycles(): void
    {
        $this->stopwatch->start();
        usleep(500);
        $first = $this->stopwatch->stop();

        usleep(500);

        $this->stopwatch->start();
        usleep(500);
        $second = $this->stopwatch->stop();

        $this->assertGreaterThan(0.0, $first);
        $this->assertGreaterThan(0.0, $second);
        $this->assertLessThan($first * 100, $second); // Sanity: not absurdly different
    }

    public function testChainedStartReturnsSelf(): void
    {
        $result = $this->stopwatch->start();

        $this->assertSame($this->stopwatch, $result);
    }

    // ---------------------------------------------------------------- spans

    public function testEndSpanWithoutBeginReturnsNull(): void
    {
        $this->assertNull($this->stopwatch->endSpan());
    }

    public function testBeginEndRecordsSingleSpan(): void
    {
        $this->stopwatch->beginSpan('unit', ['a' => 1]);
        usleep(200);
        $span = $this->stopwatch->endSpan();

        $this->assertIsArray($span);
        $this->assertSame('unit', $span['label']);
        $this->assertSame(['a' => 1], $span['meta']);
        $this->assertSame(1, $span['depth']);
        $this->assertGreaterThan(0.0, $span['duration_ms']);
        $this->assertCount(1, $this->stopwatch->getSpans());
    }

    public function testNestedSpansRecordDepth(): void
    {
        $this->stopwatch->beginSpan('outer');
        $this->stopwatch->beginSpan('inner');
        $inner = $this->stopwatch->endSpan();
        $outer = $this->stopwatch->endSpan();

        $this->assertSame(2, $inner['depth']);
        $this->assertSame(1, $outer['depth']);
    }

    public function testExclusiveTimeExcludesNestedChildren(): void
    {
        $this->stopwatch->beginSpan('outer');
        usleep(4000);
        $this->stopwatch->beginSpan('inner');
        usleep(4000);
        $inner = $this->stopwatch->endSpan();
        $outer = $this->stopwatch->endSpan();

        // The child's duration is what the parent must stop counting as its own work.
        $childTime = $outer['duration_ms'] - $outer['exclusive_ms'];

        $this->assertGreaterThan($inner['duration_ms'] * 0.5, $childTime);
        $this->assertGreaterThan(0.0, $inner['exclusive_ms']);
        $this->assertGreaterThanOrEqual(0.0, $outer['exclusive_ms']);
    }

    public function testExclusiveTimeIsNeverNegative(): void
    {
        // Clock granularity can make a zero-length child appear to exceed its parent.
        for ($i = 0; $i < 50; $i++) {
            $this->stopwatch->beginSpan('parent');
            $this->stopwatch->beginSpan('child');
            $this->stopwatch->endSpan();
            $span = $this->stopwatch->endSpan();

            $this->assertGreaterThanOrEqual(0.0, $span['exclusive_ms']);
        }
    }

    public function testMeasureReturnsCallbackResultUnchanged(): void
    {
        $result = $this->stopwatch->measure('op', static fn(): array => ['a' => 1]);

        $this->assertSame(['a' => 1], $result);
    }

    public function testMeasureReturnsNullResultUnchanged(): void
    {
        $this->assertNull($this->stopwatch->measure('op', static fn(): ?string => null));
    }

    public function testMeasureRecordsSpan(): void
    {
        $this->stopwatch->measure('op', static function (): void {
            usleep(100);
        });

        $spans = $this->stopwatch->getSpans();
        $this->assertCount(1, $spans);
        $this->assertSame('op', $spans[0]['label']);
    }

    public function testMeasureClosesSpanWhenCallbackThrows(): void
    {
        try {
            $this->stopwatch->measure('boom', static function (): void {
                throw new RuntimeException('kaboom');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('kaboom', $e->getMessage());
        }

        // An unbalanced stack would corrupt every exclusive time after it.
        $this->assertSame(0, $this->stopwatch->getOpenSpanCount());

        $this->stopwatch->beginSpan('after');
        $after = $this->stopwatch->endSpan();

        $this->assertSame(1, $after['depth']);
        $this->assertCount(2, $this->stopwatch->getSpans());
    }

    // ---------------------------------------------------------- aggregation

    public function testAggregatesGroupByLabelWithCountAndTotal(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->stopwatch->measure('hot', static function (): void {
                usleep(100);
            });
        }
        $this->stopwatch->measure('cold', static function (): void {
            usleep(10);
        });

        $aggregates = $this->stopwatch->getAggregates();

        $this->assertCount(2, $aggregates);
        $this->assertSame(5, $aggregates['hot']['count']);
        $this->assertSame(1, $aggregates['cold']['count']);
        $this->assertGreaterThan($aggregates['cold']['total_ms'], $aggregates['hot']['total_ms']);
    }

    public function testAggregatesAreOrderedByTotalDescending(): void
    {
        $this->stopwatch->measure('tiny', static function (): void {
            usleep(10);
        });
        $this->stopwatch->measure('huge', static function (): void {
            usleep(6000);
        });

        $aggregates = $this->stopwatch->getAggregates();

        $this->assertSame(['huge', 'tiny'], array_keys($aggregates));
    }

    public function testAggregatesIgnoreMetadataWhenGrouping(): void
    {
        // Grouping by metadata would shatter one hot operation into one bucket per
        // component name, which defeats the purpose of the rollup.
        $this->stopwatch->measure('loader', static function (): void {
        }, ['component' => 'PhButton']);
        $this->stopwatch->measure('loader', static function (): void {
        }, ['component' => 'PhCard']);

        $aggregates = $this->stopwatch->getAggregates();

        $this->assertCount(1, $aggregates);
        $this->assertSame(2, $aggregates['loader']['count']);
    }

    public function testAggregatesTrackMinMaxAndAverage(): void
    {
        $this->stopwatch->measure('op', static function (): void {
            usleep(100);
        });
        $this->stopwatch->measure('op', static function (): void {
            usleep(300);
        });

        $aggregate = $this->stopwatch->getAggregates()['op'];

        $this->assertGreaterThan(0.0, $aggregate['min_ms']);
        $this->assertGreaterThan($aggregate['min_ms'], $aggregate['max_ms']);
        $this->assertEqualsWithDelta(
            $aggregate['total_ms'] / 2,
            $aggregate['avg_ms'],
            0.000001
        );
    }

    public function testAggregatesOnEmptyCollectorAreEmpty(): void
    {
        $this->assertSame([], $this->stopwatch->getAggregates());
    }

    public function testTopSpansAreOrderedByDurationDescending(): void
    {
        $this->stopwatch->measure('a', static function (): void {
            usleep(10);
        });
        $this->stopwatch->measure('b', static function (): void {
            usleep(6000);
        });
        $this->stopwatch->measure('c', static function (): void {
            usleep(10);
        });

        $top = $this->stopwatch->getTopSpans(2);

        $this->assertCount(2, $top);
        $this->assertSame('b', $top[0]['label']);
    }

    public function testTopSpansRespectLimit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->stopwatch->measure('op' . $i, static function (): void {
            });
        }

        $this->assertCount(4, $this->stopwatch->getTopSpans(4));
        $this->assertCount(0, $this->stopwatch->getTopSpans(0));
        $this->assertCount(0, $this->stopwatch->getTopSpans(-5));
    }

    public function testTopSpansOnEmptyCollectorAreEmpty(): void
    {
        $this->assertSame([], $this->stopwatch->getTopSpans());
        $this->assertSame([], $this->stopwatch->getTopExclusiveSpans());
    }

    public function testTopExclusiveSpansRankBySelfTimeNotTotal(): void
    {
        // A recursive parent has a huge total but almost no self time.
        $this->stopwatch->beginSpan('parent');
        $this->stopwatch->beginSpan('child');
        usleep(3000);
        $this->stopwatch->endSpan();
        $this->stopwatch->endSpan();

        $this->stopwatch->measure('loner', static function (): void {
            usleep(1000);
        });

        $topTotal = $this->stopwatch->getTopSpans(1)[0];
        $topSelf = $this->stopwatch->getTopExclusiveSpans(1)[0];

        // The recursive parent owns the most total time...
        $this->assertSame('parent', $topTotal['label']);
        // ...but almost none of it is its own work, which is exactly the misreading the
        // exclusive ranking exists to prevent.
        $this->assertNotSame('parent', $topSelf['label']);
        $this->assertLessThan($topTotal['duration_ms'] * 0.5, $topTotal['exclusive_ms']);
    }

    // ----------------------------------------------------------------- caps

    public function testDepthCapStopsRecordingButKeepsStackBalanced(): void
    {
        for ($i = 0; $i < Stopwatch::MAX_SPAN_DEPTH + 5; $i++) {
            $this->stopwatch->beginSpan('deep' . $i);
        }

        $this->assertSame(Stopwatch::MAX_SPAN_DEPTH + 5, $this->stopwatch->getOpenSpanCount());

        for ($i = 0; $i < Stopwatch::MAX_SPAN_DEPTH + 5; $i++) {
            $this->stopwatch->endSpan();
        }

        $this->assertSame(0, $this->stopwatch->getOpenSpanCount());
        $this->assertCount(Stopwatch::MAX_SPAN_DEPTH, $this->stopwatch->getSpans());
    }

    public function testCappedFrameStillSubtractsFromParentExclusiveTime(): void
    {
        // Without this, an over-deep subtree would be charged entirely to the parent's
        // self time and the report would blame the wrong operation.
        $this->stopwatch->beginSpan('parent');
        for ($i = 0; $i < Stopwatch::MAX_SPAN_DEPTH + 3; $i++) {
            $this->stopwatch->beginSpan('deep' . $i);
            usleep(200);
        }
        for ($i = 0; $i < Stopwatch::MAX_SPAN_DEPTH + 3; $i++) {
            $this->stopwatch->endSpan();
        }
        $parent = $this->stopwatch->endSpan();

        $recordedChildren = array_filter(
            $this->stopwatch->getSpans(),
            static fn(array $s): bool => $s['label'] === 'parent'
        );

        $this->assertCount(1, $recordedChildren);
        $this->assertGreaterThanOrEqual(0.0, $parent['exclusive_ms']);
        $this->assertLessThan($parent['duration_ms'], $parent['exclusive_ms']);
    }

    public function testSpanCountCapBoundsMemoryAndReportsDropped(): void
    {
        for ($i = 0; $i < Stopwatch::MAX_SPANS + 10; $i++) {
            $this->stopwatch->beginSpan('op');
            $this->stopwatch->endSpan();
        }

        $this->assertCount(Stopwatch::MAX_SPANS, $this->stopwatch->getSpans());
        $this->assertSame(10, $this->stopwatch->getDroppedSpanCount());
    }

    public function testDroppedCountIsNotIncrementedByDepthCappedFrames(): void
    {
        for ($i = 0; $i < Stopwatch::MAX_SPAN_DEPTH + 3; $i++) {
            $this->stopwatch->beginSpan('deep');
            $this->stopwatch->endSpan();
        }

        $this->assertSame(0, $this->stopwatch->getDroppedSpanCount());
    }

    // ---------------------------------------------------------------- reset

    public function testResetSpansClearsSpansAndStack(): void
    {
        $this->stopwatch->beginSpan('open');
        $this->stopwatch->measure('done', static function (): void {
        });

        $this->stopwatch->resetSpans();

        $this->assertSame([], $this->stopwatch->getSpans());
        $this->assertSame([], $this->stopwatch->getAggregates());
        $this->assertSame(0, $this->stopwatch->getOpenSpanCount());
        $this->assertSame(0, $this->stopwatch->getDroppedSpanCount());
    }

    public function testCollectorIsReusableAfterReset(): void
    {
        $this->stopwatch->measure('before', static function (): void {
        });
        $this->stopwatch->resetSpans();
        $this->stopwatch->measure('after', static function (): void {
        });

        $spans = $this->stopwatch->getSpans();

        $this->assertCount(1, $spans);
        $this->assertSame('after', $spans[0]['label']);
    }
}
