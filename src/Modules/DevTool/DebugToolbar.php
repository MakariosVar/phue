<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

/**
 * Class DebugToolbar
 *
 * Renders the bottom HTML debug toolbar with metrics from the RequestProfiler.
 *
 * @package Phue\Modules\DevTool
 */
class DebugToolbar
{
    /**
     * Render the HTML debug toolbar.
     *
     * @param RequestProfiler $profiler The request profiler instance.
     * @param float $duration Total duration in milliseconds.
     * @return string Rendered HTML snippet.
     */
    public function render(RequestProfiler $profiler, float $duration): string
    {
        $durationFormatted = number_format($duration, 2);
        $memoryMb = number_format($profiler->getMemoryUsage() / 1024 / 1024, 2);
        $peakMb = number_format($profiler->getPeakMemoryUsage() / 1024 / 1024, 2);

        return <<<HTML
        <div id="phue-debug-toolbar" style="position: fixed; bottom: 0; left: 0; right: 0; background: #0f172a; color: #f8fafc; padding: 8px 16px; font-family: system-ui, sans-serif; font-size: 12px; display: flex; gap: 20px; align-items: center; z-index: 999999; border-top: 1px solid #334155;">
            <div style="font-weight: bold; color: #38bdf8;">Phue DevTool</div>
            <div>⏱ <strong>{$durationFormatted} ms</strong></div>
            <div>💾 <strong>{$memoryMb} MB</strong> (Peak: {$peakMb} MB)</div>
        </div>
        HTML;
    }
}
