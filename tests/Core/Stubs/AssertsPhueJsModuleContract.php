<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Stubs;

use PHPUnit\Framework\TestCase;

/**
 * AssertsPhueJsModuleContract — shared hardening gate for generated modules.
 *
 * Locks the precompiled-render contract from the consumer's side:
 *  1. the module must expose `__phueRender` as a declared, exported binding
 *     (the server reads mod.__phueRender, child imports alias it, and the
 *     local app proxy references it — a re-export alone would not bind it),
 *  2. the two historical failure shapes must never return:
 *     - `export const __phueRender = const { ... }` (declaration used as a value),
 *     - a free `Vue` destructure (`const { ... } = Vue`) from the compiler's
 *       "function mode", which only exists inside a scope with a `Vue` binding
 *       and cannot resolve in the browser ESM context,
 *  3. the emitted module must be syntactically valid ESM, verified by a real
 *     parse through Bun's Transpiler (not a string heuristic).
 *
 * Implemented as a static helper (not a TestCase subclass or trait) so the
 * PHPUnit class scanner does not treat it as a test class.
 *
 * @package Phue\Tests\Core\UI
 */
final class AssertsPhueJsModuleContract
{
    /**
     * Asserts a built Phue module satisfies the precompiled-render contract.
     *
     * @param TestCase $test    Active test instance (for assertions/skips).
     * @param string   $module  Generated ESM module source.
     * @param string   $context Human-readable label used in failure messages.
     *
     * @return void
     */
    public static function assertEmittedModuleIsLoadableJs(TestCase $test, string $module, string $context): void
    {
        $test->assertStringContainsString(
            'export const __phueRender',
            $module,
            "[{$context}] module must declare and export the __phueRender binding."
        );
        $test->assertStringNotContainsString(
            'export const __phueRender = const',
            $module,
            "[{$context}] __phueRender must be assigned a value, not a declaration (the `= const` regression)."
        );
        $test->assertStringNotContainsString(
            '} = Vue',
            $module,
            "[{$context}] compiled render must not destructure helpers from a free `Vue` global (function-mode leak)."
        );

        $bin = trim((string) shell_exec('command -v bun'));
        if ($bin === '') {
            $test->markTestSkipped("bun is unavailable; cannot verify ESM syntax for {$context}.");
        }

        $payload = base64_encode($module);
        $script = "const t = new Bun.Transpiler();"
            . "const s = Buffer.from('{$payload}', 'base64').toString('utf8');"
            . "try { t.transformSync(s, 'ts'); } catch (e) { console.log(String((e && e.message) || e).split('\\n')[0]); process.exit(1); }";

        $cmd = escapeshellarg($bin) . ' -e ' . escapeshellarg($script);

        $output = [];
        $exit = -1;
        exec($cmd, $output, $exit);

        $test->assertSame(
            0,
            $exit,
            sprintf(
                "[%s] generated module is not valid ESM: %s",
                $context,
                $output !== [] ? implode("\n", $output) : 'unexpected exit',
            )
        );
    }
}