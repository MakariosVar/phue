<?php

declare(strict_types=1);

namespace Phue\Tests\Compiler;

use PHPUnit\Framework\TestCase;
use Phue\Compiler\AotCompatibilityChecker;
use Phue\Compiler\AotCompatibilityReport;
use Phue\Compiler\FallbackStrategy;
use Phue\Compiler\WiringGenerator;
use Phue\Tests\Compiler\Fixtures\AbstractAotTargetClass;
use Phue\Tests\Compiler\Fixtures\CallUserFuncArrayAotTargetClass;
use Phue\Tests\Compiler\Fixtures\ExtractAotTargetClass;
use Phue\Tests\Compiler\Fixtures\IncompatibleAotTargetClass;
use Phue\Tests\Compiler\Fixtures\InterfaceAotTarget;
use Phue\Tests\Compiler\Fixtures\ValidAotTargetClass;
use Phue\Tests\Compiler\Fixtures\VariableVariableAotTargetClass;

/**
 * Unit Test Suite for TypePHP AOT Compiler Engine.
 *
 * @package Phue\Tests\Compiler
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
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
     * Tests that FallbackStrategy routes conflicting reports away from AOT_NATIVE.
     */
    public function testFallbackStrategyResolvesNonNativeFallback(): void
    {
        $report = new AotCompatibilityReport(IncompatibleAotTargetClass::class, ['Forbidden eval() usage']);
        $strategy = new FallbackStrategy();

        $mode = $strategy->resolveMode($report);
        $this->assertNotSame(FallbackStrategy::AOT_NATIVE, $mode);
    }

    /**
     * Tests that the checker reports a non-existent class/type.
     */
    public function testCompatibilityCheckerReportsMissingType(): void
    {
        $checker = new AotCompatibilityChecker();
        $report = $checker->checkClass('Phue\\Tests\\Compiler\\Fixtures\\DoesNotExistAnywhere');

        $this->assertFalse($report->isFullyCompatible());
        $this->assertStringContainsString('does not exist', $report->issues[0]);
    }

    /**
     * Tests that the checker flags abstract types as requiring dynamic binding.
     */
    public function testCompatibilityCheckerFlagsAbstractType(): void
    {
        $checker = new AotCompatibilityChecker();
        $report = $checker->checkClass(AbstractAotTargetClass::class);

        $this->assertFalse($report->isFullyCompatible());
        $this->assertStringContainsString('abstract or interface', $report->issues[0]);
    }

    /**
     * Tests that the checker flags interface types as requiring dynamic binding.
     */
    public function testCompatibilityCheckerFlagsInterfaceType(): void
    {
        $checker = new AotCompatibilityChecker();
        $report = $checker->checkClass(InterfaceAotTarget::class);

        $this->assertFalse($report->isFullyCompatible());
        $this->assertStringContainsString('abstract or interface', $report->issues[0]);
    }

    /**
     * Tests that the checker reports extract() usage.
     */
    public function testCompatibilityCheckerFlagsExtract(): void
    {
        $checker = new AotCompatibilityChecker();
        $report = $checker->checkClass(ExtractAotTargetClass::class);

        $this->assertFalse($report->isFullyCompatible());
        $this->assertStringContainsString('Forbidden use of extract()', implode("\n", $report->issues));
    }

    /**
     * Tests that the checker reports variable-variable ($$var) usage.
     */
    public function testCompatibilityCheckerFlagsVariableVariables(): void
    {
        $checker = new AotCompatibilityChecker();
        $report = $checker->checkClass(VariableVariableAotTargetClass::class);

        $this->assertFalse($report->isFullyCompatible());
        $this->assertStringContainsString('Forbidden variable variables', implode("\n", $report->issues));
    }

    /**
     * Tests that the checker reports dynamic call_user_func_array usage.
     */
    public function testCompatibilityCheckerFlagsDynamicCallUserFuncArray(): void
    {
        $checker = new AotCompatibilityChecker();
        $report = $checker->checkClass(CallUserFuncArrayAotTargetClass::class);

        $this->assertFalse($report->isFullyCompatible());
        $this->assertStringContainsString('Dynamic call_user_func_array', implode("\n", $report->issues));
    }

    /**
     * Tests that FallbackStrategy resolves to PHP_INTERPRETER when OPcache JIT is unavailable or disabled.
     */
    public function testFallbackStrategyResolvesInterpreterWhenJitUnavailable(): void
    {
        if (function_exists('opcache_get_status')) {
            $status = opcache_get_status(false);
            if ($status !== false && !empty($status['jit']['enabled'])) {
                $this->markTestSkipped('OPcache JIT is enabled in this environment.');
            }
        }

        $report = new AotCompatibilityReport(IncompatibleAotTargetClass::class, ['Forbidden eval() usage']);
        $strategy = new FallbackStrategy();

        $this->assertSame(FallbackStrategy::PHP_INTERPRETER, $strategy->resolveMode($report));
    }

    /**
     * Tests that FallbackStrategy resolves to PHP_JIT when OPcache JIT is enabled.
     */
    public function testFallbackStrategyResolvesJitWhenEnabled(): void
    {
        if (!function_exists('opcache_get_status')) {
            $this->markTestSkipped('opcache_get_status is unavailable in this environment.');
        }

        $status = opcache_get_status(false);
        if ($status === false || empty($status['jit']['enabled'])) {
            $this->markTestSkipped('OPcache JIT is not enabled in this environment.');
        }

        $report = new AotCompatibilityReport(IncompatibleAotTargetClass::class, ['Forbidden eval() usage']);
        $strategy = new FallbackStrategy();

        $this->assertSame(FallbackStrategy::PHP_JIT, $strategy->resolveMode($report));
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

    public function testCompatibilityCheckerClassWithNoFileNameIsCompatible(): void
    {
        $suffix = uniqid();
        $className = "Phue\\Tests\\Compiler\\EvalDefined_{$suffix}";
        eval("namespace Phue\\Tests\\Compiler { class EvalDefined_{$suffix} {} }");

        $checker = new AotCompatibilityChecker();
        $report = $checker->checkClass($className);

        // No file → file_get_contents branch skipped → fully compatible
        $this->assertTrue($report->isFullyCompatible());
        $this->assertFalse($report->isPartiallyCompatible());
    }
}