<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\DevTool\DebugToolbar;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;

/**
 * A span that records no caller cannot be explained, only counted.
 *
 * The profile said `loader.buildFor` was entered 214 times for PhCard, and PhCard is
 * used by five components. Three different mechanisms were consistent with those two
 * numbers, and the report could not tell them apart because a span only ever said what
 * it was — never who asked for it.
 *
 * The immediate parent is the whole point. On a component page the call path is
 * pipeline → moduleHash → buildFor → moduleHash, so the inner moduleHash is reached
 * through buildFor, not through the moduleHash that started the walk. Recording the
 * root, or recording nothing, would send the next diagnosis after the wrong mechanism.
 */
#[Group('unit')]
class StopwatchCallerAttributionTest extends TestCase
{
    private Stopwatch $stopwatch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stopwatch = new Stopwatch();
    }

    // ------------------------------------------------------------- the span field

    public function testRootSpanHasNoParent(): void
    {
        $this->stopwatch->measure('pipeline', static function (): void {
        });

        $span = $this->stopwatch->getSpans()[0];

        $this->assertArrayHasKey('parent', $span);
        $this->assertNull($span['parent'], 'The outermost span has no caller to name.');
    }

    public function testSpanRecordsTheLabelOfItsEnclosingSpan(): void
    {
        $this->stopwatch->measure('pipeline', function (): void {
            $this->stopwatch->measure('loader.buildFor', static function (): void {
            });
        });

        $spans = [];
        foreach ($this->stopwatch->getSpans() as $span) {
            $spans[$span['label']] = $span;
        }

        $this->assertNull($spans['pipeline']['parent']);
        $this->assertSame('pipeline', $spans['loader.buildFor']['parent']);
    }

    public function testASpanIsAttributedToItsImmediateParentNotTheRoot(): void
    {
        $this->stopwatch->measure('pipeline', function (): void {
            $this->stopwatch->measure('loader.moduleHash', function (): void {
                $this->stopwatch->measure('loader.buildFor', function (): void {
                    $this->stopwatch->measure('loader.moduleHash', static function (): void {
                    });
                });
            });
        });

        $inner = null;
        foreach ($this->stopwatch->getSpans() as $span) {
            if ($span['label'] === 'loader.moduleHash' && $span['depth'] === 4) {
                $inner = $span;
            }
        }

        $this->assertNotNull($inner);
        $this->assertSame(
            'loader.buildFor',
            $inner['parent'],
            'The re-entrant call is reached through the builder, not through the walk that started it.'
        );
    }

    public function testAFoldedFrameIsNotRecordedSoItNamesNoCaller(): void
    {
        $this->stopwatch->measure('op', function (): void {
            $this->stopwatch->measure('op', static function (): void {
            });
        });

        $spans = $this->stopwatch->getSpans();

        $this->assertCount(1, $spans);
        $this->assertNull($spans[0]['parent']);
        $this->assertArrayNotHasKey('callers', $this->stopwatch->getAggregates()['op']);
    }

    // ------------------------------------------------------------- the callers rollup

    public function testAggregateCountsEntriesByTheirImmediateCaller(): void
    {
        $this->stopwatch->measure('pipeline', function (): void {
            $this->stopwatch->measure('loader.buildFor', static function (): void {
            });
            $this->stopwatch->measure('loader.buildFor', static function (): void {
            });
            $this->stopwatch->measure('ssr.send', function (): void {
                $this->stopwatch->measure('loader.buildFor', static function (): void {
                });
            });
        });

        $callers = $this->stopwatch->getAggregates()['loader.buildFor']['callers'];

        $this->assertSame(2, $callers['pipeline']);
        $this->assertSame(1, $callers['ssr.send']);
        $this->assertCount(2, $callers, 'Every caller that reached the label has to be visible.');
    }

    public function testCallersSumToTheLabelCallCount(): void
    {
        $this->stopwatch->measure('pipeline', function (): void {
            for ($i = 0; $i < 7; $i++) {
                $this->stopwatch->measure('loader.buildFor', static function (): void {
                });
            }
        });

        $aggregates = $this->stopwatch->getAggregates();

        // A breakdown that does not add up is worse than none: it looks like an
        // accounting of the calls and is not.
        $this->assertSame(
            $aggregates['loader.buildFor']['count'],
            array_sum($aggregates['loader.buildFor']['callers'])
        );
    }

    public function testCallersAreOrderedByFrequencyThenByName(): void
    {
        $this->stopwatch->measure('a', function (): void {
            $this->stopwatch->measure('target', static function (): void {
            });
            $this->stopwatch->measure('target', static function (): void {
            });
            $this->stopwatch->measure('target', static function (): void {
            });
        });
        $this->stopwatch->measure('b', function (): void {
            $this->stopwatch->measure('target', static function (): void {
            });
        });

        $callers = $this->stopwatch->getAggregates()['target']['callers'];

        $this->assertSame(['a' => 3, 'b' => 1], $callers);

        // Equal counts must not swap places between requests, or two reports of the
        // same workload cannot be diffed against each other.
        $this->stopwatch->resetSpans();
        $this->stopwatch->measure('z', function (): void {
            $this->stopwatch->measure('target', static function (): void {
            });
        });
        $this->stopwatch->measure('y', function (): void {
            $this->stopwatch->measure('target', static function (): void {
            });
        });

        $this->assertSame(['y' => 1, 'z' => 1], $this->stopwatch->getAggregates()['target']['callers']);
    }

    public function testEveryDistinctCallerIsKept(): void
    {
        // Callers are labels, and the set of labels an instrumented request can produce
        // is bounded by nesting depth — so a breakdown of them needs no cap, and quietly
        // dropping one would hide a mechanism.
        $this->stopwatch->measure('root', function (): void {
            foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as $caller) {
                $this->stopwatch->measure($caller, function (): void {
                    $this->stopwatch->measure('target', static function (): void {
                    });
                });
            }
        });

        $this->assertCount(8, $this->stopwatch->getAggregates()['target']['callers']);
    }

    public function testARootLabelReportsNoCallersAtAll(): void
    {
        $this->stopwatch->measure('pipeline', static function (): void {
        });

        $this->assertArrayNotHasKey('callers', $this->stopwatch->getAggregates()['pipeline']);
    }

    public function testCallersAreNotConfusedWithMeta(): void
    {
        $this->stopwatch->measure('pipeline', function (): void {
            $this->stopwatch->measure('op', static function (): void {
            }, ['component' => 'PhButton']);
        });

        $aggregates = $this->stopwatch->getAggregates();

        // The caller is structure, not something about the call. Mixing the two would let
        // a reader filter by caller and think they were filtering by component.
        $this->assertSame(['pipeline' => 1], $aggregates['op']['callers']);
        $this->assertSame('PhButton', $aggregates['op']['meta']['component']['top'][0]['value']);
    }

    public function testCallersAreRecomputedAndNotCarriedBetweenRequests(): void
    {
        $this->stopwatch->measure('a', function (): void {
            $this->stopwatch->measure('target', static function (): void {
            });
        });
        $this->assertArrayHasKey('callers', $this->stopwatch->getAggregates()['target']);

        $this->stopwatch->resetSpans();
        $this->stopwatch->measure('target', static function (): void {
        });

        // A stale breakdown is a memory-resident worker's worst failure: it survives
        // resetSpans() and describes a request that already ended.
        $this->assertArrayNotHasKey('callers', $this->stopwatch->getAggregates()['target']);
    }

    // --------------------------------------- the shape that started this investigation

    public function testTheRealDecoratorStackAttributesReEntryToTheBuilder(): void
    {
        // pipeline > moduleHash > buildFor > moduleHash, twice over.
        $build = function (): void {
            $this->stopwatch->measure('loader.moduleHash', function (): void {
                $this->stopwatch->measure('loader.moduleHash', function (): void {
                    $this->stopwatch->measure('loader.buildFor', static function (): void {
                        usleep(50);
                    });
                });
            });
        };

        $this->stopwatch->measure('pipeline', function () use ($build): void {
            $build();
            $build();
        });

        $aggregates = $this->stopwatch->getAggregates();

        $this->assertSame(['pipeline' => 2], $aggregates['loader.moduleHash']['callers']);
        $this->assertSame(
            ['loader.moduleHash' => 2],
            $aggregates['loader.buildFor']['callers'],
            'Both re-entries of the builder came through the loader-level moduleHash, not through the registry.'
        );
    }

    // ------------------------------------------------------------------ the toolbar

    public function testTimelineRowShowsWhichCallersReachedTheOperation(): void
    {
        $html = $this->render(static function (RequestProfiler $profiler): void {
            $profiler->measure('pipeline', function () use ($profiler): void {
                $profiler->measure('loader.buildFor', static function (): void {
                });
                $profiler->measure('loader.buildFor', static function (): void {
                });
            });
        });

        // Both entries into loader.buildFor came through pipeline, and the row has to say
        // so: a count of 2 on its own cannot be told apart from 2 entries by some other
        // mechanism.
        $this->assertStringContainsString('via</span> pipeline×2', $html);
    }

    public function testTimelineRowSeparatesCallersFromMetadataValues(): void
    {
        $html = $this->render(static function (RequestProfiler $profiler): void {
            $profiler->measure('pipeline', function () use ($profiler): void {
                $profiler->measure('loader.buildFor', static function (): void {
                }, ['component' => 'PhCard']);
            });
        });

        // The component is what the operation was called on; the caller is who asked.
        // Rendering them the same way would make the filter ambiguous.
        $this->assertStringContainsString('PhCard×1', $html);
        $this->assertStringContainsString('via</span> pipeline×1', $html);
    }

    public function testSpanTableShowsTheCallerOfEachCall(): void
    {
        $html = $this->render(static function (RequestProfiler $profiler): void {
            $profiler->measure('pipeline', function () use ($profiler): void {
                $profiler->measure('loader.buildFor', static function (): void {
                }, ['component' => 'PhCard']);
            });
        });

        // The per-call panel is where a specific slow call gets identified, and a call
        // without its caller is a number with nowhere to go.
        $this->assertStringContainsString('pipeline', $html);
        $this->assertStringContainsString('PhCard', $html);
    }

    public function testCallerLabelsAreEscapedInTheReport(): void
    {
        $html = $this->render(static function (RequestProfiler $profiler): void {
            $profiler->measure('<img src=x onerror=alert(1)>', function () use ($profiler): void {
                $profiler->measure('target', static function (): void {
                });
            });
        });

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    private function render(callable $record): string
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        $record($profiler);
        $profiler->stop();

        return (new DebugToolbar())->render($profiler, 10.0);
    }
}