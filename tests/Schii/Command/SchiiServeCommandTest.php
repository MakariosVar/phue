<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Command;

use PHPUnit\Framework\TestCase;
use Phue\Modules\Schii\Commands\SchiiServeCommand;
use Phue\Core\Routing\Router;
use Phue\Modules\Schii\Generator\RuntimeGenerator;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;

/**
 * SchiiServeCommand: identity contract and missing-public-dir guard.
 *
 * The real server bootstrap (passthru php -S) is intentionally not exercised.
 *
 * @package Phue\Tests\Schii\Command
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class SchiiServeCommandTest extends TestCase
{
    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stdout = fopen('php://memory', 'w+');
        $this->stderr = fopen('php://memory', 'w+');
    }

    private function command(): SchiiServeCommand
    {
        return new SchiiServeCommand();
    }

    private function read($stream): string
    {
        rewind($stream);
        return (string) stream_get_contents($stream);
    }

    public function testCommandIdentityContract(): void
    {
        $command = $this->command();

        $this->assertSame('schii:serve', $command->getName());
        $this->assertSame('Serves Schii API + console from schema at runtime.', $command->getDescription());
    }

    public function testExecuteFailsWhenPublicDirIsMissing(): void
    {
        $command = $this->command();

        $code = $command->execute(
            ['--public=/nonexistent/public', '--port=9123'],
            $this->stdout,
            $this->stderr,
        );

        $this->assertSame(1, $code);
        $this->assertStringContainsString('does not exist', $this->read($this->stderr));
        $this->assertSame('', $this->read($this->stdout));
    }

    public function testExecuteAcceptsDependencyScalffolding(): void
    {
        $registry = new SchemaRegistry();
        $generator = new RuntimeGenerator(new Router(), new ModelEndpoint($registry));

        $command = new SchiiServeCommand($registry, $generator);

        $this->assertSame('schii:serve', $command->getName());
    }
}