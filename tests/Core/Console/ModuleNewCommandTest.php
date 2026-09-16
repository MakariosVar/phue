<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console;

use PHPUnit\Framework\TestCase;

/**
 * Integration test for `bin/phue module:new`.
 *
 * Runs the real script with `--output=<tmp>` so NO filesystem
 * pollution happens in /app. Verifies generated files, then deletes tmp.
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class ModuleNewCommandTest extends TestCase
{
    private string $tmpDir;
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phue_mn_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testModuleNewGeneratesLayerModule(): void
    {
        $name = 'TmpMod';
        $id = lcfirst($name);
        $moduleDir = $this->tmpDir . "/src/Modules/{$name}";
        $configFile = $this->tmpDir . "/config/{$id}.php";
        $testDir = $this->tmpDir . "/tests/{$name}";

        $output = shell_exec('php /app/bin/phue module:new ' . $name . ' --type=layer --requires=auth --output=' . $this->tmpDir . ' 2>&1');

        // Command output
        $this->assertStringContainsString("Successfully created module '{$name}'", $output ?? '');

        // Files generated under tmp
        $this->assertFileExists($moduleDir . "/{$name}Module.php", "Module file missing");
        $this->assertFileExists($configFile, "Config file missing");
        $this->assertFileExists($testDir . "/{$name}ModuleTest.php", "Test file missing");
        $this->assertFileExists($testDir . "/.gitkeep", "Gitkeep missing");

        // Module content verification
        $content = file_get_contents($moduleDir . "/{$name}Module.php");
        $this->assertStringContainsString("namespace Phue\\Modules\\{$name}", $content);
        $this->assertStringContainsString("\$id = '{$id}'", $content);
        $this->assertStringContainsString("\$type = 'layer'", $content);
        $this->assertStringContainsString("\$requires = ['auth']", $content);
        $this->assertStringContainsString("@package Phue\\Modules\\{$name}", $content);

        // Config content
        $this->assertStringContainsString("'enabled' => true", file_get_contents($configFile));

        // Test content
        $testContent = file_get_contents($testDir . "/{$name}ModuleTest.php");
        $this->assertStringContainsString("namespace Phue\\Tests\\{$name}", $testContent);
        $this->assertStringContainsString("class {$name}ModuleTest extends TestCase", $testContent);

        // Verify NO pollution in /app
        $this->assertFileDoesNotExist('/app/src/Modules/' . $name . '/' . $name . 'Module.php');
        $this->assertFileDoesNotExist('/app/config/' . $id . '.php');
        $this->assertFileDoesNotExist('/app/tests/' . $name . '/' . $name . 'ModuleTest.php');
    }

    public function testModuleNewRejectsLowercaseName(): void
    {
        $output = shell_exec('php /app/bin/phue module:new queue --output=' . $this->tmpDir . ' 2>&1');
        $this->assertStringContainsString('must be alphabetic and in PascalCase', $output ?? '');
    }

    public function testModuleNewRejectsMissingName(): void
    {
        $output = shell_exec('php /app/bin/phue module:new --output=' . $this->tmpDir . ' 2>&1');
        $this->assertStringContainsString('Missing module name argument', $output ?? '');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
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