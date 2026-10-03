<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

/**
 * Class DebugToolbar
 *
 * Renders the bottom HTML debug toolbar: a collapsed summary bar that expands into a
 * tabbed profiling report.
 *
 * Styling strategy: Bootstrap 5 is already served as a framework default stylesheet
 * (see Application's asset registry), so the tab strip, tables, badges and alerts reuse
 * those classes and cost zero extra bytes. Only the handful of rules Bootstrap has no
 * class for are inlined: the fixed bottom bar, its z-index ceiling, the collapse, the tab
 * panel sizing and numeric alignment. The toolbar therefore degrades to a readable but
 * plain report if an application drops Bootstrap, instead of breaking.
 *
 * Design constraints that drive the markup:
 *
 *  1. Aggregates, not raw events. A hot page can enter the component loader thousands of
 *     times per request. Rendering one row per call would inject more HTML than the page
 *     it is profiling, so spans are rolled up per label server-side, only the slowest
 *     individual calls are listed verbatim, and every listing is capped.
 *  2. Tabs, not one long column. Six full-width tables inside a capped panel turn the
 *     report into a scroll hunt; tabs give each table the whole panel height.
 *  3. Everything dynamic is escaped. Labels and metadata originate from file names, query
 *     strings, SQL and header values, so they are untrusted input on a page that ships
 *     with a CSP allowing inline script.
 *
 * @package Phue\Modules\DevTool
 */
class DebugToolbar
{
    /**
     * Maximum aggregate rows rendered in the timeline panel.
     */
    public const MAX_AGGREGATE_ROWS = 40;

    /**
     * Maximum individual spans rendered in the slowest-calls panels.
     */
    public const MAX_SPAN_ROWS = 25;

    /**
     * Maximum statements rendered in the database panel.
     */
    public const MAX_QUERY_ROWS = 25;

    /**
     * Span label prefix reserved for database statements.
     */
    public const SPAN_PREFIX_DB = 'db.';

    /**
     * Maximum characters of SQL rendered per row before truncation.
     */
    public const MAX_SQL_LENGTH = 400;

    /**
     * Total duration above which the bar reports a slow request, in milliseconds.
     */
    public const SLOW_REQUEST_MS = 100.0;

    /**
     * Total duration above which the bar reports a very slow request, in milliseconds.
     */
    public const CRITICAL_REQUEST_MS = 400.0;

    /**
     * Marker attribute used to detect whether a filter control was rendered.
     */
    public const FILTER_MARKER = 'data-phue-dt-filter';

    /**
     * Element id of the first toolbar rendered. Kept stable for backwards compatibility
     * with tooling and tests that target the toolbar by id.
     */
    public const ROOT_ID = 'phue-debug-toolbar';

    /**
     * Monotonic suffix so multiple toolbars on one page never share element ids.
     */
    private static int $instanceCounter = 0;

    /**
     * Render the HTML debug toolbar.
     *
     * @param RequestProfiler $profiler The request profiler instance.
     * @param float $duration Total duration in milliseconds.
     * @param array<string, mixed> $options Panel toggles (see config/devtool.php).
     * @return string Rendered HTML snippet.
     */
    public function render(RequestProfiler $profiler, float $duration, array $options = []): string
    {
        $instance = ++self::$instanceCounter;
        $rootId = $instance === 1 ? self::ROOT_ID : self::ROOT_ID . '-' . $instance;

        $themeMode = (string) ($options['theme'] ?? 'auto');
        if ($themeMode !== 'light' && $themeMode !== 'dark') {
            $themeMode = 'auto';
        }

        $fallback = (string) ($options['theme_fallback'] ?? 'dark');
        $fallback = $fallback === 'light' ? 'light' : 'dark';

        $sections = $this->buildSections($profiler, $duration, $options);

        return $this->renderShell($rootId, $this->buildView($profiler, $duration, $fallback), $sections, $profiler)
            . $this->renderStyles()
            . $this->renderScript($rootId, $themeMode, str_contains($sections[0]['content'] ?? '', self::FILTER_MARKER)
                || $this->anySectionContains($sections, self::FILTER_MARKER));
    }

