<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;
use RuntimeException;

#[Group('unit')]
class RequestProfilerTest extends TestCase
{
    private RequestProfiler $profiler;
    private Stopwatch $stopwatch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stopwatch = new Stopwatch();
        $this->profiler = new RequestProfiler($this->stopwatch);
    }

    public function testStartRecordsBaselineMemory(): void
    {
        $this->profiler->start();

        // After start, getMemoryUsage should be >= 0 (might be slightly positive)
        $this->assertGreaterThanOrEqual(0, $this->profiler->getMemoryUsage());
    }

    public function testStopReturnsDuration(): void
    {
        $this->profiler->start();
        usleep(500);
        $duration = $this->profiler->stop();

        $this->assertGreaterThan(0.0, $duration);
    }

    public function testGetMemoryUsageReturnsBytes(): void
    {
        $this->profiler->start();
        $mem = $this->profiler->getMemoryUsage();

        $this->assertIsInt($mem);
        $this->assertGreaterThanOrEqual(0, $mem);
    }

    public function testGetPeakMemoryUsageReturnsInt(): void
    {
        $this->profiler->start();
        $peak = $this->profiler->getPeakMemoryUsage();

        $this->assertIsInt($peak);
        $this->assertGreaterThan(0, $peak);
    }

    public function testGetDurationMsDelegatesToStopwatch(): void
    {
        $this->profiler->start();
        usleep(500);
        $this->profiler->stop();

        $duration = $this->profiler->getDurationMs();

        $this->assertGreaterThan(0.0, $duration);
    }

    public function testMemoryUsageIncreasesAfterAllocation(): void
    {
        $this->profiler->start();
        $before = $this->profiler->getMemoryUsage();

        // Allocate some memory
        $data = str_repeat('x', 1024 * 1024); // 1MB string
        $after = $this->profiler->getMemoryUsage();
        unset($data);

        $this->assertGreaterThanOrEqual($before, $after);
    }

    // ---------------------------------------------------------------- spans

    public function testSpanApiIsDelegatedToTheStopwatch(): void
    {
        $this->profiler->start();

        $result = $this->profiler->measure('op', static fn(): string => 'value', ['a' => 1]);

        $this->assertSame('value', $result);

        $spans = $this->profiler->getSpans();
        $this->assertCount(1, $spans);
        $this->assertSame('op', $spans[0]['label']);
        $this->assertSame(['a' => 1], $spans[0]['meta']);
    }

    public function testBeginAndEndSpanAreDelegated(): void
    {
        $this->profiler->start();
        $this->profiler->beginSpan('outer');
        $this->profiler->beginSpan('inner');
        $this->profiler->endSpan();
        $this->profiler->endSpan();

        $this->assertSame(0, $this->profiler->getOpenSpanCount());
        $this->assertCount(2, $this->profiler->getSpans());
    }

    public function testTopSpanHelpersAreDelegated(): void
    {
        $this->profiler->start();
        for ($i = 0; $i < 6; $i++) {
            $this->profiler->measure('op', static function (): void {
            });
        }

        $this->assertCount(3, $this->profiler->getTopSpans(3));
        $this->assertCount(3, $this->profiler->getTopExclusiveSpans(3));
    }

    public function testDroppedSpanCountIsDelegated(): void
    {
        $this->profiler->start();
        $this->assertSame(0, $this->profiler->getDroppedSpanCount());
    }

    public function testMeasureClosesSpanOnException(): void
    {
        $this->profiler->start();

        try {
            $this->profiler->measure('boom', static function (): void {
                throw new RuntimeException('nope');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, $this->profiler->getOpenSpanCount());
    }

    // ------------------------------------------------- request isolation

    public function testStartClearsSpansCollectedByThePreviousRequest(): void
    {
        // Container::get() memoizes autowired instances, so under Swoole this object is
        // reused for every request. Without this reset the panel would report request
        // N+1's numbers as an ever-growing pile of request N's work.
        $this->profiler->start();
        $this->profiler->measure('first-request', static function (): void {
            usleep(100);
        });
        $this->profiler->addContext(['uri' => '/first']);

        $this->profiler->start();

        $this->assertSame([], $this->profiler->getSpans());
        $this->assertSame([], $this->profiler->getAggregates());
        $this->assertSame([], $this->profiler->getContext());
    }

    public function testStartUnwindsAnUnbalancedSpanStack(): void
    {
        $this->profiler->start();
        $this->profiler->beginSpan('leaked');

        $this->profiler->start();

        $this->assertSame(0, $this->profiler->getOpenSpanCount());
    }

    public function testSecondRequestDoesNotInheritFirstRequestTiming(): void
    {
        $this->profiler->start();
        $this->profiler->measure('slow', static function (): void {
            usleep(8000);
        });
        $firstAggregates = $this->profiler->getAggregates();
        $this->profiler->stop();

        $this->profiler->start();
        $this->profiler->measure('fast', static function (): void {
        });
        $secondAggregates = $this->profiler->getAggregates();
        $this->profiler->stop();

        $this->assertArrayHasKey('slow', $firstAggregates);
        $this->assertArrayNotHasKey('slow', $secondAggregates);
        $this->assertArrayHasKey('fast', $secondAggregates);
        $this->assertSame(1, $secondAggregates['fast']['count']);
    }

    public function testResetClearsSpansContextAndMemoryBaseline(): void
    {
        $this->profiler->start();
        $this->profiler->measure('op', static function (): void {
        });
        $this->profiler->addContext(['uri' => '/x']);

        $this->profiler->reset();

        $this->assertSame([], $this->profiler->getSpans());
        $this->assertSame([], $this->profiler->getContext());
        $this->assertSame(0, $this->profiler->getOpenSpanCount());
        $this->assertGreaterThanOrEqual(0, $this->profiler->getMemoryUsage());
    }

    // --------------------------------------------------------------- context

    public function testAddContextMergesRatherThanReplaces(): void
    {
        $this->profiler->start();
        $this->profiler->addContext(['method' => 'GET']);
        $this->profiler->addContext(['status' => 200]);

        $this->assertSame(['method' => 'GET', 'status' => 200], $this->profiler->getContext());
    }

    public function testAddContextOverwritesADuplicateKey(): void
    {
        $this->profiler->start();
        $this->profiler->addContext(['status' => 500]);
        $this->profiler->addContext(['status' => 200]);

        $this->assertSame(200, $this->profiler->getContextValue('status'));
    }

    public function testGetContextValueReturnsDefaultForMissingKey(): void
    {
        $this->profiler->start();

        $this->assertNull($this->profiler->getContextValue('missing'));
        $this->assertSame('fallback', $this->profiler->getContextValue('missing', 'fallback'));
    }

    public function testContextIsEmptyBeforeStart(): void
    {
        $this->assertSame([], $this->profiler->getContext());
    }
}
