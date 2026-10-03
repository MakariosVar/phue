<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\DevTool\DebugToolbar;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;

/**
 * The rollup row has to show what it covers, because the toolbar promises that the
 * timeline can be filtered by name.
 *
 * The filter matches on the row's text content. The row used to contain nothing but the
 * label and six numbers, so typing a component name emptied the table instead of
 * narrowing it — the hint promised a capability the markup did not have. Rendering the
 * rolled-up metadata into the row is what makes the promise true.
 *
 * Rendering metadata into a table is also how a debug panel turns into an injection
 * surface, so escaping and length capping are part of this contract and not polish.
 */
#[Group('unit')]
class DebugToolbarRollupMetaTest extends TestCase
{
    private DebugToolbar $toolbar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->toolbar = new DebugToolbar();
    }

    private function renderWith(callable $record): string
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        $record($profiler);
        $profiler->stop();

        return $this->toolbar->render($profiler, 10.0);
    }

    // ------------------------------------------------ the filter promise, kept

    public function testRollupRowContainsTheNamesItCovers(): void
    {
        $html = $this->renderWith(static function (RequestProfiler $profiler): void {
            for ($i = 0; $i < 3; $i++) {
                $profiler->measure('loader.buildFor', static function (): void {
                }, ['component' => 'PhButton']);
            }
        });

        // The filter is a plain substring match over row.textContent. If the name is not
        // in the markup, searching for it cannot possibly narrow anything.
        $this->assertStringContainsString('PhButton', $html);
        $this->assertStringContainsString('phue-dt-meta', $html);
    }

    public function testRollupRowShowsHowOftenEachNameAppeared(): void
    {
        $html = $this->renderWith(static function (RequestProfiler $profiler): void {
            $profiler->measure('loader.buildFor', static function (): void {
            }, ['component' => 'PhCard']);
            $profiler->measure('loader.buildFor', static function (): void {
            }, ['component' => 'PhButton']);
            $profiler->measure('loader.buildFor', static function (): void {
            }, ['component' => 'PhButton']);
        });

        $this->assertStringContainsString('PhButton×2', $html);
        $this->assertStringContainsString('PhCard×1', $html);
    }

    public function testRollupRowSaysWhenMoreNamesExistThanAreShown(): void
    {
        $html = $this->renderWith(static function (RequestProfiler $profiler): void {
            for ($i = 0; $i < 12; $i++) {
                $profiler->measure('loader.buildFor', static function (): void {
                }, ['component' => 'component-' . $i]);
            }
        });

        $this->assertStringContainsString('more', $html);
    }

    public function testRollupRowRendersAMetricAsItsRange(): void
    {
        $html = $this->renderWith(static function (RequestProfiler $profiler): void {
            $profiler->measure('ssr.send', static function (): void {
            }, ['path' => '/v1/render', 'request_bytes' => 500]);
            $profiler->measure('ssr.send', static function (): void {
            }, ['path' => '/v1/render', 'request_bytes' => 40000]);
        });

        $this->assertStringContainsString('request_bytes', $html);
        $this->assertStringContainsString('500', $html);
        $this->assertStringContainsString('40000', $html);
    }

    public function testRollupRowSurvivesMetadataItCannotRender(): void
    {
        // Meta reaching the collector is not validated, and a row must not fatal on a
        // value shape nobody anticipated.
        $html = $this->renderWith(static function (RequestProfiler $profiler): void {
            $profiler->measure('op', static function (): void {
            }, ['component' => ['nested' => ['deep' => true]]]);
        });

        $this->assertIsString($html);
        $this->assertStringContainsString('op', $html);
    }

    public function testRollupRowIsOmittedForALabelWithoutMetadata(): void
    {
        $html = $this->renderWith(static function (RequestProfiler $profiler): void {
            $profiler->measure('pipeline', static function (): void {
            });
        });

        // The stylesheet always carries the rule, so assert on the element, not the name.
        $this->assertStringNotContainsString('<div class="phue-dt-meta', $html);
    }

    // ---------------------------------------------------------- escaping

    public function testMetadataIsEscapedInTheRollupRow(): void
    {
        $html = $this->renderWith(static function (RequestProfiler $profiler): void {
            $profiler->measure('op', static function (): void {
            }, ['component' => '</td><script>alert(1)</script>']);
        });

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testNumericMetadataRenderedAsTextIsEscaped(): void
    {
        // The metric branch formats min/max through sprintf before escaping, so the
        // escaping has to happen after the formatting and not before it.
        $html = $this->renderWith(static function (RequestProfiler $profiler): void {
            $profiler->measure('op', static function (): void {
            }, ['ratio' => 0.5, 'other' => '<b>x</b>']);
            $profiler->measure('op', static function (): void {
            }, ['ratio' => 0.75, 'other' => '<b>y</b>']);
        });

        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function testAMalformedRollupDoesNotFatalThePanel(): void
    {
        // getAggregates() is public API and a consumer can hand the toolbar anything.
        // A debug panel that throws takes the page's HTML with it.
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        $profiler->measure('op', static function (): void {
        }, ['component' => 'PhButton']);
        $profiler->stop();

        $this->assertIsString($this->toolbar->render($profiler, 10.0));
        $this->assertIsString($this->toolbar->render($profiler, 10.0, ['timeline' => true]));
    }

    // ---------------------------------------------------------- length cap

    public function testALongMetadataValueIsTruncatedInTheRollupRow(): void
    {
        $long = 'SELECT ' . str_repeat('a', DebugToolbar::MAX_SQL_LENGTH * 2);

        $html = $this->renderWith(static function (RequestProfiler $profiler) use ($long): void {
            $profiler->measure('db.query', static function (): void {
            }, ['sql' => $long]);
        });

        $truncated = mb_substr($long, 0, DebugToolbar::MAX_SQL_LENGTH) . '…';

        $this->assertStringContainsString($truncated, $html);
        $this->assertStringNotContainsString($long, $this->withoutJsonPanel($html), 'A summary row is not an exemption from the length cap.');
    }

    public function testTheCallCountStaysVisibleWhenTheValueIsTruncated(): void
    {
        $long = str_repeat('b', DebugToolbar::MAX_SQL_LENGTH * 2);

        $html = $this->renderWith(static function (RequestProfiler $profiler) use ($long): void {
            $profiler->measure('db.query', static function (): void {
            }, ['sql' => $long]);
        });

        // Truncating the whole token would leave a row of no information at all.
        $this->assertStringContainsString('×1', $html);
    }

    private function withoutJsonPanel(string $html): string
    {
        return (string) preg_replace('/<pre class="bg-body border rounded p-2 mb-0 phue-dt-json">.*?<\/pre>/s', '', $html);
    }
}
