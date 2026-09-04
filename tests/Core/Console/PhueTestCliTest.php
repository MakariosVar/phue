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