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
                    <pre class="dump-trace mt-2 d-none"><code></code></pre>
                </div>
            </div>
            HTML;
        }

        $html .= '</div>';

        // Add JavaScript for interactive dump display
        $html .= self::renderScript($dumps);

        return $html;
    }

    /**
     * Highlights a value with syntax coloring (server-side).
     */
    private static function highlightValue(mixed $value, int $depth = 0): string
    {
        if ($depth > 10) {
            return '<span class="text-muted">[Max depth reached]</span>';
        }

        if (is_null($value)) {
            return '<span class="text-muted">null</span>';
        }

        if (is_bool($value)) {
            return '<span class="text-info">' . ($value ? 'true' : 'false') . '</span>';
        }

        if (is_int($value) || is_float($value)) {
            return '<span class="text-warning">' . $value . '</span>';
        }

        if (is_string($value)) {
            return '<span class="text-success">"' . htmlspecialchars($value) . '"</span>';
        }

        if (is_array($value)) {
            if ($value === []) {
                return '<span class="text-muted">[]</span>';
            }

            $items = [];
            foreach ($value as $k => $v) {
                $key = '<span class="text-primary">' . htmlspecialchars((string)$k) . '</span>';
                $items[] = '  ' . $key . ' => ' . self::highlightValue($v, $depth + 1);
            }
            return '[\n' . implode(",\n", $items) . "\n" . str_repeat('  ', $depth) . ']';
        }

        if (is_object($value)) {
            if (method_exists($value, '__debugInfo')) {
                return self::highlightValue($value->__debugInfo(), $depth + 1);
            }

            $reflection = new \ReflectionObject($value);
            $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED);

            $items = [];
            foreach ($properties as $prop) {
                $prop->setAccessible(true);
                $key = '<span class="text-primary">' . htmlspecialchars($prop->getName()) . '</span>';
                $val = $prop->getValue($value);
                $items[] = '  ' . $key . ' => ' . self::highlightValue($val, $depth + 1);
            }

            $className = '<span class="text-info">' . get_class($value) . '</span>';
            if ($items === []) {
                return "{$className} {}";
            }
            return $className . ' {\n' . implode(",\n", $items) . "\n" . str_repeat('  ', $depth) . '}';
        }

        return htmlspecialchars((string)$value);
    }

    /**
     * Renders the JavaScript for interactive dump display.
     */
    private static function renderScript(array $dumps): string
    {
        $dumpsJson = json_encode($dumps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
<script>
(function() {
    const dumps = {$dumpsJson};

    // Initialize when panel is shown (tab activated)
    function initDumpsPanel(panel) {
        // Render stack trace for each dump
        const traceContainers = panel.querySelectorAll('.dump-trace code');
        traceContainers.forEach((traceContainer, i) => {
            const dump = dumps[i];
            if (traceContainer && dump && dump.trace) {
                traceContainer.textContent = dump.trace.map(f =>
                    f.file + ':' + f.line + ' ' + (f.class || '') + (f.function || '')
                ).join('\\n');
            }
        });

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