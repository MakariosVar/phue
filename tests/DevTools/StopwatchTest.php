<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\TestCase;
use Phue\Core\DevTool\Stopwatch;

#[\PHPUnit\Framework\Attributes\Group('unit')]
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
}