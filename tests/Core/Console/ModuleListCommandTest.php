<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console;

use PHPUnit\Framework\TestCase;

/**
 * Integration test for `./phue module:list`.
 *
 * Runs the real script as a subprocess and asserts:
 * - exit code 0
 * - stdout contains every module registered in config/app.php
 * - stdout shows the FQCN for each module
 * - no PHP diagnostics leaked to STDERR
 *
 * No filesystem side effects (read-only command).
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class ModuleListCommandTest extends TestCase
{
    private const SCRIPT = '/app/phue';

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

    private function assertNoPhpDiagnostics(string $stdout, string $stderr): void
    {
        $combined = $stdout . "\n" . $stderr;
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error:'] as $needle) {
            $this->assertStringNotContainsString($needle, $combined);
        }
    }

    public function testModuleListExitsZero(): void
    {
        $result = $this->runCli('module:list');

        $this->assertSame(0, $result['exit']);
        $this->assertNoPhpDiagnostics($result['stdout'], $result['stderr']);
    }

    public function testModuleListShowsAllRegisteredModules(): void
    {
        $result = $this->runCli('module:list');

        // These modules are registered in config/app.php
        foreach (['auth', 'schii', 'rest', 'server', 'events', 'devtools'] as $id) {
            $this->assertStringContainsString($id, $result['stdout'], "Missing module ID: {$id}");
        }
    }

    public function testModuleListShowsFqcnForEachModule(): void
    {
        $result = $this->runCli('module:list');

        foreach (['AuthModule', 'SchiiModule', 'RestModule', 'ServerModule', 'EventModule', 'DevToolsModule'] as $class) {
            $this->assertStringContainsString($class, $result['stdout'], "Missing class: {$class}");
        }
    }

    public function testModuleListShowsRequires(): void
    {
        $result = $this->runCli('module:list');

        // Rest requires auth
        $this->assertStringContainsString('rest', $result['stdout']);
        $this->assertStringContainsString('auth', $result['stdout']);
    }
}