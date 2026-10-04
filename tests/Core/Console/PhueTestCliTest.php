<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the bin/phue_test PHPUnit wrapper CLI.
 *
 * Runs the real script as a subprocess (inside the app container, cwd = /app)
 * and asserts exit-code propagation, shortcut translation, pass-through and
 * clean STDERR (no PHP warnings/notices leaking into the run).
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class PhueTestCliTest extends TestCase
{
    private const SCRIPT = '/app/bin/phue_test';

    /**
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runCli(string ...$args): array
    {
        $cmd = array_merge([self::SCRIPT], $args);
        $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit' => proc_close($process),
        ];
    }

    /**
     * PHP diagnostics (Notice/Warning/Deprecated) leak to STDOUT when
     * display_errors is enabled in the CLI SAPI - assert both streams stay clean.
     */
    private function assertNoPhpDiagnostics(string $stdout, string $stderr): void
    {
        $combined = $stdout . "\n" . $stderr;
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error:'] as $needle) {
            $this->assertStringNotContainsString($needle, $combined);
        }
    }

    public function testHelpShowsUsageAndExitsZero(): void
    {
        $result = $this->runCli('--help');

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('Phue Test Runner', $result['stdout']);
        $this->assertStringContainsString('--unit', $result['stdout']);
        $this->assertStringContainsString('--integration', $result['stdout']);
        $this->assertStringContainsString('--coverage', $result['stdout']);
        $this->assertNoPhpDiagnostics($result['stdout'], $result['stderr']);
    }

    public function testHelpShortFlagAlsoExitsZero(): void
    {
        $result = $this->runCli('-h');

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('Phue Test Runner', $result['stdout']);
        $this->assertNoPhpDiagnostics($result['stdout'], $result['stderr']);
    }

    public function testUnknownFlagsPassThroughAndExitCodePropagates(): void
    {
        // --bootstrap pointing to a missing file makes PHPUnit exit with a
        // configuration error (exit code 2) - proves the wrapper propagates it
        $result = $this->runCli('--bootstrap', '/nonexistent.php');

        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('Cannot open bootstrap script', $result['stdout']);
        $this->assertNoPhpDiagnostics($result['stdout'], $result['stderr']);
    }

    public function testVersionFlagPassesThroughToPhpunit(): void
    {
        $result = $this->runCli('--version');

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('PHPUnit', $result['stdout']);
        $this->assertStringContainsString('Sebastian Bergmann', $result['stdout']);
        $this->assertNoPhpDiagnostics($result['stdout'], $result['stderr']);
    }

    public function testUnitShortcutTranslatesToGroup(): void
    {
        $result = $this->runCli('--unit', '--filter=RequestTest');

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('OK', $result['stdout']);
        $this->assertStringContainsString('tests', $result['stdout']);
        $this->assertNoPhpDiagnostics($result['stdout'], $result['stderr']);
    }

    public function testIntegrationShortcutTranslatesToGroup(): void
    {
        $result = $this->runCli('--integration', '--filter=SchiiConfigTest');

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('OK', $result['stdout']);
        $this->assertStringContainsString('tests', $result['stdout']);
        $this->assertNoPhpDiagnostics($result['stdout'], $result['stderr']);
    }

    public function testBothShortcutsMergeWithoutCollision(): void
    {
        // --unit + --integration must produce --group unit,integration and not
        // blow up on colliding --group options
        $result = $this->runCli('--unit', '--integration', '--filter=RequestTest');

        $this->assertSame(0, $result['exit']);
        $this->assertNoPhpDiagnostics($result['stdout'], $result['stderr']);
    }

    public function testUserProvidedGroupIsMergedWithShortcutGroup(): void
    {
        $result = $this->runCli('--unit', '--group=foo', '--filter=RequestTest');

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('OK', $result['stdout']);
        $this->assertNoPhpDiagnostics($result['stdout'], $result['stderr']);
    }

    public function testListModeSelectsWithoutRunningAnything(): void
    {
        $result = $this->runCli('--impact', 'src/Core/Hiit/Hiit.php', '--list');

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('test files', $result['stdout']);
        $this->assertStringNotContainsString('PHPUnit 10', $result['stdout'], '--list must not start a run');

        // The file that names the class is the one that has to be selected.
        $this->assertStringContainsString('tests/Core/Hiit/HiitTest.php', $result['stdout']);
        $this->assertNoPhpDiagnostics($result['stdout'], $result['stderr']);
    }

    public function testImpactSelectsTheTestsThatNameAChangedClass(): void
    {
        $result = $this->runCli('--impact', 'src/Core/UI/PhueComponentLoader.php', '--list');

        // The loader is what the freshness contract is written against, so its test has to
        // come along when the loader moves — and something unrelated must not.
        $this->assertStringContainsString('PhueComponentFreshnessTest.php', $result['stdout']);
        $this->assertStringContainsString('HiitTest.php', $result['stdout']);
        $this->assertStringNotContainsString('SchiiAutoMigrationTest.php', $result['stdout']);
        $this->assertNoPhpDiagnostics($result['stdout'], $result['stderr']);
    }

    public function testImpactOnAnUnmappablePathFallsBackToTheWholeSuite(): void
    {
        $result = $this->runCli('--impact', 'database/migrations/whatever.sql', '--list');

        // Narrower than the truth is the dangerous direction here: it looks like a pass while
        // the change is untested. So an unplaceable path widens to everything, and says so.
        $this->assertStringContainsString('no static mapping', $result['stderr']);

        // Compare against what a plain run selects, rather than a number that goes stale the
        // moment anyone adds a test file.
        $everything = $this->runCli('--list');
        preg_match('/of (\d+) test files/', $everything['stdout'], $total);

        $this->assertStringContainsString(
            sprintf('of %s test files', $total[1] ?? '?'),
            $result['stdout'],
            'an unplaceable path must widen to the whole suite'
        );
    }

    public function testOnlyFailedIsCalmWhenTheLastRunWasClean(): void
    {
        $cacheFile = '/app/.phpunit.cache/phue-test.json';
        $backup = is_file($cacheFile) ? (string) file_get_contents($cacheFile) : null;

        @mkdir(dirname($cacheFile), 0777, true);
        file_put_contents($cacheFile, json_encode(['failures' => [], 'at' => date('c')]));

        try {
            $result = $this->runCli('--only-failed');

            $this->assertSame(0, $result['exit']);
            $this->assertStringContainsString('no failures', $result['stdout']);
            $this->assertStringNotContainsString('PHPUnit 10', $result['stdout']);
        } finally {
            $backup === null ? @unlink($cacheFile) : file_put_contents($cacheFile, $backup);
        }
    }

    public function testOnlyFailedReplaysTheFilesFromTheRecordedFailures(): void
    {
        $cacheFile = '/app/.phpunit.cache/phue-test.json';
        $backup = is_file($cacheFile) ? (string) file_get_contents($cacheFile) : null;

        @mkdir(dirname($cacheFile), 0777, true);
        file_put_contents($cacheFile, json_encode([
            'failures' => ['Phue\\Tests\\Core\\Console\\PhueTestCliTest::testHelpShowsUsageAndExitsZero'],
            'at' => date('c'),
        ]));

        try {
            $result = $this->runCli('--only-failed', '--filter=testHelpShowsUsageAndExitsZero');

            // A recorded failure must come back as a file on disk, not as a test id PHPunit
            // would silently match against nothing.
            $this->assertStringContainsString('1 of', $result['stdout']);
            $this->assertStringContainsString('OK', $result['stdout']);
            $this->assertStringNotContainsString('No tests executed', $result['stdout']);
        } finally {
            $backup === null ? @unlink($cacheFile) : file_put_contents($cacheFile, $backup);
        }
    }

    public function testChangedReportsNothingToDoOnACleanTree(): void
    {
        $result = $this->runCli('--changed', '--list');

        // The working tree is dirty while developing, so only assert the contract that holds
        // either way: it never silently runs nothing.
        $this->assertSame(0, $result['exit']);
        $this->assertMatchesRegularExpression(
            '/(Nothing changed|test files)/',
            $result['stdout'],
            '--changed must either say the tree is clean or actually select something'
        );
        $this->assertNoPhpDiagnostics($result['stdout'], $result['stderr']);
    }

    public function testCoverageShortcutProducesTextReport(): void
    {
        if (!extension_loaded('pcov') && !extension_loaded('xdebug')) {
            $this->markTestSkipped('No coverage driver (pcov/xdebug) available.');
        }

        $result = $this->runCli('--unit', '--filter=RequestTest', '--coverage');

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('Summary:', $result['stdout']);
        $this->assertNoPhpDiagnostics($result['stdout'], $result['stderr']);
    }
}