<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool\Panels;

use Phue\Modules\DevTool\DebugToolbar;

/**
 * Dumps panel for the Debug Toolbar.
 *
 * Displays all dumps collected by AsyncDumper during the request.
 *
 * @package Phue\Modules\DevTool\Panels
 */
final class DumpsPanel
{
    /**
     * How deep the panel keeps indenting before it gives up on a value.
     */
    private const MAX_DEPTH = 10;
    /**
     * Renders the dumps panel.
     *
     * @param array<string, mixed> $data Panel data from toolbar
     * @return string HTML panel content
     */
    public static function render(array $data): string
    {
        $dumps = $data['dumps'] ?? [];

        if ($dumps === []) {
            return '<div class="text-muted p-3">No dumps collected</div>';
        }

        $html = '<div class="dumps-panel">';

        foreach ($dumps as $i => $dump) {
            $time = date('H:i:s.u', (int) $dump['time']);
            $label = $dump['label'] ? "<span class=\"badge bg-primary\">{$dump['label']}</span>" : '';
            $group = $dump['group'] !== 'default' ? "<span class=\"badge bg-secondary\">{$dump['group']}</span>" : '';
            $requestId = $dump['request_id'] ?? '';

            // Pre-render the value with syntax highlighting (server-side)
            $valueHtml = self::highlightValue($dump['value']);

            // Pre-render the trace too, so it is present without JavaScript.
            $traceHtml = self::renderTrace($dump['trace'] ?? []);

            $html .= <<<HTML
            <div class="dump-item card mb-2">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="fw-bold">Dump #{$i}</span>
                        {$label}
                        {$group}
                    </div>
                    <div class="d-flex align-items-center gap-3 small text-muted">
                        <span><strong>Time:</strong> {$time}</span>
                        <span><strong>Request ID:</strong> <code>{$requestId}</code></span>
                        <span><strong>Group:</strong> <code>{$dump['group']}</code></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="dump-meta mb-3">
                        <strong>Value:</strong>
                    </div>
                    <div class="dump-value mb-3" data-dump-index="{$i}">
                        <pre class="mb-0"><code>{$valueHtml}</code></pre>
                    </div>
                    <div class="d-flex gap-2 mb-2">
                        <button class="btn btn-sm btn-outline-secondary toggle-trace" data-index="{$i}">
                            Toggle Stack Trace
                        </button>
                    </div>
                    <pre class="dump-trace mt-2 d-none"><code>{$traceHtml}</code></pre>
                </div>
            </div>
            HTML;
        }

        $html .= '</div>';

        // Add JavaScript for interactive dump display
        $html .= self::renderScript();

        return $html;
    }

    /**
     * Highlights a value with syntax coloring (server-side).
     *
     * @param mixed $value Value to render
     * @param int $depth Nesting level of the value
     * @return string Highlighted block, one line per nesting level
     */
    private static function highlightValue(mixed $value, int $depth = 0): string
    {
        return implode("\n", self::valueLines($value, $depth));
    }

    /**
     * Renders a value as lines, indented by how deep it sits.
     *
     * The value is assembled line by line rather than per node because a child
     * has to know how far right its parent already is: returning a finished
     * string per node loses that, and every nested level then lands in the same
     * column, which is what made the panel unreadable.
     *
     * @param mixed $value Value to render
     * @param int $depth Nesting level, which becomes this value's indentation
     * @return array<int, string> Rendered lines, already indented
     */
    private static function valueLines(mixed $value, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [self::indent($depth) . '<span class="text-muted">[Max depth reached]</span>'];
        }

        if (is_null($value)) {
            return [self::indent($depth) . '<span class="text-muted">null</span>'];
        }

        if (is_bool($value)) {
            return [self::indent($depth) . '<span class="text-info">' . ($value ? 'true' : 'false') . '</span>'];
        }

        if (is_int($value) || is_float($value)) {
            return [self::indent($depth) . '<span class="text-warning">' . $value . '</span>'];
        }

        if (is_string($value)) {
            return [self::indent($depth) . '<span class="text-success">"' . htmlspecialchars($value) . '"</span>'];
        }

