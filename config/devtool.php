<?php

declare(strict_types=1);

/**
 * DevTool Configuration
 *
 * Panel toggles for the debug toolbar, plus the live-reload interval.
 *
 * The panel toggles are read by Phue\Modules\DevTool\DevToolsModule and only consulted when
 * both app.debug and app.devTool are enabled. `live_reload_interval_ms` is read earlier, because
 * it decides whether the change watcher gets installed.
 *
 * Panels are opt-out rather than opt-in: a new panel should be visible by default, since
 * its whole purpose is surfacing something that was previously invisible.
 */
return [
    /**
     * How often the server looks for changed `.phue` files, in milliseconds.
     *
     * Read before the debug gate, unlike the panel toggles below, because the value has to be
     * readable to decide whether to install the watcher at all. Zero switches live reload off,
     * which is what an application that moved change detection into its own build step wants:
     * nothing polls, nothing ticks, and the toolbar simply never reloads.
     *
     * This is the *server's* scan. The toolbar's own polling interval is a separate setting and
     * defaults to zero — meaning "poll only while waiting for a realtime socket to connect".
     */
    'live_reload_interval_ms' => 1000,

    /**
     * Per-operation rollup table (calls, total, average, max, share of request).
     */
    'timeline' => true,

    /**
     * Slowest individual calls, ranked by total and by exclusive time.
     */
    'spans' => true,

    /**
     * Database panel: every statement executed during the request, with its SQL,
     * binding count, returned row count and duration.
     *
     * Populated only when a profiling database driver is installed, which requires a
     * configured database connection (see app config `database.default`).
     */
    'queries' => true,

    /**
     * Request/response metadata recorded by the DevTool middleware.
     */
    'request' => true,

    /**
     * Raw profiling report as copy/pasteable JSON.
     */
    'json' => true,

    /**
     * Toolbar colour scheme.
     *
     * 'auto'   follow the browser's/OS theme, but inverted: the toolbar renders in the
     *          opposite theme from the application, which keeps it readable as an
     *          instrument rather than blending into the page it is measuring.
     * 'light'  pin the toolbar to the light palette regardless of the application.
     * 'dark'   pin the toolbar to the dark palette regardless of the application.
     *
     * The resolved value is applied by setting `data-bs-theme` on the toolbar root.
     * Bootstrap inherits that attribute, so every table, badge and alert inside the
     * toolbar re-themes itself without any extra CSS.
     */
    'theme' => 'auto',

    /**
     * How database statements are recorded.
     *
     * 'redact' (default)
     *     Quoted literals and comments are replaced before the statement reaches the
     *     report. The framework prepares and executes separately, so a prepared statement
     *     carries only placeholders — anything quoted arrived through interpolation, which
     *     is the one path that can put a real row of user data into a rendered page. The
     *     structure of the query is preserved, so it stays diagnosable.
     * 'raw'
     *     Record the statement verbatim. Use only where the page is never reachable by
     *     anyone who should not see the schema or the data.
     */
    'sql_literals' => 'redact',

    /**
     * Theme rendered before the browser-side resolver runs.
     *
     * The server cannot know the resolved client theme when the application preference is
     * 'system', so this is only a first-paint fallback; the inline script corrects it
     * immediately after the toolbar markup is parsed.
     */
    'theme_fallback' => 'dark',

    /**
     * Application theme preference key, mirroring app.themeToggle.storageKey.
     *
     * Read only to detect a preference that resolves through 'system'; the resolved
     * `data-bs-theme` attribute on <html> remains the source of truth.
     */
    'theme_storage_key' => 'phue-theme',
];
