<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\DevTool\Stopwatch;

/**
 * A label nested inside an identical label is the SAME operation observed from two
 * layers, not two operations.
 *
 * The DevTool instruments component resolution twice on purpose: ComponentLoaderRegistry
 * and PhueComponentLoader both wrap their resolution methods, and the renderer calls the
 * loader directly in places, so instrumenting one layer alone leaves a blind spot. A
 * registry-level moduleHash() therefore always opens a loader-level moduleHash() span
 * underneath itself.
 *
 * Recording both is the defect: the inner span's duration is already contained in the
 * outer span's, so the rollup counts every operation twice and reports more total time
 * under one label than the request actually took. A report whose own totals exceed the
 * request cannot be used to decide what to optimize.
 *
 * The invariant pinned here: a frame whose parent frame carries the same label is folded
 * into that parent — not recorded, but still timed, so the parent's exclusive time and the
 * request-wide exclusive partition stay exactly as they were.
 */
#[Group('unit')]
class StopwatchLabelNestingTest extends TestCase
{
    private Stopwatch $stopwatch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stopwatch = new Stopwatch();
    }

    // ------------------------------------------------- collapse of same-label nesting

    public function testSameLabelNestedSpanIsRecordedOnce(): void
    {
        $this->stopwatch->measure('loader.moduleHash', function (): void {
            $this->stopwatch->measure('loader.moduleHash', static function (): void {
                usleep(100);
            });
        });

        $spans = $this->stopwatch->getSpans();

        $this->assertCount(1, $spans, 'A span nested in an identical label is the same operation, not a second one.');
        $this->assertSame('loader.moduleHash', $spans[0]['label']);
    }

    public function testAggregateCountCountsOneOperationNotTwoInstrumentedLayers(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->stopwatch->measure('loader.moduleHash', function (): void {
                $this->stopwatch->measure('loader.moduleHash', static function (): void {
                });
            });
        }

        $aggregates = $this->stopwatch->getAggregates();

        $this->assertSame(5, $aggregates['loader.moduleHash']['count']);
    }

    public function testAggregateTotalIsNotDoubleCountedForSameLabelNesting(): void
    {
        // The outer frame spans ~60ms in total: 10ms, then a 40ms inner frame with the same
        // label, then 10ms more. A same-label inner frame is folded into its parent, so the label
        // total is ~60ms. If the fold stopped working the inner frame would also be summed in and
        // the label would report ~100ms — more elapsed time than the work took.
        //
        // The inner frame is deliberately far larger than the outer one. Two similar-sized sleeps
        // put the right answer and the wrong one a couple of milliseconds apart, and usleep
        // overshoots by that much on a loaded machine, so the test would fail for reasons that
        // have nothing to do with the code. Here the two answers are 40ms apart.
        $this->stopwatch->measure('loader.moduleHash', function (): void {
            usleep(10000);
            $this->stopwatch->measure('loader.moduleHash', static function (): void {
                usleep(40000);
            });
            usleep(10000);
        });

        $total = $this->stopwatch->getAggregates()['loader.moduleHash']['total_ms'];

        $this->assertGreaterThan(45.0, $total, 'The outer span was not measured at all.');
        $this->assertLessThan(
            75.0,
            $total,
            'The folded frame was summed on top of its parent, so the label reports more elapsed time than the work took.'
        );
    }

    public function testFoldingKeepsTheFoldedTimeInTheExclusiveTotal(): void
    {
        // A folded frame's own time becomes the parent's own time, and deliberately so: charging
        // only the folded frame's children to the parent would erase its slice of work from the
        // request, and "Share of self" would stop adding up to 100% with nothing in the report
        // saying so. So exclusive_ms tracks total_ms here rather than shrinking.
        $this->stopwatch->measure('loader.moduleHash', function (): void {
            usleep(10000);
            $this->stopwatch->measure('loader.moduleHash', static function (): void {
                usleep(40000);
            });
            usleep(10000);
        });

        $aggregate = $this->stopwatch->getAggregates()['loader.moduleHash'];

        $this->assertGreaterThan(45.0, $aggregate['exclusive_ms'], 'The outer span was not measured.');
        $this->assertLessThan(75.0, $aggregate['exclusive_ms'], 'The folded frame\'s time went missing entirely.');
    }

    public function testFoldingRecordsOneSpanAndCountsOneCall(): void
    {
        $this->stopwatch->measure('op', function (): void {
            $this->stopwatch->measure('op', static function (): void {
            });
        });

        // The fold is the point: a same-label frame is the same operation seen from another
        // layer, so it is neither recorded as a second span nor counted as a second call. What
        // keeps the request honest is that its time stays in the parent's figures, which the
        // test above pins.
        $this->assertCount(1, $this->stopwatch->getSpans());
        $this->assertSame(1, $this->stopwatch->getAggregates()['op']['count']);
    }

    public function testCollapsingSameLabelKeepsTheOuterSpanTotal(): void
    {
        $this->stopwatch->measure('op', function (): void {
            usleep(3000);
            $this->stopwatch->measure('op', static function (): void {
                usleep(1000);
            });
        });

        $span = $this->stopwatch->getSpans()[0];

        $this->assertGreaterThan(3.0, $span['duration_ms'], 'The surviving span must still measure the whole operation.');
        $this->assertLessThan(6.0, $span['duration_ms']);
    }

    public function testFoldedLayersKeepTheirOwnWorkAndStillSubtractRealChildren(): void
    {
        // outer > op > op > leaf.
        //
        // The two `op` frames are the same operation seen from two instrumented layers,
        // so the time between them is the operation's OWN work and has to stay in `op`'s
        // exclusive time. The leaf is a genuinely different operation and must still be
        // subtracted. Getting either half wrong breaks the exclusive partition.
        $this->stopwatch->measure('outer', function (): void {
            usleep(4000);
            $this->stopwatch->measure('op', function (): void {
                usleep(3000);
                $this->stopwatch->measure('op', function (): void {
                    usleep(2000);
                    $this->stopwatch->measure('leaf', static function (): void {
                        usleep(1000);
                    });
                });
            });
        });

        $spans = [];
        foreach ($this->stopwatch->getSpans() as $span) {
            $spans[$span['label']] = $span;
        }

        $childTime = $spans['op']['duration_ms'] - $spans['op']['exclusive_ms'];

        $this->assertGreaterThan(0.5, $childTime, 'A real child operation must still be subtracted from the parent.');
        $this->assertGreaterThan(1.0, $spans['op']['exclusive_ms'], 'The folded layer\'s own work must be charged to the operation, not lost.');
        $this->assertGreaterThan(0.0, $spans['outer']['exclusive_ms']);
    }

    // ---------------------------------------------------------- partition is preserved

    public function testCollapsingSameLabelPreservesTheExclusiveTimePartition(): void
    {
        // pipeline(20ms) > op(14ms) > op(12ms) > leaf(6ms)
        $this->stopwatch->measure('pipeline', function (): void {
            usleep(5000);
            $this->stopwatch->measure('op', function (): void {
                usleep(3000);
                $this->stopwatch->measure('op', function (): void {
                    usleep(2000);
                    $this->stopwatch->measure('leaf', static function (): void {
                        usleep(1000);
                    });
                });
            });
        });

        $aggregates = $this->stopwatch->getAggregates();

        $partition = 0.0;
        foreach ($aggregates as $aggregate) {
            $partition += $aggregate['exclusive_ms'];
        }

        // Exclusive time is what makes "Share of self" add up to 100%. Folding a frame
        // must move its time to the parent, never delete it.
        $this->assertEqualsWithDelta(
            $aggregates['pipeline']['total_ms'],
            $partition,
            0.5,
            'Exclusive time must still partition the request after a same-label fold.'
        );
    }

    // ------------------------------------------------------------- what must NOT fold

    public function testDifferentLabelsStillNestAndAreBothRecorded(): void
    {
        $this->stopwatch->measure('registry.load', function (): void {
            $this->stopwatch->measure('loader.load', static function (): void {
                usleep(100);
            });
        });

        $labels = array_column($this->stopwatch->getSpans(), 'label');

        $this->assertSame(['loader.load', 'registry.load'], $labels);
    }

    public function testSameLabelSiblingsAreBothRecorded(): void
    {
        // Only NESTING folds. Two independent calls under a shared parent are two calls,
        // and collapsing them would hide real repetition — the exact thing the call
        // count exists to reveal.
        $this->stopwatch->measure('pipeline', function (): void {
            $this->stopwatch->measure('op', static function (): void {
            });
            $this->stopwatch->measure('op', static function (): void {
            });
        });

        $this->assertSame(2, $this->stopwatch->getAggregates()['op']['count']);
    }

    public function testSameLabelGrandchildrenFoldAcrossManyLayers(): void
    {
        $this->stopwatch->measure('a', function (): void {
            $this->stopwatch->measure('b', function (): void {
                $this->stopwatch->measure('b', function (): void {
                    $this->stopwatch->measure('b', function (): void {
                    });
                });
            });
        });

        $aggregates = $this->stopwatch->getAggregates();

        $this->assertSame(1, $aggregates['b']['count']);
        $this->assertSame(1, $aggregates['a']['count']);
        $this->assertSame(0, $this->stopwatch->getOpenSpanCount());
    }

    // ---------------------------------------------------------------- bookkeeping

    public function testSameLabelCollapseKeepsTheStackBalanced(): void
    {
        $this->stopwatch->measure('op', function (): void {
            $this->stopwatch->measure('op', static function (): void {
            });
        });

        $this->assertSame(0, $this->stopwatch->getOpenSpanCount());

        $next = $this->stopwatch->measure('after', static function (): string {
            return 'ok';
        });

        $this->assertSame('ok', $next);
        $this->assertSame(1, $this->stopwatch->getSpans()[1]['depth']);
    }

    public function testSameLabelCollapseDoesNotInflateTheDroppedSpanCount(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->stopwatch->measure('op', function (): void {
                $this->stopwatch->measure('op', static function (): void {
                });
            });
        }

        // Nothing was lost to a cap, so nothing may be reported as lost. A folded frame
        // that incremented this counter would make the toolbar cry wolf on every request.
        $this->assertSame(0, $this->stopwatch->getDroppedSpanCount());
    }

    public function testDescribeResultOnAFoldedSameLabelSpanDoesNotLeakIntoAnotherSpan(): void
    {
        // describeResult merges into "whichever span closed last". On a folded frame the
        // callback has nothing to merge into, and the nearest recorded span belongs to an
        // unrelated operation — so the metadata has to be dropped, not redirected.
        $this->stopwatch->measure('op', function (): void {
            $this->stopwatch->measure('op', static fn(): string => 'inner', [], static fn(string $r): array => ['result' => $r]);
        });

        $spans = $this->stopwatch->getSpans();

        $this->assertCount(1, $spans);
        $this->assertArrayNotHasKey('result', $spans[0]['meta'], 'Metadata must not migrate to whichever span happens to be last.');
    }

    public function testDescribeResultStillAttachesToTheSurvivingSameLabelSpan(): void
    {
        $this->stopwatch->measure('op', function (): string {
            return 'ok';
        }, [], static fn(string $r): array => ['status' => 200]);

        $span = $this->stopwatch->getSpans()[0];

        $this->assertSame(200, $span['meta']['status']);
    }

    public function testThrowingInsideAFoldedSameLabelSpanLeavesNoOpenFrames(): void
    {
        try {
            $this->stopwatch->measure('op', function (): void {
                $this->stopwatch->measure('op', static function (): void {
                    throw new \RuntimeException('kaboom');
                });
            });
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, $this->stopwatch->getOpenSpanCount());
    }

    // ------------------------------------------------ the real decorator stack shape

    public function testNoLabelTotalCanExceedTheRequestItWasMeasuredIn(): void
    {
        // The exact nesting a component page produces: pipeline > registry moduleHash >
        // loader moduleHash > buildFor > (child registry moduleHash > child loader
        // moduleHash > child buildFor).
        $build = function (string $label) use (&$build): void {
            $this->stopwatch->measure('loader.moduleHash', function () use (&$build, $label): void {
                $this->stopwatch->measure('loader.moduleHash', function () use (&$build, $label): void {
                    $this->stopwatch->measure($label, static function (): void {
                        usleep(200);
                    });
                });
            });
        };

        $this->stopwatch->measure('pipeline', function () use (&$build): void {
            usleep(500);
            $build('loader.buildFor');
            $build('loader.buildFor');
        });

        $aggregates = $this->stopwatch->getAggregates();
        $requestTotal = $aggregates['pipeline']['total_ms'];

        foreach ($aggregates as $label => $aggregate) {
            $this->assertLessThanOrEqual(
                $requestTotal + 0.5,
                $aggregate['total_ms'],
                "Label '{$label}' reports more time than the request took, so the rollup cannot be trusted."
            );
        }

        $this->assertSame(2, $aggregates['loader.moduleHash']['count']);
        $this->assertSame(2, $aggregates['loader.buildFor']['count']);
    }

    public function testFoldedDecoratorStackStillAttributesEveryMillisecond(): void
    {
        $this->stopwatch->measure('pipeline', function (): void {
            usleep(4000);
            $this->stopwatch->measure('loader.moduleHash', function (): void {
                usleep(3000);
                $this->stopwatch->measure('loader.moduleHash', static function (): void {
                    usleep(2000);
                });
            });
        });

        $aggregates = $this->stopwatch->getAggregates();
        $partition = array_sum(array_column($aggregates, 'exclusive_ms'));

        $this->assertEqualsWithDelta($aggregates['pipeline']['total_ms'], $partition, 0.5);
        $this->assertSame(1, $aggregates['loader.moduleHash']['count']);
        $this->assertGreaterThan(1.0, $aggregates['loader.moduleHash']['exclusive_ms'], 'The folded layer\'s work must be charged to the operation, not lost.');
    }
}
