<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\TestCase;
use Phue\Core\DevTool\Dumper;

#[\PHPUnit\Framework\Attributes\Group('unit')]
class DumperTest extends TestCase
{
    private Dumper $dumper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dumper = new Dumper();
    }

    public function testDumpReturnsString(): void
    {
        $output = $this->dumper->dump('hello world');

        $this->assertIsString($output);
        $this->assertStringContainsString('hello world', $output);
    }

    public function testDumpReturnModeReturnsOutput(): void
    {
        $output = $this->dumper->dump(['key' => 'value'], return: true);

        $this->assertIsString($output);
        $this->assertStringContainsString('key', $output);
        $this->assertStringContainsString('value', $output);
    }

    public function testDumpOutputContainsStyledPre(): void
    {
        $output = $this->dumper->dump(42);

        $this->assertStringContainsString('<pre', $output);
        $this->assertStringContainsString('</pre>', $output);
        $this->assertStringContainsString('style=', $output);
    }

    public function testDumpHandlesArray(): void
    {
        $output = $this->dumper->dump(['a' => 1, 'b' => 2]);

        $this->assertStringContainsString('a', $output);
        $this->assertStringContainsString('b', $output);
        $this->assertStringContainsString('1', $output);
        $this->assertStringContainsString('2', $output);
    }

    public function testDumpHandlesNestedStructure(): void
    {
        $data = [
            'user' => [
                'name' => 'test',
                'roles' => ['admin', 'editor'],
            ],
        ];
        $output = $this->dumper->dump($data, return: true);

        $this->assertStringContainsString('user', $output);
        $this->assertStringContainsString('roles', $output);
        $this->assertStringContainsString('admin', $output);
    }

    public function testDumpHandlesNull(): void
    {
        $output = $this->dumper->dump(null, return: true);

        $this->assertIsString($output);
        $this->assertStringContainsString('NULL', $output);
    }
}