    /**
     * Assemble the report panels, honouring the configured toggles.
     *
     * A panel with nothing to show is omitted rather than rendered as a stub: an empty
     * section in a debugging tool is indistinguishable from a broken one.
     *
     * @param RequestProfiler $profiler The request profiler instance.
     * @param float $duration Total duration in milliseconds.
     * @param array<string, mixed> $options Panel toggles.
     * @return list<array{key: string, title: string, hint: string, content: string}>
     */
    private function buildSections(RequestProfiler $profiler, float $duration, array $options): array
    {
        $show = static fn(string $key, bool $default = true): bool => (bool) ($options[$key] ?? $default);

        $spans = $profiler->getSpans();
        $sections = [];

        if ($show('timeline')) {
            $sections[] = [
                'key' => 'timeline',
                'title' => 'Timeline',
                'hint' => 'Per-operation rollup, heaviest first. Filter by name or label.',
                'content' => $this->renderTimeline($profiler->getAggregates(), $duration),
            ];
        }

        if ($show('spans')) {
            $sections[] = [
                'key' => 'slowest',
                'title' => 'Slowest calls',
                'hint' => 'Individual calls ranked by total time, nested children included.',
                'content' => $this->renderSpanTable($profiler->getTopSpans(self::MAX_SPAN_ROWS)),
            ];
            $sections[] = [
                'key' => 'self',
                'title' => 'Slowest self time',
                'hint' => 'Ranked by exclusive time: total minus nested children.',
                'content' => $this->renderSpanTable($profiler->getTopExclusiveSpans(self::MAX_SPAN_ROWS)),
            ];
        }

        if ($show('queries')) {
            $queries = $this->filterDbSpans($spans);
            if ($queries !== []) {
                $sections[] = [
                    'key' => 'database',
                    'title' => 'Database',
                    'hint' => 'Statements executed during this request, slowest first.',
                    'content' => $this->renderQueryTable($queries),
                ];
            }
        }

        if ($show('request')) {
            $context = $profiler->getContext();
            if ($context !== []) {
                $sections[] = [
                    'key' => 'request',
                    'title' => 'Request',
                    'hint' => 'Metadata recorded by the DevTool middleware.',
                    'content' => $this->renderContextTable($context),
                ];
            }
        }

        if ($show('json')) {
            $sections[] = [
                'key' => 'json',
                'title' => 'Raw report',
                'hint' => 'Copy this JSON into an issue or a benchmark report.',
                'content' => $this->renderJson($profiler, $duration),
            ];
        }

        return $sections;
    }

    /**
     * Prepare the collapsed bar's display values.
     *
     * @param RequestProfiler $profiler The request profiler instance.
     * @param float $duration Total duration in milliseconds.
     * @param string $fallback First-paint theme.
     * @return array<string, mixed>
     */
    private function buildView(RequestProfiler $profiler, float $duration, string $fallback): array
    {
        $context = $profiler->getContext();
        $eventCount = array_sum(array_column($profiler->getAggregates(), 'count'));

        return [
            'duration_formatted' => number_format($duration, 2),
            'tone' => $this->tone($duration),
            'memory' => number_format($profiler->getMemoryUsage() / 1024 / 1024, 2) . ' MB',
            'peak' => number_format($profiler->getPeakMemoryUsage() / 1024 / 1024, 2) . ' MB',
            'status' => (int) ($context['status'] ?? 0),
            'bytes' => (int) ($context['response_bytes'] ?? 0),
            'events' => $eventCount,
            'uri' => (string) ($context['uri'] ?? ''),
            'method' => strtoupper((string) ($context['method'] ?? '')),
            'route' => (string) ($context['route'] ?? ''),
            'theme_fallback' => $fallback,
        ];
    }

