<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\DevTool\DebugToolbar;
use Phue\Modules\DevTool\LiveReloadRoute;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;
use Phue\Modules\DevTool\Stopwatch as StopwatchAlias;

#[Group('unit')]
class DebugToolbarTest extends TestCase
{
    private DebugToolbar $toolbar;
    private RequestProfiler $profiler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->toolbar = new DebugToolbar();
        $this->profiler = new RequestProfiler(new Stopwatch());
    }

    /**
     * Build a profiler that has already recorded a realistic request.
     */
    private function profiled(float $duration = 12.0, array $context = []): RequestProfiler
    {
        $this->profiler->start();
        $this->profiler->beginSpan('pipeline');
        $this->profiler->measure('loader.moduleHash', static function (): void {
            usleep(200);
        }, ['component' => 'PhButton']);
        $this->profiler->measure('ssr.send', static function (): void {
            usleep(400);
        }, ['path' => '/v1/render', 'request_bytes' => 4096]);
        $this->profiler->endSpan();
        $this->profiler->addContext($context + [
            'method' => 'GET',
            'uri' => '/demo',
            'route' => '/demo',
            'status' => 200,
            'response_bytes' => 2048,
        ]);
        $this->profiler->stop();

        return $this->profiler;
    }

    private function render(float $duration = 12.0, array $context = [], array $options = []): string
    {
        return $this->toolbar->render($this->profiled($duration, $context), $duration, $options);
    }

    // ------------------------------------------------------------ collapsed

    public function testRenderReturnsString(): void
    {
        $html = $this->render();

        $this->assertIsString($html);
        $this->assertStringContainsString('phue-debug-toolbar', $html);
    }

    public function testRenderContainsPhueDevToolBrand(): void
    {
        $this->assertStringContainsString('Phue DevTool', $this->render());
    }

    public function testRenderContainsDurationMetrics(): void
    {
        $this->assertStringContainsString('ms', $this->render());
    }

    public function testRenderContainsMemoryMetrics(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('MB', $html);
        $this->assertStringContainsString('peak', $html);
    }

    public function testRenderHasFixedPositionStyling(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('bottom: 0', $html);
        $this->assertStringContainsString('z-index', $html);
    }

    public function testBarShowsFormattedDuration(): void
    {
        $this->assertStringContainsString('12.00 ms', $this->render(12.0));
    }

    public function testBarShowsResponseSize(): void
    {
        $this->assertStringContainsString('2.0 KB', $this->render());
    }

    public function testBarShowsEventCountFromAggregates(): void
    {
        $html = $this->render();

        // pipeline + moduleHash + ssr.send
        $this->assertStringContainsString('3 events', $html);
    }

    // ------------------------------------------------------------ tone

    public function testFastRequestUsesSuccessTone(): void
    {
        $this->assertStringContainsString('text-bg-success', $this->render(10.0));
    }

    public function testSlowRequestUsesWarningTone(): void
    {
        $this->assertStringContainsString('text-bg-warning', $this->render(DebugToolbar::SLOW_REQUEST_MS));
    }

    public function testCriticalRequestUsesDangerTone(): void
    {
        $this->assertStringContainsString('text-bg-danger', $this->render(DebugToolbar::CRITICAL_REQUEST_MS));
    }

    public function testToneBoundariesAreInclusiveAtTheThreshold(): void
    {
        $justUnder = $this->render(DebugToolbar::SLOW_REQUEST_MS - 0.01);
        $exactly = $this->render(DebugToolbar::SLOW_REQUEST_MS);

        $this->assertStringContainsString('text-bg-success', $justUnder);
        $this->assertStringContainsString('text-bg-warning', $exactly);
    }

    // -------------------------------------------------------------- expand

    public function testPanelIsCollapsedByDefault(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression('/class="phue-dt-panel[^"]*"[^>]*\shidden/', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
    }

    public function testPanelLivesInsideTheRootElement(): void
    {
        // Styling is scoped under .phue-dt-root. A sibling panel would receive no styles
        // at all, including the display:none that makes the hidden attribute work.
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '/<div id="[^"]+" class="phue-dt-root[^"]*"[^>]*>\s*<div id="[^"]+" class="phue-dt-panel/',
            $html
        );
    }

    public function testToggleButtonWiresThePanelThroughAriaControls(): void
    {
        $html = $this->render();

        $panelId = $this->extractPanelId($html);

        $this->assertStringContainsString('aria-controls="' . $panelId . '"', $html);
        $this->assertStringContainsString('<button type="button"', $html);
    }

    public function testToggleScriptReferencesOnlyExistingElementIds(): void
    {
        $html = $this->render();

        preg_match_all('/getElementById\("([^"]+)"\)/', $html, $matches);
        $referenced = $matches[1];

        preg_match_all('/id="([^"]+)"/', $html, $idMatches);
        $present = $idMatches[1];

        $this->assertNotEmpty($referenced);
        foreach ($referenced as $id) {
            $this->assertContains($id, $present, "Script references missing id: {$id}");
        }
    }

    public function testToggleScriptIsEmittedAsExecutableJavascript(): void
    {
        $html = $this->render();

        $this->assertStringContainsString("addEventListener('click'", $html);
        $this->assertStringContainsString('panel.hidden', $html);
        // A toolbar that stops expanding when an app trims bootstrap.bundle.js is a bad
        // trade, so the behaviour must not depend on Bootstrap's JavaScript.
        $this->assertStringNotContainsString('bootstrap.Collapse', $html);
        $this->assertStringNotContainsString('data-bs-toggle', $html);
    }

    public function testToggleScriptIsNotEmittedForNonAsciiSafeIds(): void
    {
        // Ids are interpolated into JS via json_encode; a quote in an id must not be
        // able to break out of the string literal.
        $html = $this->render();

        preg_match('/getElementById\("([^"]*)"\)/', $html, $m);
        $this->assertStringNotContainsString('\\"', $m[1]);
    }

    // ------------------------------------------------------------- panels

    public function testTimelinePanelListsAggregatedOperations(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Timeline', $html);
        $this->assertStringContainsString('loader.moduleHash', $html);
        $this->assertStringContainsString('ssr.send', $html);
    }

    public function testSpanPanelsAreRendered(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Slowest calls', $html);
        $this->assertStringContainsString('Slowest self time', $html);
    }

    public function testRequestPanelShowsContext(): void
    {
        $html = $this->render(12.0, ['section' => 'buttons']);

        $this->assertStringContainsString('Request', $html);
        $this->assertStringContainsString('section', $html);
        $this->assertStringContainsString('buttons', $html);
    }

    public function testJsonPanelEmitsDecodableReport(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Raw report', $html);

        $json = $this->extractJson($html);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded, 'Raw report is not valid JSON');
        $this->assertArrayHasKey('duration_ms', $decoded);
        $this->assertArrayHasKey('aggregates', $decoded);
        $this->assertArrayHasKey('loader.moduleHash', $decoded['aggregates']);
        $this->assertSame(1, $decoded['aggregates']['ssr.send']['count']);
    }

    // ------------------------------------------------------- panel toggles

    public function testDisabledPanelIsOmitted(): void
    {
        $html = $this->render(12.0, [], ['timeline' => false]);

        $this->assertStringNotContainsString('Per-operation rollup', $html);
        $this->assertStringContainsString('Slowest calls', $html);
    }

    public function testJsonPanelCanBeDisabled(): void
    {
        $html = $this->render(12.0, [], ['json' => false]);

        $this->assertStringNotContainsString('Raw report', $html);
    }

    public function testAllPanelsCanBeDisabledLeavingNoTables(): void
    {
        $html = $this->render(12.0, [], [
            'timeline' => false,
            'spans' => false,
            'request' => false,
            'json' => false,
        ]);

        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString('Phue DevTool', $html);
    }

    // --------------------------------------------------------- empty state

    public function testEmptyProfilerStillRendersValidToolbar(): void
    {
        $profiler = new RequestProfiler(new StopwatchAlias());
        $profiler->start();
        $profiler->stop();

        $html = $this->toolbar->render($profiler, 0.0);

        $this->assertStringContainsString('Phue DevTool', $html);
        $this->assertStringContainsString('No spans were recorded', $html);
        $this->assertStringContainsString('0 events', $html);
    }

    public function testUnbalancedSpansProduceAWarning(): void
    {
        $profiler = $this->profiled();
        $profiler->beginSpan('never-closed');

        $html = $this->toolbar->render($profiler, 5.0);

        $this->assertStringContainsString('left open', $html);
    }

    public function testMissingStatusFallsBackToPlaceholder(): void
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        $profiler->stop();

        $html = $this->toolbar->render($profiler, 1.0);

        $this->assertStringContainsString('>-<', $html);
    }

    // ------------------------------------------------------- payload caps

    public function testAggregateRowsAreCapped(): void
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        for ($i = 0; $i < DebugToolbar::MAX_AGGREGATE_ROWS + 25; $i++) {
            $profiler->measure('op' . $i, static function (): void {
            });
        }
        $profiler->stop();

        $html = $this->toolbar->render($profiler, 10.0);

        // Rows are ordered by duration, so which labels survive the cap is not
        // deterministic. The contract under test is the row budget itself.
        $this->assertSame(DebugToolbar::MAX_AGGREGATE_ROWS, $this->countTimelineRows($html));
        $this->assertStringContainsString(
            sprintf('%d more operation(s) not shown', DebugToolbar::MAX_AGGREGATE_ROWS + 25 - DebugToolbar::MAX_AGGREGATE_ROWS),
            $html
        );
    }

    public function testThousandsOfSpansDoNotInflateTheToolbar(): void
    {
        // The failure this guards against: 6000 loader calls rendering 6000 table rows,
        // injecting more markup than the page being profiled. The contract is that the
        // payload tracks the panel caps, not the event count, so the strongest available
        // assertion is that a 10x larger event count barely moves the byte size.
        $small = $this->renderWithSpans(600);
        $large = $this->renderWithSpans(6000);

        // All calls share one label, so the rollup is a single row either way.
        $this->assertSame(1, $this->countTimelineRows($large));
        $this->assertLessThan(strlen($small) * 2, strlen($large));
        $this->assertLessThan(120000, strlen($large));
        $this->assertStringContainsString('6000', $large);
    }

    public function testComponentNamesAreBoundedByThePanelCaps(): void
    {
        $html = $this->renderWithSpans(6000);

        // Per-call names may surface only through the capped listings (two span panels plus
        // the raw report), never once per recorded call.
        $this->assertLessThan(
            6000,
            substr_count($html, 'PhComponent')
        );
    }

    // ---------------------------------------------------------------- tabs

    public function testReportIsPresentedAsTabs(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('class="nav nav-tabs', $html);
        $this->assertStringContainsString('role="tab"', $html);
    }

    public function testExactlyOneTabPanelIsVisibleInitially(): void
    {
        $html = $this->render();

        preg_match_all('/role="tabpanel"/', $html, $visible);
        preg_match_all('/role="tabpanel"[^>]*\shidden/', $html, $hidden);

        $this->assertGreaterThan(1, count($visible[0]));
        $this->assertCount(count($visible[0]) - 1, $hidden[0]);
    }

    public function testEveryTabControlsAnExistingPanel(): void
    {
        $html = $this->render();

        preg_match_all('/id="([^"]+)" class="nav-link/', $html, $tabs);
        preg_match_all('/aria-controls="([^"]+)"/', $html, $controls);
        preg_match_all('/id="([^"]+)"/', $html, $ids);

        $this->assertNotEmpty($tabs[1]);
        foreach ($controls[1] as $panelId) {
            $this->assertContains($panelId, $ids[1]);
        }
    }

    public function testFirstTabIsSelectedAndFocusable(): void
    {
        $html = $this->render();

        preg_match('/<button[^>]*role="tab"[^>]*>/', $html, $tab);

        $this->assertStringContainsString('aria-selected="true"', $tab[0]);
        $this->assertStringContainsString('tabindex="0"', $tab[0]);
    }

    public function testInactiveTabsAreNotFocusable(): void
    {
        $html = $this->render();

        preg_match_all('/<button[^>]*role="tab"[^>]*>/', $html, $tabs);

        $inactive = 0;
        foreach ($tabs[0] as $tab) {
            if (!str_contains($tab, 'aria-selected="true"')) {
                $inactive++;
                $this->assertStringContainsString('tabindex="-1"', $tab);
            }
        }

        $this->assertGreaterThan(0, $inactive);
    }

    public function testPanelWithoutDataGetsNoTab(): void
    {
        // An empty section in a debugging tool is indistinguishable from a broken one.
        $html = $this->renderWithSpans(0, []);

        $this->assertStringNotContainsString('>Request</button>', $html);
        $this->assertStringContainsString('>Timeline</button>', $html);
    }

    public function testEveryRenderedTabHasABody(): void
    {
        $html = $this->renderWithSpans(3, ['status' => 200]);

        preg_match_all('/role="tab" aria-selected="[^"]*" aria-controls="([^"]+)"/', $html, $controls);
        preg_match_all('/role="tabpanel"/', $html, $bodies);

        $this->assertNotEmpty($controls[1]);
        $this->assertCount(count($controls[1]), $bodies[0]);
    }

    // -------------------------------------------------------------- filter

    public function testTimelineExposesAFilterControl(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-phue-dt-filter', $html);
        $this->assertStringContainsString('type="search"', $html);
    }

    public function testFilterBehaviourIsWiredWithoutBootstrapJavaScript(): void
    {
        $html = $this->render();

        $this->assertStringContainsString("filter.addEventListener('input'", $html);
        $this->assertStringContainsString('row.hidden', $html);
        $this->assertStringNotContainsString('bootstrap.', $html);
    }

    public function testFilterIsScopedToItsOwnPanel(): void
    {
        $script = $this->extractScript($this->render());

        $this->assertStringContainsString("closest('.phue-dt-tabpanel')", $script);
    }

    public function testFilterIsAbsentWhenTheTimelineIsDisabled(): void
    {
        $html = $this->render(12.0, [], ['timeline' => false]);

        $this->assertStringNotContainsString('data-phue-dt-filter', $html);
    }

    public function testFilterScriptIsOmittedWhenNoFilterControlExists(): void
    {
        // Dead code in an injected <script> is still bytes shipped on every page load.
        $script = $this->extractScript($this->render(12.0, [], ['timeline' => false]));

        $this->assertStringNotContainsString('data-phue-dt-filter', $script);
        $this->assertStringContainsString('aria-expanded', $script);
    }

    // -------------------------------------------------------------- reload

    public function testReloadControlIsPresent(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('class="phue-dt-reload', $html);
        $this->assertStringContainsString('aria-label="Reload page and re-profile"', $html);
    }

    public function testReloadTriggersAFullPageReload(): void
    {
        $script = $this->extractScript($this->render());

        $this->assertStringContainsString('window.location.reload()', $script);
    }

    public function testReloadControlIsNotInsideTheToggleButton(): void
    {
        // A nested button inside the toggle would swallow the click and break collapsing.
        $html = $this->render();

        $toggle = $this->between($html, '<button type="button" id="', '</button>');
        $reloadPos = strpos($html, 'phue-dt-reload');

        $this->assertNotFalse($reloadPos);
        $this->assertGreaterThan(strpos($html, '</button>'), $reloadPos);
        $this->assertStringNotContainsString('phue-dt-reload', $toggle);
    }

    public function testReloadIsRenderedEvenWhenEveryPanelIsDisabled(): void
    {
        $html = $this->render(12.0, [], [
            'timeline' => false,
            'spans' => false,
            'queries' => false,
            'request' => false,
            'json' => false,
        ]);

        $this->assertStringContainsString('phue-dt-reload', $html);
        $this->assertStringContainsString('No report data collected', $html);
    }

    // ------------------------------------------------------------- database

    public function testDatabasePanelIsOmittedWhenNoStatementRan(): void
    {
        $html = $this->renderWithSpans(2, ['status' => 200]);

        $this->assertStringNotContainsString('>Database</button>', $html);
    }

    public function testDatabasePanelListsStatementsWithTiming(): void
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        $profiler->measure('db.query', static function (): void {
            usleep(300);
        }, ['sql' => 'SELECT * FROM users WHERE id = ?', 'bindings' => 1, 'rows' => 12]);
        $profiler->measure('db.execute', static function (): void {
        }, ['sql' => 'UPDATE users SET seen_at = NOW()', 'bindings' => 0, 'rows' => 1]);
        $profiler->addContext(['status' => 200]);
        $profiler->stop();

        $html = $this->toolbar->render($profiler, 10.0);

        $this->assertStringContainsString('>Database</button>', $html);
        $this->assertStringContainsString('SELECT * FROM users WHERE id = ?', $html);
        $this->assertStringContainsString('UPDATE users SET seen_at = NOW()', $html);
        $this->assertStringContainsString('2 statement(s)', $html);
        $this->assertStringContainsString('1 binding', $html);
        $this->assertStringContainsString('12 rows', $html);
    }

    public function testDatabaseStatementsAppearInTheRawReport(): void
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        $profiler->measure('db.query', static function (): void {
        }, ['sql' => 'SELECT 1', 'bindings' => 0, 'rows' => 1]);
        $profiler->stop();

        $report = json_decode($this->extractJson($this->toolbar->render($profiler, 5.0)), true);

        $this->assertIsArray($report);
        $this->assertCount(1, $report['queries']);
        $this->assertSame('SELECT 1', $report['queries'][0]['meta']['sql']);
    }

    public function testOnlyDatabasePrefixedSpansReachTheDatabasePanel(): void
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        $profiler->measure('db.query', static function (): void {
        }, ['sql' => 'SELECT 1']);
        $profiler->measure('loader.moduleHash', static function (): void {
        }, ['component' => 'PhButton']);
        $profiler->stop();

        $html = $this->toolbar->render($profiler, 5.0);

        $this->assertStringContainsString('SELECT 1', $html);
        $this->assertStringNotContainsString('PhButton</td><td class="text-break">component', $html);
    }

    public function testHostileSqlIsEscaped(): void
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        $profiler->measure('db.query', static function (): void {
        }, ['sql' => "SELECT '</td><script>alert(1)</script>'"]);
        $profiler->stop();

        $html = $this->toolbar->render($profiler, 5.0);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testLongSqlIsTruncatedEverywhereItIsRendered(): void
    {
        $sql = 'SELECT ' . str_repeat('a', DebugToolbar::MAX_SQL_LENGTH * 2);

        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        $profiler->measure('db.query', static function () use ($sql): void {
        }, ['sql' => $sql]);
        $profiler->stop();

        $html = $this->toolbar->render($profiler, 5.0);

        // The statement surfaces in the rollup row's metadata summary, in the database
        // table and in both slowest-call listings. Truncating only some of them would
        // undo the panel caps through the side door.
        $truncated = mb_substr($sql, 0, DebugToolbar::MAX_SQL_LENGTH) . '…';

        $this->assertSame(
            4,
            substr_count($html, $truncated),
            'Expected the truncated statement in the rollup summary, the database table and both span listings'
        );

        // The raw report deliberately keeps it verbatim: that panel exists to be pasted
        // into a bug report and is bounded by the row cap rather than by length. Exactly
        // one verbatim copy, in the export — never in a rendered table.
        $this->assertStringContainsString($sql, $this->extractJson($html));
        $this->assertSame(0, substr_count($this->stripJsonPanel($html), $sql));
        $this->assertSame(4, substr_count($this->stripJsonPanel($html), $truncated));
    }

    public function testRedactedReportSaysSo(): void
    {
        // A report that silently shows a redacted statement is indistinguishable from one
        // where the query really was that short.
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        $profiler->measure('db.query', static function (): void {
        }, ['sql' => 'SELECT * FROM users WHERE email = ?', 'bindings' => 1, 'redacted' => true]);
        $profiler->stop();

        $this->assertStringContainsString('quoted literals redacted', $this->toolbar->render($profiler, 5.0));
    }

    public function testUnredactedReportDoesNotClaimRedaction(): void
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        $profiler->measure('db.query', static function (): void {
        }, ['sql' => 'SELECT * FROM users', 'bindings' => 0, 'redacted' => false]);
        $profiler->stop();

        $this->assertStringNotContainsString('quoted literals redacted', $this->toolbar->render($profiler, 5.0));
    }

    public function testShareIsComputedFromSelfTime(): void
    {
        // A parent's total contains every descendant, so totals over the request duration
        // double-count and saturate. Share must come from exclusive time, which partitions
        // the request, and the percentages must add up.
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        $profiler->beginSpan('wrapper');
        $profiler->measure('inner', static function (): void {
            usleep(4000);
        });
        $profiler->measure('inner', static function (): void {
            usleep(4000);
        });
        $profiler->measure('solo', static function (): void {
            usleep(4000);
        });
        $profiler->endSpan();
        $profiler->stop();

        $aggregates = $profiler->getAggregates();
        $selfTotal = array_sum(array_column($aggregates, 'exclusive_ms'));

        // Ordered by self time, so the heaviest own-work label leads.
        $this->assertSame(['inner', 'solo', 'wrapper'], array_keys($aggregates));

        $shares = [];
        foreach ($aggregates as $label => $aggregate) {
            $shares[$label] = (float) $aggregate['exclusive_ms'] / $selfTotal * 100;
        }

        $this->assertEqualsWithDelta(100.0, array_sum($shares), 0.01, 'self-time shares must partition the request');
        $this->assertLessThan(100.0, $shares['wrapper'], 'a wrapper must not be credited with its children');
        $this->assertEqualsWithDelta(
            (float) $aggregates['inner']['exclusive_ms'] / $selfTotal * 100,
            $shares['inner'],
            0.01
        );
    }

    public function testStatementRowsAreCapped(): void
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        for ($i = 0; $i < DebugToolbar::MAX_QUERY_ROWS + 15; $i++) {
            $profiler->measure('db.query', static function (): void {
            }, ['sql' => 'SELECT ' . $i]);
        }
        $profiler->stop();

        $html = $this->toolbar->render($profiler, 5.0);

        $this->assertStringContainsString('more statement(s) not shown', $html);
    }

    // ------------------------------------------------------------- helpers

    private function extractPanelId(string $html): string
    {
        preg_match('/id="([^"]+)" class="phue-dt-panel/', $html, $m);

        return $m[1] ?? '';
    }

    private function extractJson(string $html): string
    {
        preg_match('/<pre class="[^"]*phue-dt-json[^"]*">(.*?)<\/pre>/s', $html, $m);
        $raw = $m[1] ?? '';

        return html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function extractScript(string $html): string
    {
        $start = strrpos($html, '<script>');
        $end = strrpos($html, '</script>');

        return $start === false || $end === false ? '' : substr($html, $start + 8, $end - $start - 8);
    }

    /**
     * Count rendered rows in the timeline table body.
     */
    private function countTimelineRows(string $html): int
    {
        return $this->countRowsAfter($html, 'Share of self</th>');
    }

    /**
     * Count table rows that follow the first header cell containing the given text.
     */
    private function countRowsAfter(string $html, string $headerNeedle): int
    {
        $headerPos = strpos($html, $headerNeedle);
        if ($headerPos === false) {
            return 0;
        }

        $bodyStart = strpos($html, '<tbody>', $headerPos);
        $bodyEnd = strpos($html, '</tbody>', $bodyStart);
        if ($bodyStart === false || $bodyEnd === false) {
            return 0;
        }

        return substr_count(substr($html, $bodyStart, $bodyEnd - $bodyStart), '<tr>');
    }

    /**
     * Render a toolbar for a profiler carrying N spans of a single label.
     */
    private function renderWithSpans(int $count, array $context = [], ?float $duration = null): string
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        for ($i = 0; $i < $count; $i++) {
            $profiler->measure('loader.moduleHash', static function (): void {
            }, ['component' => 'PhComponent' . $i]);
        }
        $profiler->addContext($context);
        $profiler->stop();

        return $this->toolbar->render($profiler, $duration ?? 650.0);
    }

    /**
     * Return the substring between the first occurrence of $start and the first $end after it.
     */
    private function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        if ($from === false) {
            return '';
        }

        $to = strpos($haystack, $end, $from);

        return $to === false ? '' : substr($haystack, $from, $to - $from);
    }

    /**
     * Remove the raw-report tab panel so table assertions cannot see the export.
     */
    private function stripJsonPanel(string $html): string
    {
        // The last occurrence is the tabpanel's own id; the tab button's aria-controls
        // carries the same suffix and sits earlier in the document.
        $start = strrpos($html, '-body-json');
        if ($start === false) {
            return $html;
        }

        return substr($html, 0, $start);
    }

    // ------------------------------------------------------ span row budget

    public function testSpanRowsAreCapped(): void
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();
        for ($i = 0; $i < DebugToolbar::MAX_SPAN_ROWS + 20; $i++) {
            $profiler->measure('op' . $i, static function (): void {
                usleep(50);
            });
        }
        $profiler->stop();

        $html = $this->toolbar->render($profiler, 10.0);

        // Two span listings share the cap; the timeline and context tables add their own.
        $spanRows = $this->countRowsAfter($html, 'Detail</th>');

        $this->assertGreaterThan(0, $spanRows);
        $this->assertLessThanOrEqual(DebugToolbar::MAX_SPAN_ROWS * 2, $spanRows);
    }

    // ------------------------------------------------------------- theming

    public function testToolbarRendersInTheContrastingThemeByDefault(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-bs-theme="dark"', $html);
    }

    public function testThemeFallbackIsConfigurable(): void
    {
        $html = $this->render(12.0, [], ['theme' => 'auto', 'theme_fallback' => 'light']);

        $this->assertStringContainsString('data-bs-theme="light"', $html);
    }

    public function testAutoThemeResolvesTheOppositeOfTheApplication(): void
    {
        $script = $this->extractScript($this->render(12.0, [], ['theme' => 'auto']));

        $this->assertStringContainsString("getAttribute('data-bs-theme')", $script);
        $this->assertStringContainsString("t === 'dark' ? 'light' : 'dark'", $script);
        $this->assertStringContainsString('MutationObserver', $script);
        $this->assertStringContainsString('prefers-color-scheme', $script);
    }

    public function testAutoThemeFollowsTheApplicationToggle(): void
    {
        $script = $this->extractScript($this->render(12.0, [], ['theme' => 'auto']));

        // Watching <html> covers the app's own toggle; matchMedia covers a 'system'
        // preference; storage covers a second tab. All three are needed.
        $this->assertStringContainsString("attributeFilter: ['data-bs-theme']", $script);
        $this->assertStringContainsString("addEventListener('change', apply)", $script);
        $this->assertStringContainsString("addEventListener('storage', apply)", $script);
    }

    public function testPinnedThemeSkipsTheResolverScript(): void
    {
        $script = $this->extractScript($this->render(12.0, [], ['theme' => 'dark']));

        $this->assertStringNotContainsString('MutationObserver', $script);
        $this->assertStringNotContainsString('prefers-color-scheme', $script);
        $this->assertStringContainsString('aria-expanded', $script);
    }

    public function testPinnedThemeStillSetsTheAttribute(): void
    {
        $html = $this->render(12.0, [], ['theme' => 'dark']);

        $this->assertStringContainsString('data-bs-theme="dark"', $html);
    }

    public function testToolbarDoesNotUseThemeFixedBootstrapUtilities(): void
    {
        // btn-dark / text-light / table-dark / bg-dark ignore data-bs-theme, which would
        // defeat the whole point of re-theming the toolbar against the application.
        $html = $this->render();

        foreach (['btn-dark', 'text-light', 'table-dark', 'bg-dark'] as $utility) {
            $this->assertStringNotContainsString($utility, $html);
        }
    }

    public function testThemeNeutralUtilitiesAreUsedInstead(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('bg-body-secondary', $html);
        $this->assertStringContainsString('bg-body-tertiary', $html);
    }

    // --------------------------------------------------------- readability

    public function testDurationBadgeDoesNotOutsizeTheBarItSitsIn(): void
    {
        $html = $this->render();

        preg_match('/\.phue-dt-duration \{([^}]*)\}/', $html, $rule);
        preg_match('/\.phue-dt-root \{[^}]*font-size: ([0-9.]+)px/s', $html, $root);

        $this->assertNotEmpty($rule, 'The duration badge has no styling hook');
        $this->assertGreaterThanOrEqual(13.0, (float) $root[1]);

        // The bar used to print the duration at 16px next to 10px sibling chips. Read next
        // to them it said "this is the only figure that matters", which is the opposite of
        // what the panel then spends its whole width arguing. Severity is already carried
        // by the badge's tone, so the size does not have to carry it too.
        if (preg_match('/font-size:\s*([0-9.]+)px/', $rule[1], $size) === 1) {
            $this->assertLessThanOrEqual((float) $root[1], (float) $size[1]);
        }
    }

    public function testDurationBadgeStillCarriesTheSeverityTone(): void
    {
        // Shrinking the number is only honest while something else still says whether the
        // request was slow.
        preg_match('/<span class="badge phue-dt-duration text-bg-[^"]*"[^>]*>/', $this->render(), $badge);

        $this->assertNotEmpty($badge, 'The duration badge lost its tone');
    }

    public function testTablesAreNotDensifiedByBootstrapSmallUtility(): void
    {
        // Readability in a profiling table beats density: the font size is set once on the
        // toolbar instead of shrinking every cell with the `small` utility.
        $html = $this->render();

        $this->assertStringNotContainsString('class="font-monospace small"', $html);
        $this->assertStringContainsString('.phue-dt-root .table { font-size: 13px', $html);
    }

    public function testToolbarDefaultsToFabState(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-bar-visible="false"', $html);
        $this->assertStringContainsString('class="phue-dt-fab"', $html);
        $this->assertStringContainsString('src="/Phue.svg"', $html);
    }

    public function testCloseButtonRendersBeforeReload(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('class="phue-dt-close btn btn-sm"', $html);
        $this->assertStringContainsString('title="Collapse toolbar to icon"', $html);

        $closePos = strpos($html, 'class="phue-dt-close');
        $reloadPos = strpos($html, 'class="phue-dt-reload');

        $this->assertNotFalse($closePos);
        $this->assertNotFalse($reloadPos);
        $this->assertLessThan($reloadPos, $closePos, 'Close button must precede reload button');
    }

    public function testBadgesAndButtonsCarryDescriptiveTooltips(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('title="Total request duration"', $html);
        $this->assertStringContainsString('title="HTTP response status"', $html);
        $this->assertStringContainsString('title="Total profiled events"', $html);
        $this->assertStringContainsString('title="Memory usage at end of request"', $html);
        $this->assertStringContainsString('title="Peak memory usage during request"', $html);
        $this->assertStringContainsString('title="Response body size"', $html);
        $this->assertStringContainsString('title="Toggle DevTool bar"', $html);
        $this->assertStringContainsString('title="Collapse toolbar to icon"', $html);
        $this->assertStringContainsString('title="Re-run this request so the report matches the current page"', $html);
    }

    public function testStatusCodeBadgesUseContextualColors(): void
    {
        $html200 = $this->render(12.0, ['status' => 200]);
        $this->assertStringContainsString('class="badge text-bg-success" title="HTTP response status">200</span>', $html200);

        $html302 = $this->render(12.0, ['status' => 302]);
        $this->assertStringContainsString('class="badge text-bg-info" title="HTTP response status">302</span>', $html302);

        $html404 = $this->render(12.0, ['status' => 404]);
        $this->assertStringContainsString('class="badge text-bg-warning" title="HTTP response status">404</span>', $html404);

        $html500 = $this->render(12.0, ['status' => 500]);
        $this->assertStringContainsString('class="badge text-bg-danger" title="HTTP response status">500</span>', $html500);
    }

    public function testColdAndWarmRequestTooltipsAreIntuitive(): void
    {
        $coldHtml = $this->render();
        $this->assertStringContainsString('title="Cold request: initial run, caches are warming up"', $coldHtml);

        $this->profiler->start();
        $this->profiler->stop();
        $warmHtml = $this->toolbar->render($this->profiler, 12.0);
        $this->assertStringContainsString('title="Warm request: in-memory caches are ready"', $warmHtml);
    }

    // ------------------------------------------------------------------ live reload

    public function testThePollerIsInjectedOnEveryToolbar(): void
    {
        $html = $this->render();

        // Without the poller the endpoint is unreachable from the browser and the feature is
        // a server-side curiosity.
        $this->assertStringContainsString('/_phue/live-reload', $html);
        $this->assertStringContainsString('__phueLiveReload', $html);
        $this->assertStringContainsString('window.location.reload()', $html);
    }

    public function testTheEndpointPathComesFromTheRouteItself(): void
    {
        $this->assertStringContainsString(
            LiveReloadRoute::ENDPOINT,
            $this->render(),
            'the poller and the route must not be able to disagree about the path'
        );
    }

    public function testThePollerStopsAfterRepeatedFailures(): void
    {
        $html = $this->render();

        // A page whose endpoint is missing must not retry forever in the background. Five
        // failures then stop, and the guard is released so a later attempt can try again.
        $this->assertStringContainsString('failures', $html);
        $this->assertMatchesRegularExpression(
            '/failures\s*>=\s*\d+/',
            $html,
            'there must be a failure ceiling, or a 404 becomes a request every second forever'
        );
    }

    public function testThePollerPausesWhileTheTabIsHidden(): void
    {
        $this->assertStringContainsString(
            "visibilityState === 'hidden'",
            $this->render(),
            'a background tab has nothing to reload, and this is the common case'
        );
    }

    public function testThePollIntervalIsConfigurable(): void
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $toolbar = new DebugToolbar();

        $fast = $toolbar->render($profiler, 1.0, ['live_reload_interval_ms' => 300]);
        $slow = $toolbar->render($profiler, 1.0, ['live_reload_interval_ms' => 5000]);

        $this->assertMatchesRegularExpression('/var interval = \d+/', $fast);
        $this->assertStringContainsString('var interval = 300', $fast);
        $this->assertStringContainsString('var interval = 5000', $slow);
    }

    public function testAPollIntervalBelowTheFloorIsRaised(): void
    {
        $profiler = new RequestProfiler(new Stopwatch());

        $html = (new DebugToolbar())->render($profiler, 1.0, ['live_reload_interval_ms' => 1]);

        // A zero or negative interval is a hot loop against the dev server. The floor is small
        // enough to feel immediate and large enough to stay a request per quarter second.
        $this->assertStringContainsString('var interval = 250', $html);
    }

    public function testThePollerIsIndependentOfTheBarBeingVisible(): void
    {
        $html = $this->render();

        // The toolbar starts collapsed behind its FAB and the root carries pointer-events: none.
        // Gating the poller on visibility would mean live reload silently stops working the
        // moment the bar is collapsed, which is its default state.
        $this->assertStringContainsString('data-bar-visible="false"', $html);
        $this->assertStringContainsString('__phueLiveReload', $html);
    }
}
