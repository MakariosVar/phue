<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Stubs;

/**
 * PhueComponentRuntime — executes a compiled Phui component in `bun`.
 *
 * The Phui components are Vue 3 SFCs that the loader compiles into ESM modules
 * whose only import is `import { ... } from "vue"`. To exercise a component's
 * `methods`, `computed` and `data` there is no need for a rendering library
 * and no need for a DOM implementation: those members never touch Vue's
 * runtime helpers. So this runner strips that single import, which makes the
 * harness hermetic — no `vue` package, no happy-dom, no node_modules, and no
 * network at any point.
 *
 * What the component's logic does need is a *shallow* DOM surface:
 * `form.elements`, `name`, `type`, `disabled`, `checkValidity()`,
 * `validationMessage`, `setCustomValidity()`, the attribute methods, `focus()`,
 * `scrollIntoView()`, `reportValidity()` and `form.reset()`. That surface is
 * provided by the caller's own double, so every interaction is observable and
 * assertable rather than implicit.
 *
 * Skipped by the caller when `bun` is not installed.
 *
 * @package Phue\Tests\Core\UI
 */
final class PhueComponentRuntime
{
    private const TIMEOUT_SECONDS = 60;

    private static ?string $bun = null;

    /**
     * Absolute path to the bun binary, or null when it is not installed.
     */
    public static function bun(): ?string
    {
        if (self::$bun !== null) {
            return self::$bun === '' ? null : self::$bun;
        }

        $bin = trim((string) shell_exec('command -v bun 2>/dev/null'));
        self::$bun = $bin === '' ? '' : $bin;

        return self::$bun === '' ? null : self::$bun;
    }

    public static function available(): bool
    {
        return self::bun() !== null;
    }

    /**
     * Remove the framework's runtime import from a compiled module.
     *
     * Only the leading `import { ... } from "vue";` statement is removed, and
     * only its bindings — the render function is left intact but never called.
     * The result is a module with no dependencies at all.
     */
    public static function stripRuntimeImport(string $module): string
    {
        $stripped = preg_replace(
            '/^\s*import\s*\{[^}]*\}\s*from\s*["\']vue["\']\s*;?/m',
            '',
            $module,
            1,
            $count
        );

        return is_string($stripped) ? $stripped : $module;
    }

    /**
     * Run a harness against a compiled component module.
     *
     * The harness receives `globalThis.__COMPONENT__` (the component's options
     * object) and `globalThis.__PRELUDE__` (a JS source string it must evaluate
     * first, so it can install globals such as `FormData` before importing the
     * component). It must print a single JSON document on stdout.
     *
     * @param string $componentModule Compiled component ESM, as built by the loader.
     * @param string $harness         ESM source of the harness.
     * @param string $prelude         ESM source evaluated before the component import.
     *
     * @return array<string, mixed> Decoded harness output.
     */
    public static function run(string $componentModule, string $harness, string $prelude = ''): array
    {
        $bun = self::bun();
        if ($bun === null) {
            throw new \RuntimeException('bun is not installed; cannot execute the component runtime.');
        }

        $work = sys_get_temp_dir() . '/phue-component-run-' . bin2hex(random_bytes(6));
        if (!@mkdir($work, 0777, true) && !is_dir($work)) {
            throw new \RuntimeException("Could not create work directory {$work}.");
        }

        try {
            file_put_contents($work . '/component.mjs', self::stripRuntimeImport($componentModule));
            file_put_contents(
                $work . '/harness.mjs',
                $prelude . "\n" . $harness
            );

            $process = proc_open(
                sprintf('cd %s && %s harness.mjs 2>&1', escapeshellarg($work), escapeshellarg($bun)),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $work
            );

            if (!is_resource($process)) {
                throw new \RuntimeException('Could not start the bun harness.');
            }

            foreach ($pipes as $pipe) {
                stream_set_timeout($pipe, self::TIMEOUT_SECONDS);
            }

            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            $timedOut = false;
            foreach ($pipes as $pipe) {
                $meta = @stream_get_meta_data($pipe);
                $timedOut = $timedOut || !empty($meta['timed_out']);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            if ($timedOut) {
                throw new \RuntimeException(sprintf(
                    "The component harness did not finish within %d seconds.",
                    self::TIMEOUT_SECONDS
                ));
            }

            if ($status !== 0) {
                throw new \RuntimeException(sprintf(
                    "The component harness exited with status %d.\n%s",
                    $status,
                    $stdout !== '' ? $stdout : $stderr
                ));
            }

            $decoded = json_decode(trim($stdout), true);
            if (!is_array($decoded)) {
                throw new \RuntimeException(sprintf(
                    "The component harness did not print a JSON document.\nstdout: %s\nstderr: %s",
                    $stdout,
                    $stderr
                ));
            }

            return $decoded;
        } finally {
            foreach (glob($work . '/*') ?: [] as $file) {
                if (is_file($file) || is_link($file)) {
                    @unlink($file);
                }
            }
            @rmdir($work);
        }
    }
}