    /**
     * Render the toolbar shell: collapsible panel with tabs, plus the summary bar.
     *
     * The panel must live inside the root element. Styling is scoped under
     * `.phue-dt-root`, so a sibling panel would receive none of it — including the
     * `display: none` that makes the `hidden` attribute work.
     *
     * @param string $rootId Unique root element id.
     * @param array<string, mixed> $view Prepared display values.
     * @param list<array{key: string, title: string, hint: string, content: string}> $sections Report panels.
     * @param RequestProfiler $profiler The request profiler instance.
     * @return string
     */
    private function renderShell(string $rootId, array $view, array $sections, RequestProfiler $profiler): string
    {
        $panelId = $rootId . '-panel';
        $toggleId = $rootId . '-toggle';
        $reloadId = $rootId . '-reload';
        $status = (int) $view['status'];
        $tone = (string) $view['tone'];

        $subtitle = trim((string) $view['method'] . ' ' . (string) $view['uri']);
        if ($subtitle === '') {
            $subtitle = 'no request context';
        }

        // The route chip only earns its width when it adds information the subtitle does
        // not already carry. The router does not expose the matched pattern, so the route
        // usually *is* the uri and rendering both just duplicates the text.
        $route = (string) $view['route'];
        $routeChip = ($route !== '' && $route !== $subtitle) ? $route : '';

        $chips = '<span class="badge text-bg-light">' . $this->esc($status > 0 ? (string) $status : '-') . '</span>'
            . '<span class="badge text-bg-secondary">' . $this->esc((string) $view['events']) . ' events</span>'
            . '<span class="badge text-bg-secondary">' . $this->esc((string) $view['memory']) . '</span>'
            . '<span class="badge text-bg-secondary">peak ' . $this->esc((string) $view['peak']) . '</span>'
            . '<span class="badge text-bg-secondary">' . $this->esc($this->formatBytes((int) $view['bytes'])) . '</span>';

        return '<div id="' . $this->esc($rootId) . '" class="phue-dt-root bg-body-secondary text-body"'
            . ' data-bs-theme="' . $this->esc((string) $view['theme_fallback']) . '"'
            . ' data-tone="' . $this->esc($tone) . '" data-expanded="false">'
            . '<div id="' . $this->esc($panelId) . '" class="phue-dt-panel bg-body-tertiary p-3 overflow-auto" hidden>'
            . $this->renderNotices($profiler)
            . $this->renderTabs($rootId, $sections)
            . '</div>'
            . '<button type="button" id="' . $this->esc($toggleId) . '"'
            // No `btn-dark`/`text-light` here: those are theme-fixed utilities and would
            // defeat the whole point of re-theming the toolbar against the application.
            . ' class="phue-dt-toggle btn btn-sm w-100 d-flex align-items-center gap-2 rounded-0'
            . ' border-top text-start"'
            . ' aria-expanded="false" aria-controls="' . $this->esc($panelId) . '">'
            . '<span class="phue-dt-caret" aria-hidden="true">&#9650;</span>'
            . '<span class="fw-bold text-info-emphasis">Phue DevTool</span>'
            . '<span class="badge phue-dt-duration text-bg-' . $this->esc($this->toneBootstrap($tone)) . '">'
            . $this->esc((string) $view['duration_formatted']) . ' ms</span>'
            . $chips
            . '<span class="ms-auto text-secondary text-truncate phue-dt-subtitle">' . $this->esc($subtitle) . '</span>'
            . '</button>'
            . '<button type="button" id="' . $this->esc($reloadId) . '" class="phue-dt-reload btn btn-sm ms-2"'
            . ' title="Re-run this request so the report matches the current page"'
            . ' aria-label="Reload page and re-profile">'
            . '<span aria-hidden="true">&#8635;</span></button>'
            . '</div>';
    }

    /**
     * Render the tab strip and the tab panels.
     *
     * @param string $rootId Unique root element id.
     * @param list<array{key: string, title: string, hint: string, content: string}> $sections Report panels.
     * @return string
     */
    private function renderTabs(string $rootId, array $sections): string
    {
        if ($sections === []) {
            return '<p class="phue-dt-note text-secondary mb-0">No report data collected for this request.</p>';
        }

        $items = '';
        $bodies = '';

        foreach ($sections as $index => $section) {
            $tabId = $rootId . '-tab-' . $section['key'];
            $bodyId = $rootId . '-body-' . $section['key'];
            $active = $index === 0;

            $items .= '<li class="nav-item" role="presentation">'
                . '<button type="button" id="' . $this->esc($tabId) . '"'
                . ' class="nav-link py-1 px-2' . ($active ? ' active' : '') . '"'
                . ' data-phue-dt-tab role="tab"'
                . ' aria-selected="' . ($active ? 'true' : 'false') . '"'
                . ' aria-controls="' . $this->esc($bodyId) . '"'
                . ' tabindex="' . ($active ? '0' : '-1') . '">'
                . $this->esc($section['title'])
                . '</button></li>';

            $bodies .= '<div id="' . $this->esc($bodyId) . '" role="tabpanel"'
                . ' aria-labelledby="' . $this->esc($tabId) . '"'
                . ' class="phue-dt-tabpanel"' . ($active ? '' : ' hidden') . '>'
                . '<p class="phue-dt-hint text-secondary">' . $this->esc($section['hint']) . '</p>'
                . $section['content']
                . '</div>';
        }

        return '<ul class="nav nav-tabs phue-dt-tabs mb-2" role="tablist">' . $items . '</ul>'
            . $bodies;
    }

