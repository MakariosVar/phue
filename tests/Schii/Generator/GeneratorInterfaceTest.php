<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Generator;

use PHPUnit\Framework\TestCase;
use Phue\Modules\Schii\Generator\BuildGenerator;
use Phue\Modules\Schii\Generator\FileGeneratorInterface;
use Phue\Modules\Schii\Generator\GeneratorInterface;
use Phue\Modules\Schii\Generator\RuntimeGenerator;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Core\Routing\Router;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;

/**
 * Generator contract conformance: name contracts and FileGeneratorInterface
 * separation expectations.
 *
 * @package Phue\Tests\Schii\Generator
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class GeneratorInterfaceTest extends TestCase
{
    private function buildGenerator(): BuildGenerator
    {
        return new BuildGenerator(new SchemaRegistry());
    }

    public function testBuildGeneratorNameIsBuild(): void
    {
        $this->assertSame('build', $this->buildGenerator()->name());
    }

    public function testRuntimeGeneratorNameIsRuntime(): void
    {
        $generator = new RuntimeGenerator(
            new Router(),
            new ModelEndpoint(new SchemaRegistry())
        );

        $this->assertSame('runtime', $generator->name());
    }

    public function testBuildGeneratorImplementsFileGeneratorInterface(): void
    {
        $this->assertInstanceOf(GeneratorInterface::class, $this->buildGenerator());
        $this->assertInstanceOf(FileGeneratorInterface::class, $this->buildGenerator());
    }

    public function testRuntimeGeneratorDoesNotImplementFileGeneratorInterface(): void
    {
        $generator = new RuntimeGenerator(
            new Router(),
            new ModelEndpoint(new SchemaRegistry())
        );

        $this->assertInstanceOf(GeneratorInterface::class, $generator);
        $this->assertNotInstanceOf(FileGeneratorInterface::class, $generator);
    }

    public function testBuildGeneratorOutputDirDefaultsToSchemasStorage(): void
    {
        $this->assertSame('storage/generated/schemas', $this->buildGenerator()->getOutputDir());
    }

    public function testBuildGeneratorNestedOutputBaseIsTrailingSlashTrimmed(): void
    {
        $generator = new BuildGenerator(new SchemaRegistry(), '/var/www/gen/');

        $this->assertSame('/var/www/gen', $generator->getOutputDir());
    }
}