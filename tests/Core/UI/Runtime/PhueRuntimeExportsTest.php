<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI\Runtime;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The `phue` import-map entry is the single module every built `.phue` imports.
 *
 * The loader injects `import { createPhueApp, installI18n, t } from 'phue'`,
 * so a single missing export breaks EVERY component in the framework with a
 * `SyntaxError` at module-evaluation time — in the browser, after the page has
 * already been served. These tests read the actual file so the failure lands
 * in the suite instead.
 */
#[Group('unit')]
final class PhueRuntimeExportsTest extends TestCase
{
    private const RUNTIME_DIR = __DIR__ . '/../../../../src/Core/UI/runtime';

    /**
     * @return array<string, string> exported symbol => source file basename
     */
    private function exportsOf(string $file): array
    {
        $path = self::RUNTIME_DIR . '/' . $file;
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);
        $found = [];

        // `export function NAME(`
        if (preg_match_all('/^\s*export\s+function\s+([A-Za-z_$][\w$]*)/m', $source, $m) === 1
            || !empty($m[1])) {
            foreach ($m[1] as $name) {
                $found[$name] = $file;
            }
        }

        // `export { a as b, c };`
        if (preg_match('/^\s*export\s*\{([^}]*)\}\s*;/m', $source, $block) === 1) {
            foreach (explode(',', $block[1]) as $entry) {
                $entry = trim($entry);
                if ($entry === '') {
                    continue;
                }
                // `local as exported` — the PUBLIC name is what callers use.
                $parts = preg_split('/\s+as\s+/i', $entry);
                $public = trim((string) end($parts));
                if (preg_match('/^[A-Za-z_$][\w$]*$/', $public) === 1) {
                    $found[$public] = $file;
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, string> every symbol the runtime exposes
     */
    private function runtimeExports(): array
    {
        return array_merge(
            $this->exportsOf('createPhueApp.js'),
            $this->exportsOf('i18n.js'),
        );
    }

    public function testTheModuleNamedByTheImportMapEntryExists(): void
    {
        $this->assertFileExists(self::RUNTIME_DIR . '/createPhueApp.js');
    }

    /**
     * The regression that motivated this file: the loader emitted
     * `installI18n` in the injected import while the runtime only re-exported
     * `createPhueApp` and `t`.
     */
    public function testEverySymbolTheLoaderInjectsIsActuallyExported(): void
    {
        $loader = (string) file_get_contents(
            __DIR__ . '/../../../../src/Core/UI/PhueComponentLoader.php',
        );

        $this->assertSame(
            1,
            preg_match("/runtimeImport\(\): string\s*\{(.*?)\n    \}/s", $loader, $m),
            'could not locate runtimeImport() in the component loader',
        );

        $this->assertSame(
            1,
            preg_match('/import\s*\{([^}]*)\}\s*from\s*.phue./', $m[1], $specifiers),
            "runtimeImport() must import from the 'phue' import-map specifier",
        );

        $imported = [];
        foreach (explode(',', $specifiers[1]) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            // `exported as local` — the LEFT side is what the module must
            // provide; the right side is only the local binding name.
            $parts = preg_split('/\s+as\s+/i', $entry);
            $imported[] = trim((string) reset($parts));
        }

        $this->assertNotEmpty($imported, 'runtimeImport() injected no symbols');

        $available = $this->runtimeExports();

        foreach ($imported as $symbol) {
            $this->assertArrayHasKey(
                $symbol,
                $available,
                sprintf(
                    "The loader injects `import { %s } from 'phue'` but no runtime module exports it — "
                    . 'every .phue component would fail to evaluate in the browser.',
                    $symbol,
                ),
            );
        }
    }

    public function testCreatePhueAppAndTranslateHelpersAreReachableFromTheEntryModule(): void
    {
        $entry = $this->exportsOf('createPhueApp.js');

        // The specifier resolves to createPhueApp.js, so these three MUST be
        // reachable through it or through the import-map subpath.
        foreach (['createPhueApp', 't', 'installI18n'] as $symbol) {
            $this->assertArrayHasKey(
                $symbol,
                $entry,
                sprintf("'%s' is not exported from createPhueApp.js, the module the `phue` specifier points at.", $symbol),
            );
        }
    }

    /**
     * The runtime imports `./i18n.js` as a sibling, so the import-map URL must
     * point at a directory whose relative imports resolve over HTTP.
     */
    public function testTheRuntimeImportsItsSiblingByRelativePath(): void
    {
        $source = (string) file_get_contents(self::RUNTIME_DIR . '/createPhueApp.js');

        $this->assertSame(
            1,
            preg_match('/from\s*"(\.\/i18n\.js)"/', $source, $m),
            'createPhueApp.js must import ./i18n.js relatively so the published URL resolves it',
        );
        $this->assertFileExists(self::RUNTIME_DIR . '/' . $m[1]);
    }

    /**
     * The factory picks its Vue factory from what the mount target holds.
     *
     * Always-hydrate would emit "Attempting to hydrate existing markup but
     * container is empty" on every SPA navigation; always-render would throw
     * away the server markup on a full page load. The auto branch is what makes
     * one call correct on both, so both factories must be reachable and the
     * decision must depend on the container, not on a constant.
     */
    public function testTheFactoryChoosesBetweenHydratingAndRenderingFromTheContainer(): void
    {
        $source = (string) file_get_contents(self::RUNTIME_DIR . '/createPhueApp.js');

        preg_match('/function factoryFor\(.*?\n}/s', $source, $fn);
        $this->assertNotEmpty($fn, 'could not locate factoryFor()');

        // The decision reads the container.
        $this->assertStringContainsString(
            'hasChildNodes()',
            $fn[0],
            'the hydrate/render decision must inspect the mount target',
        );

        // Both branches exist: SSR app to hydrate, plain app to render.
        $this->assertStringContainsString('createSSRApp', $fn[0]);
        $this->assertStringContainsString('createApp', $fn[0]);

        // And the explicit override is honoured, so a caller that KNOWS its
        // container is empty can silence the heuristic.
        $this->assertStringContainsString('hydrate === true', $fn[0]);
        $this->assertStringContainsString('hydrate === false', $fn[0]);

        // The factory itself must delegate, not hardcode one of them.
        preg_match('/export function createPhueApp.*?\n}/s', $source, $factory);
        $this->assertNotEmpty($factory);
        $this->assertStringContainsString('factoryFor(', $factory[0]);
        $this->assertStringNotContainsString('createSSRApp(options)', $factory[0]);
    }

    /**
     * The factory is what makes a repeated call safe: a component can be
     * mounted again (SPA navigation) without the previous app staying wired
     * to the container.
     */
    public function testTheFactoryTearsDownThePreviousAppBeforeBuildingANewOne(): void
    {
        $source = (string) file_get_contents(self::RUNTIME_DIR . '/createPhueApp.js');

        $this->assertStringContainsString(
            'previous.unmount()',
            $source,
            'createPhueApp must unmount the app it replaces, or re-mounts leak watchers',
        );
        $this->assertStringContainsString('typeof previous.unmount === "function"', $source);
    }

    /**
     * `mount: false` is what lets a component build the app and take it over
     * itself — PhUI.phue needs the instance, not the mount.
     */
    public function testMountingCanBeDeclinedWithoutSkippingTheWiring(): void
    {
        $source = (string) file_get_contents(self::RUNTIME_DIR . '/createPhueApp.js');

        preg_match('/export function createPhueApp.*?\n}/s', $source, $fn);
        $this->assertNotEmpty($fn);

        // installI18n and the window publish are unconditional: they must sit
        // outside the `if (mount)` block or a declined mount would also skip
        // the wiring, and the caller would get an app with no `t` on it.
        $this->assertSame(
            0,
            preg_match('/if\s*\(mount\)\s*\{[^}]*installI18n/', $fn[0]),
            'translations must be installed even when the caller declines the mount',
        );
        $this->assertStringContainsString('installI18n(app)', $fn[0]);

        // ...only the mount itself is gated.
        $this->assertStringContainsString('if (mount) {', $fn[0]);
    }
}