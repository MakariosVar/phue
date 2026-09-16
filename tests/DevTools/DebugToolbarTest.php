<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\TestCase;
use Phue\Modules\DevTool\DebugToolbar;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;

#[\PHPUnit\Framework\Attributes\Group('unit')]
class DebugToolbarTest extends TestCase
{
    private DebugToolbar $toolbar;
    private Stopwatch $stopwatch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stopwatch = new Stopwatch();
        $this->toolbar = new DebugToolbar();
    }

    public function testRenderReturnsString(): void
    {
        $this->stopwatch->start();
        usleep(500);
        $duration = $this->stopwatch->stop();

        $profiler = new RequestProfiler($this->stopwatch);
        $profiler->start();
        // Simulate some activity
        $data = str_repeat('x', 100);
        unset($data);
        $profiler->stop();

        $html = $this->toolbar->render($profiler, $duration);

        $this->assertIsString($html);
        $this->assertStringContainsString('phue-debug-toolbar', $html);
    }

    public function testRenderContainsPhueDevToolBrand(): void
    {
        $this->stopwatch->start();
        usleep(500);
        $duration = $this->stopwatch->stop();

        $profiler = new RequestProfiler($this->stopwatch);
        $profiler->start();
        $profiler->stop();

        $html = $this->toolbar->render($profiler, $duration);

        $this->assertStringContainsString('Phue DevTool', $html);
    }

    public function testRenderContainsDurationMetrics(): void
    {
        $this->stopwatch->start();
        usleep(500);
        $duration = $this->stopwatch->stop();

        $profiler = new RequestProfiler($this->stopwatch);
        $profiler->start();
        $profiler->stop();

        $html = $this->toolbar->render($profiler, $duration);

        $this->assertStringContainsString('ms', $html);
    }

    public function testRenderContainsMemoryMetrics(): void
    {
        $this->stopwatch->start();
        usleep(500);
        $duration = $this->stopwatch->stop();

        $profiler = new RequestProfiler($this->stopwatch);
        $profiler->start();
        $profiler->stop();

        $html = $this->toolbar->render($profiler, $duration);

        $this->assertStringContainsString('MB', $html);
        $this->assertStringContainsString('Peak', $html);
    }

    public function testRenderHasFixedPositionStyling(): void
    {
        $this->stopwatch->start();
        usleep(100);
        $duration = $this->stopwatch->stop();

        $profiler = new RequestProfiler($this->stopwatch);
        $profiler->start();
        $profiler->stop();

        $html = $this->toolbar->render($profiler, $duration);

        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('bottom: 0', $html);
        $this->assertStringContainsString('z-index', $html);
    }
}