    /**
     * Render the timeline panel: a rollup table plus a client-side filter.
     *
     * @param array<string, array<string, mixed>> $aggregates Per-label rollup.
     * @param float $totalDuration Total request duration, used to scale the bars.
     * @return string
     */
    private function renderTimeline(array $aggregates, float $totalDuration): string
    {
        if ($aggregates === []) {
            return '<p class="phue-dt-note text-secondary mb-0">No spans were recorded for this request.</p>';
        }

        $rows = '';
        $shown = 0;

        foreach ($aggregates as $label => $aggregate) {
            if ($shown >= self::MAX_AGGREGATE_ROWS) {
                break;
            }
            $shown++;

            $total = (float) $aggregate['total_ms'];
            $ratio = $totalDuration > 0.0 ? min(100.0, $total / $totalDuration * 100.0) : 0.0;

            $rows .= '<tr>'
                . '<td class="font-monospace">' . $this->esc((string) $label) . '</td>'
                . '<td class="phue-dt-num">' . $this->esc((string) $aggregate['count']) . '</td>'
                . '<td class="phue-dt-num fw-bold">' . $this->esc(number_format($total, 2)) . '</td>'
                . '<td class="phue-dt-num">' . $this->esc(number_format((float) $aggregate['avg_ms'], 3)) . '</td>'
                . '<td class="phue-dt-num">' . $this->esc(number_format((float) $aggregate['max_ms'], 2)) . '</td>'
                . '<td style="width:20%"><span class="phue-dt-bar bg-info" style="width:'
                . $this->esc(number_format($ratio, 2)) . '%"></span></td>'
                . '</tr>';
        }

        $remaining = count($aggregates) - $shown;
        $footnote = $remaining > 0
            ? '<p class="phue-dt-note text-secondary mb-0 mt-1">'
                . $this->esc(sprintf('%d more operation(s) not shown.', $remaining)) . '</p>'
            : '';

        $filter = '<input type="search" class="form-control form-control-sm phue-dt-filter mb-2"'
            . ' placeholder="Filter operations…" aria-label="Filter operations"'
            . ' data-phue-dt-filter>';

        return $filter
            . $this->wrapTable(
                '<thead><tr>'
                . '<th scope="col">Operation</th>'
                . '<th scope="col" class="phue-dt-num">Calls</th>'
                . '<th scope="col" class="phue-dt-num">Total ms</th>'
                . '<th scope="col" class="phue-dt-num">Avg ms</th>'
                . '<th scope="col" class="phue-dt-num">Max ms</th>'
                . '<th scope="col">Share</th>'
                . '</tr></thead>',
                $rows
            )
            . $footnote;
    }

    /**
     * Render a table of individual spans.
     *
     * @param list<array<string, mixed>> $spans Spans to list.
     * @return string
     */
    private function renderSpanTable(array $spans): string
    {
        if ($spans === []) {
            return '<p class="phue-dt-note text-secondary mb-0">No spans recorded.</p>';
        }

        $rows = '';
        foreach ($spans as $span) {
            $rows .= '<tr>'
                . '<td class="font-monospace">' . $this->esc((string) $span['label']) . '</td>'
                . '<td class="text-break">' . $this->esc($this->describeMeta((array) ($span['meta'] ?? []))) . '</td>'
                . '<td class="phue-dt-num fw-bold">' . $this->esc(number_format((float) $span['duration_ms'], 2)) . '</td>'
                . '<td class="phue-dt-num">' . $this->esc(number_format((float) $span['exclusive_ms'], 2)) . '</td>'
                . '<td class="phue-dt-num">' . $this->esc((string) ($span['depth'] ?? 0)) . '</td>'
                . '</tr>';
        }

        return $this->wrapTable(
            '<thead><tr>'
            . '<th scope="col">Operation</th>'
            . '<th scope="col">Detail</th>'
            . '<th scope="col" class="phue-dt-num">Total ms</th>'
            . '<th scope="col" class="phue-dt-num">Self ms</th>'
            . '<th scope="col" class="phue-dt-num">Depth</th>'
            . '</tr></thead>',
            $rows
        );
    }

