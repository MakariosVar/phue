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
     * Default gap between live-reload polls, in milliseconds.
     *
     * One second is chosen to sit below the point where an edit feels unacknowledged while
     * staying cheap: the endpoint answers with a short JSON body and returns, so a poll costs a
     * worker for the length of one directory scan and nothing more.
     */
    public const LIVE_RELOAD_INTERVAL_MS = 1000;

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

        $liveReloadMs = (int) ($options['live_reload_interval_ms'] ?? self::LIVE_RELOAD_INTERVAL_MS);

        return $this->renderShell($rootId, $this->buildView($profiler, $duration, $fallback), $sections, $profiler)
            . $this->renderStyles()
            . $this->renderScript($rootId, $themeMode, str_contains($sections[0]['content'] ?? '', self::FILTER_MARKER)
                || $this->anySectionContains($sections, self::FILTER_MARKER), $liveReloadMs);
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
        $context = $profiler->getContext();
        $sections = [];

        if ($show('timeline')) {
            $sections[] = [
                'key' => 'timeline',
                'title' => 'Timeline',
                'hint' => 'Per-operation rollup by self time, so the shares partition the request. Filter by name or label.',
                'content' => $this->renderTimeline($profiler->getAggregates(), $context),
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
        $closeId = $rootId . '-close';
        $fabId = $rootId . '-fab';
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

        $cold = $profiler->isColdRequest();

        $statusClass = match (true) {
            $status >= 200 && $status < 300 => 'text-bg-success',
            $status >= 300 && $status < 400 => 'text-bg-info',
            $status >= 400 && $status < 500 => 'text-bg-warning',
            $status >= 500 => 'text-bg-danger',
            default => 'text-bg-secondary',
        };

        $chips = '<span class="badge ' . $statusClass . '" title="HTTP response status">' . $this->esc($status > 0 ? (string) $status : '-') . '</span>'
            // Spelled out because a cold request is routinely several times slower for the
            // same page, and a report that does not say so reads as a regression.
            . '<span class="badge ' . ($cold ? 'text-bg-warning' : 'text-bg-secondary') . '"'
            . ' title="' . ($cold
                ? 'Cold request: initial run, caches are warming up'
                : 'Warm request: in-memory caches are ready') . '">' 
            . ($cold ? 'cold' : 'warm') . '</span>'
            . '<span class="badge text-bg-secondary" title="Total profiled events">' . $this->esc((string) $view['events']) . ' events</span>'
            . '<span class="badge text-bg-secondary" title="Memory usage at end of request">' . $this->esc((string) $view['memory']) . '</span>'
            . '<span class="badge text-bg-secondary" title="Peak memory usage during request">peak ' . $this->esc((string) $view['peak']) . '</span>'
            . '<span class="badge text-bg-secondary" title="Response body size">' . $this->esc($this->formatBytes((int) $view['bytes'])) . '</span>';

        return '<div id="' . $this->esc($rootId) . '" class="phue-dt-root bg-body-secondary text-body"'
            . ' data-bs-theme="' . $this->esc((string) $view['theme_fallback']) . '"'
            . ' data-tone="' . $this->esc($tone) . '" data-expanded="false" data-bar-visible="false">'
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
            . '<span class="badge phue-dt-duration text-bg-' . $this->esc($this->toneBootstrap($tone)) . '"'
            . ' title="Total request duration">'
            . $this->esc((string) $view['duration_formatted']) . ' ms</span>'
            . $chips
            . '<span class="ms-auto text-secondary text-truncate phue-dt-subtitle">' . $this->esc($subtitle) . '</span>'
            . '</button>'
            . '<button type="button" id="' . $this->esc($closeId) . '" class="phue-dt-close btn btn-sm"'
            . ' title="Collapse toolbar to icon" aria-label="Collapse toolbar to icon">'
            . '<span aria-hidden="true">&#10005;</span></button>'
            . '<button type="button" id="' . $this->esc($reloadId) . '" class="phue-dt-reload btn btn-sm ms-2"'
            . ' title="Re-run this request so the report matches the current page"'
            . ' aria-label="Reload page and re-profile">'
            . '<span aria-hidden="true">&#8635;</span></button>'
            . '<button type="button" id="' . $this->esc($fabId) . '" class="phue-dt-fab"'
            . ' title="Toggle DevTool bar" aria-label="Toggle DevTool bar"'
            . ' aria-expanded="false">'
            . '<img src="/Phue.svg" alt="" width="24" height="24">'
            . '</button>'
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
     * @param array<string, mixed> $context Request context recorded by the middleware.
     * @return string
     */
    private function renderTimeline(array $aggregates, array $context = []): string
    {
        if ($aggregates === []) {
            return '<p class="phue-dt-note text-secondary mb-0">No spans were recorded for this request.</p>';
        }

        // Share is computed from EXCLUSIVE time, and that is the only figure that can be
        // turned into a percentage at all. Spans nest, so a parent's total contains every
        // descendant: dividing totals by the request duration double-counts, saturates the
        // bar at 100% for anything wrapping other work, and makes the column unreadable.
        // Exclusive time partitions the request — every nanosecond belongs to exactly one
        // frame — so these shares sum to 100% across the rows.
        $exclusiveTotal = 0.0;
        foreach ($aggregates as $aggregate) {
            $exclusiveTotal += (float) ($aggregate['exclusive_ms'] ?? 0.0);
        }

        $rows = '';
        $shown = 0;

        foreach ($aggregates as $label => $aggregate) {
            if ($shown >= self::MAX_AGGREGATE_ROWS) {
                break;
            }
            $shown++;

            $exclusive = (float) ($aggregate['exclusive_ms'] ?? 0.0);
            $ratio = $exclusiveTotal > 0.0 ? $exclusive / $exclusiveTotal * 100.0 : 0.0;

            $operationCell = '<span class="font-monospace">' . $this->esc((string) $label) . '</span>'
                . $this->renderAggregateMeta(is_array($aggregate['meta'] ?? null) ? $aggregate['meta'] : [])
                . $this->renderAggregateCallers(is_array($aggregate['callers'] ?? null) ? $aggregate['callers'] : []);

            $rows .= '<tr>'
                . '<td>' . $operationCell . '</td>'
                . '<td class="phue-dt-num">' . $this->esc((string) $aggregate['count']) . '</td>'
                . '<td class="phue-dt-num fw-bold">' . $this->esc(number_format($exclusive, 2)) . '</td>'
                . '<td class="phue-dt-num">' . $this->esc(number_format((float) $aggregate['total_ms'], 2)) . '</td>'
                . '<td class="phue-dt-num">' . $this->esc(number_format((float) $aggregate['avg_ms'], 3)) . '</td>'
                . '<td class="phue-dt-num">' . $this->esc(number_format((float) $aggregate['max_ms'], 2)) . '</td>'
                . '<td class="phue-dt-sharecell">'
                . '<span class="phue-dt-bar bg-info" style="width:'
                . $this->esc(number_format($ratio, 2)) . '%"></span>'
                . '<span class="phue-dt-share">' . $this->esc(number_format($ratio, 1)) . '%</span></td>'
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

        return $this->renderRequestLine($context)
            . $filter
            . $this->wrapTable(
                '<thead><tr>'
                . '<th scope="col">Operation</th>'
                . '<th scope="col" class="phue-dt-num">Calls</th>'
                . '<th scope="col" class="phue-dt-num">Self ms</th>'
                . '<th scope="col" class="phue-dt-num">Total ms</th>'
                . '<th scope="col" class="phue-dt-num">Avg ms</th>'
                . '<th scope="col" class="phue-dt-num">Max ms</th>'
                . '<th scope="col">Share of self</th>'
                . '</tr></thead>',
                $rows
            )
            . $footnote;
    }

    /**
     * Render a rollup row's rolled-up metadata as a compact line under the operation.
     *
     * This line is what makes "Filter by name" true. The row's text content is what the
     * filter matches against, and without it a label covering 1227 calls across 140
     * components showed nothing but numbers — typing a component name emptied the table
     * instead of narrowing it. Reporting the real distribution is also the only honest
     * option: naming a single component as if it described the whole row is how the
     * previous last-wins metadata sent the profiling exercise after a phantom.
     *
     * Every value passes through the same truncation the span table applies. Raw span
     * metadata is kept verbatim on purpose so the export panel can be pasted into a bug
     * report, and a summary line is not an exemption: without this, a single long SQL
     * statement reached a rendered table at full length and undid the panel caps.
     *
     * @param array<string, mixed> $meta Rolled-up metadata from Stopwatch::getAggregates().
     * @return string HTML, or an empty string when there is nothing worth showing.
     */
    private function renderAggregateMeta(array $meta): string
    {
        $fragments = [];

        foreach ($meta as $key => $rollup) {
            if (!is_array($rollup)) {
                continue;
            }

            if (($rollup['kind'] ?? null) === 'metric') {
                $fragments[] = $this->esc(sprintf(
                    '%s %s…%s',
                    (string) $key,
                    (string) $rollup['min'],
                    (string) $rollup['max']
                ));

                continue;
            }

            $top = is_array($rollup['top'] ?? null) ? $rollup['top'] : [];
            $parts = [];

            foreach ($top as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $parts[] = $this->esc(sprintf(
                    '%s×%s',
                    $this->truncate($this->stringifyMetaValue($entry['value'] ?? null), self::MAX_SQL_LENGTH),
                    (string) ($entry['count'] ?? 0)
                ));
            }

            if ($parts === []) {
                continue;
            }

            $distinct = (int) ($rollup['distinct'] ?? count($top));

            if ($distinct > count($parts)) {
                $parts[] = $this->esc(sprintf('+%d more', $distinct - count($parts)));
            }

            $fragments[] = '<span class="text-body-secondary">' . implode(' · ', $parts) . '</span>';
        }

        if ($fragments === []) {
            return '';
        }

        return '<div class="phue-dt-meta small text-body-secondary">' . implode(' ', $fragments) . '</div>';
    }

    /**
     * Render the request target the rollup was measured on.
     *
     * The collapsed bar prints the method, the path and a route chip, but the bar is the
     * one part of the report that never travels: panels get pasted into issues and lifted
     * out of the page. A timeline that does not name its request cannot be compared with
     * the next one, and on a page that renders a different subtree per query string
     * (/phui?section=button against /phui?section=otp-input) there is nothing else in the
     * table that distinguishes the two.
     *
     * The target comes from the middleware's `route`, which is the path with the query
     * string rebuilt from the parsed parameters. That is a normalised target, not the
     * bytes the client sent: parameter order and encoding follow the parsed array.
     *
     * @param array<string, mixed> $context Request context recorded by the middleware.
     * @return string HTML, or an empty string when no request was recorded.
     */
    private function renderRequestLine(array $context): string
    {
        $method = strtoupper(trim((string) ($context['method'] ?? '')));
        $target = trim((string) ($context['route'] ?? ''));

        if ($target === '') {
            $target = trim((string) ($context['uri'] ?? ''));
        }

        if ($method === '' && $target === '') {
            return '';
        }

        $method = $method !== '' ? $method . ' ' : '';
        $target = $target !== '' ? $this->esc($this->truncate($target, self::MAX_SQL_LENGTH)) : '';

        if ($target === '') {
            return '';
        }

        return '<p class="phue-dt-requestline font-monospace small text-body-secondary mb-2">'
            . $this->esc($method) . $target
            . '</p>';
    }

    /**
     * Render which callers reached an operation, as a line under the operation name.
     *
     * A call count says an operation was entered often. It cannot say by which mechanism,
     * and on a component page several mechanisms produce the same count — so a hot row
     * without this line is a question, not a diagnosis.
     *
     * @param array<string, int> $callers Immediate caller label => number of entries.
     * @return string HTML, or an empty string for an operation nothing called.
     */
    private function renderAggregateCallers(array $callers): string
    {
        if ($callers === []) {
            return '';
        }

        $parts = [];

        foreach ($callers as $caller => $count) {
            $parts[] = $this->esc(sprintf('%s×%d', $this->truncate((string) $caller, self::MAX_SQL_LENGTH), $count));
        }

        return '<div class="phue-dt-meta small text-body-secondary"><span class="phue-dt-callerlabel">via</span> '
            . implode(' · ', $parts)
            . '</div>';
    }

    /**
     * Render one metadata value as the short, readable token the report displays.
     *
     * @param mixed $value Value taken from a span's metadata.
     * @return string
     */
    private function stringifyMetaValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '?',
        };
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
            $parent = $span['parent'] ?? null;

            // The per-call panel is where a specific slow call gets identified. A call
            // without its caller is a number with nowhere to go.
            $labelCell = '<span class="font-monospace">' . $this->esc((string) $span['label']) . '</span>';

            if (is_string($parent) && $parent !== '') {
                $labelCell .= '<div class="phue-dt-meta small text-body-secondary">'
                    . '<span class="phue-dt-callerlabel">via</span> '
                    . $this->esc($this->truncate($parent, self::MAX_SQL_LENGTH))
                    . '</div>';
            }

            $rows .= '<tr>'
                . '<td>' . $labelCell . '</td>'
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
        /* The duration sits at the same size as its sibling chips. Its background tone
           already carries how slow the request was, and a 16px number beside 10px badges
           reads as the only figure that matters — which is the opposite of what the panel
           then spends its whole width arguing. */
        .phue-dt-duration { font-weight: 400; }
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
        .phue-dt-sharecell { width: 20%; white-space: nowrap; }
        .phue-dt-sharecell .phue-dt-bar { display: inline-block; vertical-align: middle; width: auto; }
        .phue-dt-share { display: inline-block; margin-left: 6px; font-size: 11px; color: var(--bs-secondary-color); font-variant-numeric: tabular-nums; vertical-align: middle; }
        .phue-dt-meta { font-size: 11px; line-height: 1.35; word-break: break-word; }
        .phue-dt-callerlabel { text-transform: uppercase; letter-spacing: .04em; opacity: .7; }
        .phue-dt-filter { max-width: 26rem; }
        .phue-dt-json {
            max-height: 46vh; overflow: auto; font-size: 12px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .phue-dt-fab {
            position: fixed; bottom: 16px; right: 16px;
            width: 44px; height: 44px; border-radius: 50%;
            border: 1px solid var(--bs-border-color);
            background: var(--bs-body-bg); color: var(--bs-body-color);
            cursor: pointer; padding: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
            display: flex; align-items: center; justify-content: center;
            transition: box-shadow .15s ease, transform .15s ease;
            pointer-events: auto;
        }
        .phue-dt-fab:hover { box-shadow: 0 4px 16px rgba(0,0,0,.2); transform: scale(1.08); }
        .phue-dt-fab:focus-visible { outline: 2px solid var(--bs-primary); outline-offset: 2px; }
        .phue-dt-fab img { width: 24px; height: 24px; display: block; }
        .phue-dt-close {
            position: absolute; right: 44px; bottom: 100%; margin: 0 0 6px 0;
            border: 1px solid var(--bs-border-color); background: var(--bs-body-bg);
            color: var(--bs-body-color); line-height: 1; padding: 4px 9px; font-size: 14px;
        }
        .phue-dt-close:hover { background: var(--bs-secondary-bg); }
        .phue-dt-close:focus-visible { outline: 2px solid var(--bs-primary); outline-offset: 1px; }
        .phue-dt-root:not([data-bar-visible="true"]) { pointer-events: none; }
        .phue-dt-root:not([data-bar-visible="true"]) .phue-dt-toggle,
        .phue-dt-root:not([data-bar-visible="true"]) .phue-dt-close,
        .phue-dt-root:not([data-bar-visible="true"]) .phue-dt-reload { display: none !important; }
        .phue-dt-root[data-bar-visible="true"] .phue-dt-fab { display: none; }
        @media (prefers-reduced-motion: reduce) { .phue-dt-caret { transition: none; } .phue-dt-fab { transition: none; } }
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
     * @param int $liveReloadIntervalMs Gap between live-reload polls.
     * @return string
     */
    private function renderScript(
        string $rootId,
        string $themeMode = 'auto',
        bool $withFilter = true,
        int $liveReloadIntervalMs = self::LIVE_RELOAD_INTERVAL_MS
    ): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $root = json_encode($rootId, $flags);

        // The theme resolver is omitted for a pinned theme rather than emitted behind a
        // dead branch: dead code in an injected <script> is still bytes on every page.
        $themeScript = $themeMode === 'auto' ? $this->renderThemeScript($root) : '';
        $filterScript = $withFilter ? $this->renderFilterScript($root) : '';
        $liveReloadScript = $this->renderLiveReloadScript($root, $liveReloadIntervalMs);

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

            var fab = root.querySelector('.phue-dt-fab');
            var closeBtn = root.querySelector('.phue-dt-close');

            var collapseToFab = function () {
                root.setAttribute('data-bar-visible', 'false');
                if (fab) { fab.setAttribute('aria-expanded', 'false'); }
                if (toggle && panel) {
                    toggle.setAttribute('aria-expanded', 'false');
                    panel.hidden = true;
                    root.setAttribute('data-expanded', 'false');
                }
            };

            if (fab) {
                fab.addEventListener('click', function () {
                    var visible = root.getAttribute('data-bar-visible') === 'true';
                    if (visible) {
                        collapseToFab();
                    } else {
                        root.setAttribute('data-bar-visible', 'true');
                        fab.setAttribute('aria-expanded', 'true');
                    }
                });
            }

            if (closeBtn) {
                closeBtn.addEventListener('click', function () { collapseToFab(); });
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

        return '<script>' . $js . $filterScript . $themeScript . $liveReloadScript . '</script>';
    }

    /**
     * Render the live-reload poller.
     *
     * Polling, not a stream, and the reason is worth writing down because the obvious
     * alternative was built and thrown away: a Server-Sent Events stream is a request that never
     * finishes, so it holds a worker for as long as the tab is open. Four tabs took the dev
     * server down, and Swoole cannot report a closed tab from inside a running coroutine — not
     * through write(), isWritable(), exists() or connection_aborted() — so nothing ever told the
     * stream to let go. A poll costs a worker for one directory scan and returns.
     *
     * Three details that keep it from being noisy:
     *
     *  - it stops on its own after repeated failures, so a page whose endpoint is missing does
     *    not retry forever in the background;
     *  - it pauses while the tab is hidden, because a background tab has nothing to reload;
     *  - it guards on a window-level flag, so a page with more than one toolbar polls once.
     *
     * @param string $rootJson JSON-encoded root element id.
     * @param int $intervalMs Gap between polls.
     * @return string
     */
    private function renderLiveReloadScript(string $rootJson, int $intervalMs): string
    {
        // UNESCAPED_SLASHES on purpose: the path is the one thing a reader of the injected
        // script needs to recognise, and "\/_phue\/live-reload" is not it.
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $endpoint = json_encode(LiveReloadRoute::ENDPOINT, $flags);
        $interval = max(250, $intervalMs);

        return <<<JS

        (function () {
            var root = document.getElementById({$rootJson});
            if (!root) { return; }

            // Scoped to the window rather than to this toolbar: the toolbar is injected once per
            // response, and two pollers would double every request for one answer.
            if (window.__phueLiveReload) { return; }
            window.__phueLiveReload = true;

            var endpoint = {$endpoint};
            var interval = {$interval};
            var failures = 0;

            var poll = function () {
                // A hidden tab has nothing to reload, and this is the common case: a developer
                // leaves the page open in another window and comes back to it.
                if (document.visibilityState === 'hidden') {
                    return;
                }

                fetch(endpoint, {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store',
                    credentials: 'same-origin'
                }).then(function (response) {
                    // A missing endpoint is a configuration state, not a blip. Retrying it
                    // forever turns a 404 into a background request every second.
                    if (!response.ok) { throw new Error('status'); }
                    return response.json();
                }).then(function (payload) {
                    failures = 0;
                    var changed = (payload && payload.changed) || [];
                    if (changed.length) { window.location.reload(); }
                }).catch(function () {
                    failures++;
                    if (failures >= 5) { window.__phueLiveReload = false; }
                });
            };

            var schedule = function () {
                window.setTimeout(function () { poll(); schedule(); }, interval);
            };

            schedule();
        })();
        JS;
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
