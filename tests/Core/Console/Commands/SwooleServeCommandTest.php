<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console\Commands;

use Phue\Core\Console\Commands\SwooleServeCommand;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\Group('unit')]
final class SwooleServeCommandTest extends TestCase
{
    private SwooleServeCommand $command;
    /** @var resource */
    private $out;
    /** @var resource */
    private $err;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new SwooleServeCommand();
        $this->out = fopen('php://memory', 'w+');
        $this->err = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        fclose($this->out);
        fclose($this->err);
        parent::tearDown();
    }

    private function getStreamContent($resource): string
    {
        rewind($resource);
        return (string) stream_get_contents($resource);
    }

    public function testGetName(): void
    {
        $this->assertSame('serve:swoole', $this->command->getName());
    }

    public function testInvalidPortFormatReturnsError(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is required.');
        }

        $code = $this->command->execute(['--port=abc'], $this->out, $this->err);
        
        $this->assertSame(1, $code);
        $this->assertStringContainsString("Error: Invalid port value 'abc'", $this->getStreamContent($this->err));
    }

    public function testPortOutOfRangeReturnsError(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is required.');
        }

        $code = $this->command->execute(['--port=99999'], $this->out, $this->err);
        
        $this->assertSame(1, $code);
        $this->assertStringContainsString("Error: Port '99999' out of range", $this->getStreamContent($this->err));
    }
}
