<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Stubs;

use PHPUnit\Framework\TestCase;

/**
 * PhueJsRunner — runs a Bun driver script and decodes its JSON report.
 *
 * The shared Phue JS composables under `src/Core/UI/js/` are pure ESM modules
 * with no framework runtime and no DOM dependency at module scope, so they are
 * testable by executing them against a minimal DOM double rather than by
 * string-matching their source.
 *
 * This helper keeps the Bun invocation in exactly one place so every behavioural
 * test uses the same resolution, quoting and skip semantics as the existing
 * `AssertsPhueJsModuleContract` Bun gate.
 *
 * Implemented as a static helper (not a TestCase subclass or trait) so the
 * PHPUnit class scanner does not treat it as a test class.
 *
 * @package Phue\Tests\Core\UI
 */
final class PhueJsRunner
{
    /**
     * Absolute path to the `bun` binary, or null when unavailable.
     */
    private static ?string $bunBin = null;

    /**
     * Whether the binary lookup has already been attempted.
     */
    private static bool $bunResolved = false;

    /**
     * Absolute path to the repository root.
     */
    public static function repoRoot(): string
    {
        // tests/Core/Stubs -> tests/Core -> tests -> <root>
        return dirname(__DIR__, 3);
    }

    /**
     * Absolute path to the composable JS source directory under test.
     */
    public static function composableJsDir(): string
    {
        return self::repoRoot() . '/src/Core/UI/js';
    }

    /**
     * Absolute path to a Bun driver script, resolved relative to `tests/Core/UI/js`.
     *
     * @param string $relative Driver filename, e.g. `overlay.test.mjs`.
     */
    public static function driverPath(string $relative): string
    {
        return self::repoRoot() . '/tests/Core/UI/js/' . $relative;
    }

    /**
     * Skips the calling test when Bun is not installed.
     *
     * The JS composables are shipped as inline prelude text, so the PHP suite
     * cannot assert their runtime behaviour without an ESM runtime. Skipping
     * keeps the suite green on hosts without Bun while still failing loudly on
     * the Docker image, where Bun is part of the toolchain.
     */
    public static function skipWithoutBun(TestCase $test, string $context): void
    {
        if (self::bunBinary() === null) {
            $test->markTestSkipped("bun is unavailable; cannot execute the JS composable contract for {$context}.");
        }
    }

    /**
     * Executes a Bun driver and returns its decoded report.
     *
     * The driver is invoked with two positional arguments: the repository root
     * and the composable JS directory, so the driver never hardcodes paths.
     *
     * @param TestCase $test             Active test instance (for skip semantics).
     * @param string   $relativeDriver   Driver filename inside `tests/Core/UI/js`.
     * @param string   $context          Human-readable label for failure messages.
     *
     * @return array{exit:int,report:array<string,mixed>,raw:string}
     */
    public static function run(TestCase $test, string $relativeDriver, string $context): array
    {
        $bun = self::bunBinary();
        if ($bun === null) {
            self::skipWithoutBun($test, $context);
            /** @phpstan-ignore-next-line unreachable once skipped */
            return ['exit' => -1, 'report' => [], 'raw' => ''];
        }

        $driver = self::driverPath($relativeDriver);
        if (!is_file($driver)) {
            $test->fail("[{$context}] missing Bun driver script: {$driver}");
        }

        $cmd = escapeshellarg($bun)
            . ' ' . escapeshellarg($driver)
            . ' ' . escapeshellarg(self::repoRoot())
            . ' ' . escapeshellarg(self::composableJsDir())
            . ' 2>&1';

        $lines = [];
        $exit = -1;
        exec($cmd, $lines, $exit);

        $raw = implode("\n", $lines);
        $report = json_decode($raw, true);

        $test->assertIsArray(
            $report,
            sprintf(
                "[%s] driver did not emit a JSON report (exit %d): %s",
                $context,
                $exit,
                $raw !== '' ? $raw : 'empty output',
            )
        );

        /** @var array<string, mixed> $report */
        return ['exit' => $exit, 'report' => $report, 'raw' => $raw];
    }

    /**
     * Asserts a driver report contains zero failures and a healthy case count.
     *
     * The minimum case count is asserted as well so a driver that silently stops
     * registering assertions (or a rewritten loop) fails the suite instead of
     * passing vacuously.
     *
     * @param TestCase $test    Active test instance.
     * @param array    $report  Decoded driver report.
     * @param int      $minimum Expected minimum number of executed cases.
     * @param string   $context Human-readable label for failure messages.
     */
    public static function assertAllCasesPassed(
        TestCase $test,
        array $report,
        int $minimum,
        string $context
    ): void {
        $failures = $report['failures'] ?? null;
        $passed = $report['passed'] ?? null;

        $test->assertIsArray($failures, "[{$context}] report must expose a `failures` array.");
        $test->assertIsInt($passed, "[{$context}] report must expose an integer `passed` count.");
        $test->assertGreaterThanOrEqual(
            $minimum,
            $passed,
            "[{$context}] case-count ratchet: expected at least {$minimum} executed cases, got {$passed}."
        );

        $test->assertSame(
            [],
            array_values($failures),
            sprintf(
                "[%s] %d behavioural case(s) failed:\n  - %s",
                $context,
                count($failures),
                implode("\n  - ", $failures),
            )
        );
    }

    /**
     * Resolves and caches the `bun` binary path.
     */
    private static function bunBinary(): ?string
    {
        if (self::$bunResolved) {
            return self::$bunBin;
        }

        self::$bunResolved = true;
        $bin = trim((string) shell_exec('command -v bun'));
        self::$bunBin = $bin === '' ? null : $bin;

        return self::$bunBin;
    }
}