<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console;

use Phue\Core\Cache\FileCache;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CacheClearCommand: verifies each cache type is correctly
 * cleared and that invalid types are rejected.
 *
 * @package Phue\Tests\Core\Console
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class CacheClearCommandTest extends TestCase
{
    private string $basePath;
    private string $cacheDir;
    private string $viewsDir;
    private string $schemasDir;
    private string $logsDir;
    private string $phueBin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir() . '/phue_cache_test_' . uniqid('', true);
        $this->cacheDir = $this->basePath . '/storage/cache';
        $this->viewsDir = $this->cacheDir . '/views';
        $this->schemasDir = $this->basePath . '/storage/generated/schemas';
        $this->logsDir = $this->basePath . '/storage/logs';
        $this->phueBin = getcwd() . '/bin/phue';

        // Create directories with PathResolver markers
        foreach ([$this->cacheDir, $this->viewsDir, $this->schemasDir, $this->logsDir] as $dir) {
            @mkdir($dir, 0777, true);
        }
        @mkdir($this->basePath . '/App', 0777, true);
        @mkdir($this->basePath . '/config', 0777, true);
        file_put_contents($this->basePath . '/composer.json', '{}');

        // Seed test files
        $this->seedFiles();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->basePath);
        parent::tearDown();
    }

    private function seedFiles(): void
    {
        // General cache files
        $fileCache = new FileCache($this->cacheDir);
        $fileCache->set('key1', 'value1');
        $fileCache->set('key2', 'value2');

        // Views cache files
        file_put_contents($this->viewsDir . '/view1.php', '<?php echo "view1";');
        file_put_contents($this->viewsDir . '/view2.php', '<?php echo "view2";');

        // Schemas cache files
        file_put_contents($this->schemasDir . '/schema1.php', '<?php return [];');
        file_put_contents($this->schemasDir . '/schema2.php', '<?php return [];');

        // Log files
        file_put_contents($this->logsDir . '/app.log', 'log line 1');
        file_put_contents($this->logsDir . '/error.log', 'error line 1');
    }

    private function runCommand(array $args): array
    {
        $cmd = sprintf(
            'cd %s && php %s cache:clear %s 2>&1; EXIT_CODE=$?; echo "EXIT_CODE:$EXIT_CODE"',
            escapeshellarg($this->basePath),
            escapeshellarg($this->phueBin),
            implode(' ', array_map('escapeshellarg', $args))
        );

        $output = shell_exec($cmd);
        $exitCode = 0;

        if (preg_match('/EXIT_CODE:(\d+)$/m', $output, $matches)) {
            $exitCode = (int)$matches[1];
            $output = preg_replace('/EXIT_CODE:\d+$/m', '', $output);
        }

        return [
            'code' => $exitCode,
            'output' => trim($output),
            'error' => '',
        ];
    }

    public function testDefaultTypeClearsAll(): void
    {
        $result = $this->runCommand([]);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('General cache cleared', $result['output']);
        $this->assertStringContainsString('Views cache cleared', $result['output']);
        $this->assertStringContainsString('Schemas cache cleared', $result['output']);
        $this->assertStringContainsString('Logs cleared', $result['output']);
        $this->assertStringContainsString('Cache cleared successfully', $result['output']);

        $this->assertEmpty(glob($this->cacheDir . '/*.cache'));
        $this->assertEmpty(glob($this->viewsDir . '/*.php'));
        $this->assertEmpty(glob($this->schemasDir . '/*'));
        $this->assertEmpty(glob($this->logsDir . '/*'));
    }

    public function testTypeGeneralClearsOnlyGeneral(): void
    {
        $result = $this->runCommand(['--type=general']);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('General cache cleared', $result['output']);
        $this->assertStringNotContainsString('Views cache cleared', $result['output']);
        $this->assertStringNotContainsString('Schemas cache cleared', $result['output']);
        $this->assertStringNotContainsString('Logs cleared', $result['output']);

        $this->assertEmpty(glob($this->cacheDir . '/*.cache'));
        $this->assertNotEmpty(glob($this->viewsDir . '/*.php'));
        $this->assertNotEmpty(glob($this->schemasDir . '/*'));
        $this->assertNotEmpty(glob($this->logsDir . '/*'));
    }

    public function testTypeViewsClearsOnlyViews(): void
    {
        $result = $this->runCommand(['--type=views']);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Views cache cleared', $result['output']);
        $this->assertStringNotContainsString('General cache cleared', $result['output']);
        $this->assertStringNotContainsString('Schemas cache cleared', $result['output']);
        $this->assertStringNotContainsString('Logs cleared', $result['output']);

        $this->assertEmpty(glob($this->viewsDir . '/*.php'));
        $this->assertNotEmpty(glob($this->cacheDir . '/*.cache'));
        $this->assertNotEmpty(glob($this->schemasDir . '/*'));
        $this->assertNotEmpty(glob($this->logsDir . '/*'));
    }

    public function testTypeSchemasClearsOnlySchemas(): void
    {
        $result = $this->runCommand(['--type=schemas']);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Schemas cache cleared', $result['output']);
        $this->assertStringNotContainsString('General cache cleared', $result['output']);
        $this->assertStringNotContainsString('Views cache cleared', $result['output']);
        $this->assertStringNotContainsString('Logs cleared', $result['output']);

        $this->assertEmpty(glob($this->schemasDir . '/*'));
        $this->assertNotEmpty(glob($this->cacheDir . '/*.cache'));
        $this->assertNotEmpty(glob($this->viewsDir . '/*.php'));
        $this->assertNotEmpty(glob($this->logsDir . '/*'));
    }

    public function testTypeLogsClearsOnlyLogs(): void
    {
        $result = $this->runCommand(['--type=logs']);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Logs cleared', $result['output']);
        $this->assertStringNotContainsString('General cache cleared', $result['output']);
        $this->assertStringNotContainsString('Views cache cleared', $result['output']);
        $this->assertStringNotContainsString('Schemas cache cleared', $result['output']);

        $this->assertEmpty(glob($this->logsDir . '/*'));
        $this->assertNotEmpty(glob($this->cacheDir . '/*.cache'));
        $this->assertNotEmpty(glob($this->viewsDir . '/*.php'));
        $this->assertNotEmpty(glob($this->schemasDir . '/*'));
    }

    public function testInvalidTypeReturnsErrorCode(): void
    {
        $result = $this->runCommand(['--type=invalid']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Error: Invalid type', $result['output']);
        $this->assertStringContainsString('Valid: all, general, views, schemas, logs', $result['output']);

        // No files should be deleted
        $this->assertNotEmpty(glob($this->cacheDir . '/*.cache'));
        $this->assertNotEmpty(glob($this->viewsDir . '/*.php'));
        $this->assertNotEmpty(glob($this->schemasDir . '/*'));
        $this->assertNotEmpty(glob($this->logsDir . '/*'));
    }

    public function testGeneralCacheClearsFileCacheCorrectly(): void
    {
        // Seed specific value
        $fileCache = new \Phue\Core\Cache\FileCache($this->cacheDir);
        $fileCache->set('test_key', 'test_value');
        $this->assertNotNull($fileCache->get('test_key'));

        $result = $this->runCommand(['--type=general']);
        $this->assertSame(0, $result['code']);

        // Verify FileCache::clear() actually removed the entries
        $this->assertNull($fileCache->get('test_key'));
        $this->assertEmpty(glob($this->cacheDir . '/*.cache'));
    }

    public function testClearingNonExistentDirectoryDoesNotFail(): void
    {
        // Remove directories and re-run
        $this->removeTree($this->basePath);
        @mkdir($this->basePath, 0777, true);
        @mkdir($this->basePath . '/App', 0777, true);
        @mkdir($this->basePath . '/config', 0777, true);
        file_put_contents($this->basePath . '/composer.json', '{}');

        $result = $this->runCommand(['--type=all']);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Cache cleared successfully', $result['output']);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeTree($path);
            }
        }
        @rmdir($dir);
    }
}