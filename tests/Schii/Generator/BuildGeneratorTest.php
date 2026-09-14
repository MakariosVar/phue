<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Generator;

use PHPUnit\Framework\TestCase;
use Phue\Schii\Generator\BuildGenerator;
use Phue\Schii\Generator\PhiiExportSchema;
use Phue\Schii\Schema\SchemaRegistry;
use Phue\Tests\Schii\Stubs\User;

/**
 * BuildGenerator: schema file emission, output dir handling and round-trip
 * through the generated file.
 *
 * @package Phue\Tests\Schii\Generator
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class BuildGeneratorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phue-build-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function generator(): BuildGenerator
    {
        return new BuildGenerator(new SchemaRegistry(), $this->tmpDir);
    }

    public function testEnsureOutputDirCreatesNestedDirectories(): void
    {
        $this->assertFalse(is_dir($this->tmpDir));

        $this->generator()->ensureOutputDir();

        $this->assertTrue(is_dir($this->tmpDir));
    }

    public function testExportEntityWritesRunnableSchemaFile(): void
    {
        $path = $this->generator()->exportEntity(User::class, 'admin');

        $this->assertSame($this->tmpDir . '/user.php', $path);
        $this->assertFileExists($path);

        $content = (string) file_get_contents($path);
        $this->assertStringContainsString('PhiiExportSchema::fromArray(', $content);

        // The written file must be require-able and yield a working export.
        $export = require $path;
        $this->assertInstanceOf(PhiiExportSchema::class, $export);
        $this->assertSame(User::class, $export->className);
        $this->assertSame('users', $export->table);
    }

    public function testExportedFileRoundTripsBackToIdenticalData(): void
    {
        $schema = (new SchemaRegistry())->toPhiiExportSchema(User::class, 'admin');

        $path = $this->generator()->exportSchema($schema);

        $reloaded = require $path;
        $this->assertSame($schema->toArray(), $reloaded->toArray());
    }

    public function testExportAllWritesManifestWithOneFilePerModel(): void
    {
        $manifest = $this->generator()->exportAll('Phue\Tests\Schii\Stubs', 'admin');

        $this->assertArrayHasKey(User::class, $manifest);
        $this->assertFileExists($manifest[User::class]);
        $this->assertStringEndsWith('user.php', $manifest[User::class]);
    }

    public function testExportAllReturnsEmptyManifestForUnknownNamespace(): void
    {
        $manifest = $this->generator()->exportAll('Phue\Tests\Schii\DoesNotExist');

        $this->assertSame([], $manifest);
    }

    public function testExportSchemaUsesKebabEntityNameForFileName(): void
    {
        $schema = (new SchemaRegistry())->toPhiiExportSchema(User::class, 'admin');

        $path = $this->generator()->exportSchema($schema);

        $this->assertStringEndsWith('/user.php', $path);
    }
}