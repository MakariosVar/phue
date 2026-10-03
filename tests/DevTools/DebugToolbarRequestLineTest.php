<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\DevTool\DebugToolbar;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;

/**
 * A panel that does not name its request cannot be compared with another one.
 *
 * The collapsed bar already prints the method, the path and a route chip, so an assertion
 * over the whole document passes whether or not the timeline names the request at all —
 * these tests are scoped to the panel for that reason.
 *
 * The bar is also the one part of the report that never travels: panels get pasted into
 * issues and lifted out of the page. On a route that renders a different subtree per
 * query string — /phui?section=button against /phui?section=otp-input — nothing else in
 * the rollup tells two reports of the same endpoint apart, and the query parameter is
 * usually the entire explanation for why one of them was expensive.
 */
#[Group('unit')]
class DebugToolbarRequestLineTest extends TestCase
{
    private DebugToolbar $toolbar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->toolbar = new DebugToolbar();
    }

    public function testTimelineNamesTheRequestItIsProfiling(): void
    {
        $panel = $this->renderPanel([
            'method' => 'GET',
            'uri' => '/phui',
            'route' => '/phui?section=otp-input',
        ]);

        $this->assertStringContainsString('GET', $panel);
        $this->assertStringContainsString('/phui?section=otp-input', $panel);
    }

    public function testTimelineFallsBackToThePathWhenNoTargetIsRecorded(): void
    {
        $panel = $this->renderPanel([
            'method' => 'POST',
            'uri' => '/login',
        ]);

        $this->assertStringContainsString('POST', $panel);
        $this->assertStringContainsString('/login', $panel);
    }

    public function testTimelineOmitsTheRequestLineWithoutARequestContext(): void
    {
        $panel = $this->renderPanel();

        // A hand-built profiler has no request to name. An empty label would suggest the
        // panel lost data rather than never had any.
        $this->assertStringNotContainsString('phue-dt-requestline', $panel);
    }

    public function testTimelineWithNoSpansReportsNoRequestLine(): void
    {
        $html = $this->toolbar->render($this->profilerWith([], [
            'method' => 'GET',
            'uri' => '/phui',
        ]), 10.0);

        // A panel that says "no spans were recorded" has nothing to attribute, and the
        // empty-state message must not be crowded out by a request line.
        $this->assertStringContainsString('No spans were recorded', $html);
        $this->assertStringNotContainsString('phue-dt-requestline', $html);
    }

    public function testTheRequestLineIsEscaped(): void
    {
        $panel = $this->renderPanel([
            'method' => 'GET',
            'uri' => '/x',
            'route' => '/x?q=<script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $panel);
        $this->assertStringContainsString('&lt;script&gt;', $panel);
    }

    public function testTheRequestLineIsTruncated(): void
    {
        $panel = $this->renderPanel([
            'method' => 'GET',
            'uri' => '/x',
            'route' => '/x?' . str_repeat('a=b&', 400),
        ]);

        $this->assertStringContainsString('…', $panel);
        $this->assertStringNotContainsString(str_repeat('a=b&', 400), $panel);
    }

    private function renderPanel(array $context = []): string
    {
        return $this->timelinePanel(
            $this->toolbar->render($this->profilerWith([
                'loader.buildFor',
            ], $context), 10.0)
        );
    }

    private function profilerWith(array $labels, array $context): RequestProfiler
    {
        $profiler = new RequestProfiler(new Stopwatch());
        $profiler->start();

        foreach ($labels as $label) {
            $profiler->measure($label, static function (): void {
            });
        }

        if ($context !== []) {
            $profiler->addContext($context);
        }

        $profiler->stop();

        return $profiler;
    }

    /**
     * The timeline tabpanel on its own.
     *
     * Anchored on the tabpanel's id attribute: matching the bare suffix is not enough,
     * because the tab button repeats it in aria-controls and Bootstrap's own
     * "text-body-secondary" contains the same characters as a delimiter.
     */
    private function timelinePanel(string $html): string
    {
        preg_match_all('/id="[^"]*-body-([a-z]+)"/', $html, $matches, PREG_OFFSET_CAPTURE);

        $start = null;
        $next = null;

        foreach ($matches[1] as $index => $capture) {
            if ($capture[0] !== 'timeline') {
                continue;
            }

            $start = $matches[0][$index][1];

            foreach ($matches[0] as $candidate) {
                if ($candidate[1] > $start) {
                    $next = $candidate[1];
                    break 2;
                }
            }
        }

        if ($start === null) {
            return '';
        }

        return $next === null
            ? substr($html, $start)
            : substr($html, $start, $next - $start);
    }
}