    /**
     * Render the database panel.
     *
     * Rendered from the same span list as everything else rather than from a parallel
     * query log: one collector means one ordering, one cap and one lifetime rule, and the
     * timeline rollup already accounts for database time without a second source of truth.
     *
     * @param list<array<string, mixed>> $queries Database spans.
     * @return string
     */
    private function renderQueryTable(array $queries): string
    {
        $totalMs = array_sum(array_map(
            static fn(array $span): float => (float) $span['duration_ms'],
            $queries
        ));

        $rows = '';
        $shown = 0;

        foreach ($queries as $span) {
            if ($shown >= self::MAX_QUERY_ROWS) {
                break;
            }
            $shown++;

            $meta = (array) ($span['meta'] ?? []);
            $sql = $this->truncate((string) ($meta['sql'] ?? ''), self::MAX_SQL_LENGTH);

            $detail = [];
            if (is_int($meta['bindings'] ?? null)) {
                $detail[] = $meta['bindings'] . ' binding' . ($meta['bindings'] === 1 ? '' : 's');
            }
            if (is_int($meta['rows'] ?? null)) {
                $detail[] = $meta['rows'] . ' row' . ($meta['rows'] === 1 ? '' : 's');
            }

            $rows .= '<tr>'
                . '<td class="phue-dt-sql font-monospace">' . $this->esc($sql) . '</td>'
                . '<td class="text-nowrap text-secondary">' . $this->esc(implode(' · ', $detail)) . '</td>'
                . '<td class="phue-dt-num fw-bold">' . $this->esc(number_format((float) $span['duration_ms'], 2)) . '</td>'
                . '</tr>';
        }

        $remaining = count($queries) - $shown;
        $footnote = $remaining > 0
            ? '<p class="phue-dt-note text-secondary mb-0 mt-1">'
                . $this->esc(sprintf('%d more statement(s) not shown.', $remaining)) . '</p>'
            : '';

        // Say so when the recorded text is not what the driver received. A report that
        // silently shows a redacted statement is indistinguishable from one where the
        // query really was that short.
        $redacted = false;
        foreach ($queries as $span) {
            if (($span['meta']['redacted'] ?? false) === true) {
                $redacted = true;
                break;
            }
        }

        $summary = '<p class="phue-dt-note mb-2">'
            . $this->esc(sprintf(
                '%d statement(s), %s ms total.',
                count($queries),
                number_format($totalMs, 2)
            ))
            . ($redacted
                ? ' &middot; <span class="text-warning-emphasis">quoted literals redacted'
                    . ' (config: devtool.sql_literals)</span>'
                : '')
            . '</p>';

        return $summary
            . $this->wrapTable(
                '<thead><tr>'
                . '<th scope="col">Statement</th>'
                . '<th scope="col">Result</th>'
                . '<th scope="col" class="phue-dt-num">ms</th>'
                . '</tr></thead>',
                $rows
            )
            . $footnote;
    }

    /**
     * Render the request context table.
     *
     * @param array<string, mixed> $context Request metadata.
     * @return string
     */
    private function renderContextTable(array $context): string
    {
        ksort($context);

        $rows = '';
        foreach ($context as $key => $value) {
            $rows .= '<tr>'
                . '<td class="font-monospace text-nowrap">' . $this->esc((string) $key) . '</td>'
                . '<td class="text-break">' . $this->esc($this->stringify($value)) . '</td>'
                . '</tr>';
        }

        return $this->wrapTable(
            '<thead><tr><th scope="col">Key</th><th scope="col">Value</th></tr></thead>',
            $rows,
            striped: false
        );
    }

    /**
     * Wrap a table head and body in a scroll container.
     *
     * @param string $head Table head markup.
     * @param string $rows Table body rows.
     * @param bool $striped Whether to apply Bootstrap's striped rows.
     * @return string
     */
    private function wrapTable(string $head, string $rows, bool $striped = true): string
    {
        return '<div class="table-responsive">'
            . '<table class="table table-sm align-middle mb-0' . ($striped ? ' table-striped' : '') . '">'
            . $head . '<tbody>' . $rows . '</tbody>'
            . '</table>'
            . '</div>';
    }

    /**
     * Render the raw report as a JSON payload for copy/paste or external tooling.
     *
     * @param RequestProfiler $profiler The request profiler instance.
     * @param float $duration Total duration in milliseconds.
     * @return string
     */
    private function renderJson(RequestProfiler $profiler, float $duration): string
    {
        $report = [
            'duration_ms' => round($duration, 3),
            'memory_bytes' => $profiler->getMemoryUsage(),
            'peak_memory_bytes' => $profiler->getPeakMemoryUsage(),
            'context' => $profiler->getContext(),
            'aggregates' => $profiler->getAggregates(),
            'top_spans' => $profiler->getTopSpans(self::MAX_SPAN_ROWS),
            'top_exclusive_spans' => $profiler->getTopExclusiveSpans(self::MAX_SPAN_ROWS),
            'queries' => $this->filterDbSpans($profiler->getSpans()),
            'diagnostics' => $profiler->getDiagnostics(),
            'dropped_spans' => $profiler->getDroppedSpanCount(),
            'open_spans' => $profiler->getOpenSpanCount(),
        ];

        // HEX flags keep the payload inert if it is ever inlined into a script context.
        $json = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if ($json === false) {
            $json = '{"error":"report could not be encoded"}';
        }

        return '<pre class="bg-body border rounded p-2 mb-0 phue-dt-json">' . $this->esc($json) . '</pre>';
    }

