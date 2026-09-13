<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\TestCase;
use Phue\Core\DevTool\RequestProfiler;
use Phue\Core\DevTool\Stopwatch;

#[\PHPUnit\Framework\Attributes\Group('unit')]
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
}