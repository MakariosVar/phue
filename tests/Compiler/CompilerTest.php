<?php

declare(strict_types=1);

namespace Phue\Tests\Compiler;

use PHPUnit\Framework\TestCase;
use Phue\Compiler\AotCompatibilityChecker;
use Phue\Compiler\AotCompatibilityReport;
use Phue\Compiler\FallbackStrategy;
use Phue\Compiler\WiringGenerator;
use Phue\Tests\Compiler\Fixtures\IncompatibleAotTargetClass;
use Phue\Tests\Compiler\Fixtures\ValidAotTargetClass;

/**
 * Unit Test Suite for TypePHP AOT Compiler Engine.
 *
 * @package Phue\Tests\Compiler
 */
final class CompilerTest extends TestCase
{
    private string $tempBuildDir;

    protected function setUp(): void
    {
        $this->tempBuildDir = sys_get_temp_dir() . '/phue_aot_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempBuildDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempBuildDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                $todo($fileinfo->getRealPath());
            }

            rmdir($this->tempBuildDir);
        }
    }

    /**
     * Tests that AotCompatibilityChecker accurately identifies compliant classes.
     */
    public function testCompatibilityCheckerValidClass(): void
    {
        $checker = new AotCompatibilityChecker();
        $report = $checker->checkClass(ValidAotTargetClass::class);

        $this->assertInstanceOf(AotCompatibilityReport::class, $report);
        $this->assertTrue($report->isFullyCompatible());
        $this->assertFalse($report->isPartiallyCompatible());
        $this->assertEmpty($report->issues);
    }

    /**
     * Tests that AotCompatibilityChecker flags classes using banned dynamic evaluation (eval).
     */
    public function testCompatibilityCheckerIncompatibleClass(): void
    {
        $checker = new AotCompatibilityChecker();
        $report = $checker->checkClass(IncompatibleAotTargetClass::class);

        $this->assertFalse($report->isFullyCompatible());
        $this->assertTrue($report->isPartiallyCompatible());
        $this->assertNotEmpty($report->issues);
        $this->assertStringContainsString('Forbidden use of eval()', $report->issues[0]);
    }

    /**
     * Tests that FallbackStrategy properly routes fully compatible reports to AOT_NATIVE.
     */
    public function testFallbackStrategyResolvesAotNative(): void
    {
        $report = new AotCompatibilityReport(ValidAotTargetClass::class, []);
        $strategy = new FallbackStrategy();

        $mode = $strategy->resolveMode($report);
        $this->assertSame(FallbackStrategy::AOT_NATIVE, $mode);
    }

    /**
     * Tests that FallbackStrategy routes problematic classes away from AOT_NATIVE.
     */
    public function testFallbackStrategyResolvesNonNativeFallback(): void
    {
        $report = new AotCompatibilityReport(IncompatibleAotTargetClass::class, ['Forbidden eval() usage']);
        $strategy = new FallbackStrategy();

        $mode = $strategy->resolveMode($report);
        $this->assertNotSame(FallbackStrategy::AOT_NATIVE, $mode);
    }

    /**
     * Tests WiringGenerator emitting build/wiring.php and build/wiring.cpp files.
     */
    public function testWiringGeneratorBuildsArtifacts(): void
    {
        $generator = new WiringGenerator(
            scanDirectories: [__DIR__ . '/Fixtures'],
            outputDir: $this->tempBuildDir
        );

        $result = $generator->generate();

        $this->assertFileExists($result['php_file']);
        $this->assertFileExists($result['cpp_file']);
        $this->assertGreaterThanOrEqual(1, $result['count']);

        // Verify PHP wiring output format
        $phpWiring = require $result['php_file'];
        $this->assertIsArray($phpWiring);
        $this->assertArrayHasKey(ValidAotTargetClass::class, $phpWiring);

        // Verify C++ wiring contents
        $cppContent = file_get_contents($result['cpp_file']);
        $this->assertStringContainsString('namespace PhueAot', $cppContent);
        $this->assertStringContainsString('ValidAotTargetClass', $cppContent);
    }
}