        if (is_array($value)) {
            if ($value === []) {
                return [self::indent($depth) . '<span class="text-muted">[]</span>'];
            }

            $lines = [self::indent($depth) . '['];
            $isList = array_is_list($value);
            $last = array_key_last($value);

            foreach ($value as $key => $item) {
                $child = self::valueLines($item, $depth + 1);
                $first = array_shift($child);

                if (!$isList) {
                    $first = self::indent($depth + 1)
                        . '<span class="text-primary">' . htmlspecialchars((string) $key) . '</span> => '
                        . ltrim($first);
                }

                $lines[] = $first;
                foreach ($child as $line) {
                    $lines[] = $line;
                }

                if ($key !== $last) {
                    $lines[count($lines) - 1] .= ',';
                }
            }

            $lines[] = self::indent($depth) . ']';

            return $lines;
        }

        if (is_object($value)) {
            if (method_exists($value, '__debugInfo')) {
                return self::valueLines($value->__debugInfo(), $depth);
            }

            $reflection = new \ReflectionObject($value);
            $properties = $reflection->getProperties(
                \ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED
            );

            if ($properties === []) {
                return [self::indent($depth) . '<span class="text-info">' . get_class($value) . '</span> {}'];
            }

            $lines = [self::indent($depth) . '<span class="text-info">' . get_class($value) . '</span> {'];

            foreach ($properties as $prop) {
                // No setAccessible(): it has been a no-op since PHP 8.1 and the
                // project requires 8.5, where calling it is deprecated.
                $child = self::valueLines($prop->getValue($value), $depth + 1);

                $lines[] = self::indent($depth + 1)
                    . '<span class="text-primary">' . htmlspecialchars($prop->getName()) . '</span> => '
                    . ltrim($child[0]);

                foreach (array_slice($child, 1) as $line) {
                    $lines[] = $line;
                }

                if ($prop !== $properties[array_key_last($properties)]) {
                    $lines[count($lines) - 1] .= ',';
                }
            }

            $lines[] = self::indent($depth) . '}';

            return $lines;
        }

        return [self::indent($depth) . htmlspecialchars((string) $value)];
    }

    /**
     * The indentation for a nesting level.
     *
     * @param int $depth Nesting level
     * @return string Leading whitespace
     */
    private static function indent(int $depth): string
    {
        return str_repeat('  ', max(0, $depth));
    }

    /**
     * Renders a stack trace as plain text lines.
     *
     * The trace is built here rather than in the browser so it stays readable
     * with JavaScript disabled, and so each frame keeps the `::` between class
     * and function — the browser-side version ran the two together and rendered
     * `AsyncDumperdump`.
     *
     * @param array<int, array{file: string, line: int, function: string, class: string}> $trace Recorded frames
     * @return string Escaped trace block
     */
    private static function renderTrace(array $trace): string
    {
        if ($trace === []) {
            return '<span class="text-muted">(no stack trace recorded)</span>';
        }

        $lines = [];

        foreach ($trace as $frame) {
            $qualifier = ($frame['class'] ?? '') !== '' ? $frame['class'] . '::' : '';

            $lines[] = sprintf(
                '%s:%d %s%s()',
                (string) ($frame['file'] ?? ''),
                (int) ($frame['line'] ?? 0),
                $qualifier,
                (string) ($frame['function'] ?? '')
            );
        }

        return htmlspecialchars(implode("\n", $lines));
    }

    /**
     * Renders the JavaScript for interactive dump display.
     *
     * The trace itself is already in the markup; this only toggles it.
     *
     * @return string Script block
     */
    private static function renderScript(): string
    {
        return <<<JS
<script>
(function() {
    // Initialize when panel is shown (tab activated)
    function initDumpsPanel(panel) {
        // Toggle trace buttons
        panel.querySelectorAll('.toggle-trace').forEach(btn => {
            if (btn.dataset._initialized) return;
            btn.dataset._initialized = 'true';
            btn.addEventListener('click', () => {
                const item = btn.closest('.dump-item');
                const trace = item.querySelector('.dump-trace');
                trace.classList.toggle('d-none');
                btn.textContent = trace.classList.contains('d-none') ? 'Show Stack Trace' : 'Hide Stack Trace';
            });
        });
    }

    // Initialize immediately if panel is already visible
    const panel = document.querySelector('.dumps-panel');
    if (panel && !panel.classList.contains('d-none')) {
        initDumpsPanel(panel);
    }

    // Also hook into Dev Tools tab switching
    document.querySelectorAll('[data-phue-dt-tab]').forEach(tab => {
        tab.addEventListener('click', () => {
            const targetId = tab.getAttribute('aria-controls');
            const panel = document.getElementById(targetId);
            if (panel && panel.querySelector('.dumps-panel')) {
                // Small delay to ensure tab content is rendered
                setTimeout(() => initDumpsPanel(panel), 0);
            }
        });
    });
})();
</script>
JS;
    }
}