<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Generator;

use PHPUnit\Framework\TestCase;
use Phue\Schii\Generator\BuildGenerator;
use Phue\Schii\Generator\FileGeneratorInterface;
use Phue\Schii\Generator\GeneratorInterface;
use Phue\Schii\Generator\RuntimeGenerator;
use Phue\Schii\Schema\SchemaRegistry;
use Phue\Core\Routing\Router;
use Phue\Schii\Endpoint\ModelEndpoint;

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