    /**
     * Select the database spans out of the full span list.
     *
     * @param list<array<string, mixed>> $spans All recorded spans.
     * @return list<array<string, mixed>> Database spans, slowest first.
     */
    private function filterDbSpans(array $spans): array
    {
        $queries = array_values(array_filter(
            $spans,
            static fn(array $span): bool => str_starts_with((string) $span['label'], self::SPAN_PREFIX_DB)
        ));

        usort(
            $queries,
            static fn(array $a, array $b): int => $b['duration_ms'] <=> $a['duration_ms']
        );

        return $queries;
    }

    /**
     * Whether any section body contains the given marker.
     *
     * @param list<array{key: string, title: string, hint: string, content: string}> $sections Report panels.
     * @param string $marker Marker string.
     * @return bool
     */
    private function anySectionContains(array $sections, string $marker): bool
    {
        foreach ($sections as $section) {
            if (str_contains($section['content'], $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render the integrity notices that must appear above the tabs.
     *
     * @param RequestProfiler $profiler The request profiler instance.
     * @return string
     */
    private function renderNotices(RequestProfiler $profiler): string
    {
        $notices = '';

        foreach ($profiler->getDiagnostics() as $diagnostic) {
            $detail = (string) ($diagnostic['context']['exception'] ?? '');
            $notices .= $this->notice(
                'alert-warning',
                $diagnostic['message'] . ($detail !== '' ? ': ' . $detail : '')
            );
        }

        if ($profiler->getOpenSpanCount() > 0) {
            $notices .= $this->notice('alert-warning', sprintf(
                '%d span(s) left open — an instrumented call did not balance.',
                $profiler->getOpenSpanCount()
            ));
        }

        if ($profiler->getDroppedSpanCount() > 0) {
            $notices .= $this->notice('alert-warning', sprintf(
                '%d span(s) dropped after the collector cap.',
                $profiler->getDroppedSpanCount()
            ));
        }

        return $notices === '' ? '' : '<div class="phue-dt-notices mb-2">' . $notices . '</div>';
    }

    /**
     * Render one notice box.
     *
     * @param string $variant Bootstrap contextual variant.
     * @param string $message Notice text.
     * @return string
     */
    private function notice(string $variant, string $message): string
    {
        return '<div class="alert ' . $this->esc($variant) . ' py-1 px-2 mb-2 phue-dt-note">'
            . $this->esc($message) . '</div>';
    }

    /**
     * Render the minimal stylesheet Bootstrap does not cover.
     *
     * @return string
     */
    private function renderStyles(): string
    {
        return '<style>' . <<<'CSS'
        .phue-dt-root {
            position: fixed; left: 0; right: 0; bottom: 0; z-index: 2147483000;
            display: flex; flex-direction: column;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 13px; line-height: 1.5;
        }
        .phue-dt-root, .phue-dt-root * { box-sizing: border-box; }
        .phue-dt-toggle {
            flex: 0 0 auto; flex-wrap: wrap; gap: 8px; padding: 7px 12px; font-size: 13px;
            border-radius: 0 !important; border-top: 1px solid var(--bs-border-color) !important;
        }
        /* The headline number: the one figure the bar exists to communicate. */
        .phue-dt-duration { font-size: 16px; font-weight: 700; padding: 2px 10px; letter-spacing: .2px; }
        .phue-dt-subtitle { font-size: 12.5px; max-width: 40ch; }
        .phue-dt-caret { font-size: 10px; transition: transform .15s ease; }
        .phue-dt-root[data-expanded="true"] .phue-dt-caret { transform: rotate(180deg); }
        /* The reload control sits beside the bar but must not stretch the flex row. */
        .phue-dt-reload {
            position: absolute; right: 8px; bottom: 100%; margin: 0 0 6px 0;
            border: 1px solid var(--bs-border-color); background: var(--bs-body-bg);
            color: var(--bs-body-color); line-height: 1; padding: 4px 9px; font-size: 14px;
        }
        .phue-dt-reload:hover { background: var(--bs-secondary-bg); }
        .phue-dt-reload:focus-visible { outline: 2px solid var(--bs-primary); outline-offset: 1px; }
        .phue-dt-panel {
            order: -1; flex: 0 1 auto; min-height: 0; max-height: 62vh;
            border-bottom: 1px solid var(--bs-border-color);
        }
        .phue-dt-panel[hidden] { display: none; }
        .phue-dt-tabs { flex-wrap: wrap; }
        .phue-dt-tabs .nav-link { font-size: 12.5px; border: 0; background: transparent; cursor: pointer; }
        .phue-dt-tabs .nav-link:focus-visible { outline: 2px solid var(--bs-primary); outline-offset: -2px; }
        .phue-dt-tabpanel[hidden] { display: none; }
        .phue-dt-hint { font-size: 12px; margin: 0 0 8px; }
        .phue-dt-note { font-size: 12px; }
        /* Readability beats density in a profiling table. */
        .phue-dt-root .table { font-size: 13px; margin-bottom: 0; }
        .phue-dt-root .table th { font-size: 11px; letter-spacing: .5px; padding: 6px 8px; }
        .phue-dt-root .table td { padding: 5px 8px; }
        .phue-dt-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .phue-dt-sql { max-width: 90ch; white-space: pre-wrap; word-break: break-word; }
        .phue-dt-bar { display: block; height: 9px; min-width: 1px; border-radius: 2px; opacity: .8; }
        .phue-dt-filter { max-width: 26rem; }
        .phue-dt-json {
            max-height: 46vh; overflow: auto; font-size: 12px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        @media (prefers-reduced-motion: reduce) { .phue-dt-caret { transition: none; } }
        CSS . '</style>';
    }

    /**
     * Render the toolbar behaviour: collapse, tabs, filter, reload and theme.
     *
     * Deliberately vanilla. The framework also loads bootstrap.bundle.js, but a debug
     * toolbar that silently stops expanding when an application trims its JS bundle is a
     * bad trade for a few saved lines.
     *
     * @param string $rootId Root element id.
     * @param string $themeMode Either 'auto', 'light' or 'dark'.
     * @param bool $withFilter Whether a filter control was rendered.
     * @return string
     */
    private function renderScript(string $rootId, string $themeMode = 'auto', bool $withFilter = true): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $root = json_encode($rootId, $flags);

        // The theme resolver is omitted for a pinned theme rather than emitted behind a
        // dead branch: dead code in an injected <script> is still bytes on every page.
        $themeScript = $themeMode === 'auto' ? $this->renderThemeScript($root) : '';
        $filterScript = $withFilter ? $this->renderFilterScript($root) : '';

        $js = <<<JS
        (function () {
            var root = document.getElementById({$root});
            if (!root) { return; }

            var toggle = root.querySelector('.phue-dt-toggle');
            var panel = root.querySelector('.phue-dt-panel');
            var reload = root.querySelector('.phue-dt-reload');

            if (toggle && panel) {
                toggle.addEventListener('click', function () {
                    var open = toggle.getAttribute('aria-expanded') === 'true';
                    toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
                    panel.hidden = open;
                    root.setAttribute('data-expanded', open ? 'false' : 'true');
                });
            }

            if (reload) {
                reload.addEventListener('click', function () { window.location.reload(); });
            }

            var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-phue-dt-tab]'));
            if (tabs.length) {
                var select = function (tab) {
                    tabs.forEach(function (candidate) {
                        var active = candidate === tab;
                        candidate.classList.toggle('active', active);
                        candidate.setAttribute('aria-selected', active ? 'true' : 'false');
                        candidate.setAttribute('tabindex', active ? '0' : '-1');
                        var body = document.getElementById(candidate.getAttribute('aria-controls'));
                        if (body) { body.hidden = !active; }
                    });
                };

                tabs.forEach(function (tab, index) {
                    tab.addEventListener('click', function () { select(tab); });
                    tab.addEventListener('keydown', function (event) {
                        var step = 0;
                        if (event.key === 'ArrowRight') { step = 1; }
                        else if (event.key === 'ArrowLeft') { step = -1; }
                        else if (event.key === 'Home') { step = -index; }
                        else if (event.key === 'End') { step = tabs.length - 1 - index; }
                        else { return; }
                        event.preventDefault();
                        var next = tabs[(index + step + tabs.length) % tabs.length];
                        next.focus();
                        select(next);
                    });
                });
            }
        })();
        JS;

        return '<script>' . $js . $filterScript . $themeScript . '</script>';
    }

    /**
     * Render the timeline filter behaviour, scoped to the filter's own tab.
     *
     * Emitted only when a filter control exists: dead code in an injected <script> is
     * still bytes shipped on every page load.
     *
     * @return string
     */
    private function renderFilterScript(string $rootJson): string
    {
        // Scoped to this toolbar's root rather than to the document, so several toolbars on
        // one page each filter their own table instead of racing over the first match.
        return <<<JS

        (function () {
            var root = document.getElementById({$rootJson});
            if (!root) { return; }
            var filter = root.querySelector('[data-phue-dt-filter]');
            if (!filter) { return; }
            filter.addEventListener('input', function () {
                var needle = filter.value.trim().toLowerCase();
                var scope = filter.closest('.phue-dt-tabpanel');
                if (!scope) { return; }
                scope.querySelectorAll('tbody tr').forEach(function (row) {
                    var text = (row.textContent || '').toLowerCase();
                    row.hidden = needle !== '' && text.indexOf(needle) === -1;
                });
            });
        })();
        JS;
    }

    /**
     * Render the contrasting-theme resolver.
     *
     * @param string $rootJson JSON-encoded root element id.
     * @return string
     */
    private function renderThemeScript(string $rootJson): string
    {
        return <<<JS

        (function () {
            var root = document.getElementById({$rootJson});
            if (!root) { return; }

            // The toolbar renders in the opposite theme from the application so it reads
            // as an instrument rather than blending into the page it is measuring. Bootstrap
            // inherits data-bs-theme, so flipping this one attribute re-themes every table,
            // badge and alert inside the toolbar with no extra CSS.
            var opposite = function (t) { return t === 'dark' ? 'light' : 'dark'; };
            var resolve = function () {
                var attr = document.documentElement.getAttribute('data-bs-theme');
                if (attr !== 'dark' && attr !== 'light') {
                    attr = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
                        ? 'dark' : 'light';
                }
                return opposite(attr);
            };
            var apply = function () { root.setAttribute('data-bs-theme', resolve()); };

            apply();

            // Follow the application's own theme toggle, including cross-tab changes and
            // an OS-level flip while the preference resolves through 'system'.
            if (window.MutationObserver) {
                new MutationObserver(apply).observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['data-bs-theme']
                });
            }
            if (window.matchMedia) {
                var mq = window.matchMedia('(prefers-color-scheme: dark)');
                if (mq.addEventListener) { mq.addEventListener('change', apply); }
                else if (mq.addListener) { mq.addListener(apply); }
            }
            window.addEventListener('storage', apply);
        })();
        JS;
    }

    /**
     * Classify a duration into a display tone.
     *
     * @param float $duration Duration in milliseconds.
     * @return string One of 'ok', 'warn', 'crit'.
     */
    private function tone(float $duration): string
    {
        if ($duration >= self::CRITICAL_REQUEST_MS) {
            return 'crit';
        }

        if ($duration >= self::SLOW_REQUEST_MS) {
            return 'warn';
        }

        return 'ok';
    }

    /**
     * Map an internal tone onto a Bootstrap contextual background utility.
     *
     * @param string $tone One of 'ok', 'warn', 'crit'.
     * @return string
     */
    private function toneBootstrap(string $tone): string
    {
        return match ($tone) {
            'crit' => 'danger',
            'warn' => 'warning',
            default => 'success',
        };
    }

    /**
     * Compact human-readable byte size.
     *
     * @param int $bytes Byte count.
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / 1024 / 1024, 2) . ' MB';
    }

    /**
     * Truncate a string to a maximum length, marking the cut.
     *
     * @param string $value Raw value.
     * @param int $limit Maximum length in characters.
     * @return string
     */
    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit) . '…';
    }

    /**
     * Render span metadata as a compact single-line string.
     *
     * Values are truncated: a single 800-character statement repeated across the
     * slowest-call listings would undo the panel caps by another route.
     *
     * @param array<string, mixed> $meta Span metadata.
     * @return string
     */
    private function describeMeta(array $meta): string
    {
        if ($meta === []) {
            return '-';
        }

        $pairs = [];
        foreach ($meta as $key => $value) {
            $pairs[] = $key . '=' . $this->truncate($this->stringify($value), self::MAX_SQL_LENGTH);
        }

        return implode(' ', $pairs);
    }

    /**
     * Render any value as a display string.
     *
     * @param mixed $value Value to render.
     * @return string
     */
    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? '[unserializable]' : $encoded;
    }

    /**
     * Escape a value for HTML text and attribute contexts.
     *
     * @param string $value Raw value.
     * @return string
     */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
