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
            $time = date('H:i:s.u', $dump['time']);
            $label = $dump['label'] ? "<span class=\"badge bg-primary\">{$dump['label']}</span>" : '';
            $group = $dump['group'] !== 'default' ? "<span class=\"badge bg-secondary\">{$dump['group']}</span>" : '';
            $requestId = $dump['request_id'] ?? '';

            $html .= <<<HTML
            <div class="dump-item card mb-2">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Dump #{$i}{$label}{$group}</span>
                    <small class="text-muted">{$time}</small>
                </div>
                <div class="card-body">
                    <div class="dump-value" data-dump-index="{$i}"></div>
                    <button class="btn btn-sm btn-outline-secondary mt-2 toggle-trace" data-index="{$i}">
                        Toggle Stack Trace
                    </button>
                    <pre class="dump-trace mt-2 d-none"><code></code></pre>
                </div>
            </div>
            HTML;
        }

        $html .= '</div>';

        // Add JavaScript to render values with syntax highlighting
        $html .= self::renderScript($dumps);

        return $html;
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

    // Simple syntax highlighter for JSON-like output
    function highlight(value) {
        if (value === null) return '<span class="text-muted">null</span>';
        if (typeof value === 'boolean') return '<span class="text-info">' + value + '</span>';
        if (typeof value === 'number') return '<span class="text-warning">' + value + '</span>';
        if (typeof value === 'string') return '<span class="text-success">"' + escapeHtml(value) + '"</span>';
        if (Array.isArray(value)) {
            if (value.length === 0) return '[]';
            let html = '[\n';
            value.forEach((v, i) => {
                html += '  ' + highlight(v) + (i < value.length - 1 ? ',' : '') + '\n';
            });
            return html + ']';
        }
        if (typeof value === 'object') {
            const entries = Object.entries(value);
            if (entries.length === 0) return '{}';
            let html = '{\n';
            entries.forEach(([k, v], i) => {
                html += '  ' + escapeHtml(k) + ': ' + highlight(v) + (i < entries.length - 1 ? ',' : '') + '\n';
            });
            return html + '}';
        }
        return escapeHtml(String(value));
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Render dump values
    dumps.forEach((dump, i) => {
        const container = document.querySelector('.dump-value[data-dump-index="' + i + '"]');
        if (container) {
            container.innerHTML = '<pre class="mb-0"><code>' + highlight(dump.value) + '</code></pre>';
        }

        // Render stack trace
        const traceContainer = document.querySelector('.dump-trace code');
        if (traceContainer && dump.trace) {
            traceContainer.textContent = dump.trace.map(f =>
                f.file + ':' + f.line + ' ' + (f.class || '') + (f.function || '')
            ).join('\n');
        }
    });

    // Toggle trace buttons
    document.querySelectorAll('.toggle-trace').forEach(btn => {
        btn.addEventListener('click', () => {
            const trace = btn.nextElementSibling;
            trace.classList.toggle('d-none');
            btn.textContent = trace.classList.contains('d-none') ? 'Show Stack Trace' : 'Hide Stack Trace';
        });
    });
})();
</script>
JS;
    }
}