<?php

declare(strict_types=1);

namespace Phue\Tests\Core;

use PHPUnit\Framework\TestCase;
use Phue\Core\Config;

#[\PHPUnit\Framework\Attributes\Group('unit')]
final class ConfigTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configPath = sys_get_temp_dir() . '/phue_test_config_' . uniqid();
        mkdir($this->configPath, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $files = glob($this->configPath . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($this->configPath)) {
            rmdir($this->configPath);
        }
    }

    public function testLoadsPhpConfigFilesFromDirectory(): void
    {
        file_put_contents($this->configPath . '/app.php', '<?php return ["name" => "Phue", "version" => "1.0"];');

        $config = new Config($this->configPath);

        $this->assertSame('Phue', $config->get('app.name'));
        $this->assertSame('1.0', $config->get('app.version'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $config = new Config($this->configPath);

        $this->assertNull($config->get('nonexistent'));
        $this->assertSame('fallback', $config->get('nonexistent', 'fallback'));
    }

    public function testHasReturnsTrueForExistingKey(): void
    {
        file_put_contents($this->configPath . '/db.php', '<?php return ["driver" => "sqlite"];');

        $config = new Config($this->configPath);

        $this->assertTrue($config->has('db.driver'));
        $this->assertFalse($config->has('db.host'));
    }

    public function testSetAndRetrieveDynamicValue(): void
    {
        $config = new Config($this->configPath);

        $config->set('cache.ttl', 3600);
        $this->assertSame(3600, $config->get('cache.ttl'));
    }

    public function testSetCreatesNestedStructureFromDotNotation(): void
    {
        $config = new Config($this->configPath);

        $config->set('database.connections.mysql.host', '127.0.0.1');
        $this->assertSame('127.0.0.1', $config->get('database.connections.mysql.host'));
    }

    public function testSkipsNonPhpFiles(): void
    {
        file_put_contents($this->configPath . '/app.php', '<?php return ["name" => "Phue"];');
        file_put_contents($this->configPath . '/readme.txt', 'not a php file');
        file_put_contents($this->configPath . '/data.json', '{"key": "value"}');

        $config = new Config($this->configPath);

        $this->assertSame('Phue', $config->get('app.name'));
        $this->assertNull($config->get('readme'));
        $this->assertNull($config->get('data'));
    }

    public function testSkipsConfigFileReturningNonArray(): void
    {
        file_put_contents($this->configPath . '/good.php', '<?php return ["key" => "value"];');
        file_put_contents($this->configPath . '/bad.php', '<?php return "string value";');

        $config = new Config($this->configPath);

        $this->assertSame('value', $config->get('good.key'));
        $this->assertNull($config->get('bad'));
    }

    public function testHandlesEmptyDirectoryGracefully(): void
    {
        $config = new Config($this->configPath);

        $this->assertNull($config->get('anything'));
        $this->assertFalse($config->has('anything'));
    }

    public function testHandlesNonExistentDirectoryGracefully(): void
    {
        $config = new Config('/tmp/nonexistent_directory_' . uniqid());

        $this->assertNull($config->get('key'));
    }

    public function testDotNotationOverwritesExistingNestedKey(): void
    {
        $config = new Config($this->configPath);

        $config->set('app.debug', true);
        $this->assertTrue($config->get('app.debug'));

        $config->set('app.debug', false);
        $this->assertFalse($config->get('app.debug'));
    }

    public function testFlatKeyGetReturnsExactMatch(): void
    {
        file_put_contents($this->configPath . '/app.php', '<?php return ["name" => "Phue"];');

        $config = new Config($this->configPath);

        $this->assertSame(['name' => 'Phue'], $config->get('app'));
    }

    public function testSetOverwritesFileLoadedValue(): void
    {
        file_put_contents($this->configPath . '/app.php', '<?php return ["name" => "Phue"];');

        $config = new Config($this->configPath);
        $this->assertSame('Phue', $config->get('app.name'));

        $config->set('app.name', 'Phue2');
        $this->assertSame('Phue2', $config->get('app.name'));
    }
}
