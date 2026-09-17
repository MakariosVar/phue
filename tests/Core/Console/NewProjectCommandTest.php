<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console;

use PHPUnit\Framework\TestCase;

/**
 * Integration test for `bin/phue new --modules=<id>`.
 *
 * Runs the real script as a subprocess, verifies the scaffolded
 * temp project has the right structure, then removes the temp dir.
 *
 * Uses `auth` module (already exists) as test target.
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class NewProjectCommandTest extends TestCase
{
    private ?string $tmpDir = null;

    public function testNewScaffoldsIsolationProject(): void
    {
        $output = shell_exec('php /app/phue new --modules=Auth 2>&1');

        // Parse tmp dir from output
        if (!preg_match('/at (\S+)/', $output ?? '', $matches)) {
            $this->fail("Could not find tmp dir in output: {$output}");
            return;
        }
        $this->tmpDir = rtrim($matches[1], '.');

        // Verify scaffolded structure
        $this->assertDirectoryExists($this->tmpDir);
        $this->assertFileExists($this->tmpDir . '/composer.json');
        $this->assertFileExists($this->tmpDir . '/config/app.php');
        $this->assertFileExists($this->tmpDir . '/phpunit.xml');
        $this->assertDirectoryExists($this->tmpDir . '/src/Modules/Auth');
        $this->assertDirectoryExists($this->tmpDir . '/tests/Auth');
        $this->assertDirectoryExists($this->tmpDir . '/tests/Support');

        // config/app.php contains AuthModule only (isolation)
        $appConfig = file_get_contents($this->tmpDir . '/config/app.php');
        $this->assertStringContainsString('AuthModule', $appConfig);

        // phpunit.xml has auth testsuite
        $phpunit = file_get_contents($this->tmpDir . '/phpunit.xml');
        $this->assertStringContainsString('<testsuite name="Auth"', $phpunit);
        $this->assertStringContainsString('tests/Auth', $phpunit);

        // composer.json requires phue/phue via path repo
        $composer = json_decode(file_get_contents($this->tmpDir . '/composer.json'), true);
        $this->assertArrayHasKey('require', $composer);
        $this->assertArrayHasKey('phue/phue', $composer['require']);
        $this->assertArrayHasKey('repositories', $composer);
        $this->assertSame('path', $composer['repositories'][0]['type']);

        // No other module in config (isolation proof)
        $this->assertStringNotContainsString('SchiiModule', $appConfig);
        $this->assertStringNotContainsString('RestModule', $appConfig);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir && is_dir($this->tmpDir)) {
            $this->rrmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        $items = glob($dir . '/*');
        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->rrmdir